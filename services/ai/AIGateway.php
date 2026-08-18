<?php
declare(strict_types=1);

require_once __DIR__ . '/AIAuditService.php';
require_once __DIR__ . '/AICostService.php';
require_once __DIR__ . '/AISafetyService.php';
require_once __DIR__ . '/AIPermissionService.php';
require_once __DIR__ . '/AIContextBuilder.php';
require_once __DIR__ . '/AIKnowledgeRetriever.php';
require_once __DIR__ . '/AIModelRouter.php';

final class AIGateway
{
    private AIPermissionService $permissions;
    private AIContextBuilder $contexts;
    private AIKnowledgeRetriever $knowledge;
    private AIAuditService $audit;
    private AICostService $costs;
    private AISafetyService $safety;

    public function __construct(private mysqli $db, private ?AIModelRouter $models = null)
    {
        $this->models ??= new AIModelRouter($db);
        $this->permissions = new AIPermissionService($db);
        $this->contexts = new AIContextBuilder($db);
        $this->knowledge = new AIKnowledgeRetriever($db);
        $this->audit = new AIAuditService($db);
        $this->costs = new AICostService($db);
        $this->safety = new AISafetyService();
    }

    public function askStudent(string $studentId, array $request): array
    {
        $started = microtime(true);
        $courseId = strtoupper(trim((string)($request['course_id'] ?? '')));
        $question = $this->safety->validateQuestion((string)($request['question'] ?? ''));
        $requestType = ($request['request_type'] ?? 'chat') === 'practice' ? 'practice' : 'chat';
        $level = in_array(($request['explanation_level'] ?? ''), ['simple','standard','detailed'], true)
            ? (string)$request['explanation_level'] : 'standard';
        $assessmentId = trim((string)($request['assessment_id'] ?? '')) ?: null;

        $this->permissions->requireStudentCourse($studentId, $courseId);
        [$mode, $settings] = $this->permissions->effectiveMode($courseId, $assessmentId, $requestType);
        $context = $this->contexts->buildStudentContext($studentId, $courseId);
        $this->permissions->enforceUsageLimit($studentId, $settings, $context['department_id']);
        $conversationId = $this->resolveConversation($studentId, $courseId, $context, (int)($request['conversation_id'] ?? 0));
        $questionHash = AIKnowledgeRetriever::questionHash($question);
        $userMessageId = $this->storeMessage($conversationId, 'student', $requestType, $question, $level, null, null, false, null, 0, 0, 'published', $questionHash);

        $policy = $this->safety->policyResponse($question);
        $verified = $requestType === 'chat' ? $this->knowledge->verifiedAnswer($courseId, $question) : null;
        $sources = [];
        $model = 'policy';
        $cacheHit = false;

        if ($policy !== null) {
            $answer = $policy;
            $sourceStatus = 'policy_controlled';
            $confidence = 1.0;
            $confirm = true;
        } elseif ($verified) {
            $answer = (string)$verified['answer'];
            $sourceStatus = 'lecturer_verified';
            $confidence = 0.98;
            $confirm = false;
            $model = 'verified-answer';
            $cacheHit = true;
            $sources[] = [
                'source_type' => 'verified_answer', 'source_id' => (string)$verified['id'],
                'title' => 'Lecturer-verified answer' . (!empty($verified['topic']) ? ': ' . $verified['topic'] : ''),
                'reference_url' => null, 'excerpt' => mb_substr($answer, 0, 500), 'priority_rank' => 4,
                'verification_status' => 'lecturer_verified',
            ];
        } else {
            $sources = $this->knowledge->retrieve($courseId, $question);
            $cached = $this->cached($courseId, $questionHash, $level);
            if ($cached && $requestType === 'chat') {
                $answer = (string)$cached['answer'];
                $sourceStatus = (string)$cached['source_status'];
                $model = (string)($cached['model_name'] ?: 'response-cache');
                $cachedSources = json_decode((string)($cached['sources'] ?? '[]'), true);
                if (is_array($cachedSources)) {
                    $sources = $cachedSources;
                }
                $confidence = $sources ? 0.78 : 0.45;
                $confirm = !$sources;
                $cacheHit = true;
            } else {
                [$answer, $model] = $this->generate($question, $requestType, $level, $mode, $context, $sources, (int)$settings['max_response_tokens']);
                $sourceStatus = $sources ? 'approved_sources_used' : 'general_model_knowledge';
                $confidence = $sources ? 0.80 : 0.45;
                $confirm = !$sources;
                if ($requestType === 'chat') {
                    $this->cache($courseId, $questionHash, $level, $answer, $sourceStatus, $sources, $model);
                }
            }
        }

        $answer = $this->safety->cleanModelOutput($answer);
        $promptTokens = AICostService::estimateTokens($question . json_encode($sources));
        $completionTokens = AICostService::estimateTokens($answer);
        $assistantId = $this->storeMessage(
            $conversationId, 'assistant', $requestType, $answer, $level, $sourceStatus, $confidence,
            $confirm, $model, $promptTokens, $completionTokens, 'draft', null
        );
        $this->storeSources($assistantId, $sources);
        $this->summarizeIfNeeded($conversationId);

        $estimatedCost = AICostService::estimatedCost($promptTokens, $completionTokens, $model);
        $this->costs->log([
            'user_id' => $studentId, 'department_id' => $context['department_id'], 'course_id' => $courseId,
            'conversation_id' => $conversationId, 'request_type' => $requestType, 'model_name' => $model,
            'prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens,
            'estimated_cost' => $estimatedCost, 'latency_ms' => (int)((microtime(true) - $started) * 1000), 'cache_hit' => $cacheHit,
        ]);
        $this->audit->record($studentId, 'student', 'ai.' . $requestType, [
            'entity_type' => 'ai_message', 'entity_id' => (string)$assistantId, 'course_id' => $courseId,
            'metadata' => ['conversation_id' => $conversationId, 'mode' => $mode, 'source_status' => $sourceStatus],
        ]);

        return [
            'conversation_id' => $conversationId, 'message_id' => $assistantId,
            'answer' => $answer, 'explanation_level' => $level, 'source_status' => $sourceStatus,
            'sources' => array_map(static function(array $source): array {
                unset($source['score']);
                return $source;
            }, $sources),
            'confidence' => $confidence, 'lecturer_confirmation_recommended' => $confirm,
            'available_actions' => ['explain_simpler','give_example','generate_practice','show_sources','ask_lecturer','report_incorrect_answer'],
            'ai_mode' => $mode,
        ];
    }

