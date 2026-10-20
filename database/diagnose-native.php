<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/database.php';

/**
 * Native libpq receives separate environment fields, not a reconstructed URL/DSN.
 * @param array<string, string|false> $environment
 * @return array<string, string>
 */
function nativeEnvironment(array $environment, string $expectedDatabase, array $inherited): array
{
    \Exhibit\Database\configuration($environment);
    $parts = parse_url($environment['DATABASE_URL']);
    $database = rawurldecode(substr($parts['path'], 1));
    if ($expectedDatabase === '' || $database !== $expectedDatabase) {
        throw new RuntimeException('STOP: expected database does not match the configured URL.');
    }
    if (($environment['DB_SSLMODE'] ?: 'verify-full') !== 'verify-full') {
        throw new RuntimeException('Native diagnostics require explicit verify-full.');
    }
    foreach (array_keys($inherited) as $key) {
        if (str_starts_with(strtoupper($key), 'PG') || strtoupper($key) === 'DATABASE_URL') {
            unset($inherited[$key]);
        }
    }
    return array_replace($inherited, [
        'PGHOST' => $parts['host'],
        'PGPORT' => (string) ($parts['port'] ?? 5432),
        'PGDATABASE' => $database,
        'PGUSER' => rawurldecode($parts['user']),
        'PGPASSWORD' => rawurldecode($parts['pass']),
        'PGSSLMODE' => 'verify-full',
        'PGSSLROOTCERT' => $environment['DB_SSLROOTCERT'],
        'PGCONNECT_TIMEOUT' => '5',
        'PGOPTIONS' => '-c default_transaction_read_only=on -c statement_timeout=15000',
        'PGCLIENTENCODING' => 'UTF8',
    ]);
}

function runNativeDiagnostic(array $arguments): int
{
    if (count($arguments) !== 2 || !str_starts_with($arguments[0], '--database=')
        || !str_starts_with($arguments[1], '--psql=')
    ) {
        fwrite(STDERR, "Usage: php database/diagnose-native.php --database=EXPECTED_NAME --psql=ABSOLUTE_PSQL_PATH\n");
        return 2;
    }
    try {
        $expectedDatabase = substr($arguments[0], 11);
        $environment = nativeEnvironment(\Exhibit\Database\processEnvironment(), $expectedDatabase, getenv());
        $psql = substr($arguments[1], 7);
        if (!is_file($psql)) {
            throw new RuntimeException('The specified native psql executable is not available.');
        }
        $query = "SELECT json_build_object('database', current_database(),
            'readonly', current_setting('transaction_read_only'), 'ssl', ssl, 'tls', version)
            FROM pg_stat_ssl WHERE pid = pg_backend_pid()";
        $process = proc_open(
            [$psql, '-X', '-w', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-c', $query],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        unset($environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start native PostgreSQL diagnostic.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            // Classify only in memory. Neither native output nor its exception is emitted.
            $summary = \Exhibit\Database\connectionFailureSummary(new PDOException($errors));
            fwrite(STDERR, "FAIL: Native psql connection/query $summary; native output withheld.\n");
            return 1;
        }
        $result = json_decode(trim($output), true);
        if (!is_array($result) || ($result['database'] ?? null) !== $expectedDatabase
            || ($result['readonly'] ?? null) !== 'on' || ($result['ssl'] ?? null) !== true
        ) {
            throw new RuntimeException('Native result did not confirm expected identity, read-only session, and TLS. Output withheld.');
        }
        echo "PASS: Native psql authenticated to the expected database with verify-full, active TLS, and a read-only session.\n";
        echo "No migration or application data changes executed. Native output withheld.\n";
        return 0;
    } catch (Throwable $error) {
        $message = $error instanceof InvalidArgumentException || $error instanceof RuntimeException
            ? $error->getMessage() : 'Native diagnostic failed; internal details withheld.';
        fwrite(STDERR, "FAIL: $message\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(runNativeDiagnostic(array_slice($argv, 1)));
}
