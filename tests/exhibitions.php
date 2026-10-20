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

function studioConnection(array &$events, array $responses): PDO
{
    return new class($events, $responses) extends PDO {
        private array $events;
        private bool $active = false;
        public function __construct(array &$events, private array $responses) { $this->events =& $events; }
        public function beginTransaction(): bool { $this->events[] = ['begin']; return $this->active = true; }
        public function inTransaction(): bool { return $this->active; }
        public function commit(): bool { $this->events[] = ['commit']; $this->active = false; return true; }
        public function rollBack(): bool { $this->events[] = ['rollback']; $this->active = false; return true; }
        public function exec(string $statement): int|false { $this->events[] = [$statement]; return 0; }
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $next = function () use ($query): mixed {
                check($this->responses !== [], 'Unexpected SQL statement: ' . $query);
                return array_shift($this->responses);
            };
            return new class($this->events, $query, $next) extends PDOStatement {
                private array $events;
                private mixed $response;
                public function __construct(array &$events, private string $query, private Closure $next) { $this->events =& $events; }
                public function execute(?array $params = null): bool
                {
                    $this->events[] = [$this->query, $params];
                    $this->response = ($this->next)();
                    if ($this->response instanceof Throwable) throw $this->response;
                    return true;
                }
                public function fetchColumn(int $column = 0): mixed { return $this->response; }
                public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->response; }
                public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->response; }
            };
        }
    };
}

function contentFixture(): array
{
    return [['id' => '11', 'title' => 'First', 'era' => 'An era', 'intro' => 'Opening', 'blocks' => [
        ['id' => '21', 'type' => 'text', 'content' => ['heading' => 'Heading', 'body' => 'Body']],
        ['id' => '22', 'type' => 'artifact', 'content' => ['heading' => 'Artifact', 'body' => 'Context']],
    ]]];
}

$tests['Studio validates exact content shapes, lengths, URLs and duplicate IDs before SQL'] = static function (): void {
    $fixture = contentFixture();
    $validated = Exhibit\Exhibitions\validateContent(json_encode($fixture, JSON_THROW_ON_ERROR));
    check($validated[0]['id'] === 11 && $validated[0]['blocks'][1]['id'] === 22, 'Content identifiers were not normalized.');
    $invalid = ['{}', 'null', '[', str_repeat('x', 1048577), '[{"title":"X","blocks":{}}]',
        '[{"title":"X","blocks":[{"type":"text","content":[]}]}]'];
    $invalid[] = '[{"title":"' . "\xff" . '","blocks":[]}]';
    foreach (['title' => '', 'era' => str_repeat('x', 101), 'intro' => "\0", 'id' => '01'] as $field => $value) {
        $copy = $fixture;
        $copy[0][$field] = $value;
        $invalid[] = json_encode($copy, JSON_THROW_ON_ERROR);
    }
    $copy = $fixture;
    $copy[0]['blocks'][0]['content']['body'] = str_repeat('x', 2001);
    $invalid[] = json_encode($copy);
    $copy = $fixture;
    $copy[0]['blocks'][0]['content']['html'] = '<script>bad</script>';
    $invalid[] = json_encode($copy);
    $invalid[] = json_encode([$fixture[0], $fixture[0]]);
    $copy = $fixture;
    $copy[0]['blocks'][1]['id'] = '21';
    $invalid[] = json_encode($copy);
    $copy = $fixture;
    $copy[0]['blocks'][0] = ['type' => 'figure', 'content' => ['url' => 'javascript:alert(1)']];
    $invalid[] = json_encode($copy);
    $copy[0]['blocks'][0]['type'] = 'video';
    $invalid[] = json_encode($copy);
    foreach ($invalid as $json) {
        $calls = [];
        try {
            (new Service(exhibitionConnection($calls, [])))->saveDraft(42, 7, [
                'title' => 'Idea', 'revision' => str_repeat('a', 32), 'chapters_json' => $json]);
            throw new LogicException('Invalid content accepted.');
        } catch (ValidationError $error) {
            check(isset($error->errors['content']) && $calls === [], 'Invalid content executed SQL or lacks feedback.');
        }
    }
    $fixture[0]['title'] = str_repeat("\xc3\xa9", 110);
    $fixture[0]['intro'] = str_repeat('x', 2000);
    $fixture[0]['blocks'][0]['content'] = ['heading' => str_repeat('x', 130), 'body' => str_repeat('x', 2000)];
    check(count(Exhibit\Exhibitions\validateContent(json_encode($fixture))) === 1, 'Exact content boundaries rejected.');
};

