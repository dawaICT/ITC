<?php
/**
 * Seed the ITC 2026 training catalogue (§15 of
 * ITC_Courses_Duration_Intake_Level_Logic.md) into `short_courses`.
 *
 * For every offering we store a structured duration, a course category, a
 * classification level and an intake type — resolved through the shared
 * algorithms in includes/itc_course_helpers.php so the catalogue stays
 * consistent with what the admin UI suggests.
 *
 * Idempotent: keyed on course_code (CAT-NNN). Re-running refreshes the
 * classification of existing rows and never duplicates.
 *
 * (Note: scripts/seed_itc_catalogue.php is an older, unrelated seeder that
 * targeted the legacy `courses` table shape and is now superseded by this file.)
 *
 * Run from the project root:
 *   C:\xampp\php\php.exe scripts\seed_itc_2026_catalogue.php
 */

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/itc_course_helpers.php';
require_once __DIR__ . '/../includes/short_course_db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$CREATED_BY = 'WUC900';

/*
 * Catalogue data, grouped by category code. Each row:
 *   [name, duration_value, duration_unit, level_hint, intake_hint, is_fixed?]
 * level_hint/intake_hint use the canonical codes from §15's "Suggested" columns;
 * an empty hint (e.g. the ICT "INTERMEDIATE"/"PROFESSIONAL_SHORT" labels that are
 * not canonical) falls back to the §5/§8 algorithms. Ranges like "3 to 5 days"
 * are stored at the representative upper bound. Services with no fixed length use
 * is_fixed = 0.
 */
