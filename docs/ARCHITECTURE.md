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
src/Modules/Catalogue.php       Person post type, taxonomies, eligibility meta
src/Modules/Rest.php            /obitleague/v1 routes
src/Modules/Jobs.php            Feed polling, refresh, scoring, notifications
src/Modules/Elementor_Bridge.php Typed dynamic tags + guarded widgets
src/Support/Options.php         Key-value store (wp_options now, plugin tables later)
src/Support/Time.php            Deadline checks, London-time helpers
tests/run-tests.php             Standalone test runner (no WordPress)
```

Planned modules for the next milestone: Feed_Polling, Review (editorial queue), Leagues, Entries, Scoring, Notifications, Import.

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
- Treat feed HTML and imported text as untrusted: escape on output, reject SSRF-prone fetches (scheme and host allowlists, no private addresses after DNS resolution or redirects, byte/time limits, external entities disabled).
- Retention baseline: 30 days raw imports, 90 days security logs, 24 months score and approval evidence.
- Index only approved public pages; league, account, draft and review routes stay out of sitemaps and shared caches.
- Performance targets to prove on the host: p95 < 800 ms server response with 50 concurrent users; score updates visible within five minutes of approval; recovery point 1 h, restore time 4 h with rehearsed restores.

## Concurrency

- Deadline: the database transaction decides; a commit that lands after `00:00` on 1 January is late regardless of request start time. Jobs run their own deadline checks; a delayed job cannot extend entry eligibility.
- Scoring: idempotent per pick/event-revision/rules-version; a retry recomputes the desired award and writes only the signed delta — replaying yields zero.
- Two editors approving concurrently: the later stale revision is rejected; the latest approved revision governs scoring.
