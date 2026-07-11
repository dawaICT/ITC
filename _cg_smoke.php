<?php
error_reporting(E_ALL); ini_set('display_errors','1');
require __DIR__.'/db/connect.php';
require __DIR__.'/includes/city_guilds_helpers.php';
cg_ensure_schema($db);
$fns = ['cg_dashboard_counts','cg_students_for_select','cg_staff_for_select','cg_units','cg_learners','cg_assignments','cg_pending_assessments','cg_recent_qa_records','cg_cohorts'];
foreach ($fns as $f) {
  try { $r = $f($db); echo "$f OK (".(is_array($r)?count($r):'scalar').")\n"; }
  catch (Throwable $e) { echo "$f FAIL: ".$e->getMessage()."\n"; }
}
