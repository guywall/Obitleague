# Seed manifest — demo dataset (2026 season, in play)

Built 27 September 2026 for the LocalWP demo. All counts are reproducible via
`work/build-seed.cjs` → `tests/demo-import-people.php` → `tests/demo-leagues.php`.

## Sources

| Dataset | Source | Licence / basis | Rows |
| --- | --- | --- | --- |
| 2026 deaths | en.wikipedia "Deaths in <Month> 2026" (MediaWiki API) + Wikidata entity facts (P569/P570) | Wikipedia text/CC BY-SA; Wikidata structured data CC0. Facts only: name, death date, age, role snippet, QID. | 91 |
| Living pool | Wikidata SPARQL (Q5, day-precision P569 1940–1958, occupations actor/politician, ≥20 sitelinks, no P570) | Wikidata CC0 | 60 |
| Pre-lock deaths | en.wikipedia "Deaths in December 2025" + Wikidata | as above | 3 |
| Total people imported | — | — | 154 |

Full row data: `work/seed-deaths-2026.json`, `work/seed-living-pool.json`,
`work/seed-prelock-deaths.json` (local only, not committed).

## Demo state on the site (season 2026, in play)

- 8 side-leagues (five themed leagues—singers/songwriters, guitarists, poets, the 1950 birth-year class, athletes—plus Winter League, Office Pool and Celebrity Circle). The local demo seed places all 40 synthetic demo accounts in each, creating 320 demo side-league entries; this is seed volume, not a membership cap. Real leagues have no application-level member limit and can grow with invited users.
- 40 synthetic demo accounts with clearly fictional names; team names are distinct, real-world-plausible pun names within each league. The team identity is stored on the entry, separately from its manager's account display name.
- Ten distinct sourced catalogue picks per new team: up to eight picks selected from the theme-qualifying living pool, transparently completed from other verified living catalogue entries where occupation/birth-year facts are sparse, one sourced 2026 death for scoring and one sourced pre-lock 2025 death. No pick UUID is duplicated within a team.
- **Backdating disclosure:** seeded demo memberships and submission timestamps are demo data, backdated to December 2025 so these entries count as pre-lock participants for the 1 Jan 2026 deadline. Nothing else in the scoring pipeline is faked.
- Demo users join side-leagues through the real invite-token flow. Re-running the seeder reuses the named accounts/leagues/entries, adds any missing demo memberships/submissions, updates demo team names and does not replace submitted revisions. The overall standings are global-facing; the product still needs a separately defined all-user main-season entry policy rather than treating side-league membership as the only route onto the global board.
- 91 review cases are approved through the real `Review_Service` (source basis recorded per case: Wikipedia 2026 death lists + matching Wikidata dates). Awards, ledger, outbox and standings are produced by the real services.

## 1946 living cohort (built 28 September 2026)

A second, deeper pool of *living* people to pick, restricted to the 1946 birth
year, built by `work/build-cohort-1946.cjs` and loaded with
`tests/import-seed-file.php`. The main seed's living pool is 60 rows; this adds
150 with far wider nationality and occupation spread.

| Dataset | Source | Licence / basis | Rows |
| --- | --- | --- | --- |
| 1946 cohort | Wikidata Query Service (Q5, P569 in 1946, no P570, English Wikipedia article) + Wikidata entity API (P569 precision, P570, P106/P27 labels) + en.wikipedia categories | Wikidata structured data CC0; Wikipedia category names are facts, not text | 150 |

Full row data: `work/seed-cohort-1946.json` (committed, so the cohort is
reproducible without re-running the builder).

### How the cohort was built

- **Candidates**: 11,157 living humans born in 1946 with an English Wikipedia
  article. Coverage is very uneven across birth months — January alone holds
  3,327 of them, March 712 — so a positional sample of the candidate list is
  not a random sample. The builder orders candidates by a hash of the QID.
- **Diversity**: candidates are ranked by how much each one widens the pool,
  and admitted only while their country (cap 4) and occupations (cap 3, relaxed
  to 6 in a second pass) are under their ceilings. Result: 93 countries and
  265 distinct occupations, largest single country 7, largest single
  occupation 10, birth months 9–19 each.
- **Liveness** is cross-checked twice, because a dead-pool pick has to be
  alive. A Wikidata item with no date of death is not proof of life — a person
  who died in 2022 can sit there with no P570, and the candidate query filters
  on exactly that absence. The builder therefore also reads the article's
  categories and drops anyone carrying a "⟨year⟩ deaths" category.
  `node work/build-cohort-1946.cjs --selftest` proves that check can fail, by
  running it against David Bowie (born 1946, dead since 2016) and a living
  control. 141 of the 150 additionally carry a positive "Living people"
  category; the other 9 are recorded as `alive_confirmed: false` because both
  sources are simply silent about them.
- **Dates keep their sourced precision.** The SPARQL projection renders a
  month-precision Wikidata date as `1946-01-01`, and a quarter of the
  candidates land on the 1st, which no real distribution does. Birth dates
  are rebuilt from each statement's own precision. The plugin also refuses to
  approve a person whose birth date lacks a day, so exact dates are the
  default; pass `--any-precision` to keep partial ones for manual review.

## Integrity expectations

- events: 91 — exactly one approved death event per sourced in-season death.
- each submitted entry has ten distinct picks; every pick is backed by an imported public catalogue record.
- positive awards depend on real overlaps between teams and approved death picks; no points are inserted directly.
- standings are rebuilt as current generations for all eight demo side-leagues; the overall view aggregates published league results.
- each seeded team includes a sourced pre-lock pick that scores zero and remains explained in its entry.

## Ruleset notes for the demo

- `obitleague_first_season = 2026` (option) so season 2026 accepts awards.
- Derby Dead Pool was NOT used as a source (plan §10: no open reuse licence).
- Deaths of real people are real, sourced facts — never fabricated. The synthetic accounts and team names are demo fixtures, clearly separate from real catalogue people.
