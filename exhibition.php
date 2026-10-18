<?php
$exhibition = [
    'title' => 'The Birth of the Internet',
    'subtitle' => 'How an unlikely experiment connected the world.',
    'category' => 'TECHNOLOGY / HISTORY',
    'description' => 'Long before the internet became part of everyday life, a handful of researchers were asking a remarkable question: what if computers could talk to one another? Follow the ideas, people, and turning points that made our connected world possible.',
];
$chapters = [
    ['number' => '01', 'era' => '1950s–1960s', 'title' => 'Before the Web', 'description' => 'The ambitious ideas that started it all.'],
    ['number' => '02', 'era' => '1969–1982', 'title' => 'The First Connections', 'description' => 'From ARPANET to a network of networks.'],
    ['number' => '03', 'era' => '1983–1990', 'title' => 'A Common Language', 'description' => 'Protocols and possibilities that opened new doors.'],
    ['number' => '04', 'era' => '1991–1999', 'title' => 'The Web Goes Public', 'description' => 'Browsers, websites, and a new digital frontier.'],
    ['number' => '05', 'era' => '2000s–Today', 'title' => 'Always Connected', 'description' => 'How the internet became a part of daily life.'],
];
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="The Birth of the Internet — a five-chapter digital exhibition on Exhibit.">
  <title><?=e($exhibition['title'])?> — Exhibit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <header class="site-header">
    <div class="shell header-inner">
      <a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
      <nav aria-label="Main navigation"><a href="index.php#featured">Featured exhibition</a><a href="index.php#about">About Exhibit</a></nav>
      <span class="edition">AN OPEN SPACE FOR CURIOSITY</span>
    </div>
  </header>
  <main>
    <section class="overview-intro shell" aria-labelledby="exhibition-title">
      <a class="back-link" href="index.php#featured"><span aria-hidden="true">←</span> All exhibitions</a>
      <div class="overview-title-area">
        <div>
          <p class="eyebrow"><span class="eyebrow-line"></span> EXHIBITION 001 / <?=e($exhibition['category'])?></p>
          <h1 id="exhibition-title">The Birth<br><em>of the Internet.</em></h1>
        </div>
        <div class="overview-aside"><span class="overview-index">01 — 05</span><p>Five chapters about one of the most transformative ideas in modern history.</p></div>
      </div>
      <div class="overview-hero-visual" role="img" aria-label="Stylized green vintage computer terminal displaying an early network connection">
        <div class="overview-orbit overview-orbit-one"></div><div class="overview-orbit overview-orbit-two"></div>
        <div class="overview-screen"><div class="overview-screen-bar"><span></span><span></span><span></span><small>NETWORK / ARCHIVE 001</small></div><div class="overview-screen-body"><span>&gt; ESTABLISHING CONNECTION</span><strong>www.</strong><div class="overview-screen-code">HOST FOUND · PACKET SENT · WORLD CONNECTED_</div></div></div>
        <span class="overview-visual-caption">A WORLD CONNECTED / 1969 → NOW</span>
      </div>
    </section>
    <section class="overview-description shell" aria-label="About this exhibition">
      <div><p class="eyebrow">THE STORY</p><h2>It began with<br><em>a question.</em></h2></div>
      <div class="overview-description-copy"><p class="overview-lede"><?=e($exhibition['subtitle'])?></p><p><?=e($exhibition['description'])?></p><a class="primary-link" href="view.php">Begin exhibition <span aria-hidden="true">↗</span></a><p class="overview-muted-note">All five chapters are ready to explore.</p></div>
    </section>
    <section class="chapter-section" id="chapters" aria-labelledby="chapters-heading"><div class="shell">
      <div class="chapter-section-header"><div><p class="eyebrow">YOUR JOURNEY <span class="section-num">/ 005 CHAPTERS</span></p><h2 id="chapters-heading">Inside the exhibition<span class="heading-dot">.</span></h2></div><span class="section-note">A story told in five parts</span></div>
      <div class="chapters-list">
        <?php foreach ($chapters as $chapter): ?>
        <article class="chapter-row"><span class="chapter-number"><?=e($chapter['number'])?></span><div class="chapter-details"><span class="chapter-era"><?=e($chapter['era'])?></span><h3><?=e($chapter['title'])?></h3><p><?=e($chapter['description'])?></p></div><?php if (true): ?><a class="chapter-pending chapter-live" href="<?= $chapter['number'] === '01' ? 'view.php' : 'chapter-' . (int)$chapter['number'] . '.php' ?>">Enter chapter <span aria-hidden="true">↗</span></a><?php else: ?><span class="chapter-pending">Coming soon</span><?php endif; ?></article>
        <?php endforeach; ?>
      </div>
    </div></section>
    <section class="overview-end shell"><span class="eyebrow">THE EXHIBIT EXPERIENCE</span><p>Every big idea has a story. <em>Stay curious.</em></p><a href="index.php">Return to Exhibit <span aria-hidden="true">↗</span></a></section>
  </main>
  <footer class="footer"><div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?=date('Y')?> Exhibit</span></div></footer>
</body>
</html>
