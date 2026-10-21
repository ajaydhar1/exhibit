# Creator authentication

## Scope

Registration, sign-in, verification, recovery, and logout now use the existing PHP
screens and styling. All registered accounts are creators. As approved, creators
must verify their email before opening `my-exhibitions.php`, `new-exhibition.php`,
or `studio.php`. Public exhibition pages require neither login nor a database.
Verified creators can now create persistent exhibition drafts, list only their own
records, and edit their basic metadata in Studio. Saving is owner-scoped,
CSRF-protected, draft-only, and revision-checked. Publishing remains unimplemented.
No role system or content-model changes are introduced.

## Required web environment

Use PHP 8.2+ (the existing Docker image uses 8.4), PDO PostgreSQL, OpenSSL, and a
writable private PHP session directory. Use the isolated PHP 8.4 runtime documented
in [Database setup](database.md) for Windows CLI work; do not replace shared Ampps
DLLs or disable verified database TLS.

| Variable | Meaning |
| --- | --- |
| `DATABASE_URL` | Existing PostgreSQL connection URL; never place credentials in source or command arguments. |
| `DB_SSLMODE` | `require` for Render (TLS encryption without requiring server certificate/hostname validation); `verify-full` where full certificate and hostname verification is supported; `disable` allowed only for loopback development. |
| `DB_SSLROOTCERT` | Readable trusted CA bundle required for `verify-full`; existing Docker default is retained. |
| `APP_URL` | Canonical externally visible HTTPS application URL, including the subdirectory if present, without query or fragment. HTTP is accepted only on loopback. This controls links, redirects, cookie path, and Secure cookies; never infer it from request headers. |
| `AUTH_KEY` | 32 cryptographically random bytes encoded as 64 hexadecimal characters, stored as an environment secret. Used for AES-256-GCM queue encryption and HMAC rate-limit identities. Keep stable across web instances and workers. |

Generate the key in a private administrative environment using
`bin2hex(random_bytes(32))`; do not print it in shared logs. Rotating it makes
pending queue messages unreadable and resets HMAC bucket identities. Coordinate
rotation and discard/reissue pending emails as an explicit maintenance action.
Production uses no `.env` loader or credential file. The approved
[persistent Windows development launcher](local-development.md) stores its own
development-only settings with Windows DPAPI outside the web root/repository
and supplies process variables to AMPPS PHP. It also provides a private CLI
inbox for normal local token verification without external mail delivery.

## Transactional email configuration and delivery

Resend HTTPS is the selected provider for Exhibit, using verified domain
`exhibit.world` and sender `noreply@exhibit.world`. Set `MAIL_TRANSPORT=resend`,
`RESEND_API_KEY` as a private Exhibit-specific secret, `MAIL_FROM=noreply@exhibit.world`,
and web `APP_URL=https://exhibit.world` only when live configuration is approved.
See [Resend setup and controlled test checklist](resend.md) for complete Render
environment, transport security, permanent/transient failure handling, scheduling
and tests. No live credentials or email delivery are configured by this code.
Registration/recovery continue to enqueue only; web requests never call Resend.

### Optional existing SMTP transport

Unset/empty `MAIL_TRANSPORT` or `MAIL_TRANSPORT=smtp` retains the existing SMTP
transport, with no automatic fallback from Resend. SMTP remains environment-driven
without default hosts, ports or credentials:

| Variable | Meaning |
| --- | --- |
| `SMTP_HOST` | SMTP hostname with a publicly trusted matching TLS certificate. |
| `SMTP_PORT` | Explicit port, supplied by your service. |
| `SMTP_SECURITY` | `tls` for implicit TLS or `starttls` for mandatory STARTTLS. Plaintext/fallback delivery is not supported; TLS 1.2+ and certificate/hostname verification are required. |
| `MAIL_FROM` | Authorized sender email address. |
| `SMTP_USERNAME`, `SMTP_PASSWORD` | Both supplied for SMTP AUTH LOGIN, or both absent for an explicitly authorized relay. Other authentication schemes are not implemented. |

The worker needs the same database settings and `AUTH_KEY` as the web application.
On Windows, PHP OpenSSL must have a trusted CA store (`openssl.cafile`); do not
disable certificate validation to bypass a trust-store error.

