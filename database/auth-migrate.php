<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../backend/database.php';
require_once __DIR__ . '/../backend/auth-migrations.php';
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');

$arguments = array_slice($argv, 1);
$target = count($arguments) >= 2 && in_array($arguments[0], ['--check', '--verify', '--apply'], true)
    && str_starts_with($arguments[1], '--database=') && substr($arguments[1], 11) !== '';
$apply = $target && $arguments[0] === '--apply' && count($arguments) === 3
    && preg_match('/\A--sha256=([a-f0-9]{64})\z/', $arguments[2], $hashMatch) === 1;
$readOnly = $target && count($arguments) === 2 && in_array($arguments[0], ['--check', '--verify'], true);
if ($arguments !== [] && $arguments !== ['--plan'] && !$apply && !$readOnly) {
    fwrite(STDERR, "Usage: php database/auth-migrate.php [--plan | --check --database=NAME | --verify --database=NAME | --apply --database=NAME --sha256=APPROVED_HASH]\n");
    exit(2);
}
$db = null;
$committing = false;
try {
    $hash = Exhibit\AuthMigrations\checksum();
    echo 'Exhibit migration: 002_authentication.sql; SHA-256: ' . $hash . "\n";
    if (!$target) {
        echo "Plan: add verification and session fields to users; create auth_tokens, auth_rate_limits, auth_mail_queue.\nNo connection opened. Remote apply requires explicit operator approval.\n";
        exit(0);
    }
    $database = substr($arguments[1], 11);
    $environment = [
        'DATABASE_URL' => getenv('DATABASE_URL'), 'DB_SSLMODE' => getenv('DB_SSLMODE'), 'DB_SSLROOTCERT' => getenv('DB_SSLROOTCERT'),
    ];
    Exhibit\Database\configuration($environment);
    $parts = parse_url($environment['DATABASE_URL']);
    if (rawurldecode(substr($parts['path'], 1)) !== $database) {
        throw new RuntimeException('URL database differs from confirmed target. No connection opened.');
    }
    if ($apply && !hash_equals($hash, $hashMatch[1])) {
        throw new RuntimeException('Approved migration checksum mismatch. No connection opened.');
    }
    $db = Exhibit\Database\connect($environment);
    $db->exec($readOnly ? 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY' : 'BEGIN');
    $db->exec("SET LOCAL statement_timeout = '30s'");
    $db->exec("SET LOCAL lock_timeout = '5s'");
    $tls = $db->query('SELECT ssl, version FROM pg_stat_ssl WHERE pid = pg_backend_pid()')->fetch();
    if (($environment['DB_SSLMODE'] ?: 'verify-full') !== 'disable' && ($tls['ssl'] ?? false) !== true) {
        throw new RuntimeException('Active TLS was not confirmed.');
    }
    if ($readOnly) {
        if ($db->query('SHOW transaction_read_only')->fetchColumn() !== 'on') {
            throw new RuntimeException('Read-only transaction not confirmed.');
        }
        $privileges = $arguments[0] === '--check'
            ? Exhibit\AuthMigrations\assertReady($db, $database)
            : Exhibit\AuthMigrations\verifyInstalled($db, $database);
        $report = [
            'operation' => $arguments[0] === '--check' ? 'preflight' : 'verification',
            'database' => $database,
            'endpoint_sha256' => hash('sha256', strtolower(trim($parts['host'], '[]')) . ':' . ($parts['port'] ?? 5432) . '/' . $database),
            'migration_sha256' => $hash,
            'server_version' => $db->query('SHOW server_version')->fetchColumn(),
            'tls_mode' => $environment['DB_SSLMODE'] ?: 'verify-full',
            'tls_active' => $tls['ssl'] ?? false,
            'tls_version' => $tls['version'] ?? null,
            'read_only' => true, 'writable_primary' => true, 'privileges' => $privileges,
            'schema' => $arguments[0] === '--check' ? 'initial compatible; no conflicting auth objects' : 'authentication verified',
            'checked_at_utc' => gmdate('c'),
        ];
        $db->rollBack();
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        echo "PASS: Read-only metadata checks completed. No migration, application writes, or email delivery performed.\n";
        exit(0);
    }
    Exhibit\AuthMigrations\install($db, $database, $hashMatch[1]);
    $committing = true;
    $db->commit();
    echo "Authentication schema committed and schema verified inside the transaction. Run --verify read-only; do not rerun --apply.\n";
} catch (Throwable $error) {
    if ($db !== null && $db->inTransaction()) {
        try {
            $db->rollBack();
        } catch (PDOException) {
            fwrite(STDERR, "Transaction closure could not be confirmed. Inspect target before retrying.\n");
        }
    }
    fwrite(STDERR, $committing
        ? "Commit outcome unconfirmed. Inspect target before retrying.\n"
        : ($error instanceof PDOException
            ? "Authentication operation failed [PostgreSQL query rejected]. No sensitive details logged.\n"
            : 'Authentication operation refused: ' . $error->getMessage() . "\n"));
    exit(1);
}
