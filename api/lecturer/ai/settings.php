<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 3) . '/services/ai/AIPermissionService.php';
try {
    $input=lecturer_ai_input(); lecturer_ai_require_post($input);
    $courseId=strtoupper(trim((string)($input['course_id']??'')));
    $staffId=(string)$_SESSION['staff_id'];
    $permission=new AIPermissionService($db);
    if (!$permission->isLecturerAssigned($staffId,$courseId)) throw new RuntimeException('Course access denied.');
    $mode=(string)($input['ai_mode']??'disabled');
    if (!in_array($mode,AIPermissionService::MODES,true)) throw new InvalidArgumentException('Invalid AI mode.');
    $assessmentId=trim((string)($input['assessment_id']??''));
    if ($assessmentId!=='') {
        $stmt=$db->prepare("INSERT INTO ai_assessment_settings (assessment_id,course_id,ai_mode,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE ai_mode=VALUES(ai_mode),updated_by=VALUES(updated_by)");
        $stmt->bind_param('ssss',$assessmentId,$courseId,$mode,$staffId);
    } else {
        $enabled=$mode==='disabled'?0:1;
        $stmt=$db->prepare("INSERT INTO ai_course_settings (course_id,is_enabled,default_ai_mode,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),default_ai_mode=VALUES(default_ai_mode),updated_by=VALUES(updated_by)");
        $stmt->bind_param('siss',$courseId,$enabled,$mode,$staffId);
    }
    $stmt->execute(); $stmt->close();
    lecturer_ai_success(['message'=>'AI settings updated.']);
} catch(Throwable $e){ lecturer_ai_error($e); }