$tests['Studio persists ordered content with stable IDs and an atomic owner/version parent update'] = static function (): void {
    $events = [];
    $fixture = contentFixture();
    $fixture[0]['blocks'] = array_reverse($fixture[0]['blocks']);
    $fixture[] = ['id' => null, 'title' => 'New chapter', 'era' => '', 'intro' => '', 'blocks' => []];
    $service = new Service(studioConnection($events, [
        'new timestamp', [['id' => '11']], [['id' => '21', 'chapter_id' => '11'], ['id' => '22', 'chapter_id' => '11']],
        true, true, true, '12',
    ]));
    $service->saveDraft(42, 7, ['title' => 'Idea', 'subtitle' => 'Subtitle', 'description' => 'Description',
        'revision' => str_repeat('a', 32), 'chapters_json' => json_encode($fixture)]);
    check($events[0] === ['begin'] && end($events) === ['commit'], 'Content save is not transactional.');
    check(str_contains($events[1][0], "owner_id = ? AND status = 'draft' AND md5(updated_at::text) = ?")
        && $events[1][1] === ['Idea', null, null, null, 'Subtitle', 'Description', 42, 7, str_repeat('a', 32)],
        'Metadata or atomic ownership/version guard lost.');
    check(str_contains($events[4][0], 'DEFERRED'), 'Ordering constraints are not deferred.');
    check($events[5][1] === [1, 'First', 'An era', 'Opening', 11, 42], 'Chapter identity/position binding incorrect.');
    check($events[6][1] === [1, 'artifact', '{"heading":"Artifact","body":"Context"}', 22, 11]
        && $events[7][1][0] === 2 && $events[7][1][3] === 21, 'Reordering did not preserve block IDs.');
    check($events[8][1][0] === 42 && $events[8][1][1] === 2, 'New chapter parent or order incorrect.');
};

$tests['Studio rolls back stale revisions, foreign children, cross-chapter blocks and database failures'] = static function (): void {
    $fixture = contentFixture();
    foreach ([
        [false],
        ['timestamp', [], []],
        ['timestamp', [['id' => '11']], [['id' => '21', 'chapter_id' => '99'], ['id' => '22', 'chapter_id' => '11']]],
        ['timestamp', [['id' => '11']], [['id' => '21', 'chapter_id' => '11'], ['id' => '22', 'chapter_id' => '11']], new PDOException('injected failure')],
    ] as $responses) {
        $events = [];
        try {
            (new Service(studioConnection($events, $responses)))->saveDraft(42, 7, [
                'title' => 'Idea', 'revision' => str_repeat('a', 32), 'chapters_json' => json_encode($fixture)]);
            throw new LogicException('Invalid content save succeeded.');
        } catch (Exhibit\Exhibitions\SaveConflict|ValidationError|PDOException) {
            check($events[0] === ['begin'] && end($events) === ['rollback']
                && !in_array(['commit'], $events, true), 'Failed save was not rolled back.');
        }
    }
};

$tests['Studio subtitle and description preserve validation and exact Unicode limits'] = static function (): void {
    foreach (['subtitle' => 100, 'description' => 10000] as $field => $limit) {
        $text = str_repeat("\xc3\xa9", $limit);
        check(Exhibit\Exhibitions\studioText($text, $limit, $field) === $text, 'Exact metadata limit rejected.');
        foreach ([$text . 'x', [], "\xff", "bad\0text"] as $invalid) {
            $calls = [];
            try {
                (new Service(exhibitionConnection($calls, [])))->saveDraft(42, 7, [
                    'title' => 'Idea', $field => $invalid, 'revision' => str_repeat('a', 32)]);
                throw new LogicException('Invalid metadata accepted.');
            } catch (ValidationError $error) {
                check(isset($error->errors[$field]) && $calls === [], 'Invalid metadata executed SQL or lost field error.');
            }
        }
    }
};

