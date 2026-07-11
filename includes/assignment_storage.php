<?php
declare(strict_types=1);

/**
 * Assignment submission storage, Drive archival, and AI review helpers.
 *
 * Google Drive is intentionally optional. Configure these environment values to enable it:
 * - ASSIGNMENT_DRIVE_ENABLED=1
 * - GOOGLE_DRIVE_ASSIGNMENTS_ROOT_FOLDER_ID=<shared folder id>
 * - GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON=<absolute path to service-account JSON>
 *   or GOOGLE_APPLICATION_CREDENTIALS=<absolute path to service-account JSON>
 * - ASSIGNMENT_DRIVE_DELETE_LOCAL=1
 *
 * Optional open-source detector adapter:
 * - AI_DETECTOR_COMMAND=<command that prints JSON or a numeric score>
 *   Use {file} as a placeholder for a temporary UTF-8 text file path.
 */

if (!function_exists('assignmentStorageConfig')) {
    function assignmentStorageConfig(): array
    {
        $configPath = dirname(__DIR__) . '/config/elearning.php';
        $portalConfig = is_file($configPath) ? (require $configPath) : [];
        $localConfigPath = dirname(__DIR__) . '/config/assignment_storage.local.php';
        $localConfig = is_file($localConfigPath) ? (require $localConfigPath) : [];
        $assignmentConfig = is_array($portalConfig) ? ($portalConfig['assignments'] ?? []) : [];
        if (is_array($localConfig) && $localConfig) {
            $assignmentConfig = array_replace_recursive($assignmentConfig, $localConfig);
        }

        $drive = $assignmentConfig['google_drive'] ?? [];
        $ai = $assignmentConfig['ai_detector'] ?? [];

        return [
            'archive_mode' => (string)($assignmentConfig['archive_mode'] ?? 'google_drive'),
            'local_archive_path' => (string)($assignmentConfig['local_archive_path'] ?? (dirname(__DIR__, 2) . '/wucportal-assignment-archive')),
            'online_archive_url' => (string)($assignmentConfig['online_archive_url'] ?? 'https://drive.google.com/drive/my-drive'),
            'online_archive_provider' => (string)($assignmentConfig['online_archive_provider'] ?? 'Google Drive'),
            'drive_enabled' => assignmentStorageBoolEnv('ASSIGNMENT_DRIVE_ENABLED', (bool)($drive['enabled'] ?? false)),
            'drive_root_folder_id' => (string)(getenv('GOOGLE_DRIVE_ASSIGNMENTS_ROOT_FOLDER_ID') ?: ($drive['root_folder_id'] ?? '')),
            'service_account_json' => (string)(getenv('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON') ?: getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ($drive['service_account_json_path'] ?? '')),
            'delete_local_after_drive' => assignmentStorageBoolEnv('ASSIGNMENT_DRIVE_DELETE_LOCAL', (bool)($drive['delete_local_after_upload'] ?? true)),
            'ai_enabled' => assignmentStorageBoolEnv('AI_DETECTOR_ENABLED', (bool)($ai['enabled'] ?? true)),
            'ai_command' => (string)(getenv('AI_DETECTOR_COMMAND') ?: ($ai['command'] ?? '')),
            'ai_min_words' => (int)(getenv('AI_DETECTOR_MIN_WORDS') ?: ($ai['min_words'] ?? 80)),
        ];
    }
}

