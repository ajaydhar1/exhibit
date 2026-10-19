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
