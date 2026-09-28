<?php
declare(strict_types=1);

/*
 * Production override:
 * Create config/config.local.php on the server and return the full config array.
 * It is intentionally ignored by Git.
 */
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    return require $local;
}

return require __DIR__ . '/config.example.php';
