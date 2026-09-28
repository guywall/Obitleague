# Work directory

Working registers and operational data. `README.md` and `source-register-template.md` are committed as templates; everything else stays local (see `.gitignore`).

## Registers

- `seed-manifest.md` — candidate and approved catalogue counts by batch, source and licence. Target: 5,000–10,000 staged, 1,000 approved selectable, 200 historical deaths.
- `source-register.md` — feed register. A source enters only after its current feed URL and usage terms have been checked and recorded. Poll priority feeds every 5 minutes, others every 15.
- `connector-checklist.md` — one row per connector trial (BBC, major nationals, music/film/sport/obituary feeds).

## Synthetic demo accounts

On a local or staging WordPress install, create 2,500 natural-looking synthetic
accounts with `wp eval-file tests/demo-import-users.php` from the WordPress root.
The importer is idempotent; change the count with
`OBITLEAGUE_DEMO_USER_COUNT=500 wp eval-file tests/demo-import-users.php`.

Each account is marked in private user metadata and uses a reserved `example.test`
email plus an inaccessible generated password. Public-facing names are not
marked. WordPress admins can identify them in **Users → Obitleague demo accounts**;
that view includes a nonce-protected bulk delete action. To remove only these
accounts from the CLI, run
`OBITLEAGUE_CONFIRM_DEMO_USER_DELETE=1 wp eval-file tests/demo-remove-users.php`.
These are user accounts only, not league memberships or entries. Use only on a
non-production site; the importer refuses a WordPress production environment
unless explicitly overridden.

## Cohort builders

`build-seed.cjs` harvests deaths. `build-cohort-1946.cjs` builds a *living*
cohort instead — people born in a given year who are still alive, and who can
be picked. Run it, then load the result:

```
node work/build-cohort-1946.cjs --target 150
OBITLEAGUE_SEED_APPROVE=1 wp eval-file tests/import-seed-file.php work/seed-cohort-1946.json
```

Useful switches: `--target` (rows to write, minimum 100), `--sample` (how many
candidates to enrich), `--out`, and `--any-precision` to keep birth dates that
lack a day, which the plugin will not publish without an editor reviewing them.
`--selftest` proves the liveness cross-check can fail.

The builder caches every HTTP response under `work/.cohort-cache/`, so an
interrupted run resumes instead of starting over. The cache is local and not
committed. The row data is committed, and the method is recorded in
`seed-manifest.md`.

`tests/import-seed-file.php` takes any path, so it also loads a hand-built list.
It imports people as private candidates unless `OBITLEAGUE_SEED_APPROVE=1` is
set, and refuses to write to a production environment without
`OBITLEAGUE_ALLOW_SEED_IMPORT=1`. Its switches come from the environment rather
than the command line because WP-CLI rejects an unknown `--flag` on
`eval-file` before the script is reached.

## Add a source

1. Copy `source-register-template.md` into `source-register.md` if it does not exist.
2. Record owner, feed URL, polling interval, terms check date and permitted use.
3. Record last successful poll and cache headers from the first fetch.

If terms are unclear, leave the source out.
