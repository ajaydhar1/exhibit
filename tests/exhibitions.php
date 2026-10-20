<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../backend/exhibitions.php';

use Exhibit\Exhibitions\{Service, ValidationError};
use function Exhibit\Exhibitions\{exhibitionId, validate};

function rejectsExhibition(array $input, string $field): void
{
    try {
        validate($input);
    } catch (ValidationError $error) {
        check(isset($error->errors[$field]), 'Wrong invalid field reported.');
        return;
    }
    throw new RuntimeException('Invalid exhibition accepted.');
}

$tests = [];
$tests['Required title and exact Unicode length boundaries'] = static function (): void {
    foreach (['', " \r\n\t", [], "\xff", "invalid\0title"] as $title) {
        rejectsExhibition(['title' => $title], 'title');
    }
    $title = str_repeat("\xc3\xa9", 100);
    check(validate(['title' => ' ' . $title . ' '])['title'] === $title, 'Valid Unicode title rejected.');
    rejectsExhibition(['title' => $title . 'a'], 'title');
    check(validate(['title' => 'A', 'summary' => str_repeat('x', 320)])['summary'] === str_repeat('x', 320), 'Summary boundary rejected.');
    rejectsExhibition(['title' => 'A', 'summary' => str_repeat('x', 321)], 'summary');
};
$tests['Optional values, subject allowlist, and trusted field mapping'] = static function (): void {
    $values = validate(['title' => '  Idea  ', 'summary' => ' ', 'subject' => '', 'cover' => '',
        'owner_id' => 999, 'status' => 'published', 'slug' => 'forged']);
    check($values === ['title' => 'Idea', 'summary' => null, 'category' => null, 'cover' => null], 'Unexpected creation mapping.');
    foreach (Exhibit\Exhibitions\SUBJECTS as $subject) {
        check(validate(['title' => 'Idea', 'subject' => $subject])['category'] === $subject, 'Subject rejected.');
    }
    rejectsExhibition(['title' => 'Idea', 'subject' => 'Forged subject'], 'subject');
    foreach (['summary', 'subject', 'cover'] as $field) {
        rejectsExhibition(['title' => 'Idea', $field => ['unexpected']], $field);
    }
};
$tests['Cover URLs allow HTTP(S) only, without credentials or control characters'] = static function (): void {
    foreach (['https://example.test/image.jpg?size=large&v=1', 'http://example.test/image.jpg'] as $url) {
        check(validate(['title' => 'Idea', 'cover' => $url])['cover'] === ['url' => $url], 'Valid cover mapping failed.');
    }
    foreach (['javascript:alert(1)', 'data:image/svg+xml,test', '//example.test/image', 'ftp://example.test/image',
        'https://user:pass@example.test/image', "https://example.test/\r\nimage", 'not a URL',
        'https://example.test/' . str_repeat('x', 2048)] as $url) {
        rejectsExhibition(['title' => 'Idea', 'cover' => $url], 'cover');
    }
    $url = 'https://example.test/' . str_repeat('x', 2048 - strlen('https://example.test/'));
    check(validate(['title' => 'Idea', 'cover' => $url])['cover']['url'] === $url, 'Exact URL byte boundary rejected.');
};
$tests['IDs reject ambiguous, malformed, array, and overflowing input'] = static function (): void {
    check(exhibitionId('1') === 1 && exhibitionId((string) PHP_INT_MAX) === PHP_INT_MAX, 'Valid ID rejected.');
    foreach ([null, [], 1, '0', '-1', '01', '1.0', '1e2', ' 1', '1x', (string) PHP_INT_MAX . '0'] as $id) {
        check(exhibitionId($id) === null, 'Invalid ID accepted.');
    }
};

