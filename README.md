# signlab_studio-archive
Browse the studio archive by date: every take of a day, all five camera angles, and a completeness check per date.

## What it does
| File | Does | Used by |
|---|---|---|
| `index.html` | one card per `matched_transcriptions` row: the gloss or sentence and 1, 3 or 5 players. Thumbnails are the `.jpg` next to each `.mp4` (`post/`, else `raw/`) | browser |
| `api.php?page=N[&date=]` | list of 100 takes per page, plus the distinct dates in `m_file` (`M20260331_…` gives `20260331`) | `index.html` |
| `api.php?date=YYYYMMDD` | the full list for one date, not paged. Without `date` it returns HTTP 400 and `available_dates` | [signlab_background-fix](https://github.com/Amsterdam-Humanities-Labs/signlab_background-fix). Do not change this shape |
| `getDateStatus.php?date=` | compares `CameraRecords` with `matched_transcriptions`: validated, missing gloss ids, counts per camera, post-processed | `index.html` |

It only reads; nothing writes.

## Where it runs
- Production: core server, `/web/studioIndex`, <https://signcollect.nl/studioIndex/>.
- Demo: dev2 `/web/studioIndex`, dev-1 `/srv/signcollect/web/studioIndex`.
- Media URLs are hardcoded to `https://signcollect.nl/gebarenoverleg_media/studioFilesMini/{post,raw}/`. Demo deploys rewrite them (`rewrite-urls.sh`), so a demo checkout has local changes. Do not commit from a docroot.

## Status
Production.

## How to run / deploy
There is no build step. The stack deploys `main` (`repos.tsv` line `studioIndex	signlab_studio-archive	main`).
It runs fetch, `reset --hard`, clean, then `rewrite-urls.sh`. See
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).

## Configuration
- `../mysql_config.php`, one level above this directory (for example `/web/mysql_config.php`). It defines `$servername`, `$username`, `$password` and `$database`.
- A copy inside this directory does nothing. On deployed hosts the file is the stack's `web_extra/mysql_config.php` shim, which reads `/web/.env`.

## Dependencies
- MySQL `admin_gebarenoverleg`: reads `matched_transcriptions`, `CameraRecords`, `form_data`, `nmm_data`, `sentences`.
- Media over HTTP from `gebarenoverleg_media/studioFilesMini/{post,raw}/`.
- `/userProtect.js` guards `index.html`. The PHP endpoints have no login check.
- signlab_background-fix calls `api.php?date=`.

## License and citation

Apache License 2.0, copyright University of Amsterdam: see [LICENSE](LICENSE) and
[NOTICE](NOTICE). You may use it, also commercially, as long as you credit
Gomer Otterspeer / University of Amsterdam as the source. To cite it, use
[CITATION.cff](CITATION.cff) (the *Cite this repository* button on GitHub) or the DOI [10.21942/uva.33980392](https://doi.org/10.21942/uva.33980392).
