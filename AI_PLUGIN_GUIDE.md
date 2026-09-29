# Obitleague plugin guide for AI coding tasks

> **Use this file as the task handoff map, not as an independent specification.** Before changing behavior, inspect the current source and tests named below. This guide describes the checkout when written; it is not a release declaration. Do not assume that every working-tree change is committed or deployed. Refreshed against plugin version 0.12.1 (see `git log --oneline -5` to confirm how recent this is).

## Start here on every task

1. Read `obitleague.php` for plugin/runtime versions, bootstrap, autoloader, and module boot order.
2. Read the relevant implementation under `src/Modules/`, `src/Domain/`, `src/Templates/`, or `src/Elementor/`.
3. Read `docs/RULES.md` and the matching `tests/Scenario_*.php` for game semantics. Pure domain rules in `src/Domain/` and their tests are authoritative over older prose/docs.
4. Check `git status --short --branch` before editing. This repository may contain other agents' or user changes. Preserve unrelated modifications; never blanket-reset, stage-all, or commit unknown work. Multiple Freebuff threads may be working at once — check whether another thread owns the files you intend to touch.
5. Confirm plugin version in `obitleague.php` and schema version separately. Do not bump versions for ordinary implementation edits unless the task is a release/schema change.
6. Run `php tests/run-tests.php`, PHP lint for changed PHP, `node --check` for changed JS, and `git diff --check` where applicable. Report any known unrelated failures accurately.

## Product boundary

Obitleague is a WordPress plugin for a season-long team-picking game: a public people catalogue, discovery and editorial review of deaths, leagues, teams, auditable/reversible scoring, standings, a community forum, and admin operations. The plugin owns game rules and persistence; the theme/Elementor are presentation/integration layers. Avoid implementing eligibility, scoring, ownership, or privacy rules only in JavaScript, templates, Elementor conditions, or page-builder data.

Safety invariants:

- Feed/import/discovery flows create review candidates only; they never confirm a death, publish a record, or award points by themselves. An authorised human/editorial flow approves events, and living-person discovery adds candidates for human confirmation rather than automatically importing them.
- Rules are deterministic and versioned. Awards use reversible ledger/outbox flows; do not mutate totals directly.
- Enforce membership, ownership, email verification, eligibility, and deadlines server-side on every relevant request. Frontend state is not authorization.
- Draft/submitted team visibility must follow the current route/service privacy checks. Never expose private picks through public responses or shared caches.
- Dates keep their recorded precision; do not invent exact days. Person selection uses `Catalogue::is_selectable()` for season rules.
- Campaign sample people are fictional demo data and must never be persisted or confused with real catalogue entries.
- The forum writes only through `Forum::create_topic()`/`add_reply()` with capability and nonce checks; moderation actions are restricted to administrators and reviewers.
- The `obit_occupation` taxonomy is public and indexable: every term is a permanent archive URL. Only `People_Sync` writes it, and `Occupation_Taxonomy` prunes terms that lose their last reference when a person is deleted.

## Versions and rules

Current source values (verify before relying on these):

- WordPress plugin header / `OBITLEAGUE_VERSION`: `0.12.1` (`obitleague.php`)
- `OBITLEAGUE_DB_VERSION`: `0.5.0` (`obitleague.php`; additive migrations in `src/Modules/Setup.php`)
- Ruleset: `Ruleset::VERSION = '1'` (`src/Domain/Value/Ruleset.php`)
- Requirements: WordPress 6.4+, PHP 8.2+ (activation blocks older PHP), MySQL 8 / MariaDB 10.6+.

These are independent version tracks. A schema change needs a safe, additive migration and DB-version update. A scoring/game-rule change needs explicit ruleset/version analysis so historic entries retain their original semantics. A product release version change must keep the plugin header and `OBITLEAGUE_VERSION` synchronized. Asset cache-busting uses `OBITLEAGUE_VERSION`.

Ruleset v1 summary: calendar-year season; deadline 00:00 Europe/London on 1 January, commits strictly before deadline; ten distinct picks; minimum age 18 at season start; points `max(1, 100 - completed_age_at_death)`; tie-break on scoring picks then competition ranking; settlement through 23:59:59 Europe/London on 31 January following the season. See `docs/RULES.md`, `src/Domain/Value/Ruleset.php`, `Age.php`, `Deadline_Policy.php`, `Entry_Rules.php`, `Scoring.php`, and `Ranking.php` for the operative details. Submitted teams may be amended until the entry deadline in the current implementation; each competing revision is preserved. Check the current domain code/tests because prose in older files may lag.

## Architecture map

