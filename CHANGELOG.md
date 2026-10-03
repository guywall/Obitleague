# Changelog

All notable changes to the Obitleague plugin. Versions follow the plugin
header in `obitleague.php`; each released version is tagged in git.

## [Unreleased]

### Fixed — The first minute: the search lands, and the hero says when entries close

- **The header search now puts the caret in the box.** The field is
  `visibility: hidden` until it opens, and a hidden element cannot take focus,
  so the opening click left the user to click the field a second time. The
  opening click now retries the focus across the first frames of the transition,
  so the caret lands in the box. On phones the field also lost a stray 30px indent —
  the desktop auto-margin leaked into the stacked menu sheet — and it is wide
  enough that the "Search people" placeholder is no longer clipped.
- **The homepage hero states the deadline.** With entries now closing at the end
  of the year before the season, a first-time visitor had no way to learn when to
  act. The hero carries one quiet line — "Entries for 2027 are open now, and
  close at 23:59 London time on 31 December 2026." — derived from the same
  deadline policy the game enforces, so it cannot drift from it; once the window
  has closed it says so instead.

### Changed — The person page talks about the person, not the game

- **The raw scoring formula no longer appears anywhere a reader or an agent can
  see it.** `max(1, 100 − age)` is still the load-bearing rule (and stays in
  `docs/RULES.md` and `Ruleset::points_for_age()`), but it is not easy to read
  and was never meant to be shown to players. Every rendered surface now
  describes scoring in plain words: the person scorecard tooltips, the hero
  copy, the stats board note, the campaign demo, the review screen, the agent
  API (`points_formula`) and the A2A card. A wiring guard fails the suite if the
  formula reappears on any of those surfaces.
- **Bodies no longer editorialise about the website.** `Person_Content` composes
  a person's body from approved fields alone. The lines that talked about the
  catalogue, the game, scoring and "what you could compare" are gone; the body
  states the name, life span, recorded death date and age, the recorded
  occupations, and the cause in the plugin's own terms.

### Changed — One league, and a season that closes the year before it starts

- **Side leagues are off by default.** The product is one canonical league.
  The side-league machinery is kept intact but invisible unless an operator
  opts back in with the `obitleague_side_leagues_enabled` option
  (`League_Service::side_leagues_enabled()`, default false). While off, `/join/`
  redirects to `/my-leagues/`, `[obitleague_join]` renders nothing, `POST
  /leagues` and `POST /leagues/join` are not registered, and the My-leagues and
  footer join CTAs and the admin create form are hidden.
- **Entries now close before the season begins.** Rolling entry is replaced by
  the locked window: entries for season S open at `00:00 Europe/London` on
  1 January S−1 and a write must commit strictly before `23:59:59 Europe/London`
  on 31 December S−1, while the season runs 1 January – 31 December S. Because
  every valid entry commits before the season starts, the scoring floor is the
  season start for every entry, so historic results are unchanged and the
  ruleset version stays `1`.
- **The header search collapses to a magnifier.** On desktop the field expands
  from the icon (`[data-ob-search]`), keeping the top bar clean; without JS the
  field stays visible, and on phones it remains the field in the menu sheet.
  Every primary item now opens a mega panel — Standings and Stats gained panels,
  so all five items behave alike. `Widget_ObHeader` mirrors the markup and
  `header.min.js` is regenerated in sync.

### Fixed — The person body always lists recorded occupations

- **A person's body dropped its occupations paragraph when the taxonomy mirror
  lagged the stored list.** `Person_Content` read the `obit_occupation` terms
  alone, while the rest of the page reads the recorded `obit_occupations` list.
  A record enriched before the term backfill ran showed its occupations in the
  hero but stayed silent about them in the body. Labels now come from
  `Person_Content::bio_occupation_labels()` — the stored list first, the taxonomy
  only as a fallback — and still link to the term archive when a term exists.

### Fixed — The story modal keeps your place, and pairing actually appears

- **The Wikidata pairing widget never rendered.** The block in
  `Admin_Death_Wire::ajax_story_detail()` was gated on `$state`, but that
  variable was not assigned until the actions row *below* it, so the first read
  was the gate itself. PHP evaluated the undefined variable as null, the
  comparison was never true, and "Pair to a person" was skipped on every
  story — the feature shipped inert. `$state` is now read from the story once,
  before the widget, and the later duplicate assignment is gone. Lint and the
  wiring suite both miss this class of bug: they check that callbacks resolve,
  not that a variable is in scope where it is read.
- **Reviewing a story threw you back to the Overview tab.** The modal's action
  buttons are rendered inside the admin-ajax response, where the page query
  string is absent, so `action_button()` wrote `tab=overview` and dropped the
  state filter; the pairing form sent neither. Both now carry the editor's
  `tab` and `state` — the JS forwards them from the page URL — and
  `current_tab()`/`current_state()` read POST before GET, so publishing,
  re-running, deleting and pairing all return you to the filtered list you were
  working through instead of the dashboard.

### Changed — The enrichment sweep works newest-first and its backlog is visible

- **Newly published people are enriched before the standing backlog.** The daily
  sweep picked people with `ORDER BY p.ID ASC`, so it always began at the oldest
  record; every new death from the wire got the highest ID and landed behind the
  whole queue, drifting further back as new deaths arrived. Mighty Sparrow —
  published from the wire — had never been fetched at all: Wikidata held four
  occupations and a portrait, and neither had ever been asked for. The sweep now
  puts records it has never checked first, newest first, so a page a visitor has
  just landed on is filled in on the next run.
- **A settled record is no longer re-fetched every day.** Enrichment stamps
  `obit_enriched_at` on every successful fetch, whether or not Wikidata had
  anything to give: a person whose portrait the source simply does not hold is a
  *checked* gap, not an open one. The sweep skips records settled within
  `SETTLE_REFRESH_SECONDS` (30 days), so a permanently absent field cannot
  occupy the top of every run and starve the rest — while a field added on
  Wikidata later is still picked up.
- **Data sources shows the backlog, not just the queue.** With the queue drained
  the page read as "nothing to do" while most published people were missing a
  portrait, occupations, a role or a cause. It now reports "N of M still need
  enrichment · queued right now · waiting for the next sweep" and lists the
  backlog itself (up to 50, newest first) with each record's remaining gaps,
  whether it is already queued, and when it was last enriched.
  `People_Sync::enrichment_backlog()` and `enrichment_backlog_sample()` build on
  the sweep's own query, so the view and the sweep can never disagree about who
  needs work.

### Added — Pair an identity-mismatch story to a person from Wikidata

