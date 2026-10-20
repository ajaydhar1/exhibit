<?php
declare(strict_types=1);

namespace Exhibit\Exhibitions;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/database.php';

const SUBJECTS = [
    'Arts & Culture', 'History', 'Science & Nature', 'Technology',
    'Film & Music', 'Places & People', 'Something else',
];

final class ValidationError extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}

final class SaveConflict extends RuntimeException {}

function revision(array $exhibition): string
{
    return md5($exhibition['updated_at']);
}

function validCoverUrl(string $value): bool
{
    if (strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)
        || filter_var($value, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $parts = parse_url($value);
    return is_array($parts) && isset($parts['host'])
        && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        && !isset($parts['user']) && !isset($parts['pass']);
}

function validate(array $input): array
{
    $errors = [];
    $values = [];
    foreach (['title' => 100, 'summary' => 320, 'subject' => 100, 'cover' => 2048] as $field => $maximum) {
        $value = $input[$field] ?? '';
        if (!is_string($value) || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            $errors[$field] = 'Enter valid text for this field.';
            $values[$field] = '';
            continue;
        }
        $value = trim($value);
        $values[$field] = $value;
        if (preg_match_all('/./us', $value) > $maximum) {
            $errors[$field] = 'Use no more than ' . $maximum . ' characters.';
        }
    }
    if ($values['title'] === '' && !isset($errors['title'])) {
        $errors['title'] = 'Add an exhibition title to continue.';
    }
    if ($values['subject'] !== '' && !in_array($values['subject'], SUBJECTS, true) && !isset($errors['subject'])) {
        $errors['subject'] = 'Choose one of the available subjects.';
    }
    if ($values['cover'] !== '' && !validCoverUrl($values['cover']) && !isset($errors['cover'])) {
        $errors['cover'] = 'Enter an HTTP or HTTPS image URL without credentials, up to 2048 bytes.';
    }
    if ($errors !== []) {
        throw new ValidationError($errors);
    }
    return [
        'title' => $values['title'],
        'summary' => $values['summary'] === '' ? null : $values['summary'],
        'category' => $values['subject'] === '' ? null : $values['subject'],
        'cover' => $values['cover'] === '' ? null : ['url' => $values['cover']],
    ];
}

function exhibitionId(mixed $value): ?int
{
    if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function studioText(mixed $value, int $maximum, string $field, bool $required = false): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
        throw new ValidationError([$field => 'Enter valid plain text.']);
    }
    $value = trim($value);
    if (($required && $value === '') || preg_match_all('/./us', $value) > $maximum) {
        throw new ValidationError([$field => $required && $value === ''
            ? 'Add a title before saving.' : 'Use no more than ' . $maximum . ' characters.']);
    }
    return $value;
}

function contentId(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $id = exhibitionId($value);
    if ($id === null) {
        throw new ValidationError(['content' => 'Invalid chapter or block identifier. Reopen Studio to review saved content.']);
    }
    return $id;
}

