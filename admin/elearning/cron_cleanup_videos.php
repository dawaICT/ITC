<?php
/**
 * Video Auto-Cleanup Script
 * 
 * This script deletes uploaded session videos that are older than 72 hours
 * to save disk space. Run this via cron job or Windows Task Scheduler.
 * 
 * Cron example (run every hour):
 *   0 * * * * php /path/to/wucportal/admin/elearning/cron_cleanup_videos.php
 * 
 * Windows Task Scheduler:
 *   Run: php.exe C:\xampp\htdocs\wucportal\admin\elearning\cron_cleanup_videos.php
 *   Schedule: Repeat every 1 hour
 * 
 * Can also be triggered manually or via page load (see cleanup_old_videos function)
 */

// Configuration
define('VIDEO_MAX_AGE_HOURS', 72); // Videos older than this will be deleted
define('DRY_RUN', false); // Set to true to test without actually deleting

// Prevent web access (only run via CLI or internal call)
$isCLI = (php_sapi_name() === 'cli');
$isInternalCall = defined('CLEANUP_INTERNAL_CALL');

if (!$isCLI && !$isInternalCall) {
    http_response_code(403);
    die('Access denied. This script must be run via CLI or internally.');
}

// Include database connection
require_once __DIR__ . '/../../db/connect.php';

/**
 * Main cleanup function
 * Can be called from other scripts
 */
function cleanup_old_videos($db, $maxAgeHours = VIDEO_MAX_AGE_HOURS, $dryRun = DRY_RUN) {
    $results = [
        'checked' => 0,
        'deleted' => 0,
        'failed' => 0,
        'freed_bytes' => 0,
        'errors' => []
    ];
    
    // Find videos older than the max age
    $query = "SELECT id, video_file_path, video_file_size, topic, video_uploaded_at 
              FROM lms_sessions 
              WHERE video_file_path IS NOT NULL 
                AND video_file_path != ''
                AND video_uploaded_at IS NOT NULL
                AND video_uploaded_at < DATE_SUB(NOW(), INTERVAL ? HOUR)";
    
    $stmt = $db->prepare($query);
    if (!$stmt) {
        $results['errors'][] = "Query prepare failed: " . $db->error;
        return $results;
    }
    
    $stmt->bind_param('i', $maxAgeHours);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $results['checked'] = $result->num_rows;
    
    while ($row = $result->fetch_assoc()) {
        $sessionId = $row['id'];
        $filePath = __DIR__ . '/../../' . $row['video_file_path'];
        $fileSize = $row['video_file_size'] ?? 0;
        $topic = $row['topic'];
        $uploadedAt = $row['video_uploaded_at'];
        
        $logPrefix = "[Session #$sessionId - $topic]";
        
        if ($dryRun) {
            log_message("$logPrefix Would delete: {$row['video_file_path']} (uploaded: $uploadedAt)");
            $results['deleted']++;
            $results['freed_bytes'] += $fileSize;
            continue;
        }
        
        // Delete the file
        $fileDeleted = false;
        if (file_exists($filePath)) {
            if (unlink($filePath)) {
                $fileDeleted = true;
                log_message("$logPrefix File deleted: {$row['video_file_path']}");
            } else {
                $results['errors'][] = "$logPrefix Failed to delete file: $filePath";
                $results['failed']++;
                continue;
            }
        } else {
            // File doesn't exist, just clear the database
            log_message("$logPrefix File not found, cleaning database record: {$row['video_file_path']}");
            $fileDeleted = true;
        }
        
        // Clear database record
        if ($fileDeleted) {
            $update = $db->prepare("UPDATE lms_sessions SET 
                video_file_path = NULL, 
                video_file_size = NULL, 
                video_format = NULL,
                video_uploaded_at = NULL,
                status = 'ended',
                updated_at = NOW()
                WHERE id = ?");
            $update->bind_param('i', $sessionId);
            
            if ($update->execute()) {
                $results['deleted']++;
                $results['freed_bytes'] += $fileSize;
                log_message("$logPrefix Database record cleared");
            } else {
                $results['errors'][] = "$logPrefix Database update failed: " . $db->error;
                $results['failed']++;
            }
        }
    }
    
    return $results;
}

/**
 * Log message (stdout for CLI, can be extended for file logging)
 */
function log_message($message) {
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] $message\n";
    
    if (php_sapi_name() === 'cli') {
        echo $formatted;
    }
    
    // Optionally log to file
    // file_put_contents(__DIR__ . '/../../logs/video_cleanup.log', $formatted, FILE_APPEND);
}

/**
 * Format bytes to human readable
 */
function format_bytes($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// ... (previous code)

/**
 * Cleanup expired sessions (metadata + files)
 */
function cleanup_expired_sessions($db, $maxAgeHours = VIDEO_MAX_AGE_HOURS, $dryRun = DRY_RUN) {
    $results = [
        'checked' => 0,
        'deleted' => 0,
        'failed' => 0,
        'errors' => []
    ];
    
    // Find sessions ended longer than max age
    // Logic: (start_time + duration) < (now - max_age)
    $query = "SELECT id, video_file_path, topic 
              FROM lms_sessions 
              WHERE DATE_ADD(start_time, INTERVAL duration_minutes MINUTE) < DATE_SUB(NOW(), INTERVAL ? HOUR)";
              
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $maxAgeHours);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $results['checked'] = $result->num_rows;
    
    while ($row = $result->fetch_assoc()) {
        $sessionId = $row['id'];
        $filePath = $row['video_file_path'] ? (__DIR__ . '/../../' . $row['video_file_path']) : null;
        $topic = $row['topic'];
        $logPrefix = "[Session #$sessionId - $topic]";
        
        if ($dryRun) {
            log_message("$logPrefix Would delete session record + file");
            $results['deleted']++;
            continue;
        }
        
        // Delete file if exists
        if ($filePath && file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Delete DB record
        $del = $db->prepare("DELETE FROM lms_sessions WHERE id = ?");
        $del->bind_param('i', $sessionId);
        
        if ($del->execute()) {
            $results['deleted']++;
            log_message("$logPrefix Session deleted permanently");
        } else {
            $results['failed']++;
            $results['errors'][] = "$logPrefix DB delete failed: " . $db->error;
        }
    }
    
    return $results;
}

// Run cleanup if executed directly
if ($isCLI) {
    log_message("=== Cleanup Started ===");
    log_message("Max age: " . VIDEO_MAX_AGE_HOURS . " hours");
    
    // 1. Cleanup old videos (retain session, delete file)
    $msg = "Cleaning old videos...";
    log_message($msg);
    $vidResults = cleanup_old_videos($db);
    log_message("Videos deleted: " . $vidResults['deleted']);

    // 2. Cleanup expired sessions (delete row + file)
    log_message("Cleaning expired sessions...");
    $sessResults = cleanup_expired_sessions($db);
    log_message("Sessions deleted: " . $sessResults['deleted']);
    
    log_message("=== Complete ===");
}

