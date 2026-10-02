# Architecture

## Principles

1. One custom plugin owns all game data. Elementor + PRO Elements is the presentation layer and binds through typed dynamic tags and guarded widgets; it never re-implements eligibility or scoring.
2. Domain services are the only place decisions are made. REST handlers, widgets and jobs call them; they are the implementation, the contract and the test surface.
3. Reversibility: every score change is a signed ledger row; standings rebuilds produce new published generations; corrections are visible.
4. Privacy: teams are private until lock. Membership and ownership are re-checked on every render and REST call, never trusted to Elementor display conditions or page caching.
5. The discovery pipeline never publishes a death or awards points by itself — it only creates review candidates.

## Plugin structure

```
obitleague.php                  Bootstrap, autoloader, activation, module boot
src/Domain/                     Pure rules engine (no WordPress calls)
src/Domain/Value/               Immutable value objects
src/Domain/Wire_*.php           Death-wire decision rules (headline, wikitext, dates, score, identity, search, pause)
src/Modules/Death_Wire.php      Death-wire orchestration + public facade
src/Modules/Wikimedia_Client.php Outbound Wikidata/Wikipedia reads (search, article+QID, SPARQL, pause)
src/Modules/Catalogue.php       Person post type, taxonomies, eligibility meta
src/Modules/Rest.php            /obitleague/v1 routes
src/Modules/Jobs.php            Feed polling, refresh, scoring, notifications
src/Modules/Elementor_Bridge.php Typed dynamic tags + guarded widgets
src/Modules/Seo.php            Titles, meta, Open Graph, Person schema, noindex
src/Modules/Person_Content.php Composed body prose for person records
src/Modules/Pick_Stats.php     How often a name was taken, and by whom
src/Modules/Occupation_Taxonomy.php  Prunes occupation terms orphaned by a person deletion
src/Domain/Value/Role_Label.php Cleans unsourced role text of leaked causes
src/Support/Options.php         Key-value store (wp_options now, plugin tables later)
src/Support/Time.php            Deadline checks, London-time helpers
tests/run-tests.php             Standalone test runner (no WordPress)
```

Modules: Catalogue, Jobs (feed polling + outbox tick), Rest, Elementor_Bridge, League_Service (create/join/invites), Entry_Service (drafts, submission receipts, lock races), Review_Service (editorial state machine, stale-protected decisions, event publication), Outbox_Service (award fan-out, retraction reversals), Standings_Service (published generations with competition ranking), Scoring_Service (idempotent award ledger), Seo (titles, meta, Open Graph, Person schema, crawl control), Person_Content (person page body, composed from approved fields only), Occupation_Taxonomy (prunes occupation terms orphaned by a person deletion), Setup (migrations). Planned next: Notifications (in-app/email), Import (Wikidata seeding).

## Data model (target)

Authoritative storage is normalised InnoDB tables created by versioned migrations; the WP `posts` table carries only the public person records.

| Area | Storage | Notes |
| --- | --- | --- |
| Person | `wp_posts` (`obit_person`, public) + identity/evidence tables | Stable internal UUID; QID as preferred external key; aliases in a separate table. |
| Identity | Plugin table: UUID, QID, Wikipedia page ID, canonical title, redirects | Names are searchable attributes, not identifiers. |
| Evidence | Plugin table: claim, source URL, origin group, publisher, retrieved/approved timestamps | Death, date and cause are separate decisions. |
| League | Plugin table: owner, invites (hashed tokens, expiry), membership, state | Private by default. |
| Entry | Plugin tables: entry, revision, pick — keyed to member, league, season | Revision on every save; the submitted revision competes. |
| Award ledger | Plugin table: pick, event, season, ruleset, signed delta, operation key, reason | Idempotent via unique operation key. |
| Standings | Generated, published generations | Rebuild → new generation → swap into use. |

## REST contract (v1)

Base: `/wp-json/obitleague/v1`

| Route | Contract |
| --- | --- |
| `GET /people` | Published searchable profiles, paginated; stable IDs and eligibility state. |
| `GET /people/{id}` | Approved profile and public evidence only. |
| `POST /leagues`, `/leagues/join` | Authenticated create or token join; rate-limited; duplicate join is idempotent. |
| `GET/PUT /entries/{id}` | Owner access; save requires `expected_version`; deadline and membership revalidated in the transaction. |
| `POST /entries/{id}/submit` | Ten valid unique picks, fixed rules version, server timestamp, receipt; accepts idempotency key. |
| `GET /leagues/{id}/standings` | Members only; published generation and update timestamp; pick detail respects pre-lock privacy. |
| `POST /nominations`, `/disputes` | Validated URLs and bounded text; no automatic import or approval. |
| `POST /reviews/{id}/decision` | Admin; capability check, expected revision, field decisions, evidence, reason. |

Error mapping: 400 invalid input, 401 unauthenticated, 403 forbidden, 404 unavailable private resource, 409 stale revision or locked entry, 422 invalid selection, 429 throttled.

## Discovery pipeline

Poll (conditional GET, source locks, GUID dedup) → normalise → deduplicate by GUID/URL + text similarity (one origin group per syndicated story) → classify (death wording vs anniversaries/hoaxes/mourning) → resolve identity (aliases, QID, namesake disambiguation) → extract claims (death, date, age, cause as separate proposed claims with sources) → editorial review → publish approved revision + outbox event → scoring workers.

Editors approve a death only with two editorially independent reports (or an authentic official statement) — origin groups stop syndicated copies counting twice. Date and cause are separate decisions with their own status labels.

