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
    echo "PASS: PostgreSQL draft persistence, updates, stale/foreign/published save rejection and child counts.\n";
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
        'owner_id' => '99999999', 'status' => 'published', 'subtitle' => 'forged', 'chapters' => ['forged']];
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
    check($raw === $original, 'Save changed system-controlled or non-metadata fields.');
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
    echo "PASS: HTTP creation and metadata saving, CSRF, validation, 503 recovery, stale conflicts, dashboard, refresh/reopen and tenant isolation.\n";
    return $id;
}
