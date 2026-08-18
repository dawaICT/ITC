<?php
declare(strict_types=1);

if (!function_exists('eh_media_root')) {
    function eh_media_root(): string
    {
        $root = dirname(__DIR__, 2) . '/storage/enterprise_hub';
        foreach (['media', 'thumbnails'] as $sub) {
            $dir = $root . '/' . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
        }
        // Deny web execution
        $ht = $root . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        return $root;
    }
}

if (!function_exists('eh_count_item_media')) {
    function eh_count_item_media(mysqli $db, int $itemId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) c FROM enterprise_item_media WHERE enterprise_item_id = ?');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $c;
    }
}

if (!function_exists('eh_item_has_primary_media')) {
    function eh_item_has_primary_media(mysqli $db, int $itemId): bool
    {
        $stmt = $db->prepare('SELECT id FROM enterprise_item_media WHERE enterprise_item_id = ? AND is_primary = 1 LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('eh_list_media')) {
    function eh_list_media(mysqli $db, int $itemId): array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_item_media WHERE enterprise_item_id = ? ORDER BY is_primary DESC, sort_order, id');
        $stmt->bind_param('i', $itemId);
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

if (!function_exists('eh_upload_item_image')) {
    /**
     * @return array{ok:bool, message:string, media_id?:int}
     */
    function eh_upload_item_image(mysqli $db, int $itemId, array $file, string $uploadedBy, string $caption = ''): array
    {
        $maxImages = (int)(eh_setting($db, 'max_images_per_item', '8') ?? 8);
        if (eh_count_item_media($db, $itemId) >= $maxImages) {
            return ['ok' => false, 'message' => "Maximum of {$maxImages} images allowed per item."];
        }

        try {
            $ext = wucValidateUpload($file, 'image');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $tmp = (string)$file['tmp_name'];
        $maxDim = (int)(eh_setting($db, 'max_image_dimension', '4000') ?? 4000);
        $info = @getimagesize($tmp);
        if (!$info) {
            return ['ok' => false, 'message' => 'File is not a valid image.'];
        }
        if ($info[0] > $maxDim || $info[1] > $maxDim) {
            return ['ok' => false, 'message' => "Image dimensions exceed {$maxDim}px."];
        }

        // Block polyglot / executable markers in first bytes
        $head = (string)@file_get_contents($tmp, false, null, 0, 64);
        if (preg_match('/<\?php|<script|MZ/i', $head)) {
            return ['ok' => false, 'message' => 'File failed security inspection.'];
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $rel = 'media/' . $stored;
        $dest = eh_media_root() . '/' . $rel;
        if (!move_uploaded_file($tmp, $dest)) {
            return ['ok' => false, 'message' => 'Could not store uploaded file.'];
        }
        @chmod($dest, 0640);

        // Thumbnail
        eh_create_thumbnail($dest, eh_media_root() . '/thumbnails/' . $stored, 400);

        $mime = $info['mime'] ?? ('image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
        $size = (int)filesize($dest);
        $orig = basename((string)($file['name'] ?? $stored));
        $orig = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $orig) ?: $stored;
        $isPrimary = eh_count_item_media($db, $itemId) === 0 ? 1 : 0;
        $sort = eh_count_item_media($db, $itemId);

        $stmt = $db->prepare("INSERT INTO enterprise_item_media (
            enterprise_item_id, file_path, original_filename, stored_filename, mime_type, file_size,
            media_type, caption, is_primary, sort_order, uploaded_by
        ) VALUES (?, ?, ?, ?, ?, ?, 'image', ?, ?, ?, ?)");
        $stmt->bind_param('issssisiis', $itemId, $rel, $orig, $stored, $mime, $size, $caption, $isPrimary, $sort, $uploadedBy);
        $stmt->execute();
        $mediaId = (int)$db->insert_id;
        $stmt->close();

        eh_audit($db, 'enterprise_hub.media_uploaded', ['item_id' => $itemId, 'media_id' => $mediaId]);
        return ['ok' => true, 'message' => 'Image uploaded.', 'media_id' => $mediaId];
    }
}

if (!function_exists('eh_create_thumbnail')) {
    function eh_create_thumbnail(string $source, string $dest, int $maxWidth = 400): bool
    {
        $info = @getimagesize($source);
        if (!$info) {
            return false;
        }
        [$w, $h] = $info;
        $mime = $info['mime'] ?? '';
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if (!$src) {
            return false;
        }
        $ratio = $w > 0 ? min(1, $maxWidth / $w) : 1;
        $nw = max(1, (int)round($w * $ratio));
        $nh = max(1, (int)round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($dst, $dest, 82),
            'image/png' => imagepng($dst, $dest, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dst, $dest, 82) : imagejpeg($dst, $dest, 82),
            default => false,
        };
        imagedestroy($src);
        imagedestroy($dst);
        return (bool)$ok;
    }
}

if (!function_exists('eh_set_primary_media')) {
    function eh_set_primary_media(mysqli $db, int $itemId, int $mediaId): void
    {
        $db->begin_transaction();
        try {
            $a = $db->prepare('UPDATE enterprise_item_media SET is_primary = 0 WHERE enterprise_item_id = ?');
            $a->bind_param('i', $itemId);
            $a->execute();
            $a->close();
            $b = $db->prepare('UPDATE enterprise_item_media SET is_primary = 1 WHERE id = ? AND enterprise_item_id = ?');
            $b->bind_param('ii', $mediaId, $itemId);
            $b->execute();
            $b->close();
            $db->commit();
            eh_audit($db, 'enterprise_hub.media_primary_set', ['item_id' => $itemId, 'media_id' => $mediaId]);
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}

if (!function_exists('eh_delete_media')) {
    function eh_delete_media(mysqli $db, int $mediaId, int $itemId): void
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_item_media WHERE id = ? AND enterprise_item_id = ? LIMIT 1');
        $stmt->bind_param('ii', $mediaId, $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('Media not found.');
        }
        $del = $db->prepare('DELETE FROM enterprise_item_media WHERE id = ?');
        $del->bind_param('i', $mediaId);
        $del->execute();
        $del->close();

        $path = eh_media_root() . '/' . ltrim((string)$row['file_path'], '/');
        $thumb = eh_media_root() . '/thumbnails/' . basename((string)$row['stored_filename']);
        if (is_file($path)) {
            @unlink($path);
        }
        if (is_file($thumb)) {
            @unlink($thumb);
        }

        if ((int)$row['is_primary'] === 1) {
            $next = $db->prepare('SELECT id FROM enterprise_item_media WHERE enterprise_item_id = ? ORDER BY sort_order, id LIMIT 1');
            $next->bind_param('i', $itemId);
            $next->execute();
            $n = $next->get_result()->fetch_assoc();
            $next->close();
            if ($n) {
                eh_set_primary_media($db, $itemId, (int)$n['id']);
            }
        }
        eh_audit($db, 'enterprise_hub.media_deleted', ['item_id' => $itemId, 'media_id' => $mediaId]);
    }
}

if (!function_exists('eh_media_absolute_path')) {
    function eh_media_absolute_path(array $media, bool $thumb = false): string
    {
        $root = eh_media_root();
        if ($thumb) {
            $p = $root . '/thumbnails/' . basename((string)$media['stored_filename']);
            if (is_file($p)) {
                return $p;
            }
        }
        return $root . '/' . ltrim((string)$media['file_path'], '/');
    }
}

if (!function_exists('eh_can_view_media')) {
    function eh_can_view_media(mysqli $db, array $item): bool
    {
        if (($item['status'] ?? '') === 'published') {
            return true;
        }
        if (eh_is_systems_admin() || eh_can($db, 'enterprise.review.lecturer') || eh_can($db, 'enterprise.approve')) {
            return true;
        }
        try {
            eh_assert_owns_item($db, $item);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
