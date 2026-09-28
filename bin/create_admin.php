<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if ($argc < 4) {
    fwrite(STDERR, "Usage: php bin/create_admin.php "Name" email@example.com "Password"\n");
    exit(1);
}
[$script,$name,$email,$password]=$argv;
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<10) {
    fwrite(STDERR,"Valid email and password of at least 10 characters required.\n"); exit(1);
}
$hash=password_hash($password,PASSWORD_DEFAULT);
$s=$db->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,'owner','active')");
try { $s->execute([$name,$email,$hash]); echo "Owner created: {$email}\n"; }
catch(PDOException $e){ fwrite(STDERR,"Could not create user: ".$e->getMessage()."\n"); exit(1); }
