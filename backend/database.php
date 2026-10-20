<?php
declare(strict_types=1);

namespace Exhibit\Database;

use PDO;
use PDOException;
use RuntimeException;
use InvalidArgumentException;
use ValueError;

/**
 * @param array<string, string|false> $environment
 * @return array{dsn: string, username: string, password: string, options: array<int, mixed>}
 */
function configuration(array $environment): array
{
    $url = $environment['DATABASE_URL'] ?? false;
    if (!is_string($url) || $url === '') {
        throw new InvalidArgumentException('DATABASE_URL is required.');
    }
    if (preg_match('/[\x00-\x20\x7f]/', $url)) {
        throw new InvalidArgumentException('DATABASE_URL must URL-encode whitespace and special characters.');
    }

    try {
        $parts = parse_url($url);
    } catch (ValueError) {
        $parts = false;
    }

    if ($parts === false
        || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)
        || !isset($parts['host'], $parts['user'], $parts['pass'], $parts['path'])
        || isset($parts['query']) || isset($parts['fragment'])
        || preg_match('/%(?![0-9a-f]{2})/i', $url)
    ) {
        throw new InvalidArgumentException('DATABASE_URL must be a PostgreSQL URL with credentials and a database, without query or fragment.');
    }

    $host = trim($parts['host'], '[]');
    $database = rawurldecode(substr($parts['path'], 1));
    $username = rawurldecode($parts['user']);
    $password = rawurldecode($parts['pass']);
    $port = $parts['port'] ?? 5432;
    $profile = $environment['APP_ENV'] ?? false;
    if ($profile !== false && $profile !== '' && !in_array($profile, ['development', 'test', 'production'], true)) {
        throw new InvalidArgumentException('APP_ENV must be development, test, or production.');
    }
    if ($profile === 'development' && ($parts['host'] !== '127.0.0.1'
        || $port !== 55432 || $database !== 'exhibit_development')) {
        throw new InvalidArgumentException('Development connections require 127.0.0.1:55432/exhibit_development. Remote and other database targets are forbidden.');
    }
    if ($profile === 'test' && (!in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)
        || preg_match('/\Aexhibit_test_[a-z0-9_]+\z/', $database) !== 1)) {
        throw new InvalidArgumentException('Test connections require a loopback exhibit_test_SUFFIX database.');
    }

    if ((!filter_var($host, FILTER_VALIDATE_IP)
            && !preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/i', $host))
        || !preg_match('/\A[a-z0-9_.-]+\z/i', $database)
        || $username === '' || $password === ''
        || preg_match('/[\x00-\x1f\x7f]/', $username . $password)
        || $port < 1 || $port > 65535
    ) {
        throw new InvalidArgumentException('DATABASE_URL contains an invalid host, database, credential, or port.');
    }

    $sslMode = $environment['DB_SSLMODE'] ?? 'verify-full';
    if ($sslMode === false || $sslMode === '') {
        $sslMode = 'verify-full';
    }
    if (!in_array($sslMode, ['verify-full', 'disable'], true)
        || ($sslMode === 'disable' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true))
    ) {
        throw new InvalidArgumentException('DB_SSLMODE must be verify-full; disable is allowed only for loopback development.');
    }

    $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslMode};connect_timeout=5";
    if ($sslMode === 'verify-full') {
        $root = $environment['DB_SSLROOTCERT'] ?? false;
        if (!is_string($root) || $root === '' || preg_match('/[;\x00-\x1f\x7f]/', $root)
            || !is_file($root) || !is_readable($root)
        ) {
            throw new InvalidArgumentException('DB_SSLROOTCERT must name a readable trusted CA bundle for verify-full.');
        }
        $root = str_replace(['\\', "'"], ['\\\\', "\\'"], $root);
        $dsn .= ";sslrootcert='{$root}'";
    }

    return [
        'dsn' => $dsn,
        'username' => $username,
        'password' => $password,
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 5,
        ],
    ];
}

