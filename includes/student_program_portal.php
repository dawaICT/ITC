<?php
/**
 * Resolve the student's academic sub-portal from the live programme assignment.
 *
 * Short courses are intentionally sourced from short_course_enrollments; long
 * programmes are sourced from student_program + programs. This keeps the two
 * catalogues and their registration workflows separate.
 */

require_once __DIR__ . '/short_course_student.php';

if (!function_exists('wuc_student_program_portal_definitions')) {
    /** @return array<string,array{label:string,dashboard_label:string,route:string,icon:string}> */
    function wuc_student_program_portal_definitions(): array
    {
        return [
            'short_course' => [
                'label' => 'Short Course Portal',
                'dashboard_label' => 'Short Course Dashboard',
                'route' => '/wucportal/students/short_course_portal.php',
                'icon' => 'fa-certificate',
            ],
            'trade_test' => [
                'label' => 'Trade Test Portal',
                'dashboard_label' => 'Trade Test Dashboard',
                'route' => '/wucportal/students/trade_test_portal.php',
                'icon' => 'fa-screwdriver-wrench',
            ],
            'certificate' => [
                'label' => 'Certificate Portal',
                'dashboard_label' => 'Certificate Dashboard',
                'route' => '/wucportal/students/certificate_portal.php',
                'icon' => 'fa-award',
            ],
            'diploma' => [
                'label' => 'Diploma Portal',
                'dashboard_label' => 'Diploma Dashboard',
                'route' => '/wucportal/students/diploma_portal.php',
                'icon' => 'fa-graduation-cap',
            ],
            'degree' => [
                'label' => 'Degree Portal',
                'dashboard_label' => 'Degree Dashboard',
                'route' => '/wucportal/students/degree_portal.php',
                'icon' => 'fa-user-graduate',
            ],
            'postgraduate' => [
                'label' => 'Postgraduate Portal',
                'dashboard_label' => 'Postgraduate Dashboard',
                'route' => '/wucportal/students/postgraduate_portal.php',
                'icon' => 'fa-book-bookmark',
            ],
            'academic' => [
                'label' => 'Academic Portal',
                'dashboard_label' => 'Student Dashboard',
                'route' => '/wucportal/students/index.php',
                'icon' => 'fa-building-columns',
            ],
            'unassigned' => [
                'label' => 'Student Portal',
                'dashboard_label' => 'Student Dashboard',
                'route' => '/wucportal/students/index.php',
                'icon' => 'fa-user-graduate',
            ],
        ];
    }
}

if (!function_exists('wuc_student_program_portal_definition_for_type')) {
    /**
     * Resolve or dynamically generate a portal definition for any program type or name.
     *
     * @return array{label:string,dashboard_label:string,route:string,icon:string}
     */
    function wuc_student_program_portal_definition_for_type(string $type, string $programName = ''): array
    {
        $definitions = wuc_student_program_portal_definitions();
        if (isset($definitions[$type])) {
            return $definitions[$type];
        }

        // Clean label from type or program name
        $title = trim($type);
        if ($title === '' && $programName !== '') {
            $title = $programName;
        }
        $formattedTitle = ucwords(str_replace(['_', '-'], ' ', strtolower($title)));
        if ($formattedTitle === '') {
            $formattedTitle = 'Student';
        }

        return [
            'label' => $formattedTitle . ' Portal',
            'dashboard_label' => $formattedTitle . ' Dashboard',
            'route' => '/wucportal/students/program_portal.php?type=' . urlencode($type),
            'icon' => 'fa-graduation-cap',
        ];
    }
}