## Privacy, security, performance

- Check ownership, membership and capabilities on every request, export and background job.
- Crawl control: `Seo` marks account, team, league and forum routes `noindex, follow`, and marks an occupation archive `noindex, follow` when nobody is filed under it — terms outlive the people assigned to them, and an empty archive can only say "0 people". The person catalogue, populated occupation archives and public standings stay indexable. Structured data uses exact stored dates only, so a partial date is never widened into a stronger claim.
- Person page bodies: `Person_Content` composes the body of a person record from approved fields alone. It is a formatter, not an author — nothing about a person's life is inferred or invented, dates keep their sourced precision, and the wording is neutral and pronoun-free. Because the body is a pure function of the record it is safe to regenerate at any time, and it is regenerated on approval and on every editorial death decision. Stored bodies never carry a live figure that would go stale.
- Pick counts: `Pick_Stats` answers how many submitted teams hold a name, its
  share of the field, its rank, and who took it. Three rules make the figures
  trustworthy. Only the current `kind='submitted'` revision of each entry is
  counted, so an amended team is never counted twice. Figures are scoped to the
  season in play, because a player holding entries in two seasons would
  otherwise count as several teams. And the season is single-sourced with the
  page's own scorecard, so a page cannot label its figures with one season and
  its points with another. The ranking aggregate is far too slow to run per page
  view, so the distribution is cached and flushed on submit and on amendment; a
  short TTL is a safety net, not the strategy.
- One writer for the occupation taxonomy: `People_Sync`, from Wikidata P106 (CC0). `Import_Service` deliberately does not write it. The taxonomy is public and indexable, so each term is a permanent archive URL, and feed role text is free-form, unsourced and inconsistent between extractors — importing it produced duplicate archive pages for occupations that already existed as clean terms, and let a `Public figure` placeholder become a public page. Feed occupations are stored as `obit_occupation_hint` for the review queue. A person whose sync fails ends up with no occupation tag rather than a wrong one, which is the correct direction to fail.
- One resolved cause of death, and an editorial decision always wins: `People_Sync` brings Wikidata's P509 in as a confirmed fact the first time a record is enriched, because a cause of death legible to anyone reading the page is a stated fact about a real person, not an opinion the catalogue should withhold. A record's cause is settled once — `obit_cause_source` records how (`wikidata-P509`, `editor`, or `none` when Wikidata carries no claim) — so the daily sweep retries a gap that is still open while a closed one is never re-litigated. The review decision and the person meta box both stamp `editor`, so a human ruling that a cause is not disclosed can never be overwritten by a later enrichment pass.
- Enrichment works newest-first and settles what it has read: the sweep takes records it has never fetched before the standing backlog, newest first, so a page a visitor has just landed on is filled in on the next run rather than days behind a queue that grows with every death. A successful fetch stamps `obit_enriched_at` whatever it returned — a portrait or occupation Wikidata simply does not hold is a checked gap, not an open one — and a settled record is left alone for 30 days, so a permanently absent field cannot occupy the top of every run and starve the rest. The Data-sources screen reports that backlog (how many published people still need work, and how many are queued) so a drained queue never reads as an empty backlog.
- One remover, and only of unreferenced terms: `Occupation_Taxonomy` hooks `before_delete_post` and `deleted_post`. Core removes a deleted post's term relationships partway through `wp_delete_post()` and never touches the terms, so the terms are captured before the record goes and pruned after, when they have lost their last reference. The liveness test is the number of rows in `wp_term_relationships`, never the cached `count` on the term taxonomy row, and a term is only removed when that count is zero — so a term any other object is still filed under is always kept. `tests/prune-orphan-occupation-terms.php` calls the same `prune_term()` for historical residue, so there is one definition of when a term may be deleted. Custom post types get no trash protection in `wp_delete_post()` (its short-circuit covers only `post` and `page`), so an `obit_person` record always takes the permanent-delete path.
- One descriptor per person: `Role_Label::clean()` is the single gate between the stored role and anything public. Feed extraction sometimes appends a cause of death to an occupation, and that string previously reached the byline, the meta description and the JSON-LD `jobTitle`. All of them now read the cleaned value, so they cannot disagree with each other or with the reported cause.
- Treat feed HTML and imported text as untrusted: escape on output, reject SSRF-prone fetches (scheme and host allowlists, no private addresses after DNS resolution or redirects, byte/time limits, external entities disabled).
- Retention baseline: 30 days raw imports, 90 days security logs, 24 months score and approval evidence.
- Index only approved public pages; league, account, draft and review routes stay out of sitemaps and shared caches.
- Performance targets to prove on the host: p95 < 800 ms server response with 50 concurrent users; score updates visible within five minutes of approval; recovery point 1 h, restore time 4 h with rehearsed restores.

## Concurrency

- Deadline: the database transaction decides; a commit that lands after `00:00` on 1 January is late regardless of request start time. Jobs run their own deadline checks; a delayed job cannot extend entry eligibility.
- Scoring: idempotent per pick/event-revision/rules-version; a retry recomputes the desired award and writes only the signed delta — replaying yields zero.
- Standings: a rebuild is a single transaction that publishes a new generation. The award aggregate is computed once into a temporary table, then paged with a keyset over the full sort tuple `(points, scoring_picks, user_id)` so no team is skipped or inserted twice. A failed batch aborts the rebuild rather than publishing a short generation.
- Two editors approving concurrently: the later stale revision is rejected; the latest approved revision governs scoring.
