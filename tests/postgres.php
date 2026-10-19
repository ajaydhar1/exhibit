<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../backend/database.php';
require_once __DIR__ . '/../backend/migrations.php';

if (array_slice($argv, 1) !== ['--run']) {
    fwrite(STDERR, "Usage: php tests/postgres.php --run\nRequires TEST_DATABASE_URL: an empty loopback database named exhibit_test_SUFFIX.\n");
    exit(2);
}

function executeTestSql(PDO $pdo, string $sql, array $parameters = []): PDOStatement
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}

function expectSqlState(PDO $pdo, string $state, string $sql, array $parameters = []): void
{
    $pdo->exec('SAVEPOINT expected_violation');
    try {
        executeTestSql($pdo, $sql, $parameters);
        throw new RuntimeException('Expected SQLSTATE ' . $state . ' was not raised.');
    } catch (PDOException $error) {
        check($error->getCode() === $state, 'Expected SQLSTATE ' . $state . ', received ' . $error->getCode());
    } finally {
        $pdo->exec('ROLLBACK TO SAVEPOINT expected_violation');
        $pdo->exec('RELEASE SAVEPOINT expected_violation');
    }
}

function fixtures(PDO $pdo): array
{
    $user = executeTestSql($pdo,
        "INSERT INTO public.users (email, password_hash, display_name)
         VALUES ('Creator@example.test', 'test-only-not-a-real-password-hash', 'Test creator') RETURNING id"
    )->fetchColumn();
    $exhibitions = [];
    foreach (['first-story', 'second-story'] as $slug) {
        $exhibitions[] = executeTestSql($pdo,
            'INSERT INTO public.exhibitions (owner_id, slug, title) VALUES (?, ?, ?) RETURNING id',
            [$user, $slug, 'Test exhibition']
        )->fetchColumn();
    }
    $chapters = [];
    foreach ([[$exhibitions[0], 1], [$exhibitions[0], 2], [$exhibitions[1], 1]] as [$parent, $position]) {
        $chapters[] = executeTestSql($pdo,
            'INSERT INTO public.chapters (exhibition_id, position, title) VALUES (?, ?, ?) RETURNING id',
            [$parent, $position, 'Test chapter']
        )->fetchColumn();
    }
    $blocks = [];
    foreach ([[$chapters[0], 1, 'text'], [$chapters[0], 2, 'figure'], [$chapters[1], 1, 'artifact']] as [$parent, $position, $type]) {
        $blocks[] = executeTestSql($pdo,
            "INSERT INTO public.content_blocks (chapter_id, position, type, content)
             VALUES (?, ?, ?, '{\"heading\":\"Test\",\"body\":\"Plain text\"}') RETURNING id",
            [$parent, $position, $type]
        )->fetchColumn();
    }
    return ['user' => $user, 'exhibitions' => $exhibitions, 'chapters' => $chapters, 'blocks' => $blocks];
}

