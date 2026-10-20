<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/exhibitions.php';

use Exhibit\Auth;
use Exhibit\Exhibitions;

$creator = Auth\requireCreator();
Exhibitions\requireMethod(['GET']);
try {
  $exhibitions = Exhibitions\service()->listOwned((int) $creator['id']);
} catch (Throwable $error) {
  Exhibitions\unavailable($error);
}
?>
<!doctype html>
<html lang="en">

<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="description" content="Your private exhibition workspace.">
  <title>My Exhibitions — Exhibit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="assets/my-exhibitions.css">
</head>

<body>
  <header class="site-header">
    <div class="shell header-inner">
      <a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
      <nav aria-label="Main navigation"><a href="explore.php">Explore</a><a href="new-exhibition.php">New exhibition</a><a href="my-exhibitions.php">Studio</a><a href="sign-in.php">Account</a></nav>
      <span class="edition">CREATOR WORKSPACE</span>
    </div>
  </header>
  <main class="workspace shell">
    <div class="prototype-banner" role="note"><span class="banner-indicator"></span><strong>Your creator workspace</strong><span>Drafts stay private. Edit and save in Studio, then publish when your exhibition is ready.</span></div>
    <section class="workspace-intro" aria-labelledby="workspace-title">
      <div>
        <p class="eyebrow"><span class="eyebrow-line"></span> YOUR CREATIVE SPACE / 001</p>
        <h1 id="workspace-title">My <em>Exhibitions.</em></h1>
        <p>Every story begins somewhere. Gather your ideas, shape them into chapters, and make something worth exploring.</p>
      </div>
      <a class="new-exhibition-preview" href="new-exhibition.php"><span aria-hidden="true">＋</span> New exhibition <small>Begin →</small></a>
    </section>
    <section class="workspace-collection" aria-labelledby="collection-title">
      <div class="workspace-section-heading">
        <div>
          <p class="eyebrow">THE WORKSPACE</p>
          <h2 id="collection-title">Your exhibitions<span class="heading-dot">.</span></h2>
        </div><span class="collection-count"><?= str_pad((string) count($exhibitions), 2, '0', STR_PAD_LEFT) ?> / PROJECTS</span>
      </div>
      <?php if ($exhibitions === []): ?>
        <div class="studio-note" id="empty-exhibitions"><span class="studio-note-icon" aria-hidden="true">✳</span>
          <div><h2>Your first idea belongs here.</h2><p>You haven't created an exhibition yet. <a href="new-exhibition.php">Create your first exhibition →</a></p></div>
        </div>
      <?php endif; ?>
      <div class="workspace-grid">
        <?php foreach ($exhibitions as $index => $item): ?>
          <article class="workspace-card">
            <div class="workspace-card-art network" aria-hidden="true"<?php if ($item['cover'] !== null): ?> style="<?= Auth\escape('background-image: url(' . json_encode($item['cover']['url'], JSON_THROW_ON_ERROR) . '), linear-gradient(135deg, #102e2d, #286455); background-size: cover; background-position: center') ?>"<?php endif; ?>>
              <?php if ($item['cover'] === null): ?>
                <div class="network-art"><span>AN ORIGINAL EXHIBITION</span>
                  <div class="nodes"><i></i><i></i><i></i><i></i></div><b>✳</b>
                </div>
              <?php endif; ?>
              <span class="card-art-index">EXHIBIT / <?= str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT) ?></span>
            </div>
            <div class="workspace-card-content">
              <div class="card-topline"><span class="card-category"><?= Auth\escape($item['category'] ?? 'NO SUBJECT') ?></span><span class="status <?= $item['status'] === 'published' ? 'published' : 'draft' ?>"><i></i><?= Auth\escape(ucfirst($item['status'])) ?></span></div>
              <h3><?= Auth\escape($item['title']) ?></h3>
              <p><?= Auth\escape($item['summary'] ?? 'No introduction yet.') ?></p>
              <div class="workspace-card-bottom"><span><?= (int) $item['chapter_count'] ?> <?= (int) $item['chapter_count'] === 1 ? 'chapter' : 'chapters' ?></span>
                <a href="studio.php?id=<?= (int) $item['id'] ?>">Open Studio <span aria-hidden="true">↗</span></a>
                <?php if ($item['status'] === 'published'): ?><a href="exhibition.php?id=<?= (int) $item['id'] ?>">View public exhibition <span aria-hidden="true">↗</span></a><?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <aside class="studio-note"><span class="studio-note-icon" aria-hidden="true">✳</span>
      <div>
        <h2>Thoughtfully made, chapter by chapter.</h2>
        <p>Open a draft in Studio to shape its chapters and content. Published exhibitions are public and read-only.</p>
      </div>
    </aside>
  </main>
  <footer class="footer">
    <div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div>
  </footer>
</body>

</html>