- **The modal now offers a way to act on a story the wire could not place.**
  An `identity_mismatch` story is one whose headline the wire could not tie to
  a Wikipedia article, so the queue offered only "Re-run the wire match" or
  "Delete story". The story modal now carries a pairing widget on exactly
  those stories: it pre-fills a Wikidata search with the headline's extracted
  name group (still editable), lists human candidates — including the
  deceased, because the subject of an obituary is dead by definition — and
  imports the record the editor picks as a provisional one, with a review case
  opened, so the same confirmation rules that govern every wire import still
  apply. Birth and death come from the article wikitext first and fall back to
  Wikidata's P569/P570, the story is attached as a source and settled to
  `attached` so the queue does not reprocess it, and when nothing matches the
  existing Delete action still dismisses the story. `Wikidata_Search_Service::obituary_search()` is the
  counterpart to `search()`: it deliberately keeps P570 bearers and hides QIDs
  that are already imported, so the pairing list never offers a person the
  catalogue already holds.

### Fixed — A request the queue refused to store is no longer counted as queued

- **Enrichment gaps stopped being invisible.** `People_Sync::enqueue_missing()`
  counted a person as enqueued for every one it *tried* to queue, so when the
  live queue table was missing the `source` and `next_attempt_at` columns every
  insert failed and the run still reported success — 708 requests, none stored,
  and no signal that anything was wrong. The counter now follows
  `Wiki_Request_Queue::enqueue()`'s return value; a `failed` figure lands on the
  run and is surfaced in the admin notice and `wp obitleague sync-people`, and
  the daily refresh re-attempts the gaps that are still open.
- **The queue's pacing timestamp is a datetime again.** `next_available_time()`
  added the per-source gap to a numeric *cast* of the last stored timestamp, so
  `'2026-10-02 11:31:24'` became `2026`, the candidate came back as the bare
  year `'2027'`, and MySQL stored a zero date — which reads as due at once, so
  the per-source spacing the queue promises was silently discarded. The
  arithmetic moved to the pure `Wire_Pause::next_attempt_at()`, which parses
  datetimes properly and tolerates the zero dates earlier writes left behind.

### Fixed — The cause of death Wikidata states is brought in

- **A disclosed cause was reported as undisclosed.** Nothing read Wikidata's
  P509, so a person whose cause of death is a matter of public record still
  rendered "cause of death has not been publicly disclosed". Enrichment now
  resolves P509 to its English label and records it as a confirmed cause the
  first time a record is processed. An editorial decision always wins, and
  `obit_cause_source` marks a record resolved — `wikidata-P509`, `editor`, or
  `none` when Wikidata has no claim — so the sweep retries a gap that is still
  open without re-litigating one that is closed. `enqueue_missing()` sweeps
  records with no resolved cause alongside those missing a portrait,
  occupations or role.

### Changed — The death wire's decisions are domain rules, its HTTP is a client

- **One 1760-line file became orchestration.** `Death_Wire` owned headline
  matching, identity, wikitext reading, HTTP, queue handlers and CLI at once.
  The decision logic now lives in pure `Domain` classes it delegates to —
  `Wire_Headline` (headline → subject), `Wire_Wikitext` (death year, birth
  year, exact death date), `Wire_Dates` (Wikidata date precision, month
  walking) and `Wire_Score` (the 0–95 likelihood) — alongside the
  `Wire_Identity`, `Wire_Search` and `Wire_Pause` rules already extracted.
  The outbound Wikidata/Wikipedia reads (title search, article + QID, WDQS
  SPARQL, and the shared rate-limit pause) moved to `Modules\Wikimedia_Client`.
  Cache keys, TTLs, error codes, queue keys, admin buckets and approval rules
  are unchanged; `Death_Wire::match_group()` and `::wiki_death_year()` remain
  as thin delegates so the public surface stays put.

### Fixed — A finished Wikipedia check settles its story

- **Stories no longer strand in `check_queued`.** A story's Wikipedia
  confirmation runs as a queued request, but the handler that ran it never
  wrote the result back to the story: it finished (or refused) and the story
  sat in `check_queued` forever, so every sweep re-ran up to 200 of them as a
  band-aid (`requeue_stranded_checks()`). The check now carries its own story
  id and writes its terminal outcome — `attached`, `duplicate`,
  `created_provisional`, `identity_mismatch`, `no_anchor`, `flagged_no_anchor`
  — the moment it concludes. The band-aid is gone.
- **Each story gets its own check.** The queue key was derived from the
  article title, so many stories about one name collapsed onto a single queue
  row that ran once, stranding the rest. It is now keyed on the story
  (`Death_Wire::check_dedupe_key()`), which also keeps a double click from
  queueing the same check twice. A transient failure parks the story as
  `search_deferred` (retried by the sweep) rather than leaving it stuck.

### Fixed — A failed Wikipedia search is no longer an answer

- **The wire stopped believing a failure.** `wiki_search_title()` cached the
  empty result of any lookup — including a transport error, a 429/503
  throttle and a malformed body — for two hours, so well-known people
  ('Bob Pettit', 'Cheetah Chrome') whose raw search works were parked as
  `no_anchor` and never retried while the cache stood.
- **Definitive answers still cache; failures never do.** A new pure
  classifier (`Domain\Wire_Search::interpret()`) splits the outcome: a hit
  or a genuine empty result is cached, everything else returns a `WP_Error`
  and is not remembered. The story is parked as `search_deferred` and the
  sweep re-runs it once Wikipedia answers again; a 429/503 feeds the shared
  bounded pause as before.
- **A confirmed record is now visible.** Wire imports landed as drafts, so an
  auto-approved death was settled in the database but absent from the public
  site. Wire-created records now publish at once as provisional, like the
  list and obituary-signature paths, and approval — automatic or editorial —
  publishes the record itself. The approval rules are unchanged.

### Added — Public scope: synthetic leagues and accounts off every public surface

- **The site carried its own build artifacts into public view.** Synthetic
  leagues ("Testings", "Guy's Test League 2027") and test accounts sat on the
  front-page leaderboards, the league cards, the team directory and the user
  sitemap as though they were real players. They are real rows and are kept —
  hiding, never deleting, is the rule — but they no longer read as players.
- **One definition of "not public", in `Public_Scope`.** A league is hidden
  when `obitleague_leagues.is_hidden = 1` (additive schema change, DB
  `0.8.1`); an account is synthetic when its `obitleague_test_account` user
  meta is set. Every public read path builds its exclusion from that module —
  home league cards and season tiles, the overall and per-league standings,
  pick popularity and the statistics boards, team and league pages, the
  pick-distribution cache, robots directives and the user sitemap — so there is
  one rule rather than a filter re-guessed per module. Administrator screens
  deliberately read unfiltered, so a flag can always be reviewed and reversed.
