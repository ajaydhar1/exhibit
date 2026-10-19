<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/support.php';

final class AuthHttpClient
{
    public string $cookie = '';
    public function __construct(private string $base) {}

    public function request(string $page, ?array $fields = null): array
    {
        $context = stream_context_create(['http' => [
            'method' => $fields === null ? 'GET' : 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: " . $this->cookie . "\r\n",
            'content' => $fields === null ? '' : http_build_query($fields),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15,
        ]]);
        $body = file_get_contents($this->base . '/' . $page, false, $context);
        check($body !== false, 'HTTP request failed.');
        $headers = $http_response_header;
        preg_match('/HTTP\/\S+ (\d+)/', $headers[0], $status);
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie: (exhibit_session=[^;]*)/i', $header, $match)) {
                $this->cookie = $match[1];
            }
        }
        return ['status' => (int) $status[1], 'body' => $body, 'headers' => implode("\n", $headers)];
    }

    public function csrf(string $page): string
    {
        $response = $this->request($page);
        check($response['status'] === 200, 'Form did not load.');
        check(preg_match('/name="csrf" value="([a-f0-9]{64})"/', $response['body'], $match) === 1, 'CSRF input missing.');
        return $match[1];
    }
}

function runAuthHttp(?PDO $db = null): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    check($socket !== false, 'Unable to reserve test port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address;
    $environment = getenv();
    $environment['APP_URL'] = $base;
    $environment['AUTH_KEY'] = getenv('AUTH_KEY') ?: bin2hex(random_bytes(32));
    if ($db === null) {
        $environment['DATABASE_URL'] = '';
    } else {
        $environment = array_replace($environment, testEnvironment($environment));
    }
    $log = tempnam(sys_get_temp_dir(), 'exhibit-http-');
    check($log !== false, 'Unable to create server log.');
    $process = proc_open([
        PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'),
        '-d', 'extension=openssl', '-d', 'extension=pdo_pgsql', '-d', 'display_errors=0',
        '-d', 'zend.exception_ignore_args=1', '-S', $address, '-t', dirname(__DIR__),
    ], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $environment);
    check(is_resource($process), 'Unable to start isolated HTTP test server.');
    fclose($pipes[0]);
    $count = 0;
    try {
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $probe = @stream_socket_client('tcp://' . $address, $code, $message, 0.1);
            if ($probe !== false) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        check($ready, 'HTTP test server did not become responsive.');
        $client = new AuthHttpClient($base);
        foreach (['register', 'sign-in', 'forgot-password', 'reset-password', 'verify-email'] as $page) {
            $response = $client->request($page . '.php');
            check($response['status'] === 200, 'Account form failed to render.');
            check(str_contains($response['headers'], 'Cache-Control: no-store')
                && str_contains($response['headers'], 'Referrer-Policy: no-referrer')
                && str_contains($response['headers'], 'Content-Security-Policy:')
                && str_contains($response['headers'], 'X-Frame-Options: DENY'), 'Account protection headers missing.');
            check(!str_contains($response['body'], 'googletagmanager') && !str_contains($response['body'], 'data-auth-preview'), 'Unsafe prototype behavior retained.');
            check(str_contains($response['body'], 'assets/auth.css'), 'Approved auth stylesheet not retained.');
            $denied = $client->request($page . '.php', ['csrf' => 'incorrect', 'password' => 'never-log-password']);
            check($denied['status'] === 403 && !str_contains($denied['body'], 'never-log-password'), 'Invalid CSRF accepted or password reflected.');
            $count++;
        }
        $fresh = new AuthHttpClient($base);
        $response = $fresh->request('sign-in.php');
        check(str_contains($response['headers'], 'HttpOnly') && str_contains($response['headers'], 'SameSite=Lax'), 'Session cookie flags missing.');
        foreach (['my-exhibitions.php', 'new-exhibition.php', 'studio.php'] as $page) {
            $response = $client->request($page);
            check($response['status'] === 303 && str_contains($response['headers'], '/sign-in.php'), 'Anonymous creator access allowed.');
            $count++;
        }
        foreach (['index.php', 'explore.php', 'exhibition.php', 'view.php', 'chapter-2.php', 'chapter-3.php', 'chapter-4.php', 'chapter-5.php'] as $page) {
            check($client->request($page)['status'] === 200, 'Public exhibition page requires an account.');
            $count++;
        }
        check($client->request('logout.php')['status'] === 405, 'GET logout changed session.');
        check($client->request('logout.php', ['csrf' => 'incorrect'])['status'] === 403, 'Logout bypassed CSRF.');
        $count++;
        $response = $client->request('verify-email.php?preview=verified');
        check(str_contains($response['body'], 'id="verify-success" role="status" hidden'), 'Prototype query bypasses real verification.');
        $count++;
        if ($db !== null) {
            $password = 'http-test-password-123';
            $email = 'http-creator@example.test';
            $csrf = $client->csrf('register.php');
            $oldCookie = $client->cookie;
            $response = $client->request('register.php', [
                'csrf' => $csrf, 'name' => 'HTTP creator', 'email' => $email,
                'password' => $password, 'confirm_password' => $password,
            ]);
            check($response['status'] === 303 && $client->cookie !== $oldCookie, 'Registration failed or session not rotated.');
            foreach (['my-exhibitions.php', 'new-exhibition.php', 'studio.php'] as $page) {
                $response = $client->request($page);
                check($response['status'] === 303 && str_contains($response['headers'], '/verify-email.php'), 'Unverified creator accessed workspace.');
            }
            $queued = $db->query("SELECT payload FROM public.auth_mail_queue q JOIN public.users u ON u.id = q.user_id
                WHERE u.email = 'http-creator@example.test' AND purpose = 'verify'")->fetchColumn();
            $mail = Exhibit\Auth\decryptMail($queued);
            preg_match('/#token=([a-f0-9]{64})/', $mail['body'], $match);
            $token = $match[1];
            $csrf = $client->csrf('verify-email.php');
            check($client->request('verify-email.php', ['csrf' => $csrf, 'action' => 'capture-token', 'token' => $token])['status'] === 303, 'Verification link capture failed.');
            check($client->request('verify-email.php', ['csrf' => $csrf, 'action' => 'verify'])['status'] === 303, 'Verification failed.');
            foreach (['my-exhibitions.php', 'new-exhibition.php', 'studio.php'] as $page) {
                check($client->request($page)['status'] === 200, 'Verified creator denied workspace.');
            }
            $count++;
            $duplicate = new AuthHttpClient($base);
            $csrf = $duplicate->csrf('register.php');
            check($duplicate->request('register.php', [
                'csrf' => $csrf, 'name' => 'Duplicate', 'email' => strtoupper($email),
                'password' => $password, 'confirm_password' => $password,
            ])['status'] === 422, 'Duplicate registration accepted.');
            $count++;
            $revokedCookie = $client->cookie;
            $csrf = $client->csrf('sign-in.php');
            check($client->request('logout.php', ['csrf' => $csrf])['status'] === 303, 'Logout failed.');
            $replay = new AuthHttpClient($base);
            $replay->cookie = $revokedCookie;
            check($replay->request('my-exhibitions.php')['status'] === 303, 'Destroyed session can be replayed.');
            $csrf = $client->csrf('sign-in.php');
            check($client->request('sign-in.php', ['csrf' => $csrf, 'email' => $email, 'password' => 'wrong-password'])['status'] === 422, 'Wrong password accepted.');
            $oldCookie = $client->cookie;
            check($client->request('sign-in.php', ['csrf' => $csrf, 'email' => $email, 'password' => $password])['status'] === 303, 'Valid login failed.');
            check($client->cookie !== $oldCookie, 'Login session ID not rotated.');
            $count++;
            $csrf = $duplicate->csrf('forgot-password.php');
            check($duplicate->request('forgot-password.php', ['csrf' => $csrf, 'email' => $email])['status'] === 303, 'Recovery request failed.');
            $known = $duplicate->request('forgot-password.php')['body'];
            check($duplicate->request('forgot-password.php', ['csrf' => $csrf, 'email' => 'unknown-http@example.test'])['status'] === 303, 'Unknown account response differs.');
            $unknown = $duplicate->request('forgot-password.php')['body'];
            check($known === $unknown, 'Recovery enumerates accounts.');
            check($duplicate->request('forgot-password.php', ['csrf' => $csrf, 'email' => $email])['status'] === 429, 'Repeated recovery request not limited.');
            $count++;
            $queued = $db->query("SELECT payload FROM public.auth_mail_queue q JOIN public.users u ON u.id = q.user_id
                WHERE u.email = 'http-creator@example.test' AND purpose = 'reset'")->fetchColumn();
            preg_match('/#token=([a-f0-9]{64})/', Exhibit\Auth\decryptMail($queued)['body'], $match);
            check($duplicate->request('reset-password.php', ['csrf' => $csrf, 'action' => 'capture-token', 'token' => $match[1]])['status'] === 303, 'Reset link capture failed.');
            check($duplicate->request('reset-password.php', ['csrf' => $csrf, 'password' => 'new-http-password-123', 'confirm_password' => 'new-http-password-123'])['status'] === 303, 'Password reset failed.');
            check($client->request('my-exhibitions.php')['status'] === 303, 'Reset did not revoke other sessions.');
            $csrf = $duplicate->csrf('reset-password.php');
            check($duplicate->request('reset-password.php', ['csrf' => $csrf, 'action' => 'capture-token', 'token' => $match[1]])['status'] === 303, 'Replay capture failed.');
            check($duplicate->request('reset-password.php', ['csrf' => $csrf, 'password' => 'another-password-123', 'confirm_password' => 'another-password-123'])['status'] === 422, 'Used reset token accepted.');
            $count++;
        }
        $serverLog = file_get_contents($log);
        check(!str_contains($serverLog, 'never-log-password') && !str_contains($serverLog, '@example.test')
            && (!isset($token) || !str_contains($serverLog, $token)), 'Sensitive account data reached HTTP logs.');
        echo "PASS: $count HTTP authentication scenarios" . ($db === null ? ' (offline, no database)' : ' (local PostgreSQL)') . ".\n";
        return $count;
    } finally {
        proc_terminate($process);
        proc_close($process);
        unlink($log);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        runAuthHttp();
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