function validateContent(mixed $json): array
{
    if (!is_string($json) || strlen($json) > 1048576) {
        throw new ValidationError(['content' => 'Content must be a JSON list no larger than 1 MiB.']);
    }
    try {
        $chapters = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        throw new ValidationError(['content' => 'Content could not be read. Your edits have not been saved.']);
    }
    if (!is_array($chapters) || !array_is_list($chapters)) {
        throw new ValidationError(['content' => 'Content must be an ordered list of chapters.']);
    }
    $chapterIds = $blockIds = [];
    $result = [];
    foreach ($chapters as $chapter) {
        if (!$chapter instanceof \stdClass) {
            throw new ValidationError(['content' => 'Each chapter must be an object.']);
        }
        $chapter = get_object_vars($chapter);
        if (!is_array($chapter['blocks'] ?? null) || !array_is_list($chapter['blocks'])) {
            throw new ValidationError(['content' => 'Each chapter must contain an ordered list of blocks.']);
        }
        $id = contentId($chapter['id'] ?? null);
        if ($id !== null && isset($chapterIds[$id])) {
            throw new ValidationError(['content' => 'A chapter cannot appear twice.']);
        }
        $chapterIds[$id ?? 'new-' . count($result)] = true;
        $value = ['id' => $id,
            'title' => studioText($chapter['title'] ?? '', 110, 'content', true),
            'era' => studioText($chapter['era'] ?? '', 100, 'content'),
            'intro' => studioText($chapter['intro'] ?? '', 2000, 'content'), 'blocks' => []];
        foreach ($chapter['blocks'] as $block) {
            if (!$block instanceof \stdClass) {
                throw new ValidationError(['content' => 'Each block must be an object.']);
            }
            $block = get_object_vars($block);
            if (!in_array($block['type'] ?? null, ['text', 'artifact', 'figure'], true)
                || !(($block['content'] ?? null) instanceof \stdClass)) {
                throw new ValidationError(['content' => 'Choose a supported text, artifact, or figure block.']);
            }
            $block['content'] = get_object_vars($block['content']);
            $blockId = contentId($block['id'] ?? null);
            if ($blockId !== null && isset($blockIds[$blockId])) {
                throw new ValidationError(['content' => 'A block cannot appear twice.']);
            }
            $blockIds[$blockId ?? 'new-' . count($blockIds)] = true;
            $limits = $block['type'] === 'figure'
                ? ['url' => 2048, 'alt' => 320, 'caption' => 2000, 'credit' => 320]
                : ['heading' => 130, 'body' => 2000];
            if (array_diff(array_keys($block['content']), array_keys($limits)) !== []) {
                throw new ValidationError(['content' => 'A block contains unsupported fields. No content has been discarded or saved.']);
            }
            $content = [];
            foreach ($limits as $key => $limit) {
                $content[$key] = studioText($block['content'][$key] ?? '', $limit, 'content');
            }
            if ($block['type'] === 'figure' && !validCoverUrl($content['url'])) {
                throw new ValidationError(['content' => 'Figures need an HTTP or HTTPS image URL without credentials.']);
            }
            $value['blocks'][] = ['id' => $blockId, 'type' => $block['type'], 'content' => $content];
        }
        $result[] = $value;
    }
    return $result;
}

final class Service
{
    public function __construct(private PDO $db) {}

    public function createDraft(int $ownerId, array $input): int
    {
        $values = validate($input);
        $statement = $this->db->prepare(
            "INSERT INTO public.exhibitions (owner_id, slug, title, summary, category, cover, status, published_at)
             VALUES (?, ?, ?, ?, ?, CAST(? AS jsonb), 'draft', NULL)
             ON CONFLICT ON CONSTRAINT exhibitions_slug_key DO NOTHING RETURNING id"
        );
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $statement->execute([
                $ownerId, 'draft-' . bin2hex(random_bytes(16)), $values['title'], $values['summary'],
                $values['category'], $values['cover'] === null ? null : json_encode($values['cover'], JSON_THROW_ON_ERROR),
            ]);
            $id = $statement->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        throw new RuntimeException('Unable to allocate an exhibition slug.');
    }

    public function listOwned(int $ownerId): array
    {
        $statement = $this->db->prepare(
            'SELECT e.id, e.title, e.summary, e.category, e.cover, e.status, e.created_at, e.updated_at,
                (SELECT count(*) FROM public.chapters c WHERE c.exhibition_id = e.id) AS chapter_count
             FROM public.exhibitions e WHERE e.owner_id = ? ORDER BY e.updated_at DESC, e.id DESC'
        );
        $statement->execute([$ownerId]);
        return array_map([$this, 'decodeCover'], $statement->fetchAll());
    }

