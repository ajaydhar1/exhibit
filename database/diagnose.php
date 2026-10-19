<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/database.php';

if (count($argv) !== 2 || !str_starts_with($argv[1], '--database=') || substr($argv[1], 11) === '') {
    fwrite(STDERR, "Usage: php database/diagnose.php --database=EXPECTED_DATABASE_NAME\nRead-only diagnostics; no migration or application writes.\n");
    exit(2);
}

$pdo = null;
$exitCode = 0;
try {
    $environment = [
        'DATABASE_URL' => getenv('DATABASE_URL'),
        'DB_SSLMODE' => getenv('DB_SSLMODE'),
        'DB_SSLROOTCERT' => getenv('DB_SSLROOTCERT'),
    ];
    \Exhibit\Database\configuration($environment);
    $parts = parse_url($environment['DATABASE_URL']);
    if (rawurldecode(substr($parts['path'], 1)) !== substr($argv[1], 11)) {
        throw new RuntimeException('STOP: URL database name does not match the confirmed target. No network checks attempted.');
    }
    if (($environment['DB_SSLMODE'] ?: 'verify-full') !== 'verify-full') {
        throw new RuntimeException('Diagnostics require verify-full. TLS verification will not be disabled.');
    }
    echo "PASS: URL parsing and expected database name; credentials are not displayed.\n";
    echo "PASS: verify-full configured and CA file readable (certificate trust not yet verified).\n";
    if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('pdo_pgsql is not loaded in this PHP process.');
    }
    echo "PASS: pdo_pgsql loaded.\n";

    $host = trim($parts['host'], '[]');
    $port = $parts['port'] ?? 5432;
    if (!str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('STOP: verify the full Render external DNS hostname, not an internal short name or IP address.');
    }
    $ipv4 = @gethostbynamel($host);
    $ipv6 = @dns_get_record($host, DNS_AAAA);
    $addresses = array_values(array_unique(array_merge(
        $ipv4 === false ? [] : $ipv4,
        $ipv6 === false ? [] : array_column($ipv6, 'ipv6')
    )));
    if ($addresses === []) {
        throw new RuntimeException('DNS: external hostname did not resolve. Check External URL selection, spelling, local DNS, and VPN.');
    }
    echo "PASS: DNS resolves; " . count($addresses) . " address(es). Hostname and addresses withheld.\n";
    $reachable = false;
    foreach ($addresses as $address) {
        $endpoint = str_contains($address, ':') ? "[$address]" : $address;
        $socket = @stream_socket_client("tcp://$endpoint:$port", $errorCode, $errorMessage, 5, STREAM_CLIENT_CONNECT);
        if ($socket !== false) {
            fclose($socket);
            $reachable = true;
            break;
        }
    }
    if (!$reachable) {
        throw new RuntimeException('NETWORK: TCP connection failed for all resolved addresses. Check Render availability, current egress IP/allowlist, VPN, and outbound port 5432 restrictions.');
    }
    echo "PASS: TCP port reachable on at least one resolved address; this does not yet prove TLS or authentication.\n";
    // The helper still connects by hostname, preserving certificate hostname validation.
    $pdo = \Exhibit\Database\connect($environment);
    $pdo->exec('BEGIN READ ONLY');
    $pdo->exec("SET LOCAL statement_timeout = '15s'");
    if ($pdo->query('SHOW transaction_read_only')->fetchColumn() !== 'on') {
        throw new RuntimeException('STOP: read-only transaction not confirmed.');
    }
    if ($pdo->query('SELECT current_database()')->fetchColumn() !== substr($argv[1], 11)) {
        throw new RuntimeException('STOP: connected database identity does not match the confirmed target.');
    }
    $tls = $pdo->query('SELECT ssl, version FROM pg_stat_ssl WHERE pid = pg_backend_pid()')->fetch();
    if (($tls['ssl'] ?? false) !== true) {
        throw new RuntimeException('STOP: active TLS was not confirmed.');
    }
    $pdo->rollBack();
    echo 'PASS: PDO authenticated; expected database confirmed; verify-full connection established; active ' . $tls['version'] . ".\n";
    echo "Read-only diagnostics complete. No migration or application data changes executed.\n";
} catch (Throwable $error) {
    $message = $error instanceof PDOException
        ? 'Read-only diagnostic query failed ' . \Exhibit\Database\connectionFailureSummary($error) . '. No driver details are displayed.'
        : $error->getMessage();
    fwrite(STDERR, "FAIL: $message\n");
    $exitCode = 1;
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (PDOException) {
            $exitCode = 1;
            fwrite(STDERR, "FAIL: Could not confirm read-only transaction closure. No application writes were issued.\n");
        }
    }
}
exit($exitCode);