- **The standings are rebuilt, not just filtered.** A published generation is
  itself a public artifact, so `Standings_Service::rebuild()` excludes
  synthetic accounts at build time and ranks are recomputed over the real
  field rather than printed around the hidden rows.
- **Both flags are toggles, and both are audited.** The leagues screen gains a
  Visibility column with Hide/Restore per league; the users screen gains a
  "Public surfaces" column, a "Test accounts" view, and the same toggle per
  account. Every change writes an `obitleague_admin_audit` row.
- **The empty league reads as waiting, not broken.** Hiding the synthetic
  entrants leaves the front-page standings card with a published generation
  and nobody on it, which used to print "Standings not published yet." —
  untrue, and it looks like a fault. A published-but-empty league now says
  "No teams to show yet."; only a genuinely unpublished one keeps the old
  wording. The leagues screen keeps an audit-visible Hide/Restore column with
  an explanatory line, and both admin toggles now say what to do when a change
  fails instead of only that it did.
- **`tests/flag-qa-rows.php`** flags the known synthetic rows on a live
  install. It reports by default and writes only with
  `OBITLEAGUE_QA_APPLY=1`; targets come from a built-in list, optional
  `OBITLEAGUE_QA_LEAGUES` / `OBITLEAGUE_QA_ACCOUNTS` names, or
  `OBITLEAGUE_QA_AUTO=1` word-token suggestions. It brings the schema up first
  and rebuilds the current standings generations afterwards, so one run leaves
  the published surfaces consistent.

### Changed — Discarded stories are deleted, not retained

- **Discarded now means discarded.** Below-threshold sweep discards, the
  tidy command's not_death and already-discarded purge, and the story
  modal's Dismiss button all **delete** the feed-item row outright
  instead of parking it with a `discarded`/`dismissed` state. No audit
  bucket, no recovery: if a marginal story mattered, it should have
  cleared the threshold. The dashboard's Discarded/Not-death buckets and
  stat card are gone; the tidy dry run reports what it would delete.

### Added — Story facts in the death-wire modal: dates, cause and biography from the article

- **The story modal now reads the article, not just the RSS summary.**
  Opening a story fetches the article text (through the wire's cached,
  bounded fetch — one polite request per story per 12-hour window) and
  runs it through the new pure-domain `Story_Facts` extractor.
- **What the modal reports, when the article supports it:** date of birth
  (exact Y-m-d, or a bare year at year precision — never upgraded), date
  of death, age (stated, or computed from exact birth+death dates),
  cause of death ("died of/following …", trimmed at clause joins),
  recurring phrases (bigrams/trigrams that appear more than once), and
  the lead biography sentence(s) as a one-glance summary. Everything the
  article doesn't say comes back empty — the extractor never guesses.
- **The wordcloud itself is no longer shown in the modal.** It remains
  the extraction machinery inside `Story_Facts`/`Wordcloud` (same-name
  disambiguation, phrase detection); the modal outputs only what was
  detected. Covered by the new `Scenario_Story_Facts` suite.

### Added — Data sources admin screen: queue visibility and sync controls

- **New “Data sources” screen** under the Obitleague review menu, one
  place for everything the plugin fetches externally. For each Wikimedia
  source (wikidata / enwiki / commons) it shows the rate-limit pause
  state with a human-readable countdown, pending/running/failed request
  counts and the next due time — plus a one-click pause clear when a
  stale cooldown is holding things up.
- **People enrichment is now inspectable:** the pending enrichment rows
  are listed with the person, attempt count, exact next-attempt time
  and *why* it is waiting (pacing, pause, or the last error being
  retried) — no more opaque “verbose data missing”.
- **Controls:** drain the queue now, reset failed requests with a fresh
  attempt budget, enqueue missing people (bounded scan), run a
  discovery batch, prime the death wire, and poll any RSS feed source
  immediately. All nonced admin-post handlers, admin-only.
- **Queue fix:** enrichment dedupe no longer matches completed rows, so
  a person whose data was filled can be re-enqueued later if a field
  becomes empty again.
- The Statistics screen stays read-only and links across to the new
  controls.

### Added — Fully automatic death wire: adjustable discard line, cached combined wiki fetches, wordcloud disambiguation

- **The auto-discard threshold is now a setting, not a constant.** The
  overview tab of the death-wire dashboard carries an "Automatic discard
  threshold" field: stories whose obituary likelihood sits below the line
  are discarded by the wire sweep without human attention. Stored in the
  `obitleague_death_wire_discard_below` option (0–95, default 50), clamped
  by `Death_Wire::set_discard_threshold()`.
- **One cached Wikipedia request per person instead of two uncached
  ones.** `Death_Wire::wiki_article( $title )` fetches the article
  wikitext AND the Wikidata QID behind it in a single `prop=revisions|pageprops`
  call, and caches the pair (transient, 2 h TTL, keyed on the title).
  Negative results cache too, so a missing article is not re-requested on
  every sweep. `wiki_search_title()` and `article_text()` are transient-
  cached as well (2 h / 12 h respectively, misses included).
- **Same-name matches are disambiguated by language, not guessed.** When
  several records cite the same Wikipedia article title, the story's own
  text (title + excerpt, falling back to the cached article text) is
  compared — via the new pure-domain `Wordcloud` class (cosine similarity
  over word-frequency clouds) — against each candidate's stored
  Wikipedia-article cloud (`obit_wiki_cloud` postmeta, written when the
  wire check runs). The best overlap wins only above the `MATCH_FLOOR`
  (0.08); below that the story stays flagged for a human. Wrong
  attachments are worse than flagged ones.
- **Wire-confirmed records enrich themselves.** The wire stamps the QID
  from the combined fetch and enqueues a `People_Sync::enqueue_person()`
  request, so portraits (P18), occupations (P106) and a missing birth
  date (P569) fill in through the global Wikimedia queue instead of
  waiting for the daily refresh. `People_Sync::apply_entity()` now fills
  an absent `obit_birth_date` from a day-precision P569 claim.
- **The story modal's wordcloud now includes the article text** when the
  wire has already fetched and cached it (the modal never fetches live).
- New domain class `Obitleague\Domain\Wordcloud` (pure, no WordPress —
  covered by the new `Scenario_Wordcloud` suite: counting, stopwords,
  wikitext reduction, cosine similarity, match floor).

### Fixed — Portraits and occupations self-heal through the request queue (db 0.7.2)

- **Why the catalogue filled with "no occupation recorded" and missing
  portraits:** `People_Sync` was making direct, unqueued HTTP calls to
  Wikidata, outside the rate-limit queue — and its daily refresh hook
  was a placeholder that nothing listened to. Anyone created after a
  failed or paused sync window simply stayed empty forever.
