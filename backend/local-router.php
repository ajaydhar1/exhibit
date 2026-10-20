<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'development'
    || getenv('APP_URL') !== 'http://127.0.0.1:8085'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8085') {
    http_response_code(403);
    exit('Use the guarded local launcher and http://127.0.0.1:8085.');
}
try {
    \Exhibit\Database\configuration(\Exhibit\Database\processEnvironment());
} catch (Throwable $error) {
    error_log('Exhibit local configuration refused [' . get_class($error) . '].');
    http_response_code(503);
    exit('Local configuration is invalid. Run the local check command.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pages = ['index.php', 'explore.php', 'exhibition.php', 'view.php', 'chapter-2.php',
    'chapter-3.php', 'chapter-4.php', 'chapter-5.php', 'sign-in.php', 'register.php',
    'forgot-password.php', 'reset-password.php', 'verify-email.php', 'logout.php',
    'my-exhibitions.php', 'new-exhibition.php', 'studio.php', 'publish.php'];
$page = $path === '/' ? 'index.php' : substr((string) $path, 1);
$asset = preg_match('/\Aassets\/[a-zA-Z0-9_.-]+\.(?:css|js|svg|png|jpg|jpeg|webp|woff2?)\z/', $page) === 1;
if ((!in_array($page, $pages, true) && !$asset) || !is_file(dirname(__DIR__) . '/' . $page)) {
    http_response_code(404);
    exit('Not found.');
}
if ($path === '/') {
    require dirname(__DIR__) . '/index.php';
    return true;
}
return false;
