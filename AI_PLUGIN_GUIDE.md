# Obitleague plugin guide for AI coding tasks

> **Use this file as the task handoff map, not as an independent specification.** Before changing behavior, inspect the current source and tests named below. This guide describes the checkout when written; it is not a release declaration. Do not assume that every working-tree change is committed or deployed.

## Start here on every task

1. Read `obitleague.php` for plugin/runtime versions, bootstrap, autoloader, and module boot order.
2. Read the relevant implementation under `src/Modules/`, `src/Domain/`, `src/Templates/`, or `src/Elementor/`.
3. Read `docs/RULES.md` and the matching `tests/Scenario_*.php` for game semantics. Pure domain rules in `src/Domain/` and their tests are authoritative over older prose/docs.
4. Check `git status --short --branch` before editing. This repository may contain other agents' or user changes. Preserve unrelated modifications; never blanket-reset, stage-all, or commit unknown work.
5. Confirm plugin version in `obitleague.php` and schema version separately. Do not bump versions for ordinary implementation edits unless the task is a release/schema change.
6. Run `php tests/run-tests.php`, PHP lint for changed PHP, `node --check` for changed JS, and `git diff --check` where applicable. Report any known unrelated failures accurately.

## Product boundary

Obitleague is a WordPress plugin for a season-long team-picking game, public people catalogue, invite-only side leagues, human editorial review, auditable/reversible scoring, standings, and admin operations. The plugin owns game rules and persistence; the theme/Elementor are presentation/integration layers. Avoid implementing eligibility, scoring, ownership, or privacy rules only in JavaScript, templates, Elementor conditions, or page-builder data.

Safety invariants:

- Feed/import discovery creates candidates; it does not automatically confirm deaths or award points. An authorised human/editorial flow approves events.
- Rules are deterministic and versioned. Awards use reversible ledger/outbox flows; do not mutate totals directly.
- Enforce membership, ownership, email verification, eligibility, and deadlines server-side on every relevant request. Frontend state is not authorization.
- Draft/submitted team visibility must follow the current route/service privacy checks. Never expose private picks through public responses or shared caches.
- Dates keep their recorded precision; do not invent exact days. Person selection uses `Catalogue::is_selectable()` for season rules.
- Campaign sample people are fictional demo data and must never be persisted or confused with real catalogue entries.

## Versions and rules

Current source values (verify before relying on these):

- WordPress plugin header / `OBITLEAGUE_VERSION`: `0.7.3` (`obitleague.php`)
- `OBITLEAGUE_DB_VERSION`: `0.4.0` (`obitleague.php`; additive migrations in `src/Modules/Setup.php`)
- scoring ruleset: `Ruleset::VERSION = '1'` (`src/Domain/Value/Ruleset.php`)

These are independent version tracks. A schema change needs a safe, additive migration and DB-version update. A scoring/game-rule change needs explicit ruleset/version analysis so historic entries retain their original semantics. A product release version change must keep the plugin header and `OBITLEAGUE_VERSION` synchronized. Asset cache-busting uses `OBITLEAGUE_VERSION`.

Ruleset v1 summary: calendar-year season; deadline 00:00 Europe/London on 1 January, commits strictly before deadline; ten distinct picks; minimum age 18 at season start; points `max(1, 100 - completed_age_at_death)`; tie-break on scoring picks then competition ranking; settlement through 23:59:59 Europe/London on 31 January following the season. See `docs/RULES.md`, `src/Domain/Value/Ruleset.php`, `Age.php`, `Deadline_Policy.php`, `Entry_Rules.php`, `Scoring.php`, and `Ranking.php` for the operative details. Submitted teams may be amended before lock in the current implementation; each competing revision is preserved. Check the current domain code/tests because prose in older files may lag.

## Architecture map

