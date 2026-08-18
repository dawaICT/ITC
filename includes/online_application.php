<?php
declare(strict_types=1);

require_once __DIR__ . '/upload_validator.php';
require_once __DIR__ . '/applicant_program_helpers.php';
require_once __DIR__ . '/cse_progression.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/portal_access.php';

if (!function_exists('wuc_submit_online_application')) {
    /** @return array{success:bool,message:string,application_id?:int,account_username?:string} */
    function wuc_submit_online_application(mysqli $db, array $input, array $files, string $uploadDir): array
    {
        $fields = [
            'title', 'Fname', 'Lname', 'sex', 'nrc_pass', 'country', 'dob', 'mobile', 'email',
            'h_addre', 'p_addre', 'sponsor', 'next_kin', 'next_kin_mobile', 'relat',
            'program', 'intake', 'mode', 'year',
        ];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = trim((string)($input[$field] ?? ''));
        }

        foreach ($fields as $field) {
            if ($data[$field] === '') {
                return ['success' => false, 'message' => 'Complete every required application field.'];
            }
        }
        if (!in_array($data['sex'], ['M', 'F'], true)) {
            return ['success' => false, 'message' => 'Select a valid gender.'];
        }
        if (!preg_match("/^[\p{L} .'-]{2,100}$/u", $data['Fname'])
            || !preg_match("/^[\p{L} .'-]{2,100}$/u", $data['Lname'])) {
            return ['success' => false, 'message' => 'Enter a valid applicant name.'];
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 100) {
            return ['success' => false, 'message' => 'Enter a valid email address.'];
        }
        $accountPassword = (string)($input['account_password'] ?? '');
        $passwordConfirmation = (string)($input['account_password_confirmation'] ?? '');
        if (strlen($accountPassword) < 10
            || !preg_match('/[A-Z]/', $accountPassword)
            || !preg_match('/[a-z]/', $accountPassword)
            || !preg_match('/\d/', $accountPassword)
            || !preg_match('/[^A-Za-z0-9]/', $accountPassword)) {
            return ['success' => false, 'message' => 'Create a password of at least 10 characters with upper- and lower-case letters, a number, and a symbol.'];
        }
        if (!hash_equals($accountPassword, $passwordConfirmation)) {
            return ['success' => false, 'message' => 'The applicant portal passwords do not match.'];
        }
        $phoneDigits = preg_replace('/\D+/', '', $data['mobile']);
        $kinDigits = preg_replace('/\D+/', '', $data['next_kin_mobile']);
        if (strlen((string)$phoneDigits) < 9 || strlen((string)$phoneDigits) > 15
            || strlen((string)$kinDigits) < 9 || strlen((string)$kinDigits) > 15) {
            return ['success' => false, 'message' => 'Enter valid contact numbers.'];
        }
        $dob = DateTimeImmutable::createFromFormat('!Y-m-d', $data['dob']);
        if (!$dob || $dob->format('Y-m-d') !== $data['dob']) {
            return ['success' => false, 'message' => 'Enter a valid date of birth.'];
        }
        $age = $dob->diff(new DateTimeImmutable('today'))->y;
        if ($age < 16 || $age > 100) {
            return ['success' => false, 'message' => 'Applicants must be between 16 and 100 years old.'];
        }
        if (!preg_match('/^\d{4}$/', $data['year']) || (int)$data['year'] < (int)date('Y') - 1 || (int)$data['year'] > (int)date('Y') + 1) {
            return ['success' => false, 'message' => 'Select a valid intake year.'];
        }

        $program = wuc_resolve_applicant_program($db, $data['program']);
        if (empty($program['valid'])) {
            return ['success' => false, 'message' => 'The selected programme is not available.'];
        }
        $data['program'] = (string)$program['code'];
        if ($stageError = wuc_cse_direct_assignment_error($data['program'])) {
            return ['success' => false, 'message' => $stageError];
        }

        $email = strtolower($data['email']);
        $duplicate = $db->prepare(
            "SELECT 'application' source FROM online_applicants
             WHERE LOWER(TRIM(email)) = ? OR TRIM(nrc_pass) = ?
             UNION ALL
             SELECT 'student' source FROM students
             WHERE LOWER(TRIM(email)) = ? OR TRIM(nrc_pass) = ?
             UNION ALL
             SELECT 'account' source FROM users
             WHERE LOWER(TRIM(username)) = ?
             LIMIT 1"
        );
        $duplicate->bind_param('sssss', $email, $data['nrc_pass'], $email, $data['nrc_pass'], $email);
        $duplicate->execute();
        $duplicateRow = $duplicate->get_result()->fetch_assoc();
        $duplicate->close();
        if ($duplicateRow) {
            return ['success' => false, 'message' => match ($duplicateRow['source']) {
                'student' => 'A student account already exists for these details.',
                'account' => 'An applicant portal account already exists for this email address.',
                default => 'An application already exists for this email or NRC/passport number.',
            }];
        }

        $uploadDir = rtrim($uploadDir, '/\\');
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            return ['success' => false, 'message' => 'The document store is unavailable.'];
        }

        $stored = [];
        $transactionStarted = false;
        try {
            foreach (['results' => 'results', 'nrc_file' => 'nrc', 'deposit_slip' => 'deposit'] as $field => $prefix) {
                $file = (array)($files[$field] ?? []);
                $ext = wucValidateUpload($file, 'document');
                $name = wucSafeUploadName($prefix, $ext);
                $target = $uploadDir . DIRECTORY_SEPARATOR . $name;
                if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
                    throw new RuntimeException('A required document could not be stored.');
                }
                $stored[$field] = $name;
            }

            $db->begin_transaction();
            $transactionStarted = true;
            $stmt = $db->prepare(
                "INSERT INTO online_applicants
                    (title, Fname, Lname, sex, nrc_pass, country, dob, mobile, email, status,
                     h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat, program, intake,
                     mode, year, results, nrc_file, deposit_slip, dte_adm)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->bind_param(
                'ssssssssssssssssssssss',
                $data['title'], $data['Fname'], $data['Lname'], $data['sex'], $data['nrc_pass'],
                $data['country'], $data['dob'], $data['mobile'], $email, $data['h_addre'],
                $data['p_addre'], $data['sponsor'], $data['next_kin'], $data['next_kin_mobile'],
                $data['relat'], $data['program'], $data['intake'], $data['mode'], $data['year'],
                $stored['results'], $stored['nrc_file'], $stored['deposit_slip']
            );
            $stmt->execute();
            $applicationId = (int)$stmt->insert_id;
            $stmt->close();

            $passwordHash = password_hash($accountPassword, PASSWORD_DEFAULT);
            $role = 'applicant';
            $active = 'active';
            $account = $db->prepare(
                'INSERT INTO users (username, password, primary_role, status) VALUES (?, ?, ?, ?)'
            );
            $account->bind_param('ssss', $email, $passwordHash, $role, $active);
            $account->execute();
            $userId = (int)$account->insert_id;
            $account->close();
            if ($userId <= 0) {
                throw new RuntimeException('Applicant portal account was not created.');
            }

            $applicantKey = 'APP-' . $applicationId;
            $displayName = trim($data['Fname'] . ' ' . $data['Lname']);
            $profile = $db->prepare(
                "INSERT INTO user_profiles (user_id, person_type, applicant_id, display_name)
                 VALUES (?, 'applicant', ?, ?)"
            );
            $profile->bind_param('iss', $userId, $applicantKey, $displayName);
            $profile->execute();
            $profile->close();
            wuc_grant_user_portal_access($db, $userId, ['applicant'], 'online_application');

            audit_log($db, 'applicant:' . substr(hash('sha256', $email), 0, 16), 'admissions.application_submitted', [
                'record_id' => (string)$applicationId,
                'application_id' => $applicationId,
                'program_code' => $data['program'],
                'intake' => $data['intake'],
            ]);
            $db->commit();
            $transactionStarted = false;

            return [
                'success' => true,
                'message' => 'Your application was submitted successfully.',
                'application_id' => $applicationId,
                'account_username' => $email,
            ];
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $db->rollback();
            }
            foreach ($stored as $name) {
                $path = $uploadDir . DIRECTORY_SEPARATOR . $name;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            error_log('Online application submission failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Your application could not be submitted. Please review the form and try again.'];
        }
    }
}
