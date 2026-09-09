# Studio Index

Browse the sign-language studio archive by date: every take recorded on a day,
all five camera angles side by side, with a per-date completeness check.

## What it does

`index.html` is the whole front end. It asks `getStudioFiles.php` for a page of
`matched_transcriptions` rows — optionally filtered to one date — and renders
each row as a card: the gloss or sentence that was signed, and 1, 3 or 5 video
players (centre only, L-M-R, or all five). Thumbnails come from the `.jpg`
beside each `.mp4` in `post/`, falling back to `raw/`. The date dropdown is
filled from the same response: the distinct 8-digit dates found inside
`m_file` (`M20260331_1234.wav` → `20260331`).

Picking a date also calls `getDateStatus.php`, which is the "is it all there?"
answer for that day. It counts `CameraRecords` rows against
`matched_transcriptions` rows, groups both by `(glosId/m_transcription, zOg)`
and reports how many captures were validated, how many are missing (with the
gloss ids), how many rows carry each of `l_file`/`m_file`/`r_file`/`a_file`/
`b_file`, and how many are post-processed.

Two endpoints are not used by `index.html`:

| Endpoint | Used by |
|---|---|
| `getStudioFiles.php` | `index.html` — paged list (100/page) + date list |
| `getDateStatus.php` | `index.html` — per-date completeness counters |
| `api.php` | **signlab_videoBackgroundFix**, as its source of truth. `?date=YYYYMMDD` returns the full unpaged list for a date; with no `date` it returns HTTP 400 plus `available_dates` |
| `getStatusCache.php` | Nothing in this repo. Serves a pre-computed cache written by an external job (see Dependencies) |

There is no write path anywhere in this repo: it only reads.

## Where it runs

- **Production:** the signcollect core server (production VPS), served from
  `/web/studioIndex` at <https://signcollect.nl/studioIndex/>.
- **Demo hosts:** dev2 under `/web/studioIndex`, dev-1 under
  `/srv/signcollect/web/studioIndex`.

The media URLs in `getStudioFiles.php` and `api.php` are hardcoded to
`https://signcollect.nl/gebarenoverleg_media/studioFilesMini/{post,raw}/`. The
demo deploy rewrites those to same-origin paths on the host after cloning
(`rewrite-urls.sh`), so the checkout in a demo docroot is deliberately dirty.
Do not commit from a docroot.

## Status

Production. Small, stable, and depended on by another tool.

## Deploying it

There is no build step — PHP and one static HTML file, served as-is.

Deployment is driven by `interface_deploy/scripts/repos.tsv` in
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack),
which lists this repo as:

```
studioIndex	signlab_studioIndex	main
```

The host clones the repo itself; the checkout *is* the docroot directory.
Each deploy is `fetch → reset --hard → clean → rewrite-urls.sh`. To run it
locally, drop the tree in any PHP-serving directory and give it the config
below.

## Configuration

Nothing here is configurable in git. Two files must exist on the host and are
not (and must never be) committed:

- **`../mysql_config.php` — one level ABOVE this directory.** `api.php`,
  `getStudioFiles.php` and `getDateStatus.php` all `include('../mysql_config.php')`,
  so for `/web/studioIndex` the file lives at `/web/mysql_config.php`, not in
  this repo. This is an easy trap: putting a `mysql_config.php` in *this*
  directory does nothing. It must define `$servername`, `$username`,
  `$password`, `$database`. On a deployed host that file is a shim shipped by
  the stack repo (`web_extra/mysql_config.php`) that reads the credentials
  from `/web/.env`, written once by `provision.sh`.
- **`/home/gomer/mailChecker/status_cache.json`** — hardcoded in
  `getStatusCache.php`. Absent on any host that is not production, where the
  endpoint returns `{"error":"Status cache not found"}`.

## Dependencies

- **MySQL `admin_gebarenoverleg`** — tables `matched_transcriptions`,
  `CameraRecords`, `form_data`, `nmm_data`, `sentences`. Read-only.
- **Media on disk**, served over HTTP from
  `gebarenoverleg_media/studioFilesMini/{post,raw}/` — the `.mp4` and its
  `.jpg` thumbnail. Missing files simply render as a broken card.
- **The portal session cookie.** `index.html` loads `/userProtect.js` from the
  docroot root; the PHP endpoints themselves do **no** authentication, so
  anything that can reach them can read the archive index.
- **`signlab_videoBackgroundFix`** consumes `api.php` — changing its response
  shape breaks that tool's date list and clip list.
- `getStatusCache.php` depends on an external `mailChecker` job that writes the
  cache file. TODO: confirm where that job lives; there is no repo for it in
  this estate and nothing in this repo calls the endpoint.
