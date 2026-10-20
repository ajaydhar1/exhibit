# Persistent Windows development

## Isolation

Use the existing AMPPS PHP executable through the Exhibit launcher, not the VS
Code PHP Server button or the restricted acceptance server on port 8091.
The launcher uses `php -n` with explicit PostgreSQL/OpenSSL extensions, a private
session directory, and private error logs. It does not change shared AMPPS
configuration, register a Windows service, or configure automatic startup.

The canonical application URL is **http://127.0.0.1:8085**. PostgreSQL listens
only on **127.0.0.1:55432**, with SCRAM password authentication.
`exhibit_development` is persistent and contains only local development data.
No production accounts, passwords, or records are imported.

The application runs as `exhibit_dev_app`, with data privileges but no schema
creation, role administration, or database creation privileges. Schema setup
uses a separate local `exhibit_dev_admin` credential. The application role is
also denied connections to other databases by PostgreSQL's host authentication.

The launcher removes inherited database, application, authentication, test,
PostgreSQL, SMTP, and Resend environment variables from child processes before
supplying its own development configuration. `APP_ENV=development` rejects any
database except the literal `127.0.0.1:55432/exhibit_development` before opening a
connection. `APP_ENV=test` independently permits only loopback `exhibit_test_*`
databases. Production's existing process variables and verified TLS are unchanged.
The local web router permits only public application pages and assets, checks
the canonical Host header, and never exposes backend files, tests, configuration,
Git data, or local secrets. PHP's built-in server does not enforce `.htaccess`.

## One-time setup

Software installation, persistent initialization, and migration execution require
explicit operator approval. Do not run initialization as routine startup.

The complete PostgreSQL 18.6 runtime must be available under:

```text
%LOCALAPPDATA%\Exhibit\runtimes\postgresql-18.6\pgsql
```

It needs `bin`, `lib`, and `share` (including `share\postgres.bki`); the existing
command-line-only installation is not sufficient for `initdb`. A complete
official Windows binary distribution can supply these files without replacing
the shared installation. AMPPS PHP is expected at
`C:\Program Files\Ampps\php\php.exe`.

From the repository directory, after approval:

```powershell
powershell -NoProfile -File .\database\local.ps1 initialize
```

This creates `%LOCALAPPDATA%\Exhibit\development`, restricts its Windows ACL to
the current user and SYSTEM, generates independent credentials and an auth key,
and encrypts those secrets with Windows DPAPI for the current user.
`settings.json` is outside the repository and web root. Do not copy the existing
production/acceptance-test authentication key into it.

Initialization creates a fresh cluster/database and applies exactly the existing
`001_initial.sql` and `002_authentication.sql` in a checked transaction. Database
identity, empty-schema preflight, and installed-schema validation are mandatory.
It prints migration checksums, not credentials. Initialization refuses an
existing development directory; it never resets persistent records or retries
against another database. A partial setup failure must be inspected before
recovery; do not delete or rerun blindly. No migration runs at application startup.

## Everyday commands

```powershell
# Start both servers; keep this terminal open.
powershell -NoProfile -File .\database\local.ps1 start

# From a second terminal:
powershell -NoProfile -File .\database\local.ps1 status
powershell -NoProfile -File .\database\local.ps1 check

# Stop only this environment's servers, keeping all database records.
powershell -NoProfile -File .\database\local.ps1 stop
```

`start` verifies database identity/schema/permissions and waits for sign-in HTTP
readiness. `stop` validates process identities before stopping specific PIDs and
shuts PostgreSQL down gracefully. Other PHP/PostgreSQL servers are not stopped.
If the web terminal closes unexpectedly, PostgreSQL may remain running; use
`status` followed by `stop` rather than killing processes by name.

## Registration and local verification/reset links

Register a new development-only account at
http://127.0.0.1:8085/register.php. Use a password that is not used elsewhere.
Registration retains the real application's encrypted queue, expiring tokens,
rate limits and verified-email requirement.

Read the local queue in a **private terminal**:

```powershell
powershell -NoProfile -File .\database\local.ps1 inbox
```

Open the verification/reset URL there and complete the normal confirmation
screen. Tokens are never automatically consumed or accounts pre-verified.
The CLI inbox is read-only: it does not send email, delete messages, or change
the worker's delivery state. Expired messages are omitted. Request a fresh link
through the application when needed, respecting normal rate limits.

Inbox output contains sensitive local links. Do not paste it into chat, commit
it, redirect it into shared logs, or expose it over HTTP. No real SMTP/Resend
credentials are configured. The development launcher uses an unsupported
`local-inbox` worker transport deliberately, so accidentally invoking the normal
mail worker with that environment fails rather than sending real mail.

