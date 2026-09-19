<?php
/**
 * Add Z-Library as a "where to get books" source for the portal library and
 * reading-resource surfaces.
 *
 * Inserts a single idempotent `library_digital_resources` row whose URL is
 * https://z-library.biz/ so it appears as a resource card in the student
 * Digital Library grid (`students/digital_library.php`) and in the admin
 * Digital Resources manager (`admin/library_digital.php`). `access_level =
 * 'open'` keeps it visible to every student (it passes the
 * digital_student_visibility_clause allow-list).
 *
 * Idempotent and non-destructive: re-running finds the existing row and does
 * nothing (the ORIGINAL seed row is left untouched).
 *
 * Run:  php migrations/20260904_add_zlibrary_book_source.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';

/** @var mysqli $db */

$check = $db->query("SHOW TABLES LIKE 'library_digital_resources'");
if (!$check || $check->num_rows === 0) {
    fwrite(STDERR, "library_digital_resources table missing — run the library schema first.\n");
    exit(1);
}

// Defensive column introspection: the live table may carry extra columns
// (visibility / course_code) beyond db/library_schema.sql, and a column we
// think exists may not. Insert only what is actually present.
$columns = [];
if ($res = @$db->query('SHOW COLUMNS FROM `library_digital_resources`')) {
    while ($row = $res->fetch_assoc()) {
        $columns[strtolower((string) $row['Field'])] = (string) $row['Field'];
    }
    $res->free();
}

$url = 'https://z-library.biz/';

$existing = $db->prepare('SELECT COUNT(*) AS c FROM library_digital_resources WHERE url = ?');
$count = 0;
if ($existing) {
    $existing->bind_param('s', $url);
    $existing->execute();
    $resCheck = $existing->get_result();
    if ($resCheck) {
        $row = $resCheck->fetch_assoc();
        $count = (int) ($row['c'] ?? 0);
        $resCheck->free();
    }
    $existing->close();
}

if ($count > 0) {
    echo "= already present: Z-Library digital resource exists ({$count} row). Left unchanged.\n";
    exit(0);
}

$before = 0;
if ($res = $db->query('SELECT COUNT(*) AS c FROM library_digital_resources')) {
    $row = $res->fetch_assoc();
    $before = (int) ($row['c'] ?? 0);
    $res->free();
}

// Core columns guaranteed by the base library schema.
$map = [
    'title'         => 'Z-Library — Free eBooks & Textbooks',
    'resource_type' => 'ebook',
    'url'           => $url,
    'access_level'  => 'open',
    'subject'       => 'General',
    'description'   => 'Get books — free digital library of eBooks, textbooks and academic reading material for study and research. Opens in a new tab.',
    'visibility'    => 'all',
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
];

$fields = [];
$placeholders = [];
$types = '';
$params = [];
foreach ($map as $logical => $value) {
    if (isset($columns[$logical])) {
        $fields[] = "`{$columns[$logical]}`";
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $value;
    }
}

if (empty($fields)) {
    fwrite(STDERR, "No compatible columns found on library_digital_resources.\n");
    exit(1);
}

$stmt = $db->prepare(
    'INSERT INTO library_digital_resources (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
);
if (!$stmt) {
    fwrite(STDERR, 'Prepare failed: ' . $db->error . "\n");
    exit(1);
}
$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
    fwrite(STDERR, 'Execute failed: ' . $db->error . "\n");
    exit(1);
}
$stmt->close();

$after = 0;
if ($res = $db->query('SELECT COUNT(*) AS c FROM library_digital_resources')) {
    $row = $res->fetch_assoc();
    $after = (int) ($row['c'] ?? 0);
    $res->free();
}

echo "  + inserted Z-Library digital resource (library_digital_resources: {$before} -> {$after})\n";
echo "Done.\n";
exit(0);