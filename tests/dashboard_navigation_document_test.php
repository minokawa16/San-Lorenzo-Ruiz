<?php
$mode=$argv[1]??'static';$testSession=$argv[2]??'';

if($mode==='static'){
    $checks=[
        'dashboard uses canonical certificate route'=>str_contains(file_get_contents(__DIR__.'/../admin/dashboard.php'),"BASE_URL . 'admin/certificate-generator.php'"),
        'generator uses centralized session'=>!preg_match('/\bsession_start\s*\(/',file_get_contents(__DIR__.'/../admin/certificate-generator.php')),
        'record API uses centralized session'=>!preg_match('/\bsession_start\s*\(/',file_get_contents(__DIR__.'/../api/get_records.php')),
        'document route uses centralized session'=>!preg_match('/\bsession_start\s*\(/',file_get_contents(__DIR__.'/../request-document.php')),
        'certificate POST has CSRF enforcement'=>str_contains(file_get_contents(__DIR__.'/../admin/certificate-generator.php'),'requireValidCsrfToken();'),
    ];$failed=0;foreach($checks as$name=>$ok){echo($ok?'PASS':'FAIL').": $name\n";if(!$ok)$failed++;}exit($failed?1:0);
}

$_SERVER['HTTP_USER_AGENT']='TUGON-Codex-Bugfix-Test';$_SERVER['REMOTE_ADDR']='127.0.0.1';
ini_set('session.save_path',sys_get_temp_dir());
if($testSession!=='')$_COOKIE['TUGONSESSID']=$testSession;

if($mode==='seed'){
    $_SERVER['PHP_SELF']='/ParishSystem/tests/dashboard_navigation_document_test.php';require_once __DIR__.'/../includes/session.php';require_once __DIR__.'/../database/config.php';
    $admin=$conn->query("SELECT id,fullname,email FROM users WHERE email='tugonparish@gmail.com' AND status='active' LIMIT 1")->fetch_assoc();if(!$admin)exit(2);
    $_SESSION['user_id']=(int)$admin['id'];$_SESSION['fullname']=$admin['fullname'];$_SESSION['email']=$admin['email'];$_SESSION['fully_authenticated']=true;$_SESSION['mfa_verified']=true;$_SESSION['session_fingerprint']=hash('sha256','TUGON-Codex-Bugfix-Test');$_SESSION['session_regenerated_at']=time();$_SESSION['_csrf_token']=str_repeat('a',64);$_SESSION['_csrf_token_time']=time();$_SESSION['bugfix_audit_floor']=(int)$conn->query('SELECT COALESCE(MAX(log_id),0) id FROM audit_log')->fetch_assoc()['id'];echo session_id();session_write_close();exit;
}

if($mode==='records'){$_SERVER['PHP_SELF']='/ParishSystem/api/get_records.php';$_SERVER['REQUEST_METHOD']='GET';$_GET=['type'=>'baptism'];chdir(__DIR__.'/../api');include 'get_records.php';exit;}
if($mode==='generator'){$_SERVER['PHP_SELF']='/ParishSystem/admin/certificate-generator.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=['_csrf_token'=>str_repeat('a',64),'cert_type'=>'baptism','record_id'=>'1'];chdir(__DIR__.'/../admin');include 'certificate-generator.php';exit;}
if($mode==='inspect'){
    $_SERVER['PHP_SELF']='/ParishSystem/tests/dashboard_navigation_document_test.php';require_once __DIR__.'/../includes/session.php';$ok=($_SESSION['cert_type']??'')==='baptism'&&(int)($_SESSION['certificate_data']['baptism_id']??0)===1;echo$ok?'GENERATOR_OK':'GENERATOR_FAILED';session_write_close();exit;
}
if($mode==='preview'){
    ob_start(function($html){return str_contains($html,'BAP-2026-000001')&&str_contains($html,'REY MARK C. CAVANAS')?'PREVIEW_OK':'PREVIEW_FAILED';});$_SERVER['PHP_SELF']='/ParishSystem/admin/view-certificate.php';$_SERVER['REQUEST_METHOD']='GET';chdir(__DIR__.'/../admin');include 'view-certificate.php';exit;
}
if($mode==='document'){
    ob_start(function($bytes){return str_starts_with($bytes,"\x89PNG\r\n\x1a\n")?'DOCUMENT_OK':'DOCUMENT_FAILED';});$_SERVER['PHP_SELF']='/ParishSystem/request-document.php';$_SERVER['REQUEST_METHOD']='GET';$_GET=['id'=>6];chdir(__DIR__.'/..');include 'request-document.php';exit;
}
if($mode==='cleanup'){
    $_SERVER['PHP_SELF']='/ParishSystem/tests/dashboard_navigation_document_test.php';require_once __DIR__.'/../includes/session.php';require_once __DIR__.'/../database/config.php';$floor=(int)($_SESSION['bugfix_audit_floor']??PHP_INT_MAX);$conn->query("DELETE FROM audit_log WHERE log_id>$floor AND action='DOWNLOAD_REQUEST_DOCUMENT' AND record_id=6");if(session_status()===PHP_SESSION_ACTIVE){$_SESSION=[];session_destroy();}echo'CLEANUP_OK';exit;
}
exit(2);
