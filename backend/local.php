<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/auth-migrations.php';

function localIdentity(PDO $db, string $role): void
{
    if (getenv('APP_ENV') !== 'development' || getenv('APP_URL') !== 'http://127.0.0.1:8085') {
        throw new RuntimeException('Explicit local development configuration is required.');
    }
    $identity = $db->query("SELECT current_database() AS database, current_user AS role,
        host(inet_server_addr()) AS address, inet_server_port() AS port")->fetch();
    if ($identity['database'] !== 'exhibit_development' || $identity['role'] !== $role
        || $identity['address'] !== '127.0.0.1' || (int) $identity['port'] !== 55432) {
        throw new RuntimeException('Local database identity mismatch. No operation permitted.');
    }
}

try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['initialize', 'check', 'inbox'], true) || count($argv) !== 2) {
        throw new RuntimeException('Usage: use database\local.ps1 initialize|check|inbox.');
    }
    $db = \Exhibit\Database\connect();
    $role = $command === 'initialize' ? 'exhibit_dev_admin' : 'exhibit_dev_app';
    localIdentity($db, $role);
    \Exhibit\Auth\secret();
    if ($command === 'initialize') {
        $password = getenv('EXHIBIT_LOCAL_APP_PASSWORD');
        if (!is_string($password) || preg_match('/\A[a-f0-9]{64}\z/', $password) !== 1) {
            throw new RuntimeException('A generated development application password is required.');
        }
        $db->beginTransaction();
        \Exhibit\Migrations\installInitialSchema($db, 'exhibit_development');
        \Exhibit\AuthMigrations\assertReady($db, 'exhibit_development');
        $db->exec(\Exhibit\AuthMigrations\sql());
        \Exhibit\AuthMigrations\verifyInstalled($db, 'exhibit_development');
        $db->exec('CREATE ROLE exhibit_dev_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '
            . $db->quote($password));
        $db->exec('REVOKE ALL ON DATABASE exhibit_development FROM PUBLIC');
        $db->exec('GRANT CONNECT ON DATABASE exhibit_development TO exhibit_dev_app');
        $db->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        $db->exec('GRANT USAGE ON SCHEMA public TO exhibit_dev_app');
        $db->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO exhibit_dev_app');
        $db->exec('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO exhibit_dev_app');
        $db->commit();
        echo "Initialized only exhibit_development using the two existing migrations.\n";
        echo "Application role has data access, not schema-administration privileges.\n";
        echo 'Initial migration SHA-256: ', hash('sha256', \Exhibit\Migrations\initialSql()), "\n";
        echo 'Authentication migration SHA-256: ', \Exhibit\AuthMigrations\checksum(), "\n";
    } else {
        $db->exec('BEGIN READ ONLY');
        \Exhibit\Schema\validateInitialSchema($db, true);
        if (\Exhibit\AuthMigrations\tables($db) !== [
            'auth_mail_queue', 'auth_rate_limits', 'auth_tokens', 'chapters', 'content_blocks', 'exhibitions', 'users',
        ]) {
            throw new RuntimeException('Development schema is incomplete.');
        }
        $permissions = $db->query("SELECT rolsuper, rolcreatedb, rolcreaterole,
            has_schema_privilege('public', 'CREATE') AS schema_create
            FROM pg_roles WHERE rolname = current_user")->fetch();
        if (in_array(true, $permissions, true)) {
            throw new RuntimeException('Development application role has unexpected administrative privileges.');
        }
        if ($command === 'inbox') {
            echo "PRIVATE LOCAL INBOX: contains development-only verification/reset links.\n";
            echo "Do not paste this output into logs, issues, or shared chats. No email is sent.\n";
            $rows = $db->query('SELECT purpose, payload FROM public.auth_mail_queue
                WHERE expires_at > CURRENT_TIMESTAMP ORDER BY id')->fetchAll();
            foreach ($rows as $row) {
                $message = \Exhibit\Auth\decryptMail($row['payload']);
                echo "\nTo: ", $message['to'], "\nSubject: ", $message['subject'], "\n", $message['body'], "\n";
            }
            if ($rows === []) echo "No unexpired local messages.\n";
        } else {
            echo "PASS: local development database identity, schema, application permissions and authentication configuration.\n";
        }
        $db->rollBack();
    }
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Local operation failed [' . get_class($error)
        . "]. Check local status/configuration; no remote fallback is used. Raw database details are withheld.\n");
    if ($error instanceof RuntimeException && !$error instanceof PDOException) {
        fwrite(STDERR, $error->getMessage() . "\n");
    }
    exit(1);
}
