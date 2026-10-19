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
    $timestamps = ['created_at' => 'timestamp with time zone', 'updated_at' => 'timestamp with time zone'];
    return [
        'users' => [
            'id' => 'bigint', 'email' => 'text', 'password_hash' => 'text', 'display_name' => 'text',
        ] + $timestamps,
        'exhibitions' => [
            'id' => 'bigint', 'owner_id' => 'bigint', 'slug' => 'text', 'title' => 'text',
            'subtitle' => 'text', 'summary' => 'text', 'description' => 'text', 'category' => 'text',
            'cover' => 'jsonb', 'status' => 'text', 'published_at' => 'timestamp with time zone',
        ] + $timestamps,
        'chapters' => [
            'id' => 'bigint', 'exhibition_id' => 'bigint', 'position' => 'integer', 'title' => 'text',
            'summary' => 'text', 'era' => 'text', 'eyebrow' => 'text', 'intro' => 'text',
            'ending_heading' => 'text', 'ending_text' => 'text',
        ] + $timestamps,
        'content_blocks' => [
            'id' => 'bigint', 'chapter_id' => 'bigint', 'position' => 'integer', 'type' => 'text',
            'content' => 'jsonb',
        ] + $timestamps,
    ];
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
    ];
}
