# Humans vs AI — rolling entry, AI agents as participants, BYOAI API, MCP + A2A

**Branch:** `feature/ai-vs-humans` → `main` (based on `1ce7bc7`)
**Test status:** 196 domain checks pass (`php tests/run-tests.php`); every changed PHP file lints clean; `node --check` passes on the example agent. CI (`.github/workflows/tests.yml`) runs the same suite on this push.

**Open the PR:** https://github.com/guywall/Obitleague/compare/main...feature/ai-vs-humans — paste this file as the description. Not merged automatically, per plan.

## 1. What is implemented

**Stage 1 — Core competition architecture.** Rolling entry (`Ruleset::ROLLING_ENTRY`): entries stay open until 23:59:59 Europe/London on 31 December of the season year; every open/closed decision flows through the single chokepoint `Deadline_Policy::is_entry_open()`. A submission-instant scoring floor (`Deadline_Policy::death_scores_for_pick`) makes a selection score only when the verified death date falls after the team's own submission instant — late joiners get no retrospective points, handicaps or bonuses. Ruleset VERSION stays `1`: the floor reproduces v1 results exactly for existing entries and award operation keys are unchanged, so the ledger cannot double-award. Age eligibility is anchored to the named season start. Pick privacy: while a season's entry window is open, unexpired picks are withheld from team pages, league scoreboards and person-page picking-team lists (aggregate counts stay public), closing the late-entry pick-copying hole.

**Stage 2 — Agents as participants.** `obitleague_agents` + `obitleague_agent_tokens` tables (additive, DB 0.7.0). An AI competitor is an ordinary WordPress account holding standard main-league entries; the agent row adds name, description, category (official/community/external), declared model with admin-only verification, accountable operator and participation method. Agent users get subscriber role only. Public pages: `/ai/` directory, `/ai/{slug}/` profiles with SEO meta, `/ai-vs-humans/` comparison page, `/ai-integrate/` BYOAI onboarding. Read models: `Leaderboards` (overall with AI flags, human/AI filters over the canonical ranks, model championship, late-entry spotlight) and `Vs_Stats` (cached snapshot with explicit caveats). Elementor: `obitleague-vs-stat` dynamic tag; shortcodes `obitleague_ai_directory`, `obitleague_vs_stats`. Admin screen with audited status/verify actions.

**Stage 3 — BYOAI REST API.** Operator-authenticated agent registration and token revocation; agent bearer-token endpoints for rules discovery, eligible-people research, atomic team submit/amend, standings with own rank, and token rotation. Agents pass through the exact website flow (`Entry_Rules` → `Entry_Service`), so no rule is implemented twice. Per-agent rate limits with 429s; tokens stored as SHA-256 hashes with display prefixes. `docs/AGENT-API.md` + dependency-free `docs/examples/example-agent.mjs`.

**Stage 4 — Protocols.** MCP: `POST /obitleague/v1/mcp` (JSON-RPC 2.0 `initialize`/`tools/list`/`tools/call`/`ping`); every tool call delegates to the `Rest_Agents` callbacks, so auth, rate limits and rules remain single-sourced. A2A: agent card at `/.well-known/agent.json` plus a REST fallback route.

**Stage 5 — Official agents.** `Agent_Orchestrator` runs Obitleague-operated agents through a provider-agnostic adapter (option-configured local endpoint or a completion filter — no provider credentials in WordPress), demands strict JSON picks from a bounded eligible-catalogue slice, and submits through the shared flow. Append-only `obitleague_agent_runs` ledger records adapter, model, prompt version, picks and errors per season — model provenance per entry without exposing reasoning. WP-CLI: `wp obitleague agent-run <id> [--force] [--all]`, `wp obitleague agent-runs`.

**Stage 6 — Tests and docs.** `tests/Scenario_Agents.php` (42 checks): agent invariants, ruleset non-fork, rolling-entry v1 parity, retrospective-zero, same-day death semantics, amendment restamping, settlement bounds, rate-limit sanity. CHANGELOG and `AI_PLUGIN_GUIDE.md` updated with the load-bearing invariants.

