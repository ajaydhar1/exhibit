<?php
$featured = [
  'eyebrow' => 'FEATURED EXHIBITION · TECHNOLOGY & HISTORY',
  'title' => 'The Birth of the Internet',
  'description' => 'Before the internet was everywhere, it was an experiment. Explore the ideas, machines, and people that connected the world.',
  'chapters' => 5,
];
function e(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">

<head>

  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-E5SFFE66TD"></script>
  <script>
    window.dataLayer = window.dataLayer || [];

    function gtag() {
      dataLayer.push(arguments);
    }
    gtag('js', new Date());

    gtag('config', 'G-E5SFFE66TD');
  </script>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="Exhibit — Discover fascinating stories through thoughtfully curated digital exhibitions.">
  <title>Exhibit — Curiosity, on display.</title>
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
      <nav aria-label="Main navigation"><a href="#featured">Featured exhibition</a><a href="#about">About Exhibit</a></nav>
      <span class="edition">AN OPEN SPACE FOR CURIOSITY</span>
    </div>
  </header>
  <main>
    <section class="hero shell" aria-labelledby="hero-heading">
      <div class="hero-copy">
        <p class="eyebrow"><span class="eyebrow-line"></span> WELCOME TO EXHIBIT</p>
        <h1 id="hero-heading">Curiosity,<br><em>on display.</em></h1>
        <p class="hero-description">A new home for stories worth exploring. Step inside digital exhibitions that bring ideas, history, culture, and discovery to life.</p>
        <a class="primary-link" href="#featured">Explore our first exhibition <span aria-hidden="true">↗</span></a>
        <p class="hero-footnote">A small beginning. An entire world to explore.</p>
      </div>
      <div class="hero-art" role="img" aria-label="Abstract architectural illustration resembling an illuminated exhibition room">
        <div class="art-frame">
          <div class="arch arch-one"></div>
          <div class="arch arch-two"></div>
          <div class="art-sun"></div>
          <div class="art-floor"></div>
          <div class="art-caption">01 / AN INVITATION TO EXPLORE</div>
        </div>
      </div>
    </section>
    <section class="featured-section" id="featured" aria-labelledby="featured-heading">
      <div class="shell">
        <div class="section-heading">
          <div>
            <p class="eyebrow">START EXPLORING <span class="section-num">/ 001</span></p>
            <h2 id="featured-heading">Featured exhibition<span class="heading-dot">.</span></h2>
          </div><span class="section-note">One great story at a time</span>
        </div>
        <article class="featured-card">
          <div class="featured-visual">
            <div class="terminal-window" aria-hidden="true">
              <div class="window-top"><span></span><span></span><span></span><small>NETWORK / 1969—PRESENT</small></div>
              <div class="terminal-text"><span class="terminal-prompt">&gt; CONNECTING WORLDS_</span><strong>www.</strong><span class="terminal-line"></span><span class="terminal-grid"></span></div>
            </div><span class="visual-label">ARCHIVE NO. 001</span>
          </div>
          <div class="featured-copy">
            <p class="eyebrow"><?= e($featured['eyebrow']) ?></p>
            <h3><?= e($featured['title']) ?></h3>
            <p><?= e($featured['description']) ?></p>
            <div class="featured-bottom"><span><?= (int)$featured['chapters'] ?> chapters <span class="dot-separator">·</span> Exhibition overview</span><a class="soon-pill" href="exhibition.php">View exhibition <span aria-hidden="true">↗</span></a></div>
          </div>
        </article>
      </div>
    </section>
    <section class="about-section shell" id="about">
      <div>
        <p class="eyebrow">THE IDEA</p>
        <h2>More than a gallery.<br><em>A journey through ideas.</em></h2>
      </div>
      <p>Exhibit is a place to discover subjects through carefully crafted chapters, images, documents, and stories. The first exhibition is taking shape — and this is just the beginning.</p>
    </section>
  </main>
  <footer class="footer">
    <div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div>
  </footer>
</body>

</html>