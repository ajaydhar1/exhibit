# Resend transactional email

The authentication schema is already applied. **No migration is required and no
migration should be rerun.** Resend HTTPS support reuses the existing encrypted
PostgreSQL queue and the CLI worker. Registration, verification resend and password
recovery only enqueue; no web request connects to Resend. Screens, styles,
navigation, token generation/expiry/consumption and session policy are unchanged.

The operator supplied Resend as the provider, verified domain `exhibit.world`, and
preferred sender `noreply@exhibit.world`. This implementation does not create keys,
configure Render, change DNS, send messages automatically, or deploy anything.
The first separately approved controlled delivery test is recorded below;
production deployment and scheduling remain unconfigured.

## First controlled authentication test (2026-10-19)

The operator completed one explicitly approved creator registration through the
local application at `http://127.0.0.1:8091`, using the existing Render database.
Private credentials, recipient identity, passwords and verification tokens are
not recorded here.

- Read-only checks confirmed the previously verified database/endpoint, installed
  schema, approved migration checksum and TLSv1.3. The queue was initially empty.
- The operator confirmed no prior `AUTH_KEY` existed, initialized the first stable
  key privately, and stored it encrypted outside the repository. Local web and
  sender processes used the same key.
- Registration created one unverified account and one encrypted verification
  message. Studio access was blocked before verification.
- Following separate send approval, a temporary scoped CLI runner accepted only
  the approved account, recipient, queue ID and encrypted-payload fingerprint.
  Resend accepted exactly one verification email; the operator confirmed inbox
  arrival and the correct local verification-link host.
- Email confirmation, fresh sign-in, My Exhibitions and Studio access, and logout
  all passed. Fresh workspace requests after logout redirected to sign-in.
- Final read-only inspection reported the account verified, zero remaining
  account tokens and an empty email queue.
- The local server was stopped. No additional messages, migration execution,
  exhibition writes, deployment or Render web-service setting changes occurred.

Database/API/inbox/account outcomes are supported by operator-supplied sanitized
reports and UI confirmations. The assistant independently checked local screen
availability, private-path protection and anonymous access guards. Temporary
test helpers, logs and encrypted key storage are not application source.

This validates local-web registration and verification with real Resend delivery,
not production HTTPS cookies, deployed proxy/session behavior, unattended
scheduling, live password recovery/resend, or live failure/retry scenarios.
Further sends and production configuration require separate approval. Preserve
the stable `AUTH_KEY`; Windows DPAPI storage is user/machine-bound, not a portable
backup.

## Required Render environment (after separate approval)

| Variable | Value / requirement |
| --- | --- |
| `MAIL_TRANSPORT` | `resend` on the worker. Explicit opt-in; unset/empty retains the existing SMTP transport, `smtp` is also accepted. Unknown values fail, with no provider fallback. |
| `RESEND_API_KEY` | Exhibit-specific secret created privately in Resend. Prefer sending-only permission restricted to `exhibit.world`, not full access or a key shared by unrelated applications. Never put it in source, a command argument, chat, log or screenshot. Only the worker needs it. |
| `MAIL_FROM` | `noreply@exhibit.world`. An email address, not a display-name string. Confirm sender authorization in the intended Resend account. |
| `APP_URL` | `https://exhibit.world` on the web app. This existing setting controls emailed verification/reset links, canonical redirects and secure cookies. No production URL is hardcoded as a development default. |
| `AUTH_KEY` | The same existing stable 64-hex-character secret on web and worker; do not rotate it when enabling Resend. It decrypts pending emails and keys rate limits. |
| `DATABASE_URL` | The existing initialized Exhibit PostgreSQL URL, supplied privately to web/worker. Do not create a second auth database or use a disposable test URL. |
| `DB_SSLMODE` | `verify-full`. |
| `DB_SSLROOTCERT` | Trusted CA bundle readable by the worker; existing Docker default is `/etc/ssl/certs/ca-certificates.crt`. |

The worker needs PHP 8.2+, PDO PostgreSQL, OpenSSL, `allow_url_fopen=On`, and a
current system HTTPS CA store. PHP's HTTPS streams use the system trust store or
`openssl.cafile`; the database-specific `DB_SSLROOTCERT` does not configure HTTPS.
The existing Docker base includes HTTPS streams/OpenSSL and the Dockerfile already
installs system CA certificates. No SDK, Composer dependency, framework, cURL
extension, or Docker change is required.

