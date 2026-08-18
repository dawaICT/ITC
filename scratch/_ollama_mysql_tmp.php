<?php
require 'C:/xampp/htdocs/wucportal/db/connect.php';
foreach(['knowledge_documents','knowledge_chunks','syllabus_versions','repository_materials','course_materials','materials','elearning_resources','course_resources'] as $t){
  $r=@$db->query("SHOW TABLES LIKE '$t'");
  if($r && $r->num_rows){ $c=$db->query("SELECT COUNT(*) c FROM `$t`")->fetch_assoc()['c']; echo "$t=$c\n"; }
  else echo "$t=MISSING\n";
}
