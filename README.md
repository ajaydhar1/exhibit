# Exhibit — five-chapter preview

A static-content PHP prototype of the Exhibit digital exhibition experience.

## Local development (Ampps)

Copy `exhibit-homepage` into `C:\Program Files\Ampps\www\` and open:

`http://localhost/exhibit-homepage/`

All navigation uses regular `.php` links. No `.htaccess` rewriting or database is required.

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

Open `my-exhibitions.php` locally to view the **My Exhibitions** screen. It contains sample exhibition cards only. The dashboard is deliberately not linked from the public navigation; authentication, editing, draft management, and publishing are not implemented. The preview has a `noindex` directive. Do not treat the sample draft as saved data or expose the screen as a working creator account.

## Exhibition Studio interactive preview

Open `studio.php` locally, or use **Open Studio preview** from `my-exhibitions.php`. Chapter switching, title/era/narrative edits, adding/removing text or artifact blocks, and the live preview work in browser memory only. Refreshing the page discards all changes. Save/publish functionality is intentionally disabled; no database or authentication exists. Keep this preview out of public navigation until the creator workflow is implemented.


## Create Exhibition screen (prototype)

Open `new-exhibition.php` from the My Exhibitions dashboard. This is a design-only screen with editable title, summary, subject and optional remote cover URL, with a live preview. The Continue button explains that creating a persistent exhibition is not yet implemented. Nothing is saved to a database or published.

## Creator authentication screen previews

- `sign-in.php` — Sign In design preview
- `register.php` — Register design preview

These are **visual-only prototypes**. They deliberately have no authentication endpoint, no saved credentials, and no functional account creation. Forms prevent submission. Do not ask users to enter real passwords into prototypes. Do not deploy these previews publicly as working authentication; implement secure server-side authentication, rate limiting, CSRF defense, and session handling first.


## Explore + navigation prototype

Open `explore.php` to visit the new discovery screen. It links to the one fully authored exhibition, **The Birth of the Internet**. Three additional cards are explicitly labeled *concept previews* and do not imply that those exhibitions are published.

Top navigation now connects the homepage, Explore, creator dashboard, Studio, new-exhibition preview, and sign-in screens, with contextual links within the viewer. The creator interfaces remain sample-only demonstrations: no account creation, saving, or publishing is active.


## Additional account-flow design previews

The following screens extend the established Sign In / Register style without implementing account operations:

- `forgot-password.php` — reset-link request preview
- `reset-password.php` — new-password form preview (no tokens processed)
- `verify-email.php` — verification instruction and resend preview; add `?preview=verified` to see the success-state mockup

All forms prevent submission. No email is delivered, and no account credentials are transmitted or stored. Never enter a real password into these prototypes.

## PostgreSQL foundation (prepared, not initialized)

The approved prototype screens remain unchanged and disconnected from the database.
The repository now includes the reviewed four-table SQL migration, an opt-in
environment-driven PDO helper, PostgreSQL support in the Docker image, and offline
tests. A manual CLI migration runner provides a nonconnecting plan, read-only
preflight, and explicit atomic apply; migrations never run on application startup.
Opt-in PostgreSQL behavior tests are restricted to an empty local test database.
Authentication, saving, and publishing are still not implemented.

See [Database setup and safe Render initialization](docs/database.md) for schema
mapping, environment variables, TLS, private-file protections, test commands, and
the review/backup steps required before applying the migration. Nothing runs on
startup, and no database connection or migration is needed to view the prototype.
