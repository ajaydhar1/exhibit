(() => {
  'use strict';
  const toggle = document.getElementById('preview-toggle');
  toggle.addEventListener('click', () => {
    const hidden = document.getElementById('studio-layout').classList.toggle('preview-hidden');
    document.getElementById('studio-preview').hidden = hidden;
    toggle.textContent = hidden ? 'Show preview' : 'Hide preview';
    toggle.setAttribute('aria-expanded', String(!hidden));
  });
})();
