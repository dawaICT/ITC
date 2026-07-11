<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../students/includes/student_document_notifications.php';

$sid = $argv[1] ?? 'CSE26456789';
$pair = student_document_eligibility_pair($db, $sid);
echo "Student: {$sid}\n";
echo 'Docket: ' . json_encode($pair['docket'], JSON_PRETTY_PRINT) . "\n";
echo 'Exam slip: ' . json_encode($pair['exam_slip'], JSON_PRETTY_PRINT) . "\n";
$items = student_document_notification_items($db, $sid);
echo count($items) . " notification(s)\n";
