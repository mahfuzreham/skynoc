<?php
declare(strict_types=1);

function sn_base32_decode(string $s): string { $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s=strtoupper(preg_replace('/[^A-Z2-7]/','',$s)); $bits=''; foreach(str_split($s) as $c){$p=strpos($alphabet,$c);if($p!==false)$bits.=str_pad(decbin($p),5,'0',STR_PAD_LEFT);} $out=''; for($i=0;$i+7<strlen($bits);$i+=8)$out.=chr(bindec(substr($bits,$i,8))); return $out; }
function sn_totp(string $secret,int $counter): string { $key=sn_base32_decode($secret); $bin=pack('N*',0).pack('N*',$counter); $hash=hash_hmac('sha1',$bin,$key,true); $o=ord($hash[19])&15; $n=((ord($hash[$o])&127)<<24)|((ord($hash[$o+1])&255)<<16)|((ord($hash[$o+2])&255)<<8)|(ord($hash[$o+3])&255); return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT); }
function sn_totp_ok(string $secret,string $code): bool { $t=intdiv(time(),30); for($i=-1;$i<=1;$i++) if(hash_equals(sn_totp($secret,$t+$i),$code)) return true; return false; }
function sn_new_totp_secret(): string { $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$o='';for($i=0;$i<32;$i++)$o.=$a[random_int(0,31)];return $o; }
function sn_user_2fa(int $userId): ?array { global $db; $s=$db->prepare('SELECT * FROM user_2fa WHERE user_id=? LIMIT 1');$s->execute([$userId]);return $s->fetch()?:null; }
function sn_2fa_required(int $userId): bool { $r=sn_user_2fa($userId); return (bool)($r&&$r['enabled']); }
