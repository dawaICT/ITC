<?php
// Verify lecturer viewCourse upload flow as WUC900 over HTTP after fixes.
$BASE = 'http://localhost/wucportal';
$jar = tempnam(sys_get_temp_dir(), 'lec_');
$pass = 0; $fail = 0;
function ok($cond, $label){ global $pass,$fail; if($cond){$pass++; echo "  [OK]   $label\n";} else {$fail++; echo "  [FAIL] $label\n";} }

function req($url, $post = null, $jar = '', $multipart = false) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr((string)$raw, 0, $hs);
    $loc = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';
    return ['code' => $code, 'location' => $loc, 'body' => substr((string)$raw, $hs)];
}
$csrf = fn($h) => preg_match('/name="csrf_token" value="([^"]+)"/', $h, $m) ? $m[1] : '';
function alertText($body, $cls){
    if (($p = stripos($body, $cls)) === false) return '';
    return trim(preg_replace('/\s+/', ' ', strip_tags(substr($body, $p, 300))));
}

// 1. Login
$p = req("$BASE/staff_login.php", null, $jar);
$login = req("$BASE/staffLogin.php", ['csrf_token' => $csrf($p['body']), 'user_id' => 'WUC900', 'password' => 'Test@12345'], $jar);
ok(in_array($login['code'], [302,303]), "login redirects ({$login['code']})");

$page = req("$BASE/lecturers/viewCourse.php?code=COM101", null, $jar);
ok($page['code'] === 200, "GET viewCourse 200");
$phpIssue = false;
foreach (['Fatal error','Parse error','Warning:','Notice:','Deprecated:','headers already sent'] as $n) {
    if (stripos($page['body'], $n) !== false) { $phpIssue = true; echo "    [PHP] $n\n"; }
}
ok(!$phpIssue, "no PHP errors/warnings on page");
ok(preg_match('/courseOutlineModal.*?name="csrf_token"\s+value="[0-9a-f]{8,}"/s', $page['body']) === 1, "modal form now carries a CSRF token");
$pageCsrf = $csrf($page['body']);

// helper to build a temp file of given content/ext
$mkfile = function($bytes, $name){ $f = tempnam(sys_get_temp_dir(),'up_'); $real = $f.'_'.$name; rename($f,$real); file_put_contents($real,$bytes); return $real; };

// 3a. Upload WITHOUT csrf -> must be rejected (403 / Invalid Request)
$pdf = $mkfile("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 'a.pdf');
$noCsrf = req("$BASE/lecturers/viewCourse.php?code=COM101", [
    'submit'=>'1','course_code'=>'COM101','course_contents'=>new CURLFile($pdf,'application/pdf','a.pdf')
], $jar, true);
ok($noCsrf['code'] === 403 || stripos($noCsrf['body'],'could not be verified') !== false, "upload without CSRF token is rejected");

// 3b. Valid upload WITH csrf -> PRG redirect, then success flash
$ok1 = req("$BASE/lecturers/viewCourse.php?code=COM101", [
    'submit'=>'1','course_code'=>'COM101','csrf_token'=>$pageCsrf,
    'course_contents'=>new CURLFile($pdf,'application/pdf','outline_ok.pdf')
], $jar, true);
ok(in_array($ok1['code'],[302,303]) && stripos($ok1['location'],'viewCourse.php?code=COM101') !== false, "valid upload redirects (PRG) -> {$ok1['location']}");
$after = req("$BASE/lecturers/viewCourse.php?code=COM101", null, $jar);
ok(stripos(alertText($after['body'],'alert-success'),'uploaded successfully') !== false, "success flash shown after redirect");

// 3c. No file selected -> friendly error (was previously silent)
$noFile = req("$BASE/lecturers/viewCourse.php?code=COM101", ['submit'=>'1','course_code'=>'COM101','csrf_token'=>$csrf($after['body'])], $jar);
ok(stripos(alertText($noFile['body'],'alert-danger'),'select a file') !== false, "no-file submit shows a clear error (not silent)");

// 3d. Wrong extension -> rejected with message
$page2 = req("$BASE/lecturers/viewCourse.php?code=COM101", null, $jar);
$exe = $mkfile("MZ\x90\x00bad", 'evil.exe');
$badExt = req("$BASE/lecturers/viewCourse.php?code=COM101", [
    'submit'=>'1','course_code'=>'COM101','csrf_token'=>$csrf($page2['body']),
    'course_contents'=>new CURLFile($exe,'application/octet-stream','evil.exe')
], $jar, true);
ok(stripos(alertText($badExt['body'],'alert-danger'),'Invalid file format') !== false, "disallowed extension rejected with message");

// 3e. MIME mismatch (.pdf name but PNG content) -> rejected
$page3 = req("$BASE/lecturers/viewCourse.php?code=COM101", null, $jar);
$fakePdf = $mkfile("\x89PNG\r\n\x1a\n".str_repeat("\0",40), 'fake.pdf');
$mismatch = req("$BASE/lecturers/viewCourse.php?code=COM101", [
    'submit'=>'1','course_code'=>'COM101','csrf_token'=>$csrf($page3['body']),
    'course_contents'=>new CURLFile($fakePdf,'application/pdf','fake.pdf')
], $jar, true);
ok(stripos(alertText($mismatch['body'],'alert-danger'),'does not match its extension') !== false, "MIME/extension mismatch rejected");

@unlink($pdf); @unlink($exe); @unlink($fakePdf); @unlink($jar);
echo "\nResult: $pass passed, $fail failed\n";
