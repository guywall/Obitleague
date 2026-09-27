# Obitleague

Fantasy dead pool league website for WordPress. Players pick ten public figures before the season starts; verified deaths of their picks score points according to a versioned, reversible ruleset.

> **AI coding task handoff:** Start with [AI_PLUGIN_GUIDE.md](AI_PLUGIN_GUIDE.md) for the current plugin scope, versions, shortcodes, REST routes, frontend markers, and safe-change workflow. Verify details against source before editing.

## Design principles

1. **No LLM in the pipeline.** Death detection uses publisher RSS/Atom feeds; a human editor confirms every death. News wording is ambiguous and the pipeline never publishes unreviewed claims.
2. **No paid news APIs.** Publisher feeds, Wikidata, Wikipedia and player reports cover discovery. No NewsAPI or Guardian Open Platform dependency.
3. **Deterministic scoring.** The same approved facts and rules version must produce the same award. Every points change is a ledger row, never an UPDATE to a total.
4. **Reversible.** Corrections, retractions and eligibility changes produce new signed adjustments; nothing is mutated in place. Standings rebuilds produce new published generations.
5. **Privacy by default.** Teams stay private until the league locks. Membership is re-checked on every render; no page, REST route, export or cache may leak picks before lock.
6. **Single plugin.** One modular plugin owns all game data, jobs and administration. The site theme is presentation only.

## Status

Scaffold, v0.1.0 — architecture and rules engine are in place. See [docs/IMPLEMENTATION.md](docs/IMPLEMENTATION.md) for what exists and what is next.

## Layout

```
obitleague.php            Plugin bootstrap (header, constants, boot)
src/Modules/*.php         Module services (catalogue, REST, Elementor, jobs)
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

## Requirements

- WordPress 6.4+, PHP 8.2+, MySQL 8 or MariaDB 10.6+
- Elementor and PRO Elements for the presentation layer; the plugin degrades gracefully without them
- System scheduler (cron) for feed polling and scoring jobs
