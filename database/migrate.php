<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/database.php';
require_once __DIR__ . '/../backend/migrations.php';

$arguments = array_slice($argv, 1);
$targetCommand = count($arguments) === 2 && in_array($arguments[0], ['--apply', '--check'], true)
    && str_starts_with($arguments[1], '--database=')
    && substr($arguments[1], 11) !== '';
if ($arguments !== [] && $arguments !== ['--plan'] && !$targetCommand) {
    fwrite(STDERR, "Usage: php database/migrate.php [--plan | --check --database=NAME | --apply --database=NAME]\n");
    exit(2);
}

$pdo = null;
$commitStarted = false;
try {
    $hash = hash('sha256', \Exhibit\Migrations\initialSql());
    echo "Exhibit migration: 001_initial.sql\nSHA-256: $hash\n";
    if (!$targetCommand) {
        echo "Plan only: creates users, exhibitions, chapters, content_blocks, indexes and timestamp triggers.\n";
        echo "No connection opened. Apply requires explicit --apply and --database confirmation.\n";
        exit(0);
    }
    $pdo = \Exhibit\Database\connect();
    if ($arguments[0] === '--check') {
        $pdo->exec('BEGIN READ ONLY');
        $pdo->exec("SET LOCAL statement_timeout = '15s'");
        $tls = $pdo->query('SELECT ssl, version FROM pg_stat_ssl WHERE pid = pg_backend_pid()')->fetch();
        if (getenv('DB_SSLMODE') !== 'disable' && ($tls['ssl'] ?? false) !== true) {
            throw new RuntimeException('Preflight refused: TLS is not active.');
        }
        \Exhibit\Migrations\assertInitialTarget($pdo, substr($arguments[1], 11));
        $identity = $pdo->query('SELECT current_database() AS database, current_setting(\'server_version\') AS version')->fetch();
        echo 'Confirmed database: ' . json_encode($identity['database'], JSON_THROW_ON_ERROR) . "\n";
        echo 'PostgreSQL version: ' . $identity['version'] . "\n";
        echo ($tls['ssl'] ?? false) === true
            ? 'TLS active: ' . $tls['version'] . '; configured mode: ' . (getenv('DB_SSLMODE') ?: 'verify-full') . "\n"
            : "TLS disabled for explicit loopback development only.\n";
        echo "Permissions: database CONNECT, public USAGE/CREATE, plpgsql USAGE confirmed; writable primary confirmed.\n";
        $pdo->rollBack();
        echo "Read-only preflight passed: user schemas have no existing relations, routines, or types. No migration executed.\n";
        exit(0);
    }
    $pdo->beginTransaction();
    \Exhibit\Migrations\installInitialSchema($pdo, substr($arguments[1], 11));
    $commitStarted = true;
    $pdo->commit();
    echo "Initial schema committed. Record the SHA-256 in the deployment change record.\n";
} catch (Throwable $error) {
    $rollbackFailed = false;
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (PDOException) {
            $rollbackFailed = true;
        }
    }
    // PDO diagnostics may contain connection details or SQL values.
    $message = $error instanceof PDOException
        ? ($commitStarted
            ? 'Commit outcome could not be confirmed. Inspect the target schema before taking any further action.'
            : 'PostgreSQL rejected the migration. Verify the target, privileges, and schema; no changes were committed.')
        : $error->getMessage();
    if ($rollbackFailed) {
        $message .= ' Rollback could not be confirmed; inspect the target before retrying.';
    }
    fwrite(STDERR, "Migration failed: $message\n");
    exit(1);
}
