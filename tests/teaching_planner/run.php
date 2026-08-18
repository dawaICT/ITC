<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/teaching_planner/init.php';

$passes = 0; $failures = [];
function tp_test(string $name, callable $test): void { global $passes, $failures; try { $test(); echo "PASS  {$name}\n"; $passes++; } catch (Throwable $e) { echo "FAIL  {$name}: {$e->getMessage()} ({$e->getFile()}:{$e->getLine()})\n"; $failures[] = $name . ': ' . $e->getMessage(); } }
function tp_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function tp_assert_throws(callable $fn, string $contains): void { try { $fn(); } catch (Throwable $e) { tp_assert(str_contains($e->getMessage(), $contains), 'Unexpected error: ' . $e->getMessage()); return; } throw new RuntimeException('Expected exception containing: ' . $contains); }
function tp_fixture_docx(string $path, string $type = 'scheme_of_work', bool $malicious = false): void {
    $zip = new ZipArchive(); tp_assert($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Could not create DOCX fixture.');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    if ($type === 'scheme_of_work') {
        $body = '<w:p><w:r><w:t>{{programme_name}} | {{course_code}} | {{course_name}} | {{lecturer_name}} | {{academic_year}} | {{academic_period}} | {{document_number}} | {{approval_status}}</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>{{week_number}}</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>{{session_date}}</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>{{topic}}</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>{{subtopics}}</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>{{learning_outcomes}}</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>{{duration}}</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';
    } else {
        $body = '<w:p><w:r><w:t>{{course_code}} | {{course_name}} | {{lecturer_name}} | {{session_date}} | {{topic}} | {{learning_outcomes}} | {{duration}}</w:t></w:r></w:p>';
    }
    if ($malicious) $body .= '<w:p><w:r><w:t>{{unknown_payload}}</w:t></w:r></w:p>';
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '<w:sectPr/></w:body></w:document>');
    $zip->close();
}

$scheduler = new TeachingPlannerScheduler();
$topics = [
    ['id'=>1,'topic_title'=>'Topic A','recommended_hours'=>4,'learning_outcomes'=>'Outcome A','display_order'=>1],
    ['id'=>2,'topic_title'=>'Topic B','recommended_hours'=>2,'learning_outcomes'=>'Outcome B','display_order'=>2,'prerequisite_topic_id'=>1],
];
$mondayWednesday = [['day_of_week'=>'Monday','start_time'=>'08:00','end_time'=>'10:00'],['day_of_week'=>'Wednesday','start_time'=>'08:00','end_time'=>'10:00']];

