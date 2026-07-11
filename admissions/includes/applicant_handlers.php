<?php
/**
 * AJAX Handlers for Online Applicants Management
 * Handles all AJAX requests for applicants.php
 */

require_once dirname(__DIR__, 2) . '/includes/applicant_program_helpers.php';

/**
 * Get Pending Applicants
 */
function handleGetApplicants($db) {
    $progJoin = wuc_applicant_program_join_sql();
    $query = "SELECT 
        oa.id, oa.Fname, oa.Lname, oa.sex, oa.email, oa.mobile, 
        oa.country, oa.nrc_pass, oa.program, oa.mode, oa.intake, 
        oa.year, oa.dte_adm, oa.results, oa.status,
        {$progJoin['select']},
        (SELECT COUNT(*) FROM processed_applicants pa WHERE 
            (pa.email = oa.email AND pa.email != '') OR 
            (pa.nrc_pass = oa.nrc_pass AND pa.nrc_pass != '')
        ) as duplicate_count
    FROM online_applicants oa
    {$progJoin['join']}
    WHERE oa.status = 'pending'
    ORDER BY oa.dte_adm DESC";

    $stmt = $db->prepare($query);
    if (!$stmt) {
        return ['success' => false, 'message' => 'Query preparation failed: ' . $db->error];
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $applications = [];
    while ($row = $result->fetch_assoc()) {
        $row['duplicate_count'] = (int)$row['duplicate_count'];
        if (empty($row['program_name'])) {
            $row['program_name'] = $row['program'];
        }
        $applications[] = $row;
    }
    $stmt->close();
    
    return ['success' => true, 'data' => $applications];
}

/**
 * Get Applicant Statistics
 */
function handleGetStats($db) {
    // Pending in online_applicants
    $pending = $db->query("SELECT COUNT(*) FROM online_applicants WHERE status = 'pending'")->fetch_row()[0];
    // Accepted in processed_applicants
    $accepted = $db->query("SELECT COUNT(*) FROM processed_applicants WHERE status = 'accepted'")->fetch_row()[0];
    // Rejected in processed_applicants
    $rejected = $db->query("SELECT COUNT(*) FROM processed_applicants WHERE status = 'rejected'")->fetch_row()[0];
    
    // Potential duplicates (pending ones that match existing processed ones)
    $duplicates = $db->query("
        SELECT COUNT(*) FROM online_applicants oa 
        WHERE oa.status = 'pending' AND EXISTS (
            SELECT 1 FROM processed_applicants pa 
            WHERE (pa.email = oa.email AND pa.email != '') 
               OR (pa.nrc_pass = oa.nrc_pass AND pa.nrc_pass != '')
        )
    ")->fetch_row()[0];
    
    return [
        'success' => true, 
        'data' => [
            'pending' => (int)$pending,
            'accepted' => (int)$accepted,
            'rejected' => (int)$rejected,
            'duplicates' => (int)$duplicates
        ]
    ];
}

/**
 * Get Single Applicant Details
 */
function handleGetApplicantDetails($db, $id) {
    if (empty($id)) {
        return ['success' => false, 'message' => 'Missing ID'];
    }

    $progJoin = wuc_applicant_program_join_sql();
    $stmt = $db->prepare("SELECT oa.*, {$progJoin['select']}
                          FROM online_applicants oa
                          {$progJoin['join']}
                          WHERE oa.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        if (empty($row['program_name'])) {
            $row['program_name'] = $row['program'];
        }
        $resolved = wuc_resolve_applicant_program($db, (string)($row['program'] ?? ''));
        $row['program_valid'] = $resolved['valid'];
        $stmt->close();
        return ['success' => true, 'data' => $row];
    }
    
    $stmt->close();
    return ['success' => false, 'message' => 'Not found'];
}

/**
 * Process Application (Accept/Reject)
 */
function handleProcessApplication($db, $input, $user_name) {
    if (!isset($input['applicant_id'], $input['action'])) {
        return ['success' => false, 'message' => 'Missing parameters'];
    }
    
    $applicant_id = intval($input['applicant_id']);
    $action = in_array($input['action'], ['accept', 'reject']) ? $input['action'] : null;
    
    if (!$action) {
        return ['success' => false, 'message' => 'Invalid action'];
    }
    
    // Start transaction
    $db->begin_transaction();
    
    try {
        // Fetch applicant data
        $fetch_stmt = $db->prepare("SELECT * FROM online_applicants WHERE id = ?");
        $fetch_stmt->bind_param("i", $applicant_id);
        $fetch_stmt->execute();
        $applicant = $fetch_stmt->get_result()->fetch_assoc();
        $fetch_stmt->close();
        
        if (!$applicant) {
            throw new Exception("Applicant not found");
        }

        $resolvedProgram = wuc_resolve_applicant_program($db, (string)($applicant['program'] ?? ''));
        if ($action === 'accept' && !$resolvedProgram['valid']) {
            throw new Exception(
                'Cannot accept: programme "' . ($applicant['program'] ?? '') . '" is not in the catalogue. '
                . 'Ask the applicant to re-apply with a valid programme, or correct the programme code first.'
            );
        }

        $programCode = $resolvedProgram['code'];
        $programLabel = $resolvedProgram['valid'] ? $programCode : (string)($applicant['program'] ?? '');
        
        // Check for duplicates in processed_applicants
        $check_stmt = $db->prepare("SELECT id FROM processed_applicants WHERE 
            (email = ? AND email != '') OR 
            (nrc_pass = ? AND nrc_pass != '') OR 
            (mobile = ? AND mobile != '')
            LIMIT 1");
        $check_stmt->bind_param("sss", $applicant['email'], $applicant['nrc_pass'], $applicant['mobile']);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            if ($action === 'accept') {
                throw new Exception("Duplicate application found in processed records");
            }
        }
        $check_stmt->close();
        
        $applicantRef = (string) $applicant_id;

        // Move to processed_applicants (applicant_id is varchar(50) in schema)
        $insert_stmt = $db->prepare("INSERT INTO processed_applicants 
            (applicant_id, title, Fname, Lname, sex, dob, email, mobile, country, nrc_pass, 
             h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat, program, program_code, mode, 
             intake, year, results, nrc_file, deposit_slip, dte_adm, status, processed_at, processed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
        
        $new_status = $action === 'accept' ? 'accepted' : 'rejected';
        
        $insert_stmt->bind_param("sssssssssssssssssssssssssss",
            $applicantRef,
            $applicant['title'],
            $applicant['Fname'],
            $applicant['Lname'],
            $applicant['sex'],
            $applicant['dob'],
            $applicant['email'],
            $applicant['mobile'],
            $applicant['country'],
            $applicant['nrc_pass'],
            $applicant['h_addre'],
            $applicant['p_addre'],
            $applicant['sponsor'],
            $applicant['next_kin'],
            $applicant['next_kin_mobile'],
            $applicant['relat'],
            $programLabel,
            $programCode,
            $applicant['mode'],
            $applicant['intake'],
            $applicant['year'],
            $applicant['results'],
            $applicant['nrc_file'],
            $applicant['deposit_slip'],
            $applicant['dte_adm'],
            $new_status,
            $user_name
        );
        
        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to move to processed: " . $insert_stmt->error);
        }
        $processed_id = $insert_stmt->insert_id;
        $insert_stmt->close();
        
        // Update original application status
        $update_stmt = $db->prepare("UPDATE online_applicants SET status = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_status, $applicant_id);
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update status: " . $update_stmt->error);
        }
        $update_stmt->close();

        $db->commit();
        
        return [
            'success' => true, 
            'message' => 'Application ' . $action . 'ed successfully',
            'data' => [
                'processed_id' => $processed_id,
                'applicant_name' => $applicant['Fname'] . ' ' . $applicant['Lname']
            ]
        ];
        
    } catch (Exception $e) {
        $db->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
