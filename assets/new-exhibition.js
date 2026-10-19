(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const title = $('new-title'), summary = $('new-subtitle'), subject = $('new-subject'), cover = $('new-cover');
  const feedback = $('feedback');
  function update() {
    $('pv-title').textContent = title.value.trim() || 'Your story starts here.';
    $('pv-summary').textContent = summary.value.trim() || 'An exhibition worth exploring begins with a question. What will yours be?';
    $('pv-category').textContent = (subject.value || 'YOUR SUBJECT').toUpperCase();
    const visual = $('cover-visual');
    visual.classList.remove('has-image');
    visual.style.backgroundImage = '';
    const entered = cover.value.trim();
    if (entered) {
      try {
        const url = new URL(entered);
        if ((url.protocol === 'https:' || url.protocol === 'http:') && !url.username && !url.password) {
          visual.classList.add('has-image');
          visual.style.backgroundImage = `url(${JSON.stringify(url.href)}), linear-gradient(135deg, #153b34, #316655 65%, #80ab8a)`;
        }
      } catch (_) { /* Invalid URL retains illustrative cover. */ }
    }
  }
  [title, summary, subject, cover].forEach((el) => el.addEventListener('input', update));
  $('create-form').addEventListener('submit', (event) => {
    if (!title.value.trim()) {
      event.preventDefault();
      feedback.textContent = 'Add an exhibition title to continue.';
      title.setAttribute('aria-invalid', 'true');
      title.focus();
      return;
    }
    if (!$('create-form').reportValidity()) {
      event.preventDefault();
      return;
    }
    $('create-form').querySelector('button[type="submit"]').disabled = true;
    feedback.textContent = 'Saving your private draft…';
  });
  window.addEventListener('pageshow', () => {
    $('create-form').querySelector('button[type="submit"]').disabled = false;
  });
  update();
  document.querySelector('#create-form [aria-invalid="true"]')?.focus();
})();
