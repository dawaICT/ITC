<?php
require_once __DIR__ . '/../db/connect.php';

echo "Populating sample fees...\n";

$academicYear = '1';  // Year of study (1, 2, 3, or 4)
$yearOfStudy = 1;
$semester = 1;

$progRes = $db->query("SELECT DISTINCT program_code FROM programs");
if (!$progRes) {
    echo "Failed to fetch programs: " . $db->error . "\n";
    exit(1);
}

// We'll perform manual safe inserts (avoid bind_param float issues)
$added = 0;
while ($row = $progRes->fetch_assoc()) {
    $program = $row['program_code'];
    // check existing
    $check = $db->prepare("SELECT COUNT(*) AS c FROM fee_structures WHERE program_code=? AND year_of_study=? AND semester=? AND academic_year=?");
    $check->bind_param('siis', $program, $yearOfStudy, $semester, $academicYear);
    $check->execute();
    $cr = $check->get_result()->fetch_assoc();
    $exists = (int)($cr['c'] ?? 0);
    $check->close();
    if ($exists > 0) {
        echo "Skipping {$program} (already has fees)\n";
        continue;
    }

    // Determine tuition base
    $tuition = 45000.00;
    $lab = 2500.00;
    if (stripos($program, 'CS') !== false || stripos($program, 'CE') !== false) {
        $tuition = 50000.00;
        $lab = 3000.00;
    }

    $fees = [
        ['Tuition Fee', $tuition],
        ['Library Fee', 2000.00],
        ['Laboratory Fee', $lab],
        ['Registration Fee', 1000.00]
    ];

    foreach ($fees as $f) {
        $desc = $db->real_escape_string($f[0]);
        $amt = (float)$f[1];
        $sql = "INSERT INTO fee_structures (program_code, academic_year, year_of_study, semester, fee_description, amount, status) VALUES ('" . $db->real_escape_string($program) . "', '" . $db->real_escape_string($academicYear) . "', " . (int)$yearOfStudy . ", " . (int)$semester . ", '" . $desc . "', " . number_format($amt, 2, '.', '') . ", 'active')";
        if ($db->query($sql)) {
            $added++;
        } else {
            echo "Failed to insert for {$program}: " . $db->error . "\n";
        }
    }

    echo "Inserted fees for {$program}\n";
}

echo "Done. Added {$added} fee rows.\n";

?>