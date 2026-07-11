<?php
require_once __DIR__ . '/security.php';
/**
 * Shared helpers for e-learning uploaded files and links.
 */

function elearningAllowedExtensionsForType(string $contentType): array
{
    $map = [
        'video' => ['mp4', 'webm', 'mov', 'm4v'],
        'pdf' => ['pdf'],
        'docx' => ['doc', 'docx'],
        'scorm' => ['zip'],
    ];

    return $map[$contentType] ?? [];
}

function elearningValidateExternalUrl(string $url): bool
{
    return wuc_validate_external_http_url($url);
}

function elearningValidateUploadedFile(array $file, string $contentType, int $maxBytes = 52428800): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'File upload failed. Please choose the file again.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'The selected file is empty.'];
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'error' => 'The selected file is larger than the 50 MB portal limit.'];
    }

    $originalName = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = elearningAllowedExtensionsForType($contentType);
    if (empty($allowed) || !in_array($ext, $allowed, true)) {
        return ['ok' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowed)];
    }

    return ['ok' => true, 'extension' => $ext, 'size' => $size];
}

function elearningSafeUploadName(string $prefix, string $extension): string
{
    return preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix)
        . '_' . time()
        . '_' . bin2hex(random_bytes(4))
        . '.' . strtolower($extension);
}

function elearningFormatFileSize($bytes): string
{
    $size = (int) $bytes;
    if ($size <= 0) {
        return 'n/a';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }
    return number_format($size, $index === 0 ? 0 : 1) . ' ' . $units[$index];
}
