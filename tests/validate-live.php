<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../backend/schema.php';

function validateLiveDatabase(): void
{
    $pdo = \Exhibit\Database\connect();
    try {
        $pdo->exec('BEGIN READ ONLY');
        $pdo->exec("SET LOCAL statement_timeout = '15s'");
        check($pdo->query('SHOW transaction_read_only')->fetchColumn() === 'on', 'Validation must be read-only.');
        if (getenv('DB_SSLMODE') !== 'disable') {
            check($pdo->query('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')->fetchColumn() === true, 'TLS is not active.');
        }
        \Exhibit\Schema\validateInitialSchema($pdo);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
