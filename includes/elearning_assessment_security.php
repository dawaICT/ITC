<?php
declare(strict_types=1);

if (!function_exists('elearningAssessmentTableColumns')) {
    function elearningAssessmentTableColumns(mysqli $db, string $safeTable): array
    {
        // One SHOW COLUMNS per table per request. The previous per-column
        // INFORMATION_SCHEMA lookups (17 per ensure-schema call) have blocked
        // for the full execution limit when the server was under load.
        static $cache = [];
        if (!isset($cache[$safeTable])) {
            $cache[$safeTable] = [];
            try {
                if ($res = $db->query("SHOW COLUMNS FROM `{$safeTable}`")) {
                    while ($row = $res->fetch_assoc()) {
                        $cache[$safeTable][strtolower((string)$row['Field'])] = true;
                    }
                    $res->free();
                }
            } catch (Throwable $e) {
                error_log("elearning_assessment_security: cannot read columns of {$safeTable}: " . $e->getMessage());
            }
        }
        return $cache[$safeTable];
    }
}

if (!function_exists('elearningAssessmentEnsureColumn')) {
    function elearningAssessmentEnsureColumn(mysqli $db, string $table, string $column, string $definition): bool
    {
        $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $safeColumn = preg_replace('/[^A-Za-z0-9_]/', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return false;
        }

        if (isset(elearningAssessmentTableColumns($db, $safeTable)[strtolower($safeColumn)])) {
            return true;
        }

        try {
            $db->query("ALTER TABLE `{$safeTable}` ADD COLUMN `{$safeColumn}` {$definition}");
        } catch (Throwable $e) {
            error_log("elearning_assessment_security: failed to add {$safeTable}.{$safeColumn}: " . $e->getMessage());
            return false;
        }
        return true;
    }
}

if (!function_exists('elearningAssessmentEnsureSchema')) {
    function elearningAssessmentEnsureSchema(mysqli $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        elearningAssessmentEnsureColumn($db, 'el_quizzes', 'assessment_type', "VARCHAR(20) NOT NULL DEFAULT 'quiz'");
        elearningAssessmentEnsureColumn($db, 'el_quizzes', 'due_at', 'DATETIME NULL');
        elearningAssessmentEnsureColumn($db, 'el_quizzes', 'attachment_path', 'VARCHAR(255) NULL');
        elearningAssessmentEnsureColumn($db, 'el_quizzes', 'settings_json', 'LONGTEXT NULL');

        elearningAssessmentEnsureColumn($db, 'el_assignments', 'assessment_type', "VARCHAR(20) NOT NULL DEFAULT 'assignment'");
        elearningAssessmentEnsureColumn($db, 'el_assignments', 'attachment_path', 'VARCHAR(255) NULL');
        elearningAssessmentEnsureColumn($db, 'el_assignments', 'settings_json', 'LONGTEXT NULL');

        elearningAssessmentEnsureColumn($db, 'el_attempts', 'response_text', 'LONGTEXT NULL');
        elearningAssessmentEnsureColumn($db, 'el_attempts', 'ip_address', 'VARCHAR(64) NULL');
        elearningAssessmentEnsureColumn($db, 'el_attempts', 'user_agent', 'VARCHAR(255) NULL');
        elearningAssessmentEnsureColumn($db, 'el_attempts', 'submitted_reason', 'VARCHAR(40) NULL');
        elearningAssessmentEnsureColumn($db, 'el_attempts', 'time_spent_seconds', 'INT NULL');
        elearningAssessmentEnsureColumn($db, 'el_attempts', 'malpractice_flags_json', 'LONGTEXT NULL');

        elearningAssessmentEnsureColumn($db, 'el_submissions', 'plagiarism_provider', 'VARCHAR(80) NULL');
        elearningAssessmentEnsureColumn($db, 'el_submissions', 'plagiarism_score', 'DECIMAL(5,2) NULL');
        elearningAssessmentEnsureColumn($db, 'el_submissions', 'plagiarism_status', 'VARCHAR(40) NULL');
        elearningAssessmentEnsureColumn($db, 'el_submissions', 'plagiarism_report', 'TEXT NULL');
        elearningAssessmentEnsureColumn($db, 'el_submissions', 'plagiarism_checked_at', 'DATETIME NULL');
    }
}

if (!function_exists('elearningAssessmentCsrfToken')) {
    function elearningAssessmentCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['elearning_assessment_csrf']) || !is_string($_SESSION['elearning_assessment_csrf'])) {
            $_SESSION['elearning_assessment_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['elearning_assessment_csrf'];
    }
}

