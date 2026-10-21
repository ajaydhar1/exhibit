# Authentication activation runbook and acceptance plan

## Boundaries and current status

This procedure does not authorize deployment, migration, live SMTP configuration,
delivery, commits, or pushes. The existing visitor interface, navigation, design,
and sample creator data are unchanged. Credentials belong only in the operator's
private terminal or approved environment-secret store, never chat or source.

The initial four-table schema was previously initialized by the operator. The
operator has now also applied the explicitly approved authentication migration,
as recorded at the end of this document. **Never rerun either migration on that
database.** The procedure sections are retained as the approval/change record,
not as instructions to execute again.

The reviewed authentication SQL remains unchanged:

```text
002_authentication.sql
SHA-256: 1a7ffff5df0bd8752070c48885a415c5ea3ac035caafab7a01bc008507fabe0b
```

Compatibility with the checked-in initial schema:

- Adds nullable `users.email_verified_at`; existing accounts remain unverified.
- Adds positive `users.session_version`, default 1, including existing accounts.
- Creates three auth-only tables with user foreign keys, token-purpose uniqueness,
  bounded-purpose/check constraints, identity generation, and expiry/delivery
  indexes. Does not change content tables or their deletion/publication rules.
- Existing `users.id` is a bigint identity primary key, compatible with both new
  user references. Its case-insensitive email index supports registration's
  `ON CONFLICT (lower(email))` clause.
- Auth queue payloads are AES-256-GCM encrypted; the SQL contains no email
  credentials or cryptographic keys.
- DDL is transactional. `ALTER TABLE users` requires an access-exclusive lock
  briefly; use a maintenance/change window and a reviewed backup/recovery plan.
  Locks time out after five seconds; statements after 30 seconds. Timeouts are
  failures, not permission to retry blindly.

Local compatibility is not proof of the actual Render schema. The read-only
preflight below must pass on the exact intended endpoint before asking for apply
approval. No Render preflight or migration is implied by local test results; the separate
operator-assisted production evidence is recorded below.

## 1. Read-only Render preflight (credential assistance needed)

Use the previously validated isolated PHP runtime. Do not modify shared Ampps PHP
or DLLs. Use Render's existing **external** PostgreSQL URL without modifying its
encoding.

In a private PowerShell terminal in the repository:

```powershell
$DatabaseName = Read-Host 'Exact existing database name from Render'
.\database\auth-preflight.ps1 -Database $DatabaseName
```

The wrapper uses an already-set process `DATABASE_URL` or prompts for it with
hidden input. It preserves an explicit `DB_SSLMODE=require` or `verify-full`;
when unset, it defaults to `verify-full`. `verify-full` requires the supplied
trusted CA bundle, while `require` confirms encryption without requiring
certificate or hostname validation. Supply `-CaBundle` with a trusted PEM path
when using `verify-full`; it is not needed for Render's `require` mode. The
wrapper restores the previous environment and clears its secure input buffer. No
credential file is written.
Do not use PowerShell tracing, transcripts, screen sharing of secrets, or shell
commands that print environment values. The URL exists in process memory and is
inherited by PHP; local administrators/process-inspection tools can access it.

Without an apply switch this wrapper only runs `--check`; it cannot apply a
migration or deliver mail. Share the sanitized report, not the URL. Its separate
explicitly approved activation mode is described below.

Underlying CLI for an already configured private environment:

```powershell
$PhpHome = Join-Path $env:LOCALAPPDATA 'Exhibit\runtimes\php-8.4.26-nts-x64'
$Php = Join-Path $PhpHome 'php.exe'
$PhpArgs = @('-n', '-d', "extension_dir=$PhpHome\ext", '-d', 'extension=pdo_pgsql', '-d', 'zend.exception_ignore_args=1')
& $Php @PhpArgs 'database\auth-migrate.php' '--check' "--database=$DatabaseName"
```

Preflight checks:

1. Validate connection configuration and URL database name before connecting.
2. Open a `REPEATABLE READ READ ONLY` transaction; confirm read-only mode.
3. Confirm the connected database name exactly, writable-primary state, and active
   TLS. The wrapper accepts Render's `require` mode or stricter `verify-full`;
   remote TLS cannot be disabled.
4. Confirm database CONNECT, public-schema USAGE/CREATE, users REFERENCES, and
   effective ownership of users (required for ALTER, not satisfied by DML grants).
