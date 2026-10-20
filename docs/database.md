# Exhibit database foundation

## Scope and current behavior

This document records the original PostgreSQL foundation milestone and its
initialization procedure. Authentication now uses this foundation; see
[Authentication setup](authentication.md) for the new approval-gated migration,
account/session behavior, encrypted email queue, and current test commands.
The authored sample remains database-independent. Draft creation persists exhibition
metadata, the dashboard lists creator-owned records, and Studio loads and saves
an owned draft's metadata and ordered chapters/content. Publishing now exposes a
saved exhibition through published-only public overview/chapter queries.
Sections below describing disconnected screens or
exactly four tables refer to the original foundation, not the authentication
extension.

### Persistent draft implementation

No schema adjustment or migration is required for this workflow.
`backend/exhibitions.php` uses the existing PDO helper and native prepared
statements. The form maps title to `title`, short introduction to `summary`,
subject to `category`, and cover URL to `cover` as `{"url":"https://..."}`.
Blank optional metadata values become SQL NULL. Studio also edits the existing
`subtitle` and `description` columns; creation leaves them empty.
The trusted session supplies `owner_id`; the service generates a random
URL-safe slug and always inserts `status = 'draft'`, `published_at = NULL`.
Slug conflicts alone receive a bounded retry. No child rows are created.

Dashboard reads filter by owner, sort by `updated_at DESC, id DESC`, and derive
chapter counts in the query. Studio requires the existing ownership guard and
fetches metadata with both exhibition and owner IDs. Text is escaped on output;
HTTP(S) cover URLs are length/scheme/credential validated on write and read and
are loaded only by the browser, never fetched server-side. A failed image request
leaves the existing decorative background; it does not affect the saved record.
Creation and Studio saving use CSRF-protected POST/303 redirect; the dashboard is
read-only. Studio updates editable metadata through native prepared statements.
Its atomic WHERE predicate includes ID, owner, draft status,
and a revision derived from the existing timestamp (`md5(updated_at::text)`).
The revision is an opaque concurrency marker, not an authorization credential.
Missing/stale revisions return 409; failed saves retain entered values and the
original revision rather than silently rebasing onto a newer record.
Content saves also update ordered chapter/block records in the same transaction
as the parent metadata/revision. No migrations, slug changes, or publication
changes are involved.
Database errors produce a sanitized log and 503, not an empty/success fallback.

### Publication and public reads

No additional migration or index is required. Publishing uses the existing primary
key, `status`, `published_at`, publication-state constraint and timestamp trigger.
The service locks the owned exhibition row with `FOR UPDATE`, validates the saved
revision/title/content, and atomically sets status and publication time. Saves
use the same parent lock. Publication changes neither child IDs/content/order nor
ownership. Duplicate authenticated requests preserve the first publication time.
Published exhibitions remain immutable through the Studio save action.

Public overview/chapter loading selects only `status = 'published'` with a non-null
publication timestamp, returning visitor metadata and the ordered content tree in
one statement. It never returns owner/account information or starts a session.
Chapter lookup stays within that exhibition's tree. Missing, draft and invalid
chapter requests return a uniform 404; service failures return 503. The original
sample route without parameters does not connect to PostgreSQL.

