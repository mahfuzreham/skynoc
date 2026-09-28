<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$s=$db->prepare('UPDATE licenses SET status="expired" WHERE expires_at IS NOT NULL AND expires_at < NOW() AND status IN ("active","available")');
$s->execute();
echo "Expired licenses updated: ".$s->rowCount().PHP_EOL;