- **Sync now goes through `Wiki_Request_Queue`** as `enrich_person`
  requests: one queued request per person, deduplicated, processed
  serially at a polite fixed rate. Rate-limit answers park the whole
  wikidata source instead of being silently swallowed.
- **The queue gained real per-source pacing:** each request carries a
  `source` and a `next_attempt_at`; a Retry-After response sets that
  source's next-try timestamp and every pending row for it slides to
  the same moment — retries keep targeting that time until the source
  hits a rate limit again. Between limits, requests are spaced by a
  minimum interval so steady-state traffic stays inside recommended
  rates. Additive schema change (`source`, `next_attempt_at`),
  `OBITLEAGUE_DB_VERSION` → `0.7.2`.
- **The daily profile refresh now actually runs:** it re-enqueues every
  published person still missing a portrait or occupations, so gaps
  self-heal within a day instead of persisting. Also available as
  `wp obitleague sync-people --limit=N`.

### Added — Git delivery rules and plain-English explainers

- **New rulebook for how work reaches GitHub:** `docs/GIT-WORKFLOW.md`
  now defines the delivery process — session branches renamed to
  human-readable `<scope>/<topic>` names, plain-sentence commit
  messages, fast-forward merges into `main`, push of `main` only, and
  deletion of the session branch afterwards.
- **5-minute approval rule:** approval requests may proceed on assumed
  approval after 5 minutes of silence, but only when the change is
  exactly as asked, all checks pass, and it is trivially reversible.
- **Plain-English explainers:** `docs/GIT-EXPLAINED.md` and one-page
  guides per concept under `docs/git-explainers/` (branches, commits,
  merges, push/origin, approval, deploy) so every git step is
  understandable without git expertise.
- **Guidance kept live:** `START-HERE.md` and `AI_PLUGIN_GUIDE.md`
  now point to the rulebook, and any future change to the process must
  update the guidance files in the same change.
- **Any-tool entry point:** root `AGENTS.md` binds every AI agent
  working from any folder or tool to the same rules, so the directives
  hold regardless of where a session starts.

### Added — Future death dates are red-flagged (0.15.2)

- **A death dated in the future can no longer be approved.**
  `Review_Rules::approval_problems()` now rejects any death date after
  today (Europe/London) — partial dates are judged on their earliest
  possible interpretation — so no approval path (review screen, REST,
  WP-CLI) can publish an impossible record.
