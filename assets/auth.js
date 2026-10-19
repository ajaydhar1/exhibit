'use strict';
document.querySelectorAll('.password-toggle').forEach(button => {
  button.addEventListener('click', () => {
    const input = button.parentElement.querySelector('input');
    const showing = input.type === 'password';
    input.type = showing ? 'text' : 'password';
    button.textContent = showing ? 'Hide' : 'Show';
    button.setAttribute('aria-pressed', String(showing));
    button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
  });
});
document.querySelectorAll('form[data-auth-preview]').forEach(form => {
  form.addEventListener('submit', event => {
    event.preventDefault();
    // Visual-only prototype: never send, save, log, or process passwords.
    form.querySelector('.auth-feedback').textContent = 'Preview only — accounts are not connected yet. No information was submitted.';
  });
});
