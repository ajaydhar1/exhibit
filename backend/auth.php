<?php
declare(strict_types=1);

namespace Exhibit\Auth;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/database.php';

final class ValidationError extends RuntimeException {}
final class RateLimitError extends RuntimeException {}

function secret(): string
{
    $value = getenv('AUTH_KEY');
    if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/i', $value) !== 1) {
        throw new RuntimeException('AUTH_KEY must contain 32 random bytes encoded as 64 hexadecimal characters.');
    }
    return hex2bin($value);
}

function appUrl(): string
{
    $value = getenv('APP_URL');
    $parts = is_string($value) ? parse_url($value) : false;
    if ($parts === false || !isset($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || preg_match('/[\x00-\x20\x7f]/', $value)
        || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
        || (($parts['scheme'] ?? '') === 'http' && !in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true))
    ) {
        throw new RuntimeException('APP_URL must be a canonical HTTPS URL (HTTP is allowed only on loopback).');
    }
    return rtrim($value, '/');
}

function encryptMail(array $message): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt(json_encode($message, JSON_THROW_ON_ERROR), 'aes-256-gcm', secret(),
        OPENSSL_RAW_DATA, $nonce, $tag, 'exhibit-auth-mail-v1');
    if ($encrypted === false) {
        throw new RuntimeException('Email queue encryption failed.');
    }
    return base64_encode($nonce . $tag . $encrypted);
}

function decryptMail(string $payload): array
{
    $bytes = base64_decode($payload, true);
    if ($bytes === false || strlen($bytes) < 29) {
        throw new RuntimeException('Invalid encrypted email.');
    }
    $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', secret(), OPENSSL_RAW_DATA,
        substr($bytes, 0, 12), substr($bytes, 12, 16), 'exhibit-auth-mail-v1');
    if ($plain === false) {
        throw new RuntimeException('Email queue decryption failed.');
    }
    $message = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($message) || !is_string($message['to'] ?? null)
        || !is_string($message['subject'] ?? null) || !is_string($message['body'] ?? null)) {
        throw new RuntimeException('Invalid queued email.');
    }
    return $message;
}

function email(string $value): string
{
    $value = strtolower(trim($value));
    if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new ValidationError('Enter a valid email address.');
    }
    return $value;
}

function validatePassword(string $password, string $confirmation): void
{
    // PASSWORD_DEFAULT currently uses bcrypt, whose input limit is 72 bytes.
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")
        || preg_match('//u', $password) !== 1
        || preg_match_all('/./us', $password) < 12) {
        throw new ValidationError('Use a password with at least 12 characters and no more than 72 bytes.');
    }
    if (!hash_equals($password, $confirmation)) {
        throw new ValidationError('Passwords do not match.');
    }
}

final class Service
{
    public function __construct(private PDO $db)
    {
        secret();
        appUrl();
    }

