
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

document.querySelectorAll('form[data-auth-form]').forEach(form => {
  form.addEventListener('submit', event => {
    const feedback = form.querySelector('.auth-feedback');

    const passwordField = form.querySelector('#password');
    const confirmField = form.querySelector('#confirm-password');

    if (passwordField && confirmField) {
      confirmField.setCustomValidity('');

      if (passwordField.value !== confirmField.value) {
        event.preventDefault();
        confirmField.setCustomValidity('Passwords do not match.');
        confirmField.reportValidity();

        if (feedback) {
          feedback.textContent = 'Please make sure your passwords match.';
        }
        return;
      }
    }
  });
});

const tokenForm = document.querySelector('.token-capture');
if (tokenForm) {
  tokenForm.hidden = true;
  const captureToken = () => {
    const token = new URLSearchParams(location.hash.slice(1)).get('token');
    if (token !== null) {
      history.replaceState(null, '', location.pathname);
      tokenForm.elements.token.value = token;
      tokenForm.submit();
    }
  };
  addEventListener('hashchange', captureToken);
  captureToken();
}
