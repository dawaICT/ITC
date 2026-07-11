<?php
/**
 * Replace the EL90x e-learning test courses with real ITC catalogue courses.
 *
 * - Removes EL90x rows from every table that references them.
 * - Assigns the 12 ITC-ICT catalogue courses to TEST-PROG (6 per semester).
 * - Enrolls the TEST-PROG student (STU900) into the semester-1 courses so the
 *   e-learning flow has data (semester 2 left open for testing the enroll UI).
 * - Assigns the test lecturer (WUC907) to the program's courses.
 *
 * Idempotent: safe to run repeatedly. Run from project root:
 *   E:\xampp\php\php.exe scripts\replace_testprog_courses_with_itc.php
 */

require_once __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$PROGRAM = 'TEST-PROG';
$STUDENT = 'STU900';
$LECTURER = 'WUC907';

// Semester 1 and 2 course code sets (ITC-ICT catalogue).
$sem1 = ['ITC-ICT-01', 'ITC-ICT-02', 'ITC-ICT-03', 'ITC-ICT-04', 'ITC-ICT-05', 'ITC-ICT-06'];
$sem2 = ['ITC-ICT-07', 'ITC-ICT-08', 'ITC-ICT-09', 'ITC-ICT-10', 'ITC-ICT-11', 'ITC-ICT-12'];
$all = array_merge($sem1, $sem2);

try {
    $db->begin_transaction();

    // 1. Remove EL90x test courses everywhere.
    $deletes = [
        "DELETE FROM program_courses        WHERE course_code LIKE 'EL90%'",
        "DELETE FROM course_registration    WHERE course_code LIKE 'EL90%'",
        "DELETE FROM course_lecturer        WHERE course_code LIKE 'EL90%'",
        "DELETE FROM registered_courses     WHERE course_code LIKE 'EL90%'",
        "DELETE FROM el_course_modules      WHERE course_code LIKE 'EL90%'",
        "DELETE FROM courses                WHERE course_code LIKE 'EL90%'",
    ];
    foreach ($deletes as $sql) {
        $db->query($sql);
        echo "  removed " . $db->affected_rows . " rows: " . $sql . "\n";
    }

    // 2. Assign ITC courses to TEST-PROG (fresh, scoped to our codes for idempotency).
    $clearProg = $db->prepare("DELETE FROM program_courses WHERE program_code = ? AND course_code LIKE 'ITC-ICT-%'");
    $clearProg->bind_param('s', $PROGRAM);
    $clearProg->execute();

    // Pull the catalogue names so program_courses.course_name matches the catalogue.
    $nameOf = [];
    $nameStmt = $db->prepare("SELECT course_code, course_name FROM courses WHERE course_code LIKE 'ITC-ICT-%'");
    $nameStmt->execute();
    $nres = $nameStmt->get_result();
    while ($row = $nres->fetch_assoc()) {
        $nameOf[$row['course_code']] = $row['course_name'];
    }

    $insProg = $db->prepare(
        "INSERT INTO program_courses (program_code, course_code, course_name, semester, credits, year_of_study)
         VALUES (?, ?, ?, ?, 3, 1)"
    );
    $assigned = 0;
    foreach ([1 => $sem1, 2 => $sem2] as $semester => $codes) {
        foreach ($codes as $code) {
            $name = $nameOf[$code] ?? $code;
            $insProg->bind_param('sssi', $PROGRAM, $code, $name, $semester);
            $insProg->execute();
            $assigned++;
        }
    }
    echo "  assigned {$assigned} ITC courses to {$PROGRAM}\n";

    // 3. Enroll STU900 into semester-1 courses.
    // NOTE: registered_courses is a VIEW over course_registration, so we only
    // write to the base table -- the view reflects it automatically.
    $clearReg = $db->prepare("DELETE FROM course_registration WHERE Sid = ? AND course_code LIKE 'ITC-ICT-%'");
    $clearReg->bind_param('s', $STUDENT);
    $clearReg->execute();

    $insReg = $db->prepare(
        "INSERT INTO course_registration (Sid, course_code, semester, Year, created_at) VALUES (?, ?, 1, 1, NOW())"
    );
    foreach ($sem1 as $code) {
        $insReg->bind_param('ss', $STUDENT, $code);
        $insReg->execute();
    }
    echo "  enrolled {$STUDENT} in " . count($sem1) . " semester-1 courses\n";

    // 4. Assign the test lecturer to the program's courses.
    $clearLec = $db->prepare("DELETE FROM course_lecturer WHERE staff_id = ? AND course_code LIKE 'ITC-ICT-%'");
    $clearLec->bind_param('s', $LECTURER);
    $clearLec->execute();
    $insLec = $db->prepare("INSERT INTO course_lecturer (course_code, staff_id) VALUES (?, ?)");
    foreach ($all as $code) {
        $insLec->bind_param('ss', $code, $LECTURER);
        $insLec->execute();
    }
    echo "  assigned lecturer {$LECTURER} to " . count($all) . " courses\n";

    $db->commit();
    echo "DONE\n";
} catch (Throwable $e) {
    $db->rollback();
    echo "FAILED (rolled back): " . $e->getMessage() . "\n";
    exit(1);
}
