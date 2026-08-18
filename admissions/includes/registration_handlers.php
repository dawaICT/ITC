<?php
/**
 * AJAX handlers for new student registration.
 */

require_once __DIR__ . '/../../includes/student_id_generator.php';
require_once __DIR__ . '/../../includes/upload_validator.php';
require_once __DIR__ . '/../../includes/short_course_db.php';
require_once __DIR__ . '/../../includes/helpers/academic_structure_helpers.php';
require_once __DIR__ . '/../../includes/invoice_helpers.php';
require_once __DIR__ . '/../../includes/cse_progression.php';

function admissionsIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }

    return '`' . $identifier . '`';
}

function admissionsTableExists(mysqli $db, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $cache[$table] = $exists;
}

function admissionsColumns(mysqli $db, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!admissionsTableExists($db, $table)) {
        return $cache[$table] = [];
    }

    $columns = [];
    $result = $db->query('SHOW COLUMNS FROM ' . admissionsIdentifier($table));
    while ($row = $result->fetch_assoc()) {
        $columns[$row['Field']] = true;
    }

    return $cache[$table] = $columns;
}

function admissionsHasColumn(mysqli $db, string $table, string $column): bool
{
    $columns = admissionsColumns($db, $table);
    return isset($columns[$column]);
}

function admissionsNormalizeStudentProgramData(mysqli $db, array $data): array
{
    $programCode = trim((string)($data['program_code'] ?? ''));
    if ($programCode === '') {
        return $data;
    }

    $periodInput = wuc_student_program_period_input($db, $programCode, $data, 1);
    $periodPayload = wuc_student_program_period_payload($db, $programCode, $periodInput);
    if (!$periodPayload['ok']) {
        throw new RuntimeException($periodPayload['reason']);
    }

    foreach ($periodPayload['fields'] as $column => $value) {
        $data[$column] = $value;
    }

    return $data;
}

function admissionsInsert(mysqli $db, string $table, array $data): void
{
    if ($table === 'student_program') {
        $data = admissionsNormalizeStudentProgramData($db, $data);
    }

    $columns = admissionsColumns($db, $table);
    $filtered = [];

    foreach ($data as $column => $value) {
        if (isset($columns[$column])) {
            $filtered[$column] = $value;
        }
    }

    if (!$filtered) {
        throw new RuntimeException("No compatible columns found for {$table}.");
    }

    $columnSql = implode(', ', array_map('admissionsIdentifier', array_keys($filtered)));
    $placeholders = implode(', ', array_fill(0, count($filtered), '?'));
    $sql = 'INSERT INTO ' . admissionsIdentifier($table) . " ({$columnSql}) VALUES ({$placeholders})";

    $stmt = $db->prepare($sql);
    $types = str_repeat('s', count($filtered));
    $values = array_values($filtered);
    $bindValues = [$types];
    foreach ($values as $index => $value) {
        $bindValues[] = &$values[$index];
    }
    $stmt->bind_param(...$bindValues);
    $stmt->execute();
    $stmt->close();
}

/**
 * FIX (B1/B2): Shared duplicate-identity check used by both single and bulk
 * flows. Previously only the single-registration path checked for duplicate
 * phone or NRC, and neither path checked email at all. That allowed two
 * students to be registered with the same email, and made bulk import a
 * silent dedup-bypass for phone and NRC too. This helper throws on the first
 * collision so the caller can either return an error (single) or record a
 * per-row failure (bulk).
 */
function admissionsAssertIdentityIsUnique(mysqli $db, string $email, string $phone, string $nrc): void
{
    if ($email !== '') {
        $stmt = $db->prepare('SELECT SID FROM students WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($exists) {
            throw new RuntimeException('Email already registered');
        }
    }
    if ($phone !== '') {
        $stmt = $db->prepare('SELECT SID FROM students WHERE mobile = ? LIMIT 1');
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($exists) {
            throw new RuntimeException('Phone already registered');
        }
    }
    if ($nrc !== '') {
        $stmt = $db->prepare('SELECT SID FROM students WHERE nrc_pass = ? LIMIT 1');
        $stmt->bind_param('s', $nrc);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($exists) {
            throw new RuntimeException('NRC already registered');
        }
    }
}

function admissionsAssertPersonName(string $value, string $label): void
{
    if (!preg_match("/^[a-zA-Z\s'-]+$/", $value)) {
        throw new RuntimeException("Invalid {$label}");
    }
}

function admissionsAssertPhone(string $phone): void
{
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 9 || strlen($digits) > 15) {
        throw new RuntimeException('Phone must have 9-15 digits');
    }
}

function admissionsAssertEntryYear(int $entryYear): void
{
    $min = 2000;
    $max = (int)date('Y') + 1;
    if ($entryYear < $min || $entryYear > $max) {
        throw new RuntimeException("Entry year must be between {$min} and {$max}");
    }
}

function admissionsAssertStudyMode(string $mode): void
{
    if (!in_array($mode, ['Full-time', 'Part-time', 'Distance'], true)) {
        throw new RuntimeException('Invalid Study Mode');
    }
}

