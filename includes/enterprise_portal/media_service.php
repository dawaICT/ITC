<?php
declare(strict_types=1);

/**
 * Secure media uploads for enterprise opportunities.
 */

if (!function_exists('ep_media_storage_dir')) {
    function ep_media_storage_dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/enterprise_portal/media';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }
}

if (!function_exists('ep_upload_opportunity_media')) {
    /**
     * @param array<string,mixed> $file $_FILES entry
     * @return array{ok:bool,message:string,id?:int}
     */
    function ep_upload_opportunity_media(mysqli $db, int $opportunityId, array $file, bool $isPrimary = false): array
    {
        try {
            $ext = wucValidateUpload($file, 'image');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $info = @getimagesize($file['tmp_name']);
        if (!$info) {
            return ['ok' => false, 'message' => 'File is not a valid image.'];
        }
        if (($info[0] ?? 0) > 4000 || ($info[1] ?? 0) > 4000) {
            return ['ok' => false, 'message' => 'Image dimensions must be 4000px or less.'];
        }

        $countStmt = $db->prepare('SELECT COUNT(*) c FROM enterprise_media WHERE enterprise_opportunity_id = ?');
        $countStmt->bind_param('i', $opportunityId);
        $countStmt->execute();
        $count = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $countStmt->close();
        if ($count >= 8) {
            return ['ok' => false, 'message' => 'Maximum of 8 media files per opportunity.'];
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $subdir = date('Y/m');
        $destDir = ep_media_storage_dir() . '/' . $subdir;
        if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
            return ['ok' => false, 'message' => 'Could not prepare storage directory.'];
        }
        $destPath = $destDir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['ok' => false, 'message' => 'Could not store uploaded file.'];
        }

        $relPath = 'storage/enterprise_portal/media/' . $subdir . '/' . $stored;
        $hash = hash_file('sha256', $destPath) ?: '';
        $mime = (string)($info['mime'] ?? 'image/' . $ext);
        $size = (int)($file['size'] ?? 0);
        $orig = basename((string)($file['name'] ?? 'image'));
        $actor = ep_current_actor();

        if ($isPrimary) {
            $clr = $db->prepare('UPDATE enterprise_media SET is_primary = 0 WHERE enterprise_opportunity_id = ?');
            $clr->bind_param('i', $opportunityId);
            $clr->execute();
            $clr->close();
        }

        $stmt = $db->prepare('INSERT INTO enterprise_media
            (enterprise_opportunity_id, file_path, stored_filename, original_filename, mime_type, file_size, media_type, is_primary, sort_order, file_hash, uploaded_by)
            VALUES (?,?,?,?,?,?,\'image\',?,?,?,?)');
        $primary = ($isPrimary || $count === 0) ? 1 : 0;
        $sort = $count;
        // i + 4s + 3i + 2s
        $types = 'i' . 'ssss' . 'iii' . 'ss';
        $stmt->bind_param($types, $opportunityId, $relPath, $stored, $orig, $mime, $size, $primary, $sort, $hash, $actor);
        $stmt->execute();
        $id = (int)$db->insert_id;
        $stmt->close();
        ep_audit($db, 'enterprise_portal.media_uploaded', ['media_id' => $id, 'opportunity_id' => $opportunityId]);
        return ['ok' => true, 'message' => 'Image uploaded.', 'id' => $id];
    }
}

if (!function_exists('ep_list_media')) {
    function ep_list_media(mysqli $db, int $opportunityId): array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_media WHERE enterprise_opportunity_id = ? ORDER BY is_primary DESC, sort_order, id');
        $stmt->bind_param('i', $opportunityId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }
}
