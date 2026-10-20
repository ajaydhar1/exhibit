<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/exhibitions.php';

use Exhibit\Auth;
use Exhibit\Exhibitions;

$creator = Auth\requireCreator();
Exhibitions\requireMethod(['GET', 'POST']);
$feedback = 'Save a private draft, then open it in Studio.';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth\csrfValid(Auth\post('csrf'))) {
        http_response_code(403);
        $feedback = 'Your form expired. Please try again.';
    } else {
        try {
            $id = Exhibitions\service()->createDraft((int) $creator['id'], $_POST);
            Auth\redirect('studio.php?id=' . $id);
        } catch (Exhibitions\ValidationError $error) {
            http_response_code(422);
            $errors = $error->errors;
            $feedback = 'Please check the highlighted fields.';
        } catch (Throwable $error) {
            Exhibitions\unavailable($error);
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Create an Exhibition — Exhibit</title>
  <meta name="description" content="Start a private exhibition draft on Exhibit.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="assets/new-exhibition.css">
  <script src="assets/new-exhibition.js" defer></script>
</head>

<body>
  <header class="site-header">
    <div class="shell header-inner">
      <a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
      <nav aria-label="Creator navigation"><a href="my-exhibitions.php">← My Exhibitions</a><a href="my-exhibitions.php">Studio</a><a href="explore.php">Explore</a></nav><span class="edition">CREATOR WORKSPACE</span>
    </div>
  </header>
  <main class="create-shell shell">
    <div class="create-note" role="note"><span class="note-spark">✳</span><strong>Begin a draft</strong><span>Save your exhibition privately, then shape its chapters in Studio and publish when ready.</span></div>
    <div class="create-intro">
      <p class="eyebrow"><span class="eyebrow-line"></span> EXHIBITION STUDIO / GETTING STARTED</p>
      <h1>Every exhibition<br>begins with <em>curiosity.</em></h1>
      <p>Give your idea a name, find its focus, and imagine the journey you want to share.</p>
    </div>
    <div class="create-grid">
      <section class="create-form-panel" aria-labelledby="details-heading">
        <div class="section-kicker"><span>01 / THE IDEA</span><span>START HERE</span></div>
        <h2 id="details-heading">Tell us about your exhibition.</h2>
        <form id="create-form" method="post" action="new-exhibition.php" novalidate>
          <input type="hidden" name="csrf" value="<?= Auth\escape($_SESSION['csrf']) ?>">
          <label for="new-title">Exhibition title <span class="required">*</span></label>
          <input type="text" id="new-title" name="title" maxlength="100" placeholder="e.g. The Art of Everyday Objects" autocomplete="off" value="<?= Auth\escape(Auth\post('title')) ?>" aria-invalid="<?= isset($errors['title']) ? 'true' : 'false' ?>" aria-describedby="title-help title-error" required>
          <p class="field-help" id="title-help">Make it memorable. You can always rename it later.</p>
          <p class="field-help field-error" id="title-error"><?= Auth\escape($errors['title'] ?? '') ?></p>
          <label for="new-subtitle">A short introduction</label>
          <textarea id="new-subtitle" name="summary" rows="4" maxlength="320" placeholder="What will visitors discover?" aria-invalid="<?= isset($errors['summary']) ? 'true' : 'false' ?>" aria-describedby="summary-error"><?= Auth\escape(Auth\post('summary')) ?></textarea>
          <p class="field-help field-error" id="summary-error"><?= Auth\escape($errors['summary'] ?? '') ?></p>
          <div class="field-line"><label for="new-subject">Subject</label><span>OPTIONAL</span></div>
          <select id="new-subject" name="subject" aria-invalid="<?= isset($errors['subject']) ? 'true' : 'false' ?>" aria-describedby="subject-error">
            <option value="">Choose a subject…</option>
            <?php foreach (Exhibitions\SUBJECTS as $subject): ?>
              <option value="<?= Auth\escape($subject) ?>"<?= Auth\post('subject') === $subject ? ' selected' : '' ?>><?= Auth\escape($subject) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="field-help field-error" id="subject-error"><?= Auth\escape($errors['subject'] ?? '') ?></p>
          <div class="field-line"><label for="new-cover">Cover image URL</label><span>OPTIONAL</span></div>
          <input type="url" id="new-cover" name="cover" inputmode="url" maxlength="2048" placeholder="https://example.com/photo.jpg" value="<?= Auth\escape(Auth\post('cover')) ?>" aria-invalid="<?= isset($errors['cover']) ? 'true' : 'false' ?>" aria-describedby="cover-help cover-error">
          <p class="field-help" id="cover-help">Images aren't uploaded. Use an HTTP or HTTPS image URL you have permission to display.</p>
          <p class="field-help field-error" id="cover-error"><?= Auth\escape($errors['cover'] ?? '') ?></p>
          <div class="form-footer">
            <p id="feedback" role="status" aria-live="polite"><?= Auth\escape($feedback) ?></p><button class="create-primary" type="submit">Continue to Studio <span aria-hidden="true">↗</span></button>
          </div>
        </form>
      </section>
      <aside class="create-preview-panel" aria-labelledby="preview-heading">
        <div class="section-kicker"><span>02 / A FIRST GLIMPSE</span><span class="live-indicator"><i></i> LIVE PREVIEW</span></div>
        <h2 id="preview-heading">See your idea take shape.</h2>
        <div class="exhibition-cover" id="cover-visual">
          <div class="cover-decoration" aria-hidden="true"><i></i><i></i><i></i></div>
          <div class="cover-wordmark">EXHIBIT / AN ORIGINAL EXHIBITION</div>
          <div class="cover-foot">MADE FOR THE ENDLESSLY CURIOUS ✳</div>
        </div>
        <div class="preview-content">
          <p class="preview-category" id="pv-category">YOUR SUBJECT</p>
          <h3 id="pv-title">Your story starts here.</h3>
          <p class="preview-description" id="pv-summary">An exhibition worth exploring begins with a question. What will yours be?</p><span class="preview-chapter">CHAPTER 01 · WAITING TO BE WRITTEN</span>
        </div>
        <div class="preview-hint"><span>✳</span>
          <p>Open your saved idea in Studio to add chapters, text, artifacts, and images before publishing.</p>
        </div>
      </aside>
    </div>
    <div class="create-after"><span>ONE IDEA AT A TIME</span>
      <p>Wonderful things can begin simply.</p>
    </div>
  </main>
  <footer class="footer">
    <div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div>
  </footer>
</body>

</html>