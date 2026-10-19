<?php
declare(strict_types=1);

namespace Exhibit\Mail;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/smtp.php';
require_once __DIR__ . '/resend.php';

/** @return Closure(array, string): void */
function configuredSender(): Closure
{
    $transport = getenv('MAIL_TRANSPORT');
    if ($transport === 'resend') {
        if (!extension_loaded('openssl') || !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Resend requires OpenSSL and allow_url_fopen for verified HTTPS.');
        }
        $resend = new Resend(resendConfiguration());
        return $resend->send(...);
    }
    if ($transport === false || $transport === '' || $transport === 'smtp') {
        $config = configuration();
        return static function (array $message, string $queueIdentity) use ($config): void {
            $smtp = new Smtp($config);
            $smtp->send($message);
        };
    }
    throw new RuntimeException('MAIL_TRANSPORT must be resend or smtp.');
}

/** @param Closure(array, string): void $send
 *  @return array{sent: int, failed: int, exhausted: int}
 */
function deliverQueue(PDO $db, Closure $send): array
{
    $db->exec('DELETE FROM public.auth_tokens WHERE expires_at <= CURRENT_TIMESTAMP');
    $db->exec('DELETE FROM public.auth_rate_limits WHERE expires_at <= CURRENT_TIMESTAMP');
    $db->exec('DELETE FROM public.auth_mail_queue WHERE expires_at <= CURRENT_TIMESTAMP');
    $sent = 0;
    $failed = 0;
    for ($i = 0; $i < 25; $i++) {
        $db->beginTransaction();
        try {
            $row = $db->query("SELECT * FROM public.auth_mail_queue
                WHERE available_at <= CURRENT_TIMESTAMP AND expires_at > CURRENT_TIMESTAMP AND attempts < 5
                ORDER BY available_at, id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch();
            if ($row === false) {
                $db->commit();
                break;
            }
            $accepted = false;
            try {
                $send(\Exhibit\Auth\decryptMail($row['payload']), $row['payload']);
                $accepted = true;
            } catch (RuntimeException $error) {
                // SMTP retains its existing bounded retry policy; Resend distinguishes permanent failures.
                $retryable = !$error instanceof DeliveryError || $error->retryable;
                error_log($retryable
                    ? 'Exhibit queued email delivery failed; retry scheduled. No message details logged.'
                    : 'Exhibit queued email delivery permanently rejected; attempts exhausted. No message details logged.');
                $statement = $db->prepare($retryable
                    ? "UPDATE public.auth_mail_queue SET attempts = attempts + 1,
                        available_at = CURRENT_TIMESTAMP + INTERVAL '5 minutes' WHERE id = ?"
                    : 'UPDATE public.auth_mail_queue SET attempts = 5 WHERE id = ?');
                $statement->execute([$row['id']]);
                $failed++;
            }
            if ($accepted) {
                $statement = $db->prepare('DELETE FROM public.auth_mail_queue WHERE id = ?');
                $statement->execute([$row['id']]);
                $sent++;
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
    $exhausted = (int) $db->query('SELECT count(*) FROM public.auth_mail_queue WHERE attempts >= 5')->fetchColumn();
    return ['sent' => $sent, 'failed' => $failed, 'exhausted' => $exhausted];
}
