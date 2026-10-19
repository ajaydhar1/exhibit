<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../backend/database.php';
require_once __DIR__ . '/../backend/migrations.php';
require_once __DIR__ . '/../backend/auth-migrations.php';

if (array_slice($argv, 1) !== ['--run']) {
    fwrite(STDERR, "Usage: php tests/auth-migrations.php --run\nRequires an empty disposable loopback TEST_DATABASE_URL named exhibit_test_SUFFIX.\n");
    exit(2);
}

function migrationCli(array $environment, array $arguments, int $exit): string
{
    $process = proc_open(array_merge([
        PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_pgsql',
        __DIR__ . '/../database/auth-migrate.php',
    ], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        array_replace(getenv(), $environment));
    check(is_resource($process), 'Could not start migration CLI test.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === $exit, 'Unexpected migration CLI exit. ' . $output);
    check(!str_contains($output, parse_url($environment['DATABASE_URL'], PHP_URL_PASS)), 'Migration command disclosed password.');
    return $output;
}

$db = null;
$installed = false;
$failed = false;
try {
    $environment = testEnvironment(getenv());
    $db = Exhibit\Database\connect($environment);
    $database = $db->query('SELECT current_database()')->fetchColumn();
    $db->beginTransaction();
    Exhibit\Migrations\installInitialSchema($db, $database);
    $db->commit();
    $installed = true;
    $count = 0;
    $preflight = migrationCli($environment, ['--check', '--database=' . $database], 0);
    check(str_contains($preflight, '"read_only": true') && str_contains($preflight, '"users_owner": true'), 'Preflight report incomplete.');
    $count++;
    migrationCli($environment, ['--verify', '--database=' . $database], 1);
    migrationCli($environment, ['--check', '--database=wrong_database'], 1);
    migrationCli($environment, ['--apply', '--database=' . $database, '--sha256=' . str_repeat('0', 64)], 1);
    check(Exhibit\AuthMigrations\tables($db) === ['chapters', 'content_blocks', 'exhibitions', 'users'], 'Refused command changed schema.');
    $count++;
    foreach ([
        "CREATE TYPE public.auth_tokens AS ENUM ('conflict')",
        'CREATE SEQUENCE public.auth_mail_queue_id_seq',
        'CREATE INDEX auth_tokens_expiry_index ON public.users (id)',
        "ALTER TABLE public.users ADD COLUMN email_verified_at text",
        'ALTER TABLE public.users ENABLE ROW LEVEL SECURITY',
        'DROP INDEX public.users_email_lower_unique',
        'ALTER TABLE public.users DISABLE TRIGGER users_set_updated_at',
    ] as $mutation) {
        $db->beginTransaction();
        $db->exec($mutation);
        try {
            Exhibit\AuthMigrations\assertReady($db, $database);
            throw new LogicException('Conflicting or drifted schema accepted.');
        } catch (RuntimeException) {
            $count++;
        } finally {
            $db->rollBack();
        }
    }
    $db->exec("INSERT INTO public.users (email, display_name, password_hash)
        VALUES ('migration-fixture@example.test', 'Migration fixture', 'not-a-real-password')");
    $hash = Exhibit\AuthMigrations\checksum();
    migrationCli($environment, ['--apply', '--database=' . $database, '--sha256=' . $hash], 0);
    $verification = migrationCli($environment, ['--verify', '--database=' . $database], 0);
    check(str_contains($verification, '"schema": "authentication verified"'), 'Installed schema not verified.');
    $row = $db->query('SELECT email_verified_at, session_version FROM public.users')->fetch();
    check($row === ['email_verified_at' => null, 'session_version' => 1], 'Existing user defaults not preserved.');
    $count++;
    migrationCli($environment, ['--apply', '--database=' . $database, '--sha256=' . $hash], 1);
    migrationCli($environment, ['--check', '--database=' . $database], 1);
    $count++;
    foreach ([
        'ALTER TABLE public.users ALTER COLUMN session_version DROP NOT NULL',
        'ALTER TABLE public.auth_tokens DROP CONSTRAINT auth_tokens_user_id_purpose_key',
        'ALTER TABLE public.auth_mail_queue DROP CONSTRAINT auth_mail_queue_user_id_fkey',
        'DROP INDEX public.auth_mail_queue_delivery_index',
        'ALTER TABLE public.auth_tokens ENABLE ROW LEVEL SECURITY',
    ] as $mutation) {
        $db->beginTransaction();
        $db->exec($mutation);
        try {
            Exhibit\AuthMigrations\verifyInstalled($db, $database);
            throw new LogicException('Damaged installed auth schema accepted.');
        } catch (RuntimeException) {
            $count++;
        } finally {
            $db->rollBack();
        }
    }
    echo "PASS: $count authentication migration/preflight/verification scenarios on disposable local PostgreSQL.\n";
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL: Authentication migration tests [' . get_class($error) . ']: '
        . ($error instanceof PDOException ? 'PostgreSQL operation rejected; no driver details displayed.' : $error->getMessage()) . "\n");
} finally {
    if ($db !== null && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($installed) {
        $db->exec('DROP TABLE IF EXISTS public.auth_mail_queue, public.auth_tokens, public.auth_rate_limits,
            public.content_blocks, public.chapters, public.exhibitions, public.users');
        $db->exec('DROP FUNCTION public.exhibit_set_updated_at()');
    }
}
exit($failed ? 1 : 0);
