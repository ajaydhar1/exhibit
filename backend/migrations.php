<?php
declare(strict_types=1);

namespace Exhibit\Migrations;

use PDO;
use RuntimeException;

function initialSql(): string
{
    $sql = file_get_contents(__DIR__ . '/../database/migrations/001_initial.sql');
    if ($sql === false) {
        throw new RuntimeException('The initial Exhibit migration could not be read.');
    }
    return $sql;
}

function assertInitialTarget(PDO $pdo, string $expectedDatabase): void
{
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('An explicit transaction is required for schema installation.');
    }
    if ($expectedDatabase === '' || $pdo->query('SELECT current_database()')->fetchColumn() !== $expectedDatabase) {
        throw new RuntimeException('Database identity does not match the explicitly confirmed target.');
    }
    $permissions = $pdo->query(
        "SELECT has_database_privilege(current_database(), 'CONNECT') AS database_connect,
                has_schema_privilege('public', 'USAGE') AS schema_usage,
                has_schema_privilege('public', 'CREATE') AS schema_create,
                has_language_privilege('plpgsql', 'USAGE') AS language_usage"
    )->fetch();
    foreach (['database_connect', 'schema_usage', 'schema_create', 'language_usage'] as $permission) {
        if (($permissions[$permission] ?? false) !== true) {
            throw new RuntimeException('Initial migration refused: required privilege is missing: ' . $permission . '.');
        }
    }
    if ($pdo->query('SHOW default_transaction_read_only')->fetchColumn() !== 'off'
        || $pdo->query('SELECT pg_is_in_recovery()')->fetchColumn() !== false
    ) {
        throw new RuntimeException('Initial migration refused: target is read-only or a standby.');
    }

    $relations = $pdo->query(
        "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'"
    )->fetchColumn();
    $functions = $pdo->query(
        "SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
         WHERE n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'"
    )->fetchColumn();
    $types = $pdo->query(
        "SELECT count(*) FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
         WHERE n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'"
    )->fetchColumn();
    if ((int) $relations !== 0 || (int) $functions !== 0 || (int) $types !== 0) {
        throw new RuntimeException('Initial migration refused: user schemas already contain relations, routines, or types. Inspect existing schema; never drop or rerun blindly.');
    }
}

/**
 * The caller owns the transaction, so tests can roll back the entire schema.
 */
function installInitialSchema(PDO $pdo, string $expectedDatabase): void
{
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('An explicit transaction is required for schema installation.');
    }
    $pdo->exec("SET LOCAL lock_timeout = '5s'");
    $pdo->exec("SET LOCAL statement_timeout = '30s'");
    $pdo->query('SELECT pg_advisory_xact_lock(194872611, 1)');
    assertInitialTarget($pdo, $expectedDatabase);
    $pdo->exec(initialSql());
}
