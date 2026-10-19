<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/smtp.php';
require_once __DIR__ . '/../backend/auth-migrations.php';
require_once __DIR__ . '/preflight.php';

use Exhibit\Auth\ValidationError;
use function Exhibit\Auth\{appUrl, csrfValid, decryptMail, encryptMail, secret, signIn, startSession, validatePassword};

putenv('APP_URL=http://127.0.0.1/exhibit');
putenv('AUTH_KEY=' . bin2hex(random_bytes(32)));
$tests = [];
$tests['Email normalization and validation'] = static function (): void {
    check(Exhibit\Auth\email(' Creator@Example.test ') === 'creator@example.test', 'Email normalization failed.');
    foreach (['', 'invalid', "mail@example.test\r\nBcc:other@example.test", str_repeat('x', 255) . '@example.test'] as $input) {
        try {
            Exhibit\Auth\email($input);
            throw new RuntimeException('Invalid email accepted.');
        } catch (ValidationError) {}
    }
};
$tests['Password boundaries, Unicode, NUL, and confirmation'] = static function (): void {
    foreach ([str_repeat('a', 12), str_repeat('b', 72), str_repeat("\xc3\xa9", 12)] as $password) {
        validatePassword($password, $password);
        check(password_verify($password, password_hash($password, PASSWORD_DEFAULT)), 'Password hash roundtrip failed.');
    }
    foreach (['', str_repeat('a', 11), str_repeat('a', 73), "twelve\0characters", "\xff" . str_repeat('a', 20), str_repeat("\xc3\xa9", 6)] as $password) {
        try {
            validatePassword($password, $password);
            throw new RuntimeException('Invalid password accepted.');
        } catch (ValidationError) {}
    }
    try {
        validatePassword('valid-password-123', 'does-not-match');
        throw new RuntimeException('Mismatched confirmation accepted.');
    } catch (ValidationError) {}
};
$tests['Canonical URLs reject external HTTP, credentials, and injection'] = static function (): void {
    foreach (['https://example.test/exhibit', 'http://localhost:8181', 'http://[::1]/exhibit'] as $url) {
        putenv('APP_URL=' . $url);
        check(appUrl() === $url, 'Valid canonical URL rejected.');
    }
    foreach (['http://example.test', 'https://user:password@example.test', 'https://example.test?next=evil', "https://example.test\r\n", '//example.test', 'https://example.test#token=secret'] as $url) {
        putenv('APP_URL=' . $url);
        try {
            appUrl();
            throw new LogicException('Unsafe APP_URL accepted.');
        } catch (RuntimeException) {}
    }
    putenv('APP_URL=http://127.0.0.1/exhibit');
};
$tests['Encryption keeps addresses and tokens out of stored queue payloads'] = static function (): void {
    $message = ['to' => 'private@example.test', 'subject' => 'Verification', 'body' => 'sensitive-token-sentinel'];
    $encrypted = encryptMail($message);
    check(!str_contains($encrypted, $message['to']) && !str_contains($encrypted, $message['body']), 'Plaintext stored.');
    check(decryptMail($encrypted) === $message, 'Encrypted mail failed roundtrip.');
    check(encryptMail($message) !== $encrypted, 'Nonce reused.');
    $bytes = base64_decode($encrypted);
    $bytes[30] = chr(ord($bytes[30]) ^ 1);
    try {
        decryptMail(base64_encode($bytes));
        throw new LogicException('Tampered encrypted mail accepted.');
    } catch (RuntimeException) {}
    $key = getenv('AUTH_KEY');
    putenv('AUTH_KEY=' . bin2hex(random_bytes(32)));
    try {
        decryptMail($encrypted);
        throw new LogicException('Wrong key accepted.');
    } catch (RuntimeException) {}
    putenv('AUTH_KEY=' . $key);
};
$tests['Missing encryption key fails closed'] = static function (): void {
    $key = getenv('AUTH_KEY');
    putenv('AUTH_KEY=');
    try {
        secret();
        throw new LogicException('Missing key accepted.');
    } catch (RuntimeException) {}
    putenv('AUTH_KEY=' . $key);
};
$tests['SMTP requires explicit secure configuration'] = static function (): void {
    foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURITY', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'MAIL_FROM'] as $key) {
        putenv($key);
    }
    try {
        Exhibit\Mail\configuration();
        throw new LogicException('Missing SMTP configuration accepted.');
    } catch (RuntimeException) {}
    putenv('SMTP_HOST=smtp.example.test');
    putenv('SMTP_PORT=587');
    putenv('SMTP_SECURITY=starttls');
    putenv('MAIL_FROM=accounts@example.test');
    check(Exhibit\Mail\configuration()['security'] === 'starttls', 'Explicit STARTTLS rejected.');
    putenv('SMTP_SECURITY=none');
    try {
        Exhibit\Mail\configuration();
        throw new LogicException('Plaintext SMTP accepted.');
    } catch (RuntimeException) {}
    putenv('SMTP_SECURITY=tls');
    putenv('SMTP_USERNAME=account');
    try {
        Exhibit\Mail\configuration();
        throw new LogicException('Unpaired SMTP credential accepted.');
    } catch (RuntimeException) {}
};
$tests['CSRF rejects missing and mismatched tokens'] = static function (): void {
    $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
    check(!csrfValid('') && !csrfValid('wrong') && csrfValid($_SESSION['csrf']), 'CSRF validation failed.');
    check(Exhibit\Auth\escape('<script>"&') === '&lt;script&gt;&quot;&amp;', 'HTML escaping failed.');
};
$tests['Migration planning never connects and worker requires explicit run flag'] = static function (): void {
    foreach ([
        ['database/auth-migrate.php', ['--plan'], 0],
        ['database/auth-migrate.php', ['--apply'], 2],
        ['database/auth-migrate.php', ['--apply', '--database=example'], 2],
        ['database/auth-migrate.php', ['--apply', '--database=example', '--sha256=bad'], 2],
        ['database/auth-migrate.php', ['--check'], 2],
        ['database/auth-migrate.php', ['--verify'], 2],
        ['backend/mail-worker.php', [], 2],
    ] as [$file, $arguments, $expected]) {
        $process = proc_open(array_merge([PHP_BINARY, '-n', dirname(__DIR__) . '/' . $file], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Could not start CLI boundary test.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === $expected, 'CLI approval boundary failed.');
        check($expected === 0 ? str_contains($output, 'No connection opened.') : str_contains($errors, 'Usage:'), 'CLI did not explain its safe boundary.');
    }
};
$tests['Authentication preflight checks identity, effective ownership, references and primary state read-only'] = static function (): void {
    $permissions = [
        'database_connect' => true, 'schema_usage' => true, 'schema_create' => true,
        'users_references' => true, 'users_owner' => true,
    ];
    $valid = ['exhibit_expected', false, 'off', $permissions, 0];
    check(Exhibit\AuthMigrations\assertTarget(preflightConnection($valid), 'exhibit_expected') === $permissions, 'Valid target refused.');
    $invalid = [
        [[], false], [['other'], true], [['exhibit_expected', true], true],
        [['exhibit_expected', false, 'on'], true],
        [['exhibit_expected', false, 'off', $permissions, 1], true],
    ];
    foreach (array_keys($permissions) as $permission) {
        $invalid[] = [['exhibit_expected', false, 'off', array_replace($permissions, [$permission => false])], true];
    }
    foreach ($invalid as [$results, $transaction]) {
        try {
            Exhibit\AuthMigrations\assertTarget(preflightConnection($results, $transaction), 'exhibit_expected');
            throw new LogicException('Unsafe authentication target accepted.');
        } catch (RuntimeException) {}
    }
};
$tests['Real session cookies, login rotation, expiry, periodic rotation, and logout'] = static function (): void {
    $_SESSION = [];
    startSession();
    $params = session_get_cookie_params();
    check($params['httponly'] && $params['samesite'] === 'Lax' && !$params['secure'] && $params['path'] === '/exhibit/'
        && $params['lifetime'] === 0, 'Local cookie configuration differs.');
    check(ini_get('session.use_strict_mode') === '1' && ini_get('session.use_only_cookies') === '1', 'Unsafe session mode.');
    $old = session_id();
    $oldCsrf = $_SESSION['csrf'];
    signIn(['id' => 7, 'session_version' => 1]);
    check(session_id() !== $old && $_SESSION['csrf'] !== $oldCsrf, 'Login did not rotate session and CSRF.');
    check($_SESSION['user_id'] === 7, 'Login did not persist identity.');
    $_SESSION['last_seen'] = time() - 1800;
    session_write_close();
    startSession();
    check(!isset($_SESSION['user_id']), 'Idle session did not expire.');
    signIn(['id' => 7, 'session_version' => 1]);
    $_SESSION['signed_in_at'] = time() - 43200;
    session_write_close();
    startSession();
    check(!isset($_SESSION['user_id']), 'Absolute session did not expire.');
    signIn(['id' => 7, 'session_version' => 1]);
    $old = session_id();
    $_SESSION['rotated_at'] = time() - 900;
    session_write_close();
    startSession();
    check(session_id() !== $old && $_SESSION['user_id'] === 7, 'Periodic rotation lost identity or did not run.');
    Exhibit\Auth\clearSession();
    check(!isset($_SESSION['user_id']), 'Cleared session retained identity.');
    session_destroy();
    putenv('APP_URL=https://example.test/exhibit');
    session_id('');
    startSession();
    check(session_get_cookie_params()['secure'], 'HTTPS did not set Secure cookie.');
    session_destroy();
    putenv('APP_URL=http://127.0.0.1/exhibit');
};

// Buffer output so the session tests exercise actual cookie/header behavior.
ob_start();
$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: $name\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL: $name: " . $error->getMessage() . "\n";
    }
}
echo count($tests) . " authentication unit tests, $failed failures.\n";
ob_end_flush();
exit($failed === 0 ? 0 : 1);
