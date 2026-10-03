<h1>Review deaths</h1>
<p>Report a death, then approve it to publish and score it. An approved death can be corrected (date or cause) or retracted to withdraw its award.</p>
<?php if ( ! empty( $notice ) ) : ?><p class="success"><?= htmlspecialchars( $notice, ENT_QUOTES, 'UTF-8' ) ?></p><?php endif; ?>
<?php
$reported = array_values( array_filter( $events, static fn ( array $event ): bool => 'reported' === (string) $event['status'] ) );
$approved = array_values( array_filter( $events, static fn ( array $event ): bool => 'approved' === (string) $event['status'] ) );
$csrf = htmlspecialchars( $account['csrf_token'], ENT_QUOTES, 'UTF-8' );
?>

<section class="card">
<h2>Report a death</h2>
<form method="post" action="/review/report">
<input type="hidden" name="_csrf" value="<?= $csrf ?>">
<label for="person_uuid">Person UUID</label>
<input type="text" id="person_uuid" name="person_uuid" required>
<label for="death_date">Death date (YYYY-MM-DD)</label>
<input type="text" id="death_date" name="death_date" placeholder="2027-06-15" required>
<label for="cause_status">Cause status</label>
<select id="cause_status" name="cause_status">
<option value="not_disclosed">Not disclosed</option>
<option value="pending_official">Awaiting official confirmation</option>
<option value="confirmed">Confirmed</option>
<option value="contested">Under review</option>
</select>
<label for="cause">Cause wording (required when confirmed)</label>
<input type="text" id="cause" name="cause">
<p><button type="submit">Report</button></p>
</form>
</section>

<section class="card">
<h2>Awaiting review</h2>
<?php if ( empty( $reported ) ) : ?>
<p class="ob-picks-empty">No deaths await review.</p>
<?php else : ?>
<table>
<thead><tr><th>Person</th><th>Date</th><th>Cause</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ( $reported as $event ) : ?>
<tr>
<td><?= htmlspecialchars( (string) $event['name'], ENT_QUOTES, 'UTF-8' ) ?></td>
<td><?= htmlspecialchars( (string) $event['death_date'], ENT_QUOTES, 'UTF-8' ) ?></td>
<td><?= htmlspecialchars( (string) $event['cause_status'], ENT_QUOTES, 'UTF-8' ) ?></td>
<td><?php if ( ! empty( $event['settled'] ) ) : ?><span class="error">Season settled — cannot score</span><?php else : ?><form method="post" action="/review/approve"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><button type="submit">Approve</button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</section>

<section class="card">
<h2>Approved deaths</h2>
<?php if ( empty( $approved ) ) : ?>
<p class="ob-picks-empty">No approved deaths yet.</p>
<?php else : ?>
<?php foreach ( $approved as $event ) : ?>
<div class="person">
<strong><?= htmlspecialchars( (string) $event['name'], ENT_QUOTES, 'UTF-8' ) ?></strong>
<small>Death <?= htmlspecialchars( (string) $event['death_date'], ENT_QUOTES, 'UTF-8' ) ?> · revision <?= (int) $event['revision'] ?> · <?= htmlspecialchars( (string) $event['cause_status'], ENT_QUOTES, 'UTF-8' ) ?><?php if ( ! empty( $event['settled'] ) ) : ?> · <span class="error">season settled — date corrections refused, retraction still allowed</span><?php endif; ?></small>
<div class="row">
<form method="post" action="/review/correct">
<input type="hidden" name="_csrf" value="<?= $csrf ?>">
<input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
<label for="date-<?= (int) $event['id'] ?>">Date</label>
<input type="text" id="date-<?= (int) $event['id'] ?>" name="death_date" value="<?= htmlspecialchars( (string) $event['death_date'], ENT_QUOTES, 'UTF-8' ) ?>">
<label for="cause-<?= (int) $event['id'] ?>">Cause wording</label>
<input type="text" id="cause-<?= (int) $event['id'] ?>" name="cause" value="<?= htmlspecialchars( (string) ( $event['cause'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ?>">
<select name="cause_status">
<option value="not_disclosed"<?= 'not_disclosed' === (string) $event['cause_status'] ? ' selected' : '' ?>>Not disclosed</option>
<option value="pending_official"<?= 'pending_official' === (string) $event['cause_status'] ? ' selected' : '' ?>>Awaiting official confirmation</option>
<option value="confirmed"<?= 'confirmed' === (string) $event['cause_status'] ? ' selected' : '' ?>>Confirmed</option>
<option value="contested"<?= 'contested' === (string) $event['cause_status'] ? ' selected' : '' ?>>Under review</option>
</select>
<button type="submit">Correct</button>
</form>
<form method="post" action="/review/retract"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><button type="submit">Retract</button></form>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</section>
