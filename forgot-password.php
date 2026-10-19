<?php
$authPage = 'forgot-password';
require __DIR__ . '/backend/auth-page.php';
?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Forgot password — Exhibit</title>
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
            <p class="eyebrow">EXHIBIT / FIND YOUR WAY BACK</p>
            <h1>A little help<br><em>finding your way.</em></h1>
            <p>A forgotten password should never mean leaving your creative work behind. We’ll help you return to it.</p>
            <div class="auth-art recovery-art" aria-hidden="true">
                <div class="orbit orbit-one"></div>
                <div class="orbit orbit-two"></div>
                <div class="orbit orbit-three"></div><span class="art-label">EXHIBIT / CREATOR STUDIO</span><span class="art-bottom">YOUR STORIES WILL BE HERE WHEN YOU RETURN ✳</span>
            </div><span class="small-thought">Your curiosity is always welcome here.</span>
        </div>
        <section class="auth-panel" aria-labelledby="auth-heading">
            <div class="auth-topline"><span>ACCOUNT RECOVERY</span><span>03 / RESET LINK</span></div>
            <h2 id="auth-heading">Forgot your password?</h2>
            <p class="auth-subtitle">Enter the email associated with your account to request a secure reset link.</p>
            <div class="prototype-note" role="note"><span>✳</span><span><strong>A secure way back.</strong> Reset links expire after 30 minutes and can only be used once.</span></div>
            <form class="auth-form" method="post" action="forgot-password.php" data-auth-form><?= $csrfInput ?><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="email" maxlength="254" value="<?= Exhibit\Auth\escape(Exhibit\Auth\post('email')) ?>" placeholder="you@example.com" required><button class="auth-submit" type="submit">Send reset link <span aria-hidden="true">→</span></button>
                <p class="auth-feedback" role="status" aria-live="polite"><?= Exhibit\Auth\escape($feedback) ?></p>
            </form>
            <div class="auth-switch">Remembered your password? <a href="sign-in.php">Back to sign in →</a></div><a class="auth-back" href="reset-password.php">Have a reset link? →</a>
        </section>
    </main>
    <footer class="footer">
        <div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div>
    </footer>
</body>

</html>