Resend transport ignores SMTP credentials; keep `MAIL_TRANSPORT=resend` explicit.
The existing SMTP transport remains available for backward compatibility, but is
not automatically tried after a Resend failure. This avoids duplicate delivery or
silently routing authentication links to a different provider.

## HTTPS transport

- Fixed `POST https://api.resend.com/emails`, bearer authentication and JSON
  `{from, to: [recipient], subject, text}`. No configurable API endpoint, redirects,
  remote templates, attachments, marketing subscription or tracking code is added.
- The decrypted existing subject/text and link fragment are preserved exactly.
- Certificate and hostname verification are mandatory, TLS 1.2+ only, no
  self-signed bypass. Request/response timeout is 15 seconds and response storage
  is capped at 64 KiB.
- Acceptance requires a 2xx response with a valid-shaped Resend email ID. Empty,
  malformed, or ambiguous success is retried, not counted as accepted. Provider
  responses, IDs, recipient addresses, key, link tokens, headers and request bodies
  are never logged or included in failure exception messages.
- A stable, opaque `Idempotency-Key` binds the encrypted queued message and actual
  JSON body. Retrying the same message/body uses the same key; replacing a token
  via resend produces a new queued payload and a new key. Sender/body changes
  also produce a new key, so keep configuration stable during pending retries.
- Resend retains idempotency keys for 24 hours. This covers the ordinary retry
  window and the existing maximum token lifetime. This reduces duplicates after
  ambiguous acceptance or a crash, but is not an unlimited exactly-once guarantee.
- Resend necessarily receives the recipient and full email content to deliver it.
  Restrict provider/dashboard access and review retention/privacy settings.
  Disable provider click/open tracking for authentication mail and ensure any
  security/link rewriting preserves `#token=...` fragments. Do not enable request
  body, SQL-parameter, session, or decrypted-payload capture in infrastructure/APM.

## Queue and failure behavior

The existing queue schema, AES-256-GCM payload encryption, per-purpose replacement,
single-use tokens, token TTLs, rate limits, locking/claim order and expiry cleanup
are unchanged. The worker processes up to 25 messages per run in transactions,
using `FOR UPDATE SKIP LOCKED`.

| Result | Behavior |
| --- | --- |
| Confirmed API acceptance | Delete only the accepted queue row; increment delivered count. This means API accepted, not inbox-delivered. |
| Connection/TLS/timeout failure, HTTP 408/425/429/5xx, ambiguous success | Preserve ciphertext, increment attempts, schedule after five minutes, stop at five attempts as before. No immediate retry loop; 429 is not a reason to bypass the provider's sending limits. |
| HTTP 409 `concurrent_idempotent_requests` or `resource_locked` | Same bounded transient retry. |
| Other 3xx/4xx, including invalid/revoked key, unauthorized domain/sender, invalid payload or idempotency mismatch | Explicit permanent failure: retain ciphertext and mark `attempts=5` immediately, preventing repeated unusable sends. This intentional refinement uses the existing exhausted-retry state; no supporting table is added. |
| Local invalid message/encoding | Permanent failure; no API call. |
| Missing/invalid environment or unsupported HTTPS runtime | Fail before connecting to the database/processing queue; do not fall back to SMTP. |
| Unexpected/program/database error | Roll back the active transaction, exit nonzero with sanitized error; no success-shaped fallback. |
| Existing SMTP RuntimeException | Existing five-attempt/five-minute retry policy retained. |

Logs distinguish retry scheduled versus permanent rejection without details.
The CLI keeps its aggregate output:

```text
Delivered: N; failed this run: N; exhausted retries: N.
```

Any failed delivery or retained exhausted message makes the run exit nonzero.
Alert on this exit status, exhausted count, backlog age and provider bounce/
complaint events. Provider acceptance does not establish inbox delivery.
No webhook/bounce handler is added in this milestone.

After fixing a key/domain/quota/network issue, a creator can request a new link
subject to existing rate limits. It replaces the token and encrypted queued message,
resetting attempts. Existing exhausted messages are not silently reactivated.
An administrative bulk retry or manual queue edits would require separate review.

## Running and scheduling (not configured by this change)

After explicit approval of live credentials, deployment and delivery:

```powershell
php backend\mail-worker.php --run
```

For a Render cron service using the same approved image/source:

```text
Schedule: * * * * *
Command:  php backend/mail-worker.php --run
```

