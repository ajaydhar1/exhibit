<?php // Exhibit visual prototype only. No emails are sent, tokens verified, or passwords changed. 
?>
<!doctype html>
<html lang="en">

<head>

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-E5SFFE66TD"></script>
    <script>
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }
        gtag('js', new Date());

        gtag('config', 'G-E5SFFE66TD');
    </script>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Reset password — Exhibit (Preview)</title>
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
            <p class="auth-subtitle">In the finished product, this screen will open from a secure, expiring email link.</p>
            <div class="prototype-note" role="note"><span>✳</span><span><strong>Design preview.</strong> These screens are not connected to email or accounts. Nothing is submitted, sent, or saved.</span></div>
            <form class="auth-form" data-auth-preview data-auth-feedback="Preview only — your password was not changed."><label for="password">New password</label>
                <div class="password-wrap"><input id="password" type="password" name="password" autocomplete="new-password" minlength="12" placeholder="At least 12 characters" required><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">Show</button></div><label for="confirm-password">Confirm new password</label>
                <div class="password-wrap"><input id="confirm-password" type="password" name="confirm-password" autocomplete="new-password" minlength="12" placeholder="Repeat your new password" required><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">Show</button></div>
                <p class="field-hint">When enabled, reset links will be single-use and time-limited. This preview does not accept real reset links.</p><button class="auth-submit" type="submit">Reset password <span aria-hidden="true">→</span></button>
                <p class="auth-feedback" role="status" aria-live="polite">No password changes are made in the preview.</p>
            </form>
            <div class="auth-switch">Ready to return? <a href="sign-in.php">Back to sign in →</a></div>
        </section>
    </main>
    <footer class="footer">
        <div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div>
    </footer>
</body>

</html>