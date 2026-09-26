# Ruleset v1 (normative)

This is the build baseline for the game rules. The implementation lives in `src/Domain` and is covered by `tests/run-tests.php`. Store the ruleset version with each season and entry so later changes cannot silently alter a running competition.

## Season and deadlines

| Rule | Baseline |
| --- | --- |
| Season | Calendar year, 1 January – 31 December. |
| Entry deadline | `00:00 Europe/London` on 1 January. A write must **commit strictly before** this instant — a write that lands exactly on the deadline is late. |
| Team size | Exactly ten distinct people. |
| Entries | One entry per player per league per season. Different players may hold the same pick. |
| Privacy | Only the owner and authorised administrators see a team before lock; after lock, submitted teams are visible to league members. |
| Late membership | New members after lock are spectators; a team must already be submitted in that league to score there. |

If a submitted pick dies before the season begins, the player may replace it while entries remain open. Discovered after lock: the pick earns zero and stays in the historical entry — no retrospective replacements.

## Scoring

```
points(pick) = max(1, 100 − completed_age_at_death)
total        = sum over ten picks
```

- Completed age is computed by calendar birthday, never by dividing elapsed days by 365.
- 29 February birthday: use 1 March as the birthday in a non-leap year.
- Rank by total points, then by number of scoring picks; equal on both → shared position (competition ranking: 1, 2, 2, 4).
- No bonuses for cause, timing, uniqueness or celebrity category in v1.
- A person already dead at lock scores zero; the entry keeps the pick and explains why.
- Deaths are scored against the **verified death date**, not the article date. Publication, discovery and approval timestamps are stored separately.

## Situations

| Situation | Required result |
| --- | --- |
| Death confirmed, date unclear | Publish with an honest date label; hold scoring until an exact date is established. |
| Date is a month or range | Keep its precision; never invent a day. |
| Cause not disclosed | Score normally once identity, date and age are verified; display "not disclosed". |
| 31 December death reported in January | Scores the prior season if confirmed within its settlement period. |
| Date corrected | Recalculate, write an adjustment, show the correction. |
| Hoax or mistaken identity | Retract, reverse the awards, notify recipients of the original notification. |
| Historical seed record | Never scored unless it matches a genuine submitted entry and the season's rules. |

## Settlement and disputes

Standings stay provisional until `23:59:59 Europe/London` on 31 January following the season. Deaths approved by that cut-off receive new awards; later discoveries update the archive but never create awards for a settled season. Corrections to awards already made remain possible after settlement, with a visible amended result.

Every score change must be traceable to a corrected event, an eligibility decision or a documented adjustment linked to the original award. Administrators cannot enter an unexplained total.
