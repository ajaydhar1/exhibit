<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/exhibitions.php';

function runExhibitionPostgres(PDO $db): void
{
    $service = new Exhibit\Exhibitions\Service($db);
    $insert = $db->prepare(
        "INSERT INTO public.users (email, display_name, password_hash, email_verified_at)
         VALUES (?, 'Exhibition test creator', ?, CURRENT_TIMESTAMP) RETURNING id"
    );
    $insert->execute(['draft-owner@example.test', password_hash('draft-test-password-123', PASSWORD_DEFAULT)]);
    $owner = (int) $insert->fetchColumn();
    $insert->execute(['draft-other@example.test', password_hash('draft-test-password-123', PASSWORD_DEFAULT)]);
    $other = (int) $insert->fetchColumn();
    check($service->listOwned($owner) === [], 'New creator has sample exhibitions.');
    $children = $db->query('SELECT (SELECT count(*) FROM public.chapters) + (SELECT count(*) FROM public.content_blocks)')->fetchColumn();
    $input = [
        'title' => '  A persisted idea  ', 'summary' => " First line\nSecond line ",
        'subject' => 'Arts & Culture', 'cover' => 'https://example.test/cover.jpg?x=1&y=2',
        'owner_id' => $other, 'status' => 'published', 'published_at' => '2026-01-01',
    ];
    $id = $service->createDraft($owner, $input);
    $second = $service->createDraft($owner, ['title' => 'A persisted idea']);
    $row = $service->findOwned($id, $owner);
    check($row !== null && $row['title'] === 'A persisted idea' && $row['summary'] === "First line\nSecond line"
        && $row['category'] === 'Arts & Culture' && $row['cover'] === ['url' => $input['cover']]
        && $row['status'] === 'draft' && $row['created_at'] !== '' && $row['updated_at'] !== '', 'Draft metadata failed round trip.');
    check($service->findOwned($id, $other) === null && $service->listOwned($other) === [], 'Foreign creator can read draft.');
    check($service->findOwned(PHP_INT_MAX, $owner) === null, 'Nonexistent draft returned.');
    $rows = $service->listOwned($owner);
    check(array_map('intval', array_column($rows, 'id')) === [$second, $id], 'Owned list order incorrect.');
    check((int) $rows[0]['chapter_count'] === 0 && (int) $rows[1]['chapter_count'] === 0, 'New drafts have chapters.');
    check($service->findOwned($second, $owner)['cover'] === null, 'Empty cover is not SQL NULL.');
    $query = $db->prepare('SELECT owner_id, slug, published_at, subtitle, description FROM public.exhibitions WHERE id = ?');
    $query->execute([$id]);
    $raw = $query->fetch();
    check((int) $raw['owner_id'] === $owner && $raw['published_at'] === null && $raw['subtitle'] === null
        && $raw['description'] === null && preg_match('/\Adraft-[a-f0-9]{32}\z/', $raw['slug']) === 1, 'System-controlled creation values incorrect.');
    $query->execute([$second]);
    check($query->fetch()['slug'] !== $raw['slug'], 'Duplicate titles did not get unique slugs.');
    $before = count($rows);
    try {
        $service->createDraft($owner, ['title' => ' ']);
        throw new RuntimeException('Invalid draft persisted.');
    } catch (Exhibit\Exhibitions\ValidationError) {
        check(count($service->listOwned($owner)) === $before, 'Invalid draft added a record.');
    }
    check($db->query('SELECT (SELECT count(*) FROM public.chapters) + (SELECT count(*) FROM public.content_blocks)')->fetchColumn() === $children,
        'Draft creation added placeholder children.');
    $chapter = $db->prepare('INSERT INTO public.chapters (exhibition_id, position, title) VALUES (?, 1, ?) RETURNING id');
    $chapter->execute([$id, 'Test count only']);
    $chapterId = $chapter->fetchColumn();
    $block = $db->prepare("INSERT INTO public.content_blocks (chapter_id, position, type, content)
        VALUES (?, 1, 'text', '{\"text\":\"Protected block\"}')");
    $block->execute([$chapterId]);
    $rows = $service->listOwned($owner);
    check((int) $rows[1]['chapter_count'] === 1, 'Chapter count is not derived.');
    $delete = $db->prepare('DELETE FROM public.chapters WHERE id = ?');
    $old = $service->findOwned($id, $owner);
    $version = Exhibit\Exhibitions\revision($old);
    $service->saveDraft($id, $owner, ['title' => 'Updated metadata', 'summary' => 'Updated introduction',
        'subject' => 'Technology', 'cover' => '', 'revision' => $version, 'owner_id' => $other, 'status' => 'published']);
    $updated = $service->findOwned($id, $owner);
    check($updated['title'] === 'Updated metadata' && $updated['summary'] === 'Updated introduction'
        && $updated['category'] === 'Technology' && $updated['cover'] === null
        && $updated['status'] === 'draft' && $updated['updated_at'] !== $old['updated_at'], 'Draft update did not persist.');
    $protected = $db->prepare('SELECT c.title, b.content FROM public.chapters c
        JOIN public.content_blocks b ON b.chapter_id = c.id WHERE c.id = ?');
    $protected->execute([$chapterId]);
    $child = $protected->fetch();
    check($child !== false && $child['title'] === 'Test count only'
        && json_decode($child['content'], true, 512, JSON_THROW_ON_ERROR) === ['text' => 'Protected block'], 'Saving changed chapter/block content.');
    $delete->execute([$chapterId]);
    foreach ([[$owner, $version], [$other, Exhibit\Exhibitions\revision($updated)]] as [$actor, $revision]) {
        try {
            $service->saveDraft($id, $actor, ['title' => 'Denied edit', 'revision' => $revision]);
            throw new RuntimeException('Stale or foreign save succeeded.');
        } catch (Exhibit\Exhibitions\SaveConflict) {
            check($service->findOwned($id, $owner)['title'] === 'Updated metadata', 'Rejected update changed metadata.');
        }
    }
    $db->exec("UPDATE public.exhibitions SET status = 'published', published_at = CURRENT_TIMESTAMP WHERE id = " . $id);
    try {
        $service->saveDraft($id, $owner, ['title' => 'Denied publication edit',
            'revision' => Exhibit\Exhibitions\revision($service->findOwned($id, $owner))]);
        throw new RuntimeException('Published exhibition accepted draft edit.');
    } catch (Exhibit\Exhibitions\SaveConflict) {
        check($service->findOwned($id, $owner)['status'] === 'published', 'Saving changed publication state.');
    }
    check(count($service->listOwned($owner)) === $before, 'Saving added exhibitions.');
    runStudioPostgres($db, $owner, $other);
    runPublicationPostgres($db, $owner, $other);
    echo "PASS: PostgreSQL draft persistence, updates, stale/foreign/published save rejection and child counts.\n";
}

function runStudioPostgres(PDO $db, int $owner, int $other): void
{
    $service = new Exhibit\Exhibitions\Service($db);
    $id = $service->createDraft($owner, ['title' => 'Studio content']);
    $foreignId = $service->createDraft($other, ['title' => 'Foreign content']);
    $siblingId = $service->createDraft($owner, ['title' => 'Unrelated owned content']);
    $load = fn (int $draft, int $actor): array => $service->findOwned($draft, $actor, true);
    $save = static function (int $draft, int $actor, array $chapters) use ($service, $load): void {
        $row = $load($draft, $actor);
        $service->saveDraft($draft, $actor, ['title' => $row['title'], 'subtitle' => 'A subtitle',
            'description' => 'A detailed description', 'revision' => Exhibit\Exhibitions\revision($row),
            'chapters_json' => json_encode($chapters, JSON_THROW_ON_ERROR)]);
    };
    $chapters = [
        ['title' => 'First chapter', 'era' => 'First era', 'intro' => 'Opening', 'blocks' => [
            ['type' => 'text', 'content' => ['heading' => 'First block', 'body' => 'Narrative']],
            ['type' => 'artifact', 'content' => ['heading' => 'Second block', 'body' => 'Context']],
            ['type' => 'figure', 'content' => ['url' => 'https://example.test/figure.jpg',
                'alt' => 'An image', 'caption' => 'Caption', 'credit' => 'Source']],
        ]],
        ['title' => 'Second chapter', 'era' => '', 'intro' => '', 'blocks' => []],
    ];
    $save($id, $owner, $chapters);
    $save($foreignId, $other, [$chapters[0]]);
    $save($siblingId, $owner, [$chapters[0]]);
    $sibling = $load($siblingId, $owner);
    $saved = $load($id, $owner);
    check($saved['subtitle'] === 'A subtitle' && $saved['description'] === 'A detailed description'
        && count($saved['chapters']) === 2 && count($saved['chapters'][0]['blocks']) === 3, 'Content did not round trip.');
    $chapterId = $saved['chapters'][0]['id'];
    $blockIds = array_column($saved['chapters'][0]['blocks'], 'id');
    $db->prepare("UPDATE public.chapters SET summary = 'Keep summary', eyebrow = 'Keep eyebrow',
        ending_heading = 'Keep ending', ending_text = 'Keep text' WHERE id = ?")->execute([$chapterId]);
    $reordered = array_reverse($saved['chapters']);
    $reordered[1]['title'] = 'Edited chapter';
    $reordered[1]['blocks'] = array_reverse($reordered[1]['blocks']);
    $reordered[1]['blocks'][1]['content']['body'] = 'Edited artifact';
    $save($id, $owner, $reordered);
    $updated = $load($id, $owner);
    check(array_column($updated['chapters'], 'id') === array_reverse(array_column($saved['chapters'], 'id'))
        && array_column($updated['chapters'][1]['blocks'], 'id') === array_reverse($blockIds)
        && $updated['chapters'][1]['title'] === 'Edited chapter'
        && $updated['chapters'][1]['blocks'][1]['content']['body'] === 'Edited artifact', 'Editing/reordering lost content or stable IDs.');
    $query = $db->prepare('SELECT summary, eyebrow, ending_heading, ending_text FROM public.chapters WHERE id = ?');
    $query->execute([$chapterId]);
    check($query->fetch() === ['summary' => 'Keep summary', 'eyebrow' => 'Keep eyebrow',
        'ending_heading' => 'Keep ending', 'ending_text' => 'Keep text'], 'Studio erased unexposed chapter fields.');
    $foreign = $load($foreignId, $other);
    $forgedChapter = $updated['chapters'];
    $forgedChapter[] = $foreign['chapters'][0];
    $forgedBlock = $updated['chapters'];
    $forgedBlock[1]['blocks'][] = $foreign['chapters'][0]['blocks'][0];
    $crossChapter = $updated['chapters'];
    $crossChapter[0]['blocks'][] = array_pop($crossChapter[1]['blocks']);
    foreach ([$forgedChapter, $forgedBlock, $crossChapter] as $invalid) {
        try {
            $save($id, $owner, $invalid);
            throw new RuntimeException('Foreign or moved child was accepted.');
        } catch (Exhibit\Exhibitions\ValidationError) {
            check($load($id, $owner) === $updated && $load($foreignId, $other) === $foreign, 'Rejected child save changed data or revision.');
        }
    }
    $db->exec("CREATE FUNCTION public.exhibit_test_block_failure() RETURNS trigger LANGUAGE plpgsql AS
        'BEGIN RAISE EXCEPTION ''test-only block failure''; END;'");
    $db->exec('CREATE TRIGGER exhibit_test_block_failure BEFORE UPDATE ON public.content_blocks
        FOR EACH ROW EXECUTE FUNCTION public.exhibit_test_block_failure()');
    try {
        try {
            $save($id, $owner, $updated['chapters']);
            throw new RuntimeException('Injected block failure reported success.');
        } catch (PDOException) {
            check($load($id, $owner) === $updated, 'Mid-save failure committed metadata, revision or children.');
        }
    } finally {
        $db->exec('DROP TRIGGER exhibit_test_block_failure ON public.content_blocks');
        $db->exec('DROP FUNCTION public.exhibit_test_block_failure()');
    }
    $remaining = $updated['chapters'];
    array_shift($remaining);
    array_shift($remaining[0]['blocks']);
    $save($id, $owner, $remaining);
    $deleted = $load($id, $owner);
    check(count($deleted['chapters']) === 1 && count($deleted['chapters'][0]['blocks']) === 2
        && $deleted['chapters'][0]['id'] === $chapterId, 'Child removal did not persist.');
    $positions = $db->prepare('SELECT position FROM public.content_blocks WHERE chapter_id = ? ORDER BY position');
    $positions->execute([$chapterId]);
    check(array_map('intval', array_column($positions->fetchAll(), 'position')) === [1, 2], 'Block positions contain gaps.');
    check($load($foreignId, $other) === $foreign && $load($siblingId, $owner) === $sibling,
        'Deleting chapters or blocks changed another exhibition.');
    try {
        $service->saveDraft($id, $owner, ['title' => 'Stale content', 'revision' => Exhibit\Exhibitions\revision($updated),
            'chapters_json' => json_encode($updated['chapters'])]);
        throw new RuntimeException('Stale content overwrote newer save.');
    } catch (Exhibit\Exhibitions\SaveConflict) {
        check($load($id, $owner) === $deleted, 'Stale content save changed data.');
    }
    $save($id, $owner, []);
    check($load($id, $owner)['chapters'] === [], 'Removing all chapters did not persist.');
    $query = $db->prepare('SELECT count(*) FROM public.content_blocks WHERE id = ANY(CAST(? AS bigint[]))');
    $query->execute(['{' . implode(',', $blockIds) . '}']);
    check((int) $query->fetchColumn() === 0, 'Removing chapters left orphan blocks.');
    check($load($foreignId, $other) === $foreign && $load($siblingId, $owner) === $sibling,
        'Removing all content changed another exhibition or its revision.');
    echo "PASS: PostgreSQL Studio content round trips, stable-ID reorder, field preservation, child isolation, rollback, deletion and stale revisions.\n";
}

function studioSavedContent(array $response): array
{
    check($response['status'] === 200
        && preg_match('/id="exhibition-chapters_json" name="chapters_json" value="([^"]*)"/', $response['body'], $match) === 1,
        'Studio did not render its saved content payload.');
    $content = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, 32, JSON_THROW_ON_ERROR);
    check(is_array($content) && array_is_list($content), 'Studio saved content is not an ordered list.');
    return $content;
}

function runExhibitionHttp(AuthHttpClient $client, PDO $db, string $base): int
{
    $dashboard = $client->request('my-exhibitions.php');
    check($dashboard['status'] === 200 && str_contains($dashboard['body'], 'id="empty-exhibitions"')
        && !str_contains($dashboard['body'], 'The Birth of the Internet'), 'Dashboard is not an actual empty collection.');
    check($client->request('studio.php')['status'] === 303, 'Bare Studio did not redirect to selection.');
    $csrf = $client->csrf('new-exhibition.php');
    $count = (int) $db->query('SELECT count(*) FROM public.exhibitions')->fetchColumn();
    foreach ([[], ['csrf' => 'wrong'], ['csrf' => ['invalid']]] as $fields) {
        check($client->request('new-exhibition.php', $fields + ['title' => 'Denied'])['status'] === 403, 'CSRF bypass allowed draft creation.');
    }
    foreach ([
        ['title' => ' '], ['title' => str_repeat('x', 101)], ['title' => ['bad']],
        ['summary' => str_repeat('x', 321)], ['subject' => 'Invalid'],
        ['cover' => 'javascript:alert(1)'], ['cover' => 'https://user:pass@example.test/image'],
    ] as $fields) {
        $response = $client->request('new-exhibition.php', $fields + ['csrf' => $csrf, 'title' => 'Valid idea', 'summary' => 'Retained introduction']);
        check($response['status'] === 422 && str_contains($response['body'], 'aria-invalid="true"'), 'Invalid draft was accepted or has no field error.');
        if (!isset($fields['summary'])) {
            check(str_contains($response['body'], 'Retained introduction'), 'Validation discarded entered fields.');
        }
    }
    check((int) $db->query('SELECT count(*) FROM public.exhibitions')->fetchColumn() === $count, 'Rejected requests persisted exhibitions.');
    $input = [
        'csrf' => $csrf, 'title' => 'Private <script>alert("draft")</script> idea',
        'summary' => "An introduction & a question.\nAnother line.", 'subject' => 'History',
        'cover' => 'https://example.test/cover.jpg?size=1&v=2',
        'owner_id' => '999999999', 'status' => 'published', 'slug' => 'forged', 'published_at' => '2026-01-01',
    ];
    $response = $client->request('new-exhibition.php', $input);
    check($response['status'] === 303 && preg_match('/Location: [^\r\n]+\/studio\.php\?id=([1-9][0-9]*)/i', $response['headers'], $match) === 1,
        'Valid creation did not redirect to saved exhibition.');
    $id = (int) $match[1];
    $page = 'studio.php?id=' . $id;
    $studio = $client->request($page);
    check($studio['status'] === 200 && str_contains($studio['body'], Exhibit\Auth\escape($input['title']))
        && !str_contains($studio['body'], $input['title']) && str_contains($studio['body'], Exhibit\Auth\escape($input['summary']))
        && str_contains($studio['body'], 'value="History"') && str_contains($studio['body'], Exhibit\Auth\escape($input['cover']))
        && str_contains($studio['body'], 'id="studio-form"') && str_contains($studio['body'], 'Draft saved.')
        && !str_contains($studio['body'], 'Before the Web') && !str_contains($studio['body'], 'href="exhibition.php"'),
        'Studio did not render the saved metadata safely.');
    check(str_contains($studio['headers'], 'Cache-Control: no-store') && str_contains($studio['headers'], 'Referrer-Policy: no-referrer')
        && !str_contains($studio['body'], 'googletagmanager'), 'Private Studio leaks through cache/referrers/analytics.');
    $query = $db->prepare('SELECT owner_id, status, published_at, slug, subtitle, description FROM public.exhibitions WHERE id = ?');
    $query->execute([$id]);
    $raw = $query->fetch();
    $original = $raw;
    $owner = (int) $db->query("SELECT id FROM public.users WHERE email = 'http-creator@example.test'")->fetchColumn();
    check((int) $raw['owner_id'] === $owner && $raw['status'] === 'draft' && $raw['published_at'] === null, 'Client overrode trusted draft values.');
    check($client->request($page)['body'] === $studio['body'], 'Refreshing changed Studio information.');
    $dashboard = $client->request('my-exhibitions.php');
    check(str_contains($dashboard['body'], 'href="' . $page . '"') && str_contains($dashboard['body'], Exhibit\Auth\escape($input['title']))
        && str_contains($dashboard['body'], '0 chapters') && !str_contains($dashboard['body'], 'id="empty-exhibitions"'), 'Dashboard did not display saved draft.');
    check($client->request($page)['body'] === $studio['body'], 'Leaving and reopening changed saved information.');
    $response = $client->request('new-exhibition.php', ['csrf' => $csrf, 'title' => 'Another saved idea']);
    check($response['status'] === 303 && preg_match('/studio\.php\?id=([1-9][0-9]*)/', $response['headers'], $match) === 1,
        'Second draft was not created.');
    $second = $client->request('studio.php?id=' . $match[1]);
    check($second['status'] === 200 && str_contains($second['body'], 'Another saved idea')
        && !str_contains($second['body'], Exhibit\Auth\escape($input['title'])) && str_contains($second['body'], 'No introduction yet.'), 'Studio loaded the wrong draft.');
    foreach (['0', '-1', '01', '1x', '92233720368547758070', '', '999999999', 'id-array'] as $invalid) {
        $response = $client->request($invalid === 'id-array' ? 'studio.php?id[]=1' : 'studio.php?id=' . $invalid);
        check($response['status'] === 404 && $response['body'] === 'Exhibition not found.', 'Invalid or missing exhibition disclosed information.');
    }
    check($client->request('my-exhibitions.php', ['csrf' => $csrf, 'title' => 'Do not save'])['status'] === 405, 'Dashboard accepted a write.');
    foreach (['new-exhibition.php', 'my-exhibitions.php', $page] as $route) {
        $denied = $client->request($route, null, 'PUT');
        check($denied['status'] === 405 && str_contains($denied['headers'], 'Allow:'), 'Unsupported method was not explicitly rejected.');
    }
    check(preg_match('/name="revision" value="([a-f0-9]{32})"/', $studio['body'], $versionMatch) === 1, 'Revision missing.');
    $version = $versionMatch[1];
    $edit = ['csrf' => $csrf, 'revision' => $version, 'title' => 'Updated <script>metadata</script>',
        'summary' => 'Updated introduction & question.', 'subject' => 'Science & Nature',
        'cover' => 'https://example.test/updated.jpg?x=1&y=2',
        'owner_id' => '99999999', 'status' => 'published', 'subtitle' => 'An edited subtitle',
        'description' => 'A detailed description'];
    foreach ([[], ['csrf' => 'wrong'], ['csrf' => ['invalid']]] as $invalidCsrf) {
        $denied = $client->request($page, $invalidCsrf + array_diff_key($edit, ['csrf' => true]));
        check($denied['status'] === 403 && str_contains($denied['body'], Exhibit\Auth\escape($edit['summary'])), 'Save bypassed CSRF or lost input.');
    }
    foreach ([
        ['title' => ' '], ['title' => str_repeat('x', 101)], ['title' => ['bad']],
        ['summary' => str_repeat('x', 321)], ['subject' => 'Invalid'], ['cover' => 'javascript:alert(1)'],
    ] as $invalid) {
        $denied = $client->request($page, $invalid + $edit);
        check($denied['status'] === 422 && str_contains($denied['body'], 'aria-invalid="true"')
            && str_contains($denied['body'], 'name="revision" value="' . $version . '"'), 'Invalid update lost errors or revision.');
        if (!isset($invalid['summary'])) {
            check(str_contains($denied['body'], Exhibit\Auth\escape($edit['summary'])), 'Save validation lost valid input.');
        }
    }
    check($client->request($page)['body'] === $studio['body'], 'Rejected saves changed the record.');
    // This trigger is installed only in the disposable database to exercise a real save failure.
    $db->exec("CREATE FUNCTION public.exhibit_test_save_failure() RETURNS trigger LANGUAGE plpgsql AS
        'BEGIN IF NEW.title = ''Simulate save failure'' THEN RAISE EXCEPTION ''test-only failure''; END IF; RETURN NEW; END;'");
    $db->exec('CREATE TRIGGER exhibit_test_save_failure BEFORE UPDATE ON public.exhibitions
        FOR EACH ROW EXECUTE FUNCTION public.exhibit_test_save_failure()');
    try {
        $failure = $client->request($page, ['title' => 'Simulate save failure'] + $edit);
        check($failure['status'] === 503 && str_contains($failure['body'], 'Simulate save failure')
            && str_contains($failure['body'], Exhibit\Auth\escape($edit['summary']))
            && !str_contains($failure['body'], 'test-only failure'), 'Database error lost input or leaked exception.');
        $failure = $client->request($page, ['title' => 'Simulate save failure'] + $edit, null, true);
        check($failure['status'] === 503 && json_decode($failure['body'], true)['editable'] === true
            && !str_contains($failure['body'], 'test-only failure'), 'JSON save failure leaked details or lost retry feedback.');
    } finally {
        $db->exec('DROP TRIGGER exhibit_test_save_failure ON public.exhibitions');
        $db->exec('DROP FUNCTION public.exhibit_test_save_failure()');
    }
    check($client->request($page, $edit)['status'] === 303, 'Save did not redirect after success.');
    $saved = $client->request($page);
    check($saved['status'] === 200 && str_contains($saved['body'], 'Draft saved successfully.')
        && str_contains($saved['body'], Exhibit\Auth\escape($edit['title']))
        && !str_contains($saved['body'], $edit['title']), 'Saved result unsafe or success missing.');
    $stale = $client->request($page, ['title' => 'Stale edit'] + $edit);
    check($stale['status'] === 409 && str_contains($stale['body'], 'Stale edit')
        && str_contains($stale['body'], 'id="save-draft" class="studio-btn" disabled')
        && !str_contains($stale['body'], '<fieldset class="studio-fields" disabled>')
        && str_contains($stale['body'], 'name="revision" value="' . $version . '"'), 'Stale save overwrote newer edits or allowed blind retry.');
    check($client->request($page, ['revision' => []] + $edit)['status'] === 409, 'Malformed version accepted.');
    $current = $client->request($page);
    check(str_contains($current['body'], Exhibit\Auth\escape($edit['title']))
        && !str_contains($current['body'], 'Stale edit'), 'Stale edit was persisted.');
    $dashboard = $client->request('my-exhibitions.php');
    foreach (['title', 'summary', 'subject', 'cover'] as $field) {
        $expected = $field === 'cover' ? json_encode($edit[$field], JSON_THROW_ON_ERROR) : $edit[$field];
        check(str_contains($dashboard['body'], Exhibit\Auth\escape($expected)), 'Dashboard missing updated ' . $field);
    }
    $query->execute([$id]);
    $raw = $query->fetch();
    check((int) $raw['owner_id'] === $owner && $raw['status'] === 'draft' && $raw['published_at'] === null, 'Save altered ownership or status.');
    check($raw === array_replace($original, ['subtitle' => $edit['subtitle'], 'description' => $edit['description']]),
        'Save changed system-controlled fields or lost editable metadata.');
    check((int) $db->query('SELECT count(*) FROM public.exhibitions')->fetchColumn() === $count + 2, 'Save created duplicate records.');
    // A separate verified session exercises actual HTTP tenant isolation.
    $other = new AuthHttpClient($base);
    $otherCsrf = $other->csrf('sign-in.php');
    check($other->request('sign-in.php', ['csrf' => $otherCsrf, 'email' => 'draft-other@example.test',
        'password' => 'draft-test-password-123'])['status'] === 303, 'Second creator could not sign in.');
    $foreign = $other->request($page);
    check($foreign['status'] === 404 && $foreign['body'] === 'Exhibition not found.', 'Foreign creator opened another draft.');
    $otherCsrf = $other->csrf('sign-in.php');
    check($other->request($page, ['csrf' => $otherCsrf] + $edit)['status'] === 404, 'Foreign creator saved another draft.');
    $foreignList = $other->request('my-exhibitions.php');
    check($foreignList['status'] === 200 && !str_contains($foreignList['body'], Exhibit\Auth\escape($edit['title']))
        && !str_contains($foreignList['body'], 'href="' . $page . '"'), 'Dashboard leaked a foreign draft.');
    $childQuery = $db->prepare(
        'SELECT (SELECT count(*) FROM public.chapters WHERE exhibition_id = ?) AS chapters,
            (SELECT count(*) FROM public.content_blocks b JOIN public.chapters c ON c.id = b.chapter_id
             WHERE c.exhibition_id = ?) AS blocks'
    );
    $childQuery->execute([$id, $id]);
    $children = $childQuery->fetch();
    check((int) $children['chapters'] === 0 && (int) $children['blocks'] === 0, 'HTTP creation seeded placeholder children.');
    $current = $client->request($page);
    check(preg_match('/name="revision" value="([a-f0-9]{32})"/', $current['body'], $versionMatch) === 1, 'Content version missing.');
    $content = [['title' => 'HTTP chapter', 'era' => 'HTTP era', 'intro' => 'HTTP opening', 'blocks' => [
        ['type' => 'text', 'content' => ['heading' => 'HTTP heading', 'body' => '<script>literal text</script>']],
        ['type' => 'artifact', 'content' => ['heading' => 'HTTP artifact', 'body' => 'Context']],
        ['type' => 'figure', 'content' => ['url' => $base . '/assets/favicon.svg', 'alt' => 'Exhibit image',
            'caption' => 'Figure caption <script>literal</script>', 'credit' => 'Exhibit credit']],
    ]], ['title' => 'HTTP second chapter', 'era' => '', 'intro' => '', 'blocks' => []]];
    $contentEdit = ['revision' => $versionMatch[1], 'chapters_json' => json_encode($content)] + $edit;
    $badContent = $client->request($page, ['chapters_json' => '{}'] + $contentEdit);
    check($badContent['status'] === 422 && str_contains($badContent['body'], 'Content must be an ordered list')
        && str_contains($badContent['body'], 'name="chapters_json" value="{}"'), 'Invalid content shape accepted or input lost.');
    $badCsrf = $client->request($page, ['csrf' => 'invalid'] + $contentEdit);
    check($badCsrf['status'] === 403 && str_contains($badCsrf['body'], 'HTTP chapter'), 'Content save bypassed CSRF or lost edits.');
    $response = $client->request($page, $contentEdit, null, true);
    $snapshot = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    check($response['status'] === 200 && str_contains($response['headers'], 'Content-Type: application/json')
        && !str_contains($response['headers'], 'Location:') && $snapshot['message'] === 'Draft saved successfully.'
        && preg_match('/\A[a-f0-9]{32}\z/', $snapshot['revision']) === 1, 'In-place save did not return a confirmed snapshot.');
    $reopened = $client->request($page);
    check($reopened['status'] === 200 && str_contains($reopened['body'], 'HTTP chapter')
        && !str_contains($reopened['body'], '<script>literal text</script>'), 'Reopened content missing or unsafe.');
    $service = new Exhibit\Exhibitions\Service($db);
    $persisted = $service->findOwned($id, $owner, true);
    check(json_decode($snapshot['values']['chapters_json'], true) === $persisted['chapters']
        && $snapshot['revision'] === Exhibit\Exhibitions\revision($persisted)
        && is_string($persisted['chapters'][0]['id']) && is_string($persisted['chapters'][0]['blocks'][0]['id']),
        'In-place save returned incorrect content, database IDs or revision.');
    check(studioSavedContent($reopened) === $persisted['chapters'], 'Studio did not restore the exact persisted tree.');
    $content = array_reverse($persisted['chapters']);
    $content[1]['blocks'] = array_reverse($content[1]['blocks']);
    $content[1]['intro'] = 'Edited HTTP opening';
    $content[1]['blocks'][1]['content']['body'] = 'Edited HTTP artifact context';
    $reorder = ['revision' => Exhibit\Exhibitions\revision($persisted), 'chapters_json' => json_encode($content)] + $contentEdit;
    $response = $client->request($page, $reorder, null, true);
    check($response['status'] === 200, 'In-place HTTP reorder failed.');
    $ordered = $service->findOwned($id, $owner, true);
    check($ordered['chapters'] === $content && studioSavedContent($client->request($page)) === $content,
        'HTTP edits, chapter/block order or stable IDs did not survive reopening Studio.');
    check($client->request($page, $contentEdit)['status'] === 409, 'HTTP stale content save accepted.');
    foreach ([
        [['csrf' => 'invalid'] + $reorder, 403],
        [['revision' => Exhibit\Exhibitions\revision($ordered), 'title' => ' '] + $reorder, 422],
        [$contentEdit, 409],
    ] as [$invalid, $status]) {
        $response = $client->request($page, $invalid, null, true);
        $error = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        check($response['status'] === $status && is_string($error['message'])
            && $error['editable'] === ($status !== 409) && !isset($error['values']),
            'In-place save lost CSRF/validation/conflict protection or error feedback.');
    }
    check($other->request($page, ['csrf' => $otherCsrf] + $reorder)['status'] === 404, 'Foreign creator saved content.');
    check($other->request($page, ['csrf' => $otherCsrf] + $reorder, null, true)['status'] === 404,
        'In-place save bypassed ownership.');
    $dashboard = $client->request('my-exhibitions.php');
    check(str_contains($dashboard['body'], '2 chapters'), 'Dashboard count did not reflect saved content.');
    echo "PASS: HTTP creation and metadata saving, CSRF, validation, 503 recovery, stale conflicts, dashboard, refresh/reopen and tenant isolation.\n";
    return $id;
}

function runPublicationPostgres(PDO $db, int $owner, int $other): void
{
    $service = new Exhibit\Exhibitions\Service($db);
    foreach (['intro', 'text', 'artifact', 'figure'] as $kind) {
        $id = $service->createDraft($owner, ['title' => 'Minimal ' . $kind]);
        check($service->findPublished($id) === null, 'Draft exposed by public service.');
        $saved = $service->findOwned($id, $owner, true);
        $chapters = [['title' => 'Chapter', 'era' => '', 'intro' => '', 'blocks' => []]];
        if ($kind === 'intro') {
            $chapters[0]['intro'] = 'Meaningful opening';
        } else {
            $chapters[0]['blocks'][] = ['type' => $kind, 'content' => $kind === 'figure'
                ? ['url' => 'https://example.test/image.jpg', 'alt' => '', 'caption' => '', 'credit' => '']
                : ['heading' => '', 'body' => 'Meaningful content']];
        }
        $service->saveDraft($id, $owner, ['title' => $saved['title'],
            'revision' => Exhibit\Exhibitions\revision($saved), 'chapters_json' => json_encode($chapters)]);
        $saved = $service->findOwned($id, $owner, true);
        $version = Exhibit\Exhibitions\revision($saved);
        foreach ([[$other, $version], [$owner, md5('stale')]] as [$actor, $revision]) {
            try {
                $service->publish($id, $actor, $revision);
                throw new LogicException('Foreign/stale publication accepted.');
            } catch (Exhibit\Exhibitions\SaveConflict) {
                check($service->findPublished($id) === null && $service->findOwned($id, $owner, true) === $saved,
                    'Rejected publication changed saved draft.');
            }
        }
        $service->publish($id, $owner, $version);
        $published = $service->findPublished($id);
        check($published !== null && $published['chapters'] === $saved['chapters']
            && $published['cover'] === null && $published['summary'] === null && $published['category'] === null
            && !array_key_exists('owner_id', $published) && !array_key_exists('updated_at', $published),
            'Minimal publication lost content or needs optional metadata.');
        $query = $db->prepare('SELECT status, published_at, updated_at FROM public.exhibitions WHERE id = ?');
        $query->execute([$id]);
        $state = $query->fetch();
        check($state['status'] === 'published' && $state['published_at'] !== null, 'Publication state is inconsistent.');
        $service->publish($id, $owner, $version);
        $query->execute([$id]);
        check($query->fetch() === $state, 'Duplicate publication changed timestamp or revision.');
    }
    echo "PASS: PostgreSQL publication of narrative/text/artifact/figure-only exhibitions, optional metadata, privacy, revisions, ownership and idempotence.\n";
}

function runPublicationHttp(AuthHttpClient $client, PDO $db, string $base, int $id): void
{
    $service = new Exhibit\Exhibitions\Service($db);
    $owner = (int) $db->query("SELECT id FROM public.users WHERE email = 'http-creator@example.test'")->fetchColumn();
    $saved = $service->findOwned($id, $owner, true);
    $version = Exhibit\Exhibitions\revision($saved);
    $route = 'publish.php?id=' . $id;
    $overview = 'exhibition.php?id=' . $id;
    $visitor = new AuthHttpClient($base);
    foreach ([$visitor, $client] as $reader) {
        foreach ([$overview, 'view.php?id=' . $id . '&chapter=' . $saved['chapters'][1]['id']] as $page) {
            $response = $reader->request($page);
            check($response['status'] === 404 && $response['body'] === 'Exhibition not found.'
                && !str_contains($response['body'], 'HTTP chapter'), 'Public route exposed a draft.');
        }
    }
    check($visitor->cookie === '', 'Public viewing started an anonymous session.');
    check($visitor->request($route)['status'] === 303
        && $visitor->request($route, ['revision' => $version])['status'] === 303, 'Anonymous publication allowed.');
    $other = new AuthHttpClient($base);
    $csrf = $other->csrf('sign-in.php');
    check($other->request('sign-in.php', ['csrf' => $csrf, 'email' => 'draft-other@example.test',
        'password' => 'draft-test-password-123'])['status'] === 303, 'Publication isolation login failed.');
    check($other->request($route . '&revision=' . $version)['status'] === 404
        && $other->request($route, ['csrf' => $csrf, 'revision' => $version])['status'] === 404, 'Foreign creator published/read confirmation.');
    $confirmation = $client->request($route . '&revision=' . $version);
    check($confirmation['status'] === 200 && str_contains($confirmation['body'], 'last saved version')
        && str_contains($confirmation['headers'], 'Cache-Control: no-store'), 'Publish confirmation unavailable or not private.');
    check($service->findOwned($id, $owner, true) === $saved && $service->findPublished($id) === null, 'GET published or changed the draft.');
    $csrf = $client->csrf($route . '&revision=' . $version);
    foreach ([[], ['csrf' => 'invalid'], ['csrf' => ['invalid']]] as $fields) {
        check($client->request($route, $fields + ['revision' => $version])['status'] === 403, 'Publish bypassed CSRF.');
    }
    foreach ([[], ['revision' => []], ['revision' => 'bad']] as $fields) {
        check($client->request($route, ['csrf' => $csrf] + $fields)['status'] === 409, 'Publish accepted missing/malformed revision.');
    }
    check($client->request($route, null, 'PUT')['status'] === 405, 'Publish accepted unsupported method.');
    $emptyId = $service->createDraft($owner, ['title' => 'Empty publication test']);
    $empty = $service->findOwned($emptyId, $owner, true);
    $emptyVersion = Exhibit\Exhibitions\revision($empty);
    check($client->request('publish.php?id=' . $emptyId . '&revision=' . $emptyVersion)['status'] === 422
        && $client->request('publish.php?id=' . $emptyId, ['csrf' => $csrf, 'revision' => $emptyVersion])['status'] === 422
        && $service->findPublished($emptyId) === null, 'Empty exhibition published.');
    $service->saveDraft($id, $owner, ['title' => $saved['title'], 'summary' => $saved['summary'],
        'subject' => $saved['category'], 'cover' => $saved['cover']['url'], 'revision' => $version]);
    check($client->request($route, ['csrf' => $csrf, 'revision' => $version])['status'] === 409, 'Stale confirmation published a newer save.');
    $saved = $service->findOwned($id, $owner, true);
    $version = Exhibit\Exhibitions\revision($saved);
    $db->exec("CREATE FUNCTION public.exhibit_test_publish_failure() RETURNS trigger LANGUAGE plpgsql AS
        'BEGIN IF NEW.status = ''published'' THEN RAISE EXCEPTION ''test-only publish failure''; END IF; RETURN NEW; END;'");
    $db->exec('CREATE TRIGGER exhibit_test_publish_failure BEFORE UPDATE ON public.exhibitions
        FOR EACH ROW EXECUTE FUNCTION public.exhibit_test_publish_failure()');
    try {
        $failure = $client->request($route, ['csrf' => $csrf, 'revision' => $version]);
        check($failure['status'] === 503 && !str_contains($failure['body'], 'test-only')
            && $service->findPublished($id) === null && $service->findOwned($id, $owner, true) === $saved,
            'Publication failure leaked details, exposed draft or changed saved version.');
    } finally {
        $db->exec('DROP TRIGGER exhibit_test_publish_failure ON public.exhibitions');
        $db->exec('DROP FUNCTION public.exhibit_test_publish_failure()');
    }
    $published = $client->request($route, ['csrf' => $csrf, 'revision' => $version, 'title' => 'Forged published title',
        'chapters_json' => '[]', 'owner_id' => '9999999']);
    check($published['status'] === 303 && str_contains($published['headers'], '/exhibition.php?id=' . $id),
        'Publishing did not redirect to public exhibition.');
    $canonical = $service->findOwned($id, $owner, true);
    check($canonical['chapters'] === $saved['chapters'] && $canonical['title'] === $saved['title']
        && $canonical['status'] === 'published', 'Publishing changed content or accepted client overrides.');
    $query = $db->prepare('SELECT published_at, updated_at FROM public.exhibitions WHERE id = ?');
    $query->execute([$id]);
    $timestamps = $query->fetch();
    check($client->request($route, ['csrf' => $csrf, 'revision' => $version])['status'] === 303, 'Duplicate publication failed.');
    $query->execute([$id]);
    check($query->fetch() === $timestamps, 'Duplicate publication changed timestamp.');
    $visitor = new AuthHttpClient($base);
    $public = $visitor->request($overview);
    check($public['status'] === 200 && str_contains($public['body'], Exhibit\Auth\escape($saved['title']))
        && !str_contains($public['body'], $saved['title']) && !str_contains($public['body'], 'Birth of the Internet')
        && !str_contains($public['headers'], 'Set-Cookie:'), 'Anonymous overview failed or exposed unescaped content/session.');
    check(strpos($public['body'], '<h3>HTTP second chapter</h3>') < strpos($public['body'], '<h3>HTTP chapter</h3>'),
        'Public chapter list is not in saved order.');
    foreach ($saved['chapters'] as $index => $chapter) {
        $page = 'view.php?id=' . $id . '&chapter=' . $chapter['id'];
        $response = $visitor->request($page);
        check($response['status'] === 200 && str_contains($response['body'], 'aria-valuemax="2"')
            && str_contains($response['body'], 'aria-valuenow="' . ($index + 1) . '"')
            && str_contains($response['body'], Exhibit\Auth\escape($chapter['title'])), 'Public chapter/progress did not render.');
        check(str_contains($response['body'], 'rel="next"') === ($index < count($saved['chapters']) - 1)
            && str_contains($response['body'], 'rel="prev"') === ($index > 0), 'Public chapter navigation boundaries wrong.');
        $previous = -1;
        foreach ($chapter['blocks'] as $block) {
            $position = strpos($response['body'], 'data-block-id="' . $block['id'] . '"');
            check($position !== false && $position > $previous, 'Public block order differs from saved order.');
            $previous = $position;
        }
        if ($chapter['blocks'] !== []) {
            check(str_contains($response['body'], '&lt;script&gt;literal text&lt;/script&gt;')
                && !str_contains($response['body'], '<script>literal')
                && str_contains($response['body'], 'alt="Exhibit image"')
                && str_contains($response['body'], 'Figure caption &lt;script&gt;literal&lt;/script&gt;')
                && str_contains($response['body'], 'Exhibit credit')
                && str_contains($response['body'], 'referrerpolicy="no-referrer"'), 'Public text/figure rendering is unsafe or incomplete.');
        }
        check($visitor->request($page, ['title' => 'Denied'])['status'] === 405, 'Public chapter accepted writes.');
    }
    foreach (['exhibition.php?id=0', 'exhibition.php?id[]=1', 'exhibition.php?id=999999999',
        'exhibition.php?chapter=1', 'view.php?chapter=1', 'view.php?id=' . $id,
        'view.php?id=' . $id . '&chapter[]=1', 'view.php?id=' . $id . '&chapter=999999999'] as $invalid) {
        $response = $visitor->request($invalid);
        check($response['status'] === 404 && $response['body'] === 'Exhibition not found.', 'Invalid public URL fell back or disclosed information.');
    }
    $foreignChapter = $db->query("SELECT c.id FROM public.chapters c JOIN public.exhibitions e ON e.id = c.exhibition_id
        WHERE e.id <> " . $id . " AND e.status = 'published' LIMIT 1")->fetchColumn();
    check($visitor->request('view.php?id=' . $id . '&chapter=' . $foreignChapter)['status'] === 404, 'Public chapter from another exhibition exposed.');
    $studio = $client->request('studio.php?id=' . $id);
    check($studio['status'] === 200 && str_contains($studio['body'], 'Published exhibition. Read-only.')
        && str_contains($studio['body'], '<fieldset class="studio-fields" disabled>')
        && !str_contains($studio['body'], 'id="publish-form"')
        && str_contains($studio['body'], 'href="' . $overview . '"'), 'Published Studio is not read-only with a public link.');
    check($client->request('studio.php?id=' . $id, ['csrf' => $csrf, 'title' => 'Denied',
        'revision' => Exhibit\Exhibitions\revision($canonical)])['status'] === 409, 'Published exhibition accepted a draft save.');
    check($service->findOwned($id, $owner, true) === $canonical, 'Published save rejection changed content.');
    check(str_contains($client->request('my-exhibitions.php')['body'], 'href="' . $overview . '"'),
        'Published dashboard has no public link.');
    check(!str_contains($visitor->request('explore.php')['body'], Exhibit\Auth\escape($saved['title'])),
        'Publishing unexpectedly added dynamic discovery.');
    check($visitor->cookie === '', 'Public viewing started a session.');
    // The existing schema permits absent optional block fields, as does Studio validation.
    $minimal = $service->createDraft($owner, ['title' => 'Optional block fields']);
    $version = Exhibit\Exhibitions\revision($service->findOwned($minimal, $owner));
    $service->saveDraft($minimal, $owner, ['title' => 'Optional block fields', 'revision' => $version,
        'chapters_json' => json_encode([['title' => 'Minimal figure', 'intro' => 'Opening', 'blocks' => [
            ['type' => 'figure', 'content' => ['url' => $base . '/assets/favicon.svg']],
            ['type' => 'text', 'content' => ['body' => 'Body without heading']],
        ]]])]);
    $db->exec("UPDATE public.content_blocks b SET content = content - 'alt' - 'caption' - 'credit' - 'heading'
        FROM public.chapters c WHERE b.chapter_id = c.id AND c.exhibition_id = " . $minimal);
    $savedMinimal = $service->findOwned($minimal, $owner, true);
    $service->publish($minimal, $owner, Exhibit\Exhibitions\revision($savedMinimal));
    $minimalPage = $visitor->request('view.php?id=' . $minimal . '&chapter=' . $savedMinimal['chapters'][0]['id']);
    check($minimalPage['status'] === 200 && str_contains($minimalPage['body'], 'alt=""')
        && str_contains($minimalPage['body'], 'Body without heading')
        && !str_contains($minimalPage['body'], '<figcaption'), 'Absent optional block fields broke public rendering.');
    echo "PASS: HTTP saved-session-to-publish-to-anonymous-view workflow; privacy, CSRF, ownership, readiness, stale/duplicate requests, failure rollback, ordered safe rendering and read-only Studio.\n";
}
