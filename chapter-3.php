<?php // Exhibit / authored historical overview, no database. 
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
  <meta name="description" content="Chapter 3 of The Birth of the Internet — Exhibit.">
  <title>Chapter 03 — Exhibit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
</head>

<body>
  <header class="site-header">
    <div class="shell header-inner"><a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
      <nav aria-label="Main navigation"><a href="exhibition.php">Exhibition overview</a><a href="index.php#featured">Featured exhibition</a></nav><span class="edition">THE BIRTH OF THE INTERNET</span>
    </div>
  </header>
  <main id="main">
    <section class="viewer-top shell" aria-labelledby="chapter-title"><a class="back-link" href="exhibition.php#chapters">← Back to chapters</a>
      <div class="viewer-progress-meta"><span>THE BIRTH OF THE INTERNET</span><span>CHAPTER 03 OF 05</span></div>
      <div class="viewer-progress" role="progressbar" aria-label="Exhibition chapter progress" aria-valuemin="0" aria-valuemax="5" aria-valuenow="3"><span style="width:60%"></span></div>
      <div class="viewer-heading-grid">
        <div>
          <p class="eyebrow"><span class="eyebrow-line"></span> 1983–1990 / A NETWORK OF NETWORKS</p>
          <h1 id="chapter-title">A Common<br><em>Language.</em></h1>
        </div>
        <p class="viewer-intro">Connecting computers was only the beginning. Different networks needed shared rules before they could become one larger internet.</p>
      </div>
    </section>
    <section class="chapter-feature-stage">
      <div class="shell">
        <div class="feature-graphic protocol-graphic" role="img" aria-label="Conceptual illustration of three distinct networks exchanging addressed packets using common internet protocols">
          <div class="protocol-network"><span>NETWORK A</span><strong>1010</strong></div>
          <div class="protocol-arrow">⇄ <small>TCP / IP</small></div>
          <div class="protocol-network"><span>NETWORK B</span><strong>0101</strong></div>
          <div class="protocol-arrow">⇄ <small>TCP / IP</small></div>
          <div class="protocol-network"><span>NETWORK C</span><strong>1100</strong></div>
        </div>
        <div class="stage-caption"><span>FIG. 03 — SHARED RULES MAKE CONNECTION POSSIBLE</span><span>ORIGINAL INTERPRETIVE ILLUSTRATION</span></div>
      </div>
    </section>
    <section class="viewer-story shell">
      <div class="viewer-side-note"><span class="eyebrow">01 / THE AGREEMENT</span><span>Many networks. One way to communicate.</span></div>
      <div class="viewer-prose">
        <h2>A language for<br><em>many networks.</em></h2>
        <p class='dropcap'>On January 1, 1983, ARPANET switched to the TCP/IP protocol suite, a milestone in the development of the internet. Instead of requiring every network to work in the same way internally, common protocols let different networks exchange information.</p>
        <p>The Internet Protocol (IP) helps address and route packets between networks. The Transmission Control Protocol (TCP) can provide reliable, ordered delivery for applications that need it. Together, they made diverse networks interoperable.</p>
        <p>The internet did not spring into existence in a single moment. Researchers, network operators, and institutions developed and adopted the standards over years.</p>
      </div>
    </section>
    <section class="artifact-band">
      <div class="shell artifact-grid">
        <div class="artifact-visual later-artifact">
          <div class="artifact-label">CONCEPT / INTERNET PROTOCOLS</div>
          <div class="exhibit-mini-visual"><span>DEVICE</span><b>IP PACKET →</b><span>NETWORK</span></div>
          <div class="artifact-bottom">EXHIBIT / ORIGINAL INTERPRETIVE GRAPHIC</div>
        </div>
        <div class="artifact-copy">
          <p class="eyebrow">CONCEPT / INTERNET PROTOCOLS</p>
          <h2>Different systems.<br><em>Shared rules.</em></h2>
          <p>A common protocol is like an agreed-upon way to address and deliver a letter. Networks can differ internally, yet still exchange packets because they recognize the same conventions.</p>
          <p class="artifact-caption">Conceptual diagram created for this prototype; not a historical reproduction.</p>
        </div>
      </div>
    </section>
    <section class="viewer-story shell story-second">
      <div class="viewer-side-note"><span class="eyebrow">02 / FINDING AN ADDRESS</span><span>The network became easier to navigate.</span></div>
      <div class="viewer-prose">
        <h2>Names instead<br><em>of numbers.</em></h2>
        <p>As the network expanded, remembering numerical addresses became impractical. The Domain Name System (DNS), developed in the 1980s, created a distributed way to translate human-friendly names into network addresses.</p>
        <p>By the end of the decade, the pieces were in place for a new layer of the connected world: a system for linking and navigating documents. That system would become the World Wide Web.</p>
      </div>
    </section>
    <section class="viewer-ending shell" aria-label="Chapter navigation">
      <div>
        <p class="eyebrow">CHAPTER 03 COMPLETE</p>
        <h2>The pieces<br><em>come together.</em></h2>
        <p>Next: the web makes connected information easier for ordinary people to explore.</p>
      </div>
      <div class="viewer-ending-actions"><span class="viewer-next-muted">UP NEXT · CHAPTER 04 / THE WEB GOES PUBLIC</span><a class="primary-link" href="chapter-4.php">Continue to Chapter 04 →</a><a class="viewer-secondary-link" href="chapter-2.php">← Previous chapter</a><a class="viewer-secondary-link" href="exhibition.php#chapters">Browse all chapters ↗</a><a class="viewer-secondary-link" href="#main">Back to chapter start ↑</a></div>
    </section>
  </main>
  <footer class="footer">
    <div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div>
  </footer>
</body>

</html>