Account creation and recovery atomically queue encrypted email with token state.
The queue does **not** store plaintext recipient addresses, link tokens, or message
bodies. Address identity remains only in the existing users table. Requests do not
contact SMTP, so known and unknown recovery addresses receive the same response
without SMTP-dependent timing or delivery-error differences.

After the selected transport is configured and explicit delivery approval:

```powershell
php backend\mail-worker.php --run
```

Run once per minute using an approved scheduler/cron job with environment secrets.
Each bounded run handles at most 25 messages, locks rows with `SKIP LOCKED`, and
retries transient failures after five minutes, up to five attempts. Permanent
Resend rejections retain the encrypted row in the existing exhausted-attempt state.
Expired messages, tokens,
and rate buckets are removed during worker runs. Monitor nonzero exit status and
the aggregate exhausted-retry count; do not log message payloads. After fixing an
outage, users can request new links (subject to limits); resend replaces the queued
message and resets its delivery attempts.

SMTP provides at-least-once delivery, not exactly-once: a process crash after
acceptance but before queue deletion can deliver the same link twice. Resend uses
stable idempotency keys retained by the provider for 24 hours. Link
consumption remains single-use. Acceptance by SMTP does not prove inbox delivery;
configure sender authorization/SPF/DKIM/DMARC with your provider and test spam/bounce
handling before launch. No bounce webhook is implemented.

The web application reports that registration email is **queued**, not delivered.
Until the web environment, secrets, selected transport, and scheduling are configured, this milestone is
not ready for live account onboarding. No scheduler or live service is configured
by this change.

## Authentication migration applied (do not rerun)

The operator has applied `001_initial.sql` and, after explicit approval and
recovery confirmation, `002_authentication.sql`. The independent read-only
verification report at `2026-10-19T12:15:20+00:00` confirmed the approved database,
endpoint/checksum, verified TLS and installed authentication schema.
**Do not rerun either migration or the activation wrapper.**
See [Activation runbook and complete acceptance plan](authentication-activation.md)
for the recorded private preflight, exact checksum/identity approval, one-time
apply/verification evidence, SMTP readiness review and end-to-end test cases.
Review `database/migrations/002_authentication.sql`:

- Add nullable `users.email_verified_at` and `users.session_version` (default 1).
- Add `auth_tokens` with SHA-256 token digests, purpose, expiry, and one active
  token per account/purpose.
- Add `auth_rate_limits` containing HMAC identities, atomic hit counts, and expiry.
- Add `auth_mail_queue` with authenticated encrypted payloads, delivery metadata,
  expiry, and one queued message per account/purpose.
- Leave exhibition, chapter, and content-block columns and behavior unchanged.

Existing accounts remain unverified and can request a verification email once
the web environment and email worker are configured and approved.

Nonconnecting review command:

```powershell
php database\auth-migrate.php --plan
```

The following runner behavior is retained as procedure documentation, not a
request to apply again. **Do not execute against Render without explicit approval**, reviewed backup and
recovery procedures, and a change window. `--check --database=NAME` performs
read-only identity/schema/privilege/conflict checks; `--verify --database=NAME`
performs read-only installed-schema checks. Apply requires
`--apply --database=NAME --sha256=APPROVED_HASH`, rechecks the schema and refuses
reruns. It executes in one transaction with timeouts, an advisory lock, and
pre-commit schema verification. Record its checksum and result. Do not run SQL at
startup or blindly rerun a migration after an ambiguous commit.

## Security and behavior

- Display names: 1-100 Unicode characters, no control characters. Emails:
  normalized trimmed lowercase, validated, max 254 bytes; existing database
  case-insensitive uniqueness handles concurrent duplicate registration.
- Passwords: at least 12 Unicode characters, at most 72 bytes, no NUL or invalid
  UTF-8; confirmations must match. The byte limit prevents bcrypt truncation.
  `password_hash(PASSWORD_DEFAULT)`, `password_verify`, and rehash-on-login.
- Session-only cookies: HttpOnly, SameSite=Lax, Secure for HTTPS, application path.
  Strict session IDs, cookies only, no URL session IDs. IDs and CSRF rotate on
  sign-in; IDs also rotate every 15 minutes. Idle expiry is 30 minutes, absolute
  expiry 12 hours. Server clock is authoritative. Password reset revokes every
  existing session through the database session generation.
