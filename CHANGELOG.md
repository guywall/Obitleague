# Changelog

All notable changes to the Obitleague plugin. Versions follow the plugin
header in `obitleague.php`; each released version is tagged in git.

## [Unreleased]

## [0.5.0] — 2026-09-27

First GitHub release. Functional demo season (2026) with real sourced data.

### Added
- Editorial front-end design system: deep green/brass palette, Fraunces +
  Public Sans, sticky branded nav with mobile menu, rich footer, reveal and
  count-up motion (reduced-motion aware).
- Statistics-driven pages built as Elementor templates: home, standings,
  people catalogue, death archive, rules — content generated from the domain
  constants so published rules cannot drift from the engine.
- Game structure per the product plan: public league pages (`/league/<id>/`)
  with expandable scored rosters, overall cross-league rankings (best league
  score, competition ranks), My Leagues with per-league status, and working
  join-by-token / create-league forms over the REST API.
- Person profiles: memoriam hero, life timeline, approved-facts panel,
  season scorecard showing the real points value, sourced attribution.
- Portraits from Wikidata P18 via Commons special-file links with credit
  meta and monogram fallback (`wp eval-file tests/sync-portraits.php`).
- LAN preview proxy (`work/lan-proxy.cjs`) so phones on the local network
  can browse the site and WP admin.

### Domain engine (from 0.1.0 scaffold)
- Ruleset v1: ten picks, 00:00 Europe/London 1 Jan deadline, reversible
  max(1, 100 − age) scoring, competition ranking (1, 2, 2, 4), hashed invite
  tokens with 14-day TTL, settlement 31 Jan.
- Editorial review state machine with field-level decisions, one approved
  death event per person, idempotent outbox awards with corrections and
  reversals, rebuildable standings generations.
- RSS/Atom intake with GUID deduplication and a circuit breaker; Wikipedia
  "Deaths in <month>" + Wikidata P569/P570 seeding pipeline (CC0/CC BY-SA
  respected, no paid APIs, no LLM in the pipeline).
- 88 domain tests (`php tests/run-tests.php`).

## [0.4.2] — 2026-09-27

### Fixed
- My Leagues fatal (missing import in a global-namespace template).
- Content links scoped away from the Elementor kit link colour.
- Future-season leagues labelled "next season" rather than "season over".

## [0.4.1] — 2026-09-27

### Changed
- Navigation: route families highlight their parent item (league pages →
  Standings, person profiles → People); Sign in / Log out entries; the
  primary CTA becomes "My game" when signed in.
- League titles on the standings page link to their league pages.
- Join/create forms hand off to My Leagues after success.

## [0.4.0] — 2026-09-27

### Added
- Overall standings service and shortcode (`[obitleague_overall_standings]`).
- League view service + `/league/<id>/` rewrite and template.
- `People_Sync` portrait service with credit postmeta.
- My Leagues and Join templates; nav v2 with account entries.

## [0.3.0] — 2026-09-27
## [0.3.1] — 2026-09-27

### Added
- Person profile template redesign; archive template redesign; rules page
  content driven by `Ruleset` constants.

## [0.2.0] — 2026-09-27
## [0.2.1] — 2026-09-27
## [0.2.2] — 2026-09-27

### Added
- First theming iteration: design tokens, hero, stat tiles, medal ranks,
  site chrome module, Elementor kit overrides, cache-purge helper.

## [0.1.0] — 2026-09-27

### Added
- Plugin scaffold: domain rules engine, catalogue, review, scoring,
  standings, REST, feed jobs, admin screens, 2026 demo seed (3 leagues,
  12 teams, 91 approved cases from real sourced deaths).
- Statistics-driven Elementor pages and primary nav.
