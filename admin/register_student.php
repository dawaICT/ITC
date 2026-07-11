<?php
/**
 * Deprecated entry point.
 *
 * Replaced by the single unified wizard in regNewStud.php, which shares the exact
 * frontend and backend with admissions/regNewStud.php
 * (admissions/includes/registration_*). Kept only as a guarded redirect so
 * existing links and bookmarks keep working.
 */
require_once __DIR__ . '/includes/admin.php';
header('Location: regNewStud.php');
exit;
