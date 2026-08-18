<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ai/AIKnowledgeRetriever.php';
require_once dirname(__DIR__) . '/ai/AIPermissionService.php';
require_once dirname(__DIR__) . '/ai/AIAuditService.php';

final class VerifiedAnswerService
{
    public function __construct(private mysqli $db) {}

    public function reviewMessage(int $messageId, string $lecturerId, string $status, string $notes = '', string $topic = ''): array
    {
        if (!in_array($status, ['reviewed','approved','published','rejected'], true)) {
            throw new InvalidArgumentException('Invalid review status.');
        }
        $stmt = $this->db->prepare(
            "SELECT m.id,m.content,c.course_id,
                    (SELECT content FROM ai_messages q WHERE q.conversation_id=m.conversation_id AND q.sender_role='student' AND q.id<m.id ORDER BY q.id DESC LIMIT 1) question
             FROM ai_messages m INNER JOIN ai_conversations c ON c.id=m.conversation_id
             WHERE m.id=? AND m.sender_role='assistant' LIMIT 1"
        );
        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        $message = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$message) throw new RuntimeException('AI answer not found.');

        $permission = new AIPermissionService($this->db);
        if (!$permission->isLecturerAssigned($lecturerId, (string)$message['course_id'])) {
            throw new RuntimeException('Only an assigned lecturer may review this course answer.');
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO ai_content_reviews (message_id,course_id,reviewer_id,status,review_notes) VALUES (?,?,?,?,?)');
            $stmt->bind_param('issss', $messageId, $message['course_id'], $lecturerId, $status, $notes);
            $stmt->execute();
            $reviewId = (int)$stmt->insert_id;
            $stmt->close();
            $stmt = $this->db->prepare('UPDATE ai_messages SET content_status=? WHERE id=?');
            $stmt->bind_param('si', $status, $messageId);
            $stmt->execute();
            $stmt->close();

            $verifiedId = null;
            if (in_array($status, ['approved','published'], true)) {
                $hash = AIKnowledgeRetriever::questionHash((string)$message['question']);
                $verifiedAt = date('Y-m-d H:i:s');
                $stmt = $this->db->prepare(
                    "INSERT INTO verified_answers (course_id,topic,question,question_hash,answer,source_message_id,status,verified_by,verified_at)
                     VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE topic=VALUES(topic),answer=VALUES(answer),source_message_id=VALUES(source_message_id),status=VALUES(status),verified_by=VALUES(verified_by),verified_at=VALUES(verified_at)"
                );
                $stmt->bind_param('sssssisss', $message['course_id'], $topic, $message['question'], $hash, $message['content'], $messageId, $status, $lecturerId, $verifiedAt);
                $stmt->execute();
                $verifiedId = $stmt->insert_id ?: null;
                $stmt->close();
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        (new AIAuditService($this->db))->record($lecturerId, 'lecturer', 'ai_answer.' . $status, [
            'entity_type'=>'ai_message','entity_id'=>(string)$messageId,'course_id'=>$message['course_id'],
            'metadata'=>['review_id'=>$reviewId,'verified_answer_id'=>$verifiedId],
        ]);
        return ['message_id'=>$messageId,'review_id'=>$reviewId,'verified_answer_id'=>$verifiedId,'status'=>$status];
    }
}
