# Obitleague plugin guide for AI coding tasks

> **Use this file as the task handoff map, not as an independent specification.** Before changing behavior, inspect the current source and tests named below. This guide describes the checkout when written; it is not a release declaration. Do not assume that every working-tree change is committed or deployed. Refreshed against plugin version 0.14.1 (see `git log --oneline -5` to confirm how recent this is).

## Start here on every task

0. Read `START-HERE.md` for the project's git model, then `docs/GIT-WORKFLOW.md` for the delivery rules: approved work is merged fast-forward into `main` and pushed to `origin/main` under human-readable branch names and commit messages; if the user does not answer an approval request within 5 minutes, proceed only when the change is exactly as asked, all checks pass, and it is trivially reversible (see `docs/git-explainers/05-approval.md`). Plain-English explainers for every git concept live in `docs/git-explainers/`.
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

- WordPress plugin header / `OBITLEAGUE_VERSION`: `0.14.1` (`obitleague.php`); DB schema `OBITLEAGUE_DB_VERSION`: `0.6.0` (adds `wp_obitleague_wiki_queue`)
- `OBITLEAGUE_DB_VERSION`: `0.5.0` (`obitleague.php`; additive migrations in `src/Modules/Setup.php`)
- Ruleset: `Ruleset::VERSION = '1'` (`src/Domain/Value/Ruleset.php`)
- Requirements: WordPress 6.4+, PHP 8.2+ (activation blocks older PHP), MySQL 8 / MariaDB 10.6+.

These are independent version tracks. A schema change needs a safe, additive migration and DB-version update. A scoring/game-rule change needs explicit ruleset/version analysis so historic entries retain their original semantics. A product release version change must keep the plugin header and `OBITLEAGUE_VERSION` synchronized. Asset cache-busting uses `OBITLEAGUE_VERSION`.

Ruleset v1 summary: calendar-year season; deadline 00:00 Europe/London on 1 January, commits strictly before deadline; ten distinct picks; minimum age 18 at season start; points `max(1, 100 - completed_age_at_death)`; tie-break on scoring picks then competition ranking; settlement through 23:59:59 Europe/London on 31 January following the season. See `docs/RULES.md`, `src/Domain/Value/Ruleset.php`, `Age.php`, `Deadline_Policy.php`, `Entry_Rules.php`, `Scoring.php`, and `Ranking.php` for the operative details. Submitted teams may be amended until the entry deadline in the current implementation; each competing revision is preserved. Check the current domain code/tests because prose in older files may lag.

## Architecture map