// Scripted PDO verifies SQL bindings and failures without a database connection.
function exhibitionConnection(array &$calls, array $results): PDO
{
    return new class($calls, $results) extends PDO {
        private array $calls;
        public function __construct(array &$calls, private array $results)
        {
            $this->calls =& $calls;
        }
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            return new class($this->calls, $query, $this->results) extends PDOStatement {
                private array $calls;
                public function __construct(array &$calls, private string $query, private array $results)
                {
                    $this->calls =& $calls;
                }
                public function execute(?array $params = null): bool
                {
                    $this->calls[] = [$this->query, $params];
                    if (($this->results[0] ?? null) instanceof Throwable) {
                        throw $this->results[0];
                    }
                    return true;
                }
                public function fetchColumn(int $column = 0): mixed
                {
                    return array_shift($this->results);
                }
                public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
                {
                    return array_shift($this->results);
                }
                public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
                {
                    return $this->results;
                }
            };
        }
    };
}
$tests['Creation binds trusted owner and draft state, retries slug conflicts only'] = static function (): void {
    $calls = [];
    $service = new Service(exhibitionConnection($calls, [false, '42']));
    check($service->createDraft(7, ['title' => "Creator's idea", 'owner_id' => 8, 'status' => 'published']) === 42, 'Creation did not return saved ID.');
    check(count($calls) === 2, 'Slug collision not retried.');
    foreach ($calls as [$sql, $params]) {
        check(str_contains($sql, 'ON CONFLICT ON CONSTRAINT exhibitions_slug_key DO NOTHING RETURNING id'), 'Conflict handling is too broad.');
        check(str_contains($sql, "'draft', NULL") && !str_contains($sql, "Creator's idea"), 'Draft state or binding failed.');
        check($params[0] === 7 && $params[2] === "Creator's idea" && $params[5] === null, 'Owner or metadata binding incorrect.');
        check(preg_match('/\Adraft-[a-f0-9]{32}\z/', $params[1]) === 1, 'Invalid generated slug.');
    }
    check($calls[0][1][1] !== $calls[1][1][1], 'Slug retry reused identifier.');
    $calls = [];
    try {
        (new Service(exhibitionConnection($calls, [false, false, false])))->createDraft(7, ['title' => 'Idea']);
        throw new LogicException('Exhausted retries reported success.');
    } catch (RuntimeException $error) {
        check(!$error instanceof LogicException && count($calls) === 3, 'Slug retries were not bounded.');
    }
};
$tests['Owned reads bind both identifiers and decode covers safely'] = static function (): void {
    $calls = [];
    $row = ['id' => '42', 'cover' => '{"url":"https://example.test/cover.jpg"}'];
    $service = new Service(exhibitionConnection($calls, [$row]));
    check($service->findOwned(42, 7)['cover']['url'] === 'https://example.test/cover.jpg', 'Cover decoding failed.');
    check($calls[0][1] === [42, 7] && str_contains($calls[0][0], 'id = ? AND owner_id = ?'), 'Read was not ownership scoped.');
    $calls = [];
    $service = new Service(exhibitionConnection($calls, [false]));
    check($service->findOwned(42, 8) === null, 'Missing record did not return null.');
    $calls = [];
    $service = new Service(exhibitionConnection($calls, [['id' => '42', 'cover' => null]]));
    check(count($service->listOwned(7)) === 1 && $calls[0][1] === [7], 'Listing not scoped.');
    check(str_contains($calls[0][0], 'e.owner_id = ? ORDER BY e.updated_at DESC, e.id DESC')
        && str_contains($calls[0][0], 'count(*)'), 'List ordering or chapter count missing.');
    $calls = [];
    try {
        (new Service(exhibitionConnection($calls, [['cover' => '{"url":"javascript:alert(1)"}']])))->findOwned(42, 7);
        throw new LogicException('Unsafe persisted cover accepted.');
    } catch (RuntimeException $error) {
        check(!$error instanceof LogicException, 'Unsafe persisted cover did not fail explicitly.');
    }
};
$tests['Invalid creation never executes SQL'] = static function (): void {
    $calls = [];
    try {
        (new Service(exhibitionConnection($calls, [])))->createDraft(7, ['title' => '']);
        throw new RuntimeException('Invalid draft accepted.');
    } catch (ValidationError) {
        check($calls === [], 'Invalid creation executed SQL.');
    }
};
$tests['Draft saving binds only metadata and atomically checks owner, draft and revision'] = static function (): void {
    $calls = [];
    $revision = str_repeat('a', 32);
    $service = new Service(exhibitionConnection($calls, ['2026-10-19 12:00:00+00']));
    $service->saveDraft(42, 7, ['title' => ' Edited ', 'summary' => ' Intro ', 'subject' => 'History',
        'cover' => 'https://example.test/a.jpg', 'revision' => $revision,
        'owner_id' => 8, 'status' => 'published', 'published_at' => '2026-01-01', 'chapters' => []]);
    [$sql, $params] = $calls[0];
    check($params === ['Edited', 'Intro', 'History', '{"url":"https:\/\/example.test\/a.jpg"}', 42, 7, $revision], 'Save binding incorrect.');
    check(str_contains($sql, "id = ? AND owner_id = ? AND status = 'draft' AND md5(updated_at::text) = ?")
        && !str_contains(explode('WHERE', $sql)[0], 'owner_id =')
        && !str_contains(explode('WHERE', $sql)[0], 'status =')
        && !str_contains($sql, 'INSERT'), 'Save modifies trusted fields or lacks atomic guards.');
    foreach ([null, [], 'invalid', str_repeat('a', 32)] as $version) {
        $calls = [];
        try {
            (new Service(exhibitionConnection($calls, [false])))->saveDraft(42, 7, ['title' => 'Idea', 'revision' => $version]);
            throw new LogicException('Rejected or stale revision reported success.');
        } catch (Exhibit\Exhibitions\SaveConflict) {
            check(count($calls) === ($version === str_repeat('a', 32) ? 1 : 0), 'Invalid revision executed SQL.');
        }
    }
    $calls = [];
    try {
        (new Service(exhibitionConnection($calls, [])))->saveDraft(42, 7, ['title' => '', 'revision' => $revision]);
        throw new LogicException('Invalid edit accepted.');
    } catch (ValidationError) {
        check($calls === [], 'Invalid edit executed SQL.');
    }
};
$tests['Database failures propagate rather than returning empty or success'] = static function (): void {
    foreach (['create', 'list', 'find', 'save'] as $operation) {
        $calls = [];
        $failure = new PDOException('test-only failure');
        $service = new Service(exhibitionConnection($calls, [$failure]));
        try {
            match ($operation) {
                'create' => $service->createDraft(7, ['title' => 'Idea']),
                'list' => $service->listOwned(7),
                'find' => $service->findOwned(42, 7),
                'save' => $service->saveDraft(42, 7, ['title' => 'Idea', 'revision' => str_repeat('a', 32)]),
            };
        } catch (PDOException $error) {
            check($error === $failure && count($calls) === 1, 'Database failure was swallowed or retried.');
            continue;
        }
        throw new RuntimeException('Database failure reported success.');
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: $name\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL: $name: " . $error->getMessage() . "\n";
    }
}
echo count($tests) . " exhibition unit tests, $failed failures (no database).\n";
exit($failed === 0 ? 0 : 1);