/** @return array{category: string, reason: string, sqlstate: string, driver_detail: string} */
function connectionFailureDetails(PDOException $error): array
{
    $info = $error->errorInfo ?? [];
    $driverMessage = is_string($info[2] ?? null) ? $info[2] : '';
    $message = strtolower($error->getMessage() . "\n" . $driverMessage);
    $knownStates = [
        '08000', '08001', '08003', '08004', '08006', '08007', '08P01',
        '28000', '28P01', '3D000', '42501', '53300', '53400', '57P03', 'HY000',
    ];
    $state = $info[0] ?? $error->getCode();
    if (!is_string($state) || !in_array($state, $knownStates, true)) {
        preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $error->getMessage(), $match);
        $state = isset($match[1]) && in_array($match[1], $knownStates, true) ? $match[1] : 'UNAVAILABLE';
    }
    $details = [
        'category' => 'UNCLASSIFIED', 'reason' => 'NO_RECOGNIZED_SIGNAL',
        'sqlstate' => $state, 'driver_detail' => $driverMessage !== '' ? 'present' : 'absent',
    ];
    $patterns = [
        ['ACCESS_POLICY', 'SERVER_ACCESS_RULE', '/pg_hba\.conf|connection requires a valid client certificate|ip address .+ not allowed|ip .+ not authorized/'],
        ['DNS', 'HOST_RESOLUTION', '/could not translate host name|name or service not known|no such host|name resolution|getaddrinfo/'],
        ['CLIENT_CONFIGURATION', 'CONNECTION_OPTION', '/invalid connection option|invalid sslmode|missing .+ in connection info|invalid connection string|invalid connection parameter/'],
        ['CLIENT_COMPATIBILITY', 'AUTH_PROTOCOL_SUPPORT', '/scram authentication requires|authentication method .+ not supported|unsupported authentication|libpq version/'],
        ['TLS', 'HOSTNAME_VERIFICATION', '/does not match host name|hostname mismatch|host name mismatch|server certificate .+ does not match/'],
        ['TLS', 'CA_FILE', '/root certificate file|could not (?:read|open|load) .+cert|no certificate or crl found|system root certificates/'],
        ['TLS', 'CERTIFICATE_VERIFICATION', '/certificate verify failed|certificate verification failed|unable to get .+issuer|self.signed certificate|certificate has expired|certificate is not yet valid/'],
        ['TLS', 'TLS_HANDSHAKE', '/certificate|ssl error|ssl connection|tls|ssl is not enabled|does not support ssl|ssl support|private key|root cert|ssl syscall|ssl negotiation|ssl response/'],
        ['AUTHENTICATION', 'CREDENTIAL_REJECTION', '/password authentication failed|no password supplied|authentication failed|role .+ does not exist|scram authentication|authentication method/'],
        ['DATABASE', 'DATABASE_SELECTION', '/database .+ does not exist|permission denied for database/'],
        ['SERVER_AVAILABILITY', 'SERVER_NOT_READY', '/too many (?:connections|clients)|remaining connection slots|database system is (?:starting|shutting)|cannot connect now/'],
        ['NETWORK', 'TRANSPORT_FAILURE', '/connection refused|timed out|timeout expired|no route to host|network is unreachable|could not connect to server|connection reset|server closed the connection|could not (?:receive|send|create socket)|software caused connection abort|forcibly closed|eof detected/'],
    ];
    foreach ($patterns as [$category, $reason, $pattern]) {
        if (preg_match($pattern, $message) === 1) {
            return array_replace($details, ['category' => $category, 'reason' => $reason]);
        }
    }
    $stateCategories = [
        '28P01' => ['AUTHENTICATION', 'INVALID_PASSWORD'],
        '28000' => ['AUTHENTICATION_OR_POLICY', 'AUTHORIZATION_REJECTED'],
        '3D000' => ['DATABASE', 'DATABASE_NOT_FOUND'],
        '42501' => ['ACCESS_POLICY', 'INSUFFICIENT_PRIVILEGE'],
        '53300' => ['SERVER_AVAILABILITY', 'CONNECTION_LIMIT'],
        '53400' => ['SERVER_AVAILABILITY', 'SERVER_LIMIT'],
        '57P03' => ['SERVER_AVAILABILITY', 'SERVER_NOT_READY'],
        '08004' => ['CONNECTION', 'SERVER_REJECTED_CONNECTION'],
    ];
    if (isset($stateCategories[$state])) {
        [$details['category'], $details['reason']] = $stateCategories[$state];
    } elseif (str_starts_with($state, '08')) {
        $details['category'] = 'CONNECTION';
        $details['reason'] = 'CONNECTION_SQLSTATE_ONLY';
    }
    return $details;
}

function connectionFailureCategory(PDOException $error): string
{
    return connectionFailureDetails($error)['category'];
}

function connectionFailureSummary(PDOException $error): string
{
    $details = connectionFailureDetails($error);
    return '[' . $details['category'] . '; reason=' . $details['reason']
        . '; SQLSTATE=' . $details['sqlstate'] . '; driver_detail=' . $details['driver_detail'] . ']';
}

function processEnvironment(): array
{
    return [
        'DATABASE_URL' => getenv('DATABASE_URL'),
        'DB_SSLMODE' => getenv('DB_SSLMODE'),
        'DB_SSLROOTCERT' => getenv('DB_SSLROOTCERT'),
        'APP_ENV' => getenv('APP_ENV'),
    ];
}

/** @param array<string, string|false>|null $environment */
function connect(?array $environment = null): PDO
{
    $config = configuration($environment ?? processEnvironment());

    if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Exhibit requires the pdo_pgsql PHP extension.');
    }

    try {
        $pdo = new PDO($config['dsn'], $config['username'], $config['password'], $config['options']);
    } catch (PDOException $error) {
        // Classify before discarding the raw exception; never retain its sensitive chain.
        throw new RuntimeException('Exhibit database connection failed ' . connectionFailureSummary($error)
            . '. No driver details or credentials are displayed.');
    }
    try {
        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec('SET search_path TO public');
        return $pdo;
    } catch (PDOException $error) {
        throw new RuntimeException('Exhibit database session initialization failed ' . connectionFailureSummary($error)
            . '. No driver details or credentials are displayed.');
    }
}
