<?php
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';
$sid='EXH-ALU-001';
$minRule = wuc_payment_rule_percent($db, 'assessment.ca.minimum_payment_percent', 'min_ca_paid_percent', 50.0);
$minPeriod = wuc_period_required_payment_percent($db, $sid, $minRule);
echo "rule=$minRule period_required=$minPeriod\n";
$elig = is_student_allowed_ca($db, $sid, '2026', '1');
echo json_encode($elig) . "\n";
$elig2 = wuc_student_payment_eligibility($db, $sid, '2026', '1', 50.0, true);
echo "at50=" . json_encode($elig2) . "\n";
$elig3 = wuc_student_payment_eligibility($db, $sid, '2026', '1', 100.0, true);
echo "at100=" . json_encode($elig3) . "\n";
