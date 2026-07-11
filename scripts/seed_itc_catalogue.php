<?php
/**
 * Seed the Industrial Training Centre (ITC) Lusaka course catalogue into `courses`.
 *
 * Source: https://www.courseoffered.com/2011/11/industrial-training-centre-in-lusaka.html
 *
 * Metadata (institution, category, duration, entry_requirements, fees, source_url)
 * is stored in the columns added by
 * migrations/add_catalogue_metadata_to_courses.php — run that first.
 *
 * The course_code prefix also encodes the ITC category group:
 *   ITC-ENG = Engineering & ICT Dept (full-time)
 *   ITC-TRL = Transport and Logistics / Driving School
 *   ITC-SIC = Short Intensive Courses
 *   ITC-ICT = ICT short courses
 *
 * Idempotent: re-running updates rows in place (keyed on the unique course_code).
 */

require __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const INSTITUTION = 'Industrial Training Centre (ITC)';
const SOURCE_URL  = 'https://www.courseoffered.com/2011/11/industrial-training-centre-in-lusaka.html';
const NOT_STATED  = 'Not stated';

$catEng = 'Engineering & ICT Department (Full-time)';
$catTrl = 'Transport and Logistics';
$catSic = 'Short Intensive Courses';
$catIct = 'Information and Communications Technology';

/** @var array<int,array{code:string,name:string,category:string,duration:string}> */
$courses = [
    // --- Engineering & ICT Department (full-time) ---
    ['code' => 'ITC-ENG-01', 'name' => 'Diploma in Motor Vehicle Engineering',                  'category' => $catEng, 'duration' => '3 years'],
    ['code' => 'ITC-ENG-02', 'name' => 'Diploma in Computer Systems Engineering',               'category' => $catEng, 'duration' => '2 years'],
    ['code' => 'ITC-ENG-03', 'name' => 'Technician Certificate in Motor Vehicle Engineering',    'category' => $catEng, 'duration' => '2 years'],
    ['code' => 'ITC-ENG-04', 'name' => 'Craft Certificate in Electronic and Telecommunications', 'category' => $catEng, 'duration' => '2 years'],
    ['code' => 'ITC-ENG-05', 'name' => 'Craft Certificate in Auto-Electrical & Electronics',     'category' => $catEng, 'duration' => '2 years'],
    ['code' => 'ITC-ENG-06', 'name' => 'Craft Certificate in Power Electrical',                  'category' => $catEng, 'duration' => '2 years'],

    // --- Transport and Logistics / Driving School ---
    ['code' => 'ITC-TRL-01', 'name' => 'Diploma in Transport and Logistics', 'category' => $catTrl, 'duration' => '3 years'],
    ['code' => 'ITC-TRL-02', 'name' => 'Professional Driving Class B, C, EC', 'category' => $catTrl, 'duration' => '20 days'],
    ['code' => 'ITC-TRL-03', 'name' => 'Defensive Driving',                   'category' => $catTrl, 'duration' => '20 days'],
    ['code' => 'ITC-TRL-04', 'name' => 'Driver Recruitment',                  'category' => $catTrl, 'duration' => '5 days'],
    ['code' => 'ITC-TRL-05', 'name' => 'Driver Suitability Assessment',       'category' => $catTrl, 'duration' => '5 days'],
    ['code' => 'ITC-TRL-06', 'name' => 'Driver Refresher',                    'category' => $catTrl, 'duration' => '5 days'],
    ['code' => 'ITC-TRL-07', 'name' => 'Motor Bike Riding',                   'category' => $catTrl, 'duration' => '5 days'],
    ['code' => 'ITC-TRL-08', 'name' => 'Chauffeur Driving',                   'category' => $catTrl, 'duration' => '5 days'],

    // --- Short Intensive Courses (names verbatim, incl. source spelling) ---
    ['code' => 'ITC-SIC-01', 'name' => 'AUTO ELECTRICAL AND ELECTRONICS DIAGNOSTICS & REPAIR', 'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-02', 'name' => 'INTENSIVE AUTO ELECTRICAL AND ELECTRONICS',            'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-03', 'name' => 'MOTOR VEHICLE SERVING FOR CAR OWNERS',                 'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-04', 'name' => 'BASIC DOMESTIC HOUSE WIRING',                          'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-05', 'name' => 'MOTER CYCLE MAINTENANCE COURSES',                      'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-06', 'name' => 'BASIC MECHANICS',                                      'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-07', 'name' => 'OCCUPATION HEALTH AND SEFETY',                         'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-08', 'name' => 'FIRST AID',                                            'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-09', 'name' => 'SCAFFOLDING',                                          'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-10', 'name' => 'FRONT END LOADER',                                     'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-11', 'name' => 'RAGGING',                                              'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-12', 'name' => 'EXCAVATOR OPERATION',                                  'category' => $catSic, 'duration' => '10 days'],
    ['code' => 'ITC-SIC-13', 'name' => 'PROGRAMMABLE & LOGIC CONTROLLERS',                     'category' => $catSic, 'duration' => '10 days'],

    // --- Information and Communications Technology (short courses) ---
    ['code' => 'ITC-ICT-01', 'name' => 'LOCAL AREA NETWORK AND ADMINISTRATION',                       'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-02', 'name' => 'FIBRE OPTICS MAINTENANCE AND INSTALLATION',                   'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-03', 'name' => 'WINDOWS SERVER ADMINISTRATION',                               'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-04', 'name' => 'PROGRAMMING(ANDROID,JAVA,C++,HTML/CSS, JAVASCRIPT,PYTHON,VB, SQL)', 'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-05', 'name' => 'WEB TECHNOLOGY',                                              'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-06', 'name' => 'ADVANCED DATABASES',                                          'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-07', 'name' => 'INTERMEDIATE EXCEL',                                          'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-08', 'name' => 'ADVANCED EXCEL',                                              'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-09', 'name' => 'IT (MICROSOFT OFFICE APPLICATIONS)',                          'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-10', 'name' => 'COMPUTER HARDWARE MAINTENACE AND REPAIR',                     'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-11', 'name' => 'CISCO CCNA 1',                                                'category' => $catIct, 'duration' => '10 days'],
    ['code' => 'ITC-ICT-12', 'name' => 'CISCO CCNA 2',                                                'category' => $catIct, 'duration' => '10 days'],
];