- `logout.php` requires POST and valid CSRF, deletes the server session and cookie.
  Access it using the sign-out form on the sign-in/account screen, linked from the
  dashboard's Account navigation.
- All account state-changing requests require session CSRF. Prepared statements
  and escaped feedback are used throughout. Account and creator responses are
  no-store with no-referrer and anti-framing headers. Auth screens have a restrictive
  CSP and no analytics script; their fonts, CSS, artwork, and responsive design
  are retained.
- Verification links: 256 random bits, 24 hours. Reset links: 256 random bits,
  30 minutes. Digests only in the token table; purpose separation, transactional
  consumption, and replacement on resend prevent replay and concurrent reuse.
  Verification requires a confirmation POST so link scanners do not consume it.
- Links use `#token=...` fragments, not query parameters, so tokens do not enter
  application/access-log URLs or referrers. First-party JavaScript removes the
  fragment and posts the code with CSRF; PHP keeps it in the private session and
  redirects to a clean URL. A matching existing-styled code form provides a
  no-JavaScript fallback; paste the 64-character portion after `#token=`.
  Do not enable request-body, session, SQL-parameter, or decrypted-queue logging
  in upstream tracing/APM/proxy systems.
- PostgreSQL-backed limits survive new sessions and concurrent requests:
  sign-in 30/IP and 10/email per 15 minutes; other account endpoints 15/IP per
  15 minutes; registration 3/email/hour; each email purpose 3/email/hour plus
  60-second cooldown. Proxy forwarding headers are not trusted. In production,
  configure trusted-proxy real-client-IP handling at Apache/infrastructure level
  so `REMOTE_ADDR` is accurate; do not blindly trust client-provided headers.
  Limits are fixed windows; subsequent hits do not extend the window.
- Recovery and resend responses, status, and persistent address throttling do not
  distinguish unknown accounts. Registration uses a generic duplicate/details
  error; it does not promise registration enumeration resistance.
- `requireCreator()`, `Service::currentUser()`, `Service::ownsExhibition()`, and
  `requireExhibitionOwner()` are reusable. Foreign/nonexistent exhibitions return
  the same 404. Studio calls the ownership guard and loads metadata with both ID
  and owner predicates. The dashboard filters by the session-derived creator ID;
  draft creation never trusts owner/status/slug/publication fields from the client.
  Creation and Studio saving use CSRF-protected POST with a success
  redirect; the dashboard accepts only GET. Studio updates editable metadata and
  ordered chapters/content, with atomic owner/draft/revision predicates. Content
  writes lock the parent exhibition and validate every existing child ID against
  its exhibition/chapter before mutation. All content and metadata changes roll
  back together on failure; client ownership/publication fields are ignored. Existing
  `updated_at` supports stale-save rejection without a migration. Validation,
  conflict and save-service failures retain submitted fields; stale revisions
  cannot be silently rebased. Bare Studio
  redirects verified creators to the dashboard. Creator workflow analytics tags
  are omitted to avoid sending private draft identifiers to analytics services.
- Sessions are file-backed using PHP's configured private storage. Multiple web
  instances require a shared secure session store or sticky sessions before
  scaling; no distributed session system is added.

## Automated validation

Use your isolated runtime and extension options from [Database setup](database.md)
when the shared Ampps PHP configuration produces duplicate-extension warnings.

```powershell
php tests\run.php
php tests\exhibitions.php
php tests\auth.php
php tests\auth-http.php
```

The baseline suite does not connect to a database. The auth unit suite exercises
validation, encryption/tampering, configuration rejection, CSRF, real PHP session
rotation, cookie flags, and idle/absolute expiry. The HTTP suite starts and stops
its own loopback PHP server; checks actual forms, headers, CSRF rejection, logout
method restrictions, anonymous creator guards, and all public content pages.
It explicitly removes `DATABASE_URL` from the child environment.
The exhibition suite uses PDO doubles, without connecting, to verify validation,
owner-scoped parameter binding, slug collisions, and explicit failure behavior.

For full service and browser-request flows, first supply `TEST_DATABASE_URL` for
an **empty, disposable loopback database named `exhibit_test_SUFFIX`**, with
`TEST_DB_SSLMODE`/`TEST_DB_SSLROOTCERT`. Never point it at the initialized database.

