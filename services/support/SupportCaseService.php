<?php
declare(strict_types=1);

require_once __DIR__ . '/SupportRoutingService.php';
require_once dirname(__DIR__, 2) . '/includes/notification_integrations.php';
require_once dirname(__DIR__) . '/ai/AIAuditService.php';

final class SupportCaseService
{
    private SupportRoutingService $routing;
    private AIAuditService $audit;

    public function __construct(private mysqli $db)
    {
        $this->routing = new SupportRoutingService();
        $this->audit = new AIAuditService($db);
    }

    public function createFromConversation(string $studentId, int $conversationId, array $context, string $studentAttempt, string $priority): array
    {
        if (!in_array($priority, ['low','normal','high'], true)) $priority = 'normal';
        $studentAttempt = mb_substr(trim($studentAttempt), 0, 4000);

        $stmt = $this->db->prepare('SELECT id FROM support_cases WHERE conversation_id=? AND student_id=? AND status NOT IN (\'resolved\',\'closed\') ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('is', $conversationId, $studentId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            return $this->getStudentCase($studentId, (int)$existing['id']);
        }

        $stmt = $this->db->prepare(
            "SELECT c.course_id,c.academic_period_id,
                    (SELECT content FROM ai_messages WHERE conversation_id=c.id AND sender_role='student' ORDER BY id DESC LIMIT 1) original_question,
                    (SELECT content FROM ai_messages WHERE conversation_id=c.id AND sender_role='assistant' ORDER BY id DESC LIMIT 1) ai_response,
                    (SELECT id FROM ai_messages WHERE conversation_id=c.id AND sender_role='assistant' ORDER BY id DESC LIMIT 1) ai_message_id
             FROM ai_conversations c WHERE c.id=? AND c.student_id=? AND c.user_id=? LIMIT 1"
        );
        $stmt->bind_param('iss', $conversationId, $studentId, $studentId);
        $stmt->execute();
        $conversation = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$conversation || empty($conversation['original_question']) || empty($conversation['ai_response'])) {
            throw new RuntimeException('The conversation could not be escalated.');
        }

