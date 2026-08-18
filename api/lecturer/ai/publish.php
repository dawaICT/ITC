<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 3) . '/services/knowledge/VerifiedAnswerService.php';
try {
    $input=lecturer_ai_input(); lecturer_ai_require_post($input);
    $data=(new VerifiedAnswerService($db))->reviewMessage((int)($input['message_id']??0),(string)$_SESSION['staff_id'],'published',(string)($input['notes']??''),(string)($input['topic']??''));
    lecturer_ai_success(['data'=>$data]);
} catch(Throwable $e){ lecturer_ai_error($e); }