if (!function_exists('assignmentStorageBoolEnv')) {
    function assignmentStorageBoolEnv(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('assignmentStorageEnsureSchema')) {
    function assignmentStorageEnsureSchema(mysqli $db): bool
    {
        static $done = false;
        if ($done) {
            return true;
        }
        $done = true;

        $tableExists = false;
        if ($res = @$db->query("SHOW TABLES LIKE 'el_submissions'")) {
            $tableExists = $res->num_rows > 0;
            $res->free();
        }
        if (!$tableExists) {
            return false;
        }

        $columns = assignmentStorageTableColumns($db, 'el_submissions');
        $needed = [
            'storage_provider' => "VARCHAR(40) NULL DEFAULT 'local'",
            'drive_file_id' => 'VARCHAR(255) NULL',
            'drive_web_url' => 'VARCHAR(1000) NULL',
            'drive_folder_id' => 'VARCHAR(255) NULL',
            'archive_file_path' => 'VARCHAR(1000) NULL',
            'archive_original_name' => 'VARCHAR(255) NULL',
            'archive_moved_at' => 'DATETIME NULL',
            'local_file_deleted_at' => 'DATETIME NULL',
            'ai_detector_provider' => 'VARCHAR(80) NULL',
            'ai_score' => 'DECIMAL(5,2) NULL',
            'ai_status' => 'VARCHAR(40) NULL',
            'ai_report' => 'TEXT NULL',
            'ai_checked_at' => 'DATETIME NULL',
        ];

        foreach ($needed as $column => $definition) {
            if (!isset($columns[strtolower($column)])) {
                try {
                    if (!$db->query("ALTER TABLE el_submissions ADD COLUMN {$column} {$definition}")) {
                        error_log('assignment_storage: failed to add el_submissions.' . $column . ': ' . $db->error);
                    }
                } catch (Throwable $e) {
                    error_log('assignment_storage: cannot add el_submissions.' . $column
                        . ' at runtime (apply migrations as a DDL-capable user): ' . $e->getMessage());
                }
            }
        }

        return true;
    }
}

if (!function_exists('assignmentStorageTableColumns')) {
    function assignmentStorageTableColumns(mysqli $db, string $table): array
    {
        $columns = [];
        $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        if ($safeTable === '') {
            return $columns;
        }
        if ($res = @$db->query("SHOW COLUMNS FROM `{$safeTable}`")) {
            while ($row = $res->fetch_assoc()) {
                $columns[strtolower((string)$row['Field'])] = true;
            }
            $res->free();
        }
        return $columns;
    }
}

if (!function_exists('assignmentStoragePortalRoot')) {
    function assignmentStoragePortalRoot(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('assignmentStorageAbsolutePath')) {
    function assignmentStorageAbsolutePath(?string $relativePath): ?string
    {
        $path = trim((string)$relativePath);
        if ($path === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z]:[\/\\\\]/', $path) || str_starts_with($path, '/')) {
            return $path;
        }
        return assignmentStoragePortalRoot() . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('assignmentStoragePublicUrl')) {
    function assignmentStoragePublicUrl(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '#';
        }
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }
        return '/wucportal/' . ltrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('assignmentStorageSubmissionUrl')) {
    function assignmentStorageSubmissionUrl($submission): string
    {
        $row = is_object($submission) ? get_object_vars($submission) : (array)$submission;
        $driveUrl = trim((string)($row['drive_web_url'] ?? ''));
        if ($driveUrl !== '') {
            return $driveUrl;
        }
        if (!empty($row['archive_file_path']) && !empty($row['submission_id'])) {
            return '/wucportal/elearning/submission_download.php?submission_id=' . urlencode((string)$row['submission_id']);
        }
        if (!empty($row['archive_file_path']) && !empty($row['id'])) {
            return '/wucportal/elearning/submission_download.php?submission_id=' . urlencode((string)$row['id']);
        }
        return assignmentStoragePublicUrl($row['file_path'] ?? '');
    }
}

if (!function_exists('assignmentStorageExtractText')) {
    function assignmentStorageExtractText(?string $absolutePath, string $textBody = ''): string
    {
        $parts = [];
        $textBody = trim($textBody);
        if ($textBody !== '') {
            $parts[] = $textBody;
        }

        if ($absolutePath && is_file($absolutePath)) {
            $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
            if ($ext === 'txt') {
                $raw = @file_get_contents($absolutePath);
                if (is_string($raw)) {
                    $parts[] = trim($raw);
                }
            } elseif ($ext === 'docx' && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($absolutePath) === true) {
                    $xml = $zip->getFromName('word/document.xml');
                    $zip->close();
                    if (is_string($xml)) {
                        $xml = preg_replace('/<\/w:p>/', "\n", $xml);
                        $parts[] = trim(html_entity_decode(strip_tags((string)$xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    }
                }
            }
        }

        return trim(implode("\n\n", array_filter($parts, static fn($part) => trim((string)$part) !== '')));
    }
}

if (!function_exists('assignmentStorageRunAiCheck')) {
    function assignmentStorageRunAiCheck(string $text, ?string $absolutePath = null): array
    {
        $config = assignmentStorageConfig();
        $words = assignmentStorageWords($text);
        if (!$config['ai_enabled']) {
            return [
                'provider' => null,
                'score' => null,
                'status' => 'disabled',
                'report' => 'AI review is disabled.',
            ];
        }
        if (count($words) < max(20, (int)$config['ai_min_words'])) {
            return [
                'provider' => 'open-source-local-baseline',
                'score' => null,
                'status' => 'skipped',
                'report' => 'Not enough extractable text for an AI-writing review.',
            ];
        }

        $command = trim((string)$config['ai_command']);
        if ($command !== '') {
            $external = assignmentStorageRunExternalDetector($command, $text, $absolutePath);
            if ($external['status'] !== 'failed') {
                return $external;
            }
            error_log('assignment_storage: external AI detector failed: ' . $external['report']);
        }

        return assignmentStorageRunBaselineDetector($text, $words);
    }
}

if (!function_exists('assignmentStorageWords')) {
    function assignmentStorageWords(string $text): array
    {
        preg_match_all('/[A-Za-z][A-Za-z\'-]*/', strtolower($text), $matches);
        return $matches[0] ?? [];
    }
}

if (!function_exists('assignmentStorageRunExternalDetector')) {
    function assignmentStorageRunExternalDetector(string $command, string $text, ?string $absolutePath = null): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'ai_submission_');
        if ($temp === false) {
            return ['provider' => 'open-source-cli', 'score' => null, 'status' => 'failed', 'report' => 'Could not create detector input file.'];
        }
        file_put_contents($temp, $text);
        $textPath = $temp;

        $prepared = str_contains($command, '{file}')
            ? str_replace('{file}', escapeshellarg($textPath), $command)
            : $command . ' ' . escapeshellarg($textPath);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($prepared, $descriptors, $pipes, assignmentStoragePortalRoot());
        if (!is_resource($process)) {
            @unlink($temp);
            return ['provider' => 'open-source-cli', 'score' => null, 'status' => 'failed', 'report' => 'Detector command could not start.'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        @unlink($temp);

        if ($exitCode !== 0) {
            return ['provider' => 'open-source-cli', 'score' => null, 'status' => 'failed', 'report' => trim((string)$stderr) ?: 'Detector command exited with code ' . $exitCode . '.'];
        }

        $parsed = json_decode(trim((string)$stdout), true);
        if (is_array($parsed)) {
            $score = isset($parsed['score']) ? max(0.0, min(100.0, (float)$parsed['score'])) : null;
            return [
                'provider' => (string)($parsed['provider'] ?? 'open-source-cli'),
                'score' => $score,
                'status' => (string)($parsed['status'] ?? assignmentStorageAiStatus($score)),
                'report' => (string)($parsed['report'] ?? 'Open-source detector completed.'),
            ];
        }

        if (preg_match('/\d+(?:\.\d+)?/', (string)$stdout, $m)) {
            $score = max(0.0, min(100.0, (float)$m[0]));
            return [
                'provider' => 'open-source-cli',
                'score' => $score,
                'status' => assignmentStorageAiStatus($score),
                'report' => 'Open-source detector returned a numeric likelihood score.',
            ];
        }

        return ['provider' => 'open-source-cli', 'score' => null, 'status' => 'failed', 'report' => 'Detector output was not JSON or a numeric score.'];
    }
}

if (!function_exists('assignmentStorageRunBaselineDetector')) {
    function assignmentStorageRunBaselineDetector(string $text, array $words): array
    {
        $wordCount = max(1, count($words));
        $uniqueRatio = count(array_unique($words)) / $wordCount;
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lengths = [];
        foreach ($sentences as $sentence) {
            $lengths[] = max(1, count(assignmentStorageWords($sentence)));
        }
        $avgSentence = $lengths ? array_sum($lengths) / count($lengths) : 0.0;
        $variance = 0.0;
        foreach ($lengths as $length) {
            $variance += ($length - $avgSentence) ** 2;
        }
        $std = $lengths ? sqrt($variance / count($lengths)) : 0.0;
        $regularity = $avgSentence > 0 ? max(0.0, 1.0 - min(1.0, $std / $avgSentence)) : 0.0;
        $repeatRatio = assignmentStorageRepeatedTrigramRatio($words);

        $score = 20.0;
        $score += max(0.0, (0.52 - $uniqueRatio) * 90.0);
        $score += $regularity * 25.0;
        $score += min(25.0, $repeatRatio * 180.0);
        if ($avgSentence > 22) {
            $score += min(12.0, ($avgSentence - 22) * 0.8);
        }
        $score = max(0.0, min(100.0, $score));

        return [
            'provider' => 'open-source-local-baseline',
            'score' => round($score, 2),
            'status' => assignmentStorageAiStatus($score),
            'report' => sprintf(
                'Baseline review only. It checks lexical diversity, sentence regularity, and repeated phrasing; it is a triage signal, not evidence. Words: %d, unique ratio: %.2f, average sentence length: %.1f.',
                $wordCount,
                $uniqueRatio,
                $avgSentence
            ),
        ];
    }
}

if (!function_exists('assignmentStorageRepeatedTrigramRatio')) {
    function assignmentStorageRepeatedTrigramRatio(array $words): float
    {
        if (count($words) < 6) {
            return 0.0;
        }
        $grams = [];
        for ($i = 0, $n = count($words) - 2; $i < $n; $i++) {
            $gram = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
            $grams[$gram] = ($grams[$gram] ?? 0) + 1;
        }
        $repeated = 0;
        foreach ($grams as $count) {
            if ($count > 1) {
                $repeated += $count - 1;
            }
        }
        return $repeated / max(1, count($grams));
    }
}

if (!function_exists('assignmentStorageAiStatus')) {
    function assignmentStorageAiStatus(?float $score): string
    {
        if ($score === null) {
            return 'unknown';
        }
        if ($score >= 70) {
            return 'high';
        }
        if ($score >= 40) {
            return 'medium';
        }
        return 'low';
    }
}

if (!function_exists('assignmentStorageArchiveSubmission')) {
    function assignmentStorageArchiveSubmission(mysqli $db, array $submission): array
    {
        assignmentStorageEnsureSchema($db);

        $existingDriveId = trim((string)($submission['drive_file_id'] ?? ''));
        $existingArchivePath = trim((string)($submission['archive_file_path'] ?? ''));
        if ($existingDriveId !== '' || $existingArchivePath !== '') {
            return ['ok' => true, 'status' => 'skipped', 'message' => 'Already archived.'];
        }

        $relativePath = trim((string)($submission['file_path'] ?? ''));
        $absolutePath = assignmentStorageAbsolutePath($relativePath);
        if (!$absolutePath || !is_file($absolutePath)) {
            return ['ok' => false, 'status' => 'missing_file', 'message' => 'Local submission file was not found.'];
        }

        $upload = assignmentStorageArchiveFile($absolutePath, [
            'submission_id' => (int)($submission['submission_id'] ?? $submission['id'] ?? 0),
            'assignment_id' => (int)($submission['assignment_id'] ?? 0),
            'assignment_title' => (string)($submission['assignment_title'] ?? $submission['title'] ?? 'Assignment'),
            'course_code' => (string)($submission['course_code'] ?? ''),
            'lecturer_id' => (string)($submission['created_by'] ?? $submission['lecturer_id'] ?? ''),
            'student_sid' => (string)($submission['Sid'] ?? ''),
        ]);

        if (empty($upload['ok'])) {
            return ['ok' => false, 'status' => 'drive_failed', 'message' => (string)($upload['message'] ?? 'Google Drive upload failed.')];
        }

        $provider = (string)($upload['provider'] ?? 'archive');
        $fileId = (string)($upload['file_id'] ?? '');
        $webUrl = (string)($upload['web_url'] ?? '');
        $folderId = (string)($upload['folder_id'] ?? '');
        $archivePath = (string)($upload['archive_file_path'] ?? '');
        $archiveOriginalName = basename($absolutePath);
        $archiveMovedAt = $archivePath !== '' ? date('Y-m-d H:i:s') : null;
        $submissionId = (int)($submission['submission_id'] ?? $submission['id'] ?? 0);

        if ($stmt = $db->prepare("UPDATE el_submissions SET storage_provider=?, drive_file_id=?, drive_web_url=?, drive_folder_id=?, archive_file_path=?, archive_original_name=?, archive_moved_at=?, local_file_deleted_at=? WHERE id=?")) {
            $deletedAt = null;
            $stmt->bind_param('ssssssssi', $provider, $fileId, $webUrl, $folderId, $archivePath, $archiveOriginalName, $archiveMovedAt, $deletedAt, $submissionId);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                return ['ok' => false, 'status' => 'database_failed', 'message' => 'Archive completed but the portal could not save the archive link: ' . $error];
            }
            $stmt->close();
        } else {
            return ['ok' => false, 'status' => 'database_failed', 'message' => 'Archive completed but the portal could not prepare the archive update.'];
        }

        if (!empty($upload['delete_local']) && is_file($absolutePath) && @unlink($absolutePath)) {
            $deletedAt = date('Y-m-d H:i:s');
            if ($stmt = $db->prepare("UPDATE el_submissions SET local_file_deleted_at=? WHERE id=?")) {
                $stmt->bind_param('si', $deletedAt, $submissionId);
                $stmt->execute();
                $stmt->close();
            }
        }

        return ['ok' => true, 'status' => 'archived', 'message' => $provider === 'google_drive' ? 'Archived to Google Drive.' : 'Moved to local archive.', 'web_url' => $webUrl, 'file_id' => $fileId, 'archive_file_path' => $archivePath];
    }
}

if (!function_exists('assignmentStorageArchiveFile')) {
    function assignmentStorageArchiveFile(string $absolutePath, array $meta): array
    {
        $config = assignmentStorageConfig();
        if (($config['archive_mode'] ?? 'google_drive') === 'local_archive') {
            return assignmentStorageArchiveToLocal($absolutePath, $meta);
        }
        return assignmentStorageUploadToDrive($absolutePath, $meta);
    }
}

if (!function_exists('assignmentStorageArchiveToLocal')) {
    function assignmentStorageArchiveToLocal(string $absolutePath, array $meta): array
    {
        if (!is_file($absolutePath)) {
            return ['ok' => false, 'enabled' => true, 'message' => 'File does not exist.'];
        }
        $config = assignmentStorageConfig();
        $root = trim((string)($config['local_archive_path'] ?? ''));
        if ($root === '') {
            return ['ok' => false, 'enabled' => true, 'message' => 'Local archive path is not configured.'];
        }

        $targetDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . assignmentStorageFolderName('Lecturer', (string)($meta['lecturer_id'] ?? 'unknown'))
            . DIRECTORY_SEPARATOR . assignmentStorageFolderName('Student', (string)($meta['student_sid'] ?? 'unknown'));
        $course = trim((string)($meta['course_code'] ?? ''));
        if ($course !== '') {
            $targetDir .= DIRECTORY_SEPARATOR . assignmentStorageFolderName('Course', $course);
        }
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return ['ok' => false, 'enabled' => true, 'message' => 'Could not create local archive folder: ' . $targetDir];
        }

        $base = pathinfo($absolutePath, PATHINFO_FILENAME);
        $ext = pathinfo($absolutePath, PATHINFO_EXTENSION);
        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?: 'submission';
        $suffix = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $targetName = $safeBase . '_' . $suffix . ($ext !== '' ? '.' . $ext : '');
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $targetName;

        if (!@copy($absolutePath, $targetPath)) {
            return ['ok' => false, 'enabled' => true, 'message' => 'Could not copy file to local archive.'];
        }
        assignmentStorageAppendManifest($root, $targetPath, $meta);

        return [
            'ok' => true,
            'enabled' => true,
            'provider' => 'local_archive',
            'archive_file_path' => $targetPath,
            'folder_id' => $targetDir,
            'delete_local' => true,
        ];
    }
}

if (!function_exists('assignmentStorageAppendManifest')) {
    function assignmentStorageAppendManifest(string $root, string $targetPath, array $meta): void
    {
        $manifest = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'assignment_archive_manifest.csv';
        $isNew = !is_file($manifest);
        $fh = @fopen($manifest, 'ab');
        if (!$fh) {
            return;
        }
        if ($isNew) {
            fputcsv($fh, ['archived_at', 'lecturer_id', 'student_sid', 'course_code', 'assignment_id', 'submission_id', 'archive_file_path']);
        }
        fputcsv($fh, [
            date('Y-m-d H:i:s'),
            (string)($meta['lecturer_id'] ?? ''),
            (string)($meta['student_sid'] ?? ''),
            (string)($meta['course_code'] ?? ''),
            (string)($meta['assignment_id'] ?? ''),
            (string)($meta['submission_id'] ?? ''),
            $targetPath,
        ]);
        fclose($fh);
    }
}

if (!function_exists('assignmentStorageCreateOnlineBundle')) {
    function assignmentStorageCreateOnlineBundle(array $archiveFiles, string $label = 'assignment_archive'): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'PHP ZipArchive is not available.'];
        }
        $config = assignmentStorageConfig();
        $root = trim((string)($config['local_archive_path'] ?? ''));
        if ($root === '') {
            return ['ok' => false, 'message' => 'Local archive path is not configured.'];
        }
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        $bundleDir = $root . DIRECTORY_SEPARATOR . '_online_uploads';
        if (!is_dir($bundleDir) && !@mkdir($bundleDir, 0775, true) && !is_dir($bundleDir)) {
            return ['ok' => false, 'message' => 'Could not create online upload bundle folder.'];
        }

        $safeLabel = preg_replace('/[^A-Za-z0-9._-]/', '_', $label) ?: 'assignment_archive';
        $zipPath = $bundleDir . DIRECTORY_SEPARATOR . $safeLabel . '_' . date('Ymd_His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'message' => 'Could not create archive zip bundle.'];
        }

        $manifestRows = [['student_sid', 'course_code', 'assignment', 'archive_file']];
        $added = 0;
        foreach ($archiveFiles as $file) {
            $path = (string)($file['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                continue;
            }
            $relative = assignmentStorageRelativeToRoot($path, $root);
            $zipPathInside = $relative !== '' ? $relative : basename($path);
            if ($zip->addFile($path, $zipPathInside)) {
                $added++;
                $manifestRows[] = [
                    (string)($file['sid'] ?? ''),
                    (string)($file['course'] ?? ''),
                    (string)($file['assignment'] ?? ''),
                    $zipPathInside,
                ];
            }
        }

        $manifestText = '';
        foreach ($manifestRows as $row) {
            $escaped = array_map(static function ($value): string {
                $value = str_replace('"', '""', (string)$value);
                return '"' . $value . '"';
            }, $row);
            $manifestText .= implode(',', $escaped) . "\r\n";
        }
        $zip->addFromString('manifest.csv', $manifestText);
        $zip->close();

        if ($added === 0) {
            @unlink($zipPath);
            return ['ok' => false, 'message' => 'No archived files were available to bundle.'];
        }

        return ['ok' => true, 'path' => $zipPath, 'count' => $added];
    }
}

