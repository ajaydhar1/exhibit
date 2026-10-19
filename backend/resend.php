<?php
declare(strict_types=1);

namespace Exhibit\Mail;

use Closure;
use JsonException;
use RuntimeException;

final class DeliveryError extends RuntimeException
{
    public function __construct(public readonly bool $retryable, string $category)
    {
        parent::__construct('Email delivery failed [' . $category . '].');
    }
}

/** @return array{key: string, from: string} */
function resendConfiguration(): array
{
    $key = getenv('RESEND_API_KEY');
    $from = getenv('MAIL_FROM');
    if (!is_string($key) || preg_match('/\Are_[a-zA-Z0-9_-]+\z/', $key) !== 1) {
        throw new RuntimeException('Configure an Exhibit-specific RESEND_API_KEY environment secret.');
    }
    if (!is_string($from) || !filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $from)) {
        throw new RuntimeException('MAIL_FROM must be an authorized sender email address.');
    }
    return ['key' => $key, 'from' => $from];
}

final class Resend
{
    private Closure $request;

    /** @param null|Closure(string, array): array{status: int, body: string} $request */
    public function __construct(private array $config, ?Closure $request = null)
    {
        $this->request = $request ?? self::https(...);
    }

    /** @return array{status: int, body: string} */
    private static function https(string $url, array $options): array
    {
        if (!extension_loaded('openssl') || !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new DeliveryError(false, 'HTTPS_RUNTIME_CONFIGURATION');
        }
        $stream = @fopen($url, 'rb', false, stream_context_create($options));
        if ($stream === false) {
            throw new DeliveryError(true, 'HTTPS_CONNECTION');
        }
        try {
            $meta = stream_get_meta_data($stream);
            $body = @stream_get_contents($stream, 65537);
            $after = stream_get_meta_data($stream);
            if ($body === false || strlen($body) > 65536 || $after['timed_out']) {
                throw new DeliveryError(true, 'HTTPS_RESPONSE');
            }
            $status = 0;
            foreach ($meta['wrapper_data'] ?? [] as $header) {
                if (preg_match('/\AHTTP\/\S+ ([0-9]{3})\b/', $header, $match)) {
                    $status = (int) $match[1];
                }
            }
            return ['status' => $status, 'body' => $body];
        } finally {
            fclose($stream);
        }
    }

    public function send(array $message, string $queueIdentity): void
    {
        if (!is_string($message['to'] ?? null) || !filter_var($message['to'], FILTER_VALIDATE_EMAIL)
            || !is_string($message['subject'] ?? null) || !is_string($message['body'] ?? null)
            || preg_match('/[\r\n]/', $message['to'] . $message['subject'])) {
            throw new DeliveryError(false, 'INVALID_MESSAGE');
        }
        try {
            $body = json_encode([
                'from' => $this->config['from'], 'to' => [$message['to']],
                'subject' => $message['subject'], 'text' => $message['body'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new DeliveryError(false, 'INVALID_MESSAGE_ENCODING');
        }
        // Stable across retries, new on resend/replacement; no recipient or token in the key.
        $idempotency = 'exhibit-auth/' . hash('sha256', $queueIdentity . "\0" . $body);
        $response = ($this->request)('https://api.resend.com/emails', [
            'http' => [
                'method' => 'POST', 'protocol_version' => 1.1,
                'header' => [
                    'Authorization: Bearer ' . $this->config['key'],
                    'Content-Type: application/json', 'Accept: application/json',
                    'User-Agent: Exhibit-Auth-Mail/1.0', 'Idempotency-Key: ' . $idempotency,
                    'Connection: close',
                ],
                'content' => $body, 'timeout' => 15,
                'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => 'api.resend.com',
                'allow_self_signed' => false, 'disable_compression' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);
        $status = $response['status'];
        $data = json_decode($response['body'], true, 8);
        if ($status >= 200 && $status < 300) {
            if (!is_array($data) || !is_string($data['id'] ?? null)
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $data['id']) !== 1) {
                throw new DeliveryError(true, 'API_SUCCESS_UNCONFIRMED');
            }
            return;
        }
        $retryable = $status === 0 || in_array($status, [408, 425, 429], true) || $status >= 500
            || ($status === 409 && is_array($data)
                && in_array($data['name'] ?? '', ['concurrent_idempotent_requests', 'resource_locked'], true));
        // Never retain the response body, provider message, request headers or a chained exception.
        throw new DeliveryError($retryable, $retryable ? 'API_TRANSIENT' : 'API_PERMANENT');
    }
}
