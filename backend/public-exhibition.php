<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/exhibitions.php';

use Exhibit\Auth;
use Exhibit\Exhibitions;

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
Exhibitions\requireMethod(['GET', 'HEAD']);
$id = Exhibitions\exhibitionId($_GET['id'] ?? null);
$chapterId = $publicChapterView ? Exhibitions\exhibitionId($_GET['chapter'] ?? null) : null;
if ($id === null || ($publicChapterView && $chapterId === null)
    || (!$publicChapterView && array_key_exists('chapter', $_GET))) {
    Exhibitions\notFound();
}
try {
    $exhibition = Exhibitions\service()->findPublished($id);
    if ($exhibition === null) {
        Exhibitions\notFound();
    }
} catch (Throwable $error) {
    Exhibitions\unavailable($error);
}
$chapters = $exhibition['chapters'];
$count = count($chapters);
$chapterIndex = null;
if ($publicChapterView) {
    foreach ($chapters as $index => $item) {
        if ($item['id'] === (string) $chapterId) {
            $chapterIndex = $index;
            break;
        }
    }
    if ($chapterIndex === null) {
        Exhibitions\notFound();
    }
    $chapter = $chapters[$chapterIndex];
}
$overviewUrl = 'exhibition.php?id=' . $id;
$chapterUrl = static fn (array $item): string => 'view.php?id=' . $id . '&chapter=' . $item['id'];
$number = static fn (int $value): string => str_pad((string) $value, 2, '0', STR_PAD_LEFT);
$pageTitle = $publicChapterView ? $chapter['title'] . ' — ' . $exhibition['title'] : $exhibition['title'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= Auth\escape($exhibition['summary'] ?? $exhibition['title']) ?>">
  <title><?= Auth\escape($pageTitle) ?> — Exhibit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="assets/public-exhibition.css">
</head>
<body class="public-exhibition">
  <header class="site-header"><div class="shell header-inner">
    <a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
    <nav aria-label="Main navigation"><a href="<?= Auth\escape($overviewUrl) ?>">Exhibition overview</a><a href="explore.php">Explore</a></nav>
    <span class="edition">AN OPEN SPACE FOR CURIOSITY</span>
  </div></header>
  <main id="main">
    <?php if (!$publicChapterView): ?>
      <section class="overview-intro shell" aria-labelledby="exhibition-title">
        <a class="back-link" href="explore.php">← Explore Exhibit</a>
        <div class="overview-title-area">
          <div>
            <p class="eyebrow"><span class="eyebrow-line"></span> EXHIBITION<?= $exhibition['category'] !== null ? ' / ' . Auth\escape($exhibition['category']) : '' ?></p>
            <h1 id="exhibition-title"><?= Auth\escape($exhibition['title']) ?></h1>
          </div>
          <div class="overview-aside"><span class="overview-index"><?= $number($count) ?></span><p><?= $count ?> <?= $count === 1 ? 'chapter' : 'chapters' ?> to explore.</p></div>
        </div>
        <?php if ($exhibition['cover'] !== null): ?>
          <div class="overview-hero-visual public-cover"><img src="<?= Auth\escape($exhibition['cover']['url']) ?>" alt="" referrerpolicy="no-referrer"></div>
        <?php endif; ?>
      </section>
      <section class="overview-description shell" aria-label="About this exhibition">
        <div><p class="eyebrow">THE STORY</p><h2>A journey<br><em>of discovery.</em></h2></div>
        <div class="overview-description-copy public-prose">
          <?php if ($exhibition['subtitle'] !== null): ?><p class="overview-lede"><?= Auth\escape($exhibition['subtitle']) ?></p><?php endif; ?>
          <?php if ($exhibition['summary'] !== null): ?><p><?= Auth\escape($exhibition['summary']) ?></p><?php endif; ?>
          <?php if ($exhibition['description'] !== null): ?><p><?= Auth\escape($exhibition['description']) ?></p><?php endif; ?>
          <?php if ($count > 0): ?><a class="primary-link" href="<?= Auth\escape($chapterUrl($chapters[0])) ?>">Begin exhibition <span aria-hidden="true">↗</span></a><?php endif; ?>
        </div>
      </section>
      <section class="chapter-section" id="chapters" aria-labelledby="chapters-heading"><div class="shell">
        <div class="chapter-section-header"><div><p class="eyebrow">YOUR JOURNEY / <?= $count ?> <?= $count === 1 ? 'CHAPTER' : 'CHAPTERS' ?></p><h2 id="chapters-heading">Inside the exhibition<span class="heading-dot">.</span></h2></div></div>
        <div class="chapters-list">
          <?php foreach ($chapters as $index => $item): ?>
            <article class="chapter-row"><span class="chapter-number"><?= $number($index + 1) ?></span>
              <div class="chapter-details">
                <?php if ($item['era'] !== ''): ?><span class="chapter-era"><?= Auth\escape($item['era']) ?></span><?php endif; ?>
                <h3><?= Auth\escape($item['title']) ?></h3>
              </div>
              <a class="chapter-pending chapter-live" href="<?= Auth\escape($chapterUrl($item)) ?>">Enter chapter <span aria-hidden="true">↗</span></a>
            </article>
          <?php endforeach; ?>
        </div>
      </div></section>
    <?php else: ?>
      <section class="viewer-top shell" aria-labelledby="chapter-title">
        <a class="back-link" href="<?= Auth\escape($overviewUrl) ?>#chapters">← Back to chapters</a>
        <div class="viewer-progress-meta"><span><?= Auth\escape($exhibition['title']) ?></span><span>CHAPTER <?= $number($chapterIndex + 1) ?> OF <?= $number($count) ?></span></div>
        <div class="viewer-progress" role="progressbar" aria-label="Exhibition chapter progress" aria-valuemin="0" aria-valuemax="<?= $count ?>" aria-valuenow="<?= $chapterIndex + 1 ?>"><span style="width:<?= ($chapterIndex + 1) / $count * 100 ?>%"></span></div>
        <div class="viewer-heading-grid">
          <div><p class="eyebrow"><span class="eyebrow-line"></span> <?= Auth\escape($chapter['era'] !== '' ? $chapter['era'] : 'CHAPTER ' . $number($chapterIndex + 1)) ?></p><h1 id="chapter-title"><?= Auth\escape($chapter['title']) ?></h1></div>
          <?php if ($chapter['intro'] !== ''): ?><p class="viewer-intro public-prose"><?= Auth\escape($chapter['intro']) ?></p><?php endif; ?>
        </div>
      </section>
      <?php foreach ($chapter['blocks'] as $block):
        $content = $block['content'] + ($block['type'] === 'figure'
            ? ['alt' => '', 'caption' => '', 'credit' => ''] : ['heading' => '', 'body' => '']);
      ?>
        <?php if ($block['type'] === 'figure'): ?>
          <figure class="shell public-figure" data-block-id="<?= Auth\escape($block['id']) ?>">
            <img src="<?= Auth\escape($content['url']) ?>" alt="<?= Auth\escape($content['alt']) ?>" loading="lazy" referrerpolicy="no-referrer">
            <?php if ($content['caption'] !== '' || $content['credit'] !== ''): ?>
              <figcaption class="public-prose"><?= Auth\escape($content['caption']) ?><?php if ($content['credit'] !== ''): ?><small><?= Auth\escape($content['credit']) ?></small><?php endif; ?></figcaption>
            <?php endif; ?>
          </figure>
        <?php else: ?>
          <section class="<?= $block['type'] === 'artifact' ? 'artifact-band public-artifact' : 'public-text' ?>" data-block-id="<?= Auth\escape($block['id']) ?>">
            <div class="shell"><div class="viewer-prose public-prose">
              <?php if ($content['heading'] !== ''): ?><h2><?= Auth\escape($content['heading']) ?></h2><?php endif; ?>
              <?php if ($content['body'] !== ''): ?><p><?= Auth\escape($content['body']) ?></p><?php endif; ?>
            </div></div>
          </section>
        <?php endif; ?>
      <?php endforeach; ?>
      <section class="viewer-ending shell" aria-label="Chapter navigation">
        <div><p class="eyebrow">CHAPTER <?= $number($chapterIndex + 1) ?> COMPLETE</p><h2>Stay <em>curious.</em></h2></div>
        <div class="viewer-ending-actions">
          <?php if (isset($chapters[$chapterIndex + 1])): ?><a class="primary-link" rel="next" href="<?= Auth\escape($chapterUrl($chapters[$chapterIndex + 1])) ?>">Next: <?= Auth\escape($chapters[$chapterIndex + 1]['title']) ?> →</a><?php else: ?><p>You've reached the end of this exhibition.</p><?php endif; ?>
          <?php if ($chapterIndex > 0): ?><a rel="prev" href="<?= Auth\escape($chapterUrl($chapters[$chapterIndex - 1])) ?>">← Previous: <?= Auth\escape($chapters[$chapterIndex - 1]['title']) ?></a><?php endif; ?>
          <a href="<?= Auth\escape($overviewUrl) ?>#chapters">Back to all chapters</a>
        </div>
      </section>
    <?php endif; ?>
    <section class="overview-end shell"><span class="eyebrow">THE EXHIBIT EXPERIENCE</span><p>Every big idea has a story. <em>Stay curious.</em></p><a href="index.php">Return to Exhibit ↗</a></section>
  </main>
  <footer class="footer"><div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div></footer>
</body>
</html>
