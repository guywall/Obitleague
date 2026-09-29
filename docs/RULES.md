# Rules (normative)

This is the build baseline for the game rules. The implementation lives in `src/Domain` and is covered by `tests/run-tests.php`. Store the ruleset version with each season and entry so later changes cannot silently alter a running competition.

## Season and deadlines

| Rule | Baseline |
| --- | --- |
| Season | Calendar year, 1 January – 31 December. |
| Entry window (rolling) | Entries stay open for the **whole season year**: a write must commit strictly before `23:59:59 Europe/London` on 31 December of the season year. Players may join, submit and amend at any point in the year. |
| Age eligibility anchor | A person must meet the minimum age at the **season start** (`00:00 Europe/London` on 1 January of the season year), regardless of when the team joins. The previous baseline used the 1 January lock instant as a proxy; that proxy is now named explicitly. |
| Team size | Exactly ten distinct people. |
| Entries | One entry per player per league per season. Different players may hold the same pick. |
| Privacy | Only the owner and authorised administrators see a team while its season's entry window is open. After the window closes, submitted teams are visible to league members. |
| Late membership | A team must already be submitted in that league to score there; joins after the entry window closes create spectators. |

## Rolling entry and the submission floor

Under rolling entry a team may join at any point in the year. Two rules keep
late entry honest without handicaps or bonuses:

1. **A selection scores only when the verified death date is after the team's
   own submission instant** (equal instant does not score). The floor is never
   earlier than the season start. There are no retrospective points: if your
   pick died in March and you joined in November, that pick scores zero.
2. **Late joiners receive no artificial adjustments** — no handicaps, bonuses
   or rescaled scores. Everyone competes under the same formula.

Entries submitted before the rolling-entry change (all pre-2027 seasons and any
entry committed before 1 January) keep exactly their previous scoring: the
season start equals the latest instant any such entry was submitted by, so the
floor never moves a historic award.

## Scoring

```
points(pick) = max(1, 100 − completed_age_at_death)
total        = sum over ten picks
```

- Completed age is computed by calendar birthday, never by dividing elapsed days by 365.
- 29 February birthday: use 1 March as the birthday in a non-leap year.
- Rank by total points, then by number of scoring picks; equal on both → shared position (competition ranking: 1, 2, 2, 4).
- No bonuses for cause, timing, uniqueness or celebrity category.
- A person already dead when the team submits scores zero; the entry keeps the pick and explains why.
- Deaths are scored against the **verified death date**, not the article date. Publication, discovery and approval timestamps are stored separately.
- Deaths reported after they occurred follow the editorial verification process; an exact verified date governs which team's floor it beats. Disputed dates stay unpublished until resolved.

## Humans and AI

Every participant — human or AI agent — holds the same kind of entry in the
same league and follows the rules on this page identically. AI competitors are
ordinary participant accounts with agent metadata; they use the same deadline,
the same ten-pick validation, the same submission floor and the same scoring
formula. There is no separate AI competition engine.

## Situations

| Situation | Required result |
| --- | --- |
| Death confirmed, date unclear | Publish with an honest date label; hold scoring until an exact date is established. |
| Date is a month or range | Keep its precision; never invent a day. |
| Cause not disclosed | Score normally once identity, date and age are verified; display "not disclosed". |
| 31 December death reported in January | Scores the prior season if confirmed within its settlement period. |
| Date corrected | Recalculate, write an adjustment, show the correction. A correction can newly clear or newly miss a team's submission floor; the adjustment is always visible. |
| Hoax or mistaken identity | Retract, reverse the awards, notify recipients of the original notification. |
| Historical seed record | Never scored unless it matches a genuine submitted entry and the season's rules. |

## Settlement and disputes

Standings stay provisional until `23:59:59 Europe/London` on 31 January following the season. Deaths approved by that cut-off receive new awards; later discoveries update the archive but never create awards for a settled season. Corrections to awards already made remain possible after settlement, with a visible amended result.

Every score change must be traceable to a corrected event, an eligibility decision or a documented adjustment linked to the original award. Administrators cannot enter an unexplained total.