tp_test('three-term style schedule allocates approved topics in order', function () use ($scheduler,$topics,$mondayWednesday): void {
    $r=$scheduler->generate(['start_date'=>'2026-01-05','end_date'=>'2026-01-23','timetable'=>$mondayWednesday,'topics'=>$topics]);
    tp_assert($r['coverage_percent']===100.0, 'Expected full syllabus coverage.'); tp_assert(str_starts_with($r['items'][0]['topic'],'Topic A'),'Topic order changed.');
});
tp_test('semester programme date range remains deterministic', function () use ($scheduler,$topics): void {
    $r=$scheduler->generate(['start_date'=>'2026-02-02','end_date'=>'2026-02-28','timetable'=>[['day_of_week'=>'Tuesday','start_time'=>'09:00','end_time'=>'12:00']],'topics'=>$topics]);
    tp_assert(count($r['items'])===4,'Expected four semester sessions.');
});
tp_test('short course uses actual custom dates', function () use ($scheduler): void {
    $r=$scheduler->generate(['start_date'=>'2026-07-06','end_date'=>'2026-07-10','timetable'=>[['day_of_week'=>'Monday','start_time'=>'08:00','end_time'=>'12:00'],['day_of_week'=>'Friday','start_time'=>'08:00','end_time'=>'12:00']],'topics'=>[['id'=>1,'topic_title'=>'Safety','recommended_hours'=>8,'learning_outcomes'=>'Work safely','display_order'=>1]]]);
    tp_assert(count($r['items'])===2 && $r['coverage_percent']===100.0,'Short-course scheduling failed.');
});
tp_test('holidays are excluded and duplicate timetable slots are rejected', function () use ($scheduler): void {
    $r=$scheduler->generate(['start_date'=>'2026-01-05','end_date'=>'2026-01-12','timetable'=>[['day_of_week'=>'Monday','start_time'=>'08:00','end_time'=>'10:00']],'events'=>[['event_type'=>'holiday','title'=>'Holiday','starts_at'=>'2026-01-05 00:00:00','ends_at'=>'2026-01-05 23:59:59']],'topics'=>[['id'=>1,'topic_title'=>'A','recommended_hours'=>2,'learning_outcomes'=>'A','display_order'=>1]]]);
    tp_assert(count($r['items'])===1 && $r['items'][0]['session_date']==='2026-01-12','Holiday was not excluded.');
    tp_assert_throws(fn()=> $scheduler->generate(['start_date'=>'2026-01-05','end_date'=>'2026-01-05','timetable'=>[['day_of_week'=>'Monday','start_time'=>'08:00','end_time'=>'10:00'],['day_of_week'=>'Monday','start_time'=>'08:00','end_time'=>'10:00']],'topics'=>[['id'=>1,'topic_title'=>'A','recommended_hours'=>2,'learning_outcomes'=>'A']]]),'duplicate session');
});
tp_test('insufficient hours warning is actionable', function () use ($scheduler): void {
    $r=$scheduler->generate(['start_date'=>'2026-01-05','end_date'=>'2026-01-05','timetable'=>[['day_of_week'=>'Monday','start_time'=>'08:00','end_time'=>'10:00']],'topics'=>[['id'=>1,'topic_title'=>'A','recommended_hours'=>6,'learning_outcomes'=>'A']]]);
    tp_assert($r['coverage_percent']<100 && str_contains($r['warnings'][0],'requires 6.00 hours'),'Expected hours warning.');
});
tp_test('partial regeneration preserves locked rows', function () use ($scheduler): void {
    $r=$scheduler->generate(['start_date'=>'2026-01-05','end_date'=>'2026-01-12','timetable'=>[['day_of_week'=>'Monday','start_time'=>'08:00','end_time'=>'10:00']],'topics'=>[['id'=>1,'topic_title'=>'New','recommended_hours'=>2,'learning_outcomes'=>'New']],'locked_items'=>[['session_date'=>'2026-01-05','start_time'=>'08:00','end_time'=>'10:00','duration_minutes'=>120,'topic'=>'LOCKED','item_type'=>'lesson','week_number'=>1,'subtopics'=>'','learning_outcomes'=>'Old','teaching_methods'=>'','lecturer_activities'=>'','learner_activities'=>'','resources'=>'','assessment_method'=>'','references_text'=>'','remarks'=>'','status'=>'locked','is_locked'=>1]]]);
    tp_assert($r['items'][0]['topic']==='LOCKED' && (int)$r['items'][0]['is_locked']===1,'Locked row was replaced.');
});
tp_test('assessment and revision weeks reserve deterministic sessions', function () use ($scheduler): void {
    $r=$scheduler->generate(['start_date'=>'2026-01-05','end_date'=>'2026-01-19','timetable'=>[['day_of_week'=>'Monday','start_time'=>'08:00','end_time'=>'10:00']],'topics'=>[['id'=>1,'topic_title'=>'A','recommended_hours'=>2,'learning_outcomes'=>'A']],'assessment_weeks'=>[1],'revision_weeks'=>[2]]);
    tp_assert($r['items'][0]['item_type']==='assessment' && $r['items'][1]['item_type']==='revision','Reserved weeks were not honoured.');
});
tp_test('invalid syllabus prerequisite ordering is blocked', function () use ($scheduler): void {
    tp_assert_throws(fn()=> $scheduler->generate(['start_date'=>'2026-01-05','end_date'=>'2026-01-12','timetable'=>[['day_of_week'=>'Monday','start_time'=>'08:00','end_time'=>'10:00']],'topics'=>[['id'=>1,'topic_title'=>'First','recommended_hours'=>2,'learning_outcomes'=>'A','prerequisite_topic_id'=>2],['id'=>2,'topic_title'=>'Second','recommended_hours'=>2,'learning_outcomes'=>'B']]]),'prerequisite ordering');
});
tp_test('lesson stage duration validation requires an exact total', function (): void {
    tp_assert_throws(fn()=> TeachingPlannerLessonPlanValidator::validateStageDurations(120,[['stage_name'=>'Intro','duration_minutes'=>20],['stage_name'=>'Development','duration_minutes'=>80]]),'must total exactly 120');
    tp_assert(TeachingPlannerLessonPlanValidator::validateStageDurations(120,[['stage_name'=>'Intro','duration_minutes'=>20],['stage_name'=>'Development','duration_minutes'=>90],['stage_name'=>'Conclusion','duration_minutes'=>10]])===120,'Valid stage total rejected.');
});

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tp-tests-' . bin2hex(random_bytes(4)); mkdir($tmp,0770,true);
$validDocx=$tmp.'/valid.docx'; $badDocx=$tmp.'/bad.docx';
$polishedFixture = __DIR__ . '/fixtures/sample_scheme_template.docx';
if (is_file($polishedFixture)) copy($polishedFixture, $validDocx); else tp_fixture_docx($validDocx);
tp_fixture_docx($badDocx,'scheme_of_work',true);
$validator = new TeachingPlannerTemplateValidator();
tp_test('template placeholder validation and malicious placeholder rejection', function () use ($validator,$validDocx,$badDocx): void {
    $ok=$validator->validate($validDocx,'scheme_of_work'); $bad=$validator->validate($badDocx,'scheme_of_work');
    tp_assert($ok['valid']===true,'Valid template rejected.'); tp_assert($bad['valid']===false && in_array('unknown_payload',$bad['unknown'],true),'Unknown placeholder not reported.');
});
tp_test('invalid upload signature is rejected', function () use ($validator,$tmp): void { $path=$tmp.'/fake.docx'; file_put_contents($path,'<?php echo 1;'); tp_assert_throws(fn()=> $validator->validate($path,'scheme_of_work'),'not a valid DOCX'); });

