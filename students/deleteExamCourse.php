<?php
require_once __DIR__ . '/includes/guard.php';

$_SESSION['student_notice'] = 'Students can view Continuous Assessment records only.';
header('Location: continuousAssessment.php');
exit;