function admissionsUploadSingle(array $files, string $key, string $folder, string $prefix, string $studentId, string $kind = 'document'): ?string
{
    if (!isset($files[$key]) || ($files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    // Shared rules: same allowed extensions, 5MB cap, and real-content MIME
    // check used by every new-student registration form. Throws on any failure.
    try {
        $ext = wucValidateUpload($files[$key], $kind);
    } catch (RuntimeException $e) {
        throw new RuntimeException(ucfirst(str_replace('_', ' ', $key)) . ': ' . $e->getMessage());
    }

    $uploadDir = dirname(__DIR__, 2) . '/admissions/uploads/' . trim($folder, '/') . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $name = $prefix . '_' . $studentId . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($files[$key]['tmp_name'], $uploadDir . $name)) {
        throw new RuntimeException("Could not save uploaded {$key}.");
    }

    return $name;
}

/**
 * FIX (CRITICAL): Create a login record for a newly registered student so they
 * can actually sign in to the student portal. Previously the registration
 * pipeline created the students/student_program rows but no row in
 * student_login, leaving every new student with no credentials.
 *
 * The initial password is the student's NRC (a value they already know). The
 * must_change_password flag is set so they are forced to set their own
 * password on first login.
 *
 * Strictly create-if-missing: an existing student_login row is NEVER touched,
 * so calling this from a re-admit / re-import / repair flow can never reset a
 * password the student already chose. The ON DUPLICATE no-op below only
 * absorbs a concurrent-insert race.
 */
function admissionsEnsureStudentLogin(mysqli $db, string $studentId, string $nrc, ?string $email): void
{
    require_once __DIR__ . '/../../includes/helpers/student_provisioning.php';
    wuc_provision_student_account($db, $studentId, [
        'plain_password' => $nrc !== '' ? $nrc : $studentId,
        'email' => $email,
        'only_create_login' => true,
        'assigned_by' => 'registration',
    ]);
}

function generateUniqueInvoiceNumber(mysqli $db, string $year): string
{
    // The real UNIQUE column in `invoices` is `invoice_number`; there is no
    // `invoice_no` column. Query `invoice_number` directly so any future schema
    // drift fails loudly instead of being masked by a non-existent fallback.
    do {
        $num = 'INV-' . $year . '-' . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $stmt = $db->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1');
        $stmt->bind_param('s', $num);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
    } while ($exists);

    return $num;
}

function admissionsResolveProgram(mysqli $db, string $programCode): array
{
    // Period model lives in the dedicated programs.period_mode column. study_mode
    // means Full Time / Part Time (attendance) and must NOT be used for the period;
    // fall back to it only on legacy databases that predate period_mode and happen
    // to store 'semester'/'term' there.
    $hasPeriodMode = admissionsHasColumn($db, 'programs', 'period_mode');
    $periodSelect = $hasPeriodMode ? 'period_mode' : "NULL AS period_mode";
    $stmt = $db->prepare("SELECT program_name, {$periodSelect}, study_mode, program_duration,
                                 academic_structure, duration_value, duration_unit, uses_terms,
                                 uses_semesters, is_short_course, is_transport_exception, examination_type
                          FROM programs WHERE program_code = ? LIMIT 1");
    $stmt->bind_param('s', $programCode);
    $stmt->execute();
    $program = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$program && admissionsTableExists($db, 'transport_programs')) {
        $stmt = $db->prepare("SELECT program_name, duration_days, default_fee FROM transport_programs WHERE program_code = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('s', $programCode);
        $stmt->execute();
        $transportProgram = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($transportProgram) {
            $durationDays = max(1, (int)($transportProgram['duration_days'] ?? 1));
            if (!sc_is_short_course_days($durationDays)) {
                throw new RuntimeException('Transport programme duration is more than six months and must be admitted as an academic programme.');
            }
            return [
                'name' => ($transportProgram['program_name'] ?? $programCode) . ' (Transport)',
                'period_mode' => 'rolling',
                'duration' => max(1, (int)ceil($durationDays / 365)), // years (fallback only)
                'duration_days' => $durationDays,
                'default_fee' => (float)($transportProgram['default_fee'] ?? 0),
                'academic_structure' => 'short_course',
                'duration_value' => $durationDays,
                'duration_unit' => 'days',
                'uses_terms' => 0,
                'uses_semesters' => 0,
                'is_short_course' => 1,
                'is_transport_exception' => 0,
                'examination_type' => 'external',
            ];
        }
    }

    if (!$program) {
        throw new RuntimeException('Invalid Program');
    }

    // Prefer the authoritative period_mode; fall back to a legacy study_mode that
    // happens to hold a period value; otherwise default to semester.
    $periodMode = in_array($program['period_mode'] ?? '', ['semester', 'term', 'short_course'], true)
        ? $program['period_mode']
        : (in_array($program['study_mode'] ?? '', ['semester', 'term'], true) ? $program['study_mode'] : 'semester');
    $duration = (int)($program['program_duration'] ?? 2);
    if ($duration <= 0) {
        $duration = 2;
    }
    if ($duration > 8) {
        $duration = (int)ceil($duration / 12);
    }

    return [
        'name' => $program['program_name'] ?? $programCode,
        'period_mode' => $periodMode,
        'duration' => $duration,
        'default_fee' => 0.0,
        'academic_structure' => $program['academic_structure'] ?? 'certificate_term',
        'duration_value' => $program['duration_value'],
        'duration_unit' => $program['duration_unit'],
        'uses_terms' => (int)($program['uses_terms'] ?? 0),
        'uses_semesters' => (int)($program['uses_semesters'] ?? 0),
        'is_short_course' => (int)($program['is_short_course'] ?? 0),
        'is_transport_exception' => (int)($program['is_transport_exception'] ?? 0),
        'examination_type' => $program['examination_type'] ?? 'external',
    ];
}

/**
 * SQL boolean (0/1) expression telling whether a programs row is term-based,
 * read from the AUTHORITATIVE programs.period_mode column (falling back to a
 * legacy study_mode that happens to hold 'term' only when period_mode is absent).
 * Centralised so admit forms stop deriving the period from study_mode — which is
 * Full/Part-time attendance and is the same for every programme, so it always
 * read "semester" and hid term intakes.
 *
 * @param string $alias optional table alias used in the query (e.g. 'p').
 */
function admissionsTermBasedSql(mysqli $db, string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    $study  = "{$prefix}study_mode";
    if (admissionsHasColumn($db, 'programs', 'period_mode')) {
        $pm = "{$prefix}period_mode";
        return "CAST((LOWER(COALESCE(NULLIF({$pm}, ''), CASE WHEN LOWER(COALESCE({$study}, '')) = 'term' THEN 'term' ELSE 'semester' END)) = 'term') AS UNSIGNED)";
    }
    return "CAST((LOWER(COALESCE({$study}, '')) = 'term') AS UNSIGNED)";
}

/**
 * Whether a programme runs on academic TERMS (3 per year) as opposed to
 * semesters (2) or a rolling short-course schedule. Schema-safe wrapper over
 * admissionsResolveProgram so every admit path agrees on the period model.
 */
function admissionsIsProgramTermBased(mysqli $db, string $programCode): bool
{
    try {
        $program = admissionsResolveProgram($db, $programCode);
    } catch (Throwable $e) {
        return false;
    }
    return ($program['period_mode'] ?? 'semester') === 'term';
}

function admissionsBuildIntake(string $periodMode, string $period, int $entryYear): string
{
    $prefixes = [
        'semester' => ['1' => 'January', '2' => 'June'],
        'term' => ['1' => 'January', '2' => 'May', '3' => 'September'],
    ];

    return ($prefixes[$periodMode][$period] ?? 'January') . ' ' . $entryYear;
}

function admissionsTermDates(string $periodMode, string $period, int $entryYear): array
{
    if ($periodMode === 'term') {
        $starts = ['1' => "{$entryYear}-01-01", '2' => "{$entryYear}-05-01", '3' => "{$entryYear}-09-01"];
        $ends = ['1' => "{$entryYear}-04-30", '2' => "{$entryYear}-08-31", '3' => "{$entryYear}-12-31"];
        return [$starts[$period] ?? "{$entryYear}-01-01", $ends[$period] ?? "{$entryYear}-12-31"];
    }

    $starts = ['1' => "{$entryYear}-01-01", '2' => "{$entryYear}-07-01"];
    $ends = ['1' => "{$entryYear}-06-30", '2' => "{$entryYear}-12-31"];
    return [$starts[$period] ?? "{$entryYear}-01-01", $ends[$period] ?? "{$entryYear}-12-31"];
}

/**
 * Resolve the intake label, start/end dates and end year for an enrolment,
 * honouring the program's period model. Centralised so the single and bulk
 * registration flows stay consistent.
 *
 * For 'rolling' programs (transport short courses) the intake is continuous:
 * the course starts on the registration date and runs for its real length
 * (duration_days), so the duration is never rounded up to a term or a year.
 *
 * @return array{intake:string,start:string,end:string,endYear:int}
 */
function admissionsEnrolmentSchedule(array $program, string $period, int $entryYear, ?string $inputStartDate = null): array
{
    if (($program['academic_structure'] ?? '') === 'short_course') {
        $durationVal = max(1, (int)($program['duration_value'] ?? 1));
        $durationUnit = $program['duration_unit'] ?? 'months';
        $start = !empty($inputStartDate) ? new DateTimeImmutable($inputStartDate) : new DateTimeImmutable('today');
        
        $intervalSpec = 'P1M';
        if ($durationUnit === 'days') {
            $intervalSpec = 'P' . $durationVal . 'D';
        } elseif ($durationUnit === 'weeks') {
            $intervalSpec = 'P' . $durationVal . 'W';
        } elseif ($durationUnit === 'months') {
            $intervalSpec = 'P' . $durationVal . 'M';
        } elseif ($durationUnit === 'years') {
            $intervalSpec = 'P' . $durationVal . 'Y';
        }
        
        try {
            $end = $start->add(new DateInterval($intervalSpec));
        } catch (Throwable $e) {
            $end = $start->add(new DateInterval('P1M'));
        }
        
        return [
            'intake'  => $start->format('F Y'),
            'start'   => $start->format('Y-m-d'),
            'end'     => $end->format('Y-m-d'),
            'endYear' => (int)$end->format('Y'),
        ];
    }

    $periodMode = $program['period_mode'] ?? 'semester';
    [$start, $end] = admissionsTermDates($periodMode, $period, $entryYear);
    return [
        'intake'  => admissionsBuildIntake($periodMode, $period, $entryYear),
        'start'   => $start,
        'end'     => $end,
        'endYear' => $entryYear + (int)($program['duration'] ?? 1),
    ];
}

function admissionsCreateInvoice(
    mysqli $db,
    string $studentId,
    float $amount,
    string $description,
    string $academicYear = '',
    string $period = '1',
    ?int $yearOfStudy = null,
    string $createdBy = 'admissions'
): void
{
    if (!admissionsTableExists($db, 'invoices')) {
        return;
    }

    // A fully sponsored learner owes no invoice balance. Their sponsorship and
    // fee-account records remain the evidence of the award; a zero-value charge
    // would violate the normalized invoice service's business rules.
    if ($amount <= 0.0) {
        return;
    }

    $result = invoice_create_for_student(
        $db,
        $studentId,
        $amount,
        $academicYear,
        $period,
        mb_substr(trim($description), 0, 255),
        null,
        $yearOfStudy,
        $createdBy
    );
    if (empty($result['success'])) {
        throw new RuntimeException((string)($result['message'] ?? 'Unable to create the registration invoice.'));
    }
}

function admissionsAssignCourses(mysqli $db, string $studentId, string $programCode, string $period, string $academicYear): float
{
    if (!admissionsTableExists($db, 'student_courses') || !admissionsTableExists($db, 'program_courses')) {
        return 0.0;
    }

    $programCoursePeriod = admissionsHasColumn($db, 'program_courses', 'semester') ? 'semester' : 'term';
    if (!admissionsHasColumn($db, 'program_courses', $programCoursePeriod)) {
        return 0.0;
    }

    // Courses belong to the academic year; the intake period only narrows
    // rows explicitly flagged period-specific in program_courses.
    $pcCols = wuc_course_availability_columns($db, 'program_courses');
    $where = ['pc.program_code = ?'];
    $types = 's';
    $params = [$programCode];
    $periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcCols[$programCoursePeriod] ?? null, $period);
    if ($periodFilter['sql'] !== '1=1') {
        $where[] = $periodFilter['sql'];
        $types .= $periodFilter['types'];
        $params = array_merge($params, $periodFilter['params']);
    }

    $coursesHaveFee = admissionsHasColumn($db, 'courses', 'course_fee');
    $feeSql = $coursesHaveFee ? ', COALESCE(c.course_fee, 0) AS course_fee' : ', 0 AS course_fee';
    $sql = "SELECT DISTINCT c.course_code{$feeSql}
            FROM courses c
            INNER JOIN program_courses pc ON c.course_code = pc.course_code
            WHERE " . implode(' AND ', $where);

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $courses = $stmt->get_result();

    $total = 0.0;
    while ($course = $courses->fetch_assoc()) {
        $total += (float)$course['course_fee'];
        admissionsInsert($db, 'student_courses', [
            'student_id' => $studentId,
            'Sid' => $studentId,
            'SID' => $studentId,
            'course_code' => $course['course_code'],
            'academic_year' => $academicYear,
            'semester' => $period,
            'term' => $period,
            'status' => 'registered',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $stmt->close();
    return $total;
}

function handleGetRecentRegistrations(mysqli $db): array
{
    try {
        $invoiceJoin = '';
        $invoiceSelect = "'Pending' AS invoice_status";

        if (admissionsTableExists($db, 'invoices') && admissionsHasColumn($db, 'invoices', 'student_id') && admissionsHasColumn($db, 'invoices', 'status')) {
            $invoiceJoin = ' LEFT JOIN invoices i ON s.SID = i.student_id';
            $invoiceSelect = "COALESCE(MAX(i.status), 'Pending') AS invoice_status";
        }

        $sql = "SELECT s.SID, s.Fname, s.Lname, s.nrc_pass,
                       COALESCE(MAX(p.program_name), NULLIF(MAX(s.program), ''), 'Unassigned') AS program_name,
                       {$invoiceSelect}, s.profile_image
                FROM students s
                LEFT JOIN student_program sp ON s.SID = sp.Sid
                LEFT JOIN programs p ON sp.program_code = p.program_code
                {$invoiceJoin}
                GROUP BY s.SID, s.Fname, s.Lname, s.nrc_pass, s.profile_image, s.created_at
                ORDER BY s.created_at DESC
                LIMIT 20";

        $result = $db->query($sql);
        $registrations = [];

        while ($row = $result->fetch_assoc()) {
            $rawStatus = strtolower((string)$row['invoice_status']);
            $registrations[] = [
                'SID' => $row['SID'],
                'Fname' => $row['Fname'],
                'Lname' => $row['Lname'],
                'nrc_pass' => $row['nrc_pass'],
                'program_name' => $row['program_name'],
                'invoice_status' => in_array($rawStatus, ['paid', 'active'], true) ? 'Paid' : 'Pending',
                'profile_image' => $row['profile_image'],
            ];
        }

        return ['success' => true, 'data' => $registrations, 'count' => count($registrations)];
    } catch (Throwable $e) {
        error_log('Recent Registrations Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to load recent registrations'];
    }
}

function handleNewStudentRegistration(mysqli $db, array $input, array $files): array
{
    $transactionStarted = false;

    try {
        $programCode = trim((string)($input['program'] ?? ''));
        if ($programCode === '') {
            throw new RuntimeException('Program is required');
        }
        if ($stageError = wuc_cse_direct_assignment_error($programCode)) {
            throw new RuntimeException($stageError);
        }
        $program = admissionsResolveProgram($db, $programCode);
        $isShortCourse = ($program['academic_structure'] ?? '') === 'short_course';

        $requiredFields = [
            'fname', 'lname', 'gender', 'dob', 'program', 'email', 'phone', 'nrc',
            'nok_fname', 'nok_lname', 'nok_relationship', 'nok_phone',
        ];
        if ($isShortCourse) {
            $requiredFields[] = 'intake_batch';
        } else {
            $requiredFields[] = 'semester';
        }

        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (trim((string)($input[$field] ?? '')) === '') {
                $missingFields[] = $field;
            }
        }

        if ($missingFields) {
            throw new RuntimeException('Missing fields: ' . implode(', ', $missingFields));
        }

        $fname = trim((string)$input['fname']);
        $lname = trim((string)$input['lname']);
        $gender = (string)$input['gender'];
        $dob = (string)$input['dob'];
        $email = strtolower(trim((string)$input['email']));
        $phone = trim((string)$input['phone']);
        $nrc = trim((string)$input['nrc']);
        $entryYear = (int)($input['entry_year'] ?? date('Y'));
        $mode = trim((string)($input['mode'] ?? 'Full-time'));
        $sponsor = trim((string)($input['sponsor'] ?? 'Self'));

        if ($isShortCourse) {
            $period = trim((string)$input['intake_batch']);
            $academicYear = !empty($input['academic_year']) ? trim((string)$input['academic_year']) : (string)$entryYear;
        } else {
            $period = (string)$input['semester'];
            $academicYear = trim((string)($input['academic_year'] ?? ''));
            if ($academicYear === '') {
                $academicYear = (string)$entryYear;
            }
        }

        admissionsAssertPersonName($fname, 'First Name');
        admissionsAssertPersonName($lname, 'Last Name');
        if (!in_array($gender, ['M', 'F'], true)) {
            throw new RuntimeException('Invalid Gender');
        }
        admissionsAssertEntryYear($entryYear);
        admissionsAssertStudyMode($mode);

        $dobDate = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dobDate) {
            throw new RuntimeException('Invalid DOB');
        }
        $age = (new DateTime())->diff($dobDate)->y;
        if ($age < 16) {
            throw new RuntimeException('Student must be at least 16 years old');
        }
        if ($age > 100) {
            throw new RuntimeException('Invalid DOB');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid Email');
        }
        admissionsAssertPhone($phone);
        if (strlen(preg_replace('/\D/', '', $nrc)) < 6) {
            throw new RuntimeException('NRC must have 6+ digits');
        }

        if (!$isShortCourse) {
            $structure = $program['academic_structure'] ?? 'certificate_term';
            if ($structure === 'semester_exception') {
                if (!in_array($period, ['1', '2'], true)) {
                    throw new RuntimeException('Invalid Semester (1 or 2)');
                }
            } else {
                if (!in_array($period, ['1', '2', '3'], true)) {
                    throw new RuntimeException('Invalid Term (1, 2, or 3)');
                }
            }
        }
        // 'rolling' (transport) imposes no period constraint — enrol any time.

        // FIX (B1): admissionsAssertIdentityIsUnique replaces the previous inline
        // phone + NRC dedup checks AND adds the missing email dedup. Email is the
        // primary contact channel, so two students sharing one address would be a
        // real operational problem.
        admissionsAssertIdentityIsUnique($db, $email, $phone, $nrc);

        $studentId = generateStudentId($db, $programCode, $period, $academicYear, $nrc);
        $schedule = admissionsEnrolmentSchedule($program, $period, $entryYear);
        $intake = $schedule['intake'];
        $termStart = $schedule['start'];
        $termEnd = $schedule['end'];
        $endYear = $schedule['endYear'];

        $profileImage = admissionsUploadSingle($files, 'profile_photo', 'profile_images', 'profile', $studentId, 'image') ?? 'default.jpg';
        $nrcFile = admissionsHasColumn($db, 'students', 'nrc_file') ? admissionsUploadSingle($files, 'id_copy', 'nrc', 'nrc', $studentId, 'document') : null;
        $resultsFile = admissionsHasColumn($db, 'students', 'results') ? admissionsUploadSingle($files, 'academic_results', 'results', 'results', $studentId, 'document') : null;
        // FIX: The modal collects a "certificate" upload, but the backend
        // previously never read $_FILES['certificate'] — every certificate
        // attachment silently disappeared. Now handled like the other docs.
        $certificateFile = admissionsHasColumn($db, 'students', 'certificate_file')
            ? admissionsUploadSingle($files, 'certificate', 'certificates', 'cert', $studentId, 'document')
            : null;

        $db->begin_transaction();
        $transactionStarted = true;

        require_once dirname(dirname(__DIR__)) . '/includes/sponsorship_helpers.php';
        $sponsorType = sps_resolve_sponsor_type_from_string($db, $sponsor);
        $requiresApproval = $sponsorType && (int)$sponsorType['requires_approval'] === 1;
        $studentStatus = $requiresApproval ? 'pending' : 'active';
        $programStatus = $requiresApproval ? 'pending' : 'active';

        // FIX: Removed duplicate 'DOB' (uppercase) key — only lowercase 'dob' exists
        // in the students table; admissionsInsert was silently filtering DOB out.
        admissionsInsert($db, 'students', [
            'SID' => $studentId,
            'Fname' => $fname,
            'Lname' => $lname,
            'sex' => $gender,
            'dob' => $dob,
            'email' => $email,
            'mobile' => $phone,
            'nrc_pass' => $nrc,
            'nrc_file' => $nrcFile,
            'results' => $resultsFile,
            'certificate_file' => $certificateFile,
            'profile_image' => $profileImage,
            'h_addre' => trim((string)($input['address'] ?? '')),
            'p_addre' => trim((string)($input['address'] ?? '')),
            'next_kin' => trim((string)($input['nok_fname'] ?? '') . ' ' . (string)($input['nok_lname'] ?? '')),
            'next_kin_mobile' => trim((string)($input['nok_phone'] ?? '')),
            'relat' => trim((string)($input['nok_relationship'] ?? '')),
            'school' => trim((string)($input['previous_school'] ?? '')),
            'sponsor' => $sponsor,
            'academic_year' => $academicYear,
            'program' => $programCode,
            'intake' => $intake,
            'mode' => $mode,
            'year' => 1,
            'status' => $studentStatus,
            'dte_adm' => date('Y-m-d H:i:s'),
            'enrollment_date' => date('Y-m-d H:i:s'),
        ]);

        // FIX: Removed 'semester' key — student_program only has a 'term' column,
        // so the duplicate write was being silently filtered out.
        admissionsInsert($db, 'student_program', [
            'Sid' => $studentId,
            'program_code' => $programCode,
            'intake' => $intake,
            'term' => $period,
            'mode' => $mode,
            'startYear' => $entryYear,
            'endYear' => $endYear,
            'status' => $programStatus,
            'academic_year' => $academicYear,
            'term_start_date' => $termStart,
            'term_end_date' => $termEnd,
        ]);

        // Create sponsorship record
        if ($sponsorType) {
            $coverage = isset($input['bursary_percentage']) && $input['bursary_percentage'] !== '' ? (float)$input['bursary_percentage'] : 100.0;
            if ($sponsorType['code'] === 'cdf' || $sponsorType['code'] === 'teveta') {
                $coverage = 100.0;
            }
            $spsRes = create_student_sponsorship($db, [
                'student_id' => $studentId,
                'sponsor_type_id' => $sponsorType['id'],
                'sponsor_id' => 0,
                'program_code' => $programCode,
                'academic_year' => $academicYear,
                'reference_number' => $input['reference_number'] ?? '',
                'coverage_percent' => $coverage,
                'amount_approved' => null,
                'start_date' => $termStart,
                'end_date' => $termEnd,
                'conditions' => $requiresApproval ? 'Pending sponsorship approval' : 'Auto-approved',
            ], 'admissions');
            if (empty($spsRes['success'])) {
                throw new RuntimeException('Failed to create student sponsorship: ' . ($spsRes['error'] ?? (isset($spsRes['errors']) ? implode(', ', $spsRes['errors']) : 'Unknown validation error')));
            }
        }

        // FIX (CRITICAL): Create login credentials so the new student can actually
        // sign in to the portal. Without this, every registered student had no row
        // in student_login and was permanently locked out.
        admissionsEnsureStudentLogin($db, $studentId, $nrc, $email);

        $totalFees = admissionsAssignCourses($db, $studentId, $programCode, $period, $academicYear);
        if ($totalFees <= 0 && (float)($program['default_fee'] ?? 0) > 0) {
            $totalFees = (float)$program['default_fee'];
        }

        // Only generate invoices if sponsorship does not require approval
        if (!$requiresApproval) {
            $bursary = (float)($input['bursary_percentage'] ?? 0);
            $bursary = max(0.0, min(100.0, $bursary));
            if ($sponsorType && ($sponsorType['code'] === 'cdf' || $sponsorType['code'] === 'teveta')) {
                $bursary = 100.0;
            }
            $invoiceAmount = $totalFees * (1 - $bursary / 100);
            $invoiceDescription = $program['name'] . ' registration - ' . $intake
                . ($bursary > 0 ? sprintf(' (%s bursary %.0f%%)', $sponsor, $bursary) : '');
            admissionsCreateInvoice($db, $studentId, $invoiceAmount, $invoiceDescription, $academicYear, $period, 1, 'admissions');
        }

        $db->commit();

        return [
            'success' => true,
            'message' => "Student registered successfully! ID: {$studentId}",
            'student_id' => $studentId,
        ];
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $db->rollback();
        }
        error_log('Student Registration Error: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handleBulkStudentRegistration(mysqli $db, array $input, array $files): array
{
    $transactionStarted = false;

    try {
        $students = $input['students'] ?? null;
        if (is_string($students)) {
            $students = json_decode($students, true);
        }
        if (!is_array($students) || !$students) {
            throw new RuntimeException('No student data provided');
        }

        $db->begin_transaction();
        $transactionStarted = true;

        $successCount = 0;
        $failedStudents = [];
        $registeredIds = [];

        // FIX (B1/B2): Track identities we've already accepted IN THIS BATCH so
        // duplicates within the same upload are caught even before they hit the
        // DB-level uniqueness check. Without this, two rows in the same batch
        // sharing a phone/NRC/email would both reach admissionsAssertIdentityIsUnique
        // separately and the first would succeed silently before the second errors.
        $seenEmails = [];
        $seenPhones = [];
        $seenNrcs = [];

        foreach ($students as $index => $student) {
            try {
                $db->query('SAVEPOINT bulk_student_' . (int)$index);

                // FIX (B3): Bulk previously required only fname/lname/program/semester
                // and silently inserted students with empty NRC and NULL DOB. Single
                // registration requires gender/dob/nrc/email/phone — bulk must too,
                // otherwise the two flows produce different data quality.
                $required = ['fname', 'lname', 'gender', 'dob', 'email', 'phone', 'nrc', 'program', 'semester'];
                foreach ($required as $field) {
                    if (trim((string)($student[$field] ?? '')) === '') {
                        throw new RuntimeException("Missing required field: {$field}");
                    }
                }

                $gender = (string)$student['gender'];
                if (!in_array($gender, ['M', 'F'], true)) {
                    throw new RuntimeException('Invalid Gender (must be M or F)');
                }

                $programCode = trim((string)$student['program']);
                if ($stageError = wuc_cse_direct_assignment_error($programCode)) {
                    throw new RuntimeException($stageError);
                }
                $period = (string)$student['semester'];
                $entryYear = (int)($student['entry_year'] ?? date('Y'));
                $academicYear = (string)$entryYear;
                $email = strtolower(trim((string)$student['email']));
                $phone = trim((string)$student['phone']);
                $nrc = trim((string)$student['nrc']);
                $fname = trim((string)$student['fname']);
                $lname = trim((string)$student['lname']);
                $mode = trim((string)($student['mode'] ?? 'Full-time'));

                admissionsAssertPersonName($fname, 'First Name');
                admissionsAssertPersonName($lname, 'Last Name');
                admissionsAssertEntryYear($entryYear);
                admissionsAssertStudyMode($mode);

                // FIX (B3 cont'd): age 16-100 mirrors single registration.
                $dobDate = DateTime::createFromFormat('Y-m-d', (string)$student['dob']);
                if (!$dobDate) {
                    throw new RuntimeException('Invalid DOB (use YYYY-MM-DD)');
                }
                $age = (new DateTime())->diff($dobDate)->y;
                if ($age < 16 || $age > 100) {
                    throw new RuntimeException('Student must be 16-100 years old');
                }
                admissionsAssertPhone($phone);
                if (strlen(preg_replace('/\D/', '', $nrc)) < 6) {
                    throw new RuntimeException('NRC must have 6+ digits');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Invalid Email');
                }

                // FIX (B2): in-batch dedup THEN database-level dedup. Catches both
                // "two rows in this CSV share a phone" and "this NRC already exists
                // in the database from a prior registration".
                if (isset($seenEmails[$email])) {
                    throw new RuntimeException("Duplicate email within batch: {$email}");
                }
                if (isset($seenPhones[$phone])) {
                    throw new RuntimeException("Duplicate phone within batch: {$phone}");
                }
                if (isset($seenNrcs[$nrc])) {
                    throw new RuntimeException("Duplicate NRC within batch: {$nrc}");
                }
                admissionsAssertIdentityIsUnique($db, $email, $phone, $nrc);

                $program = admissionsResolveProgram($db, $programCode);
                $isShortCourse = ($program['academic_structure'] ?? '') === 'short_course';

                if ($isShortCourse) {
                    $period = !empty($student['intake_batch']) ? trim($student['intake_batch']) : (!empty($student['semester']) ? trim($student['semester']) : 'Short Course Batch');
                } else {
                    $structure = $program['academic_structure'] ?? 'certificate_term';
                    if ($structure === 'semester_exception') {
                        if (!in_array($period, ['1', '2'], true)) {
                            throw new RuntimeException('Invalid Semester');
                        }
                    } else {
                        if (!in_array($period, ['1', '2', '3'], true)) {
                            throw new RuntimeException('Invalid Term');
                        }
                    }
                }

                $sid = generateStudentId($db, $programCode, $period, $academicYear, $nrc);
                $profilePhoto = uploadFile($files['profile_photos'] ?? [], $index, 'profile') ?? 'default.jpg';
                // FIX (B5): Route academic_results uploads to bulk students too.
                // Previously uploadFile() was only ever called for profile photos,
                // so every academic_results file the modal sent was silently dropped.
                $resultsFile = admissionsHasColumn($db, 'students', 'results')
                    ? uploadFile($files['academic_results'] ?? [], $index, 'results')
                    : null;
                $schedule = admissionsEnrolmentSchedule($program, $period, $entryYear, $student['start_date'] ?? null);
                $intake = trim((string)($student['intake'] ?? ''));
                if ($intake === '') {
                    $intake = $isShortCourse ? $period : $schedule['intake'];
                }
                $termStart = $schedule['start'];
                $termEnd = $schedule['end'];

                require_once dirname(dirname(__DIR__)) . '/includes/sponsorship_helpers.php';
                $sponsor = trim((string)($student['sponsor'] ?? 'Self')) ?: 'Self';
                $sponsorType = sps_resolve_sponsor_type_from_string($db, $sponsor);
                $requiresApproval = $sponsorType && (int)$sponsorType['requires_approval'] === 1;
                $studentStatus = $requiresApproval ? 'pending' : 'active';
                $programStatus = $requiresApproval ? 'pending' : 'active';

                admissionsInsert($db, 'students', [
                    'SID' => $sid,
                    'Fname' => $fname,
                    'Lname' => $lname,
                    'sex' => $gender,
                    'dob' => $dobDate->format('Y-m-d'),
                    'email' => $email,
                    'mobile' => $phone,
                    'nrc_pass' => $nrc,
                    'profile_image' => $profilePhoto,
                    'results' => $resultsFile,
                    'sponsor' => $sponsor,
                    'academic_year' => $academicYear,
                    'program' => $programCode,
                    'intake' => $intake,
                    'mode' => $mode,
                    'year' => 1,
                    'status' => $studentStatus,
                    'dte_adm' => date('Y-m-d H:i:s'),
                    'enrollment_date' => date('Y-m-d H:i:s'),
                ]);

                admissionsInsert($db, 'student_program', [
                    'Sid' => $sid,
                    'program_code' => $programCode,
                    'intake' => $intake,
                    'term' => $period,
                    'mode' => $mode,
                    'startYear' => $entryYear,
                    'endYear' => $schedule['endYear'],
                    'status' => $programStatus,
                    'academic_year' => $academicYear,
                    'term_start_date' => $termStart,
                    'term_end_date' => $termEnd,
                ]);

                // Create sponsorship record
                if ($sponsorType) {
                    $coverage = isset($student['bursary_percentage']) && $student['bursary_percentage'] !== '' ? (float)$student['bursary_percentage'] : 100.0;
                    if ($sponsorType['code'] === 'cdf' || $sponsorType['code'] === 'teveta') {
                        $coverage = 100.0;
                    }
                    $spsRes = create_student_sponsorship($db, [
                        'student_id' => $sid,
                        'sponsor_type_id' => $sponsorType['id'],
                        'sponsor_id' => 0,
                        'program_code' => $programCode,
                        'academic_year' => $academicYear,
                        'reference_number' => $student['reference_number'] ?? '',
                        'coverage_percent' => $coverage,
                        'amount_approved' => null,
                        'start_date' => $termStart,
                        'end_date' => $termEnd,
                        'conditions' => $requiresApproval ? 'Pending sponsorship approval' : 'Auto-approved',
                    ], 'admissions');
                    if (empty($spsRes['success'])) {
                        throw new RuntimeException('Failed to create student sponsorship: ' . ($spsRes['error'] ?? (isset($spsRes['errors']) ? implode(', ', $spsRes['errors']) : 'Unknown validation error')));
                    }
                }

                // FIX (CRITICAL): Mirror the single-registration flow — bulk-imported
                // students also need a student_login row, otherwise none of them can sign in.
                admissionsEnsureStudentLogin(
                    $db,
                    $sid,
                    trim((string)($student['nrc'] ?? '')),
                    isset($student['email']) ? strtolower(trim((string)$student['email'])) : null
                );

                $totalFees = admissionsAssignCourses($db, $sid, $programCode, $period, $academicYear);
                if ($totalFees <= 0 && (float)($program['default_fee'] ?? 0) > 0) {
                    $totalFees = (float)$program['default_fee'];
                }

                if (!$requiresApproval) {
                    $bursary = isset($student['bursary_percentage']) ? (float)$student['bursary_percentage'] : 0.0;
                    $bursary = max(0.0, min(100.0, $bursary));
                    if ($sponsorType && ($sponsorType['code'] === 'cdf' || $sponsorType['code'] === 'teveta')) {
                        $bursary = 100.0;
                    }
                    $invoiceAmount = $totalFees * (1 - $bursary / 100);
                    admissionsCreateInvoice(
                        $db,
                        $sid,
                        $invoiceAmount,
                        $program['name'] . ' registration - ' . $intake,
                        $academicYear,
                        $period,
                        1,
                        'admissions_bulk'
                    );
                }

                // FIX (B2 cont'd): record THIS row's identity so the next row in the
                // same batch can't reuse it. Only set after every commit-eligible
                // step succeeded, so a failed row doesn't "claim" an identity.
                $seenEmails[$email] = true;
                $seenPhones[$phone] = true;
                $seenNrcs[$nrc] = true;

                $successCount++;
                $registeredIds[] = $sid;
            } catch (Throwable $e) {
                $db->query('ROLLBACK TO SAVEPOINT bulk_student_' . (int)$index);
                $failedStudents[] = [
                    'index' => $index + 1,
                    'name' => trim((string)($student['fname'] ?? '') . ' ' . (string)($student['lname'] ?? '')),
                    'error' => $e->getMessage(),
                ];
                error_log("Bulk Registration Error (Student {$index}): " . $e->getMessage());
            }
        }

        $db->commit();

        return [
            'success' => true,
            'message' => "Successfully registered {$successCount} out of " . count($students) . ' students',
            'registered_count' => $successCount,
            'total_count' => count($students),
            'failed_count' => count($failedStudents),
            'failed_students' => $failedStudents,
            'student_ids' => $registeredIds,
        ];
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $db->rollback();
        }
        error_log('Bulk Registration Transaction Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Bulk registration failed: ' . $e->getMessage()];
    }
}

function uploadFile(array $fileArray, int $index, string $type): ?string
{
    if (!isset($fileArray['name'][$index]) || ($fileArray['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    // Re-pack the indexed multi-file entry into a single-file shape so the
    // SHARED validator (same rules as the single + admin + transfer flows)
    // can check extension, size, and real content type.
    $single = [
        'name'     => $fileArray['name'][$index],
        'type'     => $fileArray['type'][$index] ?? '',
        'tmp_name' => $fileArray['tmp_name'][$index] ?? '',
        'error'    => $fileArray['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size'     => $fileArray['size'][$index] ?? 0,
    ];
    $kind = $type === 'profile' ? 'image' : 'document';

    try {
        $ext = wucValidateUpload($single, $kind);
    } catch (RuntimeException $e) {
        // Bulk import is tolerant: a single bad optional file is skipped (and
        // logged), not fatal to the whole batch.
        error_log("Bulk upload skipped (row {$index}, {$type}): " . $e->getMessage());
        return null;
    }

    $folder = $type === 'profile' ? 'profile_images' : 'results';
    $uploadDir = dirname(__DIR__, 2) . '/admissions/uploads/' . $folder . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newName = $type . '_' . time() . '_' . $index . '.' . $ext;
    return move_uploaded_file($single['tmp_name'], $uploadDir . $newName) ? $newName : null;
}

// FIX (B6): Removed dead function getCurrentIntake(). It wasn't called from
// anywhere in the registration pipeline — admissionsBuildIntake() is the
// canonical helper now.