The Render command uses Linux path syntax. Configure the worker's environment
separately or through a reviewed shared secret environment group; do not assume a
cron service automatically inherits the web service's environment.
Review Render job cost, network access, scheduling/overlap rules and secret access
before creating it. Do not change the Apache startup command to send email and do
not add an unauthenticated web worker endpoint.

Only the worker needs the Resend key. Ensure its build/release contains the new
transport before setting `MAIL_TRANSPORT=resend`. The worker holds a queue-row lock
during the bounded HTTPS operation; observe latency/deadlocks in controlled
testing. The five-minute retry delay is deliberately retained rather than adding
a new backoff framework.

For the first controlled test, approve a manual single worker invocation rather
than scheduling an unattended drain. **A worker run can deliver every eligible
queued message up to 25, including pre-existing messages**. Privately inspect
aggregate queue counts/backlog and confirm all pending recipients are authorized
for the test. Do not start a scheduler while test boundaries are unresolved.

## Tests and controlled end-to-end checklist

Offline (no Resend credentials or network required):

```powershell
php tests\mail.php
php tests\auth.php
php tests\auth-http.php
php tests\run.php
```

On Windows, use the isolated PHP executable with `-n`, explicit `extension_dir`,
`extension=openssl`, and `extension=pdo_pgsql` for the HTTP harness. The mail suite
injects API responses and a stateful PDO queue double: success/request shape,
verified-TLS/redirect policy, opaque stable idempotency/replacement, 429/5xx and
connection failure, 409 distinctions, 4xx permanent rejection, malformed success,
invalid message rejection, safe logs/errors, all three auth email workflows,
five-minute retry, five-attempt limit, 25-message cap, rollback, and SMTP
compatibility. It sends no real HTTP requests and makes no database connection.
These doubles do not prove live Resend TLS or production PostgreSQL locking.

The existing PostgreSQL auth service/HTTP suites remain the real-database checks
for token security and account workflows; run them only against an empty local
`exhibit_test_SUFFIX` database, never Render. This transport change makes no SQL
migration or auth-workflow changes.

Offline results for this change: 10 mail test groups passed on PHP 8.4.26 and
8.2.27; 10 authentication unit/session/guard groups, 18 offline HTTP scenarios and
54 baseline offline tests passed; all 41 PHP files linted. Worker/API tests used
simulated responses and a queue double only. The subsequent approved controlled
test above established real Resend TLS/API acceptance and inbox delivery for one
account; live scheduling remains unverified. No PostgreSQL suite was rerun for this
transport-only change; the existing applied migration checksum remains unchanged.

Before a controlled end-to-end test:

1. Obtain explicit approval to configure the scoped Exhibit key privately, release
   this worker/web build, create controlled test accounts, and send to specified
   inboxes. No approval is implied by writing this code.
2. Confirm `exhibit.world` verification in the intended Resend account and sender
   `noreply@exhibit.world`; disable link tracking/rewrite behavior for auth messages.
   Check sending quotas, domain authorization and inbox access privately.
3. Configure the table above, keep `AUTH_KEY` stable, confirm web
   `APP_URL=https://exhibit.world`, and check no unapproved mail is pending.
4. Register one approved account. Verify encrypted queue creation without SMTP/
   API calls in the request. Run the worker manually; privately confirm accepted
   delivery and inbox receipt. Do not paste email links/tokens into chat or logs.
5. Open and confirm verification; verify single-use behavior and creator access.
   Request a new verification link on a second unverified controlled account and
   confirm replacement invalidates the old link and cooldown applies.
6. Sign in, open the unchanged sample dashboard/Studio, then log out; verify old
   session replay does not restore access. Confirm public exhibition pages remain
   accessible without login.
7. Request password recovery, run the worker, open the email link, set a new
   password, and verify old password rejection, new login and other-session
   revocation. Test expiry and concurrency only in local/staging fixtures.
8. Confirm TLS/HTTPS cookies, no token/key/address in logs, provider acceptance vs
   inbox evidence, aggregate exit counts, and no unexpected backlog.
9. Only after successful manual tests, approve cron setup/monitoring and any wider
   sending. Use the full [acceptance plan](authentication-activation.md) for
   session, CSRF, access-control, failure-injection and operational cases.

## Provider references

- [Send email API](https://resend.com/docs/api-reference/emails/send-email)
- [Error/status semantics](https://resend.com/docs/api-reference/errors)
- [Idempotency keys and 24-hour retention](https://resend.com/docs/dashboard/emails/idempotency-keys)
- [Sending-only, domain-restricted API keys](https://resend.com/docs/api-reference/api-keys/create-api-key)
