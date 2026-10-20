(() => {
  'use strict';
  const toggle = document.getElementById('preview-toggle');
  toggle.addEventListener('click', () => {
    const hidden = document.getElementById('studio-layout').classList.toggle('preview-hidden');
    document.getElementById('studio-preview').hidden = hidden;
    toggle.textContent = hidden ? 'Show preview' : 'Hide preview';
    toggle.setAttribute('aria-expanded', String(!hidden));
  });
  const form = document.getElementById('studio-form');
  const fields = Object.fromEntries(['title', 'summary', 'subject', 'cover'].map(
    name => [name, document.getElementById(`exhibition-${name}`)]
  ));
  const baseline = JSON.parse(form.dataset.baseline);
  const status = document.getElementById('save-status');
  const button = document.getElementById('save-draft');
  const initialMessage = status.textContent;
  const initialState = status.dataset.state;
  let submitting = false;
  const dirty = () => Object.entries(fields).some(([name, field]) => field.value !== baseline[name]);
  const setStatus = (state, message) => {
    status.dataset.state = state;
    status.textContent = message;
  };
  const preview = () => {
    const title = fields.title.value.trim() || 'Your exhibition title';
    const subject = fields.subject.value || 'YOUR EXHIBITION';
    document.getElementById('pv-title').textContent = title;
    document.getElementById('project-title').textContent = title;
    document.getElementById('pv-intro').textContent = fields.summary.value.trim() || 'No introduction yet.';
    document.getElementById('pv-eyebrow').textContent = subject;
    document.getElementById('pv-category').textContent = subject;
    const cover = fields.cover.value.trim();
    let valid = false;
    try {
      const url = new URL(cover);
      valid = /^https?:\/\//.test(cover) && ['http:', 'https:'].includes(url.protocol)
        && !url.username && !url.password && !/[\x00-\x20\x7f]/.test(cover)
        && new TextEncoder().encode(cover).length <= 2048;
    } catch (error) {
      if (!(error instanceof TypeError)) throw error;
    }
    const art = document.getElementById('preview-art');
    art.style.backgroundImage = valid ? `url(${JSON.stringify(cover)}), linear-gradient(135deg, #183f38, #326e5d)` : '';
    art.style.backgroundSize = valid ? 'cover' : '';
    art.style.backgroundPosition = valid ? 'center' : '';
    document.getElementById('preview-orbit').hidden = valid;
  };
  Object.values(fields).forEach(field => field.addEventListener('input', () => {
    preview();
    if (initialState === 'error') {
      setStatus('error', initialMessage);
    } else {
      setStatus(dirty() ? 'unsaved' : 'saved', dirty() ? 'Unsaved changes' : initialMessage);
    }
  }));
  form.addEventListener('submit', event => {
    if (submitting || button.disabled || !form.reportValidity() || !fields.title.value.trim()) {
      event.preventDefault();
      if (!fields.title.value.trim()) {
        fields.title.focus();
        setStatus('error', 'Add an exhibition title before saving.');
      }
      return;
    }
    submitting = true;
    button.disabled = true;
    setStatus('saving', 'Saving draft...');
  });
  window.addEventListener('beforeunload', event => {
    if (dirty() && !submitting) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
  window.addEventListener('pageshow', () => {
    if (submitting) {
      submitting = false;
      button.disabled = false;
      setStatus(dirty() ? 'unsaved' : initialState, dirty() ? 'Unsaved changes' : initialMessage);
    } else if (initialState !== 'error') {
      setStatus(dirty() ? 'unsaved' : initialState, dirty() ? 'Unsaved changes' : initialMessage);
    }
    preview();
  });
  preview();
})();
