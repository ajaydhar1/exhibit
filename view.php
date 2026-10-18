<?php
// Exhibit prototype: one authored chapter, no database or authentication.
$chapter = [
  'number' => '01',
  'total' => '05',
  'era' => '1950s–1960s',
  'title' => 'Before the Web',
  'subtitle' => 'The early networks',
];
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="Chapter 1 of The Birth of the Internet: Before the Web — an Exhibit prototype.">
  <title>Chapter 01: Before the Web — Exhibit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site-header"><div class="shell header-inner">
  <a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
  <nav aria-label="Main navigation"><a href="exhibition.php">Exhibition overview</a><a href="index.php#featured">Featured exhibition</a></nav>
  <span class="edition">THE BIRTH OF THE INTERNET</span>
</div></header>
<main id="main">
  <section class="viewer-top shell" aria-labelledby="chapter-title">
    <a class="back-link" href="exhibition.php#chapters"><span aria-hidden="true">←</span> Back to chapters</a>
    <div class="viewer-progress-meta"><span>THE BIRTH OF THE INTERNET</span><span>CHAPTER <?=e($chapter['number'])?> OF <?=e($chapter['total'])?></span></div>
    <div class="viewer-progress" role="progressbar" aria-label="Exhibition chapter progress" aria-valuemin="0" aria-valuemax="5" aria-valuenow="1"><span></span></div>
    <div class="viewer-heading-grid">
      <div><p class="eyebrow"><span class="eyebrow-line"></span> <?=e($chapter['era'])?> / <?=e($chapter['subtitle'])?></p><h1 id="chapter-title">Before<br><em>the Web.</em></h1></div>
      <p class="viewer-intro">Long before browsers and websites, researchers imagined something extraordinary: computers that could communicate across great distances.</p>
    </div>
  </section>
  <section class="network-stage" aria-label="Illustrated network diagram">
    <div class="shell stage-inner">
      <div class="network-art" role="img" aria-label="Conceptual diagram of four connected computer nodes — an illustration, not an archival ARPANET map">
        <svg viewBox="0 0 900 410" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
          <defs><linearGradient id="nodeGlow" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#c1d8b7"/><stop offset="1" stop-color="#7eaa92"/></linearGradient></defs>
          <g stroke="#92b7a0" stroke-width="2" fill="none" stroke-dasharray="5 10" opacity=".75"><path d="M173 230 394 110 665 195 480 310 173 230 665 195"/><path d="M394 110 480 310"/></g>
          <g fill="#173a32" stroke="url(#nodeGlow)" stroke-width="3"><circle cx="173" cy="230" r="45"/><circle cx="394" cy="110" r="45"/><circle cx="665" cy="195" r="45"/><circle cx="480" cy="310" r="45"/></g>
          <g stroke="#b4d6b3" fill="none" stroke-width="2"><rect x="157" y="216" width="32" height="23" rx="2"/><path d="M164 244h18m-9-5v5"/><rect x="378" y="96" width="32" height="23" rx="2"/><path d="M385 124h18m-9-5v5"/><rect x="649" y="181" width="32" height="23" rx="2"/><path d="M656 209h18m-9-5v5"/><rect x="464" y="296" width="32" height="23" rx="2"/><path d="M471 324h18m-9-5v5"/></g>
          <g fill="#cde3c9" font-family="monospace" font-size="13" letter-spacing="2"><text x="122" y="305">NODE 01</text><text x="343" y="49">NODE 02</text><text x="614" y="270">NODE 03</text><text x="429" y="384">NODE 04</text></g>
        </svg>
      </div>
      <div class="stage-caption"><span>FIG. 01 — THE IDEA OF A CONNECTED NETWORK</span><span>ORIGINAL CONCEPTUAL ILLUSTRATION</span></div>
    </div>
  </section>
  <section class="viewer-story shell" aria-labelledby="story-start"><div class="viewer-side-note"><span class="eyebrow">01 / THE IDEA</span><span>Networks begin with a question.</span></div><div class="viewer-prose">
    <h2 id="story-start">What if machines could <em>talk?</em></h2>
    <p class="dropcap">In the middle of the twentieth century, computers were large, expensive, and often isolated. Information lived inside individual machines, and using one frequently meant being physically close to it.</p>
    <p>Researchers began to explore a different possibility. Instead of treating each computer as an island, could they connect machines so that people could share information and computing resources?</p>
    <p>That question helped set the stage for the networks that followed. The familiar World Wide Web was still decades away.</p>
  </div></section>
  <section class="artifact-band"><div class="shell artifact-grid"><div class="artifact-visual" role="img" aria-label="Original typographic illustration of packet-switched messages travelling through a network"><div class="artifact-label">AN IDEA TAKES SHAPE / 1960s</div><div class="packet-path"><span>MESSAGE</span><i>→</i><span>PACKETS</span><i>→</i><span>NETWORK</span></div><div class="artifact-bottom">DATA DOES NOT NEED A SINGLE ROAD.</div></div><div class="artifact-copy"><p class="eyebrow">CONCEPT / PACKET SWITCHING</p><h2>Small pieces.<br><em>Big possibilities.</em></h2><p>One important approach was packet switching: breaking information into smaller units that a network could carry and reassemble. Researchers developed related ideas independently in the 1960s, laying groundwork for practical computer networking.</p><p class="artifact-caption">Illustrative diagram — not a reproduction of a historical document.</p></div></div></section>
  <section class="viewer-story shell story-second"><div class="viewer-side-note"><span class="eyebrow">02 / WHAT CAME NEXT</span><span>The first connections were getting closer.</span></div><div class="viewer-prose"><h2>From possibility<br><em>to connection.</em></h2><p>By the end of the 1960s, these ideas were becoming real. ARPANET, a research network funded by the U.S. Advanced Research Projects Agency, connected its first nodes in 1969.</p><p>In the next chapter, we'll follow those early connections — including a famously brief first message — and see how a network began growing beyond a single institution.</p></div></section>
  <section class="viewer-ending shell" aria-label="Chapter navigation"><div><p class="eyebrow">CHAPTER 01 COMPLETE</p><h2>Every connection<br><em>starts somewhere.</em></h2><p>You've reached the end of our first chapter prototype.</p></div><div class="viewer-ending-actions"><span class="viewer-next-muted">UP NEXT · CHAPTER 02 / THE FIRST CONNECTIONS</span><a class="primary-link" href="chapter-2.php">Continue to Chapter 02 <span aria-hidden="true">→</span></a><a class="viewer-secondary-link" href="exhibition.php#chapters">All chapters ↗</a><a class="viewer-secondary-link" href="#main">Back to chapter start ↑</a></div></section>
</main>
<footer class="footer"><div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?=date('Y')?> Exhibit</span></div></footer>
</body></html>
