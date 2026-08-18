<?php
declare(strict_types=1);

/**
 * Dev-only seed for Skills and Enterprise Portal.
 * Requires ENTERPRISE_SEED_ENABLED=true. Never uses real student PII.
 */

if (strtolower((string)(getenv('ENTERPRISE_SEED_ENABLED') ?: '')) !== 'true') {
    fwrite(STDERR, "Set ENTERPRISE_SEED_ENABLED=true to run this seed.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_portal/bootstrap.php';

echo "Seeding demo enterprise portal data (synthetic only)...\n";

$cats = [
    ['Demo Agri Products', 'demo-agri-products', 5],
    ['Demo Digital Services', 'demo-digital-services', 6],
    ['Demo Fabrication', 'demo-fabrication', 7],
];
$stmt = $db->prepare('INSERT IGNORE INTO enterprise_categories (category_name, category_slug, is_active, display_order) VALUES (?,?,1,?)');
foreach ($cats as $c) {
    $stmt->bind_param('ssi', $c[0], $c[1], $c[2]);
    $stmt->execute();
}
$stmt->close();

$chk = $db->query("SELECT COUNT(*) c FROM enterprise_memberships WHERE eligibility_notes = 'SEED_DEMO_ONLY'");
$existing = (int)($chk->fetch_assoc()['c'] ?? 0);
if ($existing > 0) {
    echo "Seed memberships already present ({$existing}). Skipping member/opportunity inserts.\n";
    exit(0);
}

$now = date('Y-m-d H:i:s');
$statuses = ['pending', 'active', 'changes_requested', 'suspended', 'withdrawn'];
$insMem = $db->prepare("INSERT INTO enterprise_memberships
    (user_id, student_id, membership_type, status, participation_goals_json, eligibility_result, eligibility_notes, submitted_at, last_status_change_at)
    VALUES (0, ?, 'student', ?, ?, 'eligible_student', 'SEED_DEMO_ONLY', ?, ?)");

foreach ($statuses as $i => $status) {
    $sid = 'SEED' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);
    $goals = json_encode(['showcase_skills', 'sell_products'], JSON_UNESCAPED_UNICODE);
    $insMem->bind_param('sssss', $sid, $status, $goals, $now, $now);
    $insMem->execute();
    $mid = (int)$db->insert_id;

    $ptype = $status === 'active' ? 'enterprise' : 'professional';
    $pname = 'Demo Seed Profile ' . ($i + 1);
    $bio = 'Synthetic seed profile for Skills and Enterprise Portal testing.';
    $insProf = $db->prepare("INSERT INTO enterprise_member_profiles
        (membership_id, owner_user_id, profile_type, business_name, professional_title, short_bio, preferred_contact_method, province)
        VALUES (?, 0, ?, ?, 'Demo Participant', ?, 'portal_mediated', 'Lusaka')");
    $insProf->bind_param('isss', $mid, $ptype, $pname, $bio);
    $insProf->execute();
    $pid = (int)$db->insert_id;
    $insProf->close();

    if ($status !== 'active') {
        continue;
    }

    $skill = $db->prepare("INSERT INTO enterprise_skills (enterprise_profile_id, skill_name, skill_category, proficiency_level, evidence_description) VALUES (?, 'Seed Welding', 'Fabrication', 'advanced', 'Synthetic evidence note')");
    $skill->bind_param('i', $pid);
    $skill->execute();
    $skill->close();

    $types = [
        ['product', 'Seed Demo Soap', 'draft'],
        ['service', 'Seed Demo Tailoring', 'submitted'],
        ['innovation', 'Seed Water Filter Concept', 'reviewer_verified'],
        ['business_idea', 'Seed Market Stall Idea', 'approved'],
        ['employment_profile', 'Seed Employment Profile', 'published'],
    ];
    foreach ($types as $t) {
        $code = ep_public_code();
        $slug = ep_slugify($t[1]) . '-' . substr(md5($code), 0, 6);
        $short = 'Synthetic seed listing — not a real opportunity.';
        $oppType = $t[0];
        $title = $t[1];
        $statusOpp = $t[2];
        $publishedAt = $statusOpp === 'published' ? $now : null;
        $insOpp = $db->prepare("INSERT INTO enterprise_opportunities
            (enterprise_profile_id, opportunity_type, title, slug, short_description, availability_status,
             public_code, status, preferred_employment_type, preferred_location, pricing_type, unit_price,
             current_capacity, capacity_period, problem_statement, proposed_solution, innovation_stage, published_at)
            VALUES (?,?,?,?,?,'available',?,?, 'Full-time','Lusaka','fixed_price',25.00,10,'month','Seed problem','Seed solution','concept',?)");
        $insOpp->bind_param('isssssss', $pid, $oppType, $title, $slug, $short, $code, $statusOpp, $publishedAt);
        if (!$insOpp->execute()) {
            echo 'Opp insert failed: ' . $insOpp->error . PHP_EOL;
        }
        $oid = (int)$db->insert_id;
        $insOpp->close();

        if ($statusOpp === 'published' && $oid > 0) {
            ep_save_readiness($db, $oid, [
                'product_score' => 70, 'market_score' => 65, 'costing_score' => 60,
                'capacity_score' => 55, 'compliance_score' => 50, 'team_score' => 60,
            ]);
            ep_save_opportunity_costs($db, $oid, [
                'material_cost' => 50, 'labour_cost' => 30, 'transport_cost' => 10, 'utilities_cost' => 5,
                'packaging_cost' => 5, 'marketing_cost' => 0, 'other_cost' => 0, 'number_of_units' => 10, 'selling_price' => 15,
            ]);
            $email = 'seed.buyer' . $oid . '@example.invalid';
            $msg = 'Synthetic seed interest — ignore in production.';
            $lead = $db->prepare("INSERT INTO enterprise_opportunity_interests
                (enterprise_opportunity_id, visitor_name, email, phone, interest_type, message, consent_accepted, lead_status, source)
                VALUES (?, 'Seed Buyer', ?, '260000000000', 'product_purchase', ?, 1, 'converted', 'seed')");
            $lead->bind_param('iss', $oid, $email, $msg);
            $lead->execute();
            $iid = (int)$db->insert_id;
            $lead->close();

            $out = $db->prepare("INSERT INTO enterprise_outcomes
                (enterprise_interest_id, enterprise_opportunity_id, enterprise_profile_id, outcome_type, outcome_stage,
                 estimated_value, currency, description, verification_status, recorded_by, recorded_at)
                VALUES (?,?,?,'product_order','recorded',150.00,'ZMW','Synthetic seed outcome','unverified','seed',$now)");
            // Fix: recorded_at should be bound
            $out->close();
            $actor = 'seed';
            $desc = 'Synthetic seed outcome';
            $out = $db->prepare("INSERT INTO enterprise_outcomes
                (enterprise_interest_id, enterprise_opportunity_id, enterprise_profile_id, outcome_type, outcome_stage,
                 estimated_value, currency, description, verification_status, recorded_by, recorded_at)
                VALUES (?,?,?,'product_order','recorded',150.00,'ZMW',?,'unverified',?,?)");
            $out->bind_param('iiisss', $iid, $oid, $pid, $desc, $actor, $now);
            $out->execute();
            $out->close();
        }
    }
}
$insMem->close();

echo "Seed complete. Remove rows with eligibility_notes='SEED_DEMO_ONLY' before production use.\n";
