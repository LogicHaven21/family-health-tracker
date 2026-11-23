<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$c=require __DIR__.'/config.php';
$db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(__DIR__.'/schema.sql'));
foreach (['admin'=>'admin','doctor'=>'viewer'] as $name=>$role) {
 $password=bin2hex(random_bytes(12));
 $s=$db->prepare('INSERT INTO users(username,password_hash,role) VALUES(?,?,?)');
 $s->execute([$name,password_hash($password,PASSWORD_DEFAULT),$role]);
 echo "$name : $password\n";
}
echo "Installation finished. Keep passwords in a password manager.\n";
