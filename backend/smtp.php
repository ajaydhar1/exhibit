<?php
declare(strict_types=1);

namespace Exhibit\Mail;

use RuntimeException;

function configuration(): array
{
    $host = getenv('SMTP_HOST');
    $port = getenv('SMTP_PORT');
    $security = getenv('SMTP_SECURITY');
    $from = getenv('MAIL_FROM');
    $username = getenv('SMTP_USERNAME');
    $password = getenv('SMTP_PASSWORD');
    if (!is_string($host) || preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/i', $host) !== 1
        || !is_string($port) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
        || !in_array($security, ['tls', 'starttls'], true)
        || !is_string($from) || !filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $from)
        || (($username !== false && $username !== '') !== ($password !== false && $password !== ''))
    ) {
        throw new RuntimeException('Configure SMTP_HOST, SMTP_PORT, SMTP_SECURITY, MAIL_FROM, and paired SMTP credentials if required.');
    }
    return ['host' => $host, 'port' => (int) $port, 'security' => $security, 'from' => $from,
        'username' => $username === false ? '' : $username, 'password' => $password === false ? '' : $password];
}

final class Smtp
{
    /** @var resource */
    private $socket;

    public function __construct(private array $config)
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true,
            'peer_name' => $config['host'], 'allow_self_signed' => false,
            'disable_compression' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        $socket = @stream_socket_client(
            ($config['security'] === 'tls' ? 'tls://' : 'tcp://') . $config['host'] . ':' . $config['port'],
            $errorCode, $errorMessage, 10, STREAM_CLIENT_CONNECT, $context
        );
        if ($socket === false) {
            throw new RuntimeException('SMTP connection unavailable.');
        }
        $this->socket = $socket;
        stream_set_timeout($socket, 10);
        try {
            $this->response([220]);
            $this->command('EHLO exhibit.local', [250]);
            if ($config['security'] === 'starttls') {
                $this->command('STARTTLS', [220]);
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    throw new RuntimeException('SMTP TLS negotiation failed.');
                }
                $this->command('EHLO exhibit.local', [250]);
            }
            if ($config['username'] !== '') {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($config['username']), [334]);
                $this->command(base64_encode($config['password']), [235]);
            }
        } catch (\Throwable $error) {
            fclose($socket);
            throw $error;
        }
    }

    private function response(array $expected): void
    {
        for ($lines = 0; $lines < 100; $lines++) {
            $line = fgets($this->socket, 2048);
            if ($line === false || preg_match('/\A([0-9]{3})([ -])/', $line, $match) !== 1) {
                throw new RuntimeException('Invalid or timed-out SMTP response.');
            }
            if ($match[2] === ' ') {
                if (!in_array((int) $match[1], $expected, true)) {
                    throw new RuntimeException('SMTP operation rejected.');
                }
                return;
            }
        }
        throw new RuntimeException('SMTP response exceeded protocol limits.');
    }

    private function write(string $data): void
    {
        while ($data !== '') {
            $written = fwrite($this->socket, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('SMTP write failed.');
            }
            $data = substr($data, $written);
        }
    }

    private function command(string $command, array $expected): void
    {
        $this->write($command . "\r\n");
        $this->response($expected);
    }

    public function send(array $message): void
    {
        if (!filter_var($message['to'], FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $message['to'] . $message['subject'])) {
            throw new RuntimeException('Invalid email headers.');
        }
        $this->command('MAIL FROM:<' . $this->config['from'] . '>', [250]);
        $this->command('RCPT TO:<' . $message['to'] . '>', [250, 251]);
        $this->command('DATA', [354]);
        $data = 'From: ' . $this->config['from'] . "\r\nTo: " . $message['to']
            . "\r\nSubject: " . $message['subject'] . "\r\nDate: " . gmdate('D, d M Y H:i:s') . " +0000"
            . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8"
            . "\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($message['body']), 76, "\r\n");
        $this->write($data . ".\r\n");
        $this->response([250]);
    }

    public function __destruct()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
