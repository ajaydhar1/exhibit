<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../backend/mail.php';

use Exhibit\Mail\{DeliveryError, Resend};

function expectDeliveryError(Closure $operation, bool $retryable): void
{
    try {
        $operation();
    } catch (DeliveryError $error) {
        check($error->retryable === $retryable, 'Wrong delivery failure classification.');
        foreach (['private@example.test', 'secret-token-sentinel', 're_test_sentinel', 'provider-private-sentinel'] as $secret) {
            check(!str_contains((string) $error, $secret), 'Delivery exception leaked sensitive data.');
        }
        check($error->getPrevious() === null, 'Sensitive exception chain retained.');
        return;
    }
    throw new RuntimeException('Expected delivery error was not raised.');
}

final class MailQueueDouble extends PDO
{
    public array $rows = [];
    public array $sql = [];
    public int $commits = 0;
    public int $rollbacks = 0;
    public bool $failDelete = false;
    private bool $transaction = false;
    private array $snapshot = [];

    public function __construct(array $messages)
    {
        foreach ($messages as $index => $message) {
            $this->rows[$index + 1] = [
                'id' => $index + 1, 'payload' => Exhibit\Auth\encryptMail($message),
                'attempts' => 0, 'eligible' => true,
            ];
        }
    }
    public function beginTransaction(): bool
    {
        check(!$this->transaction, 'Nested worker transaction.');
        $this->snapshot = $this->rows;
        return $this->transaction = true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool
    {
        $this->commits++;
        $this->transaction = false;
        return true;
    }
    public function rollBack(): bool
    {
        $this->rollbacks++;
        $this->rows = $this->snapshot;
        $this->transaction = false;
        return true;
    }
    public function exec(string $statement): int|false
    {
        check(str_contains($statement, 'expires_at <= CURRENT_TIMESTAMP'), 'Unexpected cleanup SQL.');
        $this->sql[] = $statement;
        return 0;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->sql[] = $query;
        if (str_contains($query, 'FOR UPDATE SKIP LOCKED')) {
            check($this->transaction && str_contains($query, 'attempts < 5')
                && str_contains($query, 'expires_at > CURRENT_TIMESTAMP'), 'Worker claim protections changed.');
            $result = false;
            foreach ($this->rows as $row) {
                if ($row['eligible'] && $row['attempts'] < 5) {
                    $result = $row;
                    break;
                }
            }
        } else {
            check(str_contains($query, 'attempts >= 5'), 'Unexpected aggregate query.');
            $result = count(array_filter($this->rows, static fn (array $row): bool => $row['attempts'] >= 5));
        }
        return new class($result) extends PDOStatement {
            public function __construct(private mixed $result) {}
            public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
            {
                return $this->result;
            }
            public function fetchColumn(int $column = 0): mixed { return $this->result; }
        };
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sql[] = $query;
        return new class($this, $query) extends PDOStatement {
            public function __construct(private MailQueueDouble $db, private string $sql) {}
            public function execute(?array $params = null): bool
            {
                $id = $params[0];
                if (str_starts_with($this->sql, 'DELETE')) {
                    if ($this->db->failDelete) {
                        throw new PDOException('test-only deletion failure');
                    }
                    unset($this->db->rows[$id]);
                } else {
                    if (str_contains($this->sql, 'attempts + 1')) {
                        check(str_contains($this->sql, "INTERVAL '5 minutes'"), 'Retry delay changed.');
                        $this->db->rows[$id]['attempts']++;
                    } else {
                        check(str_contains($this->sql, 'attempts = 5'), 'Permanent rejection not exhausted.');
                        $this->db->rows[$id]['attempts'] = 5;
                    }
                    $this->db->rows[$id]['eligible'] = false;
                }
                return true;
            }
        };
    }
}

putenv('AUTH_KEY=' . bin2hex(random_bytes(32)));
putenv('APP_URL=https://exhibit.world');
$message = [
    'to' => 'private@example.test', 'subject' => 'Verify your Exhibit email',
    'body' => "Confirm: https://exhibit.world/verify-email.php#token=secret-token-sentinel\n",
];
$config = ['key' => 're_test_sentinel', 'from' => 'noreply@exhibit.world'];
$success = ['status' => 200, 'body' => '{"id":"49a3999c-0ce1-4ea6-ab68-afcd6dc2e794"}'];
$tests = [];
$tests['Explicit Resend configuration and transport selection fail closed'] = static function (): void {
    foreach (['', 'bad-key', "re_invalid\r\nheader", 're_has spaces'] as $key) {
        putenv('RESEND_API_KEY=' . $key);
        putenv('MAIL_FROM=noreply@exhibit.world');
        try {
            Exhibit\Mail\resendConfiguration();
            throw new LogicException('Invalid API key accepted.');
        } catch (RuntimeException) {}
    }
    putenv('RESEND_API_KEY=re_test_sentinel');
    putenv('MAIL_FROM=noreply@exhibit.world');
    check(Exhibit\Mail\resendConfiguration()['from'] === 'noreply@exhibit.world', 'Preferred sender rejected.');
    putenv('MAIL_TRANSPORT=resend');
    check(Exhibit\Mail\configuredSender() instanceof Closure, 'Resend transport not selected.');
    putenv('RESEND_API_KEY=');
    try {
        Exhibit\Mail\configuredSender();
        throw new LogicException('Missing Resend key fell back to SMTP.');
    } catch (RuntimeException) {}
    putenv('RESEND_API_KEY=re_test_sentinel');
    putenv('MAIL_FROM=not-an-email');
    try {
        Exhibit\Mail\configuredSender();
        throw new LogicException('Invalid sender accepted.');
    } catch (RuntimeException) {}
    putenv('MAIL_TRANSPORT=unknown');
    try {
        Exhibit\Mail\configuredSender();
        throw new LogicException('Unknown transport accepted.');
    } catch (RuntimeException) {}
};
$tests['API success uses exact HTTPS endpoint, secure TLS, bearer auth and unchanged text'] = static function () use ($message, $config, $success): void {
    $client = new Resend($config, static function (string $url, array $options) use ($message, $success): array {
        check($url === 'https://api.resend.com/emails', 'Wrong or configurable API endpoint.');
        check($options['ssl']['verify_peer'] && $options['ssl']['verify_peer_name']
            && !$options['ssl']['allow_self_signed'] && $options['ssl']['peer_name'] === 'api.resend.com', 'TLS validation disabled.');
        check($options['http']['method'] === 'POST' && $options['http']['follow_location'] === 0
            && $options['http']['max_redirects'] === 0 && $options['http']['timeout'] === 15, 'Unsafe HTTPS request policy.');
        check(in_array('Authorization: Bearer re_test_sentinel', $options['http']['header'], true), 'Bearer header missing.');
        check(json_decode($options['http']['content'], true) === [
            'from' => 'noreply@exhibit.world', 'to' => [$message['to']],
            'subject' => $message['subject'], 'text' => $message['body'],
        ], 'Message or token link changed.');
        return $success;
    });
    $client->send($message, 'encrypted-payload-identity');
    check(Exhibit\Auth\appUrl() === 'https://exhibit.world', 'Production canonical URL rejected.');
};
$tests['Idempotency is stable on retry, unique for replacement and bound to actual request body'] = static function () use ($message, $config, $success): void {
    $keys = [];
    $request = static function (string $url, array $options) use (&$keys, $success): array {
        foreach ($options['http']['header'] as $header) {
            if (str_starts_with($header, 'Idempotency-Key: ')) {
                check(preg_match('/\AIdempotency-Key: exhibit-auth\/[a-f0-9]{64}\z/', $header) === 1, 'Unsafe key format.');
                $keys[] = $header;
            }
        }
        return $success;
    };
    $client = new Resend($config, $request);
    $client->send($message, 'payload-one');
    $client->send($message, 'payload-one');
    $client->send($message, 'payload-two');
    $client->send(array_replace($message, ['subject' => 'Reset your Exhibit password']), 'payload-one');
    check($keys[0] === $keys[1] && $keys[0] !== $keys[2] && $keys[0] !== $keys[3], 'Incorrect retry/replacement idempotency.');
};
$tests['Transient status, timeouts, and ambiguous success are safely retryable'] = static function () use ($message, $config): void {
    foreach ([0, 408, 425, 429, 500, 502, 503, 504] as $status) {
        $client = new Resend($config, static fn (): array => ['status' => $status, 'body' => 'provider-private-sentinel']);
        expectDeliveryError(fn () => $client->send($message, 'identity'), true);
    }
    foreach (['concurrent_idempotent_requests', 'resource_locked'] as $name) {
        $client = new Resend($config, static fn (): array => ['status' => 409, 'body' => json_encode(['name' => $name])]);
        expectDeliveryError(fn () => $client->send($message, 'identity'), true);
    }
    foreach (['invalid-json', '{}', '{"id":""}', '{"id":"provider-private-sentinel"}', '{"id":"------------------------------------"}'] as $body) {
        $client = new Resend($config, static fn (): array => ['status' => 200, 'body' => $body]);
        expectDeliveryError(fn () => $client->send($message, 'identity'), true);
    }
    $client = new Resend($config, static function (): never { throw new DeliveryError(true, 'HTTPS_CONNECTION'); });
    expectDeliveryError(fn () => $client->send($message, 'identity'), true);
};
$tests['Permanent API rejection and redirects never become success or leak provider details'] = static function () use ($message, $config): void {
    foreach ([301, 302, 400, 401, 403, 404, 409, 422] as $status) {
        $client = new Resend($config, static fn (): array => [
            'status' => $status,
            'body' => '{"name":"invalid_idempotent_request","message":"provider-private-sentinel private@example.test secret-token-sentinel re_test_sentinel"}',
        ]);
        expectDeliveryError(fn () => $client->send($message, 'identity'), false);
    }
};
$tests['Invalid local messages fail without an API request'] = static function () use ($message, $config): void {
    $client = new Resend($config, static function (): never { throw new LogicException('Invalid message reached transport.'); });
    foreach ([
        ['to' => "private@example.test\r\nBcc:someone@example.test"],
        ['subject' => "Bad\r\nheader"], ['body' => "\xff"], ['body' => null],
    ] as $change) {
        expectDeliveryError(fn () => $client->send(array_replace($message, $change), 'identity'), false);
    }
};
$tests['Worker decrypts verification, resend and reset email and deletes only accepted messages'] = static function () use ($message, $config, $success): void {
    $messages = [
        $message,
        array_replace($message, ['body' => 'https://exhibit.world/verify-email.php#token=replacement-test-token']),
        array_replace($message, ['subject' => 'Reset your Exhibit password', 'body' => 'https://exhibit.world/reset-password.php#token=reset-test-token']),
    ];
    $db = new MailQueueDouble($messages);
    $received = [];
    $client = new Resend($config, static function (string $url, array $options) use (&$received, $success): array {
        $received[] = json_decode($options['http']['content'], true)['text'];
        return $success;
    });
    $result = Exhibit\Mail\deliverQueue($db, $client->send(...));
    check($result === ['sent' => 3, 'failed' => 0, 'exhausted' => 0] && $db->rows === [], 'Accepted mail not deleted.');
    check($received === array_column($messages, 'body') && !$db->inTransaction(), 'Queued messages changed or transaction leaked.');
};
$tests['Worker retains five-minute transient retries, stops at five, and exhausts permanent failures'] = static function () use ($message, $config): void {
    $db = new MailQueueDouble([$message]);
    $client = new Resend($config, static fn (): array => ['status' => 429, 'body' => 'provider-private-sentinel']);
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $db->rows[1]['eligible'] = true;
        $result = Exhibit\Mail\deliverQueue($db, $client->send(...));
        check($db->rows[1]['attempts'] === $attempt && $result['failed'] === 1, 'Transient attempt not retained.');
    }
    $db->rows[1]['eligible'] = true;
    check(Exhibit\Mail\deliverQueue($db, $client->send(...)) === ['sent' => 0, 'failed' => 0, 'exhausted' => 1], 'Attempt cap not enforced.');
    $db = new MailQueueDouble([$message]);
    $client = new Resend($config, static fn (): array => ['status' => 403, 'body' => 'provider-private-sentinel']);
    check(Exhibit\Mail\deliverQueue($db, $client->send(...)) === ['sent' => 0, 'failed' => 1, 'exhausted' => 1], 'Permanent error retried/deleted.');
    check($db->rows[1]['attempts'] === 5 && !$db->inTransaction(), 'Permanent failure state incorrect.');
};
$tests['Worker preserves SMTP retry behavior, batch cap and unexpected-error rollback'] = static function () use ($message): void {
    $db = new MailQueueDouble([$message]);
    $result = Exhibit\Mail\deliverQueue($db, static function (): never { throw new RuntimeException('private provider detail'); });
    check($result['failed'] === 1 && $db->rows[1]['attempts'] === 1, 'Legacy SMTP retry behavior changed.');
    $db = new MailQueueDouble(array_fill(0, 26, $message));
    $result = Exhibit\Mail\deliverQueue($db, static function (): void {});
    check($result['sent'] === 25 && count($db->rows) === 1, 'Worker batch limit changed.');
    $db = new MailQueueDouble([$message]);
    try {
        Exhibit\Mail\deliverQueue($db, static function (): never { throw new LogicException('unexpected failure'); });
        throw new RuntimeException('Unexpected error was suppressed.');
    } catch (LogicException) {
        check($db->rollbacks === 1 && !$db->inTransaction() && count($db->rows) === 1, 'Worker did not roll back unexpected failure.');
    }
};
$tests['Database failure after API acceptance rolls back rather than becoming a delivery rejection'] = static function () use ($message): void {
    $db = new MailQueueDouble([$message]);
    $db->failDelete = true;
    try {
        Exhibit\Mail\deliverQueue($db, static function (): void {});
        throw new LogicException('Database deletion failure was suppressed.');
    } catch (PDOException) {
        check($db->rollbacks === 1 && !$db->inTransaction() && $db->rows[1]['attempts'] === 0
            && count($db->rows) === 1, 'Accepted-row database failure did not preserve retry identity.');
    }
};

ini_set('zend.exception_ignore_args', '1');
$log = tempnam(sys_get_temp_dir(), 'exhibit-mail-test-');
check($log !== false, 'Could not create private test log.');
$previousLog = ini_get('error_log');
ini_set('error_log', $log);
$failed = 0;
try {
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo "PASS: $name\n";
        } catch (Throwable $error) {
            $failed++;
            // Do not print exception traces, payloads, API credentials or provider bodies.
            fwrite(STDERR, "FAIL: $name [" . get_class($error) . "].\n");
        }
    }
    $logged = file_get_contents($log);
    foreach (['private@example.test', 'secret-token-sentinel', 're_test_sentinel', 'provider-private-sentinel', 'private provider detail'] as $secret) {
        check(!str_contains($logged, $secret), 'Worker log leaked sensitive information.');
    }
    check(str_contains($logged, 'retry scheduled') && str_contains($logged, 'attempts exhausted'), 'Failures not explicitly logged.');
    echo count($tests) . " mail test groups, $failed failures. No network, database, or real email used.\n";
} finally {
    ini_set('error_log', $previousLog);
    unlink($log);
}
exit($failed === 0 ? 0 : 1);