$catalogue = [
    'TRANS' => [
        ['Class A Motor Bike Riding', 10, 'days', 'SHORT', 'WEEKLY'],
        ['Forklift Truck Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Class C Heavy Rigid Truck and Automatic Transmission', 20, 'days', 'SHORT', 'MONTHLY'],
        ['Class C Heavy Duty Rigid Truck - Manual Transmission', 20, 'days', 'SHORT', 'MONTHLY'],
        ['Class CE Articulated Heavy Duty Truck', 15, 'days', 'ADVANCED', 'MONTHLY'],
        ['PSV Courses for Heavy Duty Vehicles', 10, 'days', 'ADVANCED', 'WEEKLY'],
        ['Dangerous Goods / HAZCHEM for Drivers and Fuel Attendants', 5, 'days', 'SHORT', 'ROLLING'],
        ['Defensive Driving', 3, 'days', 'REFRESHER', 'ROLLING'],
        ['Defensive Bike Riding', 5, 'days', 'REFRESHER', 'ROLLING'],
        ['Refresher Course', 10, 'days', 'REFRESHER', 'WEEKLY'],
        ['Fleet Management', 5, 'days', 'SHORT', 'ROLLING'],
        ['Supervisory Skills', 5, 'days', 'SHORT', 'ROLLING'],
        ['Driver Competence Assessment / Testing', 1, 'days', 'ASSESSMENT', 'ON_DEMAND'],
        ['Diploma in Logistics and Transport Management', 24, 'months', 'DIPLOMA', 'ANNUAL'],
    ],
    'AUTO' => [
        ['Motor Vehicle Mechanics', 9, 'months', 'CERTIFICATE', 'SEMESTER_BASED'],
        ['Certificate in Vehicle Maintenance and Repair', 12, 'months', 'CERTIFICATE', 'SEMESTER_BASED'],
        ['Craft Certificate in Automotive Mechanics', 2, 'years', 'CRAFT_CERT', 'ANNUAL'],
        ['Craft Certificate in Auto Electrical', 2, 'years', 'CRAFT_CERT', 'ANNUAL'],
        ['Diploma in Vehicle Maintenance and Repair', 12, 'months', 'DIPLOMA', 'SEMESTER_BASED'],
        ['Level 1 Trade Test Certificate in Automotive Mechanics', 12, 'months', 'TRADE_I', 'SEMESTER_BASED'],
        ['Level 1 TEVETA Trade Test Certificate in Auto Electrical', 12, 'months', 'TRADE_I', 'SEMESTER_BASED'],
        ['Level 2 Trade Test Certificate in Automotive Mechanics', 6, 'months', 'TRADE_II', 'TERM_BASED'],
        ['Level 2 TEVETA Trade Test Certificate in Auto Electrical', 6, 'months', 'TRADE_II', 'TERM_BASED'],
        ['Level 3 Trade Test Certificate in Automotive Mechanics', 3, 'months', 'TRADE_III', 'TERM_BASED'],
        ['Level 3 TEVETA Trade Test Certificate in Auto Electrical', 3, 'months', 'TRADE_III', 'TERM_BASED'],
        ['Vehicle Electronics and Diagnostic Techniques', 10, 'days', 'ADVANCED', 'WEEKLY'],
        ['Diesel Electronic Fuel Injection Systems and Diagnosis', 5, 'days', 'ADVANCED', 'ROLLING'],
        ['Petrol Electronic Fuel Injection Systems and Diagnosis', 5, 'days', 'ADVANCED', 'ROLLING'],
        ['Auto Electrical Intensive', 10, 'days', 'ADVANCED', 'WEEKLY'],
        ['Motor Vehicle Service and Maintenance', 5, 'days', 'BASIC', 'ROLLING'],
        ['Basic Mechanics for Drivers', 5, 'days', 'BASIC', 'ROLLING'],
        ['Engine Overhauling', 5, 'days', 'ADVANCED', 'ROLLING'],
    ],
    'ELEC' => [
        ['Technician Certificate in Electrical Engineering', 12, 'months', 'TECH_CERT', 'SEMESTER_BASED'],
        ['Technician Diploma in Electrical Engineering', 12, 'months', 'DIPLOMA', 'SEMESTER_BASED'],
        ['Trade Test Level III in Electrical Technology', 3, 'months', 'TRADE_III', 'TERM_BASED'],
        ['Trade Test Level II in Electrical Technology', 6, 'months', 'TRADE_II', 'TERM_BASED'],
        ['Trade Test Level I in Electrical Technology', 12, 'months', 'TRADE_I', 'SEMESTER_BASED'],
        ['Craft Certificate in Electrical Technology', 2, 'years', 'CRAFT_CERT', 'ANNUAL'],
        ['Domestic Installation and Wiring', 5, 'days', 'BASIC', 'ROLLING'],
        ['Generator Set Repair and Maintenance', 5, 'days', 'ADVANCED', 'ROLLING'],
        ['Troubleshooting on Motors and Associated Circuits', 5, 'days', 'ADVANCED', 'ROLLING'],
        ['Programmable Logic Controllers', 5, 'days', 'ADVANCED', 'ROLLING'],
        ['Motor and Generator Maintenance and Repair', 5, 'days', 'ADVANCED', 'ROLLING'],
    ],
    'MECH' => [
        ['TEVETA Trade Test Level III in Metal Fabrication', 3, 'months', 'TRADE_III', 'TERM_BASED'],
        ['TEVETA Trade Test Level III in Plumbing and Sheet Metal', 3, 'months', 'TRADE_III', 'TERM_BASED'],
        ['TEVETA Trade Test Level III in Machining and Plant Fitting', 3, 'months', 'TRADE_III', 'TERM_BASED'],
        ['MIG Welding', 5, 'days', 'SHORT', 'ROLLING'],
        ['TIG Welding', 5, 'days', 'SHORT', 'ROLLING'],
        ['Manual Metal Arc Welding', 5, 'days', 'SHORT', 'ROLLING'],
        ['Basic Metal Fabrication', 10, 'days', 'BASIC', 'WEEKLY'],
        ['Domestic Plumbing', 10, 'days', 'BASIC', 'WEEKLY'],
        ['Basic Machining', 10, 'days', 'BASIC', 'WEEKLY'],
        ['Basic Mechanical Fitting', 10, 'days', 'BASIC', 'WEEKLY'],
    ],
    'ICT' => [
        ['Craft Certificate in Computer Systems Engineering', 12, 'months', 'CRAFT_CERT', 'SEMESTER_BASED'],
        ['Diploma in Computer Systems Engineering', 12, 'months', 'DIPLOMA', 'SEMESTER_BASED'],
        ['Craft Certificate in Telecommunication', 2, 'years', 'CRAFT_CERT', 'ANNUAL'],
        ['Fibre Optics Maintenance and Installation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Advanced Fibre Optics Network Design and Installation', 4, 'days', 'ADVANCED', 'ROLLING'],
        ['Microsoft Application Packages', 20, 'days', 'SHORT', 'MONTHLY'],
        ['Computer Repair and Hardware Maintenance', 20, 'days', 'SHORT', 'MONTHLY'],
        ['Local Area Networking and Administration', 10, 'days', 'SHORT', 'WEEKLY'],
        ['Windows Server Administration', 10, 'days', '', 'WEEKLY'],
        ['Android Programming', 10, 'days', '', 'WEEKLY'],
        ['Advanced Databases', 15, 'days', 'ADVANCED', 'MONTHLY'],
        ['Intermediate Excel', 5, 'days', '', 'ROLLING'],
        ['Advanced Excel', 5, 'days', 'ADVANCED', 'ROLLING'],
        ['Web Technology', 20, 'days', 'SHORT', 'MONTHLY'],
        ['Programming: VB, Java, HTML, CSS, SQL', 20, 'days', '', 'MONTHLY'],
        ['CISCO CCNA 1', 10, 'days', '', 'WEEKLY'],
        ['CISCO CCNA 2', 5, 'days', '', 'ROLLING'],
        ['Pastel Accounting Package', 15, 'days', 'SHORT', 'MONTHLY'],
    ],
    'AGRI' => [
        ['Tractor Maintenance', 10, 'days', 'BASIC', 'WEEKLY'],
        ['Tractor Driving', 10, 'days', 'BASIC', 'WEEKLY'],
        ['Farm Irrigation Pump Installation and Maintenance', 5, 'days', 'BASIC', 'ROLLING'],
        ['Hammer Mill Repair', 5, 'days', 'BASIC', 'ROLLING'],
        ['Workshop Management', 5, 'days', 'SHORT', 'ROLLING'],
        ['Farm Supervisory Skills', 5, 'days', 'SHORT', 'ROLLING'],
    ],
    'SAFE' => [
        ['Working at Height / Scaffolding', 5, 'days', 'SHORT', 'ROLLING'],
        ['Occupational Health and Safety', 5, 'days', 'SHORT', 'ROLLING'],
        ['Hazardous Chemicals / HAZCHEM', 5, 'days', 'SHORT', 'ROLLING'],
        ['First Aid', 5, 'days', 'SHORT', 'ROLLING'],
        ['Fire Safety', 5, 'days', 'SHORT', 'ROLLING'],
        ['Fork Lift Truck Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Elevated Work Platform Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Pedestrian Electric Forklift Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Mobile Crane Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Overhead Crane Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Gantry Crane Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Excavator Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Front End Loader', 5, 'days', 'SHORT', 'ROLLING'],
    ],
    'MINE' => [
        ['Boiler Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Boiler Maintenance', 5, 'days', 'SHORT', 'ROLLING'],
        ['Fork Lift Truck Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Elevated Work Platform Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Pedestrian Electric Forklift Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Crane Rigging On-site', 5, 'days', 'SHORT', 'ROLLING'],
        ['Mobile Crane Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Overhead Crane Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Gantry Crane Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Excavator Operation', 5, 'days', 'SHORT', 'ROLLING'],
        ['Front End Loader', 5, 'days', 'SHORT', 'ROLLING'],
        ['Programmable Logical Controls', 10, 'days', 'ADVANCED', 'WEEKLY'],
    ],
    'SERV' => [
        ['Driver Competence Assessment', 1, 'days', 'ASSESSMENT', 'ON_DEMAND', 0],
        ['Driver Recruitment', 1, 'days', 'SERVICE', 'ON_DEMAND', 0],
        ['Vehicle Routine Service', 1, 'days', 'SERVICE', 'ON_DEMAND', 0],
        ['Transportation of Goods and Passengers', 1, 'days', 'SERVICE', 'ON_DEMAND', 0],
        ['Motor Vehicle Maintenance and Repair', 1, 'days', 'SERVICE', 'ON_DEMAND', 0],
        ['Vehicle Diagnosis and Repair', 1, 'days', 'SERVICE', 'ON_DEMAND', 0],
        ['Plumbing, Water and Sanitation Repairs / Maintenance', 1, 'days', 'SERVICE', 'ON_DEMAND', 0],
        ['Hiring Out Forklift Trucks', 1, 'days', 'SERVICE', 'ON_DEMAND', 0],
    ],
];