if (!function_exists('elearningAssessmentCsrfField')) {
    function elearningAssessmentCsrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(elearningAssessmentCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('elearningAssessmentValidateCsrf')) {
    function elearningAssessmentValidateCsrf(array $source): bool
    {
        $provided = (string)($source['csrf_token'] ?? '');
        return $provided !== '' && hash_equals(elearningAssessmentCsrfToken(), $provided);
    }
}

if (!function_exists('elearningAssessmentNormalizeDateTime')) {
    function elearningAssessmentNormalizeDateTime(string $value, array &$errors, string $label): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            $errors[] = "{$label} must be a valid date and time.";
            return null;
        }
        return $value;
    }
}

if (!function_exists('elearningAssessmentSettings')) {
    function elearningAssessmentSettings(?string $json, array $defaults = []): array
    {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        return array_replace_recursive($defaults, $decoded);
    }
}

if (!function_exists('elearningAssessmentJson')) {
    function elearningAssessmentJson(array $settings): string
    {
        return json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('elearningAssessmentQuizSettingsFromPost')) {
    function elearningAssessmentQuizSettingsFromPost(array $post): array
    {
        $maxAttempts = max(1, min(10, (int)($post['max_attempts'] ?? 1)));
        $timeLimit = trim((string)($post['time_limit'] ?? $post['time_limit_minutes'] ?? ''));
        $timeLimitMinutes = $timeLimit === '' ? null : max(1, min(480, (int)$timeLimit));

        return [
            'max_attempts' => $maxAttempts,
            'available_from' => trim((string)($post['available_from'] ?? '')),
            'time_limit_minutes' => $timeLimitMinutes,
            'shuffle_questions' => !empty($post['shuffle_questions']),
            'shuffle_options' => !empty($post['shuffle_options']),
            'require_fullscreen' => !empty($post['require_fullscreen']),
            'disable_copy_paste' => !empty($post['disable_copy_paste']),
            'show_result_immediately' => !empty($post['show_result_immediately']),
        ];
    }
}

if (!function_exists('elearningAssessmentAssignmentSettingsFromPost')) {
    function elearningAssessmentAssignmentSettingsFromPost(array $post): array
    {
        $allowedRaw = trim((string)($post['allowed_file_types'] ?? 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png'));
        $allowed = array_values(array_filter(array_map(static function ($ext) {
            return strtolower(preg_replace('/[^a-z0-9]/', '', trim($ext)));
        }, explode(',', $allowedRaw))));
        $allowed = array_values(array_intersect($allowed, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png']));
        if (!$allowed) {
            $allowed = ['pdf', 'doc', 'docx', 'txt'];
        }

        $allowText = !empty($post['allow_text_response']);
        $allowFile = !empty($post['allow_file_upload']);
        if (!$allowText && !$allowFile) {
            $allowText = true;
        }

        return [
            'allow_text_response' => $allowText,
            'allow_file_upload' => $allowFile,
            'allowed_file_types' => $allowed,
            'max_file_mb' => max(1, min(50, (int)($post['max_file_mb'] ?? 10))),
            'ai_check_enabled' => !empty($post['ai_check_enabled']),
            'plagiarism_check_enabled' => !empty($post['plagiarism_check_enabled']),
        ];
    }
}

if (!function_exists('elearningAssessmentQuizAvailability')) {
    function elearningAssessmentQuizAvailability(mysqli $db, array $quiz, string $sid): array
    {
        $settings = elearningAssessmentSettings($quiz['settings_json'] ?? null, ['max_attempts' => 1]);
        $now = time();
        $availableRaw = trim((string)($settings['available_from'] ?? ''));
        $availableTs = $availableRaw !== '' ? strtotime(str_replace('T', ' ', $availableRaw)) : false;
        if ($availableTs && $availableTs > $now) {
            return ['ok' => false, 'reason' => 'This assessment is not open yet.'];
        }

        $dueTs = !empty($quiz['due_at']) ? strtotime((string)$quiz['due_at']) : false;
        if ($dueTs && $dueTs < $now) {
            return ['ok' => false, 'reason' => 'This assessment is past its due date.'];
        }

        $submittedAttempts = 0;
        if ($stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM el_attempts WHERE quiz_id=? AND Sid=? AND submitted_at IS NOT NULL")) {
            $quizId = (int)($quiz['id'] ?? 0);
            $stmt->bind_param('is', $quizId, $sid);
            $stmt->execute();
            $res = $stmt->get_result();
            $submittedAttempts = (int)(($res ? $res->fetch_assoc() : [])['cnt'] ?? 0);
            $stmt->close();
        }

        $maxAttempts = max(1, (int)($settings['max_attempts'] ?? 1));
        if ($submittedAttempts >= $maxAttempts) {
            return ['ok' => false, 'reason' => 'You have used all allowed attempts for this assessment.'];
        }

        return ['ok' => true, 'settings' => $settings, 'submitted_attempts' => $submittedAttempts, 'max_attempts' => $maxAttempts];
    }
}

if (!function_exists('elearningAssessmentFindOrCreateAttempt')) {
    function elearningAssessmentFindOrCreateAttempt(mysqli $db, int $quizId, string $sid): int
    {
        if ($stmt = $db->prepare("SELECT id FROM el_attempts WHERE quiz_id=? AND Sid=? AND submitted_at IS NULL ORDER BY started_at DESC, id DESC LIMIT 1")) {
            $stmt->bind_param('is', $quizId, $sid);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                return (int)$row['id'];
            }
        }

        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        if ($stmt = $db->prepare("INSERT INTO el_attempts (quiz_id, Sid, ip_address, user_agent) VALUES (?, ?, ?, ?)")) {
            $stmt->bind_param('isss', $quizId, $sid, $ip, $ua);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
            return $id;
        }
        return 0;
    }
}

if (!function_exists('elearningAssessmentPlagiarismStatus')) {
    function elearningAssessmentPlagiarismStatus(?float $score): string
    {
        if ($score === null) {
            return 'skipped';
        }
        if ($score >= 75) {
            return 'high';
        }
        if ($score >= 45) {
            return 'medium';
        }
        return 'low';
    }
}

if (!function_exists('elearningAssessmentRunPlagiarismCheck')) {
    function elearningAssessmentRunPlagiarismCheck(mysqli $db, int $assignmentId, string $sid, string $text): array
    {
        $words = elearningAssessmentWords($text);
        if (count($words) < 40) {
            return [
                'provider' => 'local-similarity',
                'score' => null,
                'status' => 'skipped',
                'report' => 'Not enough extractable text for plagiarism comparison.',
            ];
        }

        $grams = elearningAssessmentNgrams($words, 5);
        $best = ['score' => 0.0, 'sid' => null, 'sample' => null];
        if ($stmt = $db->prepare("SELECT Sid, text_body FROM el_submissions WHERE assignment_id=? AND Sid<>? AND text_body IS NOT NULL AND text_body<>'' ORDER BY submitted_at DESC LIMIT 80")) {
            $stmt->bind_param('is', $assignmentId, $sid);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $otherWords = elearningAssessmentWords((string)($row['text_body'] ?? ''));
                if (count($otherWords) < 40) {
                    continue;
                }
                $score = elearningAssessmentJaccardPercent($grams, elearningAssessmentNgrams($otherWords, 5));
                if ($score > $best['score']) {
                    $best = ['score' => $score, 'sid' => (string)($row['Sid'] ?? ''), 'sample' => substr((string)($row['text_body'] ?? ''), 0, 140)];
                }
            }
            $stmt->close();
        }

        $score = round((float)$best['score'], 2);
        return [
            'provider' => 'local-similarity',
            'score' => $score,
            'status' => elearningAssessmentPlagiarismStatus($score),
            'report' => $best['sid']
                ? 'Highest local similarity was with another submission for this assignment. Matching student: ' . $best['sid'] . '.'
                : 'No comparable prior submission had meaningful overlap.',
        ];
    }
}

if (!function_exists('elearningAssessmentWords')) {
    function elearningAssessmentWords(string $text): array
    {
        preg_match_all('/[A-Za-z][A-Za-z\'-]*/', strtolower($text), $matches);
        return $matches[0] ?? [];
    }
}

if (!function_exists('elearningAssessmentNgrams')) {
    function elearningAssessmentNgrams(array $words, int $n): array
    {
        $grams = [];
        for ($i = 0, $max = count($words) - $n; $i <= $max; $i++) {
            $grams[implode(' ', array_slice($words, $i, $n))] = true;
        }
        return $grams;
    }
}

if (!function_exists('elearningAssessmentJaccardPercent')) {
    function elearningAssessmentJaccardPercent(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }
        $intersection = count(array_intersect_key($a, $b));
        $union = count($a + $b);
        return $union > 0 ? ($intersection / $union) * 100.0 : 0.0;
    }
}
