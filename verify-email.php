<?php
$authPage = 'verify-email';
require __DIR__ . '/backend/auth-page.php';
?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Verify email — Exhibit</title>
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
            <p class="eyebrow">EXHIBIT / ONE MORE STEP</p>
            <h1>Make it<br><em>official.</em></h1>
            <p>Your creative space begins with a verified email address, so we can help keep your exhibitions yours.</p>
            <div class="auth-art verify-art" aria-hidden="true">
                <div class="orbit orbit-one"></div>
                <div class="orbit orbit-two"></div>
                <div class="orbit orbit-three"></div><span class="art-label">EXHIBIT / CREATOR STUDIO</span><span class="art-bottom">GOOD THINGS BEGIN WITH A SMALL STEP ✳</span>
            </div><span class="small-thought">An exhibition begins with an invitation to explore.</span>
        </div>
        <section class="auth-panel" aria-labelledby="auth-heading">
            <div class="auth-topline"><span>ACCOUNT SETUP</span><span>05 / VERIFY EMAIL</span></div>
            <h2 id="auth-heading">Check your inbox.</h2>
            <p class="auth-subtitle">Follow your email link to confirm your address and enter your workspace.</p>
            <div class="prototype-note" role="note"><span>✳</span><span><strong>One more step.</strong> Verification links expire after 24 hours and can only be used once.</span></div>
            <div class="verify-envelope" aria-hidden="true"><span>✉</span><i>✳</i></div>
            <p class="auth-step-text">Follow the link in your verification email. Didn’t receive it? Request a new one below.</p>
            <?php if ($hasToken): ?>
                <form class="auth-form" method="post" action="verify-email.php"><?= $csrfInput ?><input type="hidden" name="action" value="verify"><button class="auth-submit" type="submit">Confirm email address <span aria-hidden="true">→</span></button></form>
            <?php endif; ?>
            <form class="auth-form" method="post" action="verify-email.php" data-auth-form><?= $csrfInput ?>
                <?php if (!isset($_SESSION['user_id'])): ?><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" required><?php endif; ?>
                <button class="auth-submit" type="submit">Resend verification email <span aria-hidden="true">→</span></button>
                <p class="auth-feedback" role="status" aria-live="polite"><?= Exhibit\Auth\escape($feedback) ?></p>
            </form>
            <noscript><p class="field-hint">To use an email link without JavaScript, paste the 64-character code after #token= into the field below.</p></noscript>
            <form class="auth-form token-capture" method="post" action="verify-email.php"><?= $csrfInput ?><input type="hidden" name="action" value="capture-token"><label for="token">Verification link code</label><input id="token" name="token" autocomplete="off" pattern="[a-f0-9]{64}" required><button class="auth-submit" type="submit">Open verification link <span aria-hidden="true">→</span></button></form>
            <div class="auth-switch">Already verified? <a href="sign-in.php">Continue to sign in →</a></div><a class="auth-back" href="my-exhibitions.php">Continue to your workspace ↗</a>
            <div class="verify-success" id="verify-success" role="status" <?= $verified ? '' : 'hidden' ?>><span aria-hidden="true">✳</span><strong>Email verified</strong>
                <p>Your email address has been confirmed.</p><a href="sign-in.php">Continue to sign in →</a>
            </div>
        </section>
    </main>
    <footer class="footer">
        <div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?= date('Y') ?> Exhibit</span></div>
    </footer>
</body>

</html>