$tests['Studio removes omitted chapters inside the same transaction without recreating content'] = static function (): void {
    $events = [];
    $service = new Service(studioConnection($events, ['timestamp', [['id' => '11'], ['id' => '12']],
        [['id' => '21', 'chapter_id' => '11']], true, true]));
    $service->saveDraft(42, 7, ['title' => 'Idea', 'revision' => str_repeat('a', 32), 'chapters_json' => '[]']);
    check($events[5][1] === [11, 42] && $events[6][1] === [12, 42]
        && str_starts_with($events[5][0], 'DELETE FROM public.chapters')
        && end($events) === ['commit'], 'Chapter removal did not use scoped transactional deletion.');
};

$tests['Studio ordered reads use one owner-scoped snapshot and decode saved content'] = static function (): void {
    $calls = [];
    $fixture = contentFixture();
    $row = ['id' => '42', 'cover' => null, 'chapters' => json_encode($fixture)];
    $saved = (new Service(exhibitionConnection($calls, [$row])))->findOwned(42, 7, true);
    check($saved['chapters'] === $fixture && count($calls) === 1 && $calls[0][1] === [42, 7]
        && str_contains($calls[0][0], 'ORDER BY c.position') && str_contains($calls[0][0], 'ORDER BY b.position'),
        'Studio loading lost ordering, ownership or snapshot consistency.');
};

$tests['Publication requires saved title and meaningful chapter content, not optional metadata'] = static function (): void {
    $chapter = ['title' => 'Chapter', 'era' => '', 'intro' => '', 'blocks' => []];
    foreach ([[], [$chapter], [array_replace($chapter, ['intro' => " \n\t\u{00a0}\u{200b}"])]] as $chapters) {
        try {
            Exhibit\Exhibitions\publicationReady(['title' => 'Title', 'chapters' => $chapters]);
            throw new LogicException('Empty exhibition accepted.');
        } catch (ValidationError $error) {
            check(isset($error->errors['content']), 'Readiness did not report missing content.');
        }
    }
    foreach ([
        array_replace($chapter, ['intro' => 'Opening']),
        array_replace($chapter, ['blocks' => [['type' => 'text', 'content' => ['body' => 'Narrative']]]]),
        array_replace($chapter, ['blocks' => [['type' => 'artifact', 'content' => ['heading' => 'Artifact']]]]),
        array_replace($chapter, ['blocks' => [['type' => 'figure', 'content' => ['url' => 'https://example.test/image.jpg']]]]),
    ] as $content) {
        Exhibit\Exhibitions\publicationReady(['title' => 'Title', 'chapters' => [$content]]);
    }
    try {
        Exhibit\Exhibitions\publicationReady(['title' => '', 'chapters' => [array_replace($chapter, ['intro' => 'Opening'])]]);
        throw new LogicException('Empty title accepted.');
    } catch (ValidationError $error) {
        check(isset($error->errors['title']), 'Readiness lost title validation.');
    }
};

$tests['Public reads enforce publication in SQL with ordered content and no account data'] = static function (): void {
    $calls = [];
    $row = ['id' => '42', 'title' => 'Public title', 'cover' => null, 'chapters' => json_encode(contentFixture())];
    $result = (new Service(exhibitionConnection($calls, [$row])))->findPublished(42);
    check($result['chapters'] === contentFixture() && $calls[0][1] === [42], 'Public read lost content or binding.');
    $sql = $calls[0][0];
    check(str_contains($sql, "status = 'published' AND published_at IS NOT NULL")
        && str_contains($sql, 'ORDER BY c.position') && str_contains($sql, 'ORDER BY b.position')
        && !str_contains($sql, 'owner_id') && !str_contains($sql, 'users'), 'Public query lost privacy or ordering.');
    $calls = [];
    check((new Service(exhibitionConnection($calls, [false])))->findPublished(42) === null, 'Hidden publication returned data.');
    $invalid = contentFixture();
    $invalid[0]['blocks'][0] = ['type' => 'figure', 'content' => ['url' => 'javascript:alert(1)']];
    $calls = [];
    try {
        (new Service(exhibitionConnection($calls, [array_replace($row, ['chapters' => json_encode($invalid)])])))->findPublished(42);
        throw new LogicException('Unsafe persisted figure accepted.');
    } catch (ValidationError) {}
};