$tests = [];
$tests['Four-table relationships and defaults'] = static function (PDO $pdo, array $f): void {
    check((int) $pdo->query(
        'SELECT count(*) FROM public.users u
         JOIN public.exhibitions e ON e.owner_id = u.id
         JOIN public.chapters c ON c.exhibition_id = e.id
         JOIN public.content_blocks b ON b.chapter_id = c.id'
    )->fetchColumn() === 3, 'Relationship join failed.');
    $row = executeTestSql($pdo, 'SELECT status, published_at, cover FROM public.exhibitions WHERE id = ?', [$f['exhibitions'][0]])->fetch();
    check($row === ['status' => 'draft', 'published_at' => null, 'cover' => null], 'Draft defaults differ.');
    check((int) $pdo->query("SELECT count(*) FROM information_schema.tables
        WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")->fetchColumn() === 4, 'Expected exactly four tables.');
};
$tests['Case-insensitive email uniqueness and required user fields'] = static function (PDO $pdo, array $f): void {
    expectSqlState($pdo, '23505', "INSERT INTO public.users (email, password_hash, display_name) VALUES ('creator@EXAMPLE.test', 'hash', 'Name')");
    foreach (['email', 'password_hash', 'display_name'] as $column) {
        expectSqlState($pdo, '23502', "UPDATE public.users SET $column = NULL WHERE id = ?", [$f['user']]);
        expectSqlState($pdo, '23514', "UPDATE public.users SET $column = '   ' WHERE id = ?", [$f['user']]);
    }
    expectSqlState($pdo, '23514', "UPDATE public.users SET email = ' spaced@example.test ' WHERE id = ?", [$f['user']]);
    expectSqlState($pdo, '428C9', "INSERT INTO public.users (id, email, password_hash, display_name) VALUES (999, 'explicit@example.test', 'hash', 'Name')");
};
$tests['Globally unique lowercase URL-safe slugs and required titles'] = static function (PDO $pdo, array $f): void {
    expectSqlState($pdo, '23505', 'UPDATE public.exhibitions SET slug = ? WHERE id = ?', ['first-story', $f['exhibitions'][1]]);
    foreach (['Uppercase', 'has spaces', '-leading', 'trailing-', 'double--hyphen', '', 'path/segment', 'under_score'] as $slug) {
        expectSqlState($pdo, '23514', 'UPDATE public.exhibitions SET slug = ? WHERE id = ?', [$slug, $f['exhibitions'][0]]);
    }
    foreach (['exhibitions' => $f['exhibitions'][0], 'chapters' => $f['chapters'][0]] as $table => $id) {
        expectSqlState($pdo, '23502', "UPDATE public.$table SET title = NULL WHERE id = ?", [$id]);
        expectSqlState($pdo, '23514', "UPDATE public.$table SET title = '  ' WHERE id = ?", [$id]);
    }
};
$tests['Foreign keys reject orphaned and null parents'] = static function (PDO $pdo, array $f): void {
    foreach ([
        ['exhibitions', 'owner_id', $f['exhibitions'][0]],
        ['chapters', 'exhibition_id', $f['chapters'][0]],
        ['content_blocks', 'chapter_id', $f['blocks'][0]],
    ] as [$table, $column, $id]) {
        expectSqlState($pdo, '23503', "UPDATE public.$table SET $column = -1 WHERE id = ?", [$id]);
        expectSqlState($pdo, '23502', "UPDATE public.$table SET $column = NULL WHERE id = ?", [$id]);
    }
};
$tests['Parent-scoped unique positive ordering'] = static function (PDO $pdo, array $f): void {
    foreach (['chapters' => $f['chapters'][0], 'content_blocks' => $f['blocks'][0]] as $table => $id) {
        foreach ([0, -1] as $position) {
            expectSqlState($pdo, '23514', "UPDATE public.$table SET position = ? WHERE id = ?", [$position, $id]);
        }
        expectSqlState($pdo, '23502', "UPDATE public.$table SET position = NULL WHERE id = ?", [$id]);
        expectSqlState($pdo, '23505', "UPDATE public.$table SET position = 2 WHERE id = ?", [$id]);
    }
    check((int) $pdo->query('SELECT count(*) FROM public.chapters WHERE position = 1')->fetchColumn() === 2, 'Chapter positions must be parent-scoped.');
    check((int) $pdo->query('SELECT count(*) FROM public.content_blocks WHERE position = 1')->fetchColumn() === 2, 'Block positions must be parent-scoped.');
};
$tests['Deferred chapter and block reordering'] = static function (PDO $pdo, array $f): void {
    foreach ([
        ['chapters', 'chapters_exhibition_position_unique', $f['chapters'][0], $f['chapters'][1]],
        ['content_blocks', 'content_blocks_chapter_position_unique', $f['blocks'][0], $f['blocks'][1]],
    ] as [$table, $constraint, $first, $second]) {
        $pdo->exec("SET CONSTRAINTS $constraint DEFERRED");
        executeTestSql($pdo, "UPDATE public.$table SET position = 2 WHERE id = ?", [$first]);
        executeTestSql($pdo, "UPDATE public.$table SET position = 1 WHERE id = ?", [$second]);
        $pdo->exec("SET CONSTRAINTS $constraint IMMEDIATE");
        $positions = executeTestSql($pdo, "SELECT position FROM public.$table WHERE id IN (?, ?) ORDER BY id", [$first, $second])->fetchAll(PDO::FETCH_COLUMN);
        check($positions === [2, 1], 'Deferred reorder failed.');
        $pdo->exec('SAVEPOINT invalid_reorder');
        $pdo->exec("SET CONSTRAINTS $constraint DEFERRED");
        executeTestSql($pdo, "UPDATE public.$table SET position = 1 WHERE id = ?", [$first]);
        expectSqlState($pdo, '23505', "SET CONSTRAINTS $constraint IMMEDIATE");
        $pdo->exec('ROLLBACK TO SAVEPOINT invalid_reorder');
        $pdo->exec('RELEASE SAVEPOINT invalid_reorder');
    }
};
$tests['Draft/published transitions and invalid publication states'] = static function (PDO $pdo, array $f): void {
    $id = $f['exhibitions'][0];
    expectSqlState($pdo, '23514', "UPDATE public.exhibitions SET status = 'published' WHERE id = ?", [$id]);
    expectSqlState($pdo, '23514', 'UPDATE public.exhibitions SET published_at = CURRENT_TIMESTAMP WHERE id = ?', [$id]);
    expectSqlState($pdo, '23514', "UPDATE public.exhibitions SET status = 'archived' WHERE id = ?", [$id]);
    expectSqlState($pdo, '23502', 'UPDATE public.exhibitions SET status = NULL WHERE id = ?', [$id]);
    executeTestSql($pdo, "UPDATE public.exhibitions SET status = 'published', published_at = CURRENT_TIMESTAMP WHERE id = ?", [$id]);
    check(executeTestSql($pdo, 'SELECT published_at IS NOT NULL FROM public.exhibitions WHERE id = ?', [$id])->fetchColumn() === true, 'Publication failed.');
    expectSqlState($pdo, '23514', "UPDATE public.exhibitions SET status = 'draft' WHERE id = ?", [$id]);
    executeTestSql($pdo, "UPDATE public.exhibitions SET status = 'draft', published_at = NULL WHERE id = ?", [$id]);
};
$tests['JSON objects and allowlisted block types'] = static function (PDO $pdo, array $f): void {
    foreach (['[]', '"string"', '42', 'true', 'null'] as $json) {
        expectSqlState($pdo, '23514', 'UPDATE public.exhibitions SET cover = CAST(? AS jsonb) WHERE id = ?', [$json, $f['exhibitions'][0]]);
        expectSqlState($pdo, '23514', 'UPDATE public.content_blocks SET content = CAST(? AS jsonb) WHERE id = ?', [$json, $f['blocks'][0]]);
    }
    executeTestSql($pdo, "UPDATE public.exhibitions SET cover = '{\"url\":\"https://example.test/image.jpg\"}' WHERE id = ?", [$f['exhibitions'][0]]);
    expectSqlState($pdo, '23502', 'UPDATE public.content_blocks SET content = NULL WHERE id = ?', [$f['blocks'][0]]);
    expectSqlState($pdo, '23502', 'UPDATE public.content_blocks SET type = NULL WHERE id = ?', [$f['blocks'][0]]);
    foreach (['html', 'svg', 'php', 'Text', ''] as $type) {
        expectSqlState($pdo, '23514', 'UPDATE public.content_blocks SET type = ? WHERE id = ?', [$type, $f['blocks'][0]]);
    }
    check($pdo->query('SELECT DISTINCT type FROM public.content_blocks ORDER BY type')->fetchAll(PDO::FETCH_COLUMN) === ['artifact', 'figure', 'text'], 'Valid block types missing.');
};
$tests['User deletion restricted while owned exhibitions exist'] = static function (PDO $pdo, array $f): void {
    expectSqlState($pdo, '23001', 'DELETE FROM public.users WHERE id = ?', [$f['user']]);
    check(executeTestSql($pdo, 'SELECT id FROM public.users WHERE id = ?', [$f['user']])->fetchColumn() !== false,
        'Restricted deletion must retain the owner.');
    check((int) executeTestSql($pdo, 'SELECT count(*) FROM public.exhibitions WHERE owner_id = ?', [$f['user']])->fetchColumn() === 2,
        'Restricted deletion must retain owned exhibitions.');
    executeTestSql($pdo, 'DELETE FROM public.exhibitions WHERE owner_id = ?', [$f['user']]);
    check(executeTestSql($pdo, 'DELETE FROM public.users WHERE id = ?', [$f['user']])->rowCount() === 1, 'Unreferenced user should be deletable.');
};
$tests['Chapter deletion cascades only to its blocks'] = static function (PDO $pdo, array $f): void {
    executeTestSql($pdo, 'DELETE FROM public.chapters WHERE id = ?', [$f['chapters'][0]]);
    check((int) $pdo->query('SELECT count(*) FROM public.content_blocks')->fetchColumn() === 1, 'Chapter cascade failed.');
    check((int) $pdo->query('SELECT count(*) FROM public.chapters')->fetchColumn() === 2, 'Sibling chapters were removed.');
    check((int) $pdo->query('SELECT count(*) FROM public.exhibitions')->fetchColumn() === 2, 'Parent exhibitions were removed.');
};
$tests['Exhibition deletion cascades through chapters and blocks, not owners or siblings'] = static function (PDO $pdo, array $f): void {
    executeTestSql($pdo, 'DELETE FROM public.exhibitions WHERE id = ?', [$f['exhibitions'][0]]);
    check((int) $pdo->query('SELECT count(*) FROM public.chapters')->fetchColumn() === 1, 'Exhibition chapter cascade failed.');
    check((int) $pdo->query('SELECT count(*) FROM public.content_blocks')->fetchColumn() === 0, 'Exhibition block cascade failed.');
    check((int) $pdo->query('SELECT count(*) FROM public.exhibitions')->fetchColumn() === 1, 'Sibling exhibition removed.');
    check((int) $pdo->query('SELECT count(*) FROM public.users')->fetchColumn() === 1, 'Owner was removed.');
};
$tests['Created timestamps retained and updated timestamps maintained on every table'] = static function (PDO $pdo, array $f): void {
    foreach ([
        'users' => $f['user'], 'exhibitions' => $f['exhibitions'][0],
        'chapters' => $f['chapters'][0], 'content_blocks' => $f['blocks'][0],
    ] as $table => $id) {
        executeTestSql($pdo, "UPDATE public.$table SET created_at = '2000-01-01T00:00:00Z', updated_at = '2000-01-01T00:00:00Z' WHERE id = ?", [$id]);
        $row = executeTestSql($pdo,
            "SELECT created_at = '2000-01-01T00:00:00Z'::timestamptz AS created_unchanged,
             updated_at > '2000-01-01T00:00:00Z'::timestamptz AS updated_maintained
             FROM public.$table WHERE id = ?", [$id]
        )->fetch();
        check($row === ['created_unchanged' => true, 'updated_maintained' => true], 'Timestamp behavior failed: ' . $table);
    }
    check($pdo->query('SHOW timezone')->fetchColumn() === 'UTC', 'Session timezone must be UTC.');
    $ownerTimestamp = executeTestSql($pdo, 'SELECT updated_at FROM public.exhibitions WHERE id = ?', [$f['exhibitions'][0]])->fetchColumn();
    executeTestSql($pdo, "UPDATE public.chapters SET title = 'Changed child' WHERE id = ?", [$f['chapters'][0]]);
    check(executeTestSql($pdo, 'SELECT updated_at FROM public.exhibitions WHERE id = ?', [$f['exhibitions'][0]])->fetchColumn() === $ownerTimestamp, 'Child changes unexpectedly touched the parent.');
};
$tests['Manual installer rejects reruns without modifying schema'] = static function (PDO $pdo, array $f): void {
    try {
        \Exhibit\Migrations\installInitialSchema($pdo, $pdo->query('SELECT current_database()')->fetchColumn());
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'already contain'), 'Unexpected rerun failure.');
        return;
    }
    throw new RuntimeException('Installer accepted an already-initialized schema.');
};

