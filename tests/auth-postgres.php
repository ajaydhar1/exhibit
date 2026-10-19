<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/migrations.php';
require_once __DIR__ . '/auth-http.php';
require_once __DIR__ . '/exhibition-workflow.php';

use Exhibit\Auth\{Service, ValidationError, RateLimitError};

if (array_slice($argv, 1) !== ['--run']) {
    fwrite(STDERR, "Usage: php tests/auth-postgres.php --run\nRequires an EMPTY loopback TEST_DATABASE_URL named exhibit_test_SUFFIX. Installs and removes only its test schema.\n");
    exit(2);
}

function tokenFor(Service $auth, int $id, string $purpose): string
{
    $row = $auth->query('SELECT payload FROM public.auth_mail_queue WHERE user_id = ? AND purpose = ?', [$id, $purpose])->fetchColumn();
    check(is_string($row), 'Expected queued email missing.');
    preg_match('/#token=([a-f0-9]{64})/', Exhibit\Auth\decryptMail($row)['body'], $match);
    check(isset($match[1]), 'Queued email has no secure link.');
    return $match[1];
}

function rejectsAuth(callable $operation, string $exception = ValidationError::class): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error instanceof $exception, 'Unexpected authentication error category.');
        return;
    }
    throw new RuntimeException('Invalid authentication operation was accepted.');
}

