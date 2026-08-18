<?php
declare(strict_types=1);

/**
 * Canonical online-application acceptance and student conversion workflow.
 *
 * Both the Admissions and Systems Administration endpoints call this service so
 * moving the application, creating the student/login/enrolment/invoice, tracking
 * the conversion, and deleting the inbox row either all commit or all roll back.
 */

require_once __DIR__ . '/applicant_admission.php';
require_once __DIR__ . '/audit.php';

if (!function_exists('wuc_applicant_workflow_columns')) {
    /** @return list<string> */
    function wuc_applicant_workflow_columns(mysqli $db, string $table): array
    {
        $stmt = $db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $columns = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $column = (string)($row['COLUMN_NAME'] ?? '');
            if ($column !== '' && preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                $columns[] = $column;
            }
        }
        $stmt->close();
        return $columns;
    }
}

if (!function_exists('wuc_accept_online_applicant')) {
    /**
     * @return array{success:bool,message:string,student_id?:string,processed_id?:int,default_password?:string,warnings?:array}
     */
    function wuc_accept_online_applicant(mysqli $db, int $onlineApplicantId, string $staffId): array
    {
        if ($onlineApplicantId <= 0 || trim($staffId) === '') {
            return ['success' => false, 'message' => 'Invalid application or admissions officer.'];
        }

        $manageTransaction = !invoice_transaction_active($db);
        $savepoint = 'accept_online_applicant';
        try {
            if ($manageTransaction) {
                $db->begin_transaction();
            } else {
                $db->query('SAVEPOINT ' . $savepoint);
            }

            $lock = $db->prepare('SELECT id FROM online_applicants WHERE id = ? LIMIT 1 FOR UPDATE');
            $lock->bind_param('i', $onlineApplicantId);
            $lock->execute();
            $exists = $lock->get_result()->num_rows === 1;
            $lock->close();
            if (!$exists) {
                throw new RuntimeException('Application not found or already processed.');
            }

            $sourceColumns = wuc_applicant_workflow_columns($db, 'online_applicants');
            $targetColumns = wuc_applicant_workflow_columns($db, 'processed_applicants');
            $common = array_values(array_filter(
                array_intersect($sourceColumns, $targetColumns),
                static fn(string $column): bool => strtolower($column) !== 'id'
            ));
            if ($common === []) {
                throw new RuntimeException('The applicant tables have no compatible fields.');
            }

            $columnSql = '`' . implode('`,`', $common) . '`';
            $move = $db->prepare(
                "INSERT INTO processed_applicants ({$columnSql})
                 SELECT {$columnSql} FROM online_applicants WHERE id = ?"
            );
            $move->bind_param('i', $onlineApplicantId);
            $move->execute();
            if ($move->affected_rows !== 1) {
                $move->close();
                throw new RuntimeException('The application could not be moved for review.');
            }
            $processedId = (int)$move->insert_id;
            $move->close();

            $processedSet = ["status = 'accepted'"];
            $types = '';
            $values = [];
            if (in_array('applicant_id', $targetColumns, true)) {
                $processedSet[] = 'applicant_id = ?';
                $types .= 'i';
                $values[] = $onlineApplicantId;
            }
            if (in_array('processed_by', $targetColumns, true)) {
                $processedSet[] = 'processed_by = ?';
                $types .= 's';
                $values[] = $staffId;
            }
            if (in_array('processed_at', $targetColumns, true)) {
                $processedSet[] = 'processed_at = NOW()';
            }
            $types .= 'i';
            $values[] = $processedId;
            $update = $db->prepare('UPDATE processed_applicants SET ' . implode(', ', $processedSet) . ' WHERE id = ?');
            $update->bind_param($types, ...$values);
            $update->execute();
            $update->close();

            $admission = admitProcessedApplicant($db, $processedId, $staffId);
            if (empty($admission['success']) || empty($admission['student_id'])) {
                throw new RuntimeException((string)($admission['message'] ?? 'Student conversion failed.'));
            }
            $studentId = (string)$admission['student_id'];

            $track = $db->prepare(
                'INSERT INTO processed_applicants_added (applicant_id, student_id, added_by)
                 SELECT ?, ?, ? FROM DUAL
                 WHERE NOT EXISTS (
                    SELECT 1 FROM processed_applicants_added
                    WHERE applicant_id = ? OR student_id = ?
                 )'
            );
            $track->bind_param('issis', $processedId, $studentId, $staffId, $processedId, $studentId);
            $track->execute();
            $track->close();

            $delete = $db->prepare('DELETE FROM online_applicants WHERE id = ?');
            $delete->bind_param('i', $onlineApplicantId);
            $delete->execute();
            if ($delete->affected_rows !== 1) {
                $delete->close();
                throw new RuntimeException('The source application changed while it was being processed.');
            }
            $delete->close();

            audit_log($db, $staffId, 'admissions.application_accepted_and_converted', [
                'record_id' => (string)$processedId,
                'online_applicant_id' => $onlineApplicantId,
                'processed_applicant_id' => $processedId,
                'student_id' => $studentId,
            ]);

            if ($manageTransaction) {
                $db->commit();
            } else {
                $db->query('RELEASE SAVEPOINT ' . $savepoint);
            }

            return [
                'success' => true,
                'message' => 'Application accepted and student account activated.',
                'student_id' => $studentId,
                'processed_id' => $processedId,
                'default_password' => (string)($admission['default_password'] ?? ''),
                'warnings' => (array)($admission['warnings'] ?? []),
            ];
        } catch (Throwable $e) {
            if ($manageTransaction) {
                $db->rollback();
            } else {
                $db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $db->query('RELEASE SAVEPOINT ' . $savepoint);
            }
            error_log('Online applicant conversion failed (id ' . $onlineApplicantId . '): ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