$pdo = null;
$failed = 0;
try {
    $environment = testEnvironment([
        'TEST_DATABASE_URL' => getenv('TEST_DATABASE_URL'),
        'TEST_DB_SSLMODE' => getenv('TEST_DB_SSLMODE'),
        'TEST_DB_SSLROOTCERT' => getenv('TEST_DB_SSLROOTCERT'),
    ]);
    \Exhibit\Database\configuration($environment);
    $database = substr(parse_url($environment['DATABASE_URL'], PHP_URL_PATH), 1);
    $pdo = \Exhibit\Database\connect($environment);
    $pdo->beginTransaction();
    try {
        \Exhibit\Migrations\installInitialSchema($pdo, $database . '_wrong_target');
        throw new RuntimeException('Installer accepted a mismatched target.');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'identity'), 'Unexpected target guard failure.');
    }
    \Exhibit\Migrations\installInitialSchema($pdo, $database);
    foreach ($tests as $name => $test) {
        $pdo->exec('SAVEPOINT test_case');
        try {
            $test($pdo, fixtures($pdo));
            echo "PASS: $name\n";
        } catch (Throwable $error) {
            $failed++;
            $message = $error instanceof PDOException ? 'Unexpected PostgreSQL error (SQLSTATE ' . $error->getCode() . ').' : $error->getMessage();
            fwrite(STDERR, "FAIL: $name: $message\n");
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT test_case');
            $pdo->exec('RELEASE SAVEPOINT test_case');
        }
    }
    $pdo->rollBack();
    check($pdo->query("SELECT to_regclass('public.users') IS NULL
        AND to_regclass('public.exhibitions') IS NULL
        AND to_regclass('public.chapters') IS NULL
        AND to_regclass('public.content_blocks') IS NULL
        AND to_regprocedure('public.exhibit_set_updated_at()') IS NULL")->fetchColumn() === true, 'Schema rollback left objects behind.');
    echo "PASS: Atomic schema rollback; no test rows or migration objects retained.\n";
    $pdo->beginTransaction();
    \Exhibit\Migrations\installInitialSchema($pdo, $database);
    $pdo->rollBack();
    echo "PASS: Migration can be repeated after full rollback.\n";
    echo (count($tests) + 3) . " PostgreSQL checks, $failed failures.\n";
} catch (Throwable $error) {
    $failed++;
    $message = $error instanceof PDOException ? 'PostgreSQL setup or rollback failed; inspect the disposable database.' : $error->getMessage();
    fwrite(STDERR, "FAIL: $message\n");
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
exit($failed === 0 ? 0 : 1);
