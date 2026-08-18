<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
require_once dirname(__DIR__, 3) . '/includes/portal_config.php';
if(session_status()===PHP_SESSION_NONE){session_set_cookie_params(['lifetime'=>0,'path'=>'/wucportal/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Strict']);session_start();}
$lecturerAiStaffId=trim((string)($_SESSION['staff_id']??''));
if($lecturerAiStaffId===''){http_response_code(401);echo json_encode(['success'=>false,'error'=>'Authentication required.']);exit;}
require_once dirname(__DIR__, 3) . '/db/connect.php';
require_once dirname(__DIR__, 3) . '/includes/role_helpers.php';
if(!hasRole(ROLE_LECTURER) && !isSystemsAdmin()){http_response_code(403);echo json_encode(['success'=>false,'error'=>'Lecturer access required.']);exit;}
$lecturerAuthStmt=$db->prepare("SELECT status FROM staff WHERE staff_id=? LIMIT 1");$lecturerAuthStmt->bind_param('s',$lecturerAiStaffId);$lecturerAuthStmt->execute();$lecturerAuthRow=$lecturerAuthStmt->get_result()->fetch_assoc();$lecturerAuthStmt->close();
if(!$lecturerAuthRow || strtolower((string)$lecturerAuthRow['status'])!=='active'){http_response_code(403);echo json_encode(['success'=>false,'error'=>'Staff account access denied.']);exit;}
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));

function lecturer_ai_input(): array
{
    $data = json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : $_POST;
}
function lecturer_ai_require_post(array $input): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('POST required.');
    $token = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        throw new RuntimeException('Your session token is invalid.');
    }
}
function lecturer_ai_success(array $data): never { echo json_encode(['success'=>true]+$data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function lecturer_ai_error(Throwable $e): never { $m=$e->getMessage(); if(http_response_code()<400){if($e instanceof InvalidArgumentException)http_response_code(422);elseif(stripos($m,'access denied')!==false||stripos($m,'only an assigned')!==false)http_response_code(403);else http_response_code(400);} if(http_response_code()===403&&isset($GLOBALS['db'])&&$GLOBALS['db'] instanceof mysqli){try{require_once dirname(__DIR__,3).'/services/ai/AIAuditService.php';(new AIAuditService($GLOBALS['db']))->record((string)($_SESSION['staff_id']??'unknown'),'lecturer','ai.api_denied',['entity_type'=>'api_route','entity_id'=>(string)($_SERVER['SCRIPT_NAME']??''),'metadata'=>['reason'=>mb_substr($m,0,240)]]);}catch(Throwable $ignored){}} error_log('Lecturer AI API: '.$m); echo json_encode(['success'=>false,'error'=>$m]); exit; }
