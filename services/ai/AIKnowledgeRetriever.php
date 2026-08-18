<?php
declare(strict_types=1);

final class AIKnowledgeRetriever
{
    public function __construct(private mysqli $db) {}

    public static function questionHash(string $question): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $question) ?? $question));
        return hash('sha256', $normalized);
    }

    public function verifiedAnswer(string $courseId, string $question): ?array
    {
        $hash = self::questionHash($question);
        $stmt = $this->db->prepare("SELECT id, topic, answer, verified_by, verified_at FROM verified_answers
                                     WHERE course_id = ? AND question_hash = ? AND status IN ('approved','published') LIMIT 1");
        $stmt->bind_param('ss', $courseId, $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $update = $this->db->prepare('UPDATE verified_answers SET use_count = use_count + 1 WHERE id = ?');
            $id = (int)$row['id'];
            $update->bind_param('i', $id);
            $update->execute();
            $update->close();
        }
        return $row ?: null;
    }

    public function retrieve(string $courseId, string $question, int $maxChunks = 5): array
    {
        $sources = [];
        $terms = $this->terms($question);

        $stmt = $this->db->prepare(
            "SELECT kc.id, COALESCE(kc.heading, kd.title) title, kc.content, kd.source_path
             FROM knowledge_chunks kc
             INNER JOIN knowledge_document_versions kv ON kv.id = kc.document_version_id
             INNER JOIN knowledge_documents kd ON kd.id = kv.document_id
             WHERE kc.course_id = ? AND kd.status IN ('approved','published')
             ORDER BY kd.approved_at DESC, kc.id DESC LIMIT 80"
        );
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $sources[] = $this->source('course_material', (string)$row['id'], (string)$row['title'],
                (string)$row['content'], (string)($row['source_path'] ?? ''), 1, 'lecturer_approved', $terms);
        }
        $stmt->close();

        $stmt = $this->db->prepare(
            "SELECT st.id, st.topic_title, CONCAT_WS(' ', st.subtopics, st.learning_outcomes, st.assessment_criteria) content, sv.version_label
             FROM syllabus_topics st INNER JOIN syllabus_versions sv ON sv.id = st.syllabus_version_id
             WHERE sv.course_code = ? AND sv.status = 'approved' ORDER BY st.display_order ASC LIMIT 100"
        );
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $sources[] = $this->source('approved_syllabus', (string)$row['id'],
                (string)$row['topic_title'] . ' (' . (string)$row['version_label'] . ')',
                (string)$row['content'], '', 2, 'institution_approved', $terms);
        }
        $stmt->close();

        $stmt = $this->db->prepare(
            "SELECT id, title, CONCAT_WS(' ', description, original_filename) content,
                    CASE WHEN upload_mode = 'link' THEN external_url ELSE file_path END reference_url
             FROM repository_materials
             WHERE course_code = ? AND status = 'approved' AND is_published = 1 AND is_archived = 0
             ORDER BY updated_at DESC LIMIT 60"
        );
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $sources[] = $this->source('institutional_repository', (string)$row['id'], (string)$row['title'],
                (string)$row['content'], (string)($row['reference_url'] ?? ''), 3, 'institution_approved', $terms);
        }
        $stmt->close();

        usort($sources, static fn(array $a, array $b): int => [$b['score'], -$b['priority_rank']] <=> [$a['score'], -$a['priority_rank']]);
        $beforeFilter = count($sources);
        $sources = array_values(array_filter($sources, static fn(array $s): bool => $s['score'] > 0 || count($terms) === 0));
        $result = array_slice($sources, 0, $maxChunks);
        // #region agent log
        file_put_contents(dirname(__DIR__, 2) . '/debug-ab9fe3.log', json_encode([
            'sessionId' => 'ab9fe3', 'runId' => 'pre-fix', 'hypothesisId' => 'C',
            'location' => 'AIKnowledgeRetriever.php:retrieve', 'message' => 'knowledge retrieve stats',
            'data' => [
                'courseId' => $courseId,
                'termCount' => count($terms),
                'terms' => array_slice($terms, 0, 12),
                'beforeFilter' => $beforeFilter,
                'afterFilter' => count($sources),
                'returned' => count($result),
                'topScores' => array_map(static fn($s) => ['title' => substr((string)$s['title'], 0, 60), 'score' => $s['score'], 'type' => $s['source_type']], array_slice($result ?: array_slice($sources, 0, 3) ?: [], 0, 3)),
            ],
            'timestamp' => (int) round(microtime(true) * 1000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        // #endregion
        return $result;
    }

    private function source(string $type, string $id, string $title, string $content, string $url, int $rank, string $verified, array $terms): array
    {
        $haystack = strtolower($title . ' ' . $content);
        $score = 0;
        foreach ($terms as $term) {
            $score += substr_count($haystack, $term) * (str_contains(strtolower($title), $term) ? 3 : 1);
        }
        return [
            'source_type' => $type, 'source_id' => $id, 'title' => $title,
            'reference_url' => $url !== '' ? $url : null,
            'excerpt' => mb_substr(trim($content), 0, 900), 'priority_rank' => $rank,
            'verification_status' => $verified, 'score' => $score,
        ];
    }

    private function terms(string $question): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['what','when','where','which','with','that','this','from','have','does','into','your','about','explain','please','could','would'];
        return array_values(array_unique(array_filter($parts, static fn(string $p): bool => strlen($p) >= 4 && !in_array($p, $stop, true))));
    }
}
