<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function validateLiveDatabase(): void
{
    $pdo = \Exhibit\Database\connect();
    try {
        $pdo->exec('BEGIN READ ONLY');
        check($pdo->query('SELECT 1')->fetchColumn() === 1, 'Connection query failed.');
        check($pdo->query('SHOW transaction_read_only')->fetchColumn() === 'on', 'Validation must be read-only.');
        if (getenv('DB_SSLMODE') !== 'disable') {
            check($pdo->query('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')->fetchColumn() === true, 'TLS is not active.');
        }
        $statement = $pdo->query(
            "SELECT table_name, column_name, data_type, is_nullable, is_identity, identity_generation, column_default
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name IN ('users', 'exhibitions', 'chapters', 'content_blocks')
             ORDER BY table_name, ordinal_position"
        );
        $actual = [];
        foreach ($statement as $column) {
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
             WHERE n.nspname = 'public' AND t.relname IN ('users', 'exhibitions', 'chapters', 'content_blocks')"
        )->fetchAll(PDO::FETCH_UNIQUE);
        foreach (['users', 'exhibitions', 'chapters', 'content_blocks'] as $table) {
            check(($constraints[$table . '_pkey']['contype'] ?? '') === 'p', 'Primary key missing: ' . $table);
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
        $indexes = $pdo->query(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public'
             AND indexname IN ('users_email_lower_unique', 'exhibitions_owner_id_index')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        check(str_contains($indexes['users_email_lower_unique'] ?? '', 'UNIQUE INDEX')
            && str_contains($indexes['users_email_lower_unique'] ?? '', '(lower(email))'), 'Case-insensitive email index missing.');
        check(str_contains($indexes['exhibitions_owner_id_index'] ?? '', '(owner_id)'), 'Owner index missing.');
        $triggers = $pdo->query(
            "SELECT t.relname, pg_get_triggerdef(g.oid) AS definition
             FROM pg_trigger g JOIN pg_class t ON t.oid = g.tgrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE n.nspname = 'public' AND NOT g.tgisinternal
             AND g.tgname = t.relname || '_set_updated_at'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (array_keys(expectedColumns()) as $table) {
            check(str_contains($triggers[$table] ?? '', 'BEFORE UPDATE')
                && str_contains($triggers[$table] ?? '', 'exhibit_set_updated_at()'), 'Update trigger missing: ' . $table);
        }
    } catch (PDOException) {
        throw new RuntimeException('Read-only PostgreSQL validation failed; check database permissions and installed schema.');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