```powershell
php tests\auth-postgres.php --run
```

This suite installs both migrations only into that empty test database, tests
registration/duplicate handling, login, verification expiry/replay/resend, reset
expiry/replay, rate limits, ownership, and HTTP end-to-end flows including logout,
account-enumeration responses, and other-session revocation. It removes its exact
test tables and timestamp function afterward. It never falls back to
`DATABASE_URL` or to a remote database. It inspects decrypted test-only email in
memory and never connects to SMTP. The original `tests/postgres.php` remains a
separate rollback-only test of the initial four-table foundation.

The same disposable suite now also checks actual exhibition draft round trips,
trusted owner/draft state, zero placeholder children, listing order/chapter counts,
CSRF and validation errors, owner-only dashboard/Studio requests, method
restrictions, refresh/reopen, and reopening after a new sign-in. Metadata tests
also cover persisted updates, stale/foreign/published save rejection, retained input
on validation/CSRF/database failures, and updated dashboard values. These scenarios
passed in the separately approved isolated PostgreSQL 18.6 run, alongside eight
authentication service groups and 23 database-backed authentication HTTP scenarios.
The disposable schema and cluster were removed after validation. A separately
approved single-private-draft browser test confirmed persistence and read-only
Studio behavior at desktop and mobile widths.
Future runs still require explicit disposable database/migration approval
(the metadata milestone authorizes this isolated testing only):
the test setup installs both existing migrations, never production migrations.

`tests/http.ps1 -BaseUrl ...` remains the separate loopback **Apache** private-file
and public-resource check; use an environment-configured web runtime. The PHP
built-in HTTP suite is not evidence of Apache deny rules or Docker behavior.

PHP lint, a real local PostgreSQL run, deployed HTTPS/proxy cookies, SMTP delivery,
and visual checks on desktop/mobile should all be included in deployment review.
Do not deploy, configure a live provider, schedule delivery, commit, or push without
approval.

### Local milestone validation

Validated with isolated PHP 8.4.26 and a temporary loopback PostgreSQL 18.6 cluster,
without connecting to Render or sending SMTP messages:

- Baseline offline: 54 tests passed.
- Auth unit/session/CLI/read-only guard tests: 10 groups passed.
- The same auth unit and PostgreSQL-backed service/HTTP suites also passed under
  the existing Ampps PHP 8.2.27 runtime with explicit, clean extension loading.
- Database-independent HTTP: 18 scenarios passed.
- PostgreSQL auth service tests: 8 groups passed.
- PostgreSQL-backed HTTP: 23 scenarios passed, including registration,
  verification gates, duplicate registration, logout/session replay, login,
  enumeration-safe recovery, request throttling, password reset and other-session
  revocation.
- Both migration CLIs applied successfully to the disposable local database;
  the authentication runner refused a second apply. That test schema was removed.
- Integrated-browser checks passed for all five account screens at actual
  1440px and 390px CSS viewport widths: forms fit without horizontal overflow,
  the existing font and button color were retained, CSRF inputs remained hidden,
  and no-JavaScript code-entry forms stayed hidden when JavaScript was enabled.
  Password visibility toggling, client-side password mismatch feedback, and
  same-page fragment-link capture with CSRF also passed browser checks.
- The original foundation assertion mismatch has now been corrected: `ON DELETE
  RESTRICT` expects SQLSTATE `23001` (restrict violation), with additional checks
  that the owner and owned exhibitions remain. Other invalid foreign-key writes
  still expect `23503`. The SQL constraint itself is unchanged.

The operator subsequently applied the approved authentication migration to Render
and supplied the successful independent read-only schema-verification report;
see the activation runbook for the exact identity, checksum, recovery and
verification evidence. No SMTP delivery or deployment was performed.

The subsequent separately approved [controlled Resend test](resend.md#first-controlled-authentication-test-2026-10-19)
passed local registration, real provider acceptance/inbox delivery, verification,
fresh sign-in, workspace access and logout for one approved account. The final
read-only report showed verified status, no remaining account tokens and an
empty queue. Worker scheduling, deployed HTTPS/reverse-proxy behavior,
Docker/Apache runtime integration and live recovery/resend/failure scenarios still
need separately approved environment-specific validation.
