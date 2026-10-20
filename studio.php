<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/exhibitions.php';

use Exhibit\Auth;
use Exhibit\Exhibitions;

$id = Exhibitions\exhibitionId($_GET['id'] ?? null);
if ($id === null) {
  Auth\requireCreator();
  Exhibitions\requireMethod(['GET']);
  if (!array_key_exists('id', $_GET)) {
    Auth\redirect('my-exhibitions.php');
  }
  Exhibitions\notFound();
}
$creator = Auth\requireExhibitionOwner($id);
Exhibitions\requireMethod(['GET', 'POST']);
try {
  $exhibition = Exhibitions\service()->findOwned($id, (int) $creator['id'], true);
  if ($exhibition === null) {
    Exhibitions\notFound();
  }
} catch (Throwable $error) {
  Exhibitions\unavailable($error);
}
$values = [
  'title' => $exhibition['title'], 'summary' => $exhibition['summary'] ?? '',
  'subject' => $exhibition['category'] ?? '', 'cover' => $exhibition['cover']['url'] ?? '',
  'subtitle' => $exhibition['subtitle'] ?? '', 'description' => $exhibition['description'] ?? '',
  'chapters_json' => json_encode($exhibition['chapters'], JSON_THROW_ON_ERROR),
];
$baseline = $values;
$revision = Exhibitions\revision($exhibition);
$errors = [];
$state = 'saved';
$feedback = 'Draft saved.';
$editable = $exhibition['status'] === 'draft';
$jsonSave = $_SERVER['REQUEST_METHOD'] === 'POST'
  && ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ($values as $field => $_) {
    if ($field !== 'chapters_json' || array_key_exists($field, $_POST)) {
      $values[$field] = Auth\post($field);
    }
  }
  $revision = Auth\post('revision');
  $state = 'error';
  if (!Auth\csrfValid(Auth\post('csrf'))) {
    http_response_code(403);
    $feedback = 'Your form expired. Your edits are still here; please try saving again.';
  } else {
    try {
      $service = Exhibitions\service();
      $service->saveDraft($id, (int) $creator['id'], $_POST);
      if (!$jsonSave) {
        $_SESSION['studio_saved'] = $id;
        Auth\redirect('studio.php?id=' . $id);
      }
      $saved = $service->findOwned($id, (int) $creator['id'], true);
      if ($saved === null) {
        throw new RuntimeException('Saved draft could not be reloaded.');
      }
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode([
        'message' => 'Draft saved successfully.',
        'revision' => Exhibitions\revision($saved),
        'values' => [
          'title' => $saved['title'], 'summary' => $saved['summary'] ?? '',
          'subject' => $saved['category'] ?? '', 'cover' => $saved['cover']['url'] ?? '',
          'subtitle' => $saved['subtitle'] ?? '', 'description' => $saved['description'] ?? '',
          'chapters_json' => json_encode($saved['chapters'], JSON_THROW_ON_ERROR),
        ],
      ], JSON_THROW_ON_ERROR);
      exit;
    } catch (Exhibitions\ValidationError $error) {
      http_response_code(422);
      $errors = $error->errors;
      $feedback = 'Save failed. Please check the highlighted fields; your edits have been retained.';
    } catch (Exhibitions\SaveConflict $error) {
      http_response_code(409);
      $feedback = $error->getMessage();
      $editable = false;
    } catch (Throwable $error) {
      error_log('Exhibit draft save unavailable [' . get_class($error) . '].');
      http_response_code(503);
      header('Retry-After: 60');
      $feedback = 'Save failed: exhibition services are temporarily unavailable. Your edits are still here; please try again later.';
    }
  }
  if ($jsonSave) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => $feedback, 'errors' => $errors, 'editable' => $editable], JSON_THROW_ON_ERROR);
    exit;
  }
} elseif (($_SESSION['studio_saved'] ?? null) === $id) {
  unset($_SESSION['studio_saved']);
  $feedback = 'Draft saved successfully.';
}
if ($exhibition['status'] !== 'draft') {
  $feedback = 'Only private drafts can be edited in this milestone.';
}
$previewCover = Exhibitions\validCoverUrl(trim($values['cover'])) ? trim($values['cover']) : null;
?>
<!doctype html>
<html lang="en">

<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="description" content="Your saved exhibition information in Exhibit Studio.">
  <title><?= Auth\escape($exhibition['title']) ?> — Exhibition Studio</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="assets/studio.css">
  <script src="assets/studio.js" defer></script>
</head>

