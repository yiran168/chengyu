<?php
declare(strict_types=1);
if (version_compare(PHP_VERSION,'7.4.0','<') || PHP_INT_SIZE<8) {http_response_code(503);exit('64-bit PHP 7.4 or newer required. All supported versions use the same feature set. Live payments require HTTPS.');}
if (!defined('CY_BOOT')) {define('CY_BOOT',true);}ini_set('display_errors','0');error_reporting(E_ALL);date_default_timezone_set('Asia/Shanghai');
require_once dirname(__DIR__).'/app/autoload.php';
use Chengyu\Core\{Input,Problem,Security,Database};
Security::headers();
$root=dirname(__DIR__);$base=\Chengyu\Core\WebPath::directory((string)($_SERVER['SCRIPT_NAME']??'/install/index.php'),2);
header('Content-Type: text/html; charset=UTF-8');
$detectedHttps=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');Security::session($base.'/',$detectedHttps);
if (is_file($root.'/app/config.php')) {header('Location: '.$base.'/admin/',true,303);exit;}
$drivers=Database::drivers();$checks=[['64-bit PHP 7.4+',version_compare(PHP_VERSION,'7.4.0','>=')&&PHP_INT_SIZE>=8],['PDO MySQL or SQLite',(bool)array_intersect($drivers,['mysql','sqlite'])],['OpenSSL',extension_loaded('openssl')],['Fileinfo',extension_loaded('fileinfo')],['Writable app directory',is_writable($root.'/app')],['Writable storage directory',is_writable($root.'/storage')]];
$expected=require __DIR__.'/key.php';$ownerReady=is_string($expected)&&preg_match('/^[a-f0-9]{64}$/D',$expected)===1;
$checks[]=['Site-specific installation verifier',$ownerReady];
$ready=!in_array(false,array_column($checks,1),true);$error='';$success=false;$lock=null;$wroteConfig=false;
$host=(string)($_SERVER['HTTP_HOST']??'');$guess=($detectedHttps?'https://':'http://').$host.$base;if (!filter_var($guess,FILTER_VALIDATE_URL)) {$guess='';}
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    try {
        Security::verify($_POST['csrf']??null);
        if (!$ready) {throw new Problem('Your environment is missing required capabilities.');}
        $provided=Input::required($_POST['install_key']??'',100);
        if (!hash_equals($expected,hash('sha256',$provided))) {usleep(300000);throw new Problem('The private installation key is incorrect.');}
        $mutex=$root.'/storage/install.mutex.php';$lock=fopen($mutex,'c+');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {throw new Problem('Another installation is in progress.');}
        if (filesize($mutex)===0) {fwrite($lock,"<?php http_response_code(404); exit; __halt_compiler();\n");fflush($lock);}
        if (is_file($root.'/app/config.php')) {throw new Problem('This site is already installed.');}
        $url=rtrim(Input::url($_POST['site_url']??'',true),'/');
        if (parse_url($url,PHP_URL_QUERY)||parse_url($url,PHP_URL_FRAGMENT)||rtrim((string)(parse_url($url,PHP_URL_PATH)??''),'/')!==$base) {throw new Problem('Site URL must match this installation directory and contain no query or fragment.');}
        $username=strtolower(Input::required($_POST['username']??'',32));if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/D',$username)) {throw new Problem('Username must be 3-32 letters, digits or underscores.');}
        $email=strtolower(Input::required($_POST['email']??'',191));if (!filter_var($email,FILTER_VALIDATE_EMAIL)) {throw new Problem('Invalid email address.');}
        $password=Input::password($_POST['password']??'');$name=Input::required($_POST['site_name']??'',100);
        $driver=Input::choice($_POST['driver']??'',array_values(array_intersect($drivers,['mysql','sqlite'])));
        if ($driver==='mysql') {
            $dbConfig=['driver'=>'mysql','host'=>Input::required($_POST['db_host']??'localhost',255),'port'=>Input::integer($_POST['db_port']??3306,1,65535),'name'=>Input::required($_POST['db_name']??'',100),'user'=>Input::required($_POST['db_user']??'',100),'password'=>Input::text($_POST['db_password']??'',500)];
        } else {
            $path=Input::required($_POST['sqlite_path']??'',1000);$parent=realpath(dirname($path));$webRoot=realpath($root);
            $normalized=str_replace('\\','/',(string)$parent).'/';$public=str_replace('\\','/',(string)$webRoot).'/';
            if (!$parent || !is_writable($parent) || strpos(strtolower($normalized),strtolower($public))===0 || !preg_match('/^[A-Za-z0-9_.-]+$/D',basename($path)) || basename($path)==='.' || basename($path)==='..') {throw new Problem('SQLite must be in an existing writable directory outside the public site folder.');}
            $dbConfig=['driver'=>'sqlite','path'=>$parent.DIRECTORY_SEPARATOR.basename($path)];
        }
        $config=['url'=>$url,'secret'=>bin2hex(random_bytes(32)),'database'=>$dbConfig,'storage'=>$root.'/storage','schema_version'=>1];
        $db=new Database($dbConfig);require_once $root.'/app/schema.php';\Chengyu\Schema::create($db);
        if ((int)$db->value('SELECT COUNT(*) FROM cy_users')>0) {throw new Problem('The selected database already contains Chengyu accounts. Use another empty database or restore its original config.php.');}
        $app=new \Chengyu\App($config);$GLOBALS['chengyu']=$app;
        $app->db->transaction(function()use($app,$username,$email,$password,$name,$root,$config,&$wroteConfig):void{
            $id=$app->db->insert('cy_users',['username'=>$username,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'display_name'=>tr('Island editor'),'role'=>'admin','status'=>'active','bio'=>tr('Caring for the little things worth sharing.'),'created_at'=>time(),'verified'=>1]);
            \Chengyu\Seed::run($app,$id,!empty($_POST['examples']),$name);
            $app->activity->audit($id,'installation.complete','schema:1');
            $text="<?php\ndeclare(strict_types=1);\nif (!defined('CY_BOOT')) { http_response_code(404); exit; }\nreturn ".var_export($config,true).";\n";
            $temp=$root.'/app/config-'.bin2hex(random_bytes(8)).'.php';
            if (file_put_contents($temp,$text,LOCK_EX)!==strlen($text)) {throw new Problem('Unable to write application configuration.');}
            chmod($temp,0600);
            if (!rename($temp,$root.'/app/config.php')) {@unlink($temp);throw new Problem('Unable to activate application configuration.');}
            $wroteConfig=true;
        });
        $success=true;$_SESSION['csrf']=bin2hex(random_bytes(32));
    } catch (\Throwable $e) {
        if ($wroteConfig) {@unlink($root.'/app/config.php');}
        $error=$e instanceof Problem?tr($e->getMessage()):tr('Installation failed. Check database credentials, InnoDB support and directory permissions. No payment credentials are needed for installation.');
        if (!($e instanceof Problem)) {error_log('Chengyu installation: '.get_class($e).' '.$e->getMessage());}
    } finally {if (is_resource($lock)) {flock($lock,LOCK_UN);fclose($lock);}}
}
?><!doctype html><html lang="zh-CN" data-theme="tide" data-mode="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= t('Welcome to Chengyu') ?></title><link rel="icon" href="<?= e($base.'/assets/favicon.svg') ?>"><link rel="stylesheet" href="<?= e($base.'/assets/app.css') ?>"></head><body><main class="container install-wrap"><a class="brand" href="<?= e($base.'/') ?>"><img src="<?= e($base.'/assets/art/chengyu-mascot.webp') ?>" width="48" height="48" alt=""><span class="brand-name"><?= t('Chengyu') ?></span></a><section class="page-hero"><div><span class="eyebrow">A NEW PLACE FOR GOOD IDEAS</span><h1><?= t($success?'Your island is ready.':'Make a little space of your own.') ?></h1><p><?= t('No WordPress. No build step. Just your own independent website.') ?></p></div></section>
<?php if ($success): ?><section class="panel"><div class="alert success"><?= t('Installation completed. Sign in with the administrator account you just created.') ?></div><p><?= t('Delete the install directory after confirming the site works. Keep the application secret and database backups private.') ?></p><div class="row wrap"><a class="btn" href="<?= e($base.'/admin/') ?>"><?= t('Open dashboard') ?><?= icon('arrow') ?></a><a class="btn secondary" href="<?= e($base.'/') ?>"><?= t('Visit your island') ?></a></div><p class="hint"><?= t('Payment providers and SMTP remain disabled until configured and tested. Replace sample content and legal notices before public launch.') ?></p></section>
<?php else: ?><?php if (!$ownerReady): ?><p class="alert warning">请先在电脑上打开完整包的 DEPLOY_PREPARE.html，生成本站独立口令，并上传生成的 key.php 覆盖 install/key.php。公开包没有通用安装口令。</p><?php endif ?><?php if ($error): ?><div class="alert danger" role="alert"><?= e($error) ?></div><?php endif ?><section class="panel section"><h2><?= t('Environment check') ?></h2><div class="health-grid"><?php foreach($checks as [$label,$ok]): ?><div class="health-card"><span class="pill <?= $ok?'success':'warning' ?>"><?= t($ok?'Ready':'Needs attention') ?></span><strong><?= t($label) ?></strong></div><?php endforeach ?></div><p class="hint">PHP <?= e(PHP_VERSION) ?> / PDO <?= e(implode(', ',$drivers)) ?>. <?= t('PHP 7.4-8.5 use the same features. HTTPS is required for online top-ups. Prefer a security-supported PHP release.') ?></p></section><form method="post" class="panel"><?= csrf() ?><h2><?= t('Private installation') ?></h2><?php field('install_key','Installation key from the private INSTALL_KEY.txt','','password',['required'=>'required','autocomplete'=>'off']); ?><p class="hint"><?= t('This key prevents someone else from claiming an uninstalled site. Do not upload INSTALL_KEY.txt to your public web directory.') ?></p><div class="fields-grid"><?php field('site_name','Site name',tr('Chengyu'),'text',['required'=>'required']);field('site_url','Canonical site URL (include https:// when configured)',$guess,'url',['required'=>'required']); ?></div><h2><?= t('Database connection') ?></h2><?php select_field('driver','Database engine',array_intersect_key(['mysql'=>'MySQL (recommended for shared hosting)','sqlite'=>'SQLite (requires an external private directory)'],array_flip($drivers)),$drivers[0]??'mysql'); ?><div class="fields-grid"><?php field('db_host','MySQL host','localhost');field('db_port','MySQL port',3306,'number',['min'=>'1','max'=>'65535']);field('db_name','MySQL database name');field('db_user','MySQL username');field('db_password','MySQL password','','password',['autocomplete'=>'new-password']);field('sqlite_path','SQLite absolute file path (SQLite only)',dirname($root).'/private/chengyu.sqlite'); ?></div><p class="hint"><?= t('Create the MySQL database in your host panel first. This installer creates tables, not the database or database user. SQLite must never be stored inside the public site folder.') ?></p><h2><?= t('Your administrator account') ?></h2><div class="fields-grid"><?php field('username','Administrator username','','text',['required'=>'required','pattern'=>'[a-z][a-z0-9_]{2,31}','autocomplete'=>'username']);field('email','Administrator email','','email',['required'=>'required','autocomplete'=>'email']);field('password','Password (12+ characters, letters and digits)','','password',['required'=>'required','minlength'=>'12','maxlength'=>'72','autocomplete'=>'new-password']); ?></div><?php check_field('examples','Install original sample content and sample membership plans',true); ?><div class="alert info"><?= t('Sample plans and resource prices are demonstrations, not a business recommendation. Review every product, permission, price and policy before accepting payments.') ?></div><?php if ($ready) {button('Create my site','full','sparkles');} else {echo '<div class="alert warning">'.t('Ask your host to enable the missing capabilities before proceeding.').'</div>';} ?></form><?php endif ?></main></body></html>
