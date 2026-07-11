<?php
declare(strict_types=1);

/**
 * Builds a student's skill profile from portal academic data and enriches
 * experience-matcher hits with explanations, evidence, and guidance.
 */

require_once __DIR__ . '/RegistrationDataService.php';
require_once __DIR__ . '/period_mode_helper.php';
require_once __DIR__ . '/ai_student_context.php';
require_once dirname(__DIR__, 2) . '/includes/elearning_access.php';

if (!function_exists('ssd_h')) {
    function ssd_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

final class StudentSkillDiscoveryService
{
    private mysqli $db;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $taxonomyByCode = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getStudentId(): string
    {
        return trim((string)($_SESSION['Sid'] ?? ''));
    }

    /** @return array<string, mixed> */
    public function buildStudentProfile(string $studentId): array
    {
        if ($studentId === '' || !preg_match('/^[A-Za-z0-9\/\-_]+$/', $studentId)) {
            return ['student_id' => '', 'valid' => false];
        }

        $ctx = wuc_ai_student_context($this->db, $studentId);
        $profile = [
            'student_id' => $studentId,
            'valid' => true,
            'name' => trim((string)($ctx['name'] ?? '')),
            'program_code' => trim((string)($ctx['program_code'] ?? '')),
            'program_name' => trim((string)($ctx['program_name'] ?? '')),
            'program_type' => trim((string)($ctx['program_type'] ?? '')),
            'year_of_study' => $ctx['year_of_study'] ?? null,
            'period_label' => trim((string)($ctx['period_label'] ?? 'Semester')),
            'registered_courses' => is_array($ctx['registered_courses'] ?? null) ? $ctx['registered_courses'] : [],
            'assessments' => is_array($ctx['assessments'] ?? null) ? $ctx['assessments'] : [],
        ];

        $enrolled = [];
        foreach (getStudentEnrolledCourses($this->db, $studentId) as $code) {
            $code = strtoupper(trim((string)$code));
            if ($code !== '') {
                $enrolled[$code] = $code;
            }
        }
        foreach ($profile['registered_courses'] as $course) {
            $code = strtoupper(trim((string)($course['code'] ?? '')));
            if ($code !== '') {
                $enrolled[$code] = (string)($course['name'] ?? $code);
            }
        }
        $profile['course_map'] = $this->resolveCourseNames($enrolled);
        $profile['program_courses'] = $this->loadProgramCourses($profile['program_code']);

        return $profile;
    }

    /**
     * Discover skills from academic records (courses, assessments, programme).
     *
     * @return array<int, array<string, mixed>>
     */
    public function discoverAcademicSkills(string $studentId): array
    {
        $profile = $this->buildStudentProfile($studentId);
        if (empty($profile['valid'])) {
            return [];
        }

        $taxonomy = $this->loadTaxonomy();
        if (!$taxonomy) {
            return [];
        }

        $courseMap = $profile['course_map'];
        foreach ($profile['program_courses'] as $row) {
            $code = strtoupper(trim((string)($row['course_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            if (!isset($courseMap[$code])) {
                $courseMap[$code] = trim((string)($row['course_name'] ?? '')) ?: $code;
            }
        }

        $marksByCourse = $this->averageMarksByCourse($profile['assessments']);
        $skills = [];
        $registeredCodes = array_map('strtoupper', array_keys($profile['course_map']));

        foreach ($courseMap as $courseCode => $courseName) {
            $avgMark = $marksByCourse[strtoupper($courseCode)] ?? null;
            $courseText = $courseCode . ' ' . $courseName;
            $matches = array_merge(
                $this->matchTextToTaxonomy($courseText, $taxonomy, 4),
                $this->hintedTaxonomyMatches($courseText, $taxonomy, 3)
            );
            $matches = $this->dedupeMatches($matches, 5);
            $isRegistered = in_array(strtoupper($courseCode), $registeredCodes, true);
            foreach ($matches as $match) {
                $this->upsertSkill($skills, $match, [
                    'source_type' => $isRegistered ? 'registered_course' : 'program_course',
                    'source_label' => $isRegistered ? 'Registered course' : 'Programme curriculum',
                    'inferred' => true,
                    'level' => $this->levelFromMark($avgMark, true),
                    'evidence' => [trim($courseCode . ' — ' . $courseName)],
                    'related_courses' => [$courseCode],
                ]);
            }
        }

        foreach ($marksByCourse as $courseCode => $avgMark) {
            $courseName = (string)($profile['course_map'][$courseCode] ?? $courseCode);
            $matches = $this->matchTextToTaxonomy($courseCode . ' ' . $courseName, $taxonomy, 3);
            foreach ($matches as $match) {
                $this->upsertSkill($skills, $match, [
                    'source_type' => 'assessment',
                    'source_label' => 'Assessment result',
                    'inferred' => false,
                    'level' => $this->levelFromMark($avgMark, false),
                    'evidence' => [sprintf('%s average mark %.0f%%', $courseName, $avgMark)],
                    'related_courses' => [$courseCode],
                ]);
            }
        }

        if (!$skills && !empty($profile['program_code'])) {
            $programText = implode(' ', array_filter([
                $profile['program_code'],
                $profile['program_name'],
                $profile['program_type'],
            ]));
            $matches = array_merge(
                $this->matchTextToTaxonomy($programText, $taxonomy, 6),
                $this->hintedTaxonomyMatches($programText, $taxonomy, 6)
            );
            $matches = $this->dedupeMatches($matches, 6);
            foreach ($matches as $match) {
                $this->upsertSkill($skills, $match, [
                    'source_type' => 'program',
                    'source_label' => 'Programme profile',
                    'inferred' => true,
                    'level' => 'beginner',
                    'evidence' => ['Suggested from your programme: ' . ($profile['program_name'] ?: $profile['program_code'])],
                    'related_courses' => [],
                ]);
            }
        }

        return $this->finalizeSkills($skills, $profile);
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @param array<int, string> $experienceTexts
     * @return array<int, array<string, mixed>>
     */
    public function enrichExperienceMatches(array $matches, array $experienceTexts, string $studentId): array
    {
        if (!$matches) {
            return [];
        }

        $profile = $this->buildStudentProfile($studentId);
        $taxonomy = $this->loadTaxonomy();
        $enriched = [];

        foreach ($matches as $match) {
            $code = (string)($match['code'] ?? '');
            $row = $taxonomy[$code] ?? null;
            $label = (string)($match['label'] ?? ($row['preferred_label'] ?? 'Skill'));
            $level = $this->levelFromRating((float)($match['rating'] ?? 0));
            $evidence = is_array($match['evidence'] ?? null) ? $match['evidence'] : [];
            $card = [
                'code' => $code,
                'name' => $label,
                'description' => $row ? $this->skillDescription($row) : $this->fallbackDescription($label),
                'explanation' => $this->experienceExplanation($label, $evidence, (int)($match['experience_count'] ?? 1)),
                'level' => $level,
                'level_label' => ucfirst($level),
                'source_type' => 'experience_match',
                'source_label' => 'Experience you described',
                'inferred' => false,
                'evidence' => $evidence,
                'related_courses' => [],
                'next_step' => $this->nextStepForSkill($label, $level, $profile),
                'career_relevance' => $this->careerRelevance($row ?: ['skill_group' => (string)($match['group'] ?? ''), 'preferred_label' => $label]),
                'rating' => (float)($match['rating'] ?? 0),
                'rating_label' => (string)($match['rating_label'] ?? 'Match'),
                'group' => (string)($match['group'] ?? ($row['skill_group'] ?? '')),
                'type' => (string)($match['type'] ?? ($row['skill_type'] ?? '')),
            ];
            $enriched[] = $card;
        }

        return $enriched;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadTaxonomy(): array
    {
        if ($this->taxonomyByCode !== null) {
            return $this->taxonomyByCode;
        }

        $this->taxonomyByCode = [];
        if (!$this->tableExists('ai_skill_taxonomy')) {
            return $this->taxonomyByCode;
        }

        $sql = 'SELECT skill_code, preferred_label, alt_labels, description, skill_group, skill_type
                FROM ai_skill_taxonomy
                ORDER BY preferred_label';
        if ($res = $this->db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $code = (string)($row['skill_code'] ?? '');
                if ($code !== '') {
                    $this->taxonomyByCode[$code] = $row;
                }
            }
            $res->free();
        }

        return $this->taxonomyByCode;
    }

    /** @param array<string, string> $codes */
    private function resolveCourseNames(array $codes): array
    {
        $map = [];
        foreach ($codes as $code => $name) {
            $map[strtoupper(trim((string)$code))] = trim((string)$name) ?: (string)$code;
        }
        return $map;
    }

    /** @return array<int, array<string, string>> */
    private function loadProgramCourses(string $programCode): array
    {
        $programCode = trim($programCode);
        if ($programCode === '' || !$this->tableExists('program_courses')) {
            return [];
        }

        $courses = [];
        $sql = 'SELECT pc.course_code, COALESCE(c.course_name, pc.course_code) AS course_name
                FROM program_courses pc
                LEFT JOIN courses c ON c.course_code = pc.course_code
                WHERE pc.program_code = ?
                ORDER BY pc.course_code
                LIMIT 40';
        if ($stmt = $this->db->prepare($sql)) {
            $stmt->bind_param('s', $programCode);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $courses[] = [
                    'course_code' => (string)($row['course_code'] ?? ''),
                    'course_name' => (string)($row['course_name'] ?? ''),
                ];
            }
            $stmt->close();
        }

        return $courses;
    }

    /** @param array<int, array<string, mixed>> $assessments */
    private function averageMarksByCourse(array $assessments): array
    {
        $buckets = [];
        foreach ($assessments as $row) {
            $course = strtoupper(trim((string)($row['course'] ?? '')));
            if ($course === '') {
                continue;
            }
            $mark = is_numeric($row['marks'] ?? null) ? (float)$row['marks'] : null;
            if ($mark === null) {
                continue;
            }
            $buckets[$course]['total'] = ($buckets[$course]['total'] ?? 0) + $mark;
            $buckets[$course]['count'] = ($buckets[$course]['count'] ?? 0) + 1;
        }

        $avg = [];
        foreach ($buckets as $course => $data) {
            $count = (int)($data['count'] ?? 0);
            if ($count > 0) {
                $avg[$course] = round(((float)$data['total']) / $count, 1);
            }
        }
        return $avg;
    }

    /**
     * @param array<string, array<string, mixed>> $taxonomy
     * @return array<int, array<string, mixed>>
     */
    private function matchTextToTaxonomy(string $text, array $taxonomy, int $limit): array
    {
        $text = trim($text);
        if ($text === '' || !$taxonomy) {
            return [];
        }

        $scores = [];
        foreach ($taxonomy as $code => $row) {
            $score = $this->keywordScore(
                $text,
                (string)($row['preferred_label'] ?? ''),
                (string)($row['alt_labels'] ?? '')
            );
            if ($score > 0) {
                $scores[] = ['code' => $code, 'row' => $row, 'score' => $score];
            }
        }

        usort($scores, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scores, 0, max(1, $limit));
    }

    private function keywordScore(string $haystack, string $label, string $altLabels): float
    {
        $haystack = strtolower($haystack);
        $words = $this->significantWords($haystack);
        if (!$words) {
            return 0.0;
        }

        $skillText = strtolower($label . ' ' . $altLabels);
        $skillWords = $this->significantWords($skillText);
        if (!$skillWords) {
            return 0.0;
        }

        $matches = 0;
        foreach ($skillWords as $word) {
            if (isset($words[$word])) {
                $matches++;
            }
        }

        return $matches > 0 ? min(1.0, $matches * 0.15) : 0.0;
    }

    /**
     * Map common course/program words to taxonomy codes when embedding match is weak.
     *
     * @param array<string, array<string, mixed>> $taxonomy
     * @return array<int, array<string, mixed>>
     */
    private function hintedTaxonomyMatches(string $text, array $taxonomy, int $limit): array
    {
        $text = strtolower($text);
        $hints = [
            'program' => ['LOC-006', 'LOC-050', 'LOC-052'],
            'software' => ['LOC-001', 'LOC-006'],
            'database' => ['LOC-003', 'LOC-051'],
            'network' => ['LOC-004', 'LOC-050'],
            'operating' => ['LOC-004', 'LOC-040'],
            'system' => ['LOC-004', 'LOC-050'],
            'web' => ['LOC-006', 'LOC-004'],
            'spreadsheet' => ['LOC-002', 'LOC-054'],
            'excel' => ['LOC-002'],
            'electrical' => ['LOC-040', 'LOC-050'],
            'motor' => ['LOC-040', 'LOC-041'],
            'vehicle' => ['LOC-041'],
            'auto' => ['LOC-040', 'LOC-041'],
            'welding' => ['LOC-043'],
            'account' => ['LOC-033', 'LOC-054'],
            'business' => ['LOC-022', 'LOC-033'],
            'communication' => ['LOC-010', 'LOC-011'],
            'math' => ['LOC-054', 'LOC-051'],
            'computer' => ['LOC-006', 'LOC-003', 'LOC-004'],
            'ict' => ['LOC-006', 'LOC-003', 'LOC-004'],
            'cse' => ['LOC-006', 'LOC-003', 'LOC-004'],
            'engineering' => ['LOC-050', 'LOC-040'],
            'maintenance' => ['LOC-040', 'LOC-050'],
            'customer' => ['LOC-020', 'LOC-021'],
            'record' => ['LOC-003', 'LOC-033'],
            'management' => ['LOC-030', 'LOC-034'],
        ];

        $codes = [];
        foreach ($hints as $needle => $skillCodes) {
            if (strpos($text, $needle) !== false) {
                foreach ($skillCodes as $code) {
                    $codes[$code] = true;
                }
            }
        }

        $out = [];
        foreach (array_keys($codes) as $code) {
            if (!isset($taxonomy[$code])) {
                continue;
            }
            $out[] = ['code' => $code, 'row' => $taxonomy[$code], 'score' => 0.55];
        }

        usort($out, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<int, array<string, mixed>>
     */
    private function dedupeMatches(array $matches, int $limit): array
    {
        $seen = [];
        $out = [];
        foreach ($matches as $match) {
            $code = (string)($match['code'] ?? '');
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = $match;
        }
        usort($out, static fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        return array_slice($out, 0, max(1, $limit));
    }

    /** @return array<string, true> */
    private function significantWords(string $text): array
    {
        $stop = ['course', 'introduction', 'fundamentals', 'basic', 'advanced', 'year', 'semester', 'term', 'the', 'and', 'for'];
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [] as $word) {
            $word = trim($word);
            if (strlen($word) < 4 || in_array($word, $stop, true)) {
                continue;
            }
            $out[$word] = true;
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $skills
     * @param array<string, mixed> $match
     * @param array<string, mixed> $meta
     */
    private function upsertSkill(array &$skills, array $match, array $meta): void
    {
        $code = (string)($match['code'] ?? '');
        if ($code === '') {
            return;
        }

        $row = $match['row'] ?? [];
        $levelRank = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];
        $newLevel = (string)($meta['level'] ?? 'beginner');

        if (!isset($skills[$code])) {
            $skills[$code] = array_merge([
                'code' => $code,
                'name' => (string)($row['preferred_label'] ?? $code),
                'description' => $this->skillDescription($row),
                'group' => (string)($row['skill_group'] ?? ''),
                'type' => (string)($row['skill_type'] ?? ''),
                'evidence' => [],
                'related_courses' => [],
            ], $meta);
            return;
        }

        if (($levelRank[$newLevel] ?? 0) > ($levelRank[(string)$skills[$code]['level']] ?? 0)) {
            $skills[$code]['level'] = $newLevel;
            $skills[$code]['level_label'] = ucfirst($newLevel);
            $skills[$code]['source_type'] = $meta['source_type'];
            $skills[$code]['source_label'] = $meta['source_label'];
            $skills[$code]['inferred'] = $meta['inferred'];
        }

        foreach (($meta['evidence'] ?? []) as $item) {
            if ($item !== '' && !in_array($item, $skills[$code]['evidence'], true)) {
                $skills[$code]['evidence'][] = $item;
            }
        }
        foreach (($meta['related_courses'] ?? []) as $course) {
            if ($course !== '' && !in_array($course, $skills[$code]['related_courses'], true)) {
                $skills[$code]['related_courses'][] = $course;
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $skills
     * @param array<string, mixed> $profile
     * @return array<int, array<string, mixed>>
     */
    private function finalizeSkills(array $skills, array $profile): array
    {
        $final = [];
        foreach ($skills as $skill) {
            $level = (string)($skill['level'] ?? 'beginner');
            $skill['level_label'] = ucfirst($level);
            $skill['explanation'] = $this->academicExplanation($skill, $profile);
            $skill['next_step'] = $this->nextStepForSkill((string)$skill['name'], $level, $profile);
            $skill['career_relevance'] = $this->careerRelevance($skill);
            $skill['evidence'] = array_values(array_unique(array_slice($skill['evidence'], 0, 4)));
            $final[] = $skill;
        }

        usort($final, static function ($a, $b) {
            $rank = ['assessment' => 5, 'registered_course' => 4, 'program_course' => 3, 'program' => 2];
            $aRank = $rank[$a['source_type'] ?? ''] ?? 1;
            $bRank = $rank[$b['source_type'] ?? ''] ?? 1;
            if ($aRank !== $bRank) {
                return $bRank <=> $aRank;
            }
            return strcmp((string)$a['name'], (string)$b['name']);
        });

        return array_slice($final, 0, 12);
    }

    private function levelFromMark(?float $mark, bool $inferredOnly): string
    {
        if ($mark === null) {
            return $inferredOnly ? 'beginner' : 'beginner';
        }
        if ($mark >= 70) {
            return 'advanced';
        }
        if ($mark >= 50) {
            return 'intermediate';
        }
        return 'beginner';
    }

    private function levelFromRating(float $rating): string
    {
        if ($rating >= 75) {
            return 'advanced';
        }
        if ($rating >= 50) {
            return 'intermediate';
        }
        return 'beginner';
    }

    /** @param array<string, mixed> $row */
    private function skillDescription(array $row): string
    {
        $stored = trim((string)($row['description'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $label = trim((string)($row['preferred_label'] ?? 'This skill'));
        $group = trim((string)($row['skill_group'] ?? 'work and study'));
        $type = trim((string)($row['skill_type'] ?? 'skill'));

        return $label . ' is a ' . $type . ' in the ' . $group
            . ' area. It describes an ability you can demonstrate in study, work, or community activities.';
    }

    private function fallbackDescription(string $label): string
    {
        return $label . ' is a transferable capability that employers and trainers recognise in everyday tasks.';
    }

    /** @param array<string, mixed> $skill */
    private function academicExplanation(array $skill, array $profile): string
    {
        $name = (string)($skill['name'] ?? 'This skill');
        $source = (string)($skill['source_label'] ?? 'your academic record');
        $program = (string)($profile['program_name'] ?? $profile['program_code'] ?? 'your programme');
        $inferred = !empty($skill['inferred']);

        if (!empty($skill['evidence'])) {
            $because = implode('; ', array_slice($skill['evidence'], 0, 2));
            $prefix = $inferred
                ? 'This skill is suggested (not directly recorded) because '
                : 'This skill is supported because ';
            return $prefix . $because . ' while you are studying ' . $program . '.';
        }

        return 'You may be developing ' . $name . ' through ' . $source . ' on ' . $program . '.';
    }

    /** @param array<int, string> $evidence */
    private function experienceExplanation(string $label, array $evidence, int $experienceCount): string
    {
        $parts = [];
        if ($evidence) {
            $parts[] = 'Your description mentioned: "' . implode('"; "', array_slice($evidence, 0, 2)) . '"';
        }
        if ($experienceCount > 1) {
            $parts[] = 'the same theme appeared in ' . $experienceCount . ' experience entries';
        }
        if (!$parts) {
            return 'This skill was matched from the everyday language in your experience entries.';
        }
        return 'Likely strength: ' . $label . '. ' . implode(', and ', $parts) . '.';
    }

    /** @param array<string, mixed> $profile */
    private function nextStepForSkill(string $name, string $level, array $profile): string
    {
        $program = (string)($profile['program_name'] ?? $profile['program_code'] ?? 'your programme');
        if ($level === 'advanced') {
            return 'Mentor others, document your work, and link ' . $name . ' to a portfolio piece or workplace project.';
        }
        if ($level === 'intermediate') {
            return 'Practise ' . $name . ' in your next assignment or practical session on ' . $program . ', then ask your lecturer for feedback.';
        }
        return 'Start with a short practice task related to ' . $name . ' in your current courses on ' . $program . ', or use the experience form below to reflect on where you already use it.';
    }

    /** @param array<string, mixed> $skill */
    private function careerRelevance(array $skill): string
    {
        $group = trim((string)($skill['skill_group'] ?? ''));
        $name = trim((string)($skill['name'] ?? ($skill['preferred_label'] ?? 'This skill')));
        $areas = [
            'Digital' => 'office work, ICT support, data handling, and modern service jobs',
            'Communication' => 'customer-facing roles, teamwork, teaching, and community work',
            'Service' => 'retail, hospitality, front-desk, and client support',
            'Management' => 'supervision, small business, project coordination',
            'Practical' => 'trades, workshops, farming, transport, and technical services',
            'Cognitive' => 'problem-solving roles across technical and administrative work',
            'Personal' => 'reliability and adaptability expected in every workplace',
            'Care' => 'health, community care, and support services',
        ];
        $context = $areas[$group] ?? 'many entry-level and technical jobs';
        return $name . ' is valued in ' . $context . '.';
    }

    private function tableExists(string $table): bool
    {
        $safe = $this->db->real_escape_string($table);
        if ($res = @$this->db->query("SHOW TABLES LIKE '{$safe}'")) {
            $exists = $res->num_rows > 0;
            $res->free();
            return $exists;
        }
        return false;
    }
}

if (!function_exists('ssd_render_skill_card')) {
  /**
   * @param array<string, mixed> $skill
   */
  function ssd_render_skill_card(array $skill, string $collapseId, bool $expanded = false): void
  {
      $level = (string)($skill['level'] ?? 'beginner');
      $levelClass = in_array($level, ['beginner', 'intermediate', 'advanced'], true) ? $level : 'beginner';
      $name = (string)($skill['name'] ?? 'Skill');
      $isInferred = !empty($skill['inferred']);
      $showMatch = isset($skill['rating']) && (float)$skill['rating'] > 0;
      ?>
      <article class="skill-detail-card">
          <div class="skill-detail-head">
              <div class="flex-grow-1">
                  <h3 class="skill-detail-title"><?= ssd_h($name) ?></h3>
                  <div>
                      <span class="skill-badge level-<?= ssd_h($levelClass) ?>"><?= ssd_h((string)($skill['level_label'] ?? ucfirst($levelClass))) ?></span>
                      <span class="skill-badge source"><?= ssd_h((string)($skill['source_label'] ?? 'Portal record')) ?></span>
                      <?php if ($isInferred): ?>
                          <span class="skill-badge inferred">Inferred</span>
                      <?php endif; ?>
                      <?php if ($showMatch): ?>
                          <span class="skill-badge source"><?= ssd_h((string)($skill['rating_label'] ?? 'Match')) ?> <?= ssd_h((string)round((float)$skill['rating'])) ?>%</span>
                      <?php endif; ?>
                  </div>
                  <p class="small text-muted mb-0 mt-2"><?= ssd_h((string)($skill['description'] ?? '')) ?></p>
              </div>
              <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= ssd_h($collapseId) ?>" aria-expanded="<?= $expanded ? 'true' : 'false' ?>">
                  Details
              </button>
          </div>
          <div class="collapse <?= $expanded ? 'show' : '' ?>" id="<?= ssd_h($collapseId) ?>">
              <div class="skill-detail-body">
                  <h4>Why this skill appears</h4>
                  <p><?= ssd_h((string)($skill['explanation'] ?? '')) ?></p>

                  <?php if (!empty($skill['evidence']) && is_array($skill['evidence'])): ?>
                      <h4>Evidence</h4>
                      <ul>
                          <?php foreach ($skill['evidence'] as $item): ?>
                              <li><?= ssd_h((string)$item) ?></li>
                          <?php endforeach; ?>
                      </ul>
                  <?php endif; ?>

                  <?php if (!empty($skill['related_courses']) && is_array($skill['related_courses'])): ?>
                      <h4>Related courses</h4>
                      <p><?= ssd_h(implode(', ', $skill['related_courses'])) ?></p>
                  <?php endif; ?>

                  <h4>Recommended next step</h4>
                  <p><?= ssd_h((string)($skill['next_step'] ?? '')) ?></p>

                  <h4>Career relevance</h4>
                  <p class="mb-0"><?= ssd_h((string)($skill['career_relevance'] ?? '')) ?></p>
              </div>
          </div>
      </article>
      <?php
  }
}
