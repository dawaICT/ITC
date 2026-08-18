<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../../db/connect.php';
require_once __DIR__.'/../../services/ai/AIGateway.php';
require_once __DIR__.'/../../services/ai/AIPermissionService.php';

$student='CSE26456789';$course='DCSE-108';$tag='audit'.bin2hex(random_bytes(4));$conversationIds=[];$assessmentIds=[];
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAILED: '.$label);echo "[pass] {$label}\n";}
$original=null;$stmt=$db->prepare('SELECT * FROM ai_course_settings WHERE course_id=?');$stmt->bind_param('s',$course);$stmt->execute();$original=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
try{
    $enabled=1;$defaultMode='full_tutoring';$actor='audit';
    $stmt=$db->prepare("INSERT INTO ai_course_settings(course_id,is_enabled,default_ai_mode,updated_by)VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE is_enabled=1");$stmt->bind_param('siss',$course,$enabled,$defaultMode,$actor);$stmt->execute();$stmt->close();
    $captured=[];
    $router=new AIModelRouter(static function(string $system,string $prompt,string $requestType)use(&$captured):string{$captured[]=$system;return $requestType==='practice'?"1. Practice concept.\nAnswer guide: review the concept.":'Controlled explanation.';});
    $gateway=new AIGateway($db,$router);
    foreach(['full_tutoring','hints_only','concepts_and_examples'] as $mode){
        $assessment=$tag.'-'.$mode;$assessmentIds[]=$assessment;
        $stmt=$db->prepare("INSERT INTO ai_assessment_settings(assessment_id,course_id,ai_mode,updated_by)VALUES(?,?,?,'audit')");$stmt->bind_param('sss',$assessment,$course,$mode);$stmt->execute();$stmt->close();
        $r=$gateway->askStudent($student,['course_id'=>$course,'assessment_id'=>$assessment,'question'=>"{$tag} {$mode} question"]);$conversationIds[]=(int)$r['conversation_id'];
        verify($r['ai_mode']===$mode,"{$mode} allows a server-side chat request");
        $last=(string)end($captured);
        if($mode==='hints_only')verify(str_contains($last,'Give hints and questions'),'hints_only restriction reaches the model system prompt');
        if($mode==='concepts_and_examples')verify(str_contains($last,'analogous examples'),'concepts_and_examples restriction reaches the model system prompt');
    }
    $practiceId=$tag.'-practice';$assessmentIds[]=$practiceId;$mode='practice_only';
    $stmt=$db->prepare("INSERT INTO ai_assessment_settings(assessment_id,course_id,ai_mode,updated_by)VALUES(?,?,?,'audit')");$stmt->bind_param('sss',$practiceId,$course,$mode);$stmt->execute();$stmt->close();
    $blocked=false;try{$gateway->askStudent($student,['course_id'=>$course,'assessment_id'=>$practiceId,'question'=>$tag.' direct answer']);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'practice activities only');}
    verify($blocked,'practice_only blocks direct tutoring at the service boundary');
    $r=$gateway->askStudent($student,['course_id'=>$course,'assessment_id'=>$practiceId,'question'=>$tag.' practice','request_type'=>'practice']);$conversationIds[]=(int)$r['conversation_id'];verify($r['ai_mode']==='practice_only','practice_only permits practice generation');

    $disabledId=$tag.'-disabled';$assessmentIds[]=$disabledId;$mode='disabled';
    $stmt=$db->prepare("INSERT INTO ai_assessment_settings(assessment_id,course_id,ai_mode,updated_by)VALUES(?,?,?,'audit')");$stmt->bind_param('sss',$disabledId,$course,$mode);$stmt->execute();$stmt->close();
    $blocked=false;try{$gateway->askStudent($student,['course_id'=>$course,'assessment_id'=>$disabledId,'question'=>$tag.' disabled']);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'disabled');}
    verify($blocked,'disabled mode blocks direct service requests');

    foreach($conversationIds as $id){$stmt=$db->prepare('DELETE FROM ai_usage_logs WHERE conversation_id=?');$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();}
    $conversationIds=[];$conversationId=0;
    for($i=1;$i<=6;$i++){$r=$gateway->askStudent($student,['course_id'=>$course,'question'=>"{$tag} summary turn {$i}",'conversation_id'=>$conversationId]);$conversationId=(int)$r['conversation_id'];if(!in_array($conversationId,$conversationIds,true))$conversationIds[]=$conversationId;}
    $stmt=$db->prepare('SELECT context_summary FROM ai_conversations WHERE id=?');$stmt->bind_param('i',$conversationId);$stmt->execute();$summary=(string)($stmt->get_result()->fetch_assoc()['context_summary']??'');$stmt->close();verify($summary!=='','long conversations are summarised after twelve messages');
    $stmt=$db->prepare('SELECT AVG(latency_ms) avg_ms,SUM(prompt_tokens+completion_tokens) tokens,SUM(estimated_cost) cost FROM ai_usage_logs WHERE conversation_id=?');$stmt->bind_param('i',$conversationId);$stmt->execute();$perf=$stmt->get_result()->fetch_assoc();$stmt->close();echo '[metric] controlled_avg_ms='.round((float)$perf['avg_ms'],1).' tokens='.(int)$perf['tokens'].' cost='.(float)$perf['cost']."\n";

    $permission=new AIPermissionService($db);$limitUser='AUDIT-'.$tag;$department='1';
    $stmt=$db->prepare("INSERT INTO ai_usage_logs(user_id,department_id,request_type,model_name,prompt_tokens,completion_tokens)VALUES(?,?,'chat','audit',6,4)");
    for($i=0;$i<10;$i++){$stmt->bind_param('ss',$limitUser,$department);$stmt->execute();}$stmt->close();
    $rateSettings=['per_user_daily_tokens'=>100000,'department_monthly_tokens'=>100000];$rateBlocked=false;try{$permission->enforceUsageLimit($limitUser,$rateSettings,$department);}catch(RuntimeException $e){$rateBlocked=str_contains($e->getMessage(),'Too many requests');}verify($rateBlocked,'per-minute rate limit is enforced server-side');
    $db->query("DELETE FROM ai_usage_logs WHERE user_id='".$db->real_escape_string($limitUser)."'");
    $deptUser='DEPT-'.$tag;$stmt=$db->prepare("INSERT INTO ai_usage_logs(user_id,department_id,request_type,model_name,prompt_tokens,completion_tokens)VALUES(?,?,'chat','audit',60,40)");$stmt->bind_param('ss',$deptUser,$department);$stmt->execute();$stmt->close();
    $deptBlocked=false;try{$permission->enforceUsageLimit('OTHER-'.$tag,['per_user_daily_tokens'=>100000,'department_monthly_tokens'=>100],$department);}catch(RuntimeException $e){$deptBlocked=str_contains($e->getMessage(),'department monthly');}verify($deptBlocked,'department monthly token limit is enforced server-side');
    $stmt=$db->prepare('DELETE FROM ai_usage_logs WHERE user_id=?');$stmt->bind_param('s',$deptUser);$stmt->execute();$stmt->close();
}finally{
    foreach($assessmentIds as $id){$stmt=$db->prepare('DELETE FROM ai_assessment_settings WHERE assessment_id=? AND course_id=?');$stmt->bind_param('ss',$id,$course);$stmt->execute();$stmt->close();}
    foreach($conversationIds as $id){$stmt=$db->prepare('DELETE FROM ai_usage_logs WHERE conversation_id=?');$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();$stmt=$db->prepare("DELETE FROM ai_audit_logs WHERE entity_type='ai_message' AND entity_id IN (SELECT CAST(id AS CHAR) FROM ai_messages WHERE conversation_id=?)");$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();$stmt=$db->prepare('DELETE FROM ai_conversations WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();}
    $stmt=$db->prepare("DELETE FROM ai_response_cache WHERE course_id=? AND (answer='Controlled explanation.' OR answer LIKE '1. Practice concept.%')");$stmt->bind_param('s',$course);$stmt->execute();$stmt->close();
    $stmt=$db->prepare("DELETE FROM ai_audit_logs WHERE metadata LIKE ?");$meta='%'.$tag.'%';$stmt->bind_param('s',$meta);$stmt->execute();$stmt->close();
    if($original){$stmt=$db->prepare('UPDATE ai_course_settings SET is_enabled=?,default_ai_mode=?,allow_external_knowledge=?,max_prompt_tokens=?,max_response_tokens=?,per_user_daily_tokens=?,department_monthly_tokens=?,updated_by=? WHERE course_id=?');$stmt->bind_param('isiiiiiss',$original['is_enabled'],$original['default_ai_mode'],$original['allow_external_knowledge'],$original['max_prompt_tokens'],$original['max_response_tokens'],$original['per_user_daily_tokens'],$original['department_monthly_tokens'],$original['updated_by'],$course);$stmt->execute();$stmt->close();}else{$stmt=$db->prepare('DELETE FROM ai_course_settings WHERE course_id=?');$stmt->bind_param('s',$course);$stmt->execute();$stmt->close();}
}
echo "Assessment modes, limits and summarisation audit complete.\n";
