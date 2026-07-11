<?php
$sessionPath = dirname(__DIR__) . '/tmp/session-tests';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}
session_save_path($sessionPath);

$_SERVER['REQUEST_URI'] = '/wucportal/api/registration.php/check-status';
$_SERVER['SCRIPT_NAME'] = '/wucportal/api/registration.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname(__DIR__) . '/api/registration.php';
