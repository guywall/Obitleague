# Obitleague

Fantasy dead pool league website for WordPress. Players pick ten public figures before the season starts; verified deaths of their picks score points according to a versioned, reversible ruleset.

> Start with [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for module layout and the REST contract, and [docs/RULES.md](docs/RULES.md) for the normative ruleset. Both are verified against source; confirm details before editing.

## Design principles

1. **No LLM in the pipeline.** Death detection uses publisher RSS/Atom feeds; a human editor confirms every death. News wording is ambiguous and the pipeline never publishes unreviewed claims.
2. **No paid news APIs.** Publisher feeds, Wikidata, Wikipedia and player reports cover discovery. No NewsAPI or Guardian Open Platform dependency.
3. **Deterministic scoring.** The same approved facts and rules version must produce the same award. Every points change is a ledger row, never an UPDATE to a total.
4. **Reversible.** Corrections, retractions and eligibility changes produce new signed adjustments; nothing is mutated in place. Standings rebuilds produce new published generations.
5. **Privacy by default.** Teams stay private until the league locks. Membership is re-checked on every render; no page, REST route, export or cache may leak picks before lock.
6. **Single plugin.** One modular plugin owns all game data, jobs and administration. The site theme is presentation only.

## Status

v0.9.0 — catalogue, feed discovery, editorial review, leagues, teams, reversible
scoring, statistics, the community forum and public search metadata are
implemented. The domain rules engine is covered by `tests/run-tests.php`.
See [CHANGELOG.md](CHANGELOG.md) for what landed recently and
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the module map and REST
contract.

## Layout

```
obitleague.php            Plugin bootstrap (header, constants, boot)
src/Modules/*.php         Module services (catalogue, REST, Elementor, jobs)
src/Modules/Seo.php      Titles, meta, Open Graph, Person schema, crawl control
src/Templates/*.php       Front-end templates (person, league, team, stats, forum)
src/Domain/*.php          Pure rules engine: age, scoring, ranking, team validation
src/Domain/Value/*.php    Immutable value objects (rulesets, names, reasons, provenance)
src/Support/*.php         Internal utilities
docs/                     Rules, architecture, operations, handoff
tests/                    Standalone test runner (no WordPress required)
work/                     Git-ignored: seed manifests, source registers, connector checklists
.gitattributes            Enforces LF line endings for PHP and docs
```

## Domain rules in one screen

- Season: calendar year. Deadline: `00:00 Europe/London` on 1 January; a write must commit strictly before the deadline.
- Team: exactly ten distinct people; one entry per player per league per season; shared picks allowed; no transfers after lock.
- Points: `max(1, 100 − completed_age_at_death)` per pick, summed over ten picks.
- Ranking: total points, then scoring picks, then shared position (1, 2, 2, 4).
- Settlement: standings stay provisional until `23:59:59 Europe/London` on 31 January. Later deaths update the archive but never create new awards for a settled season.

Full detail: [docs/RULES.md](docs/RULES.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Local commands

```bash
php tests/run-tests.php     # domain test suite (no WordPress required)
php -l src/...              # lint individual files
```

Maintenance scripts run through WP-CLI and are safe to re-run:

```bash
wp eval-file tests/build-person-content.php        # (re)compose person page bodies
wp eval-file tests/prune-orphan-occupation-terms.php  # drop empty occupation terms
wp eval-file tests/verify-orphan-occupation-pruning.php  # deleting a person prunes their terms
wp eval-file tests/verify-import-taxonomy.php      # assert imports create no public terms
wp eval-file tests/verify-pick-stats.php          # pick counts, hot/unique badges, page render
wp eval-file tests/import-seed-file.php <path>     # load any builder-produced seed file
wp eval-file tests/sync-portraits.php              # portraits + occupations from Wikidata
```

`tests/import-seed-file.php` imports people as private candidates and publishes
them only with `OBITLEAGUE_SEED_APPROVE=1`; it refuses to write to a production
site without `OBITLEAGUE_ALLOW_SEED_IMPORT=1`. Its switches come from the
environment because WP-CLI rejects an unknown `--flag` on `eval-file`.

Occupations are sourced, never imported. The `obit_occupation` taxonomy is
written only by `tests/sync-portraits.php` (Wikidata P106), so run it after
importing people; feed role text is kept as the `obit_occupation_hint` postmeta
instead of becoming a public archive page. Because each term is a public archive
URL, deleting a person prunes the occupations only they were filed under;
a term anyone else still holds is never removed.

## Demo data

The demo dataset is generated, not committed. `work/build-seed.cjs` fetches the
monthly Wikipedia "Deaths in …" pages and Wikidata facts, then
`tests/demo-import-people.php` and the other `tests/demo-*.php` scripts load
them. Raw seed JSON is git-ignored; run the builder first on a fresh clone.

`work/build-cohort-1946.cjs` builds a deeper *living* pool instead: 150 people
born in 1946, selected so the catalogue stays spread across nationalities and
occupations (93 countries, 265 occupations). Its output
`work/seed-cohort-1946.json` is committed, so the cohort does not need
rebuilding; load it with `tests/import-seed-file.php`. Liveness is checked
against both Wikidata and Wikipedia's death-year categories, because an absent
date of death is not proof of life. See `work/seed-manifest.md` for the method.

## Requirements

- WordPress 6.4+, PHP 8.2+, MySQL 8 or MariaDB 10.6+
- Elementor for the presentation layer; PRO Elements where used. The plugin's own
  templates (person, league, team, stats, forum, campaign) render without it, but
  pages authored as Elementor documents — including the seeded demo pages — render
  empty until Elementor is installed and active.
- System scheduler (cron) for feed polling and scoring jobs
