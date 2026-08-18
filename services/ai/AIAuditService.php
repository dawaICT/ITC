<?php
declare(strict_types=1);

final class AIAuditService
{
    public function __construct(private mysqli $db) {}

    public function record(string $actorId, string $actorRole, string $action, array $context = []): void
    {
        $sql = 'INSERT INTO ai_audit_logs
                (actor_id, actor_role, action, entity_type, entity_id, course_id, ip_address, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->db->prepare($sql);
        $entityType = isset($context['entity_type']) ? (string)$context['entity_type'] : null;
        $entityId = isset($context['entity_id']) ? (string)$context['entity_id'] : null;
        $courseId = isset($context['course_id']) ? (string)$context['course_id'] : null;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
        $metadata = json_encode($context['metadata'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt->bind_param('ssssssss', $actorId, $actorRole, $action, $entityType, $entityId, $courseId, $ip, $metadata);
        $stmt->execute();
        $stmt->close();
    }
}
