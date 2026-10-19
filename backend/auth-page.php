<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

use Exhibit\Auth\RateLimitError;
use Exhibit\Auth\ValidationError;
use function Exhibit\Auth\{csrfValid, escape, post, privateHeaders, redirect, service, signIn, startSession, unavailable};

privateHeaders();
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$feedback = '';
$verified = false;
$hasToken = false;
try {
    startSession();
    // Link fragments are posted once by auth.js, then kept only in the session.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfValid(post('csrf'))) {
            http_response_code(403);
            $feedback = 'Your form expired. Please try again.';
        } else {
            $auth = service();
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $auth->limit('ip-' . $authPage, $ip, $authPage === 'sign-in' ? 30 : 15, 900);
            if (in_array($authPage, ['verify-email', 'reset-password'], true) && post('action') === 'capture-token') {
                $token = post('token');
                if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
                    throw new ValidationError('This link is invalid. Please request a new one.');
                }
                $_SESSION[$authPage . '-token'] = ['value' => $token, 'received_at' => time()];
                redirect($authPage . '.php');
            }
            switch ($authPage) {
                case 'register':
                    $auth->limit('registration-email', strtolower(trim(post('email'))), 3, 3600);
                    $user = $auth->register(post('name'), post('email'), post('password'), post('confirm_password'));
                    signIn($user);
                    $_SESSION['auth_flash'] = 'Account created. Your verification email is queued for delivery.';
                    redirect('verify-email.php');
                case 'sign-in':
                    $auth->limit('login-email', strtolower(trim(post('email'))), 10, 900);
                    $user = $auth->login(post('email'), post('password'));
                    if ($user === null) {
                        throw new ValidationError('Email or password is incorrect.');
                    }
                    signIn($user);
                    redirect($user['email_verified_at'] === null ? 'verify-email.php' : 'my-exhibitions.php');
                case 'forgot-password':
                    $auth->requestToken(post('email'), 'reset');
                    $_SESSION['auth_flash'] = 'If an account matches that address, a password-reset email will be sent shortly. Check your inbox and spam folder.';
                    redirect('forgot-password.php');
                case 'verify-email':
                    if (post('action') === 'verify') {
                        $auth->consumeToken($_SESSION['verify-email-token']['value'] ?? '', 'verify');
                        unset($_SESSION['verify-email-token']);
                        $_SESSION['email_just_verified'] = true;
                        redirect('verify-email.php');
                    }
                    $user = $auth->currentUser();
                    $auth->requestToken($user['email'] ?? post('email'), 'verify');
                    $_SESSION['auth_flash'] = 'If an account needs verification, a new email will be sent shortly. Check your inbox and spam folder.';
                    redirect('verify-email.php');
                case 'reset-password':
                    $auth->consumeToken($_SESSION['reset-password-token']['value'] ?? '', 'reset',
                        post('password'), post('confirm_password'));
                    Exhibit\Auth\clearSession();
                    $_SESSION['auth_flash'] = 'Password updated. Please sign in with your new password.';
                    redirect('sign-in.php');
            }
        }
    }
    if (isset($_SESSION['auth_flash'])) {
        $feedback = $_SESSION['auth_flash'];
        unset($_SESSION['auth_flash']);
    }
    $verified = !empty($_SESSION['email_just_verified']);
    unset($_SESSION['email_just_verified']);
} catch (ValidationError $error) {
    http_response_code(422);
    $feedback = $error->getMessage();
} catch (RateLimitError $error) {
    http_response_code(429);
    header('Retry-After: 900');
    $feedback = $error->getMessage();
} catch (Throwable $error) {
    unavailable($error);
}
if (in_array($authPage, ['verify-email', 'reset-password'], true)) {
    $saved = $_SESSION[$authPage . '-token'] ?? null;
    if (is_array($saved) && time() - (int) $saved['received_at'] < ($authPage === 'reset-password' ? 1800 : 86400)) {
        $hasToken = true;
    } else {
        unset($_SESSION[$authPage . '-token']);
    }
}
$csrfInput = '<input type="hidden" name="csrf" value="' . escape($_SESSION['csrf']) . '">';
