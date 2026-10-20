<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../database/diagnose-tls.php';

use function Exhibit\TlsDiagnostic\{classify, diagnose};

$cases = [
    ['certificate verify failed', 'TLS_CERTIFICATE_TRUST'],
    ['SSL error: certificate verification failed', 'TLS_CERTIFICATE_TRUST'],
    ['unable to get local issuer certificate', 'TLS_CERTIFICATE_TRUST'],
    ['self-signed certificate in certificate chain', 'TLS_CERTIFICATE_TRUST'],
    ['certificate has expired', 'TLS_CERTIFICATE_TRUST'],
    ['certificate is not yet valid', 'TLS_CERTIFICATE_TRUST'],
    ['unable to verify the first certificate', 'TLS_CERTIFICATE_TRUST'],
    ['server certificate does not match host name', 'TLS_HOSTNAME_MISMATCH'],
    ['root certificate file could not be read', 'TLS_CA_LOADING'],
    ['could not load SSL certificate', 'TLS_CA_LOADING'],
    ['no certificate or crl found', 'TLS_CA_LOADING'],
    ['SSL error: unsupported protocol', 'TLS_PROTOCOL_NEGOTIATION'],
    ['wrong version number', 'TLS_PROTOCOL_NEGOTIATION'],
    ['no shared cipher', 'TLS_PROTOCOL_NEGOTIATION'],
    ['server does not support SSL', 'TLS_PROTOCOL_NEGOTIATION'],
    ['tlsv1 alert internal error', 'TLS_PEER_INTERNAL_ALERT'],
    ['sslv3 alert bad certificate', 'TLS_PEER_CERTIFICATE_ALERT'],
    ['tlsv1 alert unknown ca', 'TLS_PEER_CERTIFICATE_ALERT'],
    ['SSL SYSCALL error: Connection reset by peer', 'TLS_TRANSPORT_FAILURE'],
    ['SSL error: unexpected eof while reading', 'TLS_TRANSPORT_FAILURE'],
    ['connection timed out', 'TLS_TRANSPORT_FAILURE'],
    ['sslv3 alert handshake failure', 'TLS_HANDSHAKE_UNSPECIFIED'],
    ['SSL error: unrecognized failure', 'TLS_HANDSHAKE_UNSPECIFIED'],
    ['SQLSTATE[08006] unknown failure', 'TLS_FAILURE_UNCLASSIFIED'],
    ['password authentication failed', 'TLS_FAILURE_UNCLASSIFIED'],
    ['', 'TLS_FAILURE_UNCLASSIFIED'],
];
$failed = 0;
$count = 0;
foreach ($cases as [$message, $expected]) {
    foreach ([false, true] as $driverOnly) {
        try {
            $secret = 'private-host.example.test password-sentinel certificate-content-sentinel';
            $error = new PDOException($driverOnly ? 'SQLSTATE[08006]' : $message . ' ' . $secret);
            $error->errorInfo = ['08006', 7, $driverOnly ? $message . ' ' . $secret : null];
            check(classify($error) === $expected, 'Incorrect classification.');
            $label = diagnose([], [
                'DATABASE_URL' => 'postgresql://test:password-sentinel@localhost/exhibit_test_tls',
                'DB_SSLMODE' => 'verify-full',
                'DB_SSLROOTCERT' => __FILE__,
            ], static function () use ($error): never { throw $error; });
            check($label === $expected && !str_contains($label, $secret), 'Diagnostic leaked or changed classification.');
            $count++;
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDERR, 'FAIL: classifier case: ' . $expected . "\n");
        }
    }
}
try {
    $environment = [
        'DATABASE_URL' => 'postgresql://test:password-sentinel@localhost/exhibit_test_tls',
        'DB_SSLMODE' => 'verify-full',
        'DB_SSLROOTCERT' => __FILE__,
    ];
    $neverConnect = static function (): never { throw new LogicException('Connector must not run.'); };
    check(diagnose(['--run'], $environment, $neverConnect) === 'TLS_ARGUMENTS_REJECTED', 'Arguments accepted.');
    foreach (['disable', 'require', 'prefer'] as $mode) {
        check(diagnose([], array_replace($environment, ['DB_SSLMODE' => $mode]), $neverConnect)
            === 'TLS_VERIFICATION_REQUIRED', 'Insecure mode accepted.');
    }
    check(diagnose([], [], $neverConnect) === 'TLS_CONFIGURATION_REJECTED', 'Missing configuration accepted.');
    check(diagnose([], array_replace($environment, ['DB_SSLROOTCERT' => __DIR__]), $neverConnect)
        === 'TLS_CONFIGURATION_REJECTED', 'Invalid CA file accepted.');
    check(diagnose([], $environment, $neverConnect) === 'TLS_RUNTIME_FAILURE', 'Unexpected exception exposed.');
    foreach (['verify-full', '', false] as $mode) {
        $label = diagnose([], array_replace($environment, ['DB_SSLMODE' => $mode]), static function (array $config): PDO {
            check(str_contains($config['dsn'], 'sslmode=verify-full;'), 'Verified TLS lost.');
            check($config['options'][PDO::ATTR_PERSISTENT] === false, 'Persistent connection enabled.');
            return new class extends PDO {
                public function __construct() {}
            };
        });
        check($label === 'TLS_CONNECTION_OK', 'Successful connection not reported.');
    }
    $count++;
} catch (Throwable $error) {
    $failed++;
    fwrite(STDERR, "FAIL: diagnostic guards/configuration.\n");
}
try {
    foreach ([
        [[], '', 'TLS_CONFIGURATION_REJECTED'],
        [[], 'disable', 'TLS_VERIFICATION_REQUIRED'],
        [['--unexpected=password-sentinel'], '', 'TLS_ARGUMENTS_REJECTED'],
    ] as [$arguments, $mode, $expected]) {
        $environment = array_replace(getenv(), ['DATABASE_URL' => '', 'DB_SSLMODE' => $mode]);
        $process = proc_open(
            array_merge([PHP_BINARY, '-n', __DIR__ . '/../database/diagnose-tls.php'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $environment
        );
        check(is_resource($process), 'Could not start CLI guard test.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 1, 'Failure exit status missing.');
        check($output === $expected . "\n" && $errors === '', 'CLI output is not exactly one predefined label.');
        $count++;
    }
} catch (Throwable $error) {
    $failed++;
    fwrite(STDERR, "FAIL: CLI output/exit guards.\n");
}
echo "$count offline TLS checks passed; $failed failures. No network or database used.\n";
exit($failed === 0 ? 0 : 1);