if (!function_exists('assignmentStorageRelativeToRoot')) {
    function assignmentStorageRelativeToRoot(string $path, string $root): string
    {
        $pathNorm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $rootNorm = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (stripos($pathNorm, $rootNorm) === 0) {
            return ltrim(substr($pathNorm, strlen($rootNorm)), DIRECTORY_SEPARATOR);
        }
        return '';
    }
}

if (!function_exists('assignmentStorageUploadToDrive')) {
    function assignmentStorageUploadToDrive(string $absolutePath, array $meta): array
    {
        $config = assignmentStorageConfig();
        if (!$config['drive_enabled']) {
            return ['ok' => false, 'enabled' => false, 'message' => 'Google Drive archival is not enabled.'];
        }
        if (!is_file($absolutePath)) {
            return ['ok' => false, 'enabled' => true, 'message' => 'File does not exist.'];
        }
        if (!function_exists('curl_init') || !function_exists('openssl_sign')) {
            return ['ok' => false, 'enabled' => true, 'message' => 'PHP cURL and OpenSSL extensions are required for Google Drive upload.'];
        }
        $token = assignmentStorageGoogleAccessToken((string)$config['service_account_json']);
        if (!$token['ok']) {
            return ['ok' => false, 'enabled' => true, 'message' => $token['message']];
        }

        $accessToken = (string)$token['access_token'];
        $rootFolderId = (string)$config['drive_root_folder_id'];
        $lecturerFolder = assignmentStorageDriveEnsureFolder(
            $accessToken,
            assignmentStorageFolderName('Lecturer', (string)($meta['lecturer_id'] ?? 'unknown')),
            assignmentStorageIsPlaceholder($rootFolderId) ? null : $rootFolderId
        );
        if (!$lecturerFolder['ok']) {
            return ['ok' => false, 'enabled' => true, 'message' => $lecturerFolder['message']];
        }
        $studentFolder = assignmentStorageDriveEnsureFolder($accessToken, assignmentStorageFolderName('Student', (string)($meta['student_sid'] ?? 'unknown')), (string)$lecturerFolder['id']);
        if (!$studentFolder['ok']) {
            return ['ok' => false, 'enabled' => true, 'message' => $studentFolder['message']];
        }
        $course = trim((string)($meta['course_code'] ?? ''));
        $targetParent = (string)$studentFolder['id'];
        if ($course !== '') {
            $courseFolder = assignmentStorageDriveEnsureFolder($accessToken, assignmentStorageFolderName('Course', $course), $targetParent);
            if ($courseFolder['ok']) {
                $targetParent = (string)$courseFolder['id'];
            }
        }

        $upload = assignmentStorageDriveUploadFile($accessToken, $absolutePath, $targetParent, $meta);
        if (!$upload['ok']) {
            return ['ok' => false, 'enabled' => true, 'message' => $upload['message']];
        }

        return [
            'ok' => true,
            'enabled' => true,
            'file_id' => $upload['id'] ?? '',
            'web_url' => $upload['webViewLink'] ?? '',
            'folder_id' => $targetParent,
            'delete_local' => (bool)$config['delete_local_after_drive'],
        ];
    }
}

