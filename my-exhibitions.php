<?php
// Design prototype only. These are sample records, not authenticated user data.
$sampleExhibitions = [
    [
        'title' => 'The Birth of the Internet',
        'category' => 'TECHNOLOGY & HISTORY',
        'description' => 'An unlikely experiment that connected the world, told across five chapters.',
        'chapters' => 5,
        'status' => 'Published',
        'visual' => 'network',
        'link' => 'exhibition.php',
    ],
    [
        'title' => 'Cities After Dark',
        'category' => 'PLACES & CULTURE',
        'description' => 'A sample working exhibition about the places that come alive after sunset.',
        'chapters' => 3,
        'status' => 'Draft',
        'visual' => 'night',
        'link' => null,
    ],
];
function exhibitEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="description" content="A concept preview of the Exhibit creator dashboard.">
  <title>My Exhibitions — Exhibit (Design Preview)</title>
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
      <nav aria-label="Main navigation"><a href="explore.php">Explore</a><a href="new-exhibition.php">New exhibition</a><a href="studio.php">Studio</a><a href="sign-in.php">Sign in</a></nav>
      <span class="edition">CREATOR WORKSPACE · PREVIEW</span>
    </div>
  </header>
  <main class="workspace shell">
    <div class="prototype-banner" role="note"><span class="banner-indicator"></span><strong>Design preview</strong><span>Sample exhibitions only. Accounts, editing, and publishing are not yet enabled.</span></div>
    <section class="workspace-intro" aria-labelledby="workspace-title">
      <div>
        <p class="eyebrow"><span class="eyebrow-line"></span> YOUR CREATIVE SPACE / 001</p>
        <h1 id="workspace-title">My <em>Exhibitions.</em></h1>
        <p>Every story begins somewhere. Gather your ideas, shape them into chapters, and make something worth exploring.</p>
      </div>
      <a class="new-exhibition-preview" href="new-exhibition.php"><span aria-hidden="true">＋</span> New exhibition <small>Preview →</small></a>
    </section>
    <section class="workspace-collection" aria-labelledby="collection-title">
      <div class="workspace-section-heading"><div><p class="eyebrow">THE WORKSPACE</p><h2 id="collection-title">Your exhibitions<span class="heading-dot">.</span></h2></div><span class="collection-count">02 / SAMPLE PROJECTS</span></div>
      <div class="workspace-grid">
      <?php foreach ($sampleExhibitions as $item): ?>
        <article class="workspace-card">
          <div class="workspace-card-art <?= exhibitEscape($item['visual']) ?>" aria-hidden="true">
            <?php if ($item['visual'] === 'network'): ?>
              <div class="network-art"><span>ARPANET</span><div class="nodes"><i></i><i></i><i></i><i></i></div><b>www.</b></div>
            <?php else: ?>
              <div class="night-art"><span class="night-moon"></span><i></i><i></i><i></i><i></i><span class="night-caption">AFTER / DARK</span></div>
            <?php endif; ?>
            <span class="card-art-index">EXHIBIT / <?= $item['visual']==='network' ? '001' : '002' ?></span>
          </div>
          <div class="workspace-card-content">
            <div class="card-topline"><span class="card-category"><?= exhibitEscape($item['category']) ?></span><span class="status <?= $item['status'] === 'Published' ? 'published' : 'draft' ?>"><i></i><?= exhibitEscape($item['status']) ?></span></div>
            <h3><?= exhibitEscape($item['title']) ?></h3>
            <p><?= exhibitEscape($item['description']) ?></p>
            <div class="workspace-card-bottom"><span><?= (int) $item['chapters'] ?> chapters</span>
              <?php if ($item['link']): ?><a href="<?= exhibitEscape($item['link']) ?>">View exhibition <span aria-hidden="true">↗</span></a>
              <?php else: ?><span class="not-live">Sample draft · Preview only</span><?php endif; ?>
            </div>
            <?php if ($item['visual'] === 'network'): ?><p class="studio-preview-link"><a href="studio.php">Open Studio preview →</a></p><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
    </section>
    <aside class="studio-note"><span class="studio-note-icon" aria-hidden="true">✳</span><div><h2>Thoughtfully made, chapter by chapter.</h2><p>Next, we'll design the Studio where creators can actually build and edit these exhibitions. For now, this is a preview of the workspace experience.</p></div></aside>
  </main>
  <footer class="footer"><div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div></footer>
</body>
</html>
