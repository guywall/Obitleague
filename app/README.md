# Standalone application units

The WordPress-free runtime is a small set of single-concern units. `Runtime` is the
only public entry point: `new Runtime( $database, $root, ?Clock $clock = null )`, then
`->handle()` to dispatch one request and `->database()` to reach the PDO handle.
`index.php` is the PHP built-in server front controller; `schema.sql` is the schema.
It reads `OBITLEAGUE_NOW` (alongside `OBITLEAGUE_DB`) to pin the clock for tests and
ops: a fixed instant makes the season and the deadline boundary observable.

Each file owns exactly one concern and its tables:

| Unit | Owns | Calls into |
| --- | --- | --- |
| `Runtime.php` | Request routing and HTTP response composition | all units |
| `Auth.php` | Credential rules, password checks, web sessions, and the session cookie (`accounts`, `web_sessions`) | `Database`, `Csrf` |
| `Csrf.php` | Request-token verification | — |
| `Database.php` | PDO connection, schema bootstrap, atomic transactions (`schema_migrations`) | `Clock` |
| `Leagues.php` | League and membership provisioning (`leagues`, `league_members`) | `Database`, `Clock` |
| `Catalog.php` | People reads and pick selectability (`people`) | `Database`, `Discovery_Rules` |
| `Team.php` | Team save/submit/amend (`entries`, `entry_revisions`, `entry_picks`) | `Database`, `Catalog`, `Clock` |
| `Awards.php` | Reversible per-(team, pick, event) award ledger (`awards`) | `Database`, `Catalog`, `Clock`, `Scoring`, `Deadline_Policy` |
| `Events.php` | Reported-death events and the approve/correct/retract review transitions (`events`) | `Database`, `Catalog`, `Awards`, `Clock` |
| `Standings.php` | Season standings from submitted teams + ledger, and whether they are final | `Database`, `Clock`, `Scoring`, `Ranking` |
| `View.php` | Template rendering under `app/views/` | — |
| `Clock.php` | The single source of "now" and the current season; holds an optional fixed instant | — |
| `Http_Error.php` | Status-carrying request failure shared by the units | — |

Data flows one way: `Runtime` calls the units; units never call back into `Runtime`.
`Team` reads `leagues`/`league_members` through a read-only join but only `Leagues`
writes them. `Runtime.php` also loads the `src/Domain` files it needs, so it is the
single bootstrap for both `index.php` and the test. `Runtime` is routing and
composition only: the register/login policy (credential rules and the session
cookie) lives in `Auth`.

## Deadline lock

Picks are final once a season's entry window closes; there is no amendment for a
season in play. `Team::require_open( $season, $at )` is the single gate on both
`/team/save` and `/team/submit`: a season that is not the current one, or whose
`Deadline_Policy::commit_on_time` deadline (23:59:59 Europe/London on 31 December)
has already passed, is refused with 409 **before the entry is looked up**, so a
rejected post-lock amend cannot lazily create an `entries` row, let alone a revision
or pick. The gate is checked twice: once on the request instant, and again inside the
`Database::transaction` boundary on the transaction's start instant, so a request
that begins before the deadline but commits at or after it is refused — finality does
not depend on when the request started. The gate derives its instant from `Clock`;
provisioning membership (`Leagues`) and session expiry (`Auth`) use the same clock, so
a pinned clock keeps the whole boundary consistent. `tests/standalone-runtime.php`
pins the clock one second before and at the deadline and asserts the accept/refuse
boundary both in-process and through the real HTTP entry point, plus a `Commit_Clock`
that reports the commit instant only while a transaction is open to prove the
commit-time refusal, with no entry, revision, pick, or version written on refusal.

## Pick selectability

`Catalog::selectable( $person, $season )` is the single source of truth for whether
a person may be picked: approved, no recorded death, an exact valid birth date, and
old enough at the season start. Both the picker (`Catalog::picker`, which renders the
team-page options) and the save path (`Team::validate_picks`, via
`Catalog::selectable`) consume it, so they cannot diverge. The public catalogue
(`Catalog::search`) is an editorial listing, not a pick list, and intentionally does
not apply the season age rule.

## Reported death to standings (operator review)

`Events` owns the `events` table: a report is a death claim awaiting review, not a
fact. The operator surface is `GET /review` plus the `POST /review/report`,
`/review/approve`, `/review/correct`, and `/review/retract` routes. `Events::board()`
lists every event that still needs action — pending reports (with an approve action)
and approved deaths (with a correction form and a retract action) — so the whole loop
is reachable by a user rather than only by a hand-crafted POST. Approval publishes the
death onto the person row and fans the award out through `Awards`; a date correction
bumps the event revision so the ledger reverses the old date and awards the new one,
while a cause-only correction leaves the revision and every award row untouched.
Retraction clears the published death and reverses the award; retracting a
never-approved report is refused (409) rather than half-applied. Operator identity is
owned solely by `Auth::require_operator()`/`Auth::is_operator()`, which read
`OBITLEAGUE_OPERATORS`; the nav link only reflects the `is_operator` flag that
`Runtime` attaches, and the routes are closed when no operator is configured.

## Settlement gate

A season settles at `Deadline_Policy::settlement_instant( $season )` (23:59:59
Europe/London on 31 January following the season). After that instant the season is
final: `Deadline_Policy::is_settled()` is the single rule, and it is applied in three
places that each keep their own concern. `Awards::confirm()` refuses a **new** award
for a settled season with status `settled` before any ledger row is written, while
**withdrawal stays ungated** so a pre-settlement award can still be reversed after
settlement. `Events::approve()`/`Events::correct()` turn that refusal into a 409 and
let the whole transaction roll back, so a post-settlement approval writes nothing —
not the event status, not the published death date, not a ledger row. The read model
only reports finality: `Standings::is_final()` labels a season's standings as final
(rather than live) on `/standings`, and `Events::board()` marks a settled death as
uncorrectable on `/review`. No new routes were added; the deadline lock is untouched.

## Confirmed death to standings

`Runtime::confirm_death( $uuid )` reconciles awards for a confirmed death: the
pure `Obitleague\Domain\Scoring` rules decide the points, and `Awards` fans the
award out to every submitted team that picked the person, recording a signed,
per-(team, pick, event) delta in the append-only `awards` ledger (a withdrawal is
a negative delta, never an edit). Each team's award passes through the pure
`Deadline_Policy::death_scores_for_pick` floor *when it is written*, so a death
scores only for teams that submitted at or before that death's midnight instant.
Because awards belong to a team and pick, an amendment changes only the picks it
changes: a surviving pick keeps its earned rows, a removed pick stops being
counted, and a newly added pick is scored by later confirmations under the
team's new instant. `GET /standings` then aggregates the ledger per team and
ranks with `Scoring::team_total` and `Ranking::competition_rank`; the private
team page shows its owner their live rank and points via `Standings::for_account`.
Behaviour is pinned by `tests/standalone-runtime.php`, which also exercises the
real HTTP entry point, the floor in both directions, and the amendment cycle.