- `obitleague.php`: constants, PSR-4-ish `Obitleague\` autoloader, activation/deactivation hooks, module boot.
- `src/Domain/`: pure game logic/value objects; avoid WordPress dependencies here. Includes `Discovery_Rules.php` (Wikidata/Wikipedia living-person screening) and `Feed_Classifier.php` alongside the scoring/ranking/entry rules.
- `src/Modules/`: WordPress adapters and services.
- `src/Templates/`: plugin-rendered frontend routes/pages.
- `src/Elementor/`: optional Elementor dynamic tags/widgets; gracefully absent when Elementor is unavailable.
- `assets/`: frontend CSS/JS (see the marker contracts below).
- `tests/run-tests.php`: standalone domain scenarios (no WordPress runtime). Other `tests/*.php` are operational/wp-cli scripts; inspect their headers and **never execute data-changing scripts without explicit authorization**.
- `work/` is git-ignored seed material; `work/build-seed.cjs` and `work/build-cohort-1946.cjs` fetch from Wikipedia/Wikidata before `tests/import-seed-file.php` can load anything.

Module boot order (`plugins_loaded`, priority 5): admin-only `Setup::maybe_upgrade()` + `Admin_Theme`, then `Catalogue`, `Occupation_Taxonomy`, `Admin_Review`, `Discovery_Service`, `Admin_Discovery`, `Admin_Game`, `Admin_Stats`, `Demo_Accounts_Admin`, `Front_Templates`, `Site_Chrome`, `Header`, `Forum`, `Seo`, `Shortcodes`, `Game_Pages`, `Jobs`, `Rest`. `Elementor_Bridge` boots at priority 20; `HeaderBridge` on `elementor/loaded`. `Main_League_Service::on_user_register` hooks `user_register` so every new account receives a main-season entry.

Key modules:

- Game core: `League_Service` (create/join/invites), `Entry_Service` (drafts, submission receipts, stale/lock races), `Review_Service` (editorial state machine), `Outbox_Service` (award fan-out and reversals), `Scoring_Service` (award ledger), `Standings_Service` + `Overall_Standings` (published generations, keyset-paged rebuilds), `Jobs` (feed polling, outbox tick, scheduled standings rebuilds).
- Catalogue: `Catalogue` (`obit_person` post type, `obit_occupation` taxonomy, eligibility meta, `is_selectable()`), `People_Sync` (portraits P18 + occupations P106 from Wikidata; the only writer of the occupation taxonomy), `Import_Service` (feed/people imports; keeps role text as `obit_occupation_hint`), `Wikidata_Search_Service` (player-initiated lookup/import), `Person_Content` (composed body prose), `Occupation_Taxonomy` (orphan-term pruning), `Pick_Stats` (how often a name was taken).
- Front end: `Game_Pages` (routes/shortcodes/page provisioning), `Front_Templates` (person/archive templates), `Site_Chrome` (footer, fonts, motion — the navigation bar comes from `Header`), `Header` + `HeaderIntegration` (site header, see below), `Forum`, `Auth` (registration/email verification), `Campaign` (2027 landing demo), `Seo` (titles, meta, JSON-LD, crawl control).
- Admin: `Admin_Review`, `Admin_Discovery`, `Admin_Game`, `Admin_Stats`, `Admin_Theme`, `Demo_Accounts_Admin`.
- Discovery: `Discovery_Service` (living-person candidate queue for editorial confirmation).

Primary data concepts are normalized plugin tables (`obitleague_leagues`, league members, entries, entry revisions/picks, events, review cases, awards, standings generations, forum topics/posts, audit log, and supporting tables) plus public WordPress `obit_person` posts/meta. Read `Setup.php` for the exact current schema; do not assume every old architecture diagram matches it.

## Site headers

`Header` renders the single site navigation (`ob-header` mega menu) at `wp_body_open`. The old `ob-nav` bar from `Site_Chrome` has been removed: `Site_Chrome::render_header()` now only prints the skip link and opens the `#ob-main` content wrapper, which `render_footer()` closes around the footer. `Game_Pages::ensure_campaign_pages()` auto-provisions the `/people/` page plus `/login/`, `/register/` and `/verify-email/` on `init`, so nav links to them resolve. The `obitleague-header` script registers with a `.min` suffix when `SCRIPT_DEBUG` is off; `assets/header.min.js` is committed and is hand-minified — keep it in sync whenever `assets/header.js` changes.

## Routes, shortcodes, and page integration

### Plugin-routed pages

- Rewrites: `/league/{id}/` and `/team/{entry_id}/` (`Game_Pages::rewrites()`), served by `league-detail.php` / `team-detail.php`.
- Path routes at `template_include` 30: `/my-leagues/`, `/join/` (template `join-league.php`), `/stats/`.
- Campaign template at the site front page and `/register/` → `register-page.php` (`Game_Pages::campaign_template()`, priority 40) without rewriting Elementor page content.
- Forum rewrites: `/forum/`, `/forum/page/{n}/`, `/forum/{topic}` → `forum.php` / `forum-topic.php` (`Forum::maybe_route()`, priority 30).
- Person singular/archive and `obit_occupation` term archives render through `Front_Templates` (priority 20) unless the theme provides its own.
- `/register/` and `/verify-email/` WordPress pages are provisioned if missing by `Game_Pages::ensure_campaign_pages()`. Do not overwrite an existing Elementor page or its stored data.
- `/standings/`, `/people/`, `/archive/`, and `/rules/` are normal WordPress pages that carry shortcodes (Elementor documents on seeded installs).

### Shortcodes registered by the plugin

- `[obitleague_hero]` — current-season campaign-style hero.
- `[obitleague_stats]` — aggregate stats tiles with `data-count` animation.
- `[obitleague_recent_deaths count="8"]` — recent published death records; count is bounded 1–30.
- `[obitleague_standings league="" season="..." top="10"]` — standings grid for one named league or all leagues in a season.
- `[obitleague_people living="1" per_page="12"]` — public people cards; `living=0` selects records with a death date. Also honours query-string filters `?occupation=`, `?birth_year=`, `?age=` with active-filter chips.
- `[obitleague_archive]` — public death archive, optional `?ob_year=YYYY` filter.
- `[obitleague_rules]` — rules and scoring explanation, rendered from `Ruleset` domain constants.
- `[obitleague_overall_standings season="..." top="0" page="1" per_page="50"]` — canonical overall standings; the heading follows the published current standings season when one exists, falling back to the entry season with an honest note.
- `[obitleague_join]` — invite-only side-league join/create UI.
- `[obitleague_register]` — dedicated account registration; email verification required for campaign accounts.
- `[obitleague_2027_campaign]` — landing campaign and non-persistent fictional scoring demo.

Registration list is derived from `Shortcodes::boot()`, `Game_Pages::boot()`, `Auth::boot()`, and `Campaign::boot()`. Confirm actual parameters in each implementation before relying on this quick list.

### Authentication surface

Dedicated signup at `/register/` with honeypot and rate limits; verification links point at `/verify-email/?uid=..&token=..` (24-hour TTL, hashed token). Campaign accounts carry `obitleague_email_verified` / `obitleague_campaign_signup` user meta; pre-existing accounts are never blocked by the campaign distinction. Unverified campaign accounts cannot log in or use game routes. Global WordPress registration is not the signup path and must stay disabled unless explicitly requested.

## REST API contract (current source)

Base: `/wp-json/obitleague/v1`. Inspect `Rest::register_routes()` for exact args/methods and services before extending. All game routes use `Auth::must_be_verified()` as the permission callback; the review route uses a capability check (`manage_options` or `obitleague_review`).

- `GET /people`: public, published approved catalogue search, paginated (`search`, `page`, `per_page` ≤ 50); returns `id`, `uuid`, name/link/date/age/occupations/role and `selectable`; total via `X-WP-Total`.
- `GET /wikidata/people?search=...`, `POST /wikidata/people {qid}`: verified-account Wikidata lookup and explicit player selection/import. Rate-limited (20/h search, 10/h import).
- `POST /nominations`: verified-account human review request (`name`, https `source_url`, bounded `reason`); does not itself approve/import a person. Rate-limited.
- `POST /leagues`, `POST /leagues/join`: create/join optional side leagues; idempotent duplicate join; rate-limited.
- `GET|PUT /main-entry`, `POST /main-entry/submit`, `GET|PUT /entries/{id}`, `POST /entries/{id}/submit`: main/side team read, optimistic saves (`expected_version`), submit with receipt. Server validates membership, deadline, pick count, publish state, and catalogue eligibility; existing picks keep working even if a person later becomes unselectable.
- `GET /main-standings`, `GET /leagues/{id}/standings` (`main=1` for the canonical league): standings responses; side-league standings are members-only; `picks_visible` follows the lock state.
- `POST /reviews/{id}/decision`: reviewer/admin-only editorial decision (`to_state` approved/rejected/retracted, `expected_revision`, field decisions, `reason`).

Error mapping follows `docs/ARCHITECTURE.md`: 400 invalid input, 401 unauthenticated, 403 forbidden, 404 unavailable private resource, 409 stale/locked, 422 invalid selection, 429 throttled. Abuse-prone operations are rate-limited via transients.

## Frontend selectors and `data-*` markers

These are JavaScript contracts between templates and assets. If changing a marker, update all consumers and keep selectors scoped to their widget/root.

### Main/side teams (`src/Templates/my-leagues.php`, `assets/team-manager.js`)

- `#build-team`: deep-link target used by campaign CTA `/my-leagues/#build-team`.
- `[data-ob-main-team]`: root; `data-rest` is REST base URL, `data-nonce` is WP REST nonce, `data-verified="1|0"` is UI hint only (server remains authoritative).
- Main editor: `[data-main-editor]`, `[data-main-name]`, `[data-main-search]`, `[data-main-results]`, `[data-main-count]`, `[data-main-picks]`, `[data-main-save]`, `[data-main-submit]`, `[data-main-message]`, `[data-main-status]`, `[data-main-edit]`.
- Each side card: `[data-side-team]` with `data-entry-id`; editor has `[data-side-editor]`, `[data-side-name]`, `[data-side-search]`, `[data-side-results]`, `[data-side-count]`, `[data-side-picks]`, `[data-side-save]`, `[data-side-submit]`, `[data-side-message]`, `[data-side-edit]`.
- Team manager uses `GET /main-entry` or `GET /entries/{id}`, public `GET /people`, Wikidata routes when local search has no results, then `PUT` save and `POST` submit. Submission/amendment behavior and verification gating come from server domain/service code.

### Site chrome and headers

- `[data-ob-nav]`: removed — the legacy `Site_Chrome` navigation bar is gone; the inline script in `render_footer()` now only drives the `.ob-anim` reveal with a 1.2-second failsafe and the `.ob-stat__num[data-count]` count-up (reduced-motion aware).
- `[data-ob-header]`: `Header`/`Widget_ObHeader` mega-menu header; `assets/header.js` handles the mobile toggle and the `.ob-header__item` mega menus via `[data-ob-mega]` (open on hover/focus, click-to-open under 1024px).

### Campaign (`src/Modules/Campaign.php`, `assets/campaign.js`)

- `[data-demo-picks]`: sample list container.
- `[data-demo-pick]`: fictional pick toggle; `data-age` drives the sample formula, `data-name` is display text; button exposes `aria-pressed`.
- `[data-demo-score]`: total; `[data-demo-result]`: selected-pick count; `[data-demo-random]`: add a random sample pick.
- Demo is local-only/non-persistent; its hypothetical formula demonstrates `max(1,100-age)` and is not a real forecast or award.

### Other markers

- `[data-count]` on `.ob-stat__num`: animated statistic count.
- `[data-toggle]` in `league-detail.php`: expandable league row UI with an inline click handler.
- `[data-ob-join]`, `[data-ob-join-msg]`, `[data-ob-create]`, `[data-ob-create-msg]`: side-league form submission UI in `Game_Pages::join_shortcode()` (inline script; REST nonce field name `_obnonce`).
- `[data-ob-confirm]`: admin forms with a typed-name/delete confirmation dialog (`Admin_Game`).

Find the authoritative consumer with a repository search before renaming any marker.

## Elementor

- Dynamic tag group `obitleague`; tag `obitleague-person-field` supports `name`, `role`, `birth_date`, `death_date`, and `portrait_credit` for published `obit_person` posts. Unpublished posts render nothing; dates keep their stored precision.
- Widget `obitleague-league-standings` takes `league_id`; its current source still uses a filter-based rows adapter (`obitleague_league_standings_rows`), so do not promise it renders service standings without checking. Membership is guarded on every render.
- Widget `obitleague-ob-header` renders the mega-menu header; registered by `HeaderBridge` with an `obitleague-header` document type.
- Elementor is optional for plugin data/admin/runtime, but pages authored as Elementor documents (including seeded demo pages) render empty until Elementor is installed and active.

## Operational cautions

- Global WordPress registration is not the signup path; use the dedicated plugin form/verification flow. Do not enable `users_can_register` unless explicitly requested.
- Email verification uses campaign-specific account metadata and must not block pre-existing accounts; inspect `Auth.php` before altering this distinction.
- `Setup::maybe_upgrade()` applies schema changes in admin; database migrations must be additive and carefully reviewed.
- Page provisioning and demo/seed/migration scripts may change WordPress data. `tests/demo-*.php`, `tests/import-seed-file.php`, `tests/migrate-main-entries.php`, `tests/sync-portraits.php`, `tests/build-person-content.php`, and the `verify-*` scripts are wp-cli/eval-file operations — do not run them against production or a shared database without explicit approval. `tests/e2e-*.php` exercise live flows on a real install.
- Do not deploy or edit the LocalWP/live copy as part of a source-only task unless specifically requested. Deployment is guarded by `deploy-live.sh` and requires explicit user direction.
- The legacy `ob-nav` header was removed in favour of the single `ob-header` system. Keep `assets/header.min.js` in sync with `assets/header.js`.
- Keep unrelated user/agent changes untouched. Prefer narrowly scoped diffs, tests, and commits; do not assume multiple Freebuff threads automatically coordinate.

## Useful checks

```bash
php tests/run-tests.php
php -l src/Modules/Changed_Module.php
node --check assets/changed-file.js
git diff --check
```

The PHP domain suite does not replace WordPress integration tests. If no WP runtime is available, say so rather than claiming routes, email, page provisioning, or browser behavior were verified.
