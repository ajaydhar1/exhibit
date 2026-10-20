# Exhibit MVP

The Exhibit digital exhibition experience, with PostgreSQL-backed creator
authentication, creator-owned persistent drafts, publishing, and public exhibition viewing.

## Local development (Ampps)

For the fully functional persistent local app, use the guarded AMPPS PHP launcher:

```powershell
powershell -NoProfile -File .\database\local.ps1 start
```

Open **http://127.0.0.1:8085/**. From another terminal:

```powershell
powershell -NoProfile -File .\database\local.ps1 status
powershell -NoProfile -File .\database\local.ps1 inbox
powershell -NoProfile -File .\database\local.ps1 stop
```

The approved persistent development database is `exhibit_development` on
`127.0.0.1:55432`, separate from production and disposable test databases.
Local credentials/authentication keys are DPAPI-encrypted outside the repository.
Use **inbox** privately for local verification/reset links; no real email is sent.
Do not rerun initialization on the existing development data. See
[Local development setup and troubleshooting](docs/local-development.md) for
one-time approved setup, start/stop behavior, isolation, and private logs.

The VS Code PHP Server on port 3000 does not load this configuration, and the
restricted prior acceptance server on port 8091 is not the development app.

All navigation uses regular `.php` links. No URL rewriting is required. The authored
sample remains database-independent. Published creator exhibitions load from PostgreSQL
and are accessible without signing in.
Account operations require the environment and reviewed migration described in
[Authentication setup](docs/authentication.md).

## Pages

- `index.php` — homepage
- `exhibition.php` — authored sample overview; `?id=ID` opens a published exhibition
- `view.php` — first sample chapter; `?id=ID&chapter=CHAPTER_ID` opens a published chapter
- `chapter-2.php` through `chapter-5.php` — remaining sample chapters
- `publish.php?id=ID&revision=REVISION` — private saved-version confirmation; publishing requires POST

## Render (Docker)

1. Put the **contents** of this folder in a dedicated GitHub repository (the Dockerfile should be at the repository root).
2. Create a Render **Web Service**, choose **Docker** runtime and connect that repository.
3. Keep the Dockerfile path as `./Dockerfile`; no build or start commands are needed.
4. Test homepage, overview and all five chapters once deployed.

The image listens on port 10000, with `PORT=10000` as its documented deployment setting. If the hosting port requirement changes, adjust the Apache config accordingly. Creator workflows and database-backed public viewing require the configured PostgreSQL environment; application startup never runs migrations.

The content is prototype material; fact-check and confirm image rights before any public launch.

## Persistent exhibition draft workflow

Open `my-exhibitions.php` after signing in and verifying your email. It lists only
your saved exhibitions, newest-updated first, or an empty state with a creation
link. Cards show persisted metadata, publication status, and a derived chapter count.
The creator screens remain private, `no-store`, and `noindex`.

## Exhibition Studio editing and saving (Milestone 2)

Use **Open Studio** on a dashboard card to open `studio.php?id=ID`. Studio loads
that creator-owned exhibition's saved metadata and ordered chapters/content in
one database snapshot. The existing three-column Studio supports title,
introduction, subject, cover URL, subtitle (100 characters), and description
(10,000 characters). The established title, introduction, topic choices and
cover validation remain unchanged.

Select **Exhibition details** or a chapter in the organizer. Add, edit, remove,
and move chapters up/down; edit chapter title (110 characters), era/subtitle
(100), and opening narrative (2,000). Text and artifact blocks use the original
heading (130) and body (2,000) model. The existing schema's figure type uses an
HTTP(S) image URL (2,048 bytes), alt text (320), caption (2,000), and credit (320).
All narrative fields are plain text, never interpreted as HTML. Image URLs are
displayed by the browser, not fetched by the backend.

**Save Draft** persists metadata and the entire ordered content tree atomically.
Existing chapter/block IDs remain stable on edits/reordering; deletions cascade
only through their own chapter. Unexposed chapter fields are preserved. Child
IDs must already belong to this exhibition and chapter; blocks cannot be moved
between chapters by submitting another parent's ID. New content has no ID.
Content uses a JSON list (maximum 1 MiB) instead of hundreds of nested form fields.
Unknown block fields/types are explicitly rejected rather than silently discarded.
No ownership or publication fields can be changed through Studio.
Refreshing, leaving, or signing in again restores the latest saved work.
Bare `studio.php` redirects verified creators to the dashboard; malformed,
nonexistent, and foreign-owned IDs return the same 404. Saving requires verified
creator authentication, ownership, session CSRF, and server-side validation.
Metadata saving works without JavaScript using POST/303/GET and preserves existing
content. Chapter/block editing and live previews require JavaScript. Validation and service errors
retain entered values, with field errors and an explicit status message.
The interface distinguishes unsaved changes, saving, saved, and save errors;
JavaScript also warns before leaving unsaved edits.
With JavaScript, saving happens in place: the selected chapter or Exhibition
Details, editor inputs, focus and scroll position are retained. A successful
save refreshes the canonical values, assigned chapter/block IDs and revision
without navigation. Fields are briefly locked during the request; failures
retain edits and show feedback, and stale saves still require reopening Studio.

