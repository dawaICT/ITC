<?php
declare(strict_types=1);

final class SupportRoutingService
{
    public function route(array $academicContext): array
    {
        $lecturerId = trim((string)($academicContext['lecturer_id'] ?? ''));
        if ($lecturerId !== '') {
            return ['assigned_to_id' => $lecturerId, 'assigned_to_role' => 'lecturer', 'status' => 'assigned'];
        }
        $hodId = trim((string)($academicContext['hod_id'] ?? ''));
        if ($hodId !== '') {
            return ['assigned_to_id' => $hodId, 'assigned_to_role' => 'head_of_section', 'status' => 'escalated'];
        }
        return ['assigned_to_id' => '', 'assigned_to_role' => 'head_of_section', 'status' => 'escalated'];
    }
}
