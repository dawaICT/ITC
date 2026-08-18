<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/support/SupportCaseService.php';
try {
    $input=ai_api_input(); ai_api_require_post($input);
    $action=(string)($input['action']??'');
    if (!in_array($action,['follow_up','resolve','close'],true)) throw new InvalidArgumentException('Invalid support case action.');
    $caseId=(int)($input['support_case_id']??0);
    $service=new SupportCaseService($db);
    if($action==='follow_up'){$case=$service->studentFollowUp($caseId,(string)$_SESSION['Sid'],(string)($input['message']??''));ai_api_success(['message'=>'Follow-up sent.','data'=>$case]);}
    if($action==='resolve'){$service->resolve($caseId,(string)$_SESSION['Sid'],'student');ai_api_success(['message'=>'Support case resolved.']);}
    $service->close($caseId,(string)$_SESSION['Sid']);ai_api_success(['message'=>'Support case closed.']);
} catch(Throwable $e){ ai_api_error($e); }
