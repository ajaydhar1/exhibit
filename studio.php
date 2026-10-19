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
Exhibitions\requireMethod(['GET']);
try {
  $exhibition = Exhibitions\service()->findOwned($id, (int) $creator['id']);
  if ($exhibition === null) {
    Exhibitions\notFound();
  }
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
    <div class="studio-warning" role="note"><span class="warning-dot"></span><strong>Saved exhibition</strong><span>Your exhibition information is stored in your account. Studio is read-only; editing and publishing are not available yet.</span></div>
    <div class="studio-topline">
      <div>
        <p class="eyebrow">EXHIBITION STUDIO / <?= $id ?></p>
        <h1>Shape a <em>story.</em></h1>
        <p>Your idea is saved. See the beginning of your exhibition.</p>
      </div>
      <div class="studio-actions"><span class="status-note"><i></i> <?= Auth\escape(ucfirst($exhibition['status'])) ?> · Saved</span><button type="button" id="preview-toggle" class="studio-btn" aria-controls="studio-preview" aria-expanded="true">Hide preview</button><button type="button" class="studio-btn studio-btn-disabled" disabled title="Studio editing will be available in a later milestone">Save changes · Later</button></div>
    </div>
    <div class="exhibit-project-bar">
      <div><span class="project-icon">✳</span>
        <div><span class="project-kicker">CURRENT EXHIBITION</span><strong><?= Auth\escape($exhibition['title']) ?></strong></div>
      </div><span class="sample-state">SAVED INFORMATION</span>
    </div>
    <div class="studio-layout" id="studio-layout">
      <aside class="studio-side" aria-label="Chapter organizer">
        <div class="pane-heading"><span>01 / STRUCTURE</span>
          <h2>Chapters</h2>
          <p>The journey your visitors follow.</p>
        </div>
        <div class="chapter-list" id="chapter-list"><p class="editor-note">Chapter tools are coming in a later milestone. Creating a draft does not add chapters.</p></div>
        <button class="add-chapter" type="button" id="add-chapter" disabled title="Chapter editing is not available yet"><span>＋</span> Add a chapter</button>
        <div class="side-footnote"><span>✳</span>
          <p>Each chapter can hold narrative text, visual material, and artifacts.</p>
        </div>
      </aside>
      <section class="studio-editor" aria-labelledby="editor-title">
        <div class="pane-heading editor-pane-heading"><span>02 / CREATE</span>
          <h2 id="editor-title">Exhibition details</h2>
          <p>The information saved when you created this exhibition.</p>
        </div>
        <div class="editor-inner">
          <div class="chapter-overline"><span>EXHIBITION INFORMATION</span><span class="working-tag">READ ONLY</span></div>
          <label for="exhibition-title">Exhibition title</label><input id="exhibition-title" class="title-field" type="text" value="<?= Auth\escape($exhibition['title']) ?>" readonly>
          <label for="exhibition-summary">A short introduction</label><textarea id="exhibition-summary" rows="5" placeholder="No introduction yet." readonly><?= Auth\escape($exhibition['summary'] ?? '') ?></textarea>
          <label for="exhibition-subject">Subject</label><input id="exhibition-subject" type="text" value="<?= Auth\escape($exhibition['category'] ?? '') ?>" placeholder="No subject selected." readonly>
          <label for="exhibition-cover">Cover image URL</label><input id="exhibition-cover" type="url" value="<?= Auth\escape($exhibition['cover']['url'] ?? '') ?>" placeholder="No cover image selected." readonly>
          <div class="editor-note">You can leave and reopen this exhibition without losing these details. Editing, chapters, and content blocks will be available in a later milestone.</div>
        </div>
      </section>
      <aside class="studio-preview" id="studio-preview" aria-label="Saved exhibition preview">
        <div class="pane-heading preview-pane-heading"><span>03 / EXPERIENCE</span>
          <div class="preview-title-row">
            <h2>Exhibition preview</h2><span class="preview-live"><i></i> SAVED</span>
          </div>
          <p>A first glimpse of your saved idea.</p>
        </div>
        <div class="preview-stage">
          <div class="preview-art"<?php if ($exhibition['cover'] !== null): ?> style="<?= Auth\escape('background-image: url(' . json_encode($exhibition['cover']['url'], JSON_THROW_ON_ERROR) . '), linear-gradient(135deg, #183f38, #326e5d); background-size: cover; background-position: center') ?>"<?php endif; ?>><span class="art-label">EXHIBIT / A CURATED JOURNEY</span>
            <?php if ($exhibition['cover'] === null): ?><div class="preview-orbit"><i></i><i></i><i></i><i></i></div><?php endif; ?><span class="art-bottom"><?= Auth\escape($exhibition['category'] ?? 'YOUR EXHIBITION') ?></span>
          </div>
          <div class="preview-sheet">
            <p class="preview-eyebrow" id="pv-eyebrow"><?= Auth\escape($exhibition['category'] ?? 'YOUR EXHIBITION') ?></p>
            <h3 id="pv-title"><?= Auth\escape($exhibition['title']) ?></h3>
            <p id="pv-intro" class="preview-intro"><?= Auth\escape($exhibition['summary'] ?? 'No introduction yet.') ?></p>
            <p class="preview-end">Every discovery starts with a question. <span>✳</span></p>
          </div>
        </div>
        <div class="preview-foot">Saved information · Studio editing is not available yet</div>
      </aside>
    </div>
    <p class="studio-outro">Made for the endlessly curious. <span>✳</span> One thoughtful chapter at a time.</p>
  </main>
</body>

</html>