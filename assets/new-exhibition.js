(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const title = $('new-title'), summary = $('new-subtitle'), subject = $('new-subject'), cover = $('new-cover');
  const feedback = $('feedback');
  function update() {
    $('pv-title').textContent = title.value.trim() || 'Your story starts here.';
    $('pv-summary').textContent = summary.value.trim() || 'An exhibition worth exploring begins with a question. What will yours be?';
    $('pv-category').textContent = (subject.value || 'YOUR SUBJECT').toUpperCase();
    // URL typed here is preview-only and never sent to the server.
    const visual = $('cover-visual');
    visual.classList.remove('has-image');
    visual.style.backgroundImage = '';
    const entered = cover.value.trim();
    if (entered) {
      try {
        const url = new URL(entered);
        if (url.protocol === 'https:' || url.protocol === 'http:') {
          visual.classList.add('has-image');
          visual.style.backgroundImage = `url(${JSON.stringify(url.href)})`;
        }
      } catch (_) { /* Invalid URL retains illustrative cover. */ }
    }
    feedback.textContent = 'Design preview · Nothing is saved or published.';
  }
  [title, summary, subject, cover].forEach((el) => el.addEventListener('input', update));
  $('create-form').addEventListener('submit', (event) => {
    event.preventDefault();
    if (!title.value.trim()) {
      feedback.textContent = 'Add an exhibition title to continue.';
      title.focus();
      return;
    }
    feedback.textContent = 'This screen is a prototype. The Studio does not receive or save these details yet.';
  });
  update();
})();
