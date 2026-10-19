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
    $rows = $service->listOwned($owner);
    check((int) $rows[1]['chapter_count'] === 1, 'Chapter count is not derived.');
    $delete = $db->prepare('DELETE FROM public.chapters WHERE id = ?');
    $delete->execute([$chapterId]);
    echo "PASS: PostgreSQL draft persistence, mapping, ordering, ownership, validation and child counts.\n";
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
        && str_contains($studio['body'], 'READ ONLY') && str_contains($studio['body'], 'Draft · Saved')
        && !str_contains($studio['body'], 'Before the Web') && !str_contains($studio['body'], 'href="exhibition.php"'),
        'Studio did not render the saved metadata safely.');
    check(str_contains($studio['headers'], 'Cache-Control: no-store') && str_contains($studio['headers'], 'Referrer-Policy: no-referrer')
        && !str_contains($studio['body'], 'googletagmanager'), 'Private Studio leaks through cache/referrers/analytics.');
    $query = $db->prepare('SELECT owner_id, status, published_at FROM public.exhibitions WHERE id = ?');
    $query->execute([$id]);
    $raw = $query->fetch();
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
    foreach (['my-exhibitions.php', $page] as $readOnlyPage) {
        check($client->request($readOnlyPage, ['csrf' => $csrf, 'title' => 'Do not save'])['status'] === 405, 'Read-only page accepted a write.');
    }
    foreach (['new-exhibition.php', 'my-exhibitions.php', $page] as $route) {
        $denied = $client->request($route, null, 'PUT');
        check($denied['status'] === 405 && str_contains($denied['headers'], 'Allow:'), 'Unsupported method was not explicitly rejected.');
    }
    check($client->request($page)['body'] === $studio['body'], 'Studio POST changed saved information.');
    // A separate verified session exercises actual HTTP tenant isolation.
    $other = new AuthHttpClient($base);
    $otherCsrf = $other->csrf('sign-in.php');
    check($other->request('sign-in.php', ['csrf' => $otherCsrf, 'email' => 'draft-other@example.test',
        'password' => 'draft-test-password-123'])['status'] === 303, 'Second creator could not sign in.');
    $foreign = $other->request($page);
    check($foreign['status'] === 404 && $foreign['body'] === 'Exhibition not found.', 'Foreign creator opened another draft.');
    $foreignList = $other->request('my-exhibitions.php');
    check($foreignList['status'] === 200 && !str_contains($foreignList['body'], Exhibit\Auth\escape($input['title']))
        && !str_contains($foreignList['body'], 'href="' . $page . '"'), 'Dashboard leaked a foreign draft.');
    $childQuery = $db->prepare(
        'SELECT (SELECT count(*) FROM public.chapters WHERE exhibition_id = ?) AS chapters,
            (SELECT count(*) FROM public.content_blocks b JOIN public.chapters c ON c.id = b.chapter_id
             WHERE c.exhibition_id = ?) AS blocks'
    );
    $childQuery->execute([$id, $id]);
    $children = $childQuery->fetch();
    check((int) $children['chapters'] === 0 && (int) $children['blocks'] === 0, 'HTTP creation seeded placeholder children.');
    echo "PASS: HTTP draft creation, CSRF, validation, owned dashboard, read-only Studio, refresh, reopen and tenant isolation.\n";
    return $id;
}
