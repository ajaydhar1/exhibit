<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/mail.php';
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');

if (array_slice($argv, 1) !== ['--run']) {
    fwrite(STDERR, "Usage: php backend/mail-worker.php --run\nDelivers up to 25 queued messages using configured MAIL_TRANSPORT (resend or smtp). Never run without delivery approval.\n");
    exit(2);
}

$db = null;
try {
    $send = Exhibit\Mail\configuredSender();
    Exhibit\Auth\secret();
    $db = Exhibit\Database\connect();
    ['sent' => $sent, 'failed' => $failed, 'exhausted' => $exhausted] = Exhibit\Mail\deliverQueue($db, $send);
    fwrite(STDOUT, "Delivered: $sent; failed this run: $failed; exhausted retries: $exhausted.\n");
    exit($failed > 0 || $exhausted > 0 ? 1 : 0);
} catch (Throwable $error) {
    if ($db !== null && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Email worker unavailable [' . get_class($error) . "]. Check environment and service availability; no sensitive details logged.\n");
    exit(1);
}
