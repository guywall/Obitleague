# Seed manifest — demo dataset (2026 season, in play)

Built 27 September 2026 for the LocalWP demo. All counts reproducible via
`work/build-seed.cjs` → `tests/demo-import-people.php` → `tests/demo-leagues.php`.

## Sources

| Dataset | Source | Licence / basis | Rows |
| --- | --- | --- | --- |
| 2026 deaths | en.wikipedia "Deaths in \<Month\> 2026" (MediaWiki API) + Wikidata entity facts (P569/P570) | Wikipedia text/CC BY-SA; Wikidata structured data CC0. Facts only: name, death date, age, role snippet, QID. | 91 |
| Living pool | Wikidata SPARQL (Q5, day-precision P569 1940–1958, occupations actor/politician, ≥20 sitelinks, no P570) | Wikidata CC0 | 60 |
| Pre-lock deaths | en.wikipedia "Deaths in December 2025" + Wikidata | as above | 3 |
| Total people imported | — | — | 154 |

Full row data: `work/seed-deaths-2026.json`, `work/seed-living-pool.json`,
`work/seed-prelock-deaths.json` (local only, not committed).

## Demo state on the site (season 2026, in play)

- 3 leagues (Winter League 2026, Office Pool 2026, Celebrity Circle 2026), 4 members each, joined through the real invite-token flow.
- 12 submitted entries, ten picks each: 8 living + 1 real 2026 death + 1 pre-lock death.
- **Backdating disclosure:** submission timestamps are demo data, backdated to December 2025 so entries count as pre-lock for the 1 Jan 2026 deadline. Nothing else in the pipeline is faked.
- 91 review cases approved through the real `Review_Service` (source basis recorded per case: Wikipedia 2026 death lists + matching Wikidata dates).
- Awards, ledger, outbox and standings generations are 100% produced by the real services.

## Integrity at build time

- events: 91 — exactly one approved death event per person
- positive awards: 12 (one per submitted team's 2026-death pick)
- duplicate awards: 0; duplicate events: 0
- standings: 3 current generations, competition ranks incl. a genuine tie
- already-dead-at-lock picks: 12 picks score zero and stay explained in their entries

## Ruleset notes for the demo

- `obitleague_first_season = 2026` (option) so season 2026 accepts awards.
- Derby Dead Pool was NOT used as a source (plan §10: no open reuse licence).
- Deaths of real people are real, sourced facts — never fabricated. All
  synthetic fixtures (Ada Testworthy, E2E users) are labelled as such.