- **Person pages show a red-flag banner** for records that already carry
  a future death date: rose alert, plainly worded ("a date that has not
  happened yet"), with the scoring card withheld until the record is
  corrected. Existing bad data stays visible but can never score.
- **One header CTA.** The actions row no longer renders a second
  "Choose your team" button; the single CTA in `.ob-header__mobile-join`
  renders at every width, so the mobile menu sheet no longer stacks two
  identical gold buttons.
- **Mint-on-yellow and yellow-on-mint eliminated site-wide.** The pale
  mint text (`#8fd3b6`) is retired for muted sage on dark surfaces; the
  season toggle's active option on dark heroes is deep green on white
  text instead of gold with mint text; the memoriam "Confirmed" pill,
  generic badges, forum notes and occupation tags no longer sit on
  `--ob-accent-soft` where a gold context could pair with them — the
  soft-green token itself is now a greyer sage (`#dcebe1`). Dark green
  pairings are unchanged.

### Added — Article signatures create people; corroboration auto-confirms (0.15.1)

- **Obituary-desk articles now create people directly.** When a story from
  an obituary source matches no existing record, the wire fetches the
  article page and parses the signature obituary desks print at the foot
  of a piece — name plus exact date of death. Both facts present, the
  person is created at once as provisional (visible, unscored) with an
  open review case and the article attached as a source. Existing records
  are never touched: a name collision is the editor's problem, not the
  wire's.
- **Corroboration auto-confirms.** When a pending case's record carries an
  exact death date and two or more independent origins — the Wikipedia
  deaths list, Wikidata, or distinct press domains (registrable-domain
  matching, so two Guardian links count once) — the case is approved
  through the normal decide() path as the system reviewer, with the
  evidence named in the audit reason. Attach-time and publish-time
  source attachments both trigger the check.
- **`wp obitleague reclassify-feed-items`** re-runs the current weighted
  classifier over every stored feed item so verdicts and likelihoods
  reflect the new scoring; `--dry-run` shows the migrations first. The
  Guardian Obituaries stories that showed 30% under the old cue counter
  now score 95 under the weighted one.
- New pure domain classes: `Obituary_Article` (name + death-date
  signature parsing, both date orders) and `Sources` (registrable-domain
  extraction), both unit-tested.

### Changed — Weighted death-detection classifier replaces the cue counter (0.15.0)

- **The RSS classifier is now a weighted death detector, not a keyword
  counter.** Phrase tables carry separate title/body weights, matched
  spans are masked so a phrase never scores twice through its own
  substring, and the whole table set — phrases, weights, bonuses,
  exclusions — lives in named constants so it can be retuned without
  touching the logic.
- **Four verdicts** replace candidate/not_candidate:
  `death_announcement` (70+, strong candidate), `obituary` (obituary-desk
  content in the review band), `death_followup` (50–69, requires further
  confirmation) and `not_death` (below 50, no candidate is created).
  Legacy stored values still count as wire signals, so nothing already
  in the queue is lost.
- **The strongest patterns score highest**: "dies aged [AGE]", "has died
  aged", "who has died aged …" and their dead/dead-at/passses-away/killed
  variants carry 50–60 points on the title alone; an age attached to a
  death phrase adds 25 (title) or 15 (body); attribution to family,
  agent, manager, publicist, representative or spokesperson adds 10;
  each additional distinct death phrase adds 8 (to +16); a dedicated
  obituary feed or death category adds 70 and marks the story as
  obituary-desk content. Scores cap at 95.
- **Hard exclusions force `not_death`**: death hoaxes, false/mistaken
  reports, death rumours, "not dead"/"still alive"/denials, fictional
  characters killed off, death scenes, on-screen deaths, and anniversary
  /"years since"/"on this day" retrospectives. Soft dampeners (funeral,
  inquest, cause of death, tributes, remembrance, archive pieces, "would
  have turned") pull follow-up genres down — but a title that announces
  a death itself ("Tributes paid as X dies aged 80") is never buried by
  its own genre word, and body-level dampeners no longer miss genre
  words that the phrase-masker has consumed.
- **The wire sweeps every death signal**, not just legacy candidates, and
  ingests RSS descriptions and categories so the classifier sees body
  text and the feed's obituary-desk context. The likelihood gauge reads
  the weighted score directly; first-generation rows keep their ×10
  mapping.

### Added — Honest obituary likelihood, auto-discard, and per-story decisions

- **The likelihood gauge was fiction at the low end.** It mapped a zero-
  signal story to 50% (50 + score×6), so nothing ever looked unlikely.
  The gauge is now honest: 0% for a story with no death signal, roughly
  +10 points per cue-weight, capped at 95% — and both the pending list
  and the parsed-stories table sort most-likely-first.
- **Stories below 50% likelihood are auto-discarded.** The RSS sweep
  marks them `discarded` without spending a Wikimedia request or an
  editor's attention; they remain visible in the audit table. The line
  is `Death_Wire::DISCARD_BELOW` (50).
- **Pending stories gain Dismiss / Publish buttons with a match column.**
  The death-wire dashboard lists unprocessed candidate stories sorted by
  likelihood, shows which person record the headline's name group
  matches (linked to its editor screen), and offers Dismiss (story is
  not a death; hidden, kept for audit) and Publish (runs the wire match
  immediately: a story matching a confirmed death attaches its source
  to the public obit page at once, one matching a person record queues
  the Wikipedia confirmation pass, an unmatched name queues the
  new-person check).

### Changed — Wikipedia list deaths treat the list as an origin; profiles fill the gaps

- **A "Deaths in 2026" listing now counts as the Wikipedia origin group.**
  The wire already stamps every list import with `obit_death_wiki_name`,
  but approval only counted an article title (`obit_enwiki`) — so
  list-imported people failed the two-origin rule on a technicality. The
  list flag and the article title are now interchangeable as the
  Wikipedia origin; the Wikidata QID remains the second origin. Every
  list-imported case with an exact stored date is confirmable in one
  click, exactly as the editor intended: the list is hot on third-party
  confirmation.
- **Profile sync fills what the wire cannot.** Wikidata batch sync now
  also writes the public role from the primary occupation (P106) when
  the record has none — the cause of the blank "Public role" fields on
  wire imports — fills the English Wikipedia article title from the
  entity sitelink, and refines the stored death date to Wikidata's
  day-precision P570 on provisional records only. Living records gain
  deaths through the wire and review, never through sync; confirmed
  records keep the date their approval decided.

### Added — Provisional deaths, a triage queue and bulk approval

- **The wire now publishes deaths immediately as provisional.** Imported
  records (from the Deaths-in-2026 lists and matched RSS stories) go live
  at once instead of hiding as drafts: flagged `obit_death_provisional`,
  visible on every public surface, scoring nothing, and excluded from the
  confirmed-death counts, hits and misses. Public surfaces badge them
  "Awaiting confirmation"; the stats board gains an "Awaiting
  confirmation" tile. When a case is approved — by hand, from the queue,
  or in bulk — the record is promoted to a fully confirmed death in the
  same transaction as the decision.
- **The review queue is a triage desk, not a file list.** Each pending
  row now shows the person, their role, the recorded death date with
  computed age, the cause wording (or its status), the attached sources
  as links, and two actions: **Confirm** (one click, only when the record
  already carries an exact death date and two independent origins) and
  **Edit** (the full decision form). A bulk bar approves every
  evidence-complete case at once. The whole page is drawn with three
  bulk queries instead of hundreds of per-row lookups.
- **New CLI: `wp obitleague review-approve-all`.** Bulk-approves pending
  cases whose records satisfy the approval rules on their stored facts;
  lists and leaves anything else for a human. Supports `--dry-run`,
  `--limit`, `--editor` and `--reason`. Every approval goes through the
  normal service, so audit trail, events, outbox and standings behave
  exactly as a manual decision.

### Fixed — Hero CTA ink and header breathing room

- **"Browse the catalogue" rendered mint-on-gold.** The home hero emitted
  bare `<a>` tags with no button classes, and the "links inside dark heroes
  stay light" rule in chrome.css exempted CTAs with a `:not([class*=...])`
  test against the link's own class — which the links did not carry (the
  class lived on their parent). The exemption never matched, so mint
  `!important` ink beat the gold button's dark text. Hero CTAs now carry
  real `ob-btn` / `ob-btn--ghost` classes (new ghost variant), the broken
  `:not()` was rewritten in the form already used elsewhere in the file,
  and an ink law pins the CTA colours against the kit regardless of
  markup. The my-leagues hero CTAs get the same treatment.
- **Header and hero spacing loosened.** Header inner width 1180→1240px
  with 24px side padding, nav-link padding 7→11px horizontal, actions gap
  2→6px; hero padding up at both breakpoints, CTA row gains a top margin,
  and the "Season at a glance" tiles flow `auto-fit minmax(190px, 1fr)`
  instead of a hard 5-across with 212px cells.

### Fixed — Cache-buster and plugin header repaired

- **Stylesheets could keep serving stale cached copies after a deploy.**
  The `Version:` plugin header said 0.14.1 while the `OBITLEAGUE_VERSION`
  constant (the value appended to every asset URL as the cache-buster) had
  drifted to 0.14.4 in 5cad5c3 without touching the header. Both now read
  0.14.5 — never served before, so every browser fetches the fixed CSS.
  Also repaired the plugin header itself: the line break after
  `Requires PHP: 8.2` had been swallowed into a tab, leaving `Author:`
  unparsed by WordPress.

### Fixed — The death wire now actually runs

- **The Wikipedia "Deaths in 2026" pass and the RSS wire sweep were never
  scheduled.** `Death_Wire::run()` was reachable only through WP-CLI, so on a
  production install the wire never fired and confirmed list deaths were not
  being captured. A new hourly tick (`obitleague_death_wire_tick`) primes the
  Wikimedia request queue every hour; it is armed by the self-healing boot,
  on activation, and unscheduled on deactivation like the other jobs.
- **The RSS sweep's `wire_state` column did not exist.** The sweep filters on
  `feed_items.wire_state`, but the column was never added to the schema, so
  the sweep died on a SQL error even when triggered by hand. The schema now
  carries `wire_state`, plus `classification_score` and `matched_cues` for
  the dashboard (DB 0.7.0 → 0.7.1, additive; `dbDelta` backfills existing
  rows with defaults). Feed ingest now stores the classifier score and the
  matched/negative cues alongside the classification.

### Added — Death wire dashboard

- **New admin screen: Obitleague → Death wire.** Status strip (list months
  covered, Wikimedia queue depth, unprocessed candidates, next scheduled
  run, last-run tallies) with a run-now button; **RSS source management** —
  add a source by name, https feed URL and poll interval, with a probe that
  refuses unreachable or non-feed URLs, and pause/resume/delete per source
  (deleting a source removes its parsed stories); and a **parsed-stories
  table** showing every story the feeds produced with its obituary
  likelihood (classifier score as a 0–100% gauge), matched and negative
  cues, wire outcome, and a **season wordcloud** aggregated over feed
  titles, so the year's language is visible at a glance.
- README documents the wire, the dashboard and the source-terms rule: the
  dashboard makes adding easy, but unclear terms still mean no source.

### Added — Static wiring audit in the domain suite

- **The suite now re-proves the plugin's wiring on every CI run**, no
  WordPress needed. `tests/Scenario_Wiring.php` verifies: every booted
  module exists with a `boot()`, every `array( Class, "method" )` hook
  callback resolves, every cross-class static call exists, every referenced
  template and asset file exists, every shortcode tag maps to a real method,
  every rewrite query var is registered or read, and every static internal
  link points at a route the plugin itself guarantees.
- The link audit immediately earned its keep: **`/standings/`, `/rules/` and
  `/archive/` were linked from the header, footer and templates but never
  created on a fresh install** (only the demo importer made them). They are
  now auto-created with the overall-standings, rules and death-archive
  shortcodes, alongside the existing teams/obituaries pages. The person
  template's "Back to the catalogue" link now points at `/people/`, which is
  actually guaranteed, instead of the demo-only `/catalogue/`.
- **Restored the lost `Pick_Stats::season_picks_summary()`** — the header's
  "Most picked" strip has been silently empty because a `method_exists`
  guard masked the missing method. It is implemented on the cached pick
  distribution, and the audit stays strict so a future deletion fails CI.
- The Wikimedia User-Agent identity no longer links to a never-created
  `/about/` page; it uses the site root.

### Fixed — Team picker layout and button colourways

- **Search results no longer cram the name and description onto one line.**
  Each eligible-pick result is now a proper row: the person's name on its own
  line, the disambiguation (age, occupations, QID) underneath, and a
  **View on Wikipedia** link beside the choose button so players can confirm
  they have the right person before adding them. Catalogue REST results now
  expose the record's `enwiki` title to power that link; Wikidata-only
  results fall back to a "View on Wikidata" link.
- **Outlawed the broken button colourways site-wide.** The Elementor kit and
  theme globals style bare buttons and out-specify the plugin's single-class
  rules, which produced green-on-green and mint-on-yellow buttons. Doubled
  `!important` declarations now pin the system regardless of what the kit
  emits: primary is always gold on dark green, secondary and danger keep
  their white-on-colour looks, the header search keeps its deliberate ghost
  style, and disabled buttons always look disabled.

### Added — Death log with hits and misses

- **Every confirmed in-season death is now classified on the site.** The
  obituaries index and the recent-deaths card mark each death as a **hit**
  (someone picked them) or a **miss** (nobody did); the deaths pipeline
  already imports unpicked deaths, so the misses surface is a view, not a new
  pipeline. The index gains All/Hits/Misses filter pills (`?pick=picked`,
  `?pick=missed`) and a summary line; the stats shortcode gains Hits and
  Misses tiles for the season in play.
- The Obituaries header menu is now a mega panel fronting the full death log:
  all deaths, hits, misses, and the death archive. The People and Teams menus
  keep mega panels too; Standings and Stats return to plain links so the
  header reads as five clear destinations.
- Header housekeeping: current-page highlighting on primary links, deleted
  the never-booted `HeaderIntegration` module and the Elementor-only dead
  methods on `Header`.
- New WP-CLI probe `tests/verify-death-misses.php` proves hits + misses cover
  every confirmed season death.

### Changed — Discovery self-population

- **Discovery now samples the living cohort at random instead of walking it in
  order.** Each batch draws up to six distinct birth-month windows from the
  eligible span and imports up to **50 new candidates** (previously five per
  UTC day, one batch per day, in strict QID order). The catalogue is meant to
  fill itself at a decent rate with a random mix of popular and obscure
  people; the cursor, the daily-run option and the advance logic are gone.
- Manual batch starts are bounded to 4 per rolling hour so repeated clicks
  cannot hammer the Wikidata endpoints; automatic runs bypass that bound
  exactly as they bypassed the old daily cap. A new hourly cron tick
  (`obitleague_discovery_tick`) runs one full batch every hour, so the
  catalogue self-populates without anyone clicking; the tick is armed on
  activation/boot and unscheduled on deactivation like the other jobs.
  Admin batch size options are now 10/25/50; `wp obitleague discovery`
  defaults to a full batch and accepts `--limit=1..50`.

### Added — Humans vs AI (feature/ai-vs-humans)

- **AI competitors are participants, not a separate game.** An agent is an
  ordinary WordPress account holding standard main-league entries, plus a
  metadata row (`obitleague_agents`: name, description, category
  official/community/external, declared model with admin verification,
  accountable operator, participation method) and scoped, hashed bearer
  tokens (`obitleague_agent_tokens`). Agent users get subscriber role only —
  never administrative capabilities. Database version 0.7.0 (additive).
- **Rolling entry.** Entries stay open for the whole season year — a write
  must commit strictly before 23:59:59 Europe/London on 31 December of the
  season year (`Ruleset::ROLLING_ENTRY`). Ruleset VERSION stays `1`: the
  season-start scoring floor reproduces v1 results exactly for existing
  entries, and award operation keys are unchanged, so the idempotent ledger
  cannot double-award. Age eligibility is now anchored to the named season
  start (`Deadline_Policy::season_start()`).
- **Submission-instant scoring floor.** A selection scores only when the
  verified death date falls after the team's own submission instant (deaths
  are dated, compared at midnight, so a same-day death cannot be proven to
  have happened after a daytime submission). Late joiners get no retrospective
  points, no handicaps and no bonuses. The floor reads `submitted_at` on the
  competing revision, so an amendment restamps it.
