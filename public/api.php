<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('Asia/Tehran');
function reply(array $v,int $code=200): never { http_response_code($code); echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit; }
function fail(string $v,int $code=422): never { reply(['error'=>$v],$code); }
function unicode_length(string $v): int { $length=preg_match_all('/./us',$v); if($length===false)fail('متن ورودی نامعتبر است.'); return $length; }
function strval_limit(mixed $v,int $n): string { if(!is_string($v)||unicode_length($v)>$n) fail('متن ورودی نامعتبر یا طولانی است.'); return trim($v); }
function number(mixed $v,float $lo,float $hi,bool $integer=false): float|int { if(!is_numeric($v)||!is_finite((float)$v)||$v<$lo||$v>$hi||($integer&&(float)$v!=floor((float)$v))) fail('عدد واردشده خارج از محدودهٔ ثبت است.'); return $integer?(int)$v:(float)$v; }
function oneof(mixed $v,array $allowed): string { if(!in_array($v,$allowed,true)) fail('گزینهٔ نامعتبر.');return $v; }
function stamp(mixed $v): string { if(!is_string($v)) fail('تاریخ نامعتبر.'); $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$v,new DateTimeZone('Asia/Tehran')); if(!$d||$d->format('Y-m-d\TH:i')!==$v||$d>new DateTimeImmutable('+5 minutes',new DateTimeZone('Asia/Tehran'))||$d->format('Y-m-d')<'2023-01-01') fail('تاریخ و ساعت نامعتبر یا مربوط به آینده است.');return $d->format('Y-m-d H:i:s'); }
function uuid(mixed $v): string { if(!is_string($v)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$v)) fail('شناسهٔ ثبت نامعتبر.'); return $v; }
try {
 $c=require __DIR__.'/../private/config.php';
 if($c['https_only']&&($_SERVER['HTTPS']??'')!=='on') fail('اتصال HTTPS لازم است.',403);
 session_name('family_health'); session_set_cookie_params(['httponly'=>true,'secure'=>(bool)$c['https_only'],'samesite'=>'Strict','path'=>'/']);
 ini_set('session.use_strict_mode','1');session_start();
 $db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $db->exec("SET time_zone = '+00:00'");
 $q=function(string $sql,array $args=[])use($db):PDOStatement{$s=$db->prepare($sql);$s->execute($args);return $s;};
 $audit=function(string $action,?int $id=null)use($q){$q('INSERT INTO audit(user_id,action,entity_id) VALUES(?,?,?)',[(int)$_SESSION['uid'],$action,$id]);};
 $method=$_SERVER['REQUEST_METHOD'];$a=$_GET['action']??'state';$in=[];
 if($method==='POST'){if((int)($_SERVER['CONTENT_LENGTH']??0)>24000)fail('درخواست بزرگ است.',413);$raw=file_get_contents('php://input',false,null,0,24001);if(strlen($raw)>24000)fail('درخواست بزرگ است.',413);$in=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($in))fail('درخواست نامعتبر.');}
 if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
 if($method==='POST'&&!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('نشست تغییر کرده؛ صفحه را تازه کنید.',403);
 if($a==='login'&&$method==='POST'){
  $name=strval_limit($in['username']??'',64);$password=strval_limit($in['password']??'',200);$now=time();
  $buckets=[hash('sha256','ip:'.($_SERVER['REMOTE_ADDR']??'')),hash('sha256','name:'.strtolower($name))];
  foreach($buckets as $b){$q('INSERT INTO login_limits(bucket,attempts,started) VALUES(?,0,?) ON DUPLICATE KEY UPDATE attempts=IF(started < ?,0,attempts), started=IF(started < ?,VALUES(started),started)',[$b,$now,$now-900,$now-900]);$r=$q('SELECT attempts FROM login_limits WHERE bucket=?',[$b])->fetch();if((int)$r['attempts']>=10)fail('تلاش زیاد؛ ۱۵ دقیقه بعد دوباره امتحان کنید.',429);$q('UPDATE login_limits SET attempts=attempts+1 WHERE bucket=?',[$b]);}
  $u=$q('SELECT * FROM users WHERE username=?',[$name])->fetch();
  if(!$u||!password_verify($password,$u['password_hash']))fail('نام کاربری یا رمز نادرست است.',401);
  session_regenerate_id(true);$_SESSION=['uid'=>(int)$u['id'],'version'=>(int)$u['session_version'],'csrf'=>bin2hex(random_bytes(32)),'last'=>time()];
  foreach($buckets as $b)$q('DELETE FROM login_limits WHERE bucket=?',[$b]);$audit('login');reply(['ok'=>true]);
 }
 $u=null;
 if(isset($_SESSION['uid'])){$u=$q('SELECT id,username,role,session_version FROM users WHERE id=?',[$_SESSION['uid']])->fetch();if(!$u||(int)$u['session_version']!==($_SESSION['version']??0)||time()-($_SESSION['last']??0)>7200){$_SESSION=['csrf'=>bin2hex(random_bytes(32))];$u=null;}else $_SESSION['last']=time();}
 if($a==='state'){reply(['user'=>$u?['username'=>$u['username'],'role'=>$u['role']]:null,'csrf'=>$_SESSION['csrf']]);}
 if(!$u)fail('دوباره وارد شوید.',401);
 if($a==='data'&&$method==='GET'){reply(['patients'=>$q('SELECT * FROM patients ORDER BY id')->fetchAll(),'readings'=>$q('SELECT * FROM readings ORDER BY measured_at,id')->fetchAll()]);}
 if($method!=='POST')fail('مسیر نامعتبر.',404);
 if($a==='logout'){$_SESSION=[];session_destroy();reply(['ok'=>true]);}
 if($a==='password'){
  $old=strval_limit($in['old']??'',200);$new=strval_limit($in['new']??'',200);
  $hash=$q('SELECT password_hash FROM users WHERE id=?',[$u['id']])->fetchColumn();
  if(!password_verify($old,$hash)||unicode_length($new)<12)fail('رمز فعلی نادرست است یا رمز جدید کمتر از ۱۲ کاراکتر دارد.');
  $q('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$u['id']]);$_SESSION['version']++;session_regenerate_id(true);$audit('password');reply(['ok'=>true]);
 }
 if($u['role']!=='admin')fail('دسترسی پزشک فقط برای مشاهده است.',403);
 if($a==='doctor_reset'){
  $password=bin2hex(random_bytes(12));$db->beginTransaction();$q("UPDATE users SET password_hash=?,session_version=session_version+1 WHERE username='doctor' AND role='viewer'",[password_hash($password,PASSWORD_DEFAULT)]);$audit('doctor_reset');$db->commit();reply(['ok'=>true,'username'=>'doctor','password'=>$password]);
 }
 if($a==='patient'){
  $name=strval_limit($in['name']??'',100);if($name==='')fail('نام لازم است.');$sex=oneof($in['sex']??'', ['male','female','other']);
  $birth=strval_limit($in['birth_date']??'',10);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$birth);if(!$d||$d->format('Y-m-d')!==$birth||$birth>date('Y-m-d')||$birth<'1900-01-01')fail('تاریخ تولد میلادی نامعتبر.');
  $notes=strval_limit($in['notes']??'',2000);$id=(int)($in['id']??0);
  $db->beginTransaction();if($id){if(!$q('SELECT id FROM patients WHERE id=?',[$id])->fetch())fail('پروفایل پیدا نشد.');$q('UPDATE patients SET name=?,sex=?,birth_date=?,notes=? WHERE id=?',[$name,$sex,$birth,$notes,$id]);}else{$q('INSERT INTO patients(name,sex,birth_date,notes) VALUES(?,?,?,?)',[$name,$sex,$birth,$notes]);$id=(int)$db->lastInsertId();}$audit('patient',$id);$db->commit();reply(['ok'=>true]);
 }
 if($a==='void'){
  $id=(int)($in['id']??0);$reason=strval_limit($in['reason']??'',500);if($reason==='')fail('علت باطل‌کردن را بنویسید.');$db->beginTransaction();$s=$q('UPDATE readings SET voided_at=UTC_TIMESTAMP(),void_reason=? WHERE id=? AND voided_at IS NULL',[$reason,$id]);if(!$s->rowCount())fail('رکورد پیدا نشد یا قبلاً باطل شده.');$audit('void',$id);$db->commit();reply(['ok'=>true]);
 }
 if($a==='reading'){
  $id=uuid($in['uuid']??'');$patient=(int)($in['patient_id']??0);$kind=oneof($in['kind']??'',['bp','glucose']);$when=stamp($in['measured_at']??'');$slot=oneof($in['slot']??'',['morning','evening','other']);$notes=strval_limit($in['notes']??'',2000);
  $sys=$dia=$pulse=$glucose=$context=$fast=$meal=$group=null;
  if($kind==='bp'){$sys=number($in['systolic']??null,40,300,true);$dia=number($in['diastolic']??null,20,200,true);$pulse=number($in['pulse']??null,20,250,true);if($sys<=$dia)fail('فشار بالا باید از فشار پایین بیشتر باشد.');$group=uuid($in['group_uuid']??'');}
  else{$glucose=number($in['glucose']??null,10,1000);$context=oneof($in['context']??'',['fasting','before','after','random','bedtime']);if($context==='fasting')$fast=number($in['fasting_hours']??null,0,72);if($context==='after'){$meal=stamp($in['meal_at']??'');if($meal>$when)fail('زمان غذا باید قبل از تست باشد.');}}
  $db->beginTransaction();
  if(!$q('SELECT id FROM patients WHERE id=? FOR UPDATE',[$patient])->fetch())fail('پروفایل پیدا نشد.');
  $existing=$q('SELECT * FROM readings WHERE uuid=?',[$id])->fetch();
  $fields=['patient_id'=>$patient,'kind'=>$kind,'measured_at'=>$when,'slot'=>$slot,'group_uuid'=>$group,'systolic'=>$sys,'diastolic'=>$dia,'pulse'=>$pulse,'glucose'=>$glucose,'context'=>$context,'fasting_hours'=>$fast,'meal_at'=>$meal,'notes'=>$notes];
  if($existing){
   foreach($fields as $key=>$val){
    $numeric=in_array($key,['patient_id','systolic','diastolic','pulse','glucose','fasting_hours'],true);
    $same=($val===null)?$existing[$key]===null:($numeric?(float)$existing[$key]===(float)$val:(string)$existing[$key]===(string)$val);
    if(!$same)fail('این شناسه قبلاً با دادهٔ دیگری ثبت شده؛ فرم جدید باز کنید.',409);
   }
   $db->commit();reply(['ok'=>true,'duplicate'=>true]);
  }
  if($group){$rows=$q('SELECT * FROM readings WHERE group_uuid=? AND voided_at IS NULL ORDER BY measured_at',[$group])->fetchAll();if(count($rows)>=2)fail('هر نوبت حداکثر دو قرائت دارد.');foreach($rows as $r){$gap=strtotime($when)-strtotime($r['measured_at']);if((int)$r['patient_id']!==$patient||$r['slot']!==$slot||substr($r['measured_at'],0,10)!==substr($when,0,10)||$gap<0||$gap>1800)fail('قرائت دوم باید برای همان فرد، روز و نوبت و حداکثر ۳۰ دقیقه بعد باشد.');}}
  $q('INSERT INTO readings(uuid,patient_id,kind,measured_at,slot,group_uuid,systolic,diastolic,pulse,glucose,context,fasting_hours,meal_at,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$patient,$kind,$when,$slot,$group,$sys,$dia,$pulse,$glucose,$context,$fast,$meal,$notes,$u['id']]);$rid=(int)$db->lastInsertId();$audit('reading',$rid);$db->commit();reply(['ok'=>true,'id'=>$rid]);
 }
 fail('مسیر نامعتبر.',404);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('FamilyHealth: '.$e->getMessage());reply(['error'=>'خطای سرور؛ اطلاعات ذخیره‌شده را بررسی کنید و دوباره تلاش کنید.'],500);}