5. Validate foundation columns/types/nullability/defaults, identities, primary
   keys, foreign keys (including `ON DELETE RESTRICT`), ordering/check constraints,
   enabled timestamp triggers, and valid case-insensitive email/owner indexes.
6. Require only the original four tables, no unexpected views/tables, no added auth
   columns, no conflicting `auth_` relation/index/sequence/type/routine/constraint
   names, no `users_session_version_check`, and no unexpected row-security setup.
7. Print the exact database name, endpoint fingerprint, SQL checksum, PostgreSQL
   version, TLS status/version, successful privilege flags, schema result, and UTC
   timestamp. No username, password, raw URL, user rows, or token payloads are read
   into the report. Roll back the read-only transaction.

`endpoint_sha256` binds the lowercase configured hostname, explicit/default port,
and database name without exposing the hostname. Compare it between preflight and
post-migration verification. It is not a credential or cryptographic server
attestation; verified TLS authenticates the hostname.

Preflight checks use metadata SELECT/SHOW and transaction-local settings only.
They do not create probe objects, acquire DDL/advisory locks, update data, send
email, or call `nextval`. A role must have adequate migration rights, but a separate
read-only transaction enforces this probe's no-write behavior.

**Stop on any failed or incomplete report.** Do not grant new privileges, repair
schema drift, change the URL/TLS policy, or fall back to the initial migration
without separately reviewing the cause.

## 2. Exact approval gate

Before any production write, present:

- Exact confirmed database identity and endpoint fingerprint from the report.
- Migration filename and SHA-256 above, unchanged since preflight.
- Successful TLS, foundation compatibility, privilege, and conflict results.
- Fresh local test results; backup/recovery readiness; intended change window.
- Explicitly state that only the authentication migration is proposed, and that
  deployment, email configuration, delivery, and user acceptance tests are not
  included in this approval.

Request explicit permission to apply this **exact** checksum once to this
**exact** database/endpoint. A successful preflight, approval of the overall
project, or approval of the previous migration is not apply approval. If the SQL,
target, or schema changes, repeat preflight and obtain fresh approval.

## 3. Apply once, only after explicit approval

In the same private environment and against the same endpoint:

The private-input wrapper can freshly preflight, enforce the approved database,
endpoint fingerprint and SQL checksum, apply once, and independently verify.
It requires an explicit apply switch and both approval bindings:

```powershell
$CaBundle = Read-Host 'Absolute path to the trusted CA bundle already used successfully'
.\database\auth-preflight.ps1 -Database 'exhibit_s9xh' -CaBundle $CaBundle `
  -ApplyApprovedMigration `
  -ApprovedSha256 '1a7ffff5df0bd8752070c48885a415c5ea3ac035caafab7a01bc008507fabe0b' `
  -ExpectedEndpointSha256 'd4d80ef0f41ae703e8231ede22530c553cf762d329f1030ceb76b94dd1cb73a0'
```

Use this only after the approval/recovery gates; it still prompts privately for
the URL if not present and restores the prior environment afterward. On any
failure after apply starts, **do not rerun the activation wrapper**. Use the
read-only verifier to establish the outcome. It never configures or sends email.

Underlying apply command, for an already configured private environment:

```powershell
& $Php @PhpArgs 'database\auth-migrate.php' '--apply' "--database=$DatabaseName" `
  '--sha256=1a7ffff5df0bd8752070c48885a415c5ea3ac035caafab7a01bc008507fabe0b'
```

The runner requires the checksum, rechecks identity and the original schema under
an advisory lock, locks users, applies the SQL, validates the resulting schema
inside the transaction, and commits once. It refuses a second apply. No migration
executes at application startup.

After a successful commit, run the separate **read-only** verifier:

```powershell
& $Php @PhpArgs 'database\auth-migrate.php' '--verify' "--database=$DatabaseName"
```

Confirm the same database, endpoint fingerprint, checksum, and TLS policy. The
verifier checks the seven-table set, all new columns/defaults/nullability/identity,
token-purpose uniqueness and validated checks, user foreign-key cascades, indexes,
retained foundation schema, and DML/identity-sequence privileges for this role.
Do not run disposable fixture suites against Render. A CLI migration role's
permissions do not prove a different web/worker role has been granted access;
if roles differ, review those grants separately without printing credentials.

