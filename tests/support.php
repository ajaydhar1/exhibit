<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, array<string, string>> */
function expectedColumns(): array
{
    require_once __DIR__ . '/../backend/schema.php';
    return \Exhibit\Schema\expectedColumns();
}

function testEnvironment(array $environment): array
{
    $url = $environment['TEST_DATABASE_URL'] ?? false;
    try {
        $parts = is_string($url) ? parse_url($url) : false;
    } catch (ValueError) {
        $parts = false;
    }
    if ($parts === false
        || !in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
        || !preg_match('/\A\/exhibit_test_[a-z0-9_]+\z/', $parts['path'] ?? '')
    ) {
        throw new InvalidArgumentException('TEST_DATABASE_URL must target a loopback host and a database named exhibit_test_SUFFIX.');
    }
    return [
        'DATABASE_URL' => $url,
        'DB_SSLMODE' => $environment['TEST_DB_SSLMODE'] ?? false,
        'DB_SSLROOTCERT' => $environment['TEST_DB_SSLROOTCERT'] ?? false,
        'APP_ENV' => 'test',
    ];
}
