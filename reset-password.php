<?php
$authPage = 'reset-password';
require __DIR__ . '/backend/auth-page.php';
?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Reset password — Exhibit</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/auth.css">
    <script defer src="assets/auth.js"></script>
</head>

<body>
    <header class="site-header">
        <div class="shell header-inner"><a class="wordmark" href="index.php"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
            <nav aria-label="Account navigation"><a href="explore.php">Explore Exhibit</a><a href="sign-in.php">Sign in ↗</a></nav><span class="edition">YOUR CREATIVE SPACE</span>
        </div>
    </header>
    <main class="auth-shell shell">
        <div class="auth-side">
            <p class="eyebrow">EXHIBIT / A FRESH START</p>
            <h1>Begin again,<br><em>right here.</em></h1>
            <p>Choose a new password and return to the exhibitions that matter to you.</p>
            <div class="auth-art reset-art" aria-hidden="true">
                <div class="orbit orbit-one"></div>
                <div class="orbit orbit-two"></div>
                <div class="orbit orbit-three"></div><span class="art-label">EXHIBIT / CREATOR STUDIO</span><span class="art-bottom">TURN THE PAGE, KEEP THE STORY ✳</span>
            </div><span class="small-thought">A fresh start is part of every good story.</span>
        </div>
        <section class="auth-panel" aria-labelledby="auth-heading">
            <div class="auth-topline"><span>ACCOUNT RECOVERY</span><span>04 / NEW PASSWORD</span></div>
            <h2 id="auth-heading">Choose a new password.</h2>
            <p class="auth-subtitle">Open this screen from the secure, expiring link in your email.</p>
            <div class="prototype-note" role="note"><span>✳</span><span><strong>A fresh start.</strong> Updating your password signs out all existing sessions.</span></div>
            <form class="auth-form" method="post" action="reset-password.php" data-auth-form><?= $csrfInput ?><label for="password">New password</label>
                <div class="password-wrap"><input id="password" type="password" name="password" autocomplete="new-password" minlength="12" placeholder="At least 12 characters" required><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">Show</button></div><label for="confirm-password">Confirm new password</label>
                <div class="password-wrap"><input id="confirm-password" type="password" name="confirm_password" autocomplete="new-password" minlength="12" placeholder="Repeat your new password" required><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">Show</button></div>
                <p class="field-hint">Use at least 12 characters, up to 72 bytes. Reset links are single-use and expire after 30 minutes.</p><button class="auth-submit" type="submit" <?= $hasToken ? '' : 'disabled' ?>>Reset password <span aria-hidden="true">→</span></button>
                <p class="auth-feedback" role="status" aria-live="polite"><?= Exhibit\Auth\escape($feedback ?: ($hasToken ? '' : 'Open your reset email link first, or request a new link.')) ?></p>
            </form>
            <noscript><p class="field-hint">To use an email link without JavaScript, paste the 64-character code after #token= into the field below.</p></noscript>
            <form class="auth-form token-capture" method="post" action="reset-password.php"><?= $csrfInput ?><input type="hidden" name="action" value="capture-token"><label for="token">Reset link code</label><input id="token" name="token" autocomplete="off" pattern="[a-f0-9]{64}" required><button class="auth-submit" type="submit">Open reset link <span aria-hidden="true">→</span></button></form>
            <div class="auth-switch">Ready to return? <a href="sign-in.php">Back to sign in →</a></div>
        </section>
    </main>
    <footer class="footer">
        <div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div>
    </footer>
</body>

</html>