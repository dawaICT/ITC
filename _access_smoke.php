<?php
define('IS_SCRIPT', true);
error_reporting(E_ALL); ini_set('display_errors','1');
session_start();
$_SESSION['staff_id']='WUC900';
$_SESSION['user_id']='WUC900';
require __DIR__.'/db/connect.php';
require __DIR__.'/includes/role_helpers.php';
if (function_exists('hydrateStaffRolesFromDatabase')) hydrateStaffRolesFromDatabase('WUC900');
echo "role=".($_SESSION['role']??'(none)')."\n";
echo "all_roles=".json_encode($_SESSION['all_roles']??null)."\n";
echo "canAccessTransport=".(function_exists('canAccessTransport')?var_export(canAccessTransport(),true):'n/a')."\n";
echo "canManageCityGuilds=".(function_exists('canManageCityGuilds')?var_export(canManageCityGuilds(),true):'n/a')."\n";