tp_test('database generation, workflow, authorization, revision and DOCX export', function () use ($db,$validDocx,$tmp): void {
    $db->begin_transaction(); $stored=null;
    try {
        $fixture=$db->query("SELECT co.id offering_id, co.program_code, co.academic_year_id, co.academic_period_id, co.class_group_id, cc.course_code, s.id staff_numeric, s.staff_id, ay.academic_year_name, ap.period_name, ap.start_date, ap.end_date FROM course_offerings co JOIN curriculum_courses cc ON cc.id=co.curriculum_course_id CROSS JOIN (SELECT id,staff_id FROM staff ORDER BY id LIMIT 1) s JOIN academic_years ay ON ay.id=co.academic_year_id JOIN academic_periods ap ON ap.id=co.academic_period_id WHERE co.status IN ('active','planned') LIMIT 1")->fetch_assoc();
        tp_assert((bool)$fixture,'No canonical offering fixture is available.'); $actor=$fixture['staff_id']; $_SESSION['staff_id']=$actor;
        $st=$db->prepare("INSERT INTO lecturer_course_assignments(staff_id,course_offering_id,status) VALUES (?,?,'active')"); $st->bind_param('si',$actor,$fixture['offering_id']); $st->execute(); $assignmentId=(int)$db->insert_id; $st->close();
        $start=new DateTimeImmutable($fixture['start_date']); $day=$start->format('l');
        $st=$db->prepare("INSERT INTO course_schedule(course_code,lecturer_id,day_of_week,start_time,end_time,academic_year,semester,status,start_date,end_date,is_active) VALUES (?,?,?,'08:00','10:00',2026,1,'active',?,?,1)"); $st->bind_param('sisss',$fixture['course_code'],$fixture['staff_numeric'],$day,$fixture['start_date'],$fixture['end_date']); $st->execute(); $st->close();
        $st=$db->prepare("INSERT INTO document_templates(name,document_type,structure_type,status,created_by) VALUES ('Test Scheme','scheme_of_work','semester','active',?)"); $st->bind_param('s',$actor); $st->execute(); $templateId=(int)$db->insert_id; $st->close();
        $checksum=hash_file('sha256',$validDocx); $relative='templates/tests/'.bin2hex(random_bytes(5)).'.docx'; $stored=tp_storage_root().DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative); if(!is_dir(dirname($stored)))mkdir(dirname($stored),0770,true); copy($validDocx,$stored); $report=tp_json((new TeachingPlannerTemplateValidator())->validate($validDocx,'scheme_of_work'));
        $size=filesize($validDocx); $st=$db->prepare("INSERT INTO document_template_versions(template_id,version_number,effective_date,status,original_filename,storage_path,mime_type,file_size,checksum_sha256,validation_report,uploaded_by,activated_at) VALUES (?,1,CURDATE(),'active','test.docx',?,'application/vnd.openxmlformats-officedocument.wordprocessingml.document',?,?,?, ?,NOW())"); $st->bind_param('isisss',$templateId,$relative,$size,$checksum,$report,$actor); $st->execute(); $templateVersionId=(int)$db->insert_id; $st->close();
        $st=$db->prepare("INSERT INTO syllabus_versions(program_code,course_code,version_label,total_recommended_hours,status,created_by,approved_by,approved_at) VALUES (?,?,'Test Approved',2,'approved',?,?,NOW())"); $st->bind_param('ssss',$fixture['program_code'],$fixture['course_code'],$actor,$actor); $st->execute(); $syllabusId=(int)$db->insert_id; $st->close();
        $st=$db->prepare("INSERT INTO syllabus_topics(syllabus_version_id,topic_title,recommended_hours,learning_outcomes,display_order) VALUES (?,'Approved Test Topic',2,'Apply the approved test outcome.',1)"); $st->bind_param('i',$syllabusId); $st->execute(); $st->close();
        $service=new TeachingPlannerService($db); $preview=$service->generatePreview($actor,['assignment_id'=>$assignmentId,'template_version_id'=>$templateVersionId,'syllabus_version_id'=>$syllabusId,'start_date'=>$fixture['start_date'],'end_date'=>$fixture['end_date']]);
        $planId=$service->savePreview($actor,$preview); $plan=$service->plan($planId); tp_assert(count($plan['items'])>0,'Plan items were not saved.');
        tp_assert($plan['items'][0]['session_date'] !== '0000-00-00' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$plan['items'][0]['session_date']) === 1, 'Persisted plan item date is invalid.');
        $draft=(new TeachingPlannerExporter($db))->exportPlan($planId,$actor,'docx'); $z=new ZipArchive();$z->open($draft['path']);$xml=$z->getFromName('word/document.xml');$z->close();tp_assert(str_contains($xml,'DRAFT')&&!str_contains(strip_tags($xml),'{{'),'Draft watermark or placeholder resolution failed.');
        tp_assert_throws(fn()=> (new TeachingPlannerExporter($db))->exportPlan($planId,'UNAUTHORIZED','docx'),'not authorized');
        $service->transition($planId,$actor,'submit','','lecturer'); tp_assert_throws(fn()=> $service->transition($planId,$actor,'approve','','admin'),'Head of Section');
        $service->transition($planId,$actor,'request_changes','Add a clearer activity.','hos'); $service->transition($planId,$actor,'resubmit','Updated.','lecturer'); $service->transition($planId,$actor,'approve','Approved for use.','hos');
        $approved=(new TeachingPlannerExporter($db))->exportPlan($planId,$actor,'docx'); $z=new ZipArchive();$z->open($approved['path']);$xml=$z->getFromName('word/document.xml');$z->close();tp_assert(!str_contains(strip_tags($xml),'{{'),'Approved export has unresolved placeholders.');
        $artifact=dirname(__DIR__,2).'/artifacts/teaching-planner-sample.docx'; if(!is_dir(dirname($artifact)))mkdir(dirname($artifact),0770,true); copy($approved['path'],$artifact);
        $revisionId=$service->createRevision($planId,$actor); tp_assert($revisionId!==$planId,'Revision was not created.');
        $db->rollback(); if($stored&&is_file($stored))unlink($stored);
    } catch(Throwable $e) { $db->rollback(); if($stored&&is_file($stored))unlink($stored); throw $e; }
});
tp_test('migration includes immutable template version uniqueness and workflow tables', function () use ($db): void {
    foreach (['document_template_versions','teaching_plan_approvals','teaching_plan_exports','teaching_plan_audit_logs'] as $table) { $r=$db->query("SHOW TABLES LIKE '".$db->real_escape_string($table)."'"); tp_assert($r->num_rows===1,"Missing table: $table"); }
    $indexes=$db->query("SHOW INDEX FROM document_template_versions WHERE Key_name='uq_tp_template_version'"); tp_assert($indexes->num_rows>0,'Template version uniqueness index is missing.');
});
tp_test('sample Word export repeats schedule rows and resolves all placeholders', function (): void {
    $path=dirname(__DIR__,2).'/artifacts/teaching-planner-sample.docx'; tp_assert(is_file($path),'Sample export is missing.');
    $z=new ZipArchive(); tp_assert($z->open($path)===true,'Sample export is not a readable DOCX.'); $xml=(string)$z->getFromName('word/document.xml'); $z->close();
    tp_assert(substr_count($xml,'Approved Test Topic')>=1,'Repeated plan row is missing.'); tp_assert(!preg_match('/\{\{[^}]+\}\}/',strip_tags($xml)),'Sample export has unresolved placeholders.');
});

foreach (glob($tmp . '/*') ?: [] as $file) @unlink($file); @rmdir($tmp);
echo "\n{$passes} passed, " . count($failures) . " failed.\n";
if ($failures) exit(1);
