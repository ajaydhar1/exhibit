# Exhibit MVP

The Exhibit digital exhibition experience, with PostgreSQL-backed creator
authentication and creator-owned persistent exhibition drafts.

## Local development (Ampps)

Copy `exhibit-homepage` into `C:\Program Files\Ampps\www\` and open:

`http://localhost/exhibit-homepage/`

All navigation uses regular `.php` links. No URL rewriting is required. Public
exhibition pages remain database-independent and accessible without signing in.
Account operations require the environment and reviewed migration described in
[Authentication setup](docs/authentication.md).

## Pages

- `index.php` — homepage
- `exhibition.php` — exhibition overview
- `view.php` — first chapter
- `chapter-2.php` through `chapter-5.php` — remaining chapters

## Render (Docker)

1. Put the **contents** of this folder in a dedicated GitHub repository (the Dockerfile should be at the repository root).
2. Create a Render **Web Service**, choose **Docker** runtime and connect that repository.
3. Keep the Dockerfile path as `./Dockerfile`; no build or start commands are needed.
4. Test homepage, overview and all five chapters once deployed.

The image listens on port 10000, with `PORT=10000` as its documented deployment setting. If the hosting port requirement changes, adjust the Apache config accordingly. This is a public demo, not yet an editable multi-user MVP.

The content is prototype material; fact-check and confirm image rights before any public launch.

## Persistent exhibition draft workflow

Open `my-exhibitions.php` after signing in and verifying your email. It lists only
your saved exhibitions, newest-updated first, or an empty state with a creation
link. Cards show persisted metadata, draft status, and a derived chapter count.
The creator screens remain private, `no-store`, and `noindex`.

## Exhibition Studio metadata editing

Use **Open Studio** on a dashboard card to open `studio.php?id=ID`. Studio loads
that creator-owned exhibition's saved title, introduction, subject, cover, and
status. Edit the title, introduction, subject, and cover URL using the existing
three-column Studio. The live preview changes immediately, without a database
write. **Save Draft** persists only these four fields to the same record; it
never changes ownership, publication state, chapters, or content blocks.
Refreshing, leaving, or signing in again does not discard saved details.
Bare `studio.php` redirects verified creators to the dashboard; malformed,
nonexistent, and foreign-owned IDs return the same 404. Saving requires verified
creator authentication, ownership, session CSRF, and server-side validation.
It works without JavaScript using POST/303/GET. Validation and service errors
retain entered values, with field errors and an explicit status message.
The interface distinguishes unsaved changes, saving, saved, and save errors;
JavaScript also warns before leaving unsaved edits.

An atomic owner/draft/version predicate uses the existing `updated_at` timestamp.
A stale or missing revision returns 409 rather than overwriting a newer save.
Copy unsaved edits and reopen Studio to review the latest values; there is no
force-overwrite control. Published exhibitions cannot be saved through this action.
No schema changes or migrations are required. Chapter/block editing and publishing
remain unavailable.


## Create Exhibition

Open `new-exhibition.php` from the dashboard. The existing form and live preview
collect a required title (100 characters), optional introduction (320 characters),
subject, and HTTP(S) cover URL (2048 bytes, no credentials). **Continue to Studio**
submits a CSRF-protected POST, saves one private draft, and redirects to its Studio
page. Creation also works without JavaScript. Invalid fields retain entered text
and report errors; database failures return an explicit unavailable response.
No placeholder chapters or content blocks are created. No migration is needed.

## Creator authentication

- `sign-in.php` — secure sign-in and account sign-out form
- `register.php` — validated account registration

The established interface is retained. Forms now submit to PHP with CSRF
protection, prepared statements, password hashing, persistent rate limiting, and
expiring sessions. Registered accounts are creators; verified email is required
to open the dashboard, new-exhibition screen, and Studio. Logout is POST-only.


## Explore + navigation prototype

Open `explore.php` to visit the new discovery screen. It links to the one fully authored exhibition, **The Birth of the Internet**. Three additional cards are explicitly labeled *concept previews* and do not imply that those exhibitions are published.

Top navigation connects the homepage, Explore, guarded creator workspace, and
sign-in screens. Creator drafts are saved privately; public pages still display
only the authored sample exhibition. Publishing and public database-backed viewing
are not implemented.


## Verification and account recovery

These screens use the same established styling:

- `forgot-password.php` — account-enumeration-safe reset requests
- `reset-password.php` — single-use, 30-minute password resets
- `verify-email.php` — single-use, 24-hour verification and rate-limited resend

Email is queued encrypted in PostgreSQL and delivered by the explicit CLI worker.
Resend HTTPS support is prepared for `exhibit.world`; existing SMTP remains
available. No credentials or live delivery are configured in source. See
[Resend setup and controlled tests](docs/resend.md) and
[Authentication setup](docs/authentication.md) for environment variables, worker
operation, migration approval boundaries, and tests.
The [activation runbook and acceptance plan](docs/authentication-activation.md)
covers private read-only Render preflight, checksum-bound migration approval,
schema verification, email readiness, and complete account-flow acceptance tests.

