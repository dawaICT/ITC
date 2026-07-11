<?php
declare(strict_types=1);
/**
 * student_guard.php — restrict a page to authenticated students.
 *
 * Delegates to the existing student guard, which enforces the student session
 * ($_SESSION['Sid']), idle timeout, CSRF token seeding, and the first-login
 * password-change redirect. Kept here only so the guards/ directory exposes a
 * consistent entry point for every audience.
 *
 *   require_once __DIR__ . '/../includes/guards/student_guard.php';
 */

require_once __DIR__ . '/../../students/includes/guard.php';