$db = null;
$installed = false;
$failed = 0;
try {
    $environment = testEnvironment(getenv());
    $db = Exhibit\Database\connect($environment);
    $database = $db->query('SELECT current_database()')->fetchColumn();
    $db->beginTransaction();
    Exhibit\Migrations\installInitialSchema($db, $database);
    $db->exec(file_get_contents(__DIR__ . '/../database/migrations/002_authentication.sql'));
    $db->commit();
    $installed = true;
    putenv('APP_URL=http://127.0.0.1');
    putenv('AUTH_KEY=' . bin2hex(random_bytes(32)));
    $auth = new Service($db);
    $password = 'service-test-password-123';
    $user = $auth->register('Test creator', 'Creator@example.test', $password, $password);
    $id = (int) $user['id'];
    $tests = [];
    $tests['Registration hashes passwords and enforces case-insensitive uniqueness'] = static function () use ($auth, $id, $password): void {
        $row = $auth->query('SELECT * FROM public.users WHERE id = ?', [$id])->fetch();
        check($row['email'] === 'creator@example.test' && $row['password_hash'] !== $password
            && password_verify($password, $row['password_hash']) && $row['email_verified_at'] === null, 'Incorrect registration record.');
        rejectsAuth(fn () => $auth->register('Duplicate', 'CREATOR@example.test', $password, $password));
        rejectsAuth(fn () => $auth->register('', 'other@example.test', $password, $password));
        rejectsAuth(fn () => $auth->register(str_repeat('x', 101), 'other@example.test', $password, $password));
        rejectsAuth(fn () => $auth->register('Name', 'bad', $password, $password));
        rejectsAuth(fn () => $auth->register('Name', 'other@example.test', 'short', 'short'));
        check((int) $auth->query('SELECT count(*) FROM public.users')->fetchColumn() === 1, 'Invalid registration persisted.');
    };
    $tests['Login accepts correct passwords only, including normalized email'] = static function () use ($auth, $id, $password): void {
        check((int) $auth->login(' CREATOR@example.test ', $password)['id'] === $id, 'Valid login failed.');
        check($auth->login('creator@example.test', 'wrong') === null, 'Wrong password accepted.');
        check($auth->login('missing@example.test', $password) === null, 'Unknown account authenticated.');
        check($auth->login('creator@example.test', str_repeat('a', 73)) === null, 'Overlong login accepted.');
    };
    $tests['Verification token hashing, purpose isolation, expiry, single use'] = static function () use ($auth, $id): void {
        $token = tokenFor($auth, $id, 'verify');
        $stored = $auth->query('SELECT token_hash FROM public.auth_tokens WHERE user_id = ?', [$id])->fetchColumn();
        check($stored === hash('sha256', $token) && $stored !== $token, 'Raw token stored.');
        rejectsAuth(fn () => $auth->consumeToken($token, 'reset', 'replacement-password', 'replacement-password'));
        $auth->query("UPDATE public.auth_tokens SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE user_id = ?", [$id]);
        rejectsAuth(fn () => $auth->consumeToken($token, 'verify'));
        $auth->query("UPDATE public.auth_tokens SET expires_at = CURRENT_TIMESTAMP + INTERVAL '1 hour' WHERE user_id = ?", [$id]);
        $auth->consumeToken($token, 'verify');
        rejectsAuth(fn () => $auth->consumeToken($token, 'verify'));
        check($auth->query('SELECT email_verified_at FROM public.users WHERE id = ?', [$id])->fetchColumn() !== null, 'Verification not persisted.');
    };
    $tests['Resend replaces old links and enforces persistent cooldown'] = static function () use ($auth, $password): void {
        $user = $auth->register('Resend creator', 'resend@example.test', $password, $password);
        $id = (int) $user['id'];
        $old = tokenFor($auth, $id, 'verify');
        $auth->requestToken('resend@example.test', 'verify');
        $new = tokenFor($auth, $id, 'verify');
        check($new !== $old, 'Resend reused token.');
        rejectsAuth(fn () => $auth->consumeToken($old, 'verify'));
        rejectsAuth(fn () => $auth->requestToken('resend@example.test', 'verify'), RateLimitError::class);
        $auth->consumeToken($new, 'verify');
    };
    $tests['Recovery for unknown accounts does not create tokens; known tokens expire'] = static function () use ($auth, $id): void {
        $count = (int) $auth->query('SELECT count(*) FROM public.auth_tokens')->fetchColumn();
        $auth->requestToken('unknown@example.test', 'reset');
        check((int) $auth->query('SELECT count(*) FROM public.auth_tokens')->fetchColumn() === $count, 'Unknown recovery created token.');
        $auth->requestToken('creator@example.test', 'reset');
        $token = tokenFor($auth, $id, 'reset');
        $auth->query("UPDATE public.auth_tokens SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE user_id = ? AND purpose = 'reset'", [$id]);
        rejectsAuth(fn () => $auth->consumeToken($token, 'reset', 'replacement-password', 'replacement-password'));
        $auth->query("UPDATE public.auth_tokens SET expires_at = CURRENT_TIMESTAMP + INTERVAL '1 hour' WHERE user_id = ? AND purpose = 'reset'", [$id]);
    };
    $tests['Reset changes hash, revokes sessions, rejects replay and invalid confirmation'] = static function () use ($auth, $id, $password): void {
        $token = tokenFor($auth, $id, 'reset');
        rejectsAuth(fn () => $auth->consumeToken($token, 'reset', 'replacement-password', 'does-not-match'));
        $version = (int) $auth->query('SELECT session_version FROM public.users WHERE id = ?', [$id])->fetchColumn();
        $auth->consumeToken($token, 'reset', 'replacement-password', 'replacement-password');
        rejectsAuth(fn () => $auth->consumeToken($token, 'reset', 'replacement-password', 'replacement-password'));
        check($auth->login('creator@example.test', $password) === null && $auth->login('creator@example.test', 'replacement-password') !== null, 'Password was not replaced.');
        check((int) $auth->query('SELECT session_version FROM public.users WHERE id = ?', [$id])->fetchColumn() === $version + 1, 'Session generation not incremented.');
        check($auth->query("SELECT payload FROM public.auth_mail_queue WHERE user_id = ? AND purpose = 'reset'", [$id])->fetchColumn() === false, 'Used recovery email retained.');
    };
    $tests['Rate limits count atomically and reset only after expiry'] = static function () use ($auth): void {
        $auth->limit('test', 'identity', 2, 60);
        $auth->limit('test', 'identity', 2, 60);
        rejectsAuth(fn () => $auth->limit('test', 'identity', 2, 60), RateLimitError::class);
        $bucket = hash_hmac('sha256', "test\0identity", Exhibit\Auth\secret());
        $auth->query("UPDATE public.auth_rate_limits SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE bucket = ?", [$bucket]);
        $auth->limit('test', 'identity', 2, 60);
        check((int) $auth->query('SELECT hits FROM public.auth_rate_limits WHERE bucket = ?', [$bucket])->fetchColumn() === 1, 'Expired limit did not reset.');
    };
    $tests['Ownership is scoped to creator and exhibition'] = static function () use ($auth, $id): void {
        $exhibition = (int) $auth->query("INSERT INTO public.exhibitions (owner_id, slug, title) VALUES (?, 'ownership-fixture', 'Ownership') RETURNING id", [$id])->fetchColumn();
        check($auth->ownsExhibition($exhibition, $id), 'Owner denied.');
        check(!$auth->ownsExhibition($exhibition, $id + 1000) && !$auth->ownsExhibition($exhibition + 1000, $id), 'Foreign or nonexistent exhibition authorized.');
    };
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo "PASS: $name\n";
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDERR, "FAIL: $name [" . get_class($error) . "]. No sensitive exception details printed.\n");
        }
    }
    runExhibitionPostgres($db);
    runAuthHttp($db);
    echo count($tests) . " PostgreSQL service tests; $failed failures.\n";
} catch (Throwable $error) {
    $failed++;
    fwrite(STDERR, 'Authentication PostgreSQL tests unavailable/failed [' . get_class($error) . "]. Requires an empty disposable loopback database; no remote fallback used.\n");
} finally {
    if ($db !== null && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($installed) {
        $db->exec('DROP TABLE public.auth_mail_queue, public.auth_tokens, public.auth_rate_limits,
            public.content_blocks, public.chapters, public.exhibitions, public.users');
        $db->exec('DROP FUNCTION public.exhibit_set_updated_at()');
    }
}
exit($failed === 0 ? 0 : 1);
