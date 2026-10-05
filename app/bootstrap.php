<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['timezone']);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session_name']);
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'cookie_lifetime' => 0,
        'use_strict_mode' => true,
    ]);
}

/*
 * One-time POST protection for the HTML admin/reseller dashboards.
 * A successful form submission consumes the submitted CSRF token and rotates
 * the session token. If the browser reloads the POST response, the browser
 * resends the already-consumed token; redirect it to GET before any action is
 * executed. This prevents re-issuing licenses, double wallet actions, duplicate
 * deposits, repeated orders, ticket replies, and other POST side effects.
 * API requests are intentionally excluded because they have their own auth flow.
 */
$requestPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$isDashboardPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (
        str_starts_with($requestPath, '/admin')
        || str_starts_with($requestPath, '/reseller')
        || in_array(basename($requestPath), ['admin.php', 'reseller.php'], true)
    );

if ($isDashboardPost) {
    $submittedCsrf = (string)($_POST['_csrf'] ?? '');
    $currentCsrf = (string)($_SESSION['_csrf'] ?? '');
    $consumedCsrf = (string)($_SESSION['_csrf_consumed'] ?? '');

    if ($submittedCsrf !== '' && $consumedCsrf !== '' && hash_equals($consumedCsrf, $submittedCsrf)) {
        header('Location: ' . $_SERVER['REQUEST_URI'], true, 303);
        exit;
    }

    if ($submittedCsrf !== '' && $currentCsrf !== '' && hash_equals($currentCsrf, $submittedCsrf)) {
        $_SESSION['_csrf_consumed'] = $submittedCsrf;
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        // verify_csrf() must validate the rotated token during this same request.
        $_POST['_csrf'] = $_SESSION['_csrf'];
    }
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    $config['db']['port'],
    $config['db']['name'],
    $config['db']['charset']
);

try {
    $db = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed. Check config/config.php.');
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/platform_plus.php';
require_once __DIR__ . '/twofa.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/usdt.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/reseller_notifications.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/order_automation.php';
require_once __DIR__ . '/hostname_automation.php';
require_once __DIR__ . '/binance.php';
require_once __DIR__ . '/platform_integrations.php';

/*
 * Database schema changes are deployment operations, not normal web requests.
 * Keep auto_migrate disabled in production and run tools/migrate.php after updates.
 * It remains available for development installs when explicitly enabled.
 */
$autoMigrate = (($config['auto_migrate'] ?? false) === true);
if ($autoMigrate) {
    try {
        require_once __DIR__ . '/migrator.php';
        skynoc_migrate($db);

        try {
            skynoc_platform_integrations_migrate($db);
        } catch (Throwable $e) {
            throw new RuntimeException('Platform integration database setup failed: ' . $e->getMessage(), 0, $e);
        }

        require_once __DIR__ . '/payment_method_cleanup.php';

        try {
            require_once __DIR__ . '/hostname_migrate.php';
            skynoc_hostname_migrate($db);
        } catch (Throwable $e) {
            throw new RuntimeException('Hostname service database setup failed: ' . $e->getMessage(), 0, $e);
        }

        require_once __DIR__ . '/custom_migrate.php';
        skynoc_custom_migrate($db);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('SkyNoc migration failed: ' . $e->getMessage());
        exit('Database migration failed. Run the deployment migration command and check the server error log.');
    }
}