/** Normalised key for cross-category de-duplication (forklift/cranes appear twice). */
function itc_seed_norm(string $name): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($name));
}

/** Resolve a hint to a canonical level code, falling back to the §5 algorithm. */
function itc_resolve_level(mysqli $db, string $hint, string $name, ?int $days, string $catCode): string
{
    $hint = strtoupper(trim($hint));
    if ($hint !== '' && itc_level_id_by_code($db, $hint) !== null) {
        return $hint;
    }
    return itc_classify_level($name, $days, $catCode);
}

/** Resolve a hint to a canonical intake type, falling back to the §8 algorithm. */
function itc_resolve_intake(mysqli $db, string $hint, string $level, int $val, string $unit, ?int $days): string
{
    $hint = strtoupper(trim($hint));
    if ($hint !== '' && itc_intake_type_id_by_code($db, $hint) !== null) {
        return $hint;
    }
    return itc_suggest_intake_type($level, $val, $unit, $days);
}

$stmt = $db->prepare(
    "INSERT INTO short_courses
        (course_code, course_name, description, category_id, level_id, intake_type_id,
         duration_value, duration_unit, standard_duration_days, is_duration_fixed,
         fee, max_capacity, delivery_mode, status, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 30, 'full-time', 'active', ?)
     ON DUPLICATE KEY UPDATE
        course_name = VALUES(course_name),
        category_id = VALUES(category_id),
        level_id = VALUES(level_id),
        intake_type_id = VALUES(intake_type_id),
        duration_value = VALUES(duration_value),
        duration_unit = VALUES(duration_unit),
        standard_duration_days = VALUES(standard_duration_days),
        is_duration_fixed = VALUES(is_duration_fixed)"
);

