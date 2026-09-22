# studioIndex
Browse the studio archive by date: every take recorded on a day, all five camera angles, plus a per-date completeness check.

## What it does
| file | does | used by |
|---|---|---|
| `index.html` | cards per `matched_transcriptions` row: gloss/sentence + 1, 3 or 5 players; thumbnails from the `.jpg` beside each `.mp4` (`post/`, fallback `raw/`) | browser |
| `api.php?page=N[&date=]` | paged list (100/page) + distinct dates from `m_file` (`M20260331_…` → `20260331`) | `index.html` |
| `api.php?date=YYYYMMDD` | full unpaged list for a date; no `date` → HTTP 400 + `available_dates` | `signlab_videoBackgroundFix` (do not change this shape) |
| `getDateStatus.php?date=` | `CameraRecords` vs `matched_transcriptions`: validated, missing gloss ids, per-camera counts, post-processed | `index.html` |

Read-only: no write path.

## Where it runs
- Production VPS: `/web/studioIndex`, https://signcollect.nl/studioIndex/
- Demo: dev2 `/web/studioIndex`, dev-1 `/srv/signcollect/web/studioIndex`.
- Media URLs are hardcoded to `https://signcollect.nl/gebarenoverleg_media/studioFilesMini/{post,raw}/`; demo deploys rewrite them (`rewrite-urls.sh`), so a demo checkout is dirty. Do not commit from a docroot.

## Status
Production.

## How to run / deploy
No build step. Deployed by `signlab_signcollect-stack` (`repos.tsv`: `studioIndex	signlab_studioIndex	main`; fetch → reset --hard → clean → rewrite-urls):
https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack

## Configuration
- `../mysql_config.php`, one level above this directory (e.g. `/web/mysql_config.php`), defining `$servername`, `$username`, `$password`, `$database`. A copy inside this directory does nothing. On deployed hosts it is the stack's `web_extra/mysql_config.php` shim reading `/web/.env`.

## Dependencies
- MySQL `admin_gebarenoverleg`: `matched_transcriptions`, `CameraRecords`, `form_data`, `nmm_data`, `sentences` (read-only).
- Media over HTTP from `gebarenoverleg_media/studioFilesMini/{post,raw}/`.
- `/userProtect.js` guards `index.html`; the PHP endpoints have no auth.
- `signlab_videoBackgroundFix` consumes `api.php?date=`.
