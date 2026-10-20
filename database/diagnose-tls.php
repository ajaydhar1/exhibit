<?php
declare(strict_types=1);

namespace Exhibit\TlsDiagnostic;

use Closure;
use PDO;
use PDOException;
use Throwable;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/database.php';

function classify(PDOException $error): string
{
    $detail = $error->errorInfo[2] ?? '';
    $message = strtolower($error->getMessage() . "\n" . (is_string($detail) ? $detail : ''));
    $patterns = [
        'TLS_HOSTNAME_MISMATCH' => '/does not match host name|hostname mismatch|host name mismatch|server certificate .+ does not match/',
        'TLS_CA_LOADING' => '/root certificate file|could not (?:read|open|load) .+cert|no certificate or crl found|system root certificates/',
        'TLS_CERTIFICATE_TRUST' => '/certificate verif(?:y|ication) failed|unable to get .+issuer|self.signed certificate|certificate has expired|certificate is not yet valid|unable to verify .+certificate/',
        'TLS_PROTOCOL_NEGOTIATION' => '/unsupported protocol|wrong version number|protocol version|no shared cipher|no suitable signature algorithm|no protocols available|server does not support ssl|invalid response to ssl negotiation/',
        'TLS_PEER_INTERNAL_ALERT' => '/alert internal error/',
        'TLS_PEER_CERTIFICATE_ALERT' => '/alert bad certificate|alert certificate unknown|alert certificate required|alert unknown ca/',
        'TLS_TRANSPORT_FAILURE' => '/unexpected eof|eof detected|connection reset|server closed the connection|ssl syscall|timed out|timeout expired|connection refused|could not (?:receive|send)|network is unreachable|no route to host/',
        'TLS_HANDSHAKE_UNSPECIFIED' => '/handshake failure|ssl error|ssl connection|tls alert/',
    ];
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $message) === 1) {
            return $label;
        }
    }
    return 'TLS_FAILURE_UNCLASSIFIED';
}

/** The optional connector permits offline tests without opening a socket. */
function diagnose(array $arguments, array $environment, ?Closure $connector = null): string
{
    if ($arguments !== []) {
        return 'TLS_ARGUMENTS_REJECTED';
    }
    try {
        if (($environment['DB_SSLMODE'] ?? false) !== false
            && $environment['DB_SSLMODE'] !== ''
            && $environment['DB_SSLMODE'] !== 'verify-full'
        ) {
            return 'TLS_VERIFICATION_REQUIRED';
        }
        $config = \Exhibit\Database\configuration($environment);
    } catch (Throwable) {
        return 'TLS_CONFIGURATION_REJECTED';
    }
    try {
        $connector ??= static fn (array $config): PDO => new PDO(
            $config['dsn'], $config['username'], $config['password'], $config['options']
        );
        // Do not use connect(): it replaces the original exception and initializes a SQL session.
        $connection = @$connector($config);
        unset($connection);
        return 'TLS_CONNECTION_OK';
    } catch (PDOException $error) {
        return classify($error);
    } catch (Throwable) {
        return 'TLS_RUNTIME_FAILURE';
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    ini_set('zend.exception_ignore_args', '1');
    $label = diagnose(array_slice($argv, 1), \Exhibit\Database\processEnvironment());
    echo $label . "\n";
    exit($label === 'TLS_CONNECTION_OK' ? 0 : 1);
}
