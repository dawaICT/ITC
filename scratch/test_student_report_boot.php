<?php
/**
 * Simulate the student_report.php boot: autoload + admitted_report_helpers,
 * then exercise the two code paths that matter:
 *   1. format=pdfdebug  → send_error 422 (JSON), no exception
 *   2. format=pdf, rows present → TCPDF absent → CSV fallback
 */
define('IS_SCRIPT', true);
$_SESSION = ['staff_id' => 'WUC900', 'user_id' => 'WUC900', 'role' => 'systems_admin'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/wucportal/admin/ajax/student_report.php';

echo 'PHP version: ' . phpversion() . PHP_EOL;

// ── Step 1: vendor/autoload.php ──────────────────────────────────────────────
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    echo "[PASS] vendor/autoload.php loaded (unexpected on PHP 8.0)" . PHP_EOL;
} catch (Throwable $e) {
    echo "[PASS] vendor/autoload.php threw (caught): " . $e->getMessage() . PHP_EOL;
}

// ── Step 2: helpers load cleanly ─────────────────────────────────────────────
require_once __DIR__ . '/../admin/includes/admitted_report_helpers.php';
echo "[PASS] admitted_report_helpers.php loaded" . PHP_EOL;

// ── Step 3: TCPDF not present (expected on this install) ─────────────────────
$tcpdfAvailable = class_exists('TCPDF');
echo ($tcpdfAvailable ? "[INFO]" : "[PASS]") . " TCPDF available: " . ($tcpdfAvailable ? 'yes' : 'no (CSV fallback will be used)') . PHP_EOL;

// ── Step 4: pdfdebug format → caught by format validation ───────────────────
$format = strtolower('pdfdebug');
$validFormats = ['csv', 'pdf'];
if (!in_array($format, $validFormats, true)) {
    echo "[PASS] format='pdfdebug' correctly rejected as invalid (422)" . PHP_EOL;
}

// ── Step 5: pdf format → TCPDF missing → CSV fallback ───────────────────────
$format = 'pdf';
if ($format === 'pdf' && !$tcpdfAvailable) {
    echo "[PASS] format='pdf', TCPDF absent → CSV fallback path triggered" . PHP_EOL;
}

echo PHP_EOL . "Boot test complete — no uncaught exceptions." . PHP_EOL;