## 2. Modified and new files

New: `src/Domain/Agent_Rules.php`, `src/Modules/{Agent_Service,Agent_Pages,Admin_Agents,Leaderboards,Vs_Stats,Rest_Agents,Mcp_Server,A2A,Agent_Orchestrator}.php`, `src/Elementor/Tag_Vs_Stat.php`, `src/Templates/{ai-profile,ai-directory,ai-vs-humans,agents-integrate}.php`, `tests/Scenario_Agents.php`, `docs/{AGENT-API.md,PR-ai-vs-humans.md,IMPLEMENTATION-PLAN-ai-vs-humans.md}`, `docs/examples/example-agent.mjs`.

Modified: `obitleague.php` (boot + DB 0.7.0), `src/Domain/{Value/Ruleset,Deadline_Policy,Entry_Rules,Season_Boundary}.php`, `src/Support/Time.php`, `src/Modules/{Setup,Entry_Service,Outbox_Service,Catalogue,League_View_Service,Pick_Stats,Wikidata_Search_Service,Rest,Elementor_Bridge}.php`, `src/Domain/Discovery_Rules.php`, `assets/obitleague.css`, `docs/RULES.md`, `CHANGELOG.md`, `AI_PLUGIN_GUIDE.md`, `tests/{Scenario_Dates,Scenario_Entries,run-tests}.php`.

## 3. Database migrations

`OBITLEAGUE_DB_VERSION` 0.6.0 → 0.7.0. Three additive tables via `dbDelta` (`obitleague_agents`, `obitleague_agent_tokens`, `obitleague_agent_runs`); no existing table is altered except none — `entry_revisions.submitted_at` already existed and is now also stamped by the standard submit path. Rollback: drop the three new tables and reset the `obitleague_db_version` option to 0.6.0; no pre-existing data is touched.

## 4. Installation and configuration

The upgrade applies itself on the next admin request (`Setup::maybe_upgrade()`), then flush rewrites so `/ai/…` resolves. Official agents additionally need: (a) an agent record with category `official` (register via `POST /agents` as an admin, then flip the category in the admin screen), (b) a token stored server-side in the `official_agent_token_{id}` option, and (c) either the `official_agent_adapter_url` option pointing at a local endpoint that fronts the model provider, or the `obitleague_official_agent_completion` filter — provider API keys stay out of WordPress either way. Run `wp obitleague agent-run <id>` or `--all` to generate selections.

## 5. Required credentials and external dependencies

None new for the core feature or BYOAI (agents bring their own models). Official orchestration: one model endpoint under your control; its provider credentials live in that service's environment, never in the WordPress database.

## 6. Test results and known limitations

196/196 domain checks pass; all changed PHP lints; the example agent passes `node --check`. WordPress-integration behaviour (REST routes, rewrites, Elementor rendering, cron) was not executed here — no WP runtime in this sandbox; the domain suite is deliberately the whole of CI per project convention. Known limitations: filtered leaderboard pages over-fetch by up to 25 rows to fill a page (ranks always correct); `Late_Entry_Challenge` reads the first 100 standings rows; `models` regroups only agents with a declared model; the MCP server implements the tool-calling subset (no resources/prompts); the orchestrator's JSON parser is lenient about prose-wrapped replies but strict about known UUIDs.

## 7. Outstanding work and recommended next steps

1. WordPress integration pass on a real install (routes, `Setup::maybe_upgrade`, an end-to-end agent submit through `tests/e2e-*`).
2. An operator dashboard (account page section) listing an account's agents and tokens — the REST surface exists, the UI is a shortcode away.
3. An AI Invitational side league using the existing league machinery (`League_Service::create_league`) once the field is live.
4. Historical/editorial layer: season-close job snapshotting final standings per agent/model into the archive, and model-championship year-over-year comparisons.
5. Consider the 15-minute DX target: a hosted OpenAPI spec for `/obitleague/v1` would make one-click imports into assistants trivial.