Public URLs use existing IDs; generated slugs are unchanged. Readiness requires a
saved valid title and a chapter with narrative, text/artifact content or a valid
figure, not optional metadata/cover. Saved content is structurally validated
without reapplying the incoming 1 MiB JSON limit: encoding/assigned IDs can expand
a valid request's persisted representation. See the
[publishing workflow and acceptance checklist](../README.md#publishing-and-public-viewing-milestone-3).

`tests/exhibitions.php` is connection-free. Actual persistence/ownership/HTTP
workflow coverage is included in `tests/auth-postgres.php --run` through
`tests/exhibition-workflow.php`. That established suite installs/removes schemas
only in an empty, disposable loopback `exhibit_test_SUFFIX` database. The separately
approved PostgreSQL 18.6 validation passed all 16 foundation checks, eight auth
service groups, exhibition persistence/ownership checks, the draft HTTP workflow,
and 23 database-backed authentication HTTP scenarios. The exact database, role,
loopback listener, server PID, empty schema, and isolated data directory were
verified before migrations. Test schemas and the temporary cluster were removed
afterward; no Render configuration was used. This documentation does not authorize
future runs: do not use a development or production database, and do not run
migrations while disposable-test migration approval is withheld.

The operator has now also applied the explicitly approved authentication migration
and supplied a successful independent read-only verification report. See the
[activation record](authentication-activation.md) for exact identity, checksum,
recovery and verification evidence. **Do not rerun either migration.** The original
four-table-only live foundation check below predates this extension; use
`database/auth-migrate.php --verify --database=NAME` for the initialized auth schema.

The initial migration is [001_initial.sql](../database/migrations/001_initial.sql).
It was approved and applied to the existing Render database; **do not rerun it**.
The initialization instructions below are retained as procedure documentation,
not a request to execute them again.

### Completed milestones (2026-10-19)

The operator reported the following results from the local credential-bearing
terminal; no secrets or connection identifiers are recorded here:

- Isolated PHP **8.4.26** / bundled libpq **16.15** successfully authenticated using
  the existing PDO helper, the expected database, `verify-full`, and active
  **TLSv1.3**. Diagnostics were read-only.
- The initial migration was subsequently explicitly approved and executed;
  the transactional runner reported **Initial schema committed**.
- Applied migration SHA-256:
  `b26a08aa73102e47a0e09bce8d42f4ecdfa2c2461514d95aae12800a8ac98dcb`.
- Post-initialization validation reported **55 tests, 0 failures**, exit code **0**.
  Live checks used read-only queries.

Database connectivity and initial schema initialization are completed milestones.
These are operator-provided verification results, not independently repeated
connections. Do not run the initial migration or disposable behavior suite against
this initialized database. Future schema changes require a new reviewed migration.
These results do not imply that the authentication migration has run or that any
web-service deployment or environment change has occurred.

## Schema review against the approved workflows

The previously proposed four tables are retained without column additions:

| Table | Screen/workflow mapping |
| --- | --- |
| `users` | Registration name maps to `display_name`; email and a future server-generated password hash fit the account screens. No passwords are accepted or saved in this milestone. |
| `exhibitions` | New Exhibition title, short introduction, subject, and cover map to `title`, `summary`, `category`, and `cover`. `subtitle` and `description` support the public overview. My Exhibitions uses title, category, summary, status, and a derived chapter count. |
| `chapters` | Studio title, era/subtitle, and opening narrative map to `title`, `era`, and `intro`. `eyebrow`, summary, and ending fields support the authored viewer. `position` determines navigation order; chapter totals are derived. |
| `content_blocks` | Studio text/artifact heading and body fit `content`; `figure` supports authored viewer imagery. `position` preserves order. |

**No genuinely necessary structural change was found.** No extra auth, media,
autosave, or versioning tables are introduced. The current account-recovery and
verification screens remain prototypes; implementing them later will require a
separate review of expiring, hashed token storage.

Implementation details that make the proposal explicit:

- Bigint identity primary keys; required strings reject blank values.
- Email uniqueness uses a unique `lower(email)` expression index, so `citext`
  and extension privileges are unnecessary. Surrounding spaces are rejected;
  future lookups must also use `lower(email)`.
- Globally unique slugs allow lowercase ASCII alphanumeric words separated by
  single hyphens. Slug generation/collision handling belongs in future application code.
- Drafts have no `published_at`; published records require one.
- User deletion is restricted while exhibitions exist. Exhibition deletion cascades
  through chapters to blocks.
- Positive positions are unique per parent, `DEFERRABLE INITIALLY IMMEDIATE`.
  A future reorder transaction can explicitly defer the relevant unique constraint.
  The unique indexes also support parent lookups; an additional owner index supports
  creator listings.
- JSONB is restricted to objects (`cover` may be SQL NULL; JSON `null` is rejected).
- All timestamps default to the transaction timestamp; a small trigger updates
  each directly modified row's `updated_at` using the statement timestamp.
  Child edits do not automatically touch the parent exhibition.
- All objects live in `public`. The manual runner owns the transaction; the SQL
  file must not be executed without one. The migration is intentionally not
  idempotent and contains no sample users, content, credentials, or destructive
  down migration. Re-running it fails rather than concealing schema drift.

### Studio content and authorization contract

JSONB is a storage shape, **not an HTML sanitizer**. Studio payloads:

```json
{"url":"https://images.example.test/cover.jpg","alt":"Descriptive plain text"}
{"heading":"A section title","body":"Plain narrative text"}
{"url":"https://images.example.test/figure.jpg","alt":"Description","caption":"Plain text","credit":"Source"}
```

These are cover, text/artifact, and figure examples respectively. Studio validates
an allowlist of fields for each block type, string lengths, and HTTP(S) image URLs
without credentials. Text is escaped and rendered literally, never executed as
HTML, PHP or SVG; script/data URLs and unsupported block fields are rejected.
Creator-provided URLs are not fetched server-side.
Approved prototype artwork remains in the frontend assets; it is not migrated
into executable database content.

Every Studio read/write enforces exhibition ownership using the
authenticated user, including chapter/block operations through their parent
exhibition. Foreign keys alone do not provide tenant authorization. Authentication,
authorization handlers, sessions, CSRF defense, validation, and persistence are
wired into creator screens. Studio loads the parent revision and ordered children
in one statement. A content save atomically checks owner/draft/revision, holds the
parent row lock, and defers the existing position constraints within a transaction.
Existing chapter IDs are checked against the exhibition, and block IDs against
their chapter. Reordering preserves IDs and unexposed chapter fields; a failed
write rolls back the entire tree and parent revision. No migration is required.

## Environment-driven PDO helper

[backend/database.php](../backend/database.php) defines
`Exhibit\Database\configuration()` and `Exhibit\Database\connect()`.
Including it does not connect. Only an explicit `connect()` call opens a connection.
Tests can pass an explicit environment array to `connect()` without changing the
application's process environment.
It uses native prepared statements, exceptions, associative fetches, nonpersistent
connections, a five-second connect timeout, UTC sessions, and the `public` search
path. Future SQL must use bound parameters for values and allowlisted identifiers.

| Variable | Purpose |
| --- | --- |
| `DATABASE_URL` | `postgresql://USER:URL_ENCODED_PASSWORD@HOST:5432/DATABASE` (also accepts `postgres://`). Required only when connecting. |
| `DB_SSLMODE` | Defaults to `verify-full`: encrypts and validates both CA trust and hostname. Only alternative is explicitly `disable` for `localhost`, `127.0.0.1`, or `::1`. |
| `DB_SSLROOTCERT` | Readable trusted PEM CA bundle path required with `verify-full`. The Docker image sets the Debian system bundle path. Use a trusted absolute Windows path for local PHP. |
| `APP_ENV` | Optional existing-production-compatible profile: `development` allows only `127.0.0.1:55432/exhibit_development`; `test` allows only loopback `exhibit_test_SUFFIX`; `production` retains existing connection/TLS rules. Unknown profiles are rejected. |

Use Render's exact database hostname, not an IP address or invented alias. If
hostname verification fails, stop and check the provider's certificate/hostname
instructions; do not weaken TLS to `require`, `prefer`, or disable verification.
The helper deliberately rejects URL query strings/fragments so connection policy
cannot be overridden inside a URL. If Render supplies URL parameters, review them
and translate only supported settings into the separate variables above. Percent-
encode username/password components, especially `@`, `:`, `/`, `?`, `#`, `%`, and
spaces. Database names are limited to letters, digits, underscore, hyphen, and dot.

### Local development

For persistent fully functional development, use the approved
[local launcher and runbook](local-development.md). It supplies isolated process
configuration, local DPAPI secrets, loopback PostgreSQL, and explicit start/stop
commands. Tests remain on disposable databases, not the persistent development
database. The instructions below describe the original manual CLI configuration.

Keep viewing the prototype without any database variables. To prepare an
authorized local CLI connection, first create a **dedicated local PostgreSQL
database and role**, outside the application startup path. Set environment
variables in that shell; do not embed secrets in commands or PHP files:

```powershell
$secret = Read-Host 'Database URL (input hidden)' -AsSecureString
$env:DATABASE_URL = [System.Net.NetworkCredential]::new('', $secret).Password
Remove-Variable secret
$env:DB_SSLMODE = 'verify-full'
$env:DB_SSLROOTCERT = 'C:\secure\trusted-ca-bundle.pem'
php database\migrate.php --plan
```

For a local PostgreSQL server with no TLS, explicitly use `DB_SSLMODE=disable`
with a literal loopback host. Do not use this for remote servers. After the
session, remove the credential from the shell with `Remove-Item Env:\DATABASE_URL`.
Shell variables are inherited only by new child processes; they do not configure
an already-running Ampps Apache service. Configure its environment through the
local service's secure process configuration before later integration, not by
placing secrets in repository `.htaccess` or PHP files.

The [.env.example](../.env.example) file is documentation only, not a dotenv loader.
PHP reads real process variables with `getenv()`. Do not put secrets in PHP,
Docker build arguments, image layers, committed files, tickets, chat, screenshots,
or command-line arguments. Real `.env` files and private certificate/key files
are excluded from Git and the Docker build context.

Connection exceptions intentionally omit driver messages and exception chains,
which can reveal connection details. Future request handlers must report a generic
unavailable message and log sanitized operational context; never dump PDO errors
or configuration arrays to a browser.

### Safe connection troubleshooting (no migration)

In the same PowerShell terminal that holds the process environment, use:

```powershell
$Php = 'C:\Program Files\Ampps\php\php.exe'
$PhpArgs = @('-n', '-d', 'extension_dir=C:\Program Files\Ampps\php\ext', '-d', 'extension=pdo_pgsql', '-d', 'zend.exception_ignore_args=1')
& $Php @PhpArgs 'database\diagnose.php' "--database=$DatabaseName"
if ($LASTEXITCODE -eq 0) {
    & $Php @PhpArgs 'database\migrate.php' '--check' "--database=$DatabaseName"
}
```

`$DatabaseName` must be the exact database name independently confirmed in Render,
not its display label. The CLI-only diagnostic requires `verify-full`, validates
the URL/database locally, resolves the external hostname, probes TCP without
credentials, and then uses the same PDO helper by **hostname** for verified TLS
and authentication. Database queries run in a read-only transaction. It prints
no URL, host, IP address, username, password, or driver message. Network probes
and connection attempts may create normal infrastructure logs.

The helper examines both `PDOException::getMessage()` and `errorInfo[2]` locally,
plus a whitelist of SQLSTATE values from `errorInfo[0]`, the exception code, or
the PDO wrapper. The earlier classifier only examined the wrapper message;
structured driver detail was ignored, then discarded when creating the safe
replacement exception. The replacement still deliberately has no raw exception
chain. Connection creation and session initialization now have separate failure
labels.

Only fixed category/reason tokens, whitelisted SQLSTATE values, and a boolean-like
`driver_detail=present/absent` indicator are returned. No driver text or numeric
native-error field is returned or logged. For example, `28P01` identifies password
rejection, while `28000` alone cannot distinguish authentication from access policy.
Likewise `08006` alone does not prove a TLS failure: it is reported as
`CONNECTION_SQLSTATE_ONLY` unless recognized detail supports a narrower category.
An unrecognized error remains explicitly unclassified rather than inventing a
cause. Offline tests cover wrapper-only and driver-detail-only errors, SQLSTATE
fallbacks, missing/malformed metadata, and sensitive-input redaction.

Categories are diagnostic hints rather than conclusive root-cause detection:

| Failure stage/category | Smallest safe investigation/correction |
| --- | --- |
| Configuration rejection | Re-enter the exact external URL using hidden input. Do not double-encode credentials or use the PSQL shell command as a URL. Query strings are intentionally rejected. |
| DNS | Confirm External URL selection, full hostname, and local DNS/VPN behavior. Never replace the hostname with a resolved IP. |
| NETWORK | Confirm the database is available, the current VPN/NAT egress IP matches Render's saved allowlist, and outbound TCP 5432 is allowed. An IPv4 allowlist entry does not establish that all possible connection routes work. Do not open access to the entire internet as a test. |
| TLS | Keep `verify-full`. Confirm the exact external hostname, system date/time, a current trusted Mozilla CA bundle, and that the CA file contains certificates rather than HTML. Use only a trusted CA source. Never trust a captured server leaf certificate as a workaround or disable verification. |
| AUTHENTICATION | Re-copy the current external URL through hidden input; check whether the credential was rotated. Preserve the supplied URL encoding exactly. Do not log credentials or rotate them merely to test a guess. |
| ACCESS_POLICY | Review saved Render access rules and provider-side connection policy; this is distinct from a local DNS failure. |
| DATABASE | Independently verify the exact database name and selected role's CONNECT permission. Stop on identity uncertainty. |
| CLIENT_CONFIGURATION / CLIENT_COMPATIBILITY | Inspect supported libpq options and client version without printing the DSN. Do not alter TLS verification. |
| SERVER_AVAILABILITY | Check database status or connection limits through the private provider dashboard. |
| AUTHENTICATION_OR_POLICY / CONNECTION | The available SQLSTATE is ambiguous. Do not assume certificate failure or change credentials without further safe evidence. |
| UNCLASSIFIED | Inspect provider-side diagnostics privately or obtain a separately sanitized error classification. Do not print the original exception or full configuration. |

A successful TCP probe is not proof of PostgreSQL authentication or certificate
trust. Certificate trust is established only by a successful `verify-full` PDO
connection. A connection success is not permission to initialize: finish the
read-only preflight, review the plan/checksum, and obtain explicit apply approval.

### Native-client comparison for an ambiguous PDO failure

Local inspection found PHP 8.2.27 loading **libpq 11.4**, while the installed
native `psql` is **18.6**. PHP's `openssl` extension being disabled in an isolated
`-n` invocation does not disable libpq's own TLS support.

The existing DSN is valid: PDO converts semicolon separators to spaces before
passing the string to libpq; the Windows CA filename is single-quoted and its
backslashes/apostrophes escaped according to libpq rules. A network-free check
with native `PQconninfoParse` confirmed the exact CA filename round-trips.
Username/password are decoded once from URL components and supplied separately
through PDO, not inserted into the DSN. The host is retained for hostname
verification, with no `hostaddr` substitution. File readability alone still does
not prove the bundle trusts the server's chain.

PDO appends its own `connect_timeout`; setting it only in the DSN was insufficient.
The helper now also sets `PDO::ATTR_TIMEOUT=5`.

PostgreSQL added TLS Server Name Indication (SNI) support in libpq 14; it can matter
for TLS-aware proxies. This is a **compatibility hypothesis**, not evidence that
Render rejected this connection specifically because of missing SNI. Do not add
`sslsni` to the old client's DSN: unsupported options would themselves fail.

Sources: [PDO 8.2 driver](https://github.com/php/php-src/blob/PHP-8.2/ext/pdo_pgsql/pgsql_driver.c),
[PostgreSQL 14 release notes](https://www.postgresql.org/docs/14/release-14.html),
[libpq TLS SNI](https://www.postgresql.org/docs/14/libpq-connect.html#LIBPQ-CONNECT-SSLSNI).

In the same terminal holding the approved target's environment and `$DatabaseName`,
run this comparison without changing any connection variables:

```powershell
& $Php @PhpArgs 'database\diagnose-native.php' "--database=$DatabaseName" '--psql=C:\Program Files\PostgreSQL\18\bin\psql.exe'
```

This uses the same validated External URL, decoded credentials, CA filename, and
`verify-full`, but passes separate `PG*` fields directly to native libpq rather
than a PDO DSN. It clears inherited `PG*` overrides in the child process, disables
psql startup files and password prompts, and sets a read-only session plus a
statement timeout. It executes one metadata query to confirm expected database,
TLS, and read-only state. Credentials are neither command arguments nor files;
as with the existing environment setup, they are accessible to processes/users
with sufficient local process-inspection privileges. The parent environment is
not changed. Native stdout/stderr are captured in memory and never printed raw.

- **Native success, PDO failure:** focus on the old PHP/libpq/TLS stack. Use a
  supported PHP distribution with a compatible modern libpq and dependencies,
  or validate the existing Docker image when available. Do not replace a single
  DLL in the shared Ampps installation or change TLS verification.
- **Native failure:** only the fixed sanitized reason is shown; use that to
  investigate certificate trust, authentication, policy, or connection availability.
  If still ambiguous, stop rather than exposing raw output or guessing.

`pg_isready` is not used as proof of secure connectivity: it cannot establish that
the intended credentials and verified TLS session work. No migration, schema
modification, role change, or application DML occurs in this comparison.

### Isolated Windows PDO runtime

After native `psql` succeeded with the same target, credentials and `verify-full`,
an official portable PHP distribution was installed without modifying shared
Ampps, global PATH, Apache, or the repository's frontend:

- PHP **8.4.26**, x64 **NTS**, VS17 (CLI/FastCGI build, not an Apache module).
- Bundled libpq **16.15**, confirmed by the runtime's actual PDO information;
  Ampps loads libpq 11.4.
- Location: `%LOCALAPPDATA%\Exhibit\runtimes\php-8.4.26-nts-x64`.
- Official archive: `php-8.4.26-nts-Win32-vs17-x64.zip`.
- Download source: [PHP Windows releases](https://downloads.php.net/~windows/releases/).
- Archive SHA-256, verified against the official release metadata before extraction:
  `da68394f9193b7f6b89d0c76861a4034ae10efee7fd55a7255d8118c2acf70d7`.
- The executable has no Authenticode signature; provenance verification here is
  HTTPS from PHP's official source plus its published archive checksum.
- The already-installed x64 Visual C++ runtime was sufficient; no machine-wide
  prerequisite or DLL replacement was performed.

NTS is appropriate for isolated CLI diagnostics and matches the PHP 8.4 major/minor
used in the existing Docker configuration. It is **not** a drop-in replacement for
Ampps's Apache module. A later web-runtime switch needs separate review; retain
the existing screens and web-server configuration in this milestone.

In the same local PowerShell terminal holding the existing secret environment,
keep `DATABASE_URL`, `DB_SSLMODE=verify-full`, and `DB_SSLROOTCERT` unchanged:

```powershell
$ExhibitPhpHome = Join-Path $env:LOCALAPPDATA 'Exhibit\runtimes\php-8.4.26-nts-x64'
$Php = Join-Path $ExhibitPhpHome 'php.exe'
$PhpArgs = @('-n', '-d', "extension_dir=$ExhibitPhpHome\ext", '-d', 'extension=pdo_pgsql', '-d', 'zend.exception_ignore_args=1')
& $Php @PhpArgs 'database\diagnose.php' "--database=$DatabaseName"
```

Absolute executable/extension paths, the runtime's bundled dependencies, and `-n`
prevent accidental use of shared Ampps PHP configuration. No dotenv file or new
credential copy is needed. The diagnostic only probes connectivity and executes
read-only identity/TLS metadata queries; it never installs a schema.

Offline verification with this runtime: **54 tests passed and all 26 PHP files
linted**. Subsequent operator verification confirmed that this PDO runtime connects
with `verify-full` and TLSv1.3; the approved migration and post-initialization
validation also succeeded, as recorded above. Retain this runtime for future
authorized CLI work. Do not rerun the initial migration.

For future portable-runtime updates, choose a supported PHP 8.4 x64 NTS build from
[official PHP downloads](https://www.php.net/downloads.php?os=windows), verify its
published SHA-256 before extraction, keep the complete dependency set together in
a fresh versioned folder, inspect the **loaded** libpq version, and rerun the
offline suite and read-only connection check before adopting it. Never copy a
single modern libpq DLL into Ampps.

## Connecting to the existing Render PostgreSQL database (later, after approval)

1. In the private Render dashboard, confirm the intended database, region,
   environment, PostgreSQL version, and backup/recovery options. Do not copy secrets
   into this repository.
2. Use Render's **full external hostname** for this helper's `verify-full`
   configuration. Render documents that internal TLS uses self-signed certificates
   and does not support `verify-ca`/`verify-full`; recommending the internal URL
   with the system CA bundle would be incompatible. This milestone deliberately
   does not add an unverified `require` mode or plaintext remote fallback.
   Restrict external database access to authorized administrator IPs and, when
   integration is approved, the web service's documented outbound IP ranges.
   Do not open access to all addresses. Confirm the certificate chain and
   hostname in an approved connection check before integration; no handshake
   with the existing Render database has been performed.
3. In the web service's Render **Environment** settings, add `DATABASE_URL` as a
   secret value. Set `DB_SSLMODE=verify-full`. The Docker image supplies
   `DB_SSLROOTCERT=/etc/ssl/certs/ca-certificates.crt` and installs `pdo_pgsql`.
   If the external endpoint requires a different CA, provision its trusted certificate as a
   private file and set that path; never acquire certificates from untrusted sources.
4. Separate the migration/schema-owner role from the eventual runtime role.
   The runtime role should have only CONNECT, schema USAGE, necessary table DML,
   and identity-sequence privileges, not DDL, role creation, or superuser rights.
   Set future default grants as the schema owner. Do not use database-owner
   credentials in a working public application.
5. Keep secrets isolated between development, staging, and production. Rotate a
   credential immediately if it has appeared in logs or public material.

Setting variables does not initialize tables. There is no automatic migration,
startup migration, or public database health endpoint.

Provider reference: [Render PostgreSQL connections](https://render.com/docs/postgresql-creating-connecting).
Private-network TLS policy can be revisited separately if required; do not weaken
certificate verification to work around a connection failure.

## Validation

From the repository root, using PHP 8.2+ with PDO:

```powershell
php tests\run.php
```

This runs **offline** configuration/rejection tests and static SQL contract checks.
It never reads the real connection variables, connects, or executes a migration.
The CA-path test checks configuration assembly, not a TLS handshake.
Static SQL checks are not proof that PostgreSQL has parsed or enforced the schema.

Only after a separately reviewed initialization, an authorized operator can run:

```powershell
php tests\run.php --live
```

This flag explicitly connects to the database named by the real environment.
It checks the connection, TLS (unless explicitly local/unsecured), column types,
nullability, identity/default metadata, keys, constraint presence, indexes, and
timestamp triggers in a read-only transaction that is rolled back. It does not
create tables or exercise INSERT/UPDATE/DELETE constraint behavior. It may create
normal connection/query entries in provider logs. **Do not run it now against Render.**

### PostgreSQL behavioral tests (disposable local database only)

[tests/postgres.php](../tests/postgres.php) exercises real PostgreSQL behavior,
not string matches or SQLite substitutes. It requires a **previously created,
empty loopback database** with a name starting `exhibit_test_`, and a role allowed
to create the schema. It refuses remote hosts, non-test names, and existing
public relations before executing migration DDL. It never uses `DATABASE_URL`.

For example, after creating `exhibit_test_foundation` locally using a trusted
PostgreSQL administrator tool:

```powershell
$secret = Read-Host 'Disposable local TEST database URL (input hidden)' -AsSecureString
$env:TEST_DATABASE_URL = [System.Net.NetworkCredential]::new('', $secret).Password
Remove-Variable secret
$env:TEST_DB_SSLMODE = 'disable'  # Explicitly local loopback only
php tests\postgres.php --run
Remove-Item Env:\TEST_DATABASE_URL
```

For TLS-enabled local tests use `TEST_DB_SSLMODE=verify-full` and
`TEST_DB_SSLROOTCERT` pointing to the trusted CA. The suite includes:

- Target-identity guard, initial schema installation, refusal to rerun on an
  initialized schema, atomic schema rollback, and installation after rollback.
- Four-table joins, identity/default fields, required values, case-insensitive
  email uniqueness, and globally unique lowercase URL-safe slugs.
- Orphan/null-parent rejection; positive parent-scoped chapter/block positions;
  successful deferred swaps and rejection of unresolved deferred duplicates.
- Valid publication/unpublication and rejection of invalid draft/published states.
- JSON object shape, required content, and text/figure/artifact type allowlist.
- Restricted owner deletion, chapter-to-block cascade, exhibition-to-chapter/block
  cascade, and preservation of owners and unrelated siblings.
- Update timestamp triggers, stable creation timestamps, UTC sessions, and the
  deliberate absence of automatic parent timestamp propagation.

All migration objects and fixtures are enclosed in a transaction and rolled back,
including after individual test failures. Each test uses a savepoint. **Never
point this suite at Render or an existing application database.** A failed setup
or rollback is reported as a failure, not a skip.

### PHP extension checks

Both local Ampps PHP distributions can load `pdo_pgsql`, but their shared
configuration files have duplicate `pdo_pgsql`, `pdo_sqlite`, and `pgsql` entries,
which emit startup warnings. They were not modified: these are shared machine
settings outside this repository. Only PDO and `pdo_pgsql` are required here.
After owner review, retain one extension directive per module in each relevant
`php.ini`, then restart the affected service and verify `php --ini`/`php -m`.

Until then, isolated CLI checks can load the driver exactly once:

```powershell
php -n -d 'extension_dir=C:\Program Files\Ampps\php\ext' -d extension=pdo_pgsql -m
php -n tests\run.php
php -n -d 'extension_dir=C:\Program Files\Ampps\php\ext' -d extension=pdo_pgsql tests\postgres.php --run
```

The final command still requires the separate safe test environment above.
Apache uses Ampps's `php82` distribution; verifying the CLI alone does not prove
the running web server has the extension. The Docker build explicitly checks
the driver and Apache syntax. Its PHP configuration suppresses browser error
details and exception arguments while preserving server-side error logging.

### Docker and HTTP protections

With Docker available, build the image and inspect the driver without credentials:

```powershell
docker build -t exhibit-db-foundation .
docker run --rm exhibit-db-foundation php -r "exit(in_array('pgsql', PDO::getAvailableDrivers(), true) ? 0 : 1);"
docker run --rm exhibit-db-foundation php tests/run.php
```

The Apache configuration denies HTTP access to backend, migrations, tests, docs,
Docker configuration, dotfiles, Git metadata, private SQL/certificate/configuration
files, and the README; directory listings are disabled. Per-directory `.htaccess` files
provide equivalent private-directory protection on Ampps **only if Apache permits
the authorization overrides**. Do not use PHP's built-in public server to expose
the repository root; it does not enforce `.htaccess`. For another server, configure
equivalent denies before deployment.

To exercise the Apache boundary, start the image without database credentials:

```powershell
docker run --rm --name exhibit-infrastructure-check -p 127.0.0.1:18764:10000 exhibit-db-foundation
```

In a second local terminal:

```powershell
.\tests\http.ps1 -BaseUrl http://127.0.0.1:18764
```

The HTTP script requires a loopback URL, verifies all existing public PHP screens
and assets return 200, and verifies private/encoded-dotfile URLs and directory
listings return 403/404. Stop only that named container after testing. For Ampps,
pass its loopback URL including the Exhibit subdirectory.

### Verification recorded for this milestone

- Offline configuration/schema/safety suite: 29 tests passed.
- All 23 PHP files passed syntax checks.
- An isolated loopback Apache with the actual Ampps PHP module passed all 48 HTTP
  checks using the repository `.htaccess` protections. The helper server was
  stopped after testing; no shared Apache configuration was changed.
- Both installed PHP distributions successfully load `pdo_pgsql` with a clean
  one-extension CLI invocation.
- Existing tracked frontend PHP and assets are unchanged.
- **Not verified:** Docker build/runtime (Docker unavailable); real PostgreSQL
  schema behavior/TLS (installed PostgreSQL 18.6 binaries lack
  `share\postgres.bki`, so even isolated `initdb` fails); Render connections,
  grants, backups, or deployment. The behavioral suite is prepared but has not
  been executed against a PostgreSQL server. Restore a complete local PostgreSQL
  installation or use a disposable PostgreSQL container to close this gap.

## Initialization procedure reference (completed; do not rerun)

The operator has completed initialization as recorded above. The steps below
describe the approval-gated procedure; they are not pending actions on Render.

1. Review the uncommitted diff. Run the offline suite and the nonconnecting plan:

   ```powershell
   php tests\run.php
   php database\migrate.php --plan
   ```

   The plan prints the migration file's SHA-256. Record that exact hash for review.
   There is one initial migration and no bookkeeping table. This procedure is
   repeatable, but **applying an already-initialized schema intentionally fails**.
2. Restore complete local PostgreSQL tooling or use an isolated container matching
   Render's confirmed major version. Run the disposable behavior suite above;
   build and HTTP-smoke-test the Docker image. Resolve any failures before Render.
3. Obtain explicit approval to contact Render. Confirm the exact target name,
   migration role, certificate trust, external-access allowlist, and a restorable
   backup (or confirmed empty database). Use the hidden-input environment setup
   above with Render's **external** URL, `verify-full`, and a trusted CA bundle.
4. Only after connection approval, run the read-only target preflight:

   ```powershell
   php database\migrate.php --check --database=CONFIRMED_DATABASE_NAME
   ```

   This opens a connection but executes no migration. It reports the database
   name, PostgreSQL/TLS versions, and configured TLS mode without credentials.
   It confirms CONNECT, public USAGE/CREATE, plpgsql USAGE, and a writable primary.
   It requires the actual database name to match and all non-system user schemas
   to have no relations, routines, or types, including objects installed by
   extensions. Unexpected objects cause a stop for review. A nonempty database
   needs investigation and a separately reviewed incremental plan, not drops.
5. Obtain explicit approval to initialize. Recheck the reviewed hash and apply:

   ```powershell
   php database\migrate.php --plan
   php database\migrate.php --apply --database=CONFIRMED_DATABASE_NAME
   ```

   The CLI-only runner starts an explicit transaction, applies local lock and
   statement timeouts, takes a transaction-scoped advisory lock, rechecks target
   identity and emptiness, executes this one migration, and commits. Failures
   roll back; an uncertain commit is reported explicitly and requires inspection.
   There is no silent skip, schema drop, startup hook, or automatic retry.
   **Do not execute the bare SQL file**, which relies on the runner's transaction.
6. Run `php tests\run.php --live` for read-only installed-schema validation.
   Confirm role grants separately: use a restricted runtime role, not the migration
   owner. Record timestamp, target, role, reviewed SHA-256, and validation results
   in the deployment change record without credentials. Remove the local secret
   environment variables. No application deployment is required to create the
   schema, and deployment still requires separate approval.

Keep all approved prototype screens disconnected until authentication,
ownership enforcement, CSRF protection, and input/output validation are implemented.
