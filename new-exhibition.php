<?php // Exhibit — create-exhibition design preview; no saved data or publishing. ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Create an Exhibition — Exhibit (Preview)</title>
  <meta name="description" content="Design prototype for starting an exhibition on Exhibit.">
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/new-exhibition.css">
  <script src="assets/new-exhibition.js" defer></script>
</head>
<body>
<header class="site-header"><div class="shell header-inner">
<a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
<nav aria-label="Creator navigation"><a href="my-exhibitions.php">← My Exhibitions</a><a href="studio.php">Studio preview</a><a href="explore.php">Explore</a></nav><span class="edition">CREATOR WORKSPACE · PREVIEW</span>
</div></header>
<main class="create-shell shell">
  <div class="create-note" role="note"><span class="note-spark">✳</span><strong>Design preview</strong><span>Explore the setup experience. Nothing is saved, uploaded, or published.</span></div>
  <div class="create-intro"><p class="eyebrow"><span class="eyebrow-line"></span> EXHIBITION STUDIO / GETTING STARTED</p><h1>Every exhibition<br>begins with <em>curiosity.</em></h1><p>Give your idea a name, find its focus, and imagine the journey you want to share.</p></div>
  <div class="create-grid">
    <section class="create-form-panel" aria-labelledby="details-heading">
      <div class="section-kicker"><span>01 / THE IDEA</span><span>START HERE</span></div><h2 id="details-heading">Tell us about your exhibition.</h2>
      <form id="create-form" novalidate>
        <label for="new-title">Exhibition title <span class="required">*</span></label>
        <input type="text" id="new-title" name="title" maxlength="100" placeholder="e.g. The Art of Everyday Objects" autocomplete="off" required>
        <p class="field-help">Make it memorable. You can always rename it later.</p>
        <label for="new-subtitle">A short introduction</label>
        <textarea id="new-subtitle" name="summary" rows="4" maxlength="320" placeholder="What will visitors discover?" ></textarea>
        <div class="field-line"><label for="new-subject">Subject</label><span>OPTIONAL</span></div>
        <select id="new-subject" name="subject"><option value="">Choose a subject…</option><option>Arts & Culture</option><option>History</option><option>Science & Nature</option><option>Technology</option><option>Film & Music</option><option>Places & People</option><option>Something else</option></select>
        <div class="field-line"><label for="new-cover">Cover image URL</label><span>OPTIONAL</span></div>
        <input type="url" id="new-cover" name="cover" inputmode="url" placeholder="https://example.com/photo.jpg">
        <p class="field-help">For this design preview, images aren't uploaded. Use an image URL you have permission to display.</p>
        <div class="form-footer"><p id="feedback" role="status" aria-live="polite">Your idea stays right here in this browser session.</p><button class="create-primary" type="submit">Continue to Studio <span aria-hidden="true">↗</span></button></div>
      </form>
    </section>
    <aside class="create-preview-panel" aria-labelledby="preview-heading">
      <div class="section-kicker"><span>02 / A FIRST GLIMPSE</span><span class="live-indicator"><i></i> LIVE PREVIEW</span></div><h2 id="preview-heading">See your idea take shape.</h2>
      <div class="exhibition-cover" id="cover-visual"><div class="cover-decoration" aria-hidden="true"><i></i><i></i><i></i></div><div class="cover-wordmark">EXHIBIT / AN ORIGINAL EXHIBITION</div><div class="cover-foot">MADE FOR THE ENDLESSLY CURIOUS ✳</div></div>
      <div class="preview-content"><p class="preview-category" id="pv-category">YOUR SUBJECT</p><h3 id="pv-title">Your story starts here.</h3><p class="preview-description" id="pv-summary">An exhibition worth exploring begins with a question. What will yours be?</p><span class="preview-chapter">CHAPTER 01 · WAITING TO BE WRITTEN</span></div>
      <div class="preview-hint"><span>✳</span><p>Once you enter the Studio, you'll arrange chapters, add media, and guide visitors through your story.</p></div>
    </aside>
  </div>
  <div class="create-after"><span>ONE IDEA AT A TIME</span><p>Wonderful things can begin simply.</p></div>
</main>
<footer class="footer"><div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div></footer>
</body></html>
