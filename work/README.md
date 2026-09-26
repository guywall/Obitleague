# Work directory

Working registers and operational data. `README.md` and `source-register-template.md` are committed as templates; everything else stays local (see `.gitignore`).

## Registers

- `seed-manifest.md` — candidate and approved catalogue counts by batch, source and licence. Target: 5,000–10,000 staged, 1,000 approved selectable, 200 historical deaths.
- `source-register.md` — feed register. A source enters only after its current feed URL and usage terms have been checked and recorded. Poll priority feeds every 5 minutes, others every 15.
- `connector-checklist.md` — one row per connector trial (BBC, major nationals, music/film/sport/obituary feeds).

## Add a source

1. Copy `source-register-template.md` into `source-register.md` if it does not exist.
2. Record owner, feed URL, polling interval, terms check date and permitted use.
3. Record last successful poll and cache headers from the first fetch.

If terms are unclear, leave the source out.
