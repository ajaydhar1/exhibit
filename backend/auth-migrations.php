<?php
declare(strict_types=1);

namespace Exhibit\AuthMigrations;

use PDO;
use RuntimeException;
use function Exhibit\Schema\check;

require_once __DIR__ . '/schema.php';

function sql(): string
{
    $sql = file_get_contents(__DIR__ . '/../database/migrations/002_authentication.sql');
    if ($sql === false) {
        throw new RuntimeException('Authentication migration unavailable.');
    }
    return $sql;
}

function checksum(): string
{
    return hash('sha256', sql());
}

function tables(PDO $db): array
{
    return $db->query("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'f') ORDER BY c.relname")->fetchAll(PDO::FETCH_COLUMN);
}

function assertTarget(PDO $db, string $database): array
{
    check($db->inTransaction(), 'Authentication checks require an explicit transaction.');
    check($database !== '' && $db->query('SELECT current_database()')->fetchColumn() === $database,
        'Database identity differs from the explicitly confirmed target.');
    check($db->query('SELECT pg_is_in_recovery()')->fetchColumn() === false
        && $db->query('SHOW default_transaction_read_only')->fetchColumn() === 'off',
        'Authentication migration requires a writable primary.');
    $privileges = $db->query("SELECT
        has_database_privilege(current_database(), 'CONNECT') AS database_connect,
        has_schema_privilege('public', 'USAGE') AS schema_usage,
        has_schema_privilege('public', 'CREATE') AS schema_create,
        has_table_privilege('public.users', 'REFERENCES') AS users_references,
        pg_has_role((SELECT relowner FROM pg_class WHERE oid = 'public.users'::regclass), 'USAGE') AS users_owner")->fetch();
    foreach ($privileges as $name => $allowed) {
        check($allowed === true, 'Required migration privilege missing: ' . $name . '.');
    }
    // No ALTER grant exists: effective membership in the owning role is required.
    $rowSecurity = $db->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relname IN ('users', 'exhibitions', 'chapters', 'content_blocks',
            'auth_tokens', 'auth_rate_limits', 'auth_mail_queue')
        AND (c.relrowsecurity OR c.relforcerowsecurity)")->fetchColumn();
    check((int) $rowSecurity === 0, 'Unexpected row-security policy configuration; review before activation.');
    return $privileges;
}

function assertReady(PDO $db, string $database): array
{
    $privileges = assertTarget($db, $database);
    check(tables($db) === ['chapters', 'content_blocks', 'exhibitions', 'users'],
        'Expected the initialized four-table public schema without extra tables or views.');
    \Exhibit\Schema\validateInitialSchema($db);
    $conflicts = $db->query("SELECT
        (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname LIKE 'auth\\_%' ESCAPE '\\')
        + (SELECT count(*) FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
            WHERE n.nspname = 'public' AND t.typname LIKE 'auth\\_%' ESCAPE '\\')
        + (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE 'auth\\_%' ESCAPE '\\')
        + (SELECT count(*) FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
            WHERE n.nspname = 'public' AND (c.conname LIKE 'auth\\_%' ESCAPE '\\'
                OR c.conname = 'users_session_version_check'))")->fetchColumn();
    check((int) $conflicts === 0, 'Conflicting authentication relation, index, sequence, type, or routine exists.');
    return $privileges;
}

function authColumns(): array
{
    return [
        'users' => [
            'email_verified_at' => ['timestamp with time zone', true, null],
            'session_version' => ['integer', false, '1'],
        ],
        'auth_tokens' => [
            'token_hash' => ['text', false, null], 'user_id' => ['bigint', false, null],
            'purpose' => ['text', false, null], 'expires_at' => ['timestamp with time zone', false, null],
        ],
        'auth_rate_limits' => [
            'bucket' => ['text', false, null], 'hits' => ['integer', false, null],
            'expires_at' => ['timestamp with time zone', false, null],
        ],
        'auth_mail_queue' => [
            'id' => ['bigint', false, null], 'user_id' => ['bigint', false, null],
            'purpose' => ['text', false, null], 'payload' => ['text', false, null],
            'attempts' => ['integer', false, '0'], 'available_at' => ['timestamp with time zone', false, 'CURRENT_TIMESTAMP'],
            'expires_at' => ['timestamp with time zone', false, null],
        ],
    ];
}

function verifyInstalled(PDO $db, string $database): array
{
    $privileges = assertTarget($db, $database);
    check(tables($db) === ['auth_mail_queue', 'auth_rate_limits', 'auth_tokens', 'chapters', 'content_blocks', 'exhibitions', 'users'],
        'Installed authentication table set differs.');
    \Exhibit\Schema\validateInitialSchema($db, true);
    $actual = [];
    foreach ($db->query("SELECT table_name, column_name, data_type, is_nullable, column_default, is_identity, identity_generation
        FROM information_schema.columns WHERE table_schema = 'public'
        AND (table_name IN ('auth_tokens', 'auth_rate_limits', 'auth_mail_queue')
            OR (table_name = 'users' AND column_name IN ('email_verified_at', 'session_version')))
        ORDER BY table_name, ordinal_position") as $column) {
        $actual[$column['table_name']][$column['column_name']] = [
            $column['data_type'], $column['is_nullable'] === 'YES', $column['column_default'],
        ];
        $identity = $column['table_name'] === 'auth_mail_queue' && $column['column_name'] === 'id';
        check($column['is_identity'] === ($identity ? 'YES' : 'NO')
            && (!$identity || $column['identity_generation'] === 'ALWAYS'), 'Authentication identity generation differs.');
    }
    foreach (authColumns() as $table => $columns) {
        check(($actual[$table] ?? []) === $columns, 'Authentication columns/defaults/nullability differ: ' . $table . '.');
    }
    $constraints = $db->query("SELECT c.conname, pg_get_constraintdef(c.oid) AS definition
        FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace
        WHERE n.nspname = 'public' AND (t.relname IN ('auth_tokens', 'auth_rate_limits', 'auth_mail_queue')
            OR (t.relname = 'users' AND c.conname = 'users_session_version_check'))
        AND c.convalidated AND c.contype <> 'n'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $expected = [
        'users_session_version_check' => 'CHECK ((session_version > 0))',
        'auth_tokens_pkey' => 'PRIMARY KEY (token_hash)',
        'auth_tokens_token_hash_check' => "CHECK ((token_hash ~ '^[a-f0-9]{64}$'::text))",
        'auth_tokens_user_id_fkey' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        'auth_tokens_purpose_check' => "CHECK ((purpose = ANY (ARRAY['verify'::text, 'reset'::text])))",
        'auth_tokens_user_id_purpose_key' => 'UNIQUE (user_id, purpose)',
        'auth_rate_limits_pkey' => 'PRIMARY KEY (bucket)',
        'auth_rate_limits_bucket_check' => "CHECK ((bucket ~ '^[a-f0-9]{64}$'::text))",
        'auth_rate_limits_hits_check' => 'CHECK ((hits > 0))',
        'auth_mail_queue_pkey' => 'PRIMARY KEY (id)',
        'auth_mail_queue_user_id_fkey' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        'auth_mail_queue_purpose_check' => "CHECK ((purpose = ANY (ARRAY['verify'::text, 'reset'::text])))",
        'auth_mail_queue_attempts_check' => 'CHECK ((attempts >= 0))',
        'auth_mail_queue_user_id_purpose_key' => 'UNIQUE (user_id, purpose)',
    ];
    ksort($constraints);
    ksort($expected);
    check($constraints === $expected, 'Installed authentication constraints differ or are unvalidated.');
    $indexes = $db->query("SELECT c.relname, pg_get_indexdef(i.indexrelid) AS definition
        FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND i.indisvalid AND i.indisready
        AND c.relname IN ('auth_tokens_expiry_index', 'auth_rate_limits_expiry_index', 'auth_mail_queue_delivery_index')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ([
        'auth_tokens_expiry_index' => ['auth_tokens', 'expires_at'],
        'auth_rate_limits_expiry_index' => ['auth_rate_limits', 'expires_at'],
        'auth_mail_queue_delivery_index' => ['auth_mail_queue', 'available_at'],
    ] as $name => [$table, $column]) {
        check(($indexes[$name] ?? '') === "CREATE INDEX $name ON public.$table USING btree ($column)",
            'Authentication delivery/expiry index differs: ' . $name . '.');
    }
    $runtime = $db->query("SELECT
        (SELECT bool_and(has_table_privilege('public.' || t.name, p.name))
            FROM (VALUES ('users'), ('auth_tokens'), ('auth_rate_limits'), ('auth_mail_queue')) AS t(name)
            CROSS JOIN (VALUES ('SELECT'), ('INSERT'), ('UPDATE'), ('DELETE')) AS p(name)) AS auth_table_dml,
        has_sequence_privilege('public.users_id_seq', 'USAGE')
            AND has_sequence_privilege('public.auth_mail_queue_id_seq', 'USAGE') AS auth_identity_sequence_usage")->fetch();
    foreach ($runtime as $name => $allowed) {
        check($allowed === true, 'Required runtime privilege missing: ' . $name . '.');
    }
    $privileges += $runtime;
    return $privileges;
}

function install(PDO $db, string $database, string $approvedChecksum): void
{
    check($db->inTransaction(), 'Migration installation requires an explicit transaction.');
    $migration = sql();
    check(hash_equals(hash('sha256', $migration), $approvedChecksum), 'Migration checksum differs from the approved SQL.');
    $db->exec("SET LOCAL lock_timeout = '5s'");
    $db->exec("SET LOCAL statement_timeout = '30s'");
    $db->query('SELECT pg_advisory_xact_lock(194872611, 2)');
    // These locks exist only in the separately approved write transaction.
    $db->exec('LOCK TABLE public.users IN ACCESS EXCLUSIVE MODE');
    assertReady($db, $database);
    $db->exec($migration);
    verifyInstalled($db, $database);
}
