<?php
$mode=$argv[1]??'static';$sid=$argv[2]??'';$asset=$argv[3]??'';$download=($argv[4]??'')==='download';
if($mode==='static'){
    require_once __DIR__.'/../includes/CertificateTemplateManager.php';
    $url=certificateLayoutAssetUrl('uploads/certificate_layout_assets/example-logo.png',true);
    $checks=[
        'protected asset endpoint exists'=>is_file(__DIR__.'/../certificate-layout-asset.php'),
        'layout URLs use protected endpoint'=>str_contains($url,'certificate-layout-asset.php?asset=example-logo.png'),
        'download URL is explicit'=>str_contains($url,'download=1'),
        'editor does not expose a download control'=>!str_contains(file_get_contents(__DIR__.'/../admin/certificate-layout-editor.php'),'fa-download'),
    ];$failed=0;foreach($checks as$name=>$ok){echo($ok?'PASS':'FAIL').": $name\n";if(!$ok)$failed++;}exit($failed?1:0);
}
if($mode==='serve'&&$sid!==''&&$asset!==''){
    ini_set('session.save_path',sys_get_temp_dir());$_COOKIE['TUGONSESSID']=$sid;$_SERVER['HTTP_USER_AGENT']='TUGON-Codex-Bugfix-Test';$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['PHP_SELF']='/ParishSystem/certificate-layout-asset.php';$_SERVER['REQUEST_METHOD']='GET';$_GET=['asset'=>$asset];if($download)$_GET['download']='1';
    ob_start(function($bytes){$valid=str_starts_with($bytes,"\x89PNG\r\n\x1a\n")||str_starts_with($bytes,"\xff\xd8\xff");return$valid?'ASSET_OK':'ASSET_FAILED';});chdir(__DIR__.'/..');include 'certificate-layout-asset.php';exit;
}
exit(2);
