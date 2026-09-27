# Changelog

All notable changes to the Obitleague plugin. Versions follow the plugin
header in `obitleague.php`; each released version is tagged in git.

## [Unreleased]

## [0.8.0] — 2026-09-27

### Added
- Community forum at `/forum/`: verified players start threads and reply,
  everyone can read; moderators (admins/editors) can pin, lock or delete
  threads. Forum pages carry the full site chrome and navigation.
- Statistics page: "The shape of the archive" — a dozen analysis boards
  computed from confirmed deceased records (occupations via the taxonomy,
  birth decades, birth months, weekday born, star signs, first initials,
  name letter counts, ages at death) with linked occupation groups and a
  headline fact; cached and refreshed when records change.
- Occupations are now a first-class taxonomy: Wikidata occupation postmeta
  is mirrored into `obit_occupation` terms on every import/sync, an
  additive migration backfills existing people, and catalogue/profile
  cards show linked occupation tags. Occupation archives are browsable
  pages, so players can source groups of picks without extra Wikidata
  searches; the team picker already searched the on-site catalogue first
  and falls back to Wikidata with occupation labels for disambiguation.
- Branded admin design system: Obitleague screens (review queue and case
  screens, leagues & teams admin, statistics, demo accounts, people and
  nominations lists, person edit screen) pick up the editorial palette,
  typography, buttons, tables and metabox styling via a scoped
  `assets/admin.css` loaded by the new Admin_Theme module. Core admin
  screens outside the plugin are untouched.
- Administrator league management gains deletion: a side league can be
  deleted (members, teams, revisions, picks and awards; typed-name
  confirmation; audited; standings generations cleaned up) and any team
  entry can be deleted with reason, award removal and immediate standings
  rebuild. The main league cannot be deleted.

### Changed
- Rules page rewritten in plain language — five walkthrough sections,
  human examples of the scoring formula, tie-breaks, month-precision and
  settlement notes, and an expanded respect section covering the forum.
  All governing values still render from the `Ruleset` domain constants.
- Living person profiles read "age: 74" instead of "74 today" on the life
  timeline, so a birthday never seems to be implied.

### Fixed
- Light mint/white links on gold hero CTA buttons (the `ob-hero a`
  override painted button text nearly invisible); CTA buttons keep their
  dark ink, other hero links stay light.

### Added
- Per-entry team names with an additive schema migration, team-profile and
  standings display, and administrator editing/audit support.
- Expanded the local demo to eight themed leagues, forty clearly fictional
  demo accounts and up to 64 named teams with sourced, themed pick mixes.
- WordPress administrator screens for league creation and management,
  member status/removal, team entry creation, audited pick revisions,
  submission/withdrawal, revision history and standings rebuilds. Added a
  database-backed administrator audit log and additive schema migration.

### Added
- Join and team-management front end with debounced catalogue search,
  age/occupation disambiguation and explicit human-only Wikidata add flow.
- Players can amend submitted teams until their season begins; prior submitted
  revisions remain preserved and new approved-event awards are applied.

### Fixed
- Wrapped public standings and plugin-admin data tables in local horizontal
  scroll containers, and tightened narrow-screen league roster rows so tables
  and metadata no longer widen the page.
- Person-page potential now reflects points at the person's current completed
  age; scoring details live in an accessible tooltip instead of a paragraph.
- Demo imports retain Wikipedia article titles so profiles can link directly
  to the article (or follow the QID sitelink when a title is unavailable).

## [0.7.1] — 2026-09-27

### Fixed
- Reveal-on-scroll animation now has a 1.2-second failsafe so content can
  never stay invisible when IntersectionObserver callbacks never fire
  (non-composited webviews, some embedded browsers, printing).

## [0.7.0] — 2026-09-27

### Added
- Occupations for every catalogue profile, pulled from Wikidata P106 and
  stored comma-separated, with a primary designator taken from the
  preferred-rank claim (falling back to the first listed occupation).
  Shown on catalogue and archive cards and on profile pages (primary
  first); the QID map is kept in postmeta for future filtering.

### Fixed
- Nav highlight over-matched: every menu item lit up on catalogue-family
  routes. Exactly one item highlights per route now.

## [0.6.1] — 2026-09-27

### Fixed
- Hero links stay light on dark backgrounds (team page "back to league"
  was nearly invisible after the content-link palette change).
- Stats route no longer double-highlights Standings in the nav.

## [0.6.0] — 2026-09-27

### Added
- Team pages at `/team/<entry-id>/`: full ten-pick card grid with slot
  numbers, portraits, award badges and honest pre-lock-death zeros;
  header carries rank, points and scoring-pick summary. Draft entries
  redirect (team pages exist only for submitted teams).
- Statistics page at `/stats/` with four boards computed live from the
  awards ledger: Most picked, Flying under the radar (unpicked living
  figures), Highest-scoring teams (cross-league, linked to team pages)
  and Streaking teams (2+ scoring picks inside any 14-day window), plus
  a Season momentum timeline of points awarded per month.
- Standings tables link every player to their team page; the nav gains
  a Stats item beside Standings.

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
