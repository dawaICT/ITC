<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 3) . '/services/support/SupportCaseService.php';
try { $i=hod_ai_input(); hod_ai_require_post($i); $d=(new SupportCaseService($db))->respond((int)($i['support_case_id']??0),(string)$_SESSION['staff_id'],'head_of_section',(string)($i['message']??'')); hod_ai_success(['data'=>$d]); } catch(Throwable $e){ hod_ai_error($e); }