    public function saveDraft(int $exhibitionId, int $ownerId, array $input): void
    {
        $values = validate($input);
        $details = [];
        foreach (['subtitle' => 100, 'description' => 10000] as $field => $limit) {
            if (array_key_exists($field, $input)) {
                $details[$field] = studioText($input[$field], $limit, $field);
            }
        }
        $chapters = array_key_exists('chapters_json', $input) ? validateContent($input['chapters_json']) : null;
        $revision = $input['revision'] ?? null;
        if (!is_string($revision) || preg_match('/\A[a-f0-9]{32}\z/', $revision) !== 1) {
            throw new SaveConflict('The saved version is missing or invalid. Reopen Studio before saving.');
        }
        $extraSql = '';
        $extraParams = [];
        foreach ($details as $field => $value) {
            $extraSql .= ', ' . $field . ' = ?';
            $extraParams[] = $value === '' ? null : $value;
        }
        if ($chapters !== null) {
            $this->db->beginTransaction();
        }
        try {
            $statement = $this->db->prepare(
                "UPDATE public.exhibitions SET title = ?, summary = ?, category = ?, cover = CAST(? AS jsonb)$extraSql
                 WHERE id = ? AND owner_id = ? AND status = 'draft' AND md5(updated_at::text) = ?
                 RETURNING updated_at"
            );
            $statement->execute([
                $values['title'], $values['summary'], $values['category'],
                $values['cover'] === null ? null : json_encode($values['cover'], JSON_THROW_ON_ERROR),
                ...$extraParams, $exhibitionId, $ownerId, $revision,
            ]);
            if ($statement->fetchColumn() === false) {
                throw new SaveConflict('This exhibition changed since you opened it or is no longer a draft. Copy your edits, then reopen Studio to review the latest version.');
            }
            if ($chapters !== null) {
                $this->saveContent($exhibitionId, $chapters);
                $this->db->commit();
            }
        } catch (Throwable $error) {
            if ($chapters !== null && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function saveContent(int $exhibitionId, array $chapters): void
    {
        // The parent UPDATE holds the exhibition lock and revision for this entire save.
        $query = $this->db->prepare('SELECT id FROM public.chapters WHERE exhibition_id = ?');
        $query->execute([$exhibitionId]);
        $existingChapters = array_fill_keys(array_map('intval', array_column($query->fetchAll(), 'id')), true);
        $query = $this->db->prepare('SELECT b.id, b.chapter_id FROM public.content_blocks b
            JOIN public.chapters c ON c.id = b.chapter_id WHERE c.exhibition_id = ?');
        $query->execute([$exhibitionId]);
        $existingBlocks = [];
        foreach ($query->fetchAll() as $row) {
            $existingBlocks[(int) $row['id']] = (int) $row['chapter_id'];
        }
        foreach ($chapters as $chapter) {
            if ($chapter['id'] !== null && !isset($existingChapters[$chapter['id']])) {
                throw new ValidationError(['content' => 'A chapter does not belong to this exhibition. Reopen Studio before saving.']);
            }
            foreach ($chapter['blocks'] as $block) {
                if ($block['id'] !== null && (!isset($existingBlocks[$block['id']])
                    || $existingBlocks[$block['id']] !== $chapter['id'])) {
                    throw new ValidationError(['content' => 'A block does not belong to this chapter. Reopen Studio before saving.']);
                }
            }
        }
        $this->db->exec('SET CONSTRAINTS chapters_exhibition_position_unique, content_blocks_chapter_position_unique DEFERRED');
        $keptChapters = array_column($chapters, 'id');
        $deleteChapter = $this->db->prepare('DELETE FROM public.chapters WHERE id = ? AND exhibition_id = ?');
        foreach ($existingChapters as $id => $_) {
            if (!in_array($id, $keptChapters, true)) {
                $deleteChapter->execute([$id, $exhibitionId]);
            }
        }
        foreach ($chapters as $position => $chapter) {
            if ($chapter['id'] === null) {
                $query = $this->db->prepare('INSERT INTO public.chapters (exhibition_id, position, title, era, intro)
                    VALUES (?, ?, ?, ?, ?) RETURNING id');
                $query->execute([$exhibitionId, $position + 1, $chapter['title'], $chapter['era'], $chapter['intro']]);
                $chapterId = (int) $query->fetchColumn();
            } else {
                $chapterId = $chapter['id'];
                $query = $this->db->prepare('UPDATE public.chapters SET position = ?, title = ?, era = ?, intro = ?
                    WHERE id = ? AND exhibition_id = ?');
                $query->execute([$position + 1, $chapter['title'], $chapter['era'], $chapter['intro'], $chapterId, $exhibitionId]);
            }
            $keptBlocks = array_column($chapter['blocks'], 'id');
            $deleteBlock = $this->db->prepare('DELETE FROM public.content_blocks WHERE id = ? AND chapter_id = ?');
            foreach ($existingBlocks as $id => $parentId) {
                if ($parentId === $chapterId && !in_array($id, $keptBlocks, true)) {
                    $deleteBlock->execute([$id, $chapterId]);
                }
            }
            foreach ($chapter['blocks'] as $blockPosition => $block) {
                $content = json_encode($block['content'], JSON_THROW_ON_ERROR);
                if ($block['id'] === null) {
                    $query = $this->db->prepare('INSERT INTO public.content_blocks (chapter_id, position, type, content)
                        VALUES (?, ?, ?, CAST(? AS jsonb))');
                    $query->execute([$chapterId, $blockPosition + 1, $block['type'], $content]);
                } else {
                    $query = $this->db->prepare('UPDATE public.content_blocks SET position = ?, type = ?, content = CAST(? AS jsonb)
                        WHERE id = ? AND chapter_id = ?');
                    $query->execute([$blockPosition + 1, $block['type'], $content, $block['id'], $chapterId]);
                }
            }
        }
    }

    public function findOwned(int $exhibitionId, int $ownerId, bool $withContent = false): ?array
    {
        // A single statement gives metadata, revision and ordered content the same snapshot.
        $contentSql = $withContent ? ", COALESCE((SELECT jsonb_agg(jsonb_build_object(
            'id', c.id::text, 'title', c.title, 'era', COALESCE(c.era, ''), 'intro', COALESCE(c.intro, ''),
            'blocks', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                'id', b.id::text, 'type', b.type, 'content', b.content) ORDER BY b.position)
                FROM public.content_blocks b WHERE b.chapter_id = c.id), '[]'::jsonb)) ORDER BY c.position)
            FROM public.chapters c WHERE c.exhibition_id = exhibitions.id), '[]'::jsonb) AS chapters" : '';
        $statement = $this->db->prepare(
            'SELECT id, title, subtitle, summary, description, category, cover, status, created_at, updated_at'
            . $contentSql . ' FROM public.exhibitions WHERE id = ? AND owner_id = ?'
        );
        $statement->execute([$exhibitionId, $ownerId]);
        $row = $statement->fetch();
        if ($row !== false && $withContent) {
            $row['chapters'] = json_decode($row['chapters'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $row === false ? null : $this->decodeCover($row);
    }

    private function decodeCover(array $row): array
    {
        if ($row['cover'] !== null) {
            $row['cover'] = json_decode($row['cover'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($row['cover']) || !is_string($row['cover']['url'] ?? null)
                || !validCoverUrl($row['cover']['url'])) {
                throw new RuntimeException('Invalid persisted exhibition cover.');
            }
        }
        return $row;
    }
}

function service(): Service
{
    return new Service(\Exhibit\Database\connect());
}

function notFound(): never
{
    http_response_code(404);
    exit('Exhibition not found.');
}

function requireMethod(array $allowed): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        http_response_code(405);
        exit('Method not allowed.');
    }
}

function unavailable(Throwable $error): never
{
    error_log('Exhibit exhibition services unavailable [' . get_class($error) . '].');
    http_response_code(503);
    header('Retry-After: 60');
    exit('Exhibition services are temporarily unavailable. Please try again later.');
}
