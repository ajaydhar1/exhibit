<?php
declare(strict_types=1);

namespace Exhibit\Schema;

use PDO;
use PDOException;
use RuntimeException;

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

function validateInitialSchema(PDO $pdo, bool $authenticationInstalled = false): void
{
    check($pdo->inTransaction(), 'Schema validation requires an explicit transaction.');
    try {
        $statement = $pdo->query(
            "SELECT table_name, column_name, data_type, is_nullable, is_identity, identity_generation, column_default
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name IN ('users', 'exhibitions', 'chapters', 'content_blocks')
             ORDER BY table_name, ordinal_position"
        );
        $actual = [];
        foreach ($statement as $column) {
            if ($authenticationInstalled && $column['table_name'] === 'users'
                && in_array($column['column_name'], ['email_verified_at', 'session_version'], true)) {
                continue;
            }
            $actual[$column['table_name']][$column['column_name']] = $column['data_type'];
            if ($column['column_name'] === 'id') {
                check($column['is_identity'] === 'YES' && $column['identity_generation'] === 'ALWAYS', 'Identity definition mismatch.');
            }
            $required = [
                'users' => ['id', 'email', 'password_hash', 'display_name', 'created_at', 'updated_at'],
                'exhibitions' => ['id', 'owner_id', 'slug', 'title', 'status', 'created_at', 'updated_at'],
                'chapters' => ['id', 'exhibition_id', 'position', 'title', 'created_at', 'updated_at'],
                'content_blocks' => ['id', 'chapter_id', 'position', 'type', 'content', 'created_at', 'updated_at'],
            ];
            $nullable = !in_array($column['column_name'], $required[$column['table_name']], true);
            check(($column['is_nullable'] === 'YES') === $nullable, 'Nullability mismatch.');
            if (in_array($column['column_name'], ['created_at', 'updated_at'], true)) {
                check($column['column_default'] === 'CURRENT_TIMESTAMP', 'Timestamp default mismatch.');
            }
            if ($column['column_name'] === 'status') {
                check($column['column_default'] === "'draft'::text", 'Draft default mismatch.');
            }
        }
        foreach (expectedColumns() as $table => $columns) {
            check(($actual[$table] ?? []) === $columns, 'Installed columns differ: ' . $table);
        }

        $constraints = $pdo->query(
            "SELECT c.conname, c.contype, c.condeferrable, c.confdeltype, pg_get_constraintdef(c.oid) AS definition
             FROM pg_constraint c
             JOIN pg_class t ON t.oid = c.conrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE n.nspname = 'public' AND t.relname IN ('users', 'exhibitions', 'chapters', 'content_blocks')
             AND c.convalidated"
        )->fetchAll(PDO::FETCH_UNIQUE);
        foreach (['users', 'exhibitions', 'chapters', 'content_blocks'] as $table) {
            check(($constraints[$table . '_pkey']['contype'] ?? '') === 'p'
                && $constraints[$table . '_pkey']['definition'] === 'PRIMARY KEY (id)', 'Primary key missing: ' . $table);
        }
        foreach ([
            'exhibitions_owner_id_fkey' => ['r', 'FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT'],
            'chapters_exhibition_id_fkey' => ['c', 'FOREIGN KEY (exhibition_id) REFERENCES exhibitions(id) ON DELETE CASCADE'],
            'content_blocks_chapter_id_fkey' => ['c', 'FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE'],
        ] as $name => [$deleteRule, $definition]) {
            check(($constraints[$name]['confdeltype'] ?? '') === $deleteRule
                && $constraints[$name]['definition'] === $definition, 'Foreign key mismatch: ' . $name);
        }
        foreach ([
            'chapters_exhibition_position_unique' => 'UNIQUE (exhibition_id, position) DEFERRABLE',
            'content_blocks_chapter_position_unique' => 'UNIQUE (chapter_id, position) DEFERRABLE',
        ] as $name => $definition) {
            check(($constraints[$name]['condeferrable'] ?? false) === true
                && str_replace('"', '', $constraints[$name]['definition']) === $definition, 'Deferrable ordering mismatch: ' . $name);
        }
        foreach ([
            'users_email_check', 'users_password_hash_check', 'users_display_name_check',
            'exhibitions_slug_check', 'exhibitions_title_check', 'exhibitions_cover_check',
            'exhibitions_status_check', 'exhibitions_publication_state', 'chapters_position_check',
            'chapters_title_check', 'content_blocks_position_check', 'content_blocks_type_check', 'content_blocks_content_check',
        ] as $name) {
            check(($constraints[$name]['contype'] ?? '') === 'c', 'Check constraint missing: ' . $name);
        }
        check(($constraints['exhibitions_slug_key']['contype'] ?? '') === 'u', 'Slug uniqueness missing.');
        foreach ([
            'users_email_check' => "CHECK (((btrim(email) <> ''::text) AND (email = btrim(email))))",
            'users_password_hash_check' => "CHECK ((btrim(password_hash) <> ''::text))",
            'users_display_name_check' => "CHECK ((btrim(display_name) <> ''::text))",
        ] as $name => $definition) {
            check(($constraints[$name]['definition'] ?? '') === $definition, 'Account check definition differs: ' . $name . '.');
        }
        $indexes = $pdo->query(
            "SELECT c.relname, pg_get_indexdef(i.indexrelid) FROM pg_index i
             JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND i.indisvalid AND i.indisready
             AND c.relname IN ('users_email_lower_unique', 'exhibitions_owner_id_index')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        check(($indexes['users_email_lower_unique'] ?? '') ===
            'CREATE UNIQUE INDEX users_email_lower_unique ON public.users USING btree (lower(email))', 'Case-insensitive email index differs.');
        check(($indexes['exhibitions_owner_id_index'] ?? '') ===
            'CREATE INDEX exhibitions_owner_id_index ON public.exhibitions USING btree (owner_id)', 'Owner index differs.');
        $triggers = $pdo->query(
            "SELECT t.relname, pg_get_triggerdef(g.oid) AS definition
             FROM pg_trigger g JOIN pg_class t ON t.oid = g.tgrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE n.nspname = 'public' AND NOT g.tgisinternal AND g.tgenabled IN ('O', 'A')
             AND g.tgname = t.relname || '_set_updated_at'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (array_keys(expectedColumns()) as $table) {
            check(str_contains($triggers[$table] ?? '', 'BEFORE UPDATE')
                && str_contains($triggers[$table] ?? '', 'exhibit_set_updated_at()'), 'Update trigger missing: ' . $table);
        }
    } catch (PDOException) {
        throw new RuntimeException('Read-only PostgreSQL validation failed; check database permissions and installed schema.');
    }
}