An atomic owner/draft/version predicate uses the existing `updated_at` timestamp.
Content saves hold that parent lock through child validation and all writes;
deferred existing position constraints allow swaps without temporary positions.
Any failure rolls back metadata, content, ordering, and revision together.
A stale or missing revision returns 409 rather than overwriting a newer save.
Copy unsaved edits and reopen Studio to review the latest values; there is no
force-overwrite control. Published exhibitions cannot be saved through this action.
No schema changes or migrations are required. The live preview remains private
and local to Studio until the saved exhibition is explicitly published.

## Publishing and public viewing (Milestone 3)

Save your draft, then choose **Review & publish** in Studio. JavaScript disables
publishing while changes are unsaved, saving is active, or saving has failed.
A successful in-place save refreshes both the editor and publication revision.
The confirmation page explicitly describes what will become public and warns that
publication is read-only and cannot be undone in this MVP. It also works without
JavaScript: only the last saved version is published, never unsaved form values.

Publishing requires a valid saved title and at least one chapter with meaningful
opening narrative, text/artifact heading or body, or a valid figure URL. Whitespace
and invisible formatting characters alone do not count. Optional metadata, cover,
captions and credits are not required; remote image availability is not checked.
The service validates saved content before publication. Submitted replacement
metadata/content is ignored by the publish action.

The publish POST requires a verified creator, ownership, session CSRF and the
reviewed saved revision. It locks the owner-scoped exhibition row, checks the
revision, then sets the existing status and publication timestamp atomically.
Concurrent Studio saves use the same parent lock. Stale/missing revisions return
409; insufficient content returns 422; invalid CSRF returns 403. Failures roll
back and return a safe error, never a success fallback. A duplicate authenticated
publish is idempotent and preserves the original publication timestamp.

Success redirects to `exhibition.php?id=ID`. **Begin exhibition** and chapter
links open `view.php?id=ID&chapter=CHAPTER_ID`, with saved chapter/block ordering,
dynamic progress, and previous/next navigation. The existing overview/viewer
typography, layout and responsive styles are reused. Text remains escaped plain
text with line breaks; figures include alt text, captions and credits. Images
are fetched by the visitor's browser with no-referrer, never by the backend.
Only show images you have permission to publish.

Public queries filter publication state in SQL and return no creator/account
data. Drafts, nonexistent exhibitions, and foreign/invalid chapter IDs return
the same 404, including to the owner through a public URL. Public viewing does
not start a session and uses no-store responses; database failures return 503.
Malformed dynamic URLs never fall back to the sample.

Published exhibitions are read-only in Studio, with public links there and on
the dashboard. Unpublishing, edits/revisions after publishing, slug editing,
uploads, and automatic Explore discovery are outside this milestone. Existing
no-parameter sample routes and their five chapters remain functional without
PostgreSQL. No new migration is needed.

### Publishing acceptance checklist

Use a disposable test environment or an explicitly approved exhibition: publishing
cannot be reversed through the MVP interface.

1. Create an exhibition, add two chapters and text/artifact/figure blocks, reorder
   them, and save. Confirm dirty/saving/saved feedback and that unsaved edits
   disable **Review & publish**.
2. Refresh, reopen from the dashboard, and sign out/in. Confirm exact saved
   metadata, chapter/block values and order.
3. In a signed-out browser, try the draft overview and a known chapter URL.
   Both must return 404 without leaking content.
4. Review and confirm publication. Check the public overview, every chapter,
   text line breaks, images/alt/captions/credits, progress and navigation.
5. Reload the public links signed out. Check desktop and mobile widths, long
   titles/text and missing/broken optional images.
6. Reopen Studio: metadata and chapter/block mutation controls and Save Draft
   must be disabled. The dashboard must show Published and a public-view link.
7. Confirm the existing sample's overview and all five chapters still work.
   Automated disposable tests cover CSRF/ownership failures, empty content,
   stale/duplicate requests, injected database failure and published-save rejection.

### Milestone 3 validation (2026-10-20)

The publishing implementation passed 19 exhibition unit groups, 21 Node UI tests,
57 offline database/architecture checks, 10 authentication unit groups, 10 offline
mail groups, and 19 database-independent HTTP scenarios. PHP lint and JavaScript
syntax checks also passed. The offline HTTP suite confirms the sample remains
available without PostgreSQL and database-backed public URLs report a safe 503
when the database is unavailable.

A fresh session-local PostgreSQL 18.6 cluster, bound only to loopback on a
separate port, hosted two disposable `exhibit_test_*` databases. Only the existing
checked-in migrations initialized them. The database-backed suite passed eight
authentication service groups, Studio/draft/publication persistence groups, the
complete creation/edit/save/reopen/new-sign-in/publish/anonymous-view HTTP
workflow, and 25 authentication HTTP scenarios. It verified optional-metadata-free
publication, exact saved ordering, escaped content and figure metadata, draft and
cross-exhibition privacy, CSRF/ownership/revision checks, idempotence, injected
publish failure rollback, and published-save rejection.