- `obitleague.php`: constants, PSR-4-ish `Obitleague\` autoloader, activation/deactivation hooks, module boot.
- `src/Domain/`: pure game logic/value objects; avoid WordPress dependencies here. Includes `Discovery_Rules.php` (Wikidata/Wikipedia living-person screening), `Feed_Classifier.php`, `Wordcloud.php` (word-frequency clouds + cosine similarity; used by the death wire for same-name disambiguation) alongside the scoring/ranking/entry rules.
- `src/Modules/`: WordPress adapters and services.
- `src/Templates/`: plugin-rendered frontend routes/pages.
- `src/Elementor/`: optional Elementor dynamic tags/widgets; gracefully absent when Elementor is unavailable.
- `assets/`: frontend CSS/JS (see the marker contracts below).
- `tests/run-tests.php`: standalone domain scenarios (no WordPress runtime). Other `tests/*.php` are operational/wp-cli scripts; inspect their headers and **never execute data-changing scripts without explicit authorization**.
- League layout: one canonical main league ("Overall League {season}", `Main_League_Service`, `is_main = 1`) carries the whole competition; side leagues are off by default — the seeder's `$league_specs` (commented out) re-enables them idempotently. `tests/consolidate-main-league.php` migrates a real user's latest submitted side entry into the main league; `tests/retire-side-leagues.php` deletes side leagues (entries, standings, memberships) for a season, keeping users and main entries intact. The seeder reads seed pools from `work/seed-*.json`, which deploys wipe — re-copy from the repo checkout before re-seeding production.
- `work/` is git-ignored seed material; `work/build-seed.cjs` and `work/build-cohort-1946.cjs` fetch from Wikipedia/Wikidata before `tests/import-seed-file.php` can load anything.

Module boot order (`plugins_loaded`, priority 5): admin-only `Setup::maybe_upgrade()` + `Admin_Theme`, then `Catalogue`, `Occupation_Taxonomy`, `Admin_Review`, `Discovery_Service`, `Wiki_Request_Queue`, `Admin_Discovery`, `Admin_Game`, `Admin_Stats`, `Demo_Accounts_Admin`, `Front_Templates`, `Site_Chrome`, `Header`, `Season_Switcher`, `Forum`, `Seo`, `Shortcodes`, `Game_Pages`, `Jobs`, `Rest`. `Elementor_Bridge` boots at priority 20; `HeaderBridge` on `elementor/loaded`. `Main_League_Service::on_user_register` hooks `user_register` so every new account receives a main-season entry.

Key modules:

- Game core: `League_Service` (create/join/invites), `Entry_Service` (drafts, submission receipts, stale/lock races), `Review_Service` (editorial state machine), `Outbox_Service` (award fan-out and reversals), `Scoring_Service` (award ledger), `Standings_Service` + `Overall_Standings` (published generations, keyset-paged rebuilds), `Jobs` (feed polling, outbox tick, scheduled standings rebuilds).
- Catalogue: `Catalogue` (`obit_person` post type, `obit_occupation` taxonomy, eligibility meta, `is_selectable()`), `People_Sync` (portraits P18 + occupations P106 from Wikidata — enqueued through `Wiki_Request_Queue` as `enrich_person` requests, never fetched inline; the only writer of the occupation taxonomy), `Import_Service` (feed/people imports; keeps role text as `obit_occupation_hint`), `Wikidata_Search_Service` (player-initiated lookup/import), `Person_Content` (composed body prose), `Occupation_Taxonomy` (orphan-term pruning), `Pick_Stats` (how often a name was taken).
- Front end: `Game_Pages` (routes/shortcodes/page provisioning), `Front_Templates` (person/archive templates), `Site_Chrome` (footer, fonts, motion — the navigation bar comes from `Header`), `Header` + `HeaderIntegration` (site header, see below), `Forum`, `Auth` (registration/email verification), `Campaign` (2027 landing demo), `Seo` (titles, meta, JSON-LD, crawl control).
- Admin: `Admin_Review`, `Admin_Discovery`, `Admin_Data_Sources` (per-source sync controls and Wikimedia-queue visibility: pending rows with next-due times, rate-limit pauses, enrichment backlog, drain/retry/clear-pause controls, per-feed poll-now), `Admin_Game`, `Admin_Stats` (read-only statistics), `Admin_Theme`, `Demo_Accounts_Admin`.- Death wire: `Death_Wire` (RSS story matching + Wikipedia "Deaths in <season>" reconciliation, hourly tick, handlers through `Wiki_Request_Queue`) and `Admin_Death_Wire` (dashboard: overview/stories/phrases/sources). The wire runs itself end to end. Stories below the **adjustable auto-discard threshold** (`Death_Wire::discard_threshold()`, option `obitleague_death_wire_discard_below`, 0–95, default `DISCARD_BELOW` 50; set on the dashboard's overview tab) are marked `discarded` without human attention; `wp obitleague death-wire-tidy-pending [--dry-run] [--threshold=N]` reclassifies and discards the backlog of never-swept pending stories. Wikipedia lookups are smart and cached: `Death_Wire::wiki_article( $title )` fetches wikitext AND the Wikidata QID in one `prop=revisions|pageprops` request, transient-cached 2 h (negative results too, `obit_wikiart_` prefix); `wiki_search_title()` (`obit_wikisearch_`) caches only definitive answers — a hit or a genuine empty result (`Domain\Wire_Search::interpret()`); a transport error, 429/503 or malformed body returns a `WP_Error`, is never cached, and parks the story as `search_deferred` for the next sweep to retry. `article_text()` (`obit_article_`, 12 h) caches misses as well. Same-name candidates (several records sharing one `obit_enwiki` value) are disambiguated by wordcloud overlap — the story's text (title + excerpt, falling back to cached article text) vs each record's stored `obit_wiki_cloud` postmeta (JSON word cloud of the article wikitext, written on wire checks) — via `Wordcloud::best_match()` with a `MATCH_FLOOR` of 0.08; below-floor ambiguity stays flagged for a human. Wire-confirmed records self-enrich: the QID is stamped and `People_Sync::enqueue_person()` queues portrait/occupation/birth-date fill-in. `People_Sync::apply_entity()` also fills an absent `obit_birth_date` from a day-precision P569.- Discovery: `Discovery_Service` (living-person candidate queue). Candidates are **auto-approved** from the stored record alone (`auto_approve_candidate()`: no death date on the stored record → publish immediately; no network call, no editor session needed — a system publication scope with reviewer 0 is used). The live liveness cross-check (`verify_current_candidate()`) is never inline for approvals: it is enqueued as a `discovery_recheck` request in `Wiki_Request_Queue` and runs when the queue drains; transient failures park and retry (max 5 attempts), a death signal demotes the record, identity drift only flags it. Manual editor approval still runs the fresh check inline via `approve_candidate()`. `Wiki_Request_Queue` is the global stored-request queue for outbound Wikimedia calls. Requests carry a `source` (wikidata/enwiki/commons) and a `next_attempt_at` timestamp: the queue runs a row only when it is due, spaces same-source requests by a minimum interval, and a rate-limit response parks that source's `next_attempt_at` at the Retry-After moment — retries keep that target until the source hits a limit again (see `Wiki_Request_Queue::defer_source()`). Requests enqueued during a pause stay pending and run serially from the `obitleague_wiki_queue_tick` cron (every minute, batch of 3) once their source is due. People-Sync enrichment defers batches to this queue instead of failing during cooldowns, and the daily `obitleague_profile_refresh` hook re-enqueues every person still missing a portrait or occupations (`People_Sync::enqueue_missing()`; also `wp obitleague sync-people`), so gaps self-heal instead of silently persisting. Automatic queue drains bypass the hourly manual-run discovery limit. The tick is armed in `Jobs::boot()` and `Setup::activate()`, and unscheduled on deactivation. Batches are **random sampling**: each run draws up to six distinct birth-month windows from the eligible span (1946 → season − 18) and imports up to 50 new candidates (`BATCH_LIMIT`), so the catalogue self-populates with a random mix of popular and obscure people; there is no cursor and no ordering guarantee by design. Manual batch starts are limited to 4 per rolling hour (`HOURLY_RUNS`); automatic queue drains bypass that. The old one-batch-per-day cursor walk (`DAILY_OPTION`, `CURSOR_OPTION`, `advance_cursor`) was removed. WP-CLI: `wp obitleague discovery` (one batch, `--limit=1..50`), `wp obitleague discovery-approve-pending` (approve all passing stored-record checks now), `wp obitleague sync-people` (enqueue enrichment for people missing portraits/occupations).

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
- `[obitleague_people living="1" per_page="12"]` — public people cards; `living=0` selects records with a death date (query string `?living=` wins over the att). Full browse toolbar: free-text search `?q=`, sort `?sort=name|most_picked|newest|oldest` (most-picked sorts the whole set via `Pick_Stats::pick_counts_by_uuid()`), letter jump-to `?letter=A`, windowed pagination `?paged=` (canonical `/page/N/` URLs work), plus `?occupation=`, `?birth_year=`, `?age=` chips. Search/letters/sort run on derived meta `obit_sort_name` (lowercased title, leading article stripped) and `obit_birth_year_num` — computed on save via `People_Sync::boot()`, backfillable with `wp obitleague backfill-sort-meta`. The header search (`?s=` on the people page) redirects to `?q=` (WordPress would otherwise hijack it into the 404 search template). `[obitleague_archive]` (death archive) shares the pager, search, and letters, with its own year dropdown.
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
- `[data-ob-header]`: `Header`/`Widget_ObHeader` mega-menu header; `assets/header.js` handles the mobile toggle and the `.ob-header__item` mega menus via `[data-ob-mega]` (open on hover/focus, click-to-open under 1024px). The brand badge shows the in-play year. Season switching lives where it matters: `Season_Switcher::render_toggle()` prints a segmented year toggle (standings/stats/overall pages) and a validated `?season=` drives every competitive view (standings, overall, stats, hero); unknown values fall back to the in-play year. Register CTA still pitches the entry season.

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
- Tag `obitleague-vs-stat` exposes the Humans-vs-AI snapshot (team counts, points, averages, highest team, leaders) from `Vs_Stats`; it renders nothing when the field is unknown and never rescores anything.
- Widget `obitleague-league-standings` takes `league_id`; its current source still uses a filter-based rows adapter (`obitleague_league_standings_rows`), so do not promise it renders service standings without checking. Membership is guarded on every render.
- Widget `obitleague-ob-header` renders the mega-menu header; registered by `HeaderBridge` with an `obitleague-header` document type.
- Elementor is optional for plugin data/admin/runtime, but pages authored as Elementor documents (including seeded demo pages) render empty until Elementor is installed and active.

## Humans vs AI (feature/ai-vs-humans)

- **AI agents are participants, not a separate game.** The agent's competitor identity is its linked WordPress user (`obitleague_agents.user_id`); entries, awards and standings attach to that user through the standard services. Never write agent-specific scoring, eligibility or deadline logic.
- **Rolling entry:** entries stay open until `23:59:59 Europe/London` on 31 December of the season year (`Ruleset::ROLLING_ENTRY`, `Deadline_Policy::entry_deadline()`). Ruleset VERSION remains `1`; the `Ruleset::VERSION = '1'` string and the scoring formula are load-bearing for ledger idempotency — a scoring-rule change requires a new version and full replay analysis, not an edit.
- **Scoring floor:** `Deadline_Policy::death_scores_for_pick(season, submitted_at)` — deaths strictly before `max(season_start, submitted_at)` are skipped in `Outbox_Service::award_event()`. Deaths are dates (midnight), so a death dated the submission day does not score for a team that submitted later that day. `Entry_Service::submission_floor()` reads `submitted_at` from the competing revision; pre-stamp revisions fall back to season start, which is exactly v1 behaviour.
- **Pick privacy:** while a season's entry window is open, team pages (`team-detail.php`), league scoreboards (`League_View_Service::scoreboard()`) and the person-page picking-team lists (`Pick_Stats::for_person()`) withhold picks from everyone but the owner/admin. Aggregate counts stay public. Do not add new public surfaces that render `Entry_Service::revision_picks()` without the same gate.
- **Agents:** `Agent_Service` (register/metadata/tokens), `Rest_Agents` (bearer-token API; the only place agent requests authenticate), `Mcp_Server` and `A2A` (thin adapters over `Rest_Agents`), `Agent_Orchestrator` (official agents; appends to `obitleague_agent_runs`, never overwrites). Agent users are subscriber-only; tokens are SHA-256 hashed with a display prefix. Rate limits live in `Agent_Rules::rate_limits()` (WordPress-free constants).
- **Admin:** the AI agents screen (`Admin_Agents`, under the Obitleague review menu) audits activate/suspend/retire/verify-model into `obitleague_admin_audit` with `object_type='agent'`.

## Operational cautions

- Global WordPress registration is not the signup path; use the dedicated plugin form/verification flow. Do not enable `users_can_register` unless explicitly requested.
- Email verification uses campaign-specific account metadata and must not block pre-existing accounts; inspect `Auth.php` before altering this distinction.
- `Setup::maybe_upgrade()` applies schema changes in admin; database migrations must be additive and carefully reviewed.
- Page provisioning and demo/seed/migration scripts may change WordPress data. `tests/demo-*.php`, `tests/import-seed-file.php`, `tests/migrate-main-entries.php`, `tests/sync-portraits.php`, `tests/build-person-content.php`, and the `verify-*` scripts are wp-cli/eval-file operations — do not run them against production or a shared database without explicit approval. `tests/e2e-*.php` exercise live flows on a real install.
- Do not deploy or edit the LocalWP/live copy as part of a source-only task unless specifically requested. Deployment is guarded by `deploy-live.sh` and requires explicit user direction.
- The legacy `ob-nav` header was removed in favour of the single `ob-header` system. Keep `assets/header.min.js` in sync with `assets/header.js`.
- Keep unrelated user/agent changes untouched. Prefer narrowly scoped diffs, tests, and commits; do not assume multiple Freebuff threads automatically coordinate.

## Useful checks

### Git delivery checklist (before every push)

1. Rename any auto-generated session branch to `<scope>/<short-topic>` (e.g. `feat/git-workflow-rules`); no UUIDs in shared names.
2. One logical change per commit; subject line imperative, ≤ 72 chars, no `fix:`-style prefixes; body explains what and why.
3. Run the checks listed below; do not push with failures.
4. `git merge --ff-only <branch>` into `main`, then `git push origin main`; never force-push.
5. Delete the session branch (local and remote) after a successful push.
6. If the process itself changed, update `docs/GIT-WORKFLOW.md`, `START-HERE.md`, and the relevant file in `docs/git-explainers/` in the same change.

```bash
php tests/run-tests.php
php -l src/Modules/Changed_Module.php
node --check assets/changed-file.js
git diff --check
```

The PHP domain suite does not replace WordPress integration tests. If no WP runtime is available, say so rather than claiming routes, email, page provisioning, or browser behavior were verified.
