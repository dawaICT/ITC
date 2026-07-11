<?php
/**
 * Shared upload rules for ALL new-student registration forms.
 * ----------------------------------------------------------------
 * One source of truth so that every flow that registers a student —
 *   - admissions/regNewStud.php (single + bulk wizard)
 *   - admin/regNewStud.php
 *   - admissions/regOldStud.php (transfer students)
 * accepts the EXACT same file formats, enforces the same 5MB cap, and
 * performs the same real-content (MIME) check.
 *
 * Why this exists: previously each form had its own divergent list of
 * allowed extensions (some allowed webp, some didn't), only one enforced a
 * size limit, and NONE verified the actual file content — so a PHP script
 * renamed to `.jpg` could be stored in a web-served uploads folder.
 *
 * Categories:
 *   'image'    -> profile photos        (jpg, jpeg, png, webp)
 *   'document' -> results/nrc/cert/id   (pdf, jpg, jpeg, png, webp)
 */

if (!defined('WUC_UPLOAD_MAX_BYTES')) {
    define('WUC_UPLOAD_MAX_BYTES', 5 * 1024 * 1024); // 5 MB
}

if (!function_exists('wucUploadExtensions')) {
    function wucUploadExtensions(string $kind): array
    {
        return $kind === 'image'
            ? ['jpg', 'jpeg', 'png', 'webp']
            : ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    }
}

if (!function_exists('wucUploadMimeMap')) {
    /** Extension => list of acceptable real MIME types (as reported by finfo). */
    function wucUploadMimeMap(): array
    {
        return [
            'pdf'  => ['application/pdf'],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'webp' => ['image/webp'],
        ];
    }
}

if (!function_exists('wucUploadAcceptAttr')) {
    /** Value for an HTML <input type="file" accept="..."> attribute. */
    function wucUploadAcceptAttr(string $kind): string
    {
        return '.' . implode(',.', wucUploadExtensions($kind));
    }
}

if (!function_exists('wucUploadMimeListJson')) {
    /** JSON array of acceptable MIME types for a category, for client-side JS. */
    function wucUploadMimeListJson(string $kind): string
    {
        $mimes = [];
        foreach (wucUploadExtensions($kind) as $ext) {
            foreach (wucUploadMimeMap()[$ext] ?? [] as $m) {
                $mimes[$m] = true;
            }
        }
        return json_encode(array_keys($mimes), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}

if (!function_exists('wucValidateUpload')) {
    /**
     * Validate one $_FILES entry against the shared rules.
     *
     * @param array  $file A single $_FILES['x'] entry (name, type, tmp_name, error, size).
     * @param string $kind 'image' | 'document'
     * @return string The normalized lowercase extension (e.g. "pdf") on success.
     * @throws RuntimeException with a user-readable message on any failure.
     */
    function wucValidateUpload(array $file, string $kind): string
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No file was uploaded.');
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('File is too large (server limit).');
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (error code ' . (int) $err . ').');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded file is empty.');
        }
        if ($size > WUC_UPLOAD_MAX_BYTES) {
            throw new RuntimeException('File exceeds the 5MB limit.');
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = wucUploadExtensions($kind);
        if (!in_array($ext, $allowed, true)) {
            throw new RuntimeException('Invalid file type ".' . $ext . '". Allowed: ' . implode(', ', $allowed) . '.');
        }

        // Real content-type check — defends against e.g. evil.php renamed evil.jpg.
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp !== '' && is_readable($tmp) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                $expected = wucUploadMimeMap()[$ext] ?? [];
                if ($detected && $expected && !in_array($detected, $expected, true)) {
                    throw new RuntimeException('File content (' . $detected . ') does not match its ".' . $ext . '" extension.');
                }
            }
        }

        return $ext;
    }
}

if (!function_exists('wucSafeUploadName')) {
    /** Build a collision-resistant stored filename. Never trusts the client name. */
    function wucSafeUploadName(string $prefix, string $ext): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix);
        if ($prefix === '' || $prefix === null) {
            $prefix = 'file';
        }
        return $prefix . '_' . bin2hex(random_bytes(6)) . '_' . time() . '.' . strtolower($ext);
    }
}