if (!function_exists('wuc_student_program_portal_profile')) {
    /**
     * @return array{type:string,label:string,dashboard_label:string,route:string,icon:string,program_code:string,program_name:string}
     */
    function wuc_student_program_portal_profile(mysqli $db, string $sid): array
    {
        $sid = trim($sid);
        $type = 'unassigned';
        $programCode = '';
        $programName = '';

        if ($sid !== '' && sc_table_exists($db, 'student_program') && sc_table_exists($db, 'programs')) {
            $statusFilter = sc_has_column($db, 'student_program', 'status')
                ? "AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')"
                : '';
            $activeFilter = sc_has_column($db, 'programs', 'is_active')
                ? 'AND COALESCE(p.is_active, 1) = 1'
                : '';
            $longPredicate = sc_sql_programs_long_only_predicate($db, 'p');
            $qualificationSelect = sc_has_column($db, 'programs', 'qualification_level')
                ? 'p.qualification_level'
                : "'' AS qualification_level";
            $structureSelect = sc_has_column($db, 'programs', 'structure_type')
                ? 'p.structure_type'
                : "'' AS structure_type";
            $academicSelect = sc_has_column($db, 'programs', 'academic_structure')
                ? 'p.academic_structure'
                : "'' AS academic_structure";
            $typeSelect = sc_has_column($db, 'programs', 'program_type')
                ? 'p.program_type'
                : "'' AS program_type";

            $sql = "SELECT sp.program_code, p.program_name, {$typeSelect}, {$qualificationSelect},
                           {$structureSelect}, {$academicSelect}
                      FROM student_program sp
                      INNER JOIN programs p ON p.program_code = sp.program_code
                     WHERE sp.Sid = ? {$statusFilter} {$activeFilter}
                       AND ({$longPredicate})
                     ORDER BY sp.id DESC
                     LIMIT 1";
            if ($stmt = @$db->prepare($sql)) {
                $stmt->bind_param('s', $sid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();

                if ($row) {
                    $programCode = trim((string)($row['program_code'] ?? ''));
                    $programName = trim((string)($row['program_name'] ?? ''));
                    $structure = strtoupper(trim((string)($row['structure_type'] ?? '')));
                    $academicStructure = strtolower(trim((string)($row['academic_structure'] ?? '')));
                    $qualification = strtolower(trim(implode(' ', [
                        (string)($row['program_type'] ?? ''),
                        (string)($row['qualification_level'] ?? ''),
                        $programName,
                    ])));

                    if ($structure === 'TRADE_TEST_LEVEL' || str_contains($academicStructure, 'trade_test')) {
                        $type = 'trade_test';
                    } elseif (str_contains($qualification, 'masters') || str_contains($qualification, 'postgraduate') || str_contains($qualification, 'doctorate') || str_contains($qualification, 'phd')) {
                        $type = 'postgraduate';
                    } elseif (str_contains($qualification, 'degree') || str_contains($qualification, 'bachelor') || str_contains($qualification, 'undergraduate')) {
                        $type = 'degree';
                    } elseif (str_contains($qualification, 'diploma')) {
                        $type = 'diploma';
                    } elseif (str_contains($qualification, 'certificate')) {
                        $type = 'certificate';
                    } elseif (!empty($row['program_type'])) {
                        $rawPType = strtolower(trim((string)$row['program_type']));
                        $type = preg_replace('/[^a-z0-9_]+/', '_', $rawPType) ?: 'academic';
                    } else {
                        $type = 'academic';
                    }
                }
            }
        }

        // Check if student has short course enrollment or short course program assignment
        if (($type === 'unassigned' || $type === 'academic') && $sid !== '') {
            $portalMode = sc_student_portal_mode($db, $sid);
            if ($portalMode === 'short_course') {
                $type = 'short_course';
                // Populate program code/name from short course enrolments or short program row if empty
                if ($programCode === '' || $programName === '') {
                    $scEnrolments = sc_student_enrolments($db, $sid);
                    if ($scEnrolments !== []) {
                        $firstSc = $scEnrolments[0];
                        $programCode = (string)($firstSc['course_code'] ?? '');
                        $programName = (string)($firstSc['course_name'] ?? 'Short Course Programme');
                    } elseif (sc_table_exists($db, 'student_program') && sc_table_exists($db, 'programs')) {
                        $scProgStmt = @$db->prepare("SELECT sp.program_code, p.program_name FROM student_program sp JOIN programs p ON p.program_code = sp.program_code WHERE sp.Sid = ? LIMIT 1");
                        if ($scProgStmt) {
                            $scProgStmt->bind_param('s', $sid);
                            $scProgStmt->execute();
                            if ($scProgRow = $scProgStmt->get_result()->fetch_assoc()) {
                                $programCode = trim((string)($scProgRow['program_code'] ?? ''));
                                $programName = trim((string)($scProgRow['program_name'] ?? ''));
                            }
                            $scProgStmt->close();
                        }
                    }
                }
            }
        }

        $def = wuc_student_program_portal_definition_for_type($type, $programName);

        return array_merge(
            ['type' => $type, 'program_code' => $programCode, 'program_name' => $programName],
            $def
        );
    }
}

if (!function_exists('wuc_student_program_portal_url')) {
    function wuc_student_program_portal_url(mysqli $db, string $sid): string
    {
        return (string)wuc_student_program_portal_profile($db, $sid)['route'];
    }
}

if (!function_exists('wuc_student_is_certificate_portal')) {
    function wuc_student_is_certificate_portal(mysqli $db, string $sid): bool
    {
        if ($sid === '') {
            return false;
        }
        $profile = wuc_student_program_portal_profile($db, $sid);
        return (string)($profile['type'] ?? '') === 'certificate';
    }
}

if (!function_exists('wuc_student_certificate_should_use_enterprise_for_skills')) {
    /** Certificate academic portal hides skills/trade tools; active enterprise members use /enterprise/. */
    function wuc_student_certificate_should_use_enterprise_for_skills(mysqli $db, string $sid): bool
    {
        if ($sid === '' || !wuc_student_is_certificate_portal($db, $sid)) {
            return false;
        }
        require_once __DIR__ . '/enterprise_portal/bootstrap.php';
        $userId = function_exists('ep_current_user_id') ? ep_current_user_id() : (int)($_SESSION['user_id_db'] ?? 0);
        $membership = ep_get_membership_for_user($db, $userId, $sid);
        return ep_membership_effective_status($membership) === 'active';
    }
}