Record sanitized reports and the operator-approved change record. For an ambiguous
commit or connection loss, run `--verify` and inspect metadata before doing
anything else. Do not rerun apply. Partial/mismatched schema needs investigation,
not automatic drops. An applied migration has no automatic destructive rollback;
use the reviewed backup/recovery procedure if recovery is necessary.

## 4. SMTP worker readiness review

This section records the original SMTP review. Resend HTTPS is now the selected
provider for `exhibit.world`, implemented behind the same queue/CLI worker with
`MAIL_TRANSPORT=resend`; SMTP remains an optional legacy transport. See
[Resend setup](resend.md) for the current API-key/environment configuration,
permanent/transient handling, idempotency, scheduling and controlled-test gates.
At the time of that review, no live email configuration or delivery had occurred.
The later separately approved [single-account Resend test](resend.md#first-controlled-authentication-test-2026-10-19)
passed local registration, delivery, verification, login, workspace access and
logout; production deployment and scheduling remain separate approval gates.

Implementation: `backend/smtp.php`, `backend/mail-worker.php`, and encrypted queue
generation in `backend/auth.php`. No live SMTP connection is made by a web request.

| Area | Implemented behavior / activation requirement |
| --- | --- |
| Provider | None chosen. Obtain authorized SMTP host/port, sender, authentication method, sending limits, and production-network reachability from the actual provider. AUTH LOGIN or an authorized no-auth TLS relay is supported; API-only/OAuth-only providers require a separate integration decision. |
| TLS | Implicit TLS or mandatory STARTTLS, TLS 1.2+, peer and hostname verification, no plaintext fallback/self-signed bypass. Configure the runtime's trusted CA store, including `openssl.cafile` where needed on Windows. |
| Secrets | Supply `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURITY`, `MAIL_FROM`, paired `SMTP_USERNAME`/`SMTP_PASSWORD`, database settings, and the same stable `AUTH_KEY` as the web app through approved environment secrets. No source/dotenv credential storage. |
| Canonical URLs | Web `APP_URL` must be the real HTTPS app URL/subdirectory. Test generated links without logging their codes. Queue encryption key must match; do not generate a new key each worker run. |
| Sender reputation | Verify sender/domain authorization; configure provider-prescribed SPF/DKIM/DMARC. Test inbox/spam and bounce behavior using controlled mailboxes. No bounce webhook is implemented. |
| Delivery | Up to 25 messages per explicit run, row locks with SKIP LOCKED, five-minute retry delay, maximum five attempts; expired queue messages/tokens/rate buckets are removed. No automatic scheduler is installed. |
| Delivery semantics | SMTP acceptance is not inbox proof. Crash after SMTP acceptance can duplicate delivery before deletion; tokens remain single-use. No exactly-once guarantee. |
| Scheduling | After separate email/delivery approval, schedule `php backend\mail-worker.php --run` about once per minute. Ensure network/DB availability, bounded overlapping jobs, and secret inheritance. |
| Monitoring | Alert on nonzero exit, exhausted-retry aggregate, backlog/age and provider delivery failures. Observe SMTP timeouts, quotas and network restrictions; never log payloads, recipient addresses, credentials, SQL bind values, tokens, or SMTP transcripts. |
| Recovery | Keep `AUTH_KEY` backed up as a secret. Rotation makes old queued payloads unreadable and changes rate identities. Coordinate queue cleanup/reissue explicitly, not silent retry forever. |
| Database locking | Worker holds a queue-row transaction during SMTP. Slow SMTP can delay resend/consumption for that account; validate latency/lock behavior in staging and watch deadlock/retry failures. Do not change lock strategy or introduce a new queue framework as part of activation without evidence. |
| Privacy | Remove token query strings at source (links use fragments), disable request-body/session capture in APM/proxy logs, restrict DB/session storage and private backend directories. Do not share real links in support logs/screenshots. |

No SMTP credentials, production scheduler, DNS records, sender identities, or live
test messages are configured by preparing this runbook.

## 5. Complete acceptance test plan

### Environment and safety

First run all fixture tests on a fresh loopback `exhibit_test_SUFFIX` database.
Use isolated test accounts and private controlled inboxes for email acceptance
testing only after separate approval of account/data creation and delivery.
Never use real users' passwords, addresses, tokens, sessions, or mailbox contents
as fixtures. Never alter expiry timestamps or simulate SQL tampering on Render.
Destructive/race/expiry tests belong in local/staging fixtures.

Record test ID, environment/runtime, UTC time, pass/fail and sanitized evidence.
Do not record passwords, full emails, link fragments, session cookies or CSRF
values. Registration/login should use the approved UI unchanged on desktop and
mobile; no test requires redesign or visitor navigation changes.

| ID | Action | Expected result |
| --- | --- | --- |
| REG-01 | Register a new controlled creator with valid name/email and matching 12+ character password. | One user, password hash not plaintext, unverified, encrypted verification email queued atomically, rotated authenticated session, verification screen. |
| REG-02 | Submit blank/overlong/control-character name, invalid email, <12 character or >72 byte password, invalid UTF-8/NUL, mismatch. | Server rejects with safe feedback; no account/email/token persisted; entered passwords are never reflected. |
| REG-03 | Repeat existing email using alternate casing; race two matching registrations locally. | Exactly one account; graceful generic failure for duplicate, no raw SQL or credentials disclosed. |
| REG-04 | Repeated registration from same address/IP. | Persistent configured email/IP limits apply; new session does not bypass. |
| VER-01 | Deliver queued verification email to controlled inbox using approved SMTP worker. | SMTP TLS validated, authorized sender, real canonical HTTPS fragment link, inbox/spam delivery established privately. No token/address in application or infrastructure logs. |
| VER-02 | Open link and confirm in existing browser; repeat in fresh browser. | Fragment cleared, code posted with CSRF, confirmation POST verifies once, workspace becomes accessible after sign-in. GET/scanner access alone does not consume. |
| VER-03 | Reuse link, modify code, use reset-purpose code, or locally expire it after 24 hours. | Safe invalid/expired response, no unauthorized verification. |
| VER-04 | Resend for unverified account; try again within 60 seconds or beyond hourly limit. | New token replaces old; old link invalid; cooldown/hour limit enforced. Known/unknown account response and throttling do not enumerate. |
| VER-05 | With JavaScript disabled, paste code in matching existing-styled fallback. Also open a fragment link when already on that same page. | Both link-capture paths function; codes do not enter server request URLs. |
| LOG-01 | Sign in using correct password and differently-cased/trimmed email. | Session ID/CSRF rotate; verified creator goes to dashboard, unverified creator goes to verification. |
| LOG-02 | Use wrong password or unknown account; repeat up to configured limits. | Same safe failure; persistent per-email/per-IP throttling; no credential reflection or SQL errors. |
| SES-01 | Inspect HTTPS cookie and responses privately. | Secure, HttpOnly, SameSite=Lax, application path, session-only cookie; no-store, no-referrer and anti-framing headers. Auth CSP and font/assets load. |
| SES-02 | Locally test idle 30 minutes, absolute 12 hours, and periodic ID rotation at 15 minutes. | Expired sessions lose workspace access; periodic rotation retains identity; old signed-in session ID cannot be replayed. |
| SES-03 | Locally inject a preselected/unknown session ID or cross-site POST without matching CSRF. | Strict session handling prevents fixation; all state-changing endpoints reject missing/invalid CSRF. |
| OUT-01 | Sign out from the Account/sign-in confirmation form. | POST destroys session and cookie, redirects to sign-in; guarded pages and replayed old session fail. GET logout returns 405; bad-CSRF logout returns 403 without signing out. |
| REC-01 | Request recovery for controlled known and unknown addresses. | Same status/message; only known address queues encrypted mail; request does not block on SMTP or disclose its failure. |
| REC-02 | Repeat requests before cooldown and above hourly/IP caps, including new sessions. | Limits persist; no mail flood; no distinction between known/unknown identities. |
| RST-01 | Receive approved recovery email, open link, submit a valid matching new password. | One-time 30-minute code consumed atomically; hash updated; old password fails, new password works. Reset itself does not sign the browser in. |
| RST-02 | Reset while signed in in two browsers. | Both old authenticated sessions are rejected on subsequent protected requests through session-version revocation. |
| RST-03 | Locally test expired/tampered/wrong-purpose/replaced/used code and two simultaneous reset submissions. | No invalid reset; at most one use succeeds; validation mismatch does not consume a valid token. |
| ACC-01 | Request all three creator pages anonymously/unverified/verified. | Anonymous redirects to sign-in, unverified to verification, verified sees unchanged sample content. |
| ACC-02 | Locally test ownership helper with owned/foreign/nonexistent exhibition IDs. | Only owner passes; foreign/nonexistent share 404 behavior. No sample preview begins persisting data. |
| PUB-01 | Open homepage, Explore, exhibition overview and all chapters without session/database. | Existing public pages and visitor navigation unchanged and accessible. |
| UI-01 | Test five auth screens at desktop/mobile widths; keyboard navigation, show/hide, mismatch feedback. | Existing colors/artwork/fonts/layout/responsiveness preserved; no overflow or visible CSRF fields. |
| MAIL-01 | Locally/staging simulate SMTP refusal, timeout, wrong key, cert failure and unavailable database. | Safe explicit errors/exit status, no credential or recipient logging, retry metadata correct; no plaintext TLS fallback. |
| MAIL-02 | Locally/staging test expired queue cleanup, maximum attempts, multiple workers and send-after-acceptance crash. | Expired links never regain validity; exhaustion observable; row claiming avoids ordinary duplicate work; crash duplicates acknowledged without token replay. |
| OPS-01 | Validate actual deployed proxy IP and session-store configuration without printing credentials. | Accurate trusted `REMOTE_ADDR`, no blind forwarding-header trust, intended cookie HTTPS/path; shared secure sessions or sticky sessions before scaling. |
| OPS-02 | Check private backend/database/tests/config routes through Apache/Docker. | 403/404, no listings/secrets. Run existing Apache HTTP harness in the approved environment. |

### Automated commands

Use PHP 8.4 isolated runtime and extensions, or a clean supported PHP 8.2+ setup:

```powershell
& $Php @PhpArgs 'tests\run.php'
& $Php @PhpArgs -d extension=openssl 'tests\auth.php'
& $Php @PhpArgs -d extension=openssl 'tests\auth-http.php'
# Only an EMPTY loopback exhibit_test_SUFFIX database via TEST_DATABASE_URL:
& $Php @PhpArgs 'tests\postgres.php' '--run'
& $Php @PhpArgs 'tests\auth-migrations.php' '--run'
& $Php @PhpArgs -d extension=openssl 'tests\auth-postgres.php' '--run'
```

Run those database suites sequentially: each expects the empty schema and cleans
up after itself. They do not accept remote test targets or fall back to the
production URL. Do not run them against Render.

The foundation restriction assertion now expects SQLSTATE `23001`
(`restrict_violation`) for deleting a referenced owner, and additionally checks
that both owner and exhibitions remain. Ordinary invalid foreign-key writes
still expect `23503` (`foreign_key_violation`). No database constraint is relaxed.

Live SMTP certificate/provider/inbox delivery, production scheduling, deployed
TLS/proxy/session behavior, APM privacy settings and full concurrent/fault-injection
acceptance remain separate from local unit/service/HTTP test evidence.

## Local preparation results (2026-10-19)

| Validation | Result |
| --- | --- |
| Baseline offline suite | 54 tests passed. |
| Auth unit/session/CLI/read-only guard suite | 10 groups passed. |
| Database-independent HTTP | 18 scenarios passed. |
| Foundation PostgreSQL | All 16 checks passed after the precise SQLSTATE assertion correction. |
| Auth migration/preflight/verification PostgreSQL | 16 scenarios passed, including read-only reporting, exact-target/hash rejection, conflicting types/sequences/indexes/columns, schema drift, RLS, one-time apply, installed-schema checks and rerun refusal. |
| Auth PostgreSQL service/HTTP | 8 service groups and 23 HTTP scenarios passed. |
| Runtime compatibility | New migration suite and auth unit guards passed on isolated PHP 8.4.26 and existing Ampps PHP 8.2.27 with clean extension loading. |
| Lint | All 38 PHP files passed; private PowerShell wrapper parsed without errors. |
| Test isolation | Temporary PostgreSQL 18.6, loopback-only, fresh `exhibit_test_activation`; no Render fallback. Test suites left zero public test tables. |
| SQL integrity | Both initial and auth SQL files unchanged; authentication checksum remains the value recorded above. |

The new shared foundation validator replaces duplicated test-only metadata
validation; it is used by read-only preflight, approved apply, verification, and
the original live foundation check. It is not loaded into public visitor pages.

### Operator-assisted Render preflight

The operator ran the hidden-input read-only wrapper in their private terminal and
provided the sanitized successful report. Credentials were not provided to the
assistant; this is operator-supplied live evidence, not an independently repeated
agent connection.

| Check | Reported result |
| --- | --- |
| Checked at UTC | `2026-10-19T11:50:36+00:00` |
| Exact database | `exhibit_s9xh` |
| Endpoint SHA-256 | `d4d80ef0f41ae703e8231ede22530c553cf762d329f1030ceb76b94dd1cb73a0` |
| Migration SHA-256 | `1a7ffff5df0bd8752070c48885a415c5ea3ac035caafab7a01bc008507fabe0b` |
| PostgreSQL | `18.6 (Debian 18.6-1.pgdg12+2)` |
| Connection | `verify-full`, TLS active, `TLSv1.3` |
| Transaction and server | Read-only checks, writable primary confirmed |
| Privileges | CONNECT, public USAGE/CREATE, users REFERENCES and effective users ownership: all true |
| Schema | Initial compatible; no conflicting auth objects |
| Outcome | Read-only checks passed; no migration, application writes, or email delivery performed |

The operator explicitly approved the exact one-time migration and current change
window. Backup/recovery was initially unconfirmed, so execution was held. The
operator then reviewed Render's seven-day PITR window and confirmed that
**2026-10-19 11:55:23 UTC** is an actual available pre-change recovery point and
explicitly reconfirmed proceeding. No restoration or settings change was started.

[Render's backup documentation](https://render.com/docs/postgresql-backups)
states that paid databases support PITR and logical exports, while Free compute
does not provide those recovery features. Check the actual database's Recovery
page and available recovery window; do not assume the account's plan or that
protection already exists. A private `pg_dump` backup is an alternative for a free
database, but it must be stored securely outside the repository and its restore
procedure validated on an empty isolated database. Never start a production
restore, create a paid recovery instance, change a plan, or upload database data
as part of this review without separate approval.

### Approved authentication migration and verification

After exact-target/checksum approval and confirmed recovery readiness, the operator
ran the private-input activation wrapper and supplied:

```text
PASS: Approved migration committed once and independent read-only verification passed. Do not rerun apply.
```

The independent verification report confirms:

| Check | Reported result |
| --- | --- |
| Verified at UTC | `2026-10-19T12:15:20+00:00` |
| Exact database | `exhibit_s9xh` |
| Endpoint SHA-256 | `d4d80ef0f41ae703e8231ede22530c553cf762d329f1030ceb76b94dd1cb73a0` (matches preflight/approval) |
| Applied SQL SHA-256 | `1a7ffff5df0bd8752070c48885a415c5ea3ac035caafab7a01bc008507fabe0b` (matches approval) |
| PostgreSQL / transport | `18.6 (Debian 18.6-1.pgdg12+2)`, `verify-full`, active `TLSv1.3` |
| Verification transaction | Read-only, writable primary confirmed |
| Migration privileges | CONNECT, public USAGE/CREATE, users REFERENCES and ownership: all true |
| Runtime privileges for this role | Auth-table SELECT/INSERT/UPDATE/DELETE and identity-sequence USAGE: true |
| Schema | `authentication verified`: expected seven-table set, auth fields/defaults/identities/checks/uniqueness/FKs/indexes, retained foundation schema |

The verifier's "No migration, application writes, or email delivery performed"
line describes the **read-only verification operation**, not the preceding
approved apply. The wrapper's final PASS confirms that apply reported a commit
and independent verification passed.

This remains operator-assisted evidence; the assistant did not receive credentials
or independently repeat a live connection. No production test accounts, token
requests, or test emails were created.

**Render status: authentication migration applied once and schema verified.
Do not rerun either migration or the activation wrapper on this initialized
database.** Web activation, environment/secret configuration, SMTP configuration,
scheduling and controlled delivery/acceptance testing remain separate approval
gates. No deployment, live email setup/delivery, commit, or push occurred.

The statements above record the migration milestone. The subsequent controlled
local-web/Resend test is recorded in the [email guide](resend.md#first-controlled-authentication-test-2026-10-19);
it did not rerun migrations or authorize production deployment or scheduling.