    public function query(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    private function transaction(callable $operation): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function limit(string $action, string $identity, int $maximum, int $seconds): void
    {
        $bucket = hash_hmac('sha256', $action . "\0" . $identity, secret());
        $hits = $this->query(
            "INSERT INTO public.auth_rate_limits (bucket, hits, expires_at)
             VALUES (?, 1, CURRENT_TIMESTAMP + CAST(? AS integer) * INTERVAL '1 second')
             ON CONFLICT (bucket) DO UPDATE SET
                 hits = CASE WHEN auth_rate_limits.expires_at <= CURRENT_TIMESTAMP THEN 1 ELSE auth_rate_limits.hits + 1 END,
                 expires_at = CASE WHEN auth_rate_limits.expires_at <= CURRENT_TIMESTAMP
                     THEN EXCLUDED.expires_at ELSE auth_rate_limits.expires_at END
             RETURNING hits", [$bucket, $seconds]
        )->fetchColumn();
        if ((int) $hits > $maximum) {
            throw new RateLimitError('Too many attempts. Please try again later.');
        }
    }

    public function register(string $name, string $address, string $password, string $confirmation): array
    {
        $name = trim($name);
        if ($name === '' || preg_match('//u', $name) !== 1
            || preg_match('/\p{Cc}/u', $name) || preg_match_all('/./us', $name) > 100) {
            throw new ValidationError('Enter a display name of 1 to 100 characters.');
        }
        $address = email($address);
        validatePassword($password, $confirmation);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        return $this->transaction(function () use ($name, $address, $hash): array {
            $user = $this->query(
                'INSERT INTO public.users (display_name, email, password_hash) VALUES (?, ?, ?)
                 ON CONFLICT (lower(email)) DO NOTHING RETURNING id, session_version',
                [$name, $address, $hash]
            )->fetch();
            if ($user === false) {
                throw new ValidationError('Unable to create an account with these details. Try signing in or recovering your password.');
            }
            $this->issueToken((int) $user['id'], $address, 'verify');
            return $user;
        });
    }

    public function login(string $address, string $password): ?array
    {
        $address = email($address);
        if (strlen($password) > 72 || str_contains($password, "\0")) {
            return null;
        }
        $user = $this->query('SELECT * FROM public.users WHERE lower(email) = ?', [$address])->fetch();
        // A fixed valid hash keeps unknown-user attempts on the password verification path.
        $dummyCost = PHP_VERSION_ID >= 80400 ? '12' : '10';
        $hash = $user === false ? '$2y$' . $dummyCost . '$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.' : $user['password_hash'];
        if (!password_verify($password, $hash) || $user === false) {
            return null;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->query('UPDATE public.users SET password_hash = ? WHERE id = ? AND password_hash = ?',
                [password_hash($password, PASSWORD_DEFAULT), $user['id'], $hash]);
        }
        return $user;
    }

    private function issueToken(int $userId, string $address, string $purpose): void
    {
        $token = bin2hex(random_bytes(32));
        $seconds = $purpose === 'verify' ? 86400 : 1800;
        $this->query("INSERT INTO public.auth_tokens (token_hash, user_id, purpose, expires_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP + CAST(? AS integer) * INTERVAL '1 second')
            ON CONFLICT (user_id, purpose) DO UPDATE SET token_hash = EXCLUDED.token_hash, expires_at = EXCLUDED.expires_at",
            [hash('sha256', $token), $userId, $purpose, $seconds]);
        // Fragments are not sent to access logs, referrers, or the initial HTTP request.
        $link = appUrl() . ($purpose === 'verify' ? '/verify-email.php' : '/reset-password.php') . '#token=' . $token;
        $message = [
            'to' => $address,
            'subject' => $purpose === 'verify' ? 'Verify your Exhibit email' : 'Reset your Exhibit password',
            'body' => ($purpose === 'verify' ? 'Confirm your email address' : 'Choose a new password')
                . " using this single-use link:\n\n" . $link . "\n\nThis link expires in "
                . ($purpose === 'verify' ? '24 hours' : '30 minutes') . ".\nIf you did not request this, ignore this email.\n",
        ];
        $this->query("INSERT INTO public.auth_mail_queue (user_id, purpose, payload, expires_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP + CAST(? AS integer) * INTERVAL '1 second')
            ON CONFLICT (user_id, purpose) DO UPDATE SET payload = EXCLUDED.payload, attempts = 0,
                available_at = CURRENT_TIMESTAMP, expires_at = EXCLUDED.expires_at",
            [$userId, $purpose, encryptMail($message), $seconds]);
    }

    public function requestToken(string $address, string $purpose): void
    {
        if (!in_array($purpose, ['verify', 'reset'], true)) {
            throw new \InvalidArgumentException('Unknown token purpose.');
        }
        $address = email($address);
        $this->limit('mail-' . $purpose, $address, 3, 3600);
        $this->limit('mail-cooldown-' . $purpose, $address, 1, 60);
        $this->transaction(function () use ($address, $purpose): void {
            $user = $this->query('SELECT id, email_verified_at FROM public.users WHERE lower(email) = ? FOR UPDATE', [$address])->fetch();
            if ($user !== false && ($purpose === 'reset' || $user['email_verified_at'] === null)) {
                $this->issueToken((int) $user['id'], $address, $purpose);
            }
        });
    }

    public function consumeToken(string $token, string $purpose, string $password = '', string $confirmation = ''): void
    {
        if (!in_array($purpose, ['verify', 'reset'], true) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw new ValidationError('This link is invalid or has expired. Please request a new one.');
        }
        if ($purpose === 'reset') {
            validatePassword($password, $confirmation);
        }
        $this->transaction(function () use ($token, $purpose, $password): void {
            $hash = hash('sha256', $token);
            // Lock the user first, like issuance, to serialize reset/verification races.
            $user = $this->query('SELECT u.id FROM public.users u JOIN public.auth_tokens t ON t.user_id = u.id
                WHERE t.token_hash = ? AND t.purpose = ? FOR UPDATE OF u', [$hash, $purpose])->fetchColumn();
            $used = $this->query('DELETE FROM public.auth_tokens WHERE token_hash = ? AND purpose = ?
                AND expires_at > CURRENT_TIMESTAMP RETURNING user_id', [$hash, $purpose])->fetchColumn();
            if ($user === false || $used === false) {
                throw new ValidationError('This link is invalid or has expired. Please request a new one.');
            }
            if ($purpose === 'verify') {
                $this->query('UPDATE public.users SET email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP) WHERE id = ?', [$used]);
            } else {
                $this->query('UPDATE public.users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?',
                    [password_hash($password, PASSWORD_DEFAULT), $used]);
            }
            $this->query('DELETE FROM public.auth_mail_queue WHERE user_id = ? AND purpose = ?', [$used, $purpose]);
        });
    }

    public function currentUser(): ?array
    {
        if (!isset($_SESSION['user_id'], $_SESSION['session_version'])) {
            return null;
        }
        $user = $this->query('SELECT id, display_name, email, email_verified_at, session_version FROM public.users WHERE id = ?',
            [$_SESSION['user_id']])->fetch();
        if ($user === false || (int) $user['session_version'] !== $_SESSION['session_version']) {
            clearSession();
            return null;
        }
        return $user;
    }

    public function ownsExhibition(int $exhibitionId, int $userId): bool
    {
        return $this->query('SELECT 1 FROM public.exhibitions WHERE id = ? AND owner_id = ?',
            [$exhibitionId, $userId])->fetchColumn() !== false;
    }
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $url = appUrl();
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '43200');
    session_name('exhibit_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => rtrim($path, '/') . '/', 'secure' => str_starts_with($url, 'https://'),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!session_start()) {
        throw new RuntimeException('Session storage is unavailable.');
    }
    $now = time();
    if (isset($_SESSION['user_id']) && (
        $now - (int) ($_SESSION['last_seen'] ?? 0) >= 1800
        || $now - (int) ($_SESSION['signed_in_at'] ?? 0) >= 43200)) {
        clearSession();
    }
    $_SESSION['last_seen'] = $now;
    if ($now - (int) ($_SESSION['rotated_at'] ?? 0) >= 900) {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Session rotation failed.');
        }
        $_SESSION['rotated_at'] = $now;
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function clearSession(): void
{
    $_SESSION = [];
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Session invalidation failed.');
    }
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['rotated_at'] = time();
}

function signIn(array $user): void
{
    clearSession();
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['session_version'] = (int) $user['session_version'];
    $_SESSION['signed_in_at'] = $_SESSION['last_seen'] = time();
}

function csrfValid(string $value): bool
{
    return $value !== '' && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $value);
}

function post(string $key): string
{
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $page): never
{
    header('Location: ' . appUrl() . '/' . $page, true, 303);
    exit;
}

function privateHeaders(): void
{
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    ini_set('display_errors', '0');
    ini_set('zend.exception_ignore_args', '1');
}

function unavailable(Throwable $error): never
{
    // Exception messages and traces may contain SQL parameters or SMTP payloads.
    error_log('Exhibit authentication unavailable [' . get_class($error) . '].');
    http_response_code(503);
    header('Retry-After: 60');
    exit('Account services are temporarily unavailable. Please try again later.');
}

function service(): Service
{
    return new Service(\Exhibit\Database\connect());
}

function requireCreator(): array
{
    privateHeaders();
    try {
        startSession();
        $user = isset($_SESSION['user_id']) ? service()->currentUser() : null;
        if ($user === null) {
            redirect('sign-in.php');
        }
        if ($user['email_verified_at'] === null) {
            redirect('verify-email.php');
        }
        return $user;
    } catch (Throwable $error) {
        unavailable($error);
    }
}

function requireExhibitionOwner(int $exhibitionId): array
{
    $user = requireCreator();
    try {
        if (!service()->ownsExhibition($exhibitionId, (int) $user['id'])) {
            http_response_code(404);
            exit('Exhibition not found.');
        }
        return $user;
    } catch (Throwable $error) {
        unavailable($error);
    }
}
