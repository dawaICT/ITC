<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ai/AIPermissionService.php';

final class KnowledgeDocumentService
{
    public function __construct(private mysqli $db) {}

    public function approve(int $documentId, string $lecturerId): void
    {
        $stmt = $this->db->prepare('SELECT course_id FROM knowledge_documents WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $documentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !(new AIPermissionService($this->db))->isLecturerAssigned($lecturerId, (string)$row['course_id'])) {
            throw new RuntimeException('Only an assigned lecturer may approve this course document.');
        }
        $stmt = $this->db->prepare("UPDATE knowledge_documents SET status='approved',approved_by=?,approved_at=NOW() WHERE id=?");
        $stmt->bind_param('si', $lecturerId, $documentId);
        $stmt->execute();
        $stmt->close();
    }
}
