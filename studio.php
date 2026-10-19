<?php
// Exhibit Studio — interactive design prototype. Nothing is persisted or published.
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="description" content="An interactive design preview of the Exhibit creator Studio.">
  <title>Exhibition Studio — Exhibit (Preview)</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/studio.css">
  <script src="assets/studio.js" defer></script>
</head>
<body class="studio-page">
  <header class="site-header"><div class="shell header-inner">
    <a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
    <nav aria-label="Workspace"><a href="my-exhibitions.php">← My Exhibitions</a><a href="exhibition.php">Public exhibition ↗</a></nav>
    <span class="edition">THE EXHIBITION STUDIO</span>
  </div></header>
  <main class="studio-shell">
    <div class="studio-warning" role="note"><span class="warning-dot"></span><strong>Interactive design preview</strong><span>Try editing a chapter. Changes appear here only and are lost when you leave or refresh. Nothing is saved or published.</span></div>
    <div class="studio-topline">
      <div><p class="eyebrow">EXHIBITION STUDIO / 001</p><h1>Shape a <em>story.</em></h1><p>Arrange the chapters. Refine the words. See your exhibition take shape.</p></div>
      <div class="studio-actions"><span class="status-note"><i></i> Unsaved sample</span><button type="button" id="preview-toggle" class="studio-btn">Hide preview</button><button type="button" class="studio-btn studio-btn-disabled" disabled title="Saving will be available after the backend is built">Save draft · Later</button></div>
    </div>
    <div class="exhibit-project-bar"><div><span class="project-icon">✳</span><div><span class="project-kicker">CURRENT EXHIBITION</span><strong>The Birth of the Internet</strong></div></div><span class="sample-state">SAMPLE CONTENT</span></div>
    <div class="studio-layout" id="studio-layout">
      <aside class="studio-side" aria-label="Chapter organizer"><div class="pane-heading"><span>01 / STRUCTURE</span><h2>Chapters</h2><p>The journey your visitors follow.</p></div>
        <nav class="chapter-list" aria-label="Choose a chapter" id="chapter-list"></nav>
        <button class="add-chapter" type="button" id="add-chapter"><span>＋</span> Add a chapter</button>
        <div class="side-footnote"><span>✳</span><p>Each chapter can hold narrative text, visual material, and artifacts.</p></div>
      </aside>
      <section class="studio-editor" aria-labelledby="editor-title"><div class="pane-heading editor-pane-heading"><span>02 / CREATE</span><h2 id="editor-title">Chapter editor</h2><p>Give your ideas some room to breathe.</p></div>
        <div class="editor-inner"><div class="chapter-overline"><span id="chapter-number">CHAPTER 01</span><span class="working-tag">WORKING COPY</span></div>
          <label for="chapter-title">Chapter title</label><input id="chapter-title" class="title-field" type="text" maxlength="110" autocomplete="off" value="Before the Web">
          <label for="chapter-era">Era or subtitle <span class="optional">optional</span></label><input id="chapter-era" type="text" maxlength="100" value="THE EARLY NETWORKS · 1950s—1960s">
          <label for="chapter-intro">Opening narrative</label><textarea id="chapter-intro" rows="5" maxlength="2000">Long before websites and social feeds, the idea of connecting computers was an ambitious experiment. Scientists imagined a new kind of network—one that could share knowledge across distances.</textarea>
          <div class="editor-divider"><span>CHAPTER CONTENT</span><span id="block-count">02 BLOCKS</span></div>
          <div id="block-editor" class="block-editor"></div>
          <div class="block-actions"><button id="add-text" type="button" class="studio-btn studio-btn-subtle">＋ Add text</button><button id="add-artifact" type="button" class="studio-btn studio-btn-subtle">＋ Add artifact</button></div>
          <div class="editor-note">This is a design sandbox. Edits update the preview but aren't stored in your account or database.</div>
        </div>
      </section>
      <aside class="studio-preview" id="studio-preview" aria-label="Live chapter preview"><div class="pane-heading preview-pane-heading"><span>03 / EXPERIENCE</span><div class="preview-title-row"><h2>Live preview</h2><span class="preview-live"><i></i> LIVE</span></div><p>A visitor's glimpse of your chapter.</p></div>
        <div class="preview-stage"><div class="preview-art"><span class="art-label">EXHIBIT / A CURATED JOURNEY</span><div class="preview-orbit"><i></i><i></i><i></i><i></i></div><span class="art-bottom">HISTORY · TECHNOLOGY</span></div><div class="preview-sheet"><p class="preview-eyebrow" id="pv-eyebrow">CHAPTER 01 / THE EARLY NETWORKS</p><h3 id="pv-title">Before the Web</h3><p id="pv-intro" class="preview-intro"></p><div id="pv-blocks" class="preview-blocks"></div><p class="preview-end">Every discovery starts with a question. <span>✳</span></p></div></div>
        <div class="preview-foot">Preview reflects edits instantly · Nothing is published</div>
      </aside>
    </div>
    <p class="studio-outro">Made for the endlessly curious. <span>✳</span> One thoughtful chapter at a time.</p>
  </main>
</body>
</html>
