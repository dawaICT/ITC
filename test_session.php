<?php
require_once 'students/includes/AcademicSessionService.php';
require_once 'students/includes/DatabaseConnection.php';
$db = DatabaseConnection::getInstance()->getMysqli();
$service = new AcademicSessionService($db);
$session = $service->getCurrentSession();
echo 'Current Session: ' . json_encode($session) . PHP_EOL;
?>