        $sources = [];
        $messageId = (int)$conversation['ai_message_id'];
        $stmt = $this->db->prepare('SELECT source_type,source_id,title,reference_url,verification_status FROM ai_message_sources WHERE message_id=? ORDER BY priority_rank,id');
        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        $sources = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $sourcesJson = json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $route = $this->routing->route($context);
        $lecturerId = $route['assigned_to_role'] === 'lecturer' ? $route['assigned_to_id'] : null;
        $hodId = $route['assigned_to_role'] === 'head_of_section' && $route['assigned_to_id'] !== '' ? $route['assigned_to_id'] : null;
        $status = $route['status'];
        $periodId = $conversation['academic_period_id'] !== null ? (int)$conversation['academic_period_id'] : null;
        $courseId = (string)$conversation['course_id'];
        $question = (string)$conversation['original_question'];
        $aiResponse = (string)$conversation['ai_response'];

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO support_cases
                 (student_id,course_id,academic_period_id,lecturer_id,hod_id,conversation_id,original_question,ai_response,sources_used,student_attempt,priority,status,due_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 2 DAY))"
            );
            $stmt->bind_param('ssississssss', $studentId, $courseId, $periodId, $lecturerId, $hodId, $conversationId, $question, $aiResponse, $sourcesJson, $studentAttempt, $priority, $status);
            $stmt->execute();
            $caseId = (int)$stmt->insert_id;
            $stmt->close();

            if ($route['assigned_to_id'] !== '') {
                $assignedBy = $studentId;
                $stmt = $this->db->prepare('INSERT INTO support_case_assignments (support_case_id,assigned_to_id,assigned_to_role,assigned_by) VALUES (?,?,?,?)');
                $stmt->bind_param('isss', $caseId, $route['assigned_to_id'], $route['assigned_to_role'], $assignedBy);
                $stmt->execute();
                $stmt->close();
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        if ($route['assigned_to_id'] !== '') {
            wuc_notify_portal($this->db, [
                'user_id' => $route['assigned_to_id'],
                'user_role' => $route['assigned_to_role'] === 'lecturer' ? 'lecturer' : 'head_of_department',
                'module' => 'ai_learning', 'alert_type' => 'learner_support_requested',
                'severity' => $priority === 'high' ? 'warning' : 'info',
                'title' => 'Learner support request',
                'message' => "A learner requested help with {$courseId}.",
                'entity_type' => 'support_case', 'entity_id' => (string)$caseId,
                'action_url' => $route['assigned_to_role'] === 'lecturer'
                    ? '/wucportal/lecturers/ai_teaching_assistant.php?case=' . $caseId
                    : '/wucportal/hod/ai_support_cases.php?case=' . $caseId,
                'dedupe_days' => 0,
            ]);
        }
        $this->audit->record($studentId, 'student', 'support_case.created', [
            'entity_type' => 'support_case', 'entity_id' => (string)$caseId, 'course_id' => $courseId,
            'metadata' => ['assigned_to_role' => $route['assigned_to_role'], 'assigned_to_id' => $route['assigned_to_id']],
        ]);
        return $this->getStudentCase($studentId, $caseId);
    }

    public function respond(int $caseId, string $staffId, string $staffRole, string $message): array
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 6000) {
            throw new InvalidArgumentException('Enter a response of up to 6,000 characters.');
        }
        $case = $this->getStaffCase($caseId, $staffId, $staffRole);
        if (in_array($case['status'], ['resolved','closed'], true)) {
            throw new RuntimeException('This support case is already closed.');
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO support_case_messages (support_case_id,sender_id,sender_role,message) VALUES (?,?,?,?)');
            $stmt->bind_param('isss', $caseId, $staffId, $staffRole, $message);
            $stmt->execute();
            $stmt->close();
            $stmt = $this->db->prepare("UPDATE support_cases SET status='lecturer_responded',updated_at=NOW() WHERE id=?");
            $stmt->bind_param('i', $caseId);
            $stmt->execute();
            $stmt->close();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        wuc_notify_portal($this->db, [
            'user_id' => $case['student_id'], 'user_role' => 'student', 'module' => 'ai_learning',
            'alert_type' => 'lecturer_support_response', 'severity' => 'info',
            'title' => 'Your lecturer responded',
            'message' => "A response is available for your {$case['course_id']} support request.",
            'entity_type' => 'support_case', 'entity_id' => (string)$caseId,
            'action_url' => '/wucportal/students/learning_assistant.php?case=' . $caseId, 'dedupe_days' => 0,
        ]);
        $this->audit->record($staffId, $staffRole, 'support_case.responded', [
            'entity_type' => 'support_case', 'entity_id' => (string)$caseId, 'course_id' => $case['course_id'],
        ]);
        return $this->getStaffCase($caseId, $staffId, $staffRole);
    }

    public function resolve(int $caseId, string $actorId, string $actorRole): void
    {
        if ($actorRole === 'student') {
            $this->getStudentCase($actorId, $caseId);
        } else {
            $this->getStaffCase($caseId, $actorId, $actorRole);
        }
        $stmt = $this->db->prepare("UPDATE support_cases SET status='resolved',resolved_at=NOW(),updated_at=NOW() WHERE id=?");
        $stmt->bind_param('i', $caseId);
        $stmt->execute();
        $stmt->close();
        $this->audit->record($actorId, $actorRole, 'support_case.resolved', ['entity_type'=>'support_case','entity_id'=>(string)$caseId]);
    }

    public function studentFollowUp(int $caseId, string $studentId, string $message): array
    {
        $message=trim($message);
        if($message===''||mb_strlen($message)>4000) throw new InvalidArgumentException('Enter a follow-up of up to 4,000 characters.');
        $case=$this->getStudentCase($studentId,$caseId);
        if(in_array($case['status'],['resolved','closed'],true)) throw new RuntimeException('This support case is already resolved.');
        $this->db->begin_transaction();
        try {
            $role='student';
            $stmt=$this->db->prepare('INSERT INTO support_case_messages (support_case_id,sender_id,sender_role,message) VALUES (?,?,?,?)');
            $stmt->bind_param('isss',$caseId,$studentId,$role,$message);$stmt->execute();$stmt->close();
            $stmt=$this->db->prepare("UPDATE support_cases SET status='student_follow_up',updated_at=NOW() WHERE id=?");
            $stmt->bind_param('i',$caseId);$stmt->execute();$stmt->close();
            $this->db->commit();
        } catch(Throwable $e){$this->db->rollback();throw $e;}
        $recipient=(string)($case['lecturer_id']?:$case['hod_id']);
        if($recipient!=='') wuc_notify_portal($this->db,[
            'user_id'=>$recipient,'user_role'=>$case['lecturer_id']?'lecturer':'head_of_department',
            'module'=>'ai_learning','alert_type'=>'learner_support_follow_up','severity'=>'info',
            'title'=>'Learner support follow-up','message'=>"A learner added a follow-up for {$case['course_id']}.",
            'entity_type'=>'support_case','entity_id'=>(string)$caseId,
            'action_url'=>$case['lecturer_id']?'/wucportal/lecturers/ai_teaching_assistant.php?case='.$caseId:'/wucportal/hod/ai_support_cases.php?case='.$caseId,
            'dedupe_days'=>0,
        ]);
        $this->audit->record($studentId,'student','support_case.follow_up',['entity_type'=>'support_case','entity_id'=>(string)$caseId,'course_id'=>$case['course_id']]);
        return $this->getStudentCase($studentId,$caseId);
    }

    public function close(int $caseId, string $studentId): void
    {
        $case=$this->getStudentCase($studentId,$caseId);
        if($case['status']!=='resolved') throw new RuntimeException('Resolve the support case before closing it.');
        $stmt=$this->db->prepare("UPDATE support_cases SET status='closed',updated_at=NOW() WHERE id=? AND student_id=?");
        $stmt->bind_param('is',$caseId,$studentId);$stmt->execute();$stmt->close();
        $this->audit->record($studentId,'student','support_case.closed',['entity_type'=>'support_case','entity_id'=>(string)$caseId,'course_id'=>$case['course_id']]);
    }

    public function getStudentCase(string $studentId, int $caseId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM support_cases WHERE id=? AND student_id=? LIMIT 1');
        $stmt->bind_param('is', $caseId, $studentId);
        $stmt->execute();
        $case = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$case) throw new RuntimeException('Support case access denied.');
        $case['messages'] = $this->messages($caseId);
        return $case;
    }

    public function getStaffCase(int $caseId, string $staffId, string $staffRole): array
    {
        $role = strtolower($staffRole);
        if (in_array($role, ['head_of_section','head_of_department'], true)) {
            $stmt = $this->db->prepare(
                "SELECT sc.* FROM support_cases sc
                 WHERE sc.id=? AND (
                    sc.hod_id=? OR (
                        sc.hod_id IS NULL AND sc.lecturer_id IS NULL AND sc.status='escalated'
                        AND EXISTS (
                            SELECT 1 FROM courses c
                            LEFT JOIN student_program sp ON sp.Sid=sc.student_id AND LOWER(COALESCE(sp.status,'active'))='active'
                            LEFT JOIN programs p ON p.program_code=sp.program_code
                            INNER JOIN departments d ON d.id=COALESCE(c.department_id,p.department_id)
                            INNER JOIN staff_section_assignments ssa ON ssa.section_id=d.section_id
                            WHERE c.course_code=sc.course_id AND ssa.staff_id=? AND ssa.status='active'
                        )
                    )
                 ) LIMIT 1"
            );
            $stmt->bind_param('iss', $caseId, $staffId, $staffId);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM support_cases WHERE id=? AND lecturer_id=? LIMIT 1');
            $stmt->bind_param('is', $caseId, $staffId);
        }
        $stmt->execute();
        $case = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$case) throw new RuntimeException('Support case access denied.');
        $case['messages'] = $this->messages($caseId);
        return $case;
    }

    public function studentCases(string $studentId, int $limit = 20): array
    {
        $stmt = $this->db->prepare('SELECT id,course_id,priority,status,created_at,due_at,updated_at FROM support_cases WHERE student_id=? ORDER BY updated_at DESC LIMIT ?');
        $stmt->bind_param('si', $studentId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function staffCases(string $staffId, string $role, int $limit = 100): array
    {
        if (in_array(strtolower($role), ['head_of_section','head_of_department'], true)) {
            $stmt = $this->db->prepare(
                "SELECT sc.* FROM support_cases sc
                 WHERE sc.hod_id=? OR (
                    sc.hod_id IS NULL AND sc.lecturer_id IS NULL AND sc.status='escalated'
                    AND EXISTS (
                        SELECT 1 FROM courses c
                        LEFT JOIN student_program sp ON sp.Sid=sc.student_id AND LOWER(COALESCE(sp.status,'active'))='active'
                        LEFT JOIN programs p ON p.program_code=sp.program_code
                        INNER JOIN departments d ON d.id=COALESCE(c.department_id,p.department_id)
                        INNER JOIN staff_section_assignments ssa ON ssa.section_id=d.section_id
                        WHERE c.course_code=sc.course_id AND ssa.staff_id=? AND ssa.status='active'
                    )
                 )
                 ORDER BY FIELD(sc.status,'open','assigned','student_follow_up','escalated','lecturer_responded','resolved','closed'),sc.due_at ASC LIMIT ?"
            );
            $stmt->bind_param('ssi', $staffId, $staffId, $limit);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM support_cases WHERE lecturer_id=? ORDER BY FIELD(status,'open','assigned','student_follow_up','escalated','lecturer_responded','resolved','closed'),due_at ASC LIMIT ?");
            $stmt->bind_param('si', $staffId, $limit);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function messages(int $caseId): array
    {
        $stmt = $this->db->prepare('SELECT id,sender_id,sender_role,message,created_at FROM support_case_messages WHERE support_case_id=? ORDER BY id');
        $stmt->bind_param('i', $caseId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