A separate disposable browser creator completed creation, Studio text/artifact/
figure editing, saving, refresh/reopen, confirmation, publication, public overview,
chapter navigation and read-only Studio verification. Browser checks at 1440px
and 390px CSS widths found no horizontal overflow for ordinary and maximum-length
unbroken content, with optional cover absent, valid and broken image URLs. Loaded
figures, captions/credits and literal HTML-like text were checked. No existing
development draft or production record was used; no deployment or push occurred.
Both disposable databases were dropped and their temporary servers stopped after
verification.

Manual acceptance was reported successful on 2026-10-20: the creator made
*A Little Museum of Curiosity*, added a chapter and text content, saved the draft,
published it, and viewed the public chapter. The creator confirmed that the
content rendered correctly. This acceptance was user-performed; no additional
live records were changed during commit preparation.


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
sign-in screens. Creator drafts are saved privately. Published creator exhibitions
are available through direct public links; Explore still lists only the authored
sample and the clearly labeled concept previews, not creator publications.


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
using scripted PDO doubles. They also cover content validation, stable-ID ordering,
one-snapshot loading, transactional writes, rollback and child ownership.
Use the isolated PHP runtime/extension options in
[Authentication setup](docs/authentication.md) for session/HTTP suites.
The optional Node built-in test runner checks preview, native form submission,
validation feedback, safe live metadata previews, unsaved/saving/error states,
navigation warnings, duplicate-submit prevention, and chapter/block editing,
ordering, deletion, serialization and previews without packages or a database.

The extended `tests\auth-postgres.php --run` suite covers actual draft persistence,
two-creator isolation, creation and full Studio-saving/dashboard HTTP flows,
CSRF, validation, database-error input retention, stale/published-save rejection,
refresh/reopen, a new sign-in session, child-ID isolation, stable-ID chapter/block
reordering with exact saved-tree comparisons, preservation of unexposed fields,
deletion/cascades without affecting same-owner or foreign-owner exhibitions, and injected
mid-content-save failure rollback. It also exercises publication readiness for
narrative/text/artifact/figure-only exhibitions without optional metadata,
atomic/idempotent publication, draft privacy on public routes, stale/foreign/CSRF
rejection, injected publish rollback, and the complete saved-session-to-publish-to-
anonymous-view HTTP workflow with exact ordering and read-only Studio checks.
It requires an **empty disposable
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

### Milestone 2 persistence verification (2026-10-20)

The Studio content-editing implementation passed 15 exhibition unit groups, 11
Node UI tests, 54 offline database-contract checks, 10 authentication unit groups,
and 18 database-independent HTTP scenarios. A database-free browser fixture using
the actual Studio template/assets verified content editing, ordering, removal
confirmation, safe previews, validation, save-state feedback and no horizontal
overflow at 1440px and 390px CSS viewport widths. Fixture submissions were blocked;
no development or production records were changed.

The previously unverified database-backed tests now pass against a fresh
PostgreSQL 18.6 cluster bound only to `127.0.0.1`, using PHP 8.2.27 with explicit
OpenSSL/PDO PostgreSQL extensions and no shared PHP configuration. Three newly
created `exhibit_test_m2_*` databases isolated the suites. Only the existing
checked-in migrations initialized these empty databases; no new migration,
development/production database change, deployment or publishing work occurred.

| Suite | Result |
| --- | --- |
| `php tests\postgres.php --run` | 16 PostgreSQL foundation checks passed |
| `php tests\auth-migrations.php --run` | 16 authentication migration/preflight scenarios passed |
| `php tests\auth-postgres.php --run` | Eight authentication service groups, draft and Studio content persistence groups, authenticated Studio HTTP workflow, and 23 authentication HTTP scenarios passed |
| `php tests\exhibitions.php` | 15 exhibition unit groups passed |
| `node --test tests\exhibition-ui.js` | 11 UI tests passed |
| `php tests\run.php` | 54 offline contract checks passed |
| `php tests\auth.php` | 10 authentication unit groups passed |
| `php tests\auth-http.php` | 18 database-independent HTTP scenarios passed |
| `php tests\mail.php` | 10 offline mail groups passed; no real delivery |

The real PostgreSQL runs verified creation/loading, metadata edits, chapter/block
add/edit/remove/reorder, stable IDs, exact saved-tree restoration after reopening
and signing in again, malformed/foreign ID rejection, CSRF and validation failures,
stale-save conflicts, and full rollback after an injected mid-content-save
database failure. Deleting individual content or all chapters left both
same-owner and foreign-owner exhibitions unchanged, including their revisions.
No application bugs were found; tests were strengthened to assert these exact
cross-session and deletion-isolation outcomes.

All suites passed with zero failures. Independent queries confirmed zero
remaining user relations/routines in all three test databases. The disposable
databases were dropped, the server shut down cleanly, its listener was confirmed
absent, and its session-local data/runtime/download directory was removed.
Milestone 2 is ready for acceptance sign-off. Live deployment and publishing
remain outside this verification.

### Prior milestone validation

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