- `obitleague.php`: constants, PSR-4-ish `Obitleague\\` autoloader, activation/deactivation hooks, module boot.
- `src/Domain/`: pure game logic/value objects; avoid WordPress dependencies here.
- `src/Modules/`: WordPress adapters and services: catalogue, REST, auth, campaigns, entries, leagues, main league, review, scoring/outbox, standings, background jobs, admin, imports/Wikidata.
- `src/Templates/`: plugin-rendered frontend routes/pages.
- `src/Elementor/` + `src/Modules/Elementor_Bridge.php`: optional Elementor dynamic tag/widget integration; gracefully absent when Elementor is unavailable.
- `assets/`: frontend CSS/JS. `assets/team-manager.js` owns the main and side team-editor behavior used on `/my-leagues/`.
- `tests/run-tests.php`: standalone domain scenarios (no WordPress runtime). Other `tests/*.php` may be operational/wp-cli scripts; inspect their headers and **never execute data-changing scripts without explicit authorization**.

Primary data concepts are normalized plugin tables (`obitleague_leagues`, league members, entries, entry revisions/picks, events, review cases, awards, standings generations and supporting tables) plus public WordPress `obit_person` posts/meta. Read `Setup.php` for the exact current schema; do not assume every old architecture diagram matches it.

## Routes, shortcodes, and page integration

### Plugin-routed pages

`Game_Pages` routes `/my-leagues/`, `/join/`, `/stats/`, `/league/{id}/`, and `/team/{entry_id}/` through plugin templates/rewrites. It also renders the campaign template at the site front page without rewriting Elementor page content and the signup template at `/register/`. `/register/` and `/verify-email/` pages are provisioned if missing by the current campaign wiring; inspect `Game_Pages::ensure_campaign_pages()` before changing page-creation behavior. Do not overwrite an existing Elementor page or its stored data.

### Shortcodes registered by the plugin

- `[obitleague_hero]` — current-season campaign-style hero.
- `[obitleague_stats]` — aggregate stats tiles.
- `[obitleague_recent_deaths count="8"]` — recent published death records; count is bounded.
- `[obitleague_standings league="" season="..." top="10"]` — standings grid for one named league or leagues in a season.
- `[obitleague_people living="1" per_page="12"]` — public people cards; `living=0` selects records with a death date.
- `[obitleague_archive]` — public archive, optional `?ob_year=YYYY` filter.
- `[obitleague_rules]` — rules and scoring explanation.
- `[obitleague_overall_standings season="..." top="0" page="1" per_page="50"]` — canonical overall standings.
- `[obitleague_join]` — optional invite-only side-league join/create UI.
- `[obitleague_register]` — dedicated account registration; email verification required for campaign accounts.
- `[obitleague_2027_campaign]` — 2027 landing campaign and non-persistent fictional scoring demo.

Registration list is derived from `Shortcodes::boot()` and `Game_Pages::boot()` plus `Auth::boot()` / `Campaign::boot()`. Confirm actual parameters in each implementation before relying on this quick list.

### Elementor

Dynamic tag group `obitleague`; tag `obitleague-person-field` supports `name`, `role`, `birth_date`, `death_date`, and `portrait_credit` for published `obit_person` posts. Widget `obitleague-league-standings` takes `league_id`; its current source still uses a filter-based rows adapter, so do not promise it renders service standings without checking. Elementor is optional for plugin data/admin/runtime.

## REST API contract (current source)

Base: `/wp-json/obitleague/v1`. Inspect `Rest::register_routes()` for exact args/methods and services before extending. Broad route groups:

- `GET /people`: public, published approved catalogue search, paginated; returns `uuid`, name/link/date/age/occupation/role and `selectable`.
- `GET /wikidata/people?search=...`, `POST /wikidata/people {qid}`: verified-account Wikidata lookup and explicit player selection/import. Validate again server-side; rate-limited.
- `POST /nominations`: verified-account human review request; does not itself approve/import a person.
- `POST /leagues`, `POST /leagues/join`: create/join optional side leagues.
- `GET|PUT /main-entry`, `POST /main-entry/submit`, `GET|PUT /entries/{id}`, `POST /entries/{id}/submit`: main/side team read, optimistic saves, submit with receipt. Saves require `expected_version`; server validates membership, deadline, pick count and catalogue eligibility.
- `GET /main-standings`, `GET /leagues/{id}/standings`: standings responses; route permission/privacy checks apply.
- `POST /reviews/{id}/decision`: reviewer/admin-only editorial decision.

