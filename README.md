# Exhibit MVP

The Exhibit digital exhibition experience, with PostgreSQL-backed creator
authentication and the approved sample-content creator workspace.

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

## Creator workspace design preview

Open `my-exhibitions.php` after signing in and verifying your email. It contains
sample exhibition cards only, not account-owned saved records. Authentication is
functional; exhibition persistence and publishing are not. The screen remains
`noindex`.

## Exhibition Studio interactive preview

Open `studio.php` after signing in and verifying your email, or use **Open Studio
preview** from `my-exhibitions.php`. Chapter switching, title/era/narrative edits,
adding/removing text or artifact blocks, and the live preview work in browser
memory only. Refreshing discards all changes. Save/publish remain disabled.


## Create Exhibition screen (prototype)

Open `new-exhibition.php` from the My Exhibitions dashboard. This is a design-only screen with editable title, summary, subject and optional remote cover URL, with a live preview. The Continue button explains that creating a persistent exhibition is not yet implemented. Nothing is saved to a database or published.

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
sign-in screens. The creator content remains sample-only; no exhibition saving
or publishing is active.


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