- **Pick privacy under rolling entry.** Team pages and the person-page
  "picked by" team lists withhold unexpired picks while the season's entry
  window is open; aggregate counts stay public. Pre-flag seasons keep the v1
  instant, so historic behaviour is unchanged.
- **BYOAI REST API** (`/obitleague/v1`): operator-authenticated agent
  registration and token revocation; agent bearer-token endpoints for rules
  discovery, eligible-people research, team submit/amend, standings with own
  rank, and token rotation. Same domain services as the website — nothing is
  implemented twice. Per-agent rate limits with 429s.
- **MCP endpoint** (`POST /obitleague/v1/mcp`, JSON-RPC 2.0: initialize,
  tools/list, tools/call) whose six tools delegate to the shared REST
  callbacks, and an **A2A agent card** at `/.well-known/agent.json`.
- **Official agent orchestration** (`Agent_Orchestrator`): provider-agnostic
  adapter (option-configured local endpoint or completion filter — no
  provider credentials in WordPress), strict-JSON ten-pick selection through
  the shared submission flow, and an append-only run ledger
  (`obitleague_agent_runs`) recording adapter, model, prompt version, picks
  and errors per season. WP-CLI: `wp obitleague agent-run <id> [--force]
  [--all]`, `wp obitleague agent-runs`.
