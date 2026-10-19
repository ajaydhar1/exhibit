<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/migrations.php';

/** A scripted PDO double: no driver, network, SQL execution, or credentials. */
function preflightConnection(array $results, bool $transaction = true): PDO
{
    return new class($results, $transaction) extends PDO {
        public function __construct(private array $results, private bool $transaction)
        {
        }

        public function inTransaction(): bool
        {
            return $this->transaction;
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            check(str_starts_with(ltrim($query), 'SELECT') || str_starts_with($query, 'SHOW'), 'Preflight attempted a non-read-only query.');
            check($this->results !== [], 'Preflight issued an unexpected query.');
            return new class(array_shift($this->results)) extends PDOStatement {
                public function __construct(private mixed $result)
                {
                }

                public function fetchColumn(int $column = 0): mixed
                {
                    return $this->result;
                }

                public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
                {
                    return $this->result;
                }
            };
        }
    };
}

function validatePreflightGuards(): void
{
    $permissions = ['database_connect' => true, 'schema_usage' => true, 'schema_create' => true, 'language_usage' => true];
    $valid = ['exhibit_expected', $permissions, 'off', false, 0, 0, 0];
    \Exhibit\Migrations\assertInitialTarget(preflightConnection($valid), 'exhibit_expected');
    $invalid = [
        [[], false, 'transaction'],
        [['different_database'], true, 'identity'],
        [['exhibit_expected', $permissions, 'on'], true, 'read-only'],
        [['exhibit_expected', $permissions, 'off', true], true, 'standby'],
    ];
    foreach (array_keys($permissions) as $permission) {
        $missing = array_replace($permissions, [$permission => false]);
        $invalid[] = [['exhibit_expected', $missing], true, $permission];
    }
    foreach ([4, 5, 6] as $index) {
        $objects = $valid;
        $objects[$index] = 1;
        $invalid[] = [$objects, true, 'already contain'];
    }
    foreach ($invalid as [$results, $transaction, $message]) {
        try {
            \Exhibit\Migrations\assertInitialTarget(preflightConnection($results, $transaction), 'exhibit_expected');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), $message), 'Unexpected preflight rejection.');
            continue;
        }
        throw new RuntimeException('Unsafe target accepted by preflight.');
    }
}