$programStmt = $db->prepare(
    "INSERT INTO programs
        (program_code, program_name, program_type, program_duration, program_description,
         is_active, study_mode, period_mode)
     VALUES (?, ?, ?, ?, ?, 1, 'Full Time', 'term')
     ON DUPLICATE KEY UPDATE
        program_name = VALUES(program_name),
        program_type = VALUES(program_type),
        program_duration = VALUES(program_duration),
        program_description = VALUES(program_description),
        is_active = VALUES(is_active),
        study_mode = VALUES(study_mode),
        period_mode = VALUES(period_mode)"
);

function itc_seed_duration_years(int $value, string $unit, ?int $days): float
{
    switch (strtolower(trim($unit))) {
        case 'year':
        case 'years':
            return max(0.1, round($value, 1));
        case 'month':
        case 'months':
            return max(0.1, round($value / 12, 1));
        case 'week':
        case 'weeks':
        case 'day':
        case 'days':
            return max(0.1, round((float)($days ?? 1) / 365, 1));
        default:
            return max(0.1, round((float)($days ?? 365) / 365, 1));
    }
}

$seen = [];
$totals = [];
$inserted = 0;
$programUpserts = 0;
$skippedDupes = 0;

try {
    $db->begin_transaction();

    foreach ($catalogue as $catCode => $rows) {
        $catId = itc_category_id_by_code($db, $catCode);
        if ($catId === null) {
            throw new RuntimeException("Unknown category code: {$catCode}");
        }
        $seq = 0;
        foreach ($rows as $row) {
            [$name, $val, $unit, $levelHint, $intakeHint] = $row;
            $isFixed = isset($row[5]) ? (int)$row[5] : 1;

            $key = itc_seed_norm($name);
            if (isset($seen[$key])) {
                $skippedDupes++;
                continue; // cross-listed elsewhere already
            }
            $seen[$key] = true;

            $days       = itc_duration_to_days((int)$val, $unit);
            $levelCode  = itc_resolve_level($db, $levelHint, $name, $days, $catCode);
            $levelId    = itc_level_id_by_code($db, $levelCode);
            $intakeCode = itc_resolve_intake($db, $intakeHint, $levelCode, (int)$val, $unit, $days);
            $intakeId   = itc_intake_type_id_by_code($db, $intakeCode);
            $stdDays    = $isFixed ? $days : null; // services have no fixed length

            $seq++;
            $code = sprintf('%s-%03d', $catCode, $seq);
            $desc = null; // keep description free for admin notes

            if (!sc_is_short_course_days((int)($days ?? 0))) {
                $programType = stripos($name, 'diploma') !== false ? 'Diploma' : 'Certificate';
                $programDuration = itc_seed_duration_years((int)$val, $unit, $days);
                $programDesc = sprintf(
                    'Seeded from the ITC 2026 catalogue as a programme because the duration exceeds the six-month short-course limit. Catalogue duration: %d %s.',
                    (int)$val,
                    $unit
                );
                $programStmt->bind_param(
                    'sssds',
                    $code,
                    $name,
                    $programType,
                    $programDuration,
                    $programDesc
                );
                $programStmt->execute();
                $programUpserts++;
                $totals[$catCode . ' programmes'] = ($totals[$catCode . ' programmes'] ?? 0) + 1;
                continue;
            }

            $stmt->bind_param(
                'sssiiiisiis',
                $code, $name, $desc, $catId, $levelId, $intakeId,
                $val, $unit, $stdDays, $isFixed, $CREATED_BY
            );
            $stmt->execute();
            $inserted++;
            $totals[$catCode] = ($totals[$catCode] ?? 0) + 1;
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "FAILED (rolled back): " . $e->getMessage() . "\n");
    exit(1);
}

echo "ITC 2026 catalogue seeded into short_courses.\n";
foreach ($totals as $cat => $n) {
    printf("  %-6s %2d courses\n", $cat, $n);
}
printf("  TOTAL  %d short courses upserted, %d programmes upserted, %d cross-listed duplicates skipped\n", $inserted, $programUpserts, $skippedDupes);