## PostgreSQL migrations

The initial schema and the explicitly approved authentication migration have been
applied, with independent read-only schema verification reported by the operator.
**Do not rerun either migration.** Migrations never run automatically. PostgreSQL
behavior tests are restricted to an empty local test database, never Render.
Web/email activation and deployment remain separate approval gates.

See [Database setup and safe Render initialization](docs/database.md) for schema
mapping, environment variables, TLS, private-file protections, test commands, and
the review/backup steps required before applying any migration. Nothing runs on
startup. Public exhibition pages do not connect to PostgreSQL.

## Draft workflow tests

```powershell
php tests\exhibitions.php
php tests\run.php
php tests\auth.php
php tests\auth-http.php
node --test tests\exhibition-ui.js
```

These suites do not write to PostgreSQL. Exhibition unit tests validate fields,
URL/ID boundaries, owner-scoped SQL bindings, slug retries, and explicit failures
using scripted PDO doubles. Use the isolated PHP runtime/extension options in
[Authentication setup](docs/authentication.md) for session/HTTP suites.
The optional Node built-in test runner checks preview, native form submission,
validation feedback, safe live metadata previews, unsaved/saving/error states,
navigation warnings, and duplicate-submit prevention without packages or a database.

The extended `tests\auth-postgres.php --run` suite covers actual draft persistence,
two-creator isolation, creation and metadata-saving/dashboard/Studio HTTP flows,
CSRF, validation, database-error input retention, stale/published-save rejection,
refresh/reopen, and a new sign-in session. It requires an **empty disposable
loopback `exhibit_test_SUFFIX` database**, installs the test schema using both
existing migrations, and removes it afterward. It must not run while migration or
database-writing approval is withheld, or against development/production.

### Controlled metadata browser verification (approval required for writes)

Use the existing Exhibit Test Creator and draft **ID 1**. Do not create an account
or another exhibition, send email, or change environment settings.

1. Sign in only after approval: sign-in can update account/rate-limit records.
   Open `studio.php?id=1` and record the current four metadata values privately.
2. Before approving a save, edit the fields in the browser only. Confirm live text,
   subject and cover preview, **Unsaved changes**, preview hide/show, and unchanged
   desktop/mobile layout. Do not click **Save Draft** yet.
3. After separate approval, save one agreed metadata change. Confirm **Saving**
   then **Draft saved successfully**; refresh, reopen from My Exhibitions, and
   logout/sign in to check all four persisted values and draft status.
4. After approval for a second save, use two tabs with the same starting revision:
   save in one, then attempt a stale save in the other. Expect a conflict with
   retained edits, not an overwrite. Restore original values only with approval.

All destructive, invalid-input, cross-account, and database-failure scenarios
belong in the disposable automated environment, not the existing live draft.
### Milestone validation

The approved isolated PostgreSQL 18.6 run passed: 16 foundation checks, eight
authentication service groups, exhibition persistence/ownership checks, the
authenticated draft HTTP workflow, and 23 database-backed authentication HTTP
scenarios. The temporary loopback cluster and test schema were removed afterward;
no Render configuration was used by these suites.

An independently approved, single-private-draft browser test also passed:
creation, saved metadata, dashboard listing with zero chapters, refresh, and
reopening in read-only Studio. Desktop (1440px) and mobile (390px) checks preserved
the existing design without horizontal page overflow. No real account details,
credentials, or session artifacts are recorded here.

The metadata-editing milestone passed 54 offline baseline tests, nine exhibition
unit groups, six Node UI tests, ten auth unit/session groups, and 18
database-independent HTTP scenarios. A fresh disposable loopback PostgreSQL 18.6
cluster passed eight auth service groups, exhibition creation/update checks,
metadata HTTP checks (including simulated 503 and stale revisions), and 23
database-backed auth HTTP scenarios. Its schema and cluster were removed afterward.
A database-free browser fixture rendered the actual Studio template and assets:
live text/subject/cover previews, safe text rendering, dirty status and preview
toggling worked, with three desktop columns at 1440px and no horizontal overflow
at 1440px or 390px. No fixture form was submitted.

The separately approved controlled metadata browser test also passed. Read-only
preflight confirmed the configured database, verified TLS, verified creator and
private-draft ownership. All four live previews updated without saving; a separate
GET still returned the original saved metadata. Following explicit approval, one
guarded Save Draft attempt succeeded. Refresh, My Exhibitions and reopening Studio
retained all approved values, draft status and zero chapters. Desktop (1440px)
and mobile (390px) checks preserved the existing design without horizontal
overflow; the cover loaded and preview hide/show worked. Further saves were
blocked afterward. No additional account/exhibition creation, emails, migrations,
publishing or deployment occurred. Logout/login persistence was covered by the
disposable automated suite, not repeated during this single-save browser test.
No private account details, credentials, saved test values or session artifacts
are recorded here.
