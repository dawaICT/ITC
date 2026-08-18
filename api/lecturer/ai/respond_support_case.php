<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 3) . '/services/support/SupportCaseService.php';
try {
    $input=lecturer_ai_input(); lecturer_ai_require_post($input);
    $data=(new SupportCaseService($db))->respond((int)($input['support_case_id']??0),(string)$_SESSION['staff_id'],'lecturer',(string)($input['message']??''));
    lecturer_ai_success(['data'=>$data]);
} catch(Throwable $e){ lecturer_ai_error($e); }
