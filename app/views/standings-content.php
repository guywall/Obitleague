<h1>Standings</h1>
<?php if ( ! empty( $final ) ) : ?>
<p class="success">Final standings — season <?= (int) $season ?> has settled; no further awards can be scored.</p>
<?php else : ?>
<p>Season <?= (int) $season ?>. Points come from confirmed deaths among each team’s ten picks; ties share a rank. These standings are live until the season settles.</p>
<?php endif; ?>
<?php if ( empty( $rows ) ) : ?>
<p class="ob-picks-empty">No submitted teams yet.</p>
<?php else : ?>
<table>
<thead><tr><th>Rank</th><th>Team</th><th>Points</th><th>Scoring picks</th></tr></thead>
<tbody>
<?php foreach ( $rows as $row ) : ?>
<tr><td><?= (int) $row['rank'] ?></td><td><?= htmlspecialchars( $row['player'], ENT_QUOTES, 'UTF-8' ) ?></td><td><?= (int) $row['points'] ?></td><td><?= (int) $row['scoring_picks'] ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
