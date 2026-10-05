<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$config = require __DIR__ . '/../config/config.php';
$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$config['db']['host'],$config['db']['port'],$config['db']['name'],$config['db']['charset']);

try {
    $db=new PDO($dsn,$config['db']['user'],$config['db']['pass'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);

    require_once __DIR__ . '/../app/migrator.php';
    require_once __DIR__ . '/../app/platform_integrations.php';
    require_once __DIR__ . '/../app/payment_method_cleanup.php';
    require_once __DIR__ . '/../app/hostname_migrate.php';
    require_once __DIR__ . '/../app/custom_migrate.php';
    require_once __DIR__ . '/../app/production_migrate.php';

    skynoc_migrate($db);
    skynoc_platform_integrations_migrate($db);
    payment_method_cleanup($db);
    skynoc_hostname_migrate($db);
    skynoc_custom_migrate($db);
    skynoc_production_migrate($db);

    echo "SkyNoc database migrations completed successfully.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
