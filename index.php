<?php
require_once __DIR__ . '/config/auth_check.php';

checkStaffAuth();

header('Location: ' . STAFF_DASHBOARD);
exit;