<body class="studio-page">
  <header class="site-header">
    <div class="shell header-inner">
      <a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
      <nav aria-label="Workspace"><a href="my-exhibitions.php">← My Exhibitions</a><a href="new-exhibition.php">New exhibition</a><a href="explore.php">Explore</a></nav>
      <span class="edition">THE EXHIBITION STUDIO</span>
    </div>
  </header>
  <main class="studio-shell">
    <div class="studio-warning" role="note"><span class="warning-dot"></span><strong>Private draft</strong><span>Edit your exhibition and chapters, then save when ready. Publishing is not available yet.</span></div>
    <div class="studio-topline">
      <div>
        <p class="eyebrow">EXHIBITION STUDIO / <?= $id ?></p>
        <h1>Shape a <em>story.</em></h1>
        <p>Shape your story. Save your work privately.</p>
      </div>
      <div class="studio-actions"><span class="status-note" id="save-status" role="status" aria-live="polite" data-state="<?= $state ?>"><?= Auth\escape($feedback) ?></span><button type="button" id="preview-toggle" class="studio-btn" aria-controls="studio-preview" aria-expanded="true">Hide preview</button><button type="submit" form="studio-form" id="save-draft" class="studio-btn"<?= !$editable ? ' disabled' : '' ?>>Save Draft</button></div>
    </div>
    <div class="exhibit-project-bar">
      <div><span class="project-icon">✳</span>
        <div><span class="project-kicker">CURRENT EXHIBITION</span><strong id="project-title"><?= Auth\escape($values['title']) ?></strong></div>
      </div><span class="sample-state">PRIVATE EXHIBITION</span>
    </div>
    <div class="studio-layout" id="studio-layout">
      <aside class="studio-side" aria-label="Chapter organizer">
        <div class="pane-heading"><span>01 / STRUCTURE</span>
          <h2>Chapters</h2>
          <p>The journey your visitors follow.</p>
        </div>
        <button class="chapter-choice active" type="button" id="edit-details" aria-current="true">Exhibition details</button>
        <div class="chapter-list" id="chapter-list"><p class="editor-note">No chapter selected. Use JavaScript to organize chapters and content.</p></div>
        <button class="add-chapter" type="button" id="add-chapter" disabled><span>＋</span> Add a chapter</button>
        <div class="side-footnote"><span>✳</span>
          <p>Each chapter can hold narrative text, visual material, and artifacts.</p>
        </div>
      </aside>
      <section class="studio-editor" aria-labelledby="editor-title">
        <div class="pane-heading editor-pane-heading"><span>02 / CREATE</span>
          <h2 id="editor-title">Exhibition details</h2>
          <p>Edit the beginning of your exhibition.</p>
        </div>
        <form class="editor-inner" id="studio-form" method="post" action="studio.php?id=<?= $id ?>" novalidate data-baseline="<?= Auth\escape(json_encode($baseline, JSON_THROW_ON_ERROR)) ?>">
          <input type="hidden" name="csrf" value="<?= Auth\escape($_SESSION['csrf']) ?>">
          <input type="hidden" name="revision" value="<?= Auth\escape($revision) ?>">
          <input type="hidden" id="exhibition-chapters_json" name="chapters_json" value="<?= Auth\escape($values['chapters_json']) ?>">
          <p class="field-error" id="content-error" role="alert"><?= Auth\escape($errors['content'] ?? '') ?></p>
          <div id="details-editor">
          <div class="chapter-overline"><span>EXHIBITION INFORMATION</span><span class="working-tag"><?= Auth\escape(strtoupper($exhibition['status'])) ?></span></div>
          <label for="exhibition-title">Exhibition title</label><input id="exhibition-title" name="title" class="title-field" type="text" maxlength="100" required value="<?= Auth\escape($values['title']) ?>" aria-invalid="<?= isset($errors['title']) ? 'true' : 'false' ?>" aria-describedby="title-error">
          <p class="field-error" id="title-error"><?= Auth\escape($errors['title'] ?? '') ?></p>
          <label for="exhibition-subtitle">Subtitle <span class="optional">optional</span></label><input id="exhibition-subtitle" name="subtitle" type="text" maxlength="100" value="<?= Auth\escape($values['subtitle']) ?>" aria-invalid="<?= isset($errors['subtitle']) ? 'true' : 'false' ?>" aria-describedby="subtitle-error">
          <p class="field-error" id="subtitle-error"><?= Auth\escape($errors['subtitle'] ?? '') ?></p>
          <label for="exhibition-summary">A short introduction</label><textarea id="exhibition-summary" name="summary" rows="5" maxlength="320" placeholder="What will visitors discover?" aria-invalid="<?= isset($errors['summary']) ? 'true' : 'false' ?>" aria-describedby="summary-error"><?= Auth\escape($values['summary']) ?></textarea>
          <p class="field-error" id="summary-error"><?= Auth\escape($errors['summary'] ?? '') ?></p>
          <label for="exhibition-description">Description <span class="optional">optional</span></label><textarea id="exhibition-description" name="description" rows="6" maxlength="10000" aria-invalid="<?= isset($errors['description']) ? 'true' : 'false' ?>" aria-describedby="description-error"><?= Auth\escape($values['description']) ?></textarea>
          <p class="field-error" id="description-error"><?= Auth\escape($errors['description'] ?? '') ?></p>
          <label for="exhibition-subject">Subject</label><select id="exhibition-subject" name="subject" aria-invalid="<?= isset($errors['subject']) ? 'true' : 'false' ?>" aria-describedby="subject-error">
            <option value="">Choose a subject...</option>
            <?php foreach (Exhibitions\SUBJECTS as $subject): ?>
              <option value="<?= Auth\escape($subject) ?>"<?= $values['subject'] === $subject ? ' selected' : '' ?>><?= Auth\escape($subject) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="field-error" id="subject-error"><?= Auth\escape($errors['subject'] ?? '') ?></p>
          <label for="exhibition-cover">Cover image URL</label><input id="exhibition-cover" name="cover" type="url" maxlength="2048" value="<?= Auth\escape($values['cover']) ?>" placeholder="https://example.com/photo.jpg" aria-invalid="<?= isset($errors['cover']) ? 'true' : 'false' ?>" aria-describedby="cover-help cover-error">
          <p class="field-error" id="cover-error"><?= Auth\escape($errors['cover'] ?? '') ?></p>
          <p class="editor-note" id="cover-help">Use an HTTP or HTTPS image URL you have permission to display. Preview changes are not saved until you choose Save Draft.</p>
          </div>
          <div id="content-editor" hidden></div>
          <?php if (!$editable): ?><p class="editor-note">Copy any unsaved edits before <a href="studio.php?id=<?= $id ?>">reopening Studio</a> to review the saved version.</p><?php endif; ?>
          <noscript><p class="editor-note">Save Draft and metadata editing work without JavaScript. Chapter editing, live preview, and unsaved-change warnings require JavaScript. Existing content is preserved when saving metadata.</p></noscript>
        </form>
      </section>
      <aside class="studio-preview" id="studio-preview" aria-label="Live exhibition preview">
        <div class="pane-heading preview-pane-heading"><span>03 / EXPERIENCE</span>
          <div class="preview-title-row">
            <h2>Exhibition preview</h2><span class="preview-live"><i></i> LIVE</span>
          </div>
          <p>A first glimpse of your idea as you edit.</p>
        </div>
        <div class="preview-stage">
          <div class="preview-art" id="preview-art"<?php if ($previewCover !== null): ?> style="<?= Auth\escape('background-image: url(' . json_encode($previewCover, JSON_THROW_ON_ERROR) . '), linear-gradient(135deg, #183f38, #326e5d); background-size: cover; background-position: center') ?>"<?php endif; ?>><span class="art-label">EXHIBIT / A CURATED JOURNEY</span>
            <div class="preview-orbit" id="preview-orbit"<?= $previewCover !== null ? ' hidden' : '' ?>><i></i><i></i><i></i><i></i></div><span class="art-bottom" id="pv-category"><?= Auth\escape($values['subject'] ?: 'YOUR EXHIBITION') ?></span>
          </div>
          <div class="preview-sheet">
            <p class="preview-eyebrow" id="pv-eyebrow"><?= Auth\escape($values['subject'] ?: 'YOUR EXHIBITION') ?></p>
            <h3 id="pv-title"><?= Auth\escape($values['title']) ?></h3>
            <p id="pv-intro" class="preview-intro"><?= Auth\escape($values['summary'] ?: 'No introduction yet.') ?></p>
            <div id="pv-blocks"></div>
            <p class="preview-end">Every discovery starts with a question. <span>✳</span></p>
          </div>
        </div>
        <div class="preview-foot">Live preview · Save Draft to keep your changes</div>
      </aside>
    </div>
    <p class="studio-outro">Made for the endlessly curious. <span>✳</span> One thoughtful chapter at a time.</p>
  </main>
</body>

</html>