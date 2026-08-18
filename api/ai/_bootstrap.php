<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once dirname(__DIR__, 2) . '/includes/portal_config.php';
require_once dirname(__DIR__, 2) . '/includes/ai_markdown.php';
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime'=>0, 'path'=>'/wucportal/', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off',
        'httponly'=>true, 'samesite'=>'Strict',
    ]);
    session_start();
}
$aiApiStudentId=trim((string)($_SESSION['Sid']??''));
if ($aiApiStudentId==='' || !preg_match('/^[A-Za-z0-9\/_-]+$/',$aiApiStudentId)) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Authentication required.']);
    exit;
}
require_once dirname(__DIR__, 2) . '/db/connect.php';
$aiAuthStmt=$db->prepare("SELECT status FROM students WHERE SID=? LIMIT 1");
$aiAuthStmt->bind_param('s',$aiApiStudentId);$aiAuthStmt->execute();$aiAuthRow=$aiAuthStmt->get_result()->fetch_assoc();$aiAuthStmt->close();
if (!$aiAuthRow || in_array(strtolower(trim((string)$aiAuthRow['status'])),['inactive','suspended','blocked','disabled','withdrawn','deleted'],true)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Student account access denied.']);
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));

function ai_api_input(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $data = json_decode((string)file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function ai_api_require_post(array $input): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('POST required.');
    }
    $token = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        throw new RuntimeException('Your session token is invalid. Refresh the page and try again.');
    }
}

function ai_api_success(array $payload): never
{
    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ai_api_format_answer(array $result): array
{
    if (isset($result['answer']) && is_string($result['answer'])) {
        $result['answer_html'] = wuc_ai_render_markdown($result['answer']);
    }
    return $result;
}

function ai_api_error(Throwable $e): never
{
    $message=$e->getMessage();
    if (http_response_code() < 400) {
        if ($e instanceof InvalidArgumentException) http_response_code(422);
        elseif (stripos($message,'too many')!==false || stripos($message,'limit has been reached')!==false) http_response_code(429);
        elseif (stripos($message,'access denied')!==false || stripos($message,'only for your registered')!==false || stripos($message,'disabled')!==false || stripos($message,'permits practice')!==false) http_response_code(403);
        else http_response_code(400);
    }
    if (http_response_code()===403 && isset($GLOBALS['db']) && $GLOBALS['db'] instanceof mysqli) {
        try {
            require_once dirname(__DIR__, 2) . '/services/ai/AIAuditService.php';
            (new AIAuditService($GLOBALS['db']))->record((string)($_SESSION['Sid']??'unknown'),'student','ai.api_denied',[
                'entity_type'=>'api_route','entity_id'=>(string)($_SERVER['SCRIPT_NAME']??''),
                'metadata'=>['reason'=>mb_substr($message,0,240)],
            ]);
        } catch(Throwable $ignored) {}
    }
    error_log('AI API: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