    private function generate(string $question, string $type, string $level, string $mode, array $context, array $sources, int $maxTokens): array
    {
        $sourceText = '';
        foreach ($sources as $i => $source) {
            $sourceText .= "\n[" . ($i + 1) . '] ' . $source['title'] . "\n" . $source['excerpt'] . "\n";
        }
        $system = "You are the controlled WUCPortal course learning assistant. You support but never replace lecturers.\n"
            . "Course: {$context['course_id']} - {$context['course_name']}; programme: {$context['program_code']}; academic year: {$context['academic_year']}; semester: {$context['semester']}.\n"
            . "AI mode: {$mode}. Explanation level: {$level}. Maximum response target: {$maxTokens} tokens.\n"
            . "Use only the supplied approved extracts when present. Cite them as [1], [2]. Do not invent sources. "
            . "If extracts are absent, clearly begin with 'General knowledge (not lecturer-verified):'. "
            . "Never award marks, decide progression/admission/discipline, change records, assign staff, or guarantee employment. "
            . "Return clean plain text. Do not use Markdown formatting markers such as asterisks, hashes, underscores, backticks, blockquotes, tables, or horizontal rules. Use short unmarked headings and numbered lists when structure is useful. "
            . ($mode === 'hints_only' ? 'Give hints and questions, not a final solution. ' : '')
            . ($mode === 'concepts_and_examples' ? 'Explain concepts and analogous examples without solving a live assessment. ' : '')
            . ($type === 'practice' ? 'Create 3-5 ungraded practice questions, then put brief answers under a separate Answer guide heading. ' : '')
            . "Approved extracts:" . ($sourceText !== '' ? $sourceText : " none\n");
        try {
            $result = $this->models->generate($system, $question, $type);
            if (trim((string)$result['answer']) === '') {
                throw new RuntimeException('The model returned an empty answer.');
            }
            // #region agent log
            file_put_contents(dirname(__DIR__, 2) . '/debug-ab9fe3.log', json_encode([
                'sessionId' => 'ab9fe3', 'runId' => 'post-fix', 'hypothesisId' => 'B',
                'location' => 'AIGateway.php:generate:ok', 'message' => 'model generate succeeded',
                'data' => ['model' => (string)$result['model'], 'answerLen' => strlen((string)$result['answer']), 'sourceCount' => count($sources), 'type' => $type],
                'timestamp' => (int) round(microtime(true) * 1000),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
            // #endregion
            return [(string)$result['answer'], (string)$result['model']];
        } catch (Throwable $e) {
            error_log('AIGateway model fallback: ' . $e->getMessage());
            // #region agent log
            file_put_contents(dirname(__DIR__, 2) . '/debug-ab9fe3.log', json_encode([
                'sessionId' => 'ab9fe3', 'runId' => 'post-fix', 'hypothesisId' => 'A',
                'location' => 'AIGateway.php:generate:catch', 'message' => 'model generate failed; using fallback',
                'data' => [
                    'error' => $e->getMessage(),
                    'sourceCount' => count($sources),
                    'sourceTitles' => array_map(static fn($s) => (string)($s['title'] ?? ''), array_slice($sources, 0, 5)),
                    'type' => $type,
                    'questionPreview' => mb_substr($question, 0, 80),
                ],
                'timestamp' => (int) round(microtime(true) * 1000),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
            // #endregion
            if ($sources) {
                $top = $sources[0];
                $prefix = $type === 'practice'
                    ? "Practice prompt based on the approved material:\n1. In your own words, explain " . $top['title'] . ".\n2. Give one course-relevant example.\n3. List two questions you would ask your lecturer.\n\nSource extract: "
                    : "The configured AI model is temporarily unavailable. Here is the closest approved course extract for review:\n\n";
                return [$prefix . $top['excerpt'] . "\n\nUse Ask My Lecturer if you need clarification.", 'grounded-fallback'];
            }
            return ['The configured AI model is temporarily unavailable, and no approved course source matched this question. Please try again later or use Ask My Lecturer so a human can help.', 'grounded-fallback'];
        }
    }

    private function resolveConversation(string $studentId, string $courseId, array $context, int $requestedId): int
    {
        if ($requestedId > 0) {
            $stmt = $this->db->prepare('SELECT id FROM ai_conversations WHERE id = ? AND user_id = ? AND student_id = ? AND course_id = ? LIMIT 1');
            $stmt->bind_param('isss', $requestedId, $studentId, $studentId, $courseId);
            $stmt->execute();
            $owned = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$owned) {
                throw new RuntimeException('Conversation access denied.');
            }
            return $requestedId;
        }
        $stmt = $this->db->prepare('INSERT INTO ai_conversations (user_id,user_role,student_id,course_id,academic_period_id,active_portal,title) VALUES (?,\'student\',?,?,?,?,?)');
        $period = $context['academic_period_id'];
        $portal = $context['active_portal'];
        $title = $context['course_name'] . ' learning support';
        $stmt->bind_param('sssiss', $studentId, $studentId, $courseId, $period, $portal, $title);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function storeMessage(int $conversationId, string $sender, string $type, string $content, ?string $level, ?string $sourceStatus, ?float $confidence, bool $confirm, ?string $model, int $promptTokens, int $completionTokens, string $status, ?string $questionHash): int
    {
        $stmt = $this->db->prepare('INSERT INTO ai_messages (conversation_id,sender_role,message_type,content,explanation_level,source_status,confidence,lecturer_confirmation_recommended,model_name,prompt_tokens,completion_tokens,content_status,question_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $confirmInt = $confirm ? 1 : 0;
        $stmt->bind_param('isssssdisiiss', $conversationId, $sender, $type, $content, $level, $sourceStatus, $confidence, $confirmInt, $model, $promptTokens, $completionTokens, $status, $questionHash);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        $update = $this->db->prepare('UPDATE ai_conversations SET last_message_at = NOW() WHERE id = ?');
        $update->bind_param('i', $conversationId);
        $update->execute();
        $update->close();
        return $id;
    }

    private function storeSources(int $messageId, array $sources): void
    {
        if (!$sources) return;
        $stmt = $this->db->prepare('INSERT INTO ai_message_sources (message_id,source_type,source_id,title,reference_url,excerpt,priority_rank,verification_status) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($sources as $source) {
            $sourceId = $source['source_id'] ?? null;
            $url = $source['reference_url'] ?? null;
            $rank = (int)$source['priority_rank'];
            $stmt->bind_param('isssssis', $messageId, $source['source_type'], $sourceId, $source['title'], $url, $source['excerpt'], $rank, $source['verification_status']);
            $stmt->execute();
        }
        $stmt->close();
    }

    private function cached(string $courseId, string $hash, string $level): ?array
    {
        $stmt = $this->db->prepare('SELECT answer,source_status,sources,model_name FROM ai_response_cache WHERE course_id=? AND question_hash=? AND explanation_level=? AND expires_at>NOW() LIMIT 1');
        $stmt->bind_param('sss', $courseId, $hash, $level);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function cache(string $courseId, string $hash, string $level, string $answer, string $sourceStatus, array $sources, string $model): void
    {
        $json = json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare("INSERT INTO ai_response_cache (course_id,question_hash,explanation_level,answer,source_status,sources,model_name,expires_at)
                                    VALUES (?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                                    ON DUPLICATE KEY UPDATE answer=VALUES(answer),source_status=VALUES(source_status),sources=VALUES(sources),model_name=VALUES(model_name),expires_at=VALUES(expires_at)");
        $stmt->bind_param('sssssss', $courseId, $hash, $level, $answer, $sourceStatus, $json, $model);
        $stmt->execute();
        $stmt->close();
    }

    private function summarizeIfNeeded(int $conversationId): void
    {
        $stmt = $this->db->prepare('SELECT id,sender_role,content FROM ai_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 20');
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $rows = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
        if (count($rows) < 12) return;
        $summaryRows = array_slice($rows, 0, -6);
        $parts = [];
        foreach ($summaryRows as $row) {
            $parts[] = ucfirst((string)$row['sender_role']) . ': ' . mb_substr(trim((string)$row['content']), 0, 220);
        }
        $summary = mb_substr(implode("\n", $parts), 0, 3000);
        $stmt = $this->db->prepare('UPDATE ai_conversations SET context_summary=? WHERE id=?');
        $stmt->bind_param('si', $summary, $conversationId);
        $stmt->execute();
        $stmt->close();
    }
}
