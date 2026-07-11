<?php
require_once __DIR__ . '/security.php';
/**
 * Google Meet Integration
 * Generates student-specific meeting links that expire and cannot be shared
 */

require_once __DIR__ . '/../db/connect.php';

/**
 * Generate a unique, student-specific meeting link
 * 
 * @param mysqli $db Database connection
 * @param int $session_id Session ID
 * @param string $student_id Student ID
 * @param int $expiry_minutes Link expiration time in minutes
 * @return array ['success' => bool, 'link' => string, 'code' => string, 'error' => string]
 */
function generateStudentMeetingLink($db, $session_id, $student_id, $expiry_minutes = 120) {
    // Check if student already has a valid link
    $stmt = $db->prepare("
        SELECT meeting_link, meeting_code, expires_at, is_revoked 
        FROM lms_student_meeting_links 
        WHERE session_id = ? AND student_id = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param('is', $session_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $existing = $result->fetch_assoc();
        $stmt->close();
        
        // If link is not revoked and still valid, return it
        if (!$existing['is_revoked'] && strtotime($existing['expires_at']) > time()) {
            return [
                'success' => true,
                'link' => $existing['meeting_link'],
                'code' => $existing['meeting_code'],
                'expires_at' => $existing['expires_at'],
                'error' => null
            ];
        }
    } else {
        $stmt->close();
    }
    
    // Get session details
    $stmt = $db->prepare("SELECT * FROM lms_sessions WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $session_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$session) {
        return ['success' => false, 'link' => null, 'code' => null, 'error' => 'Session not found'];
    }
    
    // Generate unique meeting code (internal system)
    $meeting_code = generateUniqueMeetingCode($db, $session_id, $student_id);
    
    // For now, create a proxy link through our system
    // In production, this would call Google Meet API
    $base_url = getBaseUrl();
    $meeting_link = $base_url . '/students/elearning/join_meeting.php?code=' . $meeting_code;
    
    // Calculate expiry time
    $expires_at = date('Y-m-d H:i:s', time() + ($expiry_minutes * 60));
    
    // Store the link
    $stmt = $db->prepare("
        INSERT INTO lms_student_meeting_links 
        (session_id, student_id, meeting_link, meeting_code, expires_at) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('issss', $session_id, $student_id, $meeting_link, $meeting_code, $expires_at);
    
    if ($stmt->execute()) {
        $stmt->close();
        
        return [
            'success' => true,
            'link' => $meeting_link,
            'code' => $meeting_code,
            'expires_at' => $expires_at,
            'error' => null
        ];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'link' => null, 'code' => null, 'error' => $error];
    }
}

/**
 * Generate unique meeting code
 */
function generateUniqueMeetingCode($db, $session_id, $student_id) {
    // Create a unique code based on session, student, and timestamp
    $raw = $session_id . '-' . $student_id . '-' . time() . '-' . bin2hex(random_bytes(8));
    return hash('sha256', $raw);
}

/**
 * Validate meeting link access
 * 
 * @param mysqli $db Database connection
 * @param string $meeting_code Meeting code from URL
 * @param string $student_id Current student ID
 * @return array ['valid' => bool, 'session_id' => int, 'reason' => string, 'actual_link' => string]
 */
function validateMeetingAccess($db, $meeting_code, $student_id) {
    $stmt = $db->prepare("
        SELECT l.*, s.course_code, s.topic, s.join_url, s.google_meet_id, s.provider
        FROM lms_student_meeting_links l
        JOIN lms_sessions s ON l.session_id = s.id
        WHERE l.meeting_code = ? AND l.student_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ss', $meeting_code, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        return [
            'valid' => false,
            'session_id' => null,
            'reason' => 'Invalid or unauthorized meeting link',
            'actual_link' => null
        ];
    }
    
    $link = $result->fetch_assoc();
    $stmt->close();
    
    // Check if revoked
    if ($link['is_revoked']) {
        return [
            'valid' => false,
            'session_id' => $link['session_id'],
            'reason' => 'This meeting link has been revoked',
            'actual_link' => null
        ];
    }
    
    // Check if expired
    if (strtotime($link['expires_at']) < time()) {
        return [
            'valid' => false,
            'session_id' => $link['session_id'],
            'reason' => 'This meeting link has expired',
            'actual_link' => null
        ];
    }
    
    // Check if already used (if single-use)
    if ($link['is_used'] && !$link['allow_link_sharing']) {
        return [
            'valid' => false,
            'session_id' => $link['session_id'],
            'reason' => 'This meeting link has already been used',
            'actual_link' => null
        ];
    }
    
    // Mark as used
    $stmt = $db->prepare("
        UPDATE lms_student_meeting_links 
        SET is_used = 1, used_at = NOW(), ip_address = ?, user_agent = ?
        WHERE id = ?
    ");
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $stmt->bind_param('ssi', $ip, $ua, $link['id']);
    $stmt->execute();
    $stmt->close();
    
    // Log access attempt
    logMeetingAccess($db, $link['session_id'], $student_id, $link['id'], true, null);
    
    // Return the actual platform link for the session (Google Meet / Zoom / Teams).
    $actual_link = null;
    
    if (!empty($link['join_url']) && filter_var($link['join_url'], FILTER_VALIDATE_URL)) {
        $actual_link = $link['join_url'];
        if (($link['provider'] ?? '') === 'zoom' && preg_match('#^https?://(?:[a-z0-9-]+\.)?zoom\.us/j/\d+$#i', $actual_link)) {
            // Avoid invalid random Zoom IDs that cause error 3,001.
            $actual_link = 'https://zoom.us/join';
        }
    } elseif (!empty($link['google_meet_id'])) {
        $meet_id = trim($link['google_meet_id']);
        if (strpos($meet_id, 'http') === 0) {
            $actual_link = $meet_id;
        } elseif (preg_match('/^[a-z0-9]{3}-[a-z0-9]{4}-[a-z0-9]{3}$/i', $meet_id)) {
            $actual_link = 'https://meet.google.com/' . $meet_id;
        } else {
            error_log("Invalid Google Meet code format: " . $meet_id);
            $actual_link = 'https://meet.google.com/new';
        }
    }
    
    if (($link['provider'] ?? '') === 'google_meet' && $actual_link === null) {
        $actual_link = 'https://meet.google.com/new';
    }
    
    if ($actual_link && !filter_var($actual_link, FILTER_VALIDATE_URL)) {
        error_log("Invalid meeting URL generated: " . $actual_link);
        $actual_link = null;
    }
    
    return [
        'valid' => true,
        'session_id' => $link['session_id'],
        'session_data' => [
            'course_code' => $link['course_code'],
            'topic' => $link['topic']
        ],
        'reason' => 'Access granted',
        'actual_link' => $actual_link
    ];
}

/**
 * Revoke a student's meeting link
 */
function revokeMeetingLink($db, $session_id, $student_id) {
    $stmt = $db->prepare("
        UPDATE lms_student_meeting_links 
        SET is_revoked = 1, revoked_at = NOW() 
        WHERE session_id = ? AND student_id = ?
    ");
    $stmt->bind_param('is', $session_id, $student_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Log meeting access attempt
 */
function logMeetingAccess($db, $session_id, $student_id, $meeting_link_id, $granted, $reason) {
    $stmt = $db->prepare("
        INSERT INTO lms_meeting_access_attempts 
        (session_id, student_id, meeting_link_id, ip_address, user_agent, access_granted, denial_reason) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $granted_int = $granted ? 1 : 0;
    $stmt->bind_param('isissis', $session_id, $student_id, $meeting_link_id, $ip, $ua, $granted_int, $reason);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get base URL for the application
 */
function getBaseUrl() {
    $base = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? WUC_APP_BASE_PATH . '/index.php')), '/\\');
    
    // Remove the current script directory structure
    $base = preg_replace('#/includes.*$#', '', $base);
    
    return wuc_public_base_url() . $base;
}

/**
 * Get student's active meeting links
 */
function getStudentMeetingLinks($db, $student_id) {
    $stmt = $db->prepare("
        SELECT l.*, s.course_code, s.topic, s.start_time 
        FROM lms_student_meeting_links l
        JOIN lms_sessions s ON l.session_id = s.id
        WHERE l.student_id = ? 
        AND l.expires_at > NOW()
        AND l.is_revoked = 0
        ORDER BY s.start_time DESC
    ");
    $stmt->bind_param('s', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $links = [];
    while ($row = $result->fetch_assoc()) {
        $links[] = $row;
    }
    $stmt->close();
    
    return $links;
}

/**
 * Clean up expired links (run periodically)
 */
function cleanupExpiredLinks($db) {
    $db->query("
        UPDATE lms_student_meeting_links 
        SET is_revoked = 1, revoked_at = NOW() 
        WHERE expires_at < NOW() AND is_revoked = 0
    ");
    
    return $db->affected_rows;
}
