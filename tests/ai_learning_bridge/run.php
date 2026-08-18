<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../services/ai/AIGateway.php';
require_once __DIR__ . '/../../services/ai/AIContextBuilder.php';
require_once __DIR__ . '/../../services/support/SupportCaseService.php';
require_once __DIR__ . '/../../services/knowledge/VerifiedAnswerService.php';

$studentId = 'CSE26456789';
$courseId = 'DCSE-108';
$lecturerId = 'ITC907';
$headId = 'ITC904';
$runKey = 'bridge-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
$question = "Explain {$runKey} using the approved recursion material.";
$createdConversationIds = [];
$createdCaseIds = [];
$documentId = 0;
$verifiedHash = AIKnowledgeRetriever::questionHash($question);
$unassignedCourse='AIT'.strtoupper(bin2hex(random_bytes(3)));

function check(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
    echo "[pass] {$label}\n";
}

try {
    $stmt = $db->prepare("INSERT INTO knowledge_documents (course_id,title,document_type,status,created_by,approved_by,approved_at) VALUES (?,?,'course_note','approved',?,?,NOW())");
    $title = "Recursion test material {$runKey}";
    $stmt->bind_param('ssss', $courseId, $title, $lecturerId, $lecturerId);
    $stmt->execute(); $documentId = (int)$stmt->insert_id; $stmt->close();
    $stmt = $db->prepare("INSERT INTO knowledge_document_versions (document_id,version_no,extraction_status,created_by) VALUES (?,1,'complete',?)");
    $stmt->bind_param('is', $documentId, $lecturerId); $stmt->execute(); $versionId=(int)$stmt->insert_id; $stmt->close();
    $stmt = $db->prepare("INSERT INTO knowledge_chunks (document_version_id,course_id,chunk_index,heading,content,token_count) VALUES (?,?,0,'Recursion','Recursion solves a problem by reducing it to a smaller instance and requires a base case.',22)");
    $stmt->bind_param('is', $versionId, $courseId); $stmt->execute(); $stmt->close();

    $router = new AIModelRouter(static function(string $system, string $prompt, string $requestType): string {
        check(str_contains($system, 'Approved extracts:'), 'model receives bounded approved extracts');
        if($requestType==='practice') return "1. Identify the base case.\n2. Trace one smaller recursive input.\nAnswer guide: verify termination, then the recursive step [1].";
        return 'Recursion uses a base case and a smaller recursive step [1].';
    });
    $gateway = new AIGateway($db, $router);
    $response = $gateway->askStudent($studentId, ['course_id'=>$courseId,'question'=>$question,'explanation_level'=>'standard']);
    $createdConversationIds[] = (int)$response['conversation_id'];
    check($response['source_status'] === 'approved_sources_used', 'student answer is grounded in approved course knowledge');
    check(count($response['sources']) >= 1 && $response['sources'][0]['priority_rank'] === 1, 'lecturer-approved material has first source priority');
    check($response['lecturer_confirmation_recommended'] === false, 'grounded answer does not force confirmation warning');
    $practice=$gateway->askStudent($studentId,['course_id'=>$courseId,'question'=>"Create recursion practice for {$runKey}",'request_type'=>'practice','explanation_level'=>'standard']);
    $createdConversationIds[]=(int)$practice['conversation_id'];
    check($practice['source_status']==='approved_sources_used' && count($practice['sources'])>=1,'practice generation uses relevant approved course knowledge');

    $context = (new AIContextBuilder($db))->buildStudentContext($studentId, $courseId);
    check($context['lecturer_id'] === $lecturerId, 'official non-admin lecturer is selected');
    check(!empty($context['academic_period_id']), 'active academic period is resolved');

    $support = new SupportCaseService($db);
    $case = $support->createFromConversation($studentId, (int)$response['conversation_id'], $context, 'I identified the base case but need help with the recursive step.', 'normal');
    $createdCaseIds[] = (int)$case['id'];
    check($case['lecturer_id'] === $lecturerId && $case['status'] === 'assigned', 'support case routes to assigned lecturer');

    $denied = false;
    try { $support->getStaffCase((int)$case['id'], 'ITC911', 'lecturer'); } catch (RuntimeException $e) { $denied = true; }
    check($denied, 'unassigned lecturer cannot read support case');

    $responded = $support->respond((int)$case['id'], $lecturerId, 'lecturer', 'Start with the base case, then trace one smaller input before generalising.');
    check($responded['status'] === 'lecturer_responded', 'lecturer response updates workflow status');
    $studentView = $support->getStudentCase($studentId, (int)$case['id']);
    check(count($studentView['messages']) === 1, 'student can read the lecturer response');
    $stmt=$db->prepare("SELECT COUNT(*) total FROM portal_alerts WHERE user_id=? AND entity_type='support_case' AND entity_id=?");$caseIdString=(string)$case['id'];$stmt->bind_param('ss',$studentId,$caseIdString);$stmt->execute();$notice=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
    check($notice >= 1, 'student notification references the support_case record');
    $followed=$support->studentFollowUp((int)$case['id'],$studentId,'Can you show how the smaller input changes?');
    check($followed['status']==='student_follow_up' && count($followed['messages'])===2,'student follow-up is persisted and routed');
    $support->resolve((int)$case['id'],$studentId,'student');
    $support->close((int)$case['id'],$studentId);
    check($support->getStudentCase($studentId,(int)$case['id'])['status']==='closed','student can resolve and close the case');

    $review = (new VerifiedAnswerService($db))->reviewMessage((int)$response['message_id'], $lecturerId, 'approved', 'Checked against the approved note.', 'Recursion');
    check($review['status'] === 'approved', 'assigned lecturer can approve an AI draft');
    $reuse = $gateway->askStudent($studentId, ['course_id'=>$courseId,'question'=>$question,'explanation_level'=>'simple']);
    $createdConversationIds[] = (int)$reuse['conversation_id'];
    check($reuse['source_status'] === 'lecturer_verified', 'verified answer is reused for the same course and question');

    $deniedStudent = false;
    try { $gateway->askStudent('NOT-A-REGISTERED-STUDENT', ['course_id'=>$courseId,'question'=>'Explain recursion']); } catch (RuntimeException $e) { $deniedStudent = true; }
    check($deniedStudent, 'unregistered learner is denied server-side');

    $tempName='AI audit unassigned course';
    $stmt=$db->prepare("INSERT INTO courses (course_code,course_name,department_id,status,course_type) VALUES (?,?,1,'active','service')");$stmt->bind_param('ss',$unassignedCourse,$tempName);$stmt->execute();$stmt->close();
    $stmt=$db->prepare("INSERT INTO course_registration (Sid,course_code,semester,Year,academic_year,status,is_active) VALUES (?,?,2,1,2026,'active',1)");$stmt->bind_param('ss',$studentId,$unassignedCourse);$stmt->execute();$stmt->close();
    $second = $gateway->askStudent($studentId, ['course_id'=>$unassignedCourse,'question'=>"What is the base case for {$runKey}?",'explanation_level'=>'standard']);
    $createdConversationIds[]=(int)$second['conversation_id'];
    $headContext=(new AIContextBuilder($db))->buildStudentContext($studentId,$unassignedCourse);
    check($headContext['lecturer_id']===null && $headContext['hod_id']===$headId,'real unassigned course resolves the responsible Head of Section');
    $headCase=$support->createFromConversation($studentId,(int)$second['conversation_id'],$headContext,'No lecturer was available.','high');
    $createdCaseIds[]=(int)$headCase['id'];
    check($headCase['hod_id']===$headId && $headCase['status']==='escalated','unassigned case falls back to Head of Section');
    $support->getStaffCase((int)$headCase['id'],$headId,'head_of_section');
    check(true,'Head of Section can read routed escalation');
    $outsideDenied=false;try{$support->getStaffCase((int)$headCase['id'],'ITC910','head_of_section');}catch(RuntimeException $e){$outsideDenied=true;}
    check($outsideDenied,'Head of Section outside the section is denied');

    echo "AI learning bridge integration test complete.\n";
} finally {
    foreach ($createdCaseIds as $id) {
        $idText=(string)$id;
        $stmt=$db->prepare("DELETE FROM portal_alerts WHERE entity_type='support_case' AND entity_id=?");$stmt->bind_param('s',$idText);$stmt->execute();$stmt->close();
        $stmt=$db->prepare("DELETE FROM ai_audit_logs WHERE entity_type='support_case' AND entity_id=?");$stmt->bind_param('s',$idText);$stmt->execute();$stmt->close();
    }
    $stmt=$db->prepare("DELETE FROM verified_answers WHERE course_id=? AND question_hash=?");$stmt->bind_param('ss',$courseId,$verifiedHash);$stmt->execute();$stmt->close();
    foreach ($createdConversationIds as $id) {
        $stmt=$db->prepare('DELETE FROM ai_usage_logs WHERE conversation_id=?');$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
        $stmt=$db->prepare("DELETE FROM ai_audit_logs WHERE entity_type IN ('ai_message','support_case') AND (metadata LIKE ? OR entity_id IN (SELECT CAST(id AS CHAR) FROM ai_messages WHERE conversation_id=?))");$like='%"conversation_id":'.$id.'%';$stmt->bind_param('si',$like,$id);$stmt->execute();$stmt->close();
        $stmt=$db->prepare('DELETE FROM ai_conversations WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
    }
    $stmt=$db->prepare('DELETE FROM ai_response_cache WHERE course_id=? AND question_hash=?');$stmt->bind_param('ss',$courseId,$verifiedHash);$stmt->execute();$stmt->close();
    if ($documentId>0) {$stmt=$db->prepare('DELETE FROM knowledge_documents WHERE id=?');$stmt->bind_param('i',$documentId);$stmt->execute();$stmt->close();}
    $stmt=$db->prepare('DELETE FROM course_registration WHERE Sid=? AND course_code=?');$stmt->bind_param('ss',$studentId,$unassignedCourse);$stmt->execute();$stmt->close();
    $stmt=$db->prepare('DELETE FROM courses WHERE course_code=?');$stmt->bind_param('s',$unassignedCourse);$stmt->execute();$stmt->close();
}
