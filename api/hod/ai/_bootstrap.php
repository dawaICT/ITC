<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/includes/portal_config.php';
if(session_status()===PHP_SESSION_NONE){session_set_cookie_params(['lifetime'=>0,'path'=>'/wucportal/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Strict']);session_start();}
$hodAiStaffId=trim((string)($_SESSION['staff_id']??''));
if($hodAiStaffId===''){http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'error'=>'Authentication required.']);exit;}
require_once dirname(__DIR__, 3) . '/db/connect.php';
require_once dirname(__DIR__, 3) . '/includes/role_helpers.php';
if (!hasRole(ROLE_HEAD_OF_DEPARTMENT) && !isSystemsAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'error'=>'Head of Section access required.']);
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
header('Content-Type: application/json; charset=utf-8');
function hod_ai_input(): array { $d=json_decode((string)file_get_contents('php://input'),true); return is_array($d)?$d:$_POST; }
function hod_ai_require_post(array $i): void { if (($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new RuntimeException('POST required.'); $t=(string)($i['csrf_token']??$_SERVER['HTTP_X_CSRF_TOKEN']??''); if ($t===''||!hash_equals((string)($_SESSION['csrf_token']??''),$t)){http_response_code(403);throw new RuntimeException('Invalid session token.');} }
function hod_ai_success(array $d): never { echo json_encode(['success'=>true]+$d); exit; }
function hod_ai_error(Throwable $e): never { $m=$e->getMessage();if(http_response_code()<400){if($e instanceof InvalidArgumentException)http_response_code(422);elseif(stripos($m,'access denied')!==false)http_response_code(403);else http_response_code(400);}if(http_response_code()===403&&isset($GLOBALS['db'])&&$GLOBALS['db'] instanceof mysqli){try{require_once dirname(__DIR__,3).'/services/ai/AIAuditService.php';(new AIAuditService($GLOBALS['db']))->record((string)($_SESSION['staff_id']??'unknown'),'head_of_section','ai.api_denied',['entity_type'=>'api_route','entity_id'=>(string)($_SERVER['SCRIPT_NAME']??''),'metadata'=>['reason'=>mb_substr($m,0,240)]]);}catch(Throwable $ignored){}} error_log('HOS AI API: '.$m); echo json_encode(['success'=>false,'error'=>$m]); exit; }