- **Leaderboard views and statistics:** `Leaderboards` (overall with AI
  flags, human/AI filtered views, model championship with competition
  ranking, late-entry spotlight by submission month) and `Vs_Stats` (cached
  human-vs-AI snapshot with explicit caveats — averages describe the field,
  they are not the official ranking).
- **Public pages:** `/ai/` directory, `/ai/{slug}/` agent profiles with SEO
  meta, `/ai-vs-humans/` comparison page, `/ai-integrate/` BYOAI onboarding;
  `[obitleague_ai_directory]` and `[obitleague_vs_stats]` shortcodes plus the
  `obitleague-vs-stat` Elementor dynamic tag.
- **Admin:** AI agents screen (activate/suspend/retire, verify declared
  model) with audited actions; agents flush the VS-stats cache on submit.
- **Docs and tooling:** `docs/AGENT-API.md`, dependency-free example agent
  (`docs/examples/example-agent.mjs`), 42 new domain checks
  (`tests/Scenario_Agents.php` — 196 total).

## [0.12.1] — 2026-09-28

### Fixed
- **Deleting a person no longer strands their occupation terms.** Occupation
  terms are public, indexable archive URLs, so a term nobody is filed under is
  a reachable page that can only say “0 people”. WordPress removes a deleted
  post's term relationships but leaves the terms themselves behind, so every
  occupation that only the removed person held survived as a dead archive
  page. This was not theoretical: a live clean-slate re-import that deleted
  150 people left 45 orphaned terms behind, recoverable only by hand with
  `tests/prune-orphan-occupation-terms.php`.
  `src/Modules/Occupation_Taxonomy.php` now captures the terms before the
  record goes and prunes any that have lost their last reference afterwards.
  A term is only ever removed when `wp_term_relationships` holds no remaining
  rows for it — the live relationship rows, not the cached count on the term
  taxonomy — so a term any other object is still filed under is always kept.
- Note that custom post types get no trash protection in `wp_delete_post()`:
  its trash short-circuit only covers `post` and `page`, so an `obit_person`
  record always took the permanent-delete path.
- `tests/prune-orphan-occupation-terms.php` now shares
  `Occupation_Taxonomy::prune_term()` with the new hook, so the rule for when a
  term may be deleted is defined in exactly one place rather than two that can
  drift apart.
- `tests/verify-orphan-occupation-pruning.php` covers the case: it builds two
  people who share one occupation and gives the other a unique one, then
  asserts the unique term is pruned, the shared one survives *and* is still
  attached, and that deleting the last holder prunes it too. The shared-term
  check is the one that matters — a prune slightly too eager would pass a test
  that only looked for the unique term.

## [0.12.0] — 2026-09-28

### Added
- **Person pages now say how popular a name is.** How often someone is actually
  taken is the most interesting thing about a dead pool, and it was invisible.
  Every person page now carries a “Picked by” card: the number of submitted
  teams holding the name, its share of the field, the name's rank among picked
  names, how that splits across leagues, and the teams that took it.
- **Hot pick and unique pick badges.** The top 50 most-picked names on the
  board are badged as hot picks; a name taken by exactly one team is badged as
  a unique pick — the rarest possible outcome, and often the only way to get a
  good score out of a name nobody else wanted.
- `tests/verify-pick-stats.php` builds the unique-pick situation deliberately,
  since no real person is picked by exactly one team, and checks both the
  figures and the rendered page.
- **`work/build-cohort-1946.cjs`** builds a 1946 birth-year cohort of living
  people from Wikidata, diverse by construction: 11,157 candidates, ranked so
  that rare nationalities and occupations are taken first and admitted only
  while their country and occupation stay under hard caps. The 150 rows it
  writes span 93 countries and 265 occupations.
- **`tests/import-seed-file.php`** loads any builder-produced seed file into the
  catalogue. `demo-import-people.php` knew three filenames; this takes a path,
  so a new cohort needs no code change. People land as private candidates
  unless `OBITLEAGUE_SEED_APPROVE=1` is set, and it refuses to write to a
  production site without `OBITLEAGUE_ALLOW_SEED_IMPORT=1`.

### Fixed
- A share of submitted teams below half a percent displayed as “0.0%”, which
  reads as “nobody took this name” and contradicts the count beside it. It now
  reads “less than 1%”.
- The cohort builder is now correct about liveness, dates and sampling. A
  Wikidata item with no date of death is not proof of being alive, and the
  original candidate query filtered on exactly that absence; liveness is now
  cross-checked against Wikipedia's death-year categories as well, with a
  `--selftest` that proves the check can fail. Birth dates are rebuilt from
  each statement's own precision instead of letting a month-precision date
  masquerade as the 1st, and candidates are sampled by hashing the QID — a
  positional sample silently took January, which holds 30% of the cohort.
- `work/build-cohort-1946.cjs` refuses to write an empty or sub-minimum cohort,
  and `--any-precision` is now the opt-in rather than the default, so the rows
  it writes can be approved for publication.

### Notes
- Counts come from the current submitted revision of each entry only. A team
  that amends its picks supersedes its old revision, so counting every revision
  would inflate totals and count the same team twice.
- Figures are scoped to the season in play. A player can hold an entry in more
  than one season, and pooling them would count them as several teams. The
  season is single-sourced with the page's scorecard so the two cannot
  disagree.
- The ranking aggregate costs a few hundred milliseconds on a large field, so
  the distribution is cached and invalidated whenever an entry is submitted or
  amended. A warm read is about 5ms.
- Team names are shown, never the account holder: picks are public after lock
  under the ruleset, but a public page naming which player picked whom is a
  disclosure the game does not need to make.

## [0.11.0] — 2026-09-28

### Fixed
- **Import no longer writes the public occupation taxonomy.** The taxonomy is
  browsable and indexable, so every term in it is a permanent archive URL — but
  import was writing unsourced feed role text straight into it. Because role
  text varies by extractor, each distinct phrasing became its own archive page
  duplicating an occupation that already existed as a clean sourced term: 70 of
  the 71 empty terms on a demo install were verbatim copies of a stored role
  ("American jazz guitarist" alongside the real "jazz guitarist"). The same
  path is what turned a hardcoded `Public figure` placeholder — applied to the
  entire 60-person living pick pool by the demo importer — into a public
  archive page. Occupations now come from one place, Wikidata P106 via
  `People_Sync`; the feed occupation is kept as an `obit_occupation_hint`
  editorial hint and never becomes public. If a sync fails, a person simply has
  no occupation tag rather than a wrong one.