Sign in, create an exhibition, add/edit/reorder chapters and supported blocks,
save, leave Studio and reopen it. Stop/start the environment to confirm records
persist. For an exhibition you explicitly intend to make public, save and choose
**Review & publish**, then confirm. Open its public link signed out to check the
overview and chapters. Published exhibitions are read-only and cannot be
unpublished in this MVP; do not publish existing private development drafts merely
to test. Prefer the disposable environment for acceptance scenarios. See the
[publishing acceptance checklist](../README.md#publishing-acceptance-checklist).

## Troubleshooting

- **Sign-in says account services unavailable:** use this launcher's URL and
  `check`. The VS Code server on port 3000 does not load `.env` or this launcher's
  private configuration. Authentication needs `APP_URL` even on a GET; subsequent
  account actions also need the database and stable `AUTH_KEY`.
- **Port 8085/55432 occupied:** the launcher refuses to displace another process.
  Inspect the listener and confirm ownership; do not kill unrelated servers.
- **Private logs:** `%LOCALAPPDATA%\Exhibit\development\php-error.log` and
  `postgres.log`. Request-body/password/token logging must remain disabled.
  Raw database exceptions and statement parameters are not written by the app.
- **Wrong database/profile:** stop and correct the local configuration. Do not
  weaken the local guard or point development at Render.
- **DPAPI decryption fails:** run as the Windows user who initialized the
  environment. Do not generate replacement secrets over existing records; that
  would invalidate encrypted queue data.
- **Missing PostgreSQL initialization files:** a command-line-tools installation
  alone lacks `share`. Obtain the complete approved runtime before initializing.
- **Duplicate PHP extensions:** the launcher bypasses shared `php.ini` with `-n`;
  do not edit shared AMPPS DLLs or configuration to repair this environment.
- **Schema mismatch or partial initialization:** stop, inspect the dedicated
  local directory and migration history/checksums, and obtain approval for
  recovery. Do not use production migrations as an automatic repair mechanism.

## Tests and production

Use the suites documented in [README](../README.md). Database-writing tests use
only fresh disposable loopback `exhibit_test_SUFFIX` databases, never
`exhibit_development`. Do not test destructive failure/cascade cases against
your persistent development exhibitions.

Production remains the existing Docker/Render deployment with its own
`DATABASE_URL`, `APP_URL`, `AUTH_KEY`, mail configuration and verified database
TLS. Local DPAPI files, database directories and development secrets must never
be copied into the image, repository or production environment. Nothing here
deploys the application or changes production data.

## Setup verification (2026-10-20)

The approved environment was initialized using PostgreSQL 18.6 and AMPPS PHP
8.2.27. The prior shared PHP/VS Code server returned sign-in HTTP 503 because its
required application configuration was missing; the dedicated launcher now
serves sign-in successfully without altering authentication rules or shared
configuration.

A new synthetic local creator registered through the browser, verified using
the private CLI inbox and normal HTTP confirmation, and signed in. The browser
created a local exhibition, edited metadata, added two chapters and text/artifact
blocks, reordered chapters and blocks, saved, reopened, signed out/in, and
confirmed the exact persisted IDs/content/order. A full stop/start of both
servers preserved the draft, its content, and the private PHP session storage.
The browser was signed out afterward; register your own development account
instead of using the synthetic walkthrough account.

Loopback-only listeners, rejected foreign Host headers, inaccessible private
paths, current-user/SYSTEM directory permissions, and the application's
nonadministrative database role were checked. Two setup-tool compatibility
issues were corrected: PostgreSQL's slash-normalized Windows command line
needed normalization for PID checks, and database address checks needed
`host(inet_server_addr())` rather than an inet cast containing a CIDR suffix.

Regression results: 57 offline configuration/contract checks, 15 exhibition
unit groups, 10 authentication unit groups, 18 offline HTTP scenarios, and 11
Studio UI tests passed. The 10 offline mail groups also passed without real
delivery; CLI diagnostics/migration entry points were checked to reject remote
targets under the development profile before connecting.
Real database regressions ran only in a newly created
disposable `exhibit_test_*` database: 16 foundation checks, 16 authentication
migration checks, eight authentication service groups, exhibition/Studio
persistence workflows, and 23 authenticated HTTP scenarios passed. That
disposable database was removed; the persistent development records were kept.
No production connection, production data copy, real email, or deployment was
used.