$sql = "INSERT INTO courses
            (course_code, course_name, institution, category, duration,
             entry_requirements, fees, source_url, credits, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'active')
        ON DUPLICATE KEY UPDATE
            course_name        = VALUES(course_name),
            institution        = VALUES(institution),
            category           = VALUES(category),
            duration           = VALUES(duration),
            entry_requirements = VALUES(entry_requirements),
            fees               = VALUES(fees),
            source_url         = VALUES(source_url),
            status             = 'active'";
$stmt = $db->prepare($sql);

$institution = INSTITUTION;
$entry = NOT_STATED;
$fees = NOT_STATED;
$source = SOURCE_URL;

$inserted = 0;
$updated = 0;
foreach ($courses as $c) {
    $stmt->bind_param(
        'ssssssss',
        $c['code'], $c['name'], $institution, $c['category'], $c['duration'],
        $entry, $fees, $source
    );
    $stmt->execute();
    // affected_rows: 1 = inserted, 2 = updated, 0 = unchanged
    if ($stmt->affected_rows === 1) {
        $inserted++;
    } elseif ($stmt->affected_rows >= 1) {
        $updated++;
    }
}
$stmt->close();

$total = count($courses);
$inDb = $db->query("SELECT COUNT(*) c FROM courses WHERE course_code LIKE 'ITC-%'")->fetch_assoc()['c'];

echo "ITC catalogue seed complete.\n";
echo "  Source rows:        {$total}\n";
echo "  Newly inserted:     {$inserted}\n";
echo "  Updated (existing): {$updated}\n";
echo "  ITC rows now in DB: {$inDb}\n";