if (!function_exists('assignmentStorageGoogleAccessToken')) {
    function assignmentStorageGoogleAccessToken(string $jsonPath): array
    {
        $jsonPath = trim($jsonPath);
        if ($jsonPath === '' || !is_file($jsonPath)) {
            return ['ok' => false, 'message' => 'Google service-account JSON file is not configured or not readable.'];
        }
        $service = json_decode((string)file_get_contents($jsonPath), true);
        if (!is_array($service) || empty($service['client_email']) || empty($service['private_key'])) {
            return ['ok' => false, 'message' => 'Google service-account JSON is missing client_email or private_key.'];
        }
        $tokenUri = (string)($service['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $now = time();
        $header = assignmentStorageBase64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = assignmentStorageBase64Url(json_encode([
            'iss' => $service['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $payload = $header . '.' . $claim;
        $signature = '';
        if (!openssl_sign($payload, $signature, (string)$service['private_key'], OPENSSL_ALGO_SHA256)) {
            return ['ok' => false, 'message' => 'Could not sign Google service-account JWT.'];
        }
        $jwt = $payload . '.' . assignmentStorageBase64Url($signature);
        $response = assignmentStorageCurl($tokenUri, [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]));
        if (!$response['ok']) {
            return ['ok' => false, 'message' => $response['error']];
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            return ['ok' => false, 'message' => 'Google token endpoint did not return an access token.'];
        }
        return ['ok' => true, 'access_token' => (string)$data['access_token']];
    }
}

if (!function_exists('assignmentStorageBase64Url')) {
    function assignmentStorageBase64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('assignmentStorageDriveEnsureFolder')) {
    function assignmentStorageDriveEnsureFolder(string $accessToken, string $name, ?string $parentId = null): array
    {
        $parentId = $parentId !== null && !assignmentStorageIsPlaceholder($parentId) ? $parentId : null;
        $query = "mimeType='application/vnd.google-apps.folder' and trashed=false and name='" . assignmentStorageDriveQueryEscape($name) . "'";
        if ($parentId !== null) {
            $query .= " and '" . assignmentStorageDriveQueryEscape($parentId) . "' in parents";
        }
        $url = 'https://www.googleapis.com/drive/v3/files?fields=files(id,name)&q=' . rawurlencode($query);
        $response = assignmentStorageCurl($url, ['Authorization: Bearer ' . $accessToken], null, 'GET');
        if ($response['ok']) {
            $data = json_decode($response['body'], true);
            if (!empty($data['files'][0]['id'])) {
                return ['ok' => true, 'id' => (string)$data['files'][0]['id']];
            }
        }

        $metadata = [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];
        if ($parentId !== null) {
            $metadata['parents'] = [$parentId];
        }
        $create = assignmentStorageCurl('https://www.googleapis.com/drive/v3/files?fields=id,name', [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ], json_encode($metadata, JSON_UNESCAPED_SLASHES));

        if (!$create['ok']) {
            return ['ok' => false, 'message' => $create['error']];
        }
        $data = json_decode($create['body'], true);
        if (empty($data['id'])) {
            return ['ok' => false, 'message' => 'Google Drive did not return a folder id.'];
        }
        return ['ok' => true, 'id' => (string)$data['id']];
    }
}

if (!function_exists('assignmentStorageDriveUploadFile')) {
    function assignmentStorageDriveUploadFile(string $accessToken, string $absolutePath, string $parentId, array $meta): array
    {
        $name = basename($absolutePath);
        $mime = assignmentStorageMimeType($absolutePath);
        $metadata = [
            'name' => $name,
            'parents' => [$parentId],
            'description' => sprintf(
                'ITC Portal submission %s, assignment %s, student %s',
                (string)($meta['submission_id'] ?? ''),
                (string)($meta['assignment_id'] ?? ''),
                (string)($meta['student_sid'] ?? '')
            ),
        ];
        $boundary = 'wucportal_' . bin2hex(random_bytes(12));
        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($metadata) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$mime}\r\n\r\n"
            . (string)file_get_contents($absolutePath) . "\r\n"
            . "--{$boundary}--";

        $response = assignmentStorageCurl(
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink,webContentLink',
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: multipart/related; boundary=' . $boundary,
            ],
            $body
        );
        if (!$response['ok']) {
            return ['ok' => false, 'message' => $response['error']];
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['id'])) {
            return ['ok' => false, 'message' => 'Google Drive upload did not return a file id.'];
        }
        $data['ok'] = true;
        return $data;
    }
}

if (!function_exists('assignmentStorageCurl')) {
    function assignmentStorageCurl(string $url, array $headers, ?string $body = null, string $method = 'POST'): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code < 200 || $code >= 300) {
            $message = $error !== '' ? $error : ('HTTP ' . $code . ': ' . substr((string)$raw, 0, 500));
            return ['ok' => false, 'body' => (string)$raw, 'error' => $message, 'code' => $code];
        }
        return ['ok' => true, 'body' => (string)$raw, 'code' => $code];
    }
}

