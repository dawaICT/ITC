<?php
error_reporting(E_ALL); ini_set('display_errors','1');
session_start();
$_SESSION['staff_id']='WUC900';
require __DIR__.'/db/connect.php';
require __DIR__.'/includes/city_guilds_helpers.php';
cg_ensure_schema($db);

// pick a real student
$sid = ($db->query("SELECT SID FROM students LIMIT 1")->fetch_row())[0];
echo "Using SID=$sid\n";
try {
  $id = cg_enroll_learner($db, ['SID'=>$sid,'qualification_code'=>'CG-TEST','cohort'=>'2026-JUN','candidate_number'=>'SMOKE-'.time(),'notes'=>'smoke']);
  echo "cg_enroll_learner OK id=$id\n";
  $db->query("DELETE FROM city_guilds_learners WHERE id=".(int)$id);
  echo "cleanup ok\n";
} catch (Throwable $e) { echo "cg_enroll_learner FAIL: ".$e->getMessage()."\n"; }

// transport candidates (populates dropdown for transport trainee enrollment)
require __DIR__.'/transport/includes/transport.php';
