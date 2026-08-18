<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/ai/AIAuditService.php';
try {
    $input = ai_api_input();
    ai_api_require_post($input);
    $messageId = (int)($input['message_id'] ?? 0);
    $rating = (string)($input['rating'] ?? '');
    if (!in_array($rating, ['helpful','not_helpful','incorrect'], true)) throw new InvalidArgumentException('Invalid feedback rating.');
    $stmt = $db->prepare("SELECT m.id,c.course_id FROM ai_messages m INNER JOIN ai_conversations c ON c.id=m.conversation_id WHERE m.id=? AND c.user_id=? AND c.student_id=? AND m.sender_role='assistant' LIMIT 1");
    $studentId = (string)$_SESSION['Sid'];
    $stmt->bind_param('iss', $messageId, $studentId, $studentId);
    $stmt->execute();
    $owned = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$owned) throw new RuntimeException('Message access denied.');
    $comment = mb_substr(trim((string)($input['comment'] ?? '')), 0, 1000);
    $stmt = $db->prepare("INSERT INTO ai_feedback (message_id,user_id,rating,comment) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),comment=VALUES(comment),created_at=NOW()");
    $stmt->bind_param('isss', $messageId, $studentId, $rating, $comment);
    $stmt->execute();
    $stmt->close();
    (new AIAuditService($db))->record($studentId, 'student', 'ai.feedback', ['entity_type'=>'ai_message','entity_id'=>(string)$messageId,'course_id'=>$owned['course_id'],'metadata'=>['rating'=>$rating]]);
    ai_api_success(['message' => 'Feedback recorded.']);
} catch (Throwable $e) { ai_api_error($e); }
