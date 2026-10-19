<?php
declare(strict_types=1);
require_once __DIR__ . '/backend/auth.php';

Exhibit\Auth\privateHeaders();
try {
    Exhibit\Auth\startSession();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('Sign out using the confirmation form on the sign-in screen.');
    }
    if (!Exhibit\Auth\csrfValid(Exhibit\Auth\post('csrf'))) {
        http_response_code(403);
        exit('Your form expired. Return to sign in and try again.');
    }
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => $params['path'], 'secure' => $params['secure'],
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!session_destroy()) {
        throw new RuntimeException('Session destruction failed.');
    }
    Exhibit\Auth\redirect('sign-in.php');
} catch (Throwable $error) {
    Exhibit\Auth\unavailable($error);
}
