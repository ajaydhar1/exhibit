
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

// Clear password mismatch errors when the user edits either field.
const password = document.getElementById('password');
const confirmPassword = document.getElementById('confirm-password');

if (password && confirmPassword) {
  [password, confirmPassword].forEach(input => {
    input.addEventListener('input', () => {
      confirmPassword.setCustomValidity('');
    });
  });
}

document.querySelectorAll('form[data-auth-preview]').forEach(form => {
  form.addEventListener('submit', event => {
    event.preventDefault();

    const feedback = form.querySelector('.auth-feedback');

    // Validate matching passwords on the registration preview.
    const passwordField = form.querySelector('#password');
    const confirmField = form.querySelector('#confirm-password');

    if (passwordField && confirmField) {
      confirmField.setCustomValidity('');

      if (passwordField.value !== confirmField.value) {
        confirmField.setCustomValidity('Passwords do not match.');
        confirmField.reportValidity();

        if (feedback) {
          feedback.textContent = 'Please make sure your passwords match.';
        }
        return;
      }
    }

    // Visual-only prototype: never send, save, or log credentials.
    if (feedback) {
      feedback.textContent =
        form.dataset.authFeedback ||
        'Preview only — no information was submitted or stored.';
    }
  });
});

if (
  location.pathname.endsWith('/verify-email.php') &&
  new URLSearchParams(location.search).get('preview') === 'verified'
) {
  const success = document.getElementById('verify-success');
  if (success) {
    success.hidden = false;
    success.scrollIntoView({ block: 'nearest' });
  }
}