$tests['Publishing locks the owner row, validates the saved revision and changes publication state only'] = static function (): void {
    $row = ['id' => '42', 'title' => 'Title', 'cover' => null, 'updated_at' => 'saved timestamp',
        'chapters' => json_encode(contentFixture())];
    $revision = md5($row['updated_at']);
    $events = [];
    (new Service(studioConnection($events, ['draft', $row, '42'])))->publish(42, 7, $revision);
    check($events[0] === ['begin'] && end($events) === ['commit'], 'Publishing is not transactional.');
    check(str_contains($events[1][0], 'owner_id = ? FOR UPDATE') && $events[1][1] === [42, 7], 'Publish lock not owner scoped.');
    check(str_contains($events[3][0], "status = 'published', published_at = CURRENT_TIMESTAMP")
        && str_contains($events[3][0], "status = 'draft' AND md5(updated_at::text) = ?")
        && $events[3][1] === [42, 7, $revision] && count($events) === 5, 'Publish changed content or lost final revision predicate.');
    $events = [];
    (new Service(studioConnection($events, ['published'])))->publish(42, 7, $revision);
    check(count($events) === 3 && end($events) === ['commit'], 'Duplicate publish changed timestamp or content.');
    foreach ([null, [], '', 'invalid'] as $version) {
        $events = [];
        try {
            (new Service(studioConnection($events, [])))->publish(42, 7, $version);
            throw new LogicException('Malformed publication revision accepted.');
        } catch (Exhibit\Exhibitions\SaveConflict) {
            check($events === [], 'Malformed revision reached SQL.');
        }
    }
    foreach ([
        [false], ['draft', array_replace($row, ['updated_at' => 'newer'])],
        ['draft', array_replace($row, ['chapters' => '[]'])],
        ['draft', $row, new PDOException('test-only publish failure')],
        ['draft', $row, false],
    ] as $responses) {
        $events = [];
        try {
            (new Service(studioConnection($events, $responses)))->publish(42, 7, $revision);
            throw new LogicException('Invalid publication reported success.');
        } catch (Exhibit\Exhibitions\SaveConflict|ValidationError|PDOException) {
            check(end($events) === ['rollback'] && !in_array(['commit'], $events, true), 'Publication failed without rollback.');
        }
    }
};

$tests['Persisted Unicode content is not rejected by the request JSON byte limit after encoding'] = static function (): void {
    $chapters = [['id' => '11', 'title' => 'Unicode chapter', 'era' => '', 'intro' => '', 'blocks' => []]];
    for ($index = 1; $index <= 100; $index++) {
        $chapters[0]['blocks'][] = ['id' => (string) $index, 'type' => 'text',
            'content' => ['heading' => '', 'body' => str_repeat("\xc3\xa9", 1900)]];
    }
    $request = json_encode($chapters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stored = json_encode($chapters, JSON_THROW_ON_ERROR);
    check(strlen($request) < 1048576 && strlen($stored) > 1048576, 'Byte expansion fixture is not exercising the boundary.');
    Exhibit\Exhibitions\validateContent($request);
    $calls = [];
    $row = ['id' => '42', 'title' => 'Title', 'cover' => null, 'updated_at' => 'timestamp', 'chapters' => $stored];
    check((new Service(exhibitionConnection($calls, [$row])))->findPublished(42)['chapters'] === $chapters,
        'Valid saved Unicode content could not be viewed.');
    $events = [];
    (new Service(studioConnection($events, ['draft', $row, '42'])))->publish(42, 7, md5('timestamp'));
    check(end($events) === ['commit'], 'Valid saved Unicode content could not be published.');
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
