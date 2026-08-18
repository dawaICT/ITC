<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/elearning_access.php';

final class AIPermissionService
{
    public const MODES = ['full_tutoring', 'hints_only', 'concepts_and_examples', 'practice_only', 'disabled'];

    public function __construct(private mysqli $db) {}

    public function requireStudentCourse(string $studentId, string $courseId): void
    {
        if (!preg_match('/^[A-Za-z0-9\/_-]+$/', $studentId) || !preg_match('/^[A-Za-z0-9._-]+$/', $courseId)) {
            throw new RuntimeException('Invalid learner or course context.');
        }
        if (!isStudentEnrolledInCourse($this->db, $studentId, $courseId)) {
            throw new RuntimeException('You can use the learning assistant only for your registered courses.');
        }
    }

    public function courseSettings(string $courseId): array
    {
        $defaults = [
            'is_enabled' => 1, 'default_ai_mode' => 'full_tutoring', 'allow_external_knowledge' => 0,
            'max_prompt_tokens' => 1800, 'max_response_tokens' => 700,
            'per_user_daily_tokens' => 30000, 'department_monthly_tokens' => 1000000,
        ];
        $stmt = $this->db->prepare('SELECT * FROM ai_course_settings WHERE course_id = ? LIMIT 1');
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? array_merge($defaults, $row) : $defaults;
    }

    public function effectiveMode(string $courseId, ?string $assessmentId, string $requestType): array
    {
        $settings = $this->courseSettings($courseId);
        if ((int)$settings['is_enabled'] !== 1) {
            throw new RuntimeException('AI support is disabled for this course.');
        }
        $mode = (string)$settings['default_ai_mode'];
        if ($assessmentId !== null && $assessmentId !== '') {
            $stmt = $this->db->prepare('SELECT ai_mode FROM ai_assessment_settings WHERE assessment_id = ? AND course_id = ? LIMIT 1');
            $stmt->bind_param('ss', $assessmentId, $courseId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $mode = (string)$row['ai_mode'];
            }
        }
        if (!in_array($mode, self::MODES, true) || $mode === 'disabled') {
            throw new RuntimeException('AI support is disabled for this assessment.');
        }
        if ($requestType === 'chat' && $mode === 'practice_only') {
            throw new RuntimeException('This assessment permits practice activities only.');
        }
        return [$mode, $settings];
    }

    public function enforceUsageLimit(string $userId, array $settings, ?string $departmentId = null): void
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) total FROM ai_usage_logs WHERE user_id = ? AND created_at >= CURDATE()');
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $used = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($used >= (int)$settings['per_user_daily_tokens']) {
            throw new RuntimeException('Your daily AI learning limit has been reached. You can still use Ask My Lecturer.');
        }

        if ($departmentId !== null && $departmentId !== '') {
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(prompt_tokens + completion_tokens),0) total
                                        FROM ai_usage_logs WHERE department_id=?
                                          AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')");
            $stmt->bind_param('s',$departmentId);$stmt->execute();
            $departmentUsed=(int)($stmt->get_result()->fetch_assoc()['total']??0);$stmt->close();
            if($departmentUsed >= (int)$settings['department_monthly_tokens']) {
                throw new RuntimeException('The department monthly AI limit has been reached. Please use Ask My Lecturer.');
            }
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) total FROM ai_usage_logs WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $recent = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($recent >= 10) {
            throw new RuntimeException('Too many requests. Please wait a minute and try again.');
        }
    }

    public function isLecturerAssigned(string $staffId, string $courseId): bool
    {
        return isLecturerAssignedToCourse($this->db, $staffId, $courseId);
    }
}
