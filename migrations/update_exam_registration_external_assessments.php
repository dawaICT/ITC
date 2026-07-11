<?php
/**
 * Migration: external assessment metadata for exam registration.
 *
 * Adds period/type metadata so exam registrations can distinguish end-of-term,
 * end-of-semester, and short-course test registrations.
 */
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../students/includes/period_mode_helper.php';
require_once __DIR__ . '/../students/includes/exam_helpers.php';

echo "Running exam registration external assessment migration...\n";

if (student_exam_ensure_schema($db)) {
    echo "exam_registration table created/updated.\n";
} else {
    echo "exam_registration migration failed: " . $db->error . "\n";
    exit(1);
}

echo "Migration complete.\n";
