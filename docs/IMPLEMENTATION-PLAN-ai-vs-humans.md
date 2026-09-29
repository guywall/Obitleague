# Implementation plan — Humans vs AI (feature/ai-vs-humans)

Branched from `origin/main` @ `1ce7bc7` (plugin 0.14.1, DB 0.6.0). This plan was
written after auditing the current source; it identifies what is reused, what is
missing and what changes.

## Audit result — what already exists and is reused unchanged

- **Competition engine (single source of truth):** `src/Domain` pure rules +
  `Entry_Service` (optimistic saves, commit-time deadline, receipts),
  `Outbox_Service`/`Scoring_Service` (idempotent signed ledger, reversals),
  `Standings_Service` (published generations, competition ranking),
  `Overall_Standings`, `Main_League_Service` (canonical main league, one entry
  per account, auto-provisioned on registration).
- **Catalogue + eligibility:** `Catalogue::is_selectable()` (published, approved,
  no unresolved death/case, exact birth date, min age), `Pick_Stats`,
  Wikidata import routes.
- **Public API surface:** `Rest` (`/obitleague/v1`: people, entries, submit,
  standings), `Auth` (dedicated registration, email verification,
  `must_be_verified`), rate-limiting helper, error-code mapping.
- **Presentation:** plugin templates + shortcodes + Elementor bridge pattern
  (`Tag_Person_Field`, guarded widgets), design system, SEO module, mobile
  table scroll pattern.

## Key architectural decisions

1. **AI agents are another type of participant, not a separate game.** Every
   competitor (human or AI) is a WordPress user holding a standard main-league
   entry. An AI agent = an operator-owned WP account + a row in
   `obitleague_agents` + scoped tokens in `obitleague_agent_tokens`. The agent
   user gets `subscriber` role only, never admin capabilities. All scoring,
   standings and privacy code is reused untouched.
2. **Rolling entry is a versioned ruleset change (v2), not a hack.** v1 closed
   entries at 00:00 London on 1 January. v2 keeps entries open until
   23:59:59 London on 31 December of the season year. Every open/closed
   decision flows through `Deadline_Policy::is_entry_open()`, so the change is
   centralised. Age eligibility stays anchored to the season start (1 January).
3. **Submission-instant scoring floor preserves v1 results exactly.** A
   selection scores only when the verified death date is after the entry's
   submission instant (equal instant does not score). For pre-v2 entries
   (submitted before 1 January) the floor is before every scorable death, so
   historic scoring is bit-identical; award operation keys are unchanged, so
   the idempotent ledger cannot double-award.
4. **Pick privacy under rolling entry.** Submitted teams stay amendable all
   season, so picks are public only after the entry window closes (or to the
   owner/admin). Aggregate counts on person pages stay public; the list of
   *which teams* picked a person is gated to closed seasons.
5. **One API, three interfaces.** REST is the single business-logic surface;
   MCP tools and the A2A agent card map onto the same services. No protocol
   duplicates game rules.

## Stages

| Stage | Scope | Key files |
| --- | --- | --- |
| 1 | Ruleset v2 rolling entry + submission floor + privacy gate + docs | `Ruleset.php`, `Deadline_Policy.php`, `Entry_Rules.php`, `Entry_Service.php`, `Outbox_Service.php`, `Catalogue.php`, `Discovery_Rules.php`, `Wikidata_Search_Service.php`, `team-detail.php`, `League_View_Service.php`, `Pick_Stats.php`, `docs/RULES.md` |
| 2 | Agents: schema (DB 0.7.0), service, admin, public profiles, filtered leaderboards, human-vs-AI stats, Elementor tags, `/ai/` pages | `Setup.php`, `Agent_Service.php`, `Agent_Rules.php`, `Admin_Agents.php`, `Leaderboards.php`, `Vs_Stats.php`, `Game_Pages.php`, `Elementor_Bridge.php`, templates |
| 3 | BYOAI REST API (token auth, validation, submit with idempotency, standings), rate limits, `docs/AGENT-API.md`, example client | `Rest_Agents.php` (or Rest additions), `docs/AGENT-API.md`, `docs/examples/` |
| 4 | MCP endpoint (`/wp-json/obitleague/mcp`, JSON-RPC tools) + A2A agent card (`.well-known`) | `Mcp_Server.php`, `A2A.php` |
| 5 | Official AI orchestration: provider-agnostic adapter, run ledger, WP-CLI + admin trigger, ~10 configurable official agents | `Agent_Orchestrator.php`, `obitleague_agent_runs` table, WP-CLI |
| 6 | Tests (`tests/Scenario_Agents.php` + rolling-entry scenarios), lint, CHANGELOG, AI_PLUGIN_GUIDE, push, PR (no merge) | `tests/` |

## Conflicts / dependencies with other work

- Other Freebuff threads' merged work (browse filters, Discovery auto-approval,
  main-league reconstruction) is already an ancestor of the branch point; no
  rebase conflicts expected.
- Isolated-thread work on Discovery (`1a008a8`) is already in `main`. If other
  threads touch `Setup.php` (DB 0.7.0) or `Rest.php` concurrently, integrate
  theirs first — this branch touches both.

## Deliverables at completion

Implemented stages, migrations list, install/config notes, API credentials
notes, test results, known limitations, outstanding work, PR targeting `main`
(not merged).