Most game routes use `Auth::must_be_verified()` as the permission callback; reviewer route uses its capability check. When adding routes, define permission callbacks, sanitize/validate inputs, rate-limit abuse-prone operations, return appropriate WP REST errors, and avoid leaking whether private accounts/resources exist.

## Frontend selectors and `data-*` markers

These are JavaScript contracts between templates and assets. If changing a marker, update all consumers and keep selectors scoped to their widget/root.

### Main/side teams (`src/Templates/my-leagues.php`, `assets/team-manager.js`)

- `#build-team`: deep-link target used by campaign CTA `/my-leagues/#build-team`.
- `[data-ob-main-team]`: root; `data-rest` is REST base URL, `data-nonce` is WP REST nonce, `data-verified="1|0"` is UI hint only (server remains authoritative).
- Main editor: `[data-main-editor]`, `[data-main-name]`, `[data-main-search]`, `[data-main-results]`, `[data-main-count]`, `[data-main-picks]`, `[data-main-save]`, `[data-main-submit]`, `[data-main-message]`, `[data-main-status]`, `[data-main-edit]`.
- Each side card: `[data-side-team]` with `data-entry-id`; editor has `[data-side-editor]`, `[data-side-name]`, `[data-side-search]`, `[data-side-results]`, `[data-side-count]`, `[data-side-picks]`, `[data-side-save]`, `[data-side-submit]`, `[data-side-message]`, `[data-side-edit]`.
- Team manager uses `GET /main-entry` or `GET /entries/{id}`, public `GET /people`, Wikidata routes when local search has no results, then `PUT` save and `POST` submit. Submission/amendment behavior and verification gating come from server domain/service code.

### Campaign (`src/Modules/Campaign.php`, `assets/campaign.js`)

- `[data-demo-pick]`: fictional pick toggle; `data-age` drives sample formula, `data-name` is display text; button exposes `aria-pressed`.
- `[data-demo-score]`: total; `[data-demo-result]`: selected-pick count; `[data-demo-random]`: add random sample pick; `[data-demo-picks]`: sample list container.
- Demo is local-only/non-persistent; its hypothetical formula demonstrates `max(1,100-age)` and is not a real forecast or award.

### Other markers

- `[data-ob-nav]`: site navigation root.
- `[data-count]` on `.ob-stat__num`: animated statistic count.
- `[data-toggle]` in league detail: expandable league row UI.
- `[data-ob-join]`, `[data-ob-join-msg]`, `[data-ob-create]`, `[data-ob-create-msg]`: side league form submission UI in `Game_Pages::join_shortcode()`.

Find the authoritative consumer with a repository search before renaming any marker.

## Operational cautions

- Global WordPress registration is not the signup path; use the dedicated plugin form/verification flow. Do not enable `users_can_register` unless explicitly requested.
- Email verification uses campaign-specific account metadata and must not block pre-existing accounts; inspect `Auth.php` before altering this distinction.
- `Setup::maybe_upgrade()` applies schema changes in admin; database migrations must be additive and carefully reviewed.
- Page provisioning and demo/seed/migration scripts may change WordPress data. Do not run them against production or a shared database without explicit approval.
- Do not deploy or edit the LocalWP/live copy as part of a source-only task unless specifically requested.
- Keep unrelated user/agent changes untouched. Prefer narrowly scoped diffs, tests, and commits; do not assume multiple Freebuff threads automatically coordinate.

## Useful checks

```bash
php tests/run-tests.php
php -l src/Modules/Changed_Module.php
node --check assets/changed-file.js
git diff --check
```

The PHP domain suite does not replace WordPress integration tests. If no WP runtime is available, say so rather than claiming routes, email, page provisioning, or browser behavior were verified.
