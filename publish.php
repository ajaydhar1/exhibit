<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/exhibitions.php';

use Exhibit\Auth;
use Exhibit\Exhibitions;

Exhibitions\requireMethod(['GET', 'POST']);
$id = Exhibitions\exhibitionId($_GET['id'] ?? null);
if ($id === null) {
    Auth\requireCreator();
    Exhibitions\notFound();
}
$creator = Auth\requireExhibitionOwner($id);
$expectedRevision = $_SERVER['REQUEST_METHOD'] === 'POST' ? Auth\post('revision') : ($_GET['revision'] ?? null);
$errorMessage = '';
try {
    $service = Exhibitions\service();
    $exhibition = $service->findOwned($id, (int) $creator['id'], true);
    if ($exhibition === null) {
        Exhibitions\notFound();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth\csrfValid(Auth\post('csrf'))) {
        http_response_code(403);
        $errorMessage = 'Your form expired. Reopen Studio before publishing.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->publish($id, (int) $creator['id'], $expectedRevision);
        Auth\redirect('exhibition.php?id=' . $id);
    } elseif ($exhibition['status'] === 'published') {
        Auth\redirect('exhibition.php?id=' . $id);
    } elseif (!is_string($expectedRevision) || !hash_equals(Exhibitions\revision($exhibition), $expectedRevision)) {
        throw new Exhibitions\SaveConflict('This exhibition changed or the saved version is missing. Reopen Studio and review it before publishing.');
    } else {
        Exhibitions\publicationReady($exhibition);
    }
} catch (Exhibitions\ValidationError $error) {
    http_response_code(422);
    $errorMessage = $error->getMessage();
} catch (Exhibitions\SaveConflict $error) {
    http_response_code(409);
    $errorMessage = $error->getMessage();
} catch (Throwable $error) {
    Exhibitions\unavailable($error);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Publish exhibition — Exhibit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="assets/studio.css">
</head>
<body class="studio-page">
  <header class="site-header"><div class="shell header-inner">
    <a class="wordmark" href="index.php">exhibit<span class="wordmark-period">.</span></a>
    <nav aria-label="Workspace"><a href="studio.php?id=<?= $id ?>">Back to Studio</a><a href="my-exhibitions.php">My Exhibitions</a></nav>
  </div></header>
  <main class="shell publish-confirmation">
    <p class="eyebrow">SHARE YOUR STORY</p>
    <h1>Publish your exhibition.</h1>
    <?php if ($errorMessage !== ''): ?>
      <p role="alert"><?= Auth\escape($errorMessage) ?></p>
    <?php else: ?>
      <h2><?= Auth\escape($exhibition['title']) ?></h2>
      <p>You are publishing the last saved version. Any unsaved Studio changes are not included.</p>
      <p>Anyone with its public link will be able to view it. Published exhibitions are read-only in this MVP; editing and unpublishing are not available.</p>
      <form method="post" action="publish.php?id=<?= $id ?>">
        <input type="hidden" name="csrf" value="<?= Auth\escape($_SESSION['csrf']) ?>">
        <input type="hidden" name="revision" value="<?= Auth\escape($expectedRevision) ?>">
        <button class="studio-btn" type="submit">Publish exhibition</button>
      </form>
    <?php endif; ?>
    <p><a href="studio.php?id=<?= $id ?>">Return to Studio</a></p>
  </main>
</body>
</html>