- **71 empty occupation archive pages removed.** Terms now outlive no one, so
  the taxonomy holds 211 terms and every one has people behind it. Import no
  longer creates the orphans, so this only had to clear what earlier versions
  left behind.

### Notes
- The "Public figure" catch-all is gone: the term no longer exists, and no
  person carries it. The earlier "58" figure counted the 60-person living pool
  at import time, before the Wikidata occupation sync replaced those tags.
- Enrichment was measured, not assumed, and is not available: of the 41 people
  with a single occupation (40 of them deceased), every record sampled has
  exactly one occupation claim on Wikidata. Those records are accurate rather
  than degraded, and the only way to make them read as richer would be to
  invent classifications. `sync_all()` still only re-checks people missing
  portrait or occupation data, so thin records are not re-fetched; measurement
  says that would currently find nothing.
- No schema changes; `OBITLEAGUE_DB_VERSION` stays at 0.5.0.

## [0.10.0] — 2026-09-28

### Added
- **Person pages now carry a body.** Every published person record is
  described in prose composed from the facts an editor has already approved:
  life span, linked occupations, cause of death in the plugin's own terms,
  what the record scores under the current ruleset, and where the facts come
  from. Previously the page showed the same values only as a definition list
  and no body at all. The composer is a formatter, not an author — it
  assembles sentences from stored fields, never infers or invents anything
  about a person's life, and keeps the sourced precision of every date.
  Living records state the scoring rule but never a frozen points figure,
  because age moves daily; the live figure stays on the page's own scorecard.
  `Person_Content` regenerates on approval and on every editorial death
  decision, and is idempotent — an unchanged record is never rewritten.

### Fixed
- **Cause-of-death text was leaking into occupations, in public.** Feed
  extraction sometimes appends a cause to the role field ("South Korean actor
  , blood cancer", "Pakistani footballer, colon cancer"). That string reached
  the profile byline, the meta description, the JSON-LD `jobTitle` — a
  structured-data assertion of a false occupation — and, in a handful of
  cases, created a permanent taxonomy term. So the site claimed someone died of
  a cause it elsewhere reported as undisclosed. `Role_Label::clean()` now
  strips a trailing cause clause, and every consumer of the role field shares
  it, so the byline, description, schema and body cannot disagree. Labels
  that are nothing but a cause are dropped; genuine occupations, including
  ones that borrow a cause word ("cancer researcher"), are untouched.
- **Thin occupation archive pages are no longer indexable.** Terms outlive the
  people filed under them, leaving 71 reachable archives whose only content
  was "0 people". Empty occupation archives are now `noindex, follow`; the
  populated ones stay indexable.
- `tests/build-person-content.php` backfills bodies for existing installs and
  `tests/prune-orphan-occupation-terms.php` removes the contaminated terms an
  older import may already have created. Both are safe to re-run.

### Notes
- No schema changes; `OBITLEAGUE_DB_VERSION` stays at 0.5.0.

## [0.9.0] — 2026-09-27

### Fixed
- **Standings rebuild no longer loses or duplicates teams.** Paging used a
  keyset on `entries.id` while ordering by `points DESC`, so a batch of
  scorers advanced the cursor to the highest id present and permanently
  excluded every lower-id team outside that batch; later batches could also
  re-select rows already inserted, violating the unique key on
  (generation_id, user_id). Leagues over 500 teams published short, partly
  duplicate leaderboards with nonsensical rank positions, and the failure was
  silent — the insert error was ignored and the rebuild reported success.
  Paging now uses a keyset over the full sort tuple
  (points, scoring_picks, user_id) computed once into a temporary table, and a
  failed batch aborts the rebuild instead of publishing a short generation.
  The aggregate is no longer recomputed per batch, which also cut a
  2,500-team rebuild from over three minutes to about 30 seconds.
- **The join page rendered as an empty 200.** `Game_Pages` built the template
  filename from the route slug, resolving `/join/` to a missing `join.php`
  while the file is `join-league.php`, orphaning that template entirely.
- **Escaped HTML shown as text on every catalogue card.** The person archive
  rendered occupation tags twice; the duplicate escaped already-built link
  markup, so visitors saw literal `<a class="ob-occ-tag" …>` on each card.
- **Seven of the eight "shape of the archive" boards rendered blank labels.**
  `Stats_Service::board()` emitted `value` while the template read `label`, so
  birth decades, birth months, weekday born, star signs, first initials, name
  lengths and ages at death showed bars and counts with no category name, plus
  a PHP warning per row.
- **Entry season copy is no longer hardcoded to 2027.** The header CTA, the
  register page and the verification email all named a literal year and would
  have advertised a locked season from 1 January 2027. They now follow the
  open entry season.
- "Season {year} is in play" on My Leagues contradicted the front page, which
  correctly showed the season actually in play.
- Removed a dead `page` computation and a duplicated if/else branch on the
  team detail template.
- The leagues admin list now selects `is_main`, so the "main league" marker
  appears instead of never rendering.
- `work/build-seed.cjs` now downloads the monthly Wikipedia pages it parses
  (previously it only ever read cached files, so a clean clone silently
  produced an empty 2026 deaths seed) and rejects a `parse.wikitext` payload
  in the API's default object shape instead of parsing it as zero entries.

### Added
- Search metadata and crawl control via a new `Seo` module: per-person titles
  with life span, factual descriptions, canonical URLs, Open Graph and Twitter
  cards using the synced Wikimedia portrait, and `Person` JSON-LD built from
  approved fields (exact dates only, with Wikipedia/Wikidata `sameAs`).
  Occupation archives get their own titles and descriptions. Account, team,
  league and forum routes now send `noindex, follow` so private and thin pages
  stay out of the index, matching the architecture's sitemap policy.

No schema changes: `OBITLEAGUE_DB_VERSION` remains `0.5.0`.

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
- Per-entry team names with an additive schema migration, team-profile and
  standings display, and administrator editing/audit support.
- Expanded the local demo to eight themed leagues, forty clearly fictional
  demo accounts and up to 64 named teams with sourced, themed pick mixes.
- WordPress administrator screens for league creation and management,
  member status/removal, team entry creation, audited pick revisions,
  submission/withdrawal, revision history and standings rebuilds. Added a
  database-backed administrator audit log and additive schema migration.
- Join and team-management front end with debounced catalogue search,
  age/occupation disambiguation and explicit human-only Wikidata add flow.
- Players can amend submitted teams until their season begins; prior submitted
  revisions remain preserved and new approved-event awards are applied.

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
