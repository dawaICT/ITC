<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/ai/AIContextBuilder.php';
require_once dirname(__DIR__, 2) . '/services/ai/AIPermissionService.php';
require_once dirname(__DIR__, 2) . '/services/support/SupportCaseService.php';
try {
    $input = ai_api_input();
    ai_api_require_post($input);
    $conversationId = (int)($input['conversation_id'] ?? 0);
    $courseId = strtoupper(trim((string)($input['course_id'] ?? '')));
    (new AIPermissionService($db))->requireStudentCourse((string)$_SESSION['Sid'], $courseId);
    $context = (new AIContextBuilder($db))->buildStudentContext((string)$_SESSION['Sid'], $courseId);
    $case = (new SupportCaseService($db))->createFromConversation(
        (string)$_SESSION['Sid'], $conversationId, $context,
        (string)($input['student_attempt'] ?? ''), (string)($input['priority'] ?? 'normal')
    );
    ai_api_success(['data' => $case]);
} catch (Throwable $e) { ai_api_error($e); }
