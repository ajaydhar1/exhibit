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

    public function findOwned(int $exhibitionId, int $ownerId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, title, subtitle, summary, description, category, cover, status, created_at, updated_at
             FROM public.exhibitions WHERE id = ? AND owner_id = ?'
        );
        $statement->execute([$exhibitionId, $ownerId]);
        $row = $statement->fetch();
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
