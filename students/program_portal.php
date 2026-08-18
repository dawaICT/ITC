<?php
$reqType = trim((string)($_GET['type'] ?? ''));
if ($reqType !== '') {
    $expectedStudentProgramPortal = $reqType;
}
require __DIR__ . '/index.php';
