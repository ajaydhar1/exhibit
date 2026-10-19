<?php
// Exhibit prototype: Chapter 2, authored sample content, no database.
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
  <meta name="description" content="Chapter 2 of The Birth of the Internet: The First Connections.">
  <title>Chapter 02: The First Connections — Exhibit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
</head>

<body>
  <header class="site-header">
    <div class="shell header-inner"><a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
      <nav aria-label="Main navigation"><a href="exhibition.php">Exhibition overview</a><a href="explore.php">Explore</a></nav><span class="edition">THE BIRTH OF THE INTERNET</span>
    </div>
  </header>
  <main id="main">
    <section class="viewer-top shell" aria-labelledby="chapter-title"><a class="back-link" href="exhibition.php#chapters"><span aria-hidden="true">←</span> Back to chapters</a>
      <div class="viewer-progress-meta"><span>THE BIRTH OF THE INTERNET</span><span>CHAPTER 02 OF 05</span></div>
      <div class="viewer-progress" role="progressbar" aria-label="Exhibition chapter progress" aria-valuemin="0" aria-valuemax="5" aria-valuenow="2"><span style="width:40%"></span></div>
      <div class="viewer-heading-grid">
        <div>
          <p class="eyebrow"><span class="eyebrow-line"></span> 1969–1982 / NETWORKS TAKE SHAPE</p>
          <h1 id="chapter-title">The First<br><em>Connections.</em></h1>
        </div>
        <p class="viewer-intro">In 1969, an experiment began linking distant computers. The first message was only two letters long — but it hinted at a much bigger world.</p>
      </div>
    </section>
    <section class="connection-stage" aria-label="Illustration of the first ARPANET message">
      <div class="shell connection-stage-inner">
        <div class="connection-illustration" role="img" aria-label="Original conceptual illustration: two connected terminal screens, one sending LO and one receiving LO">
          <div class="conn-terminal"><span class="conn-terminal-label">UCLA · TERMINAL</span><strong>LO<span class="cursor">_</span></strong><small>ATTEMPTED: LOGIN</small></div>
          <div class="conn-signal" aria-hidden="true"><span></span><b>→</b><span></span></div>
          <div class="conn-terminal"><span class="conn-terminal-label">SRI · TERMINAL</span><strong>LO</strong><small>RECEIVED: LO</small></div>
        </div>
        <div class="stage-caption"><span>FIG. 02 — THE FIRST MESSAGE, OCTOBER 1969</span><span>ORIGINAL INTERPRETIVE ILLUSTRATION</span></div>
      </div>
    </section>
    <section class="viewer-story shell">
      <div class="viewer-side-note"><span class="eyebrow">01 / A MESSAGE</span><span>A small beginning with an outsized legacy.</span></div>
      <div class="viewer-prose">
        <h2>Two letters.<br><em>A new possibility.</em></h2>
        <p class="dropcap">On October 29, 1969, a team at UCLA tried to send the word “LOGIN” to a computer at the Stanford Research Institute over ARPANET. The remote system crashed after the first two characters reached it.</p>
        <p>“LO” became the first message of a network that would help shape modern computing. The full login was successfully completed later that evening.</p>
        <p>It wasn't a public internet, and the World Wide Web did not yet exist. This was a research network, built for connecting institutions and exploring new ways to share computing resources.</p>
      </div>
    </section>
    <section class="artifact-band">
      <div class="shell artifact-grid">
        <div class="artifact-visual connection-artifact" role="img" aria-label="Stylized illustration of four early ARPANET nodes connected across research institutions">
          <div class="artifact-label">ARPANET / 1969</div>
          <div class="early-nodes">
            <div>UCLA</div>
            <div>SRI</div>
            <div>UCSB</div>
            <div>UTAH</div>
          </div>
          <div class="artifact-bottom">FOUR NODES BY THE END OF 1969</div>
        </div>
        <div class="artifact-copy">
          <p class="eyebrow">ARTIFACT / AN EXPANDING NETWORK</p>
          <h2>One link became<br><em>four places.</em></h2>
          <p>By the end of 1969, ARPANET connected four sites: UCLA, Stanford Research Institute, UC Santa Barbara, and the University of Utah. What began with a single connection was becoming a small but functioning network.</p>
          <p class="artifact-caption">Original illustrative graphic, not a reproduction of an archival network map.</p>
        </div>
      </div>
    </section>
    <section class="viewer-story shell story-second">
      <div class="viewer-side-note"><span class="eyebrow">02 / PEOPLE CONNECT</span><span>Networks became useful to their users.</span></div>
      <div class="viewer-prose">
        <h2>More than<br><em>machines.</em></h2>
        <p>As the network grew, people began using it for communication, not just remote computing. In 1971, Ray Tomlinson developed an early network email system and popularized the use of the @ symbol in addresses.</p>
        <p>Throughout the 1970s, different networks emerged, and researchers faced a new challenge: how could unlike networks communicate with one another?</p>
        <p>The answer would help turn separate networks into a true “network of networks.” Our next chapter explores the common language that made that possible.</p>
      </div>
    </section>
    <section class="viewer-ending shell" aria-label="Chapter navigation">
      <div>
        <p class="eyebrow">CHAPTER 02 COMPLETE</p>
        <h2>A network begins<br><em>to grow.</em></h2>
        <p>Continue to Chapter 03 — A Common Language.</p>
      </div>
      <div class="viewer-ending-actions"><span class="viewer-next-muted">UP NEXT · CHAPTER 03 / A COMMON LANGUAGE</span><a class="primary-link" href="chapter-3.php">Continue to Chapter 03 →</a><a class="primary-link" href="view.php">← Previous chapter</a><a class="viewer-secondary-link" href="exhibition.php#chapters">Browse all chapters ↗</a><a class="viewer-secondary-link" href="#main">Back to chapter start ↑</a></div>
    </section>
  </main>
  <footer class="footer">
    <div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div>
  </footer>
</body>

</html>