if (!function_exists('assignmentStorageDriveQueryEscape')) {
    function assignmentStorageDriveQueryEscape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}

if (!function_exists('assignmentStorageFolderName')) {
    function assignmentStorageFolderName(string $prefix, string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._ -]/', '_', trim($value));
        $value = trim((string)$value, " ._-\t\n\r\0\x0B");
        if ($value === '') {
            $value = 'unknown';
        }
        return substr($prefix . '_' . $value, 0, 120);
    }
}

if (!function_exists('assignmentStorageMimeType')) {
    function assignmentStorageMimeType(string $absolutePath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $absolutePath);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}

if (!function_exists('assignmentStorageConfigStatus')) {
    function assignmentStorageConfigStatus(): array
    {
        $config = assignmentStorageConfig();
        $archiveMode = (string)($config['archive_mode'] ?? 'google_drive');
        if ($archiveMode === 'local_archive') {
            $archiveRoot = trim((string)($config['local_archive_path'] ?? ''));
            $ready = $archiveRoot !== '';
            return [
                'drive_enabled' => false,
                'drive_ready' => $ready,
                'drive_message' => $ready
                    ? 'Online archive is ready. Move submissions out of portal storage, then upload the generated bundle to your online drive.'
                    : 'To archive files, set local_archive_path in config/assignment_storage.local.php.',
                'archive_mode' => 'local_archive',
                'local_archive_path' => $archiveRoot,
                'online_archive_url' => (string)$config['online_archive_url'],
                'online_archive_provider' => (string)$config['online_archive_provider'],
                'drive_root_folder_id' => '',
                'service_account_json_path' => (string)$config['service_account_json'],
                'service_account_email' => '',
                'ai_enabled' => (bool)$config['ai_enabled'],
                'ai_provider' => trim((string)$config['ai_command']) !== '' ? 'open-source-cli' : 'open-source-local-baseline',
            ];
        }
        $folderMissing = assignmentStorageIsPlaceholder((string)$config['drive_root_folder_id']);
        $jsonValidation = assignmentStorageValidateServiceAccountJson((string)$config['service_account_json']);
        $jsonMissing = !$jsonValidation['ok'];
        $ready = $config['drive_enabled']
            && !$jsonMissing;

        $driveMessage = 'Google Drive archival is configured.';
        if (!$ready) {
            $missing = [];
            if (!$config['drive_enabled']) {
                $missing[] = 'enable Drive archival';
            }
            if ($jsonMissing) {
                $missing[] = $jsonValidation['message'];
            }
            $driveMessage = 'To archive files, ' . implode(', ', $missing) . '.';
        } elseif ($folderMissing) {
            $driveMessage = 'Google Drive archival is configured. No root folder ID is set, so files will be created in the service account Drive.';
        }

        return [
            'drive_enabled' => (bool)$config['drive_enabled'],
            'drive_ready' => $ready,
            'drive_message' => $driveMessage,
            'archive_mode' => 'google_drive',
            'local_archive_path' => (string)$config['local_archive_path'],
            'online_archive_url' => (string)$config['online_archive_url'],
            'online_archive_provider' => (string)$config['online_archive_provider'],
            'drive_root_folder_id' => $folderMissing ? '' : (string)$config['drive_root_folder_id'],
            'service_account_json_path' => (string)$config['service_account_json'],
            'service_account_email' => (string)($jsonValidation['client_email'] ?? ''),
            'ai_enabled' => (bool)$config['ai_enabled'],
            'ai_provider' => trim((string)$config['ai_command']) !== '' ? 'open-source-cli' : 'open-source-local-baseline',
        ];
    }
}

if (!function_exists('assignmentStorageValidateServiceAccountJson')) {
    function assignmentStorageValidateServiceAccountJson(string $jsonPath): array
    {
        $jsonPath = trim($jsonPath);
        if (assignmentStorageIsPlaceholder($jsonPath)) {
            return ['ok' => false, 'message' => 'set a readable service-account JSON path'];
        }
        if (!is_file($jsonPath)) {
            return ['ok' => false, 'message' => 'service-account JSON file was not found at ' . $jsonPath];
        }
        if (!is_readable($jsonPath)) {
            return ['ok' => false, 'message' => 'service-account JSON file is not readable at ' . $jsonPath];
        }
        $data = json_decode((string)file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'service-account JSON is not valid JSON'];
        }
        if (($data['type'] ?? '') !== 'service_account') {
            return ['ok' => false, 'message' => 'JSON file is not a Google service-account key'];
        }
        foreach (['client_email', 'private_key', 'token_uri'] as $field) {
            if (empty($data[$field])) {
                return ['ok' => false, 'message' => 'service-account JSON is missing ' . $field];
            }
        }
        return ['ok' => true, 'client_email' => (string)$data['client_email']];
    }
}

if (!function_exists('assignmentStorageIsPlaceholder')) {
    function assignmentStorageIsPlaceholder(string $value): bool
    {
        $value = trim($value);
        return $value === ''
            || str_contains($value, 'PASTE_')
            || str_contains($value, '_HERE')
            || str_contains($value, 'your_')
            || str_contains($value, 'example');
    }
}
