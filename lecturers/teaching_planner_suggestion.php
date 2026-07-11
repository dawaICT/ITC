<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/teaching_planner/init.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST required.');
    tp_require_csrf();
    $suggestion=(new TeachingPlannerService($db))->suggestField(tp_actor_id(),(int)($_POST['plan_id']??0),(int)($_POST['item_id']??0),(string)($_POST['field']??''));
    echo tp_json(['success'=>true,'suggestion'=>$suggestion,'provider'=>'deterministic_fallback']);
} catch(Throwable $e) {
    http_response_code(400); echo tp_json(['success'=>false,'message'=>$e->getMessage()]);
}

