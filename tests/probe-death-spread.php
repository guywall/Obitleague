<?php
global $wpdb;

$dead_ids = $wpdb->get_col(
	"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'obit_death_date' AND m.meta_value != ''
	 WHERE p.post_type = 'obit_person'"
);
echo "dead people (any status): " . count( $dead_ids ) . "\n";
if ( ! $dead_ids ) {
	return;
}
$in = implode( ',', array_map( 'intval', $dead_ids ) );
$dead_uuids = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'obit_uuid' AND post_id IN ($in)" );
echo "dead uuids: " . count( $dead_uuids ) . "\n";

$placeholders = implode( ',', array_fill( 0, count( $dead_uuids ), '%s' ) );
$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT r.entry_id, COUNT(DISTINCT p.person_uuid) AS n
		 FROM {$wpdb->prefix}obitleague_entry_picks p
		 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'
		 WHERE p.person_uuid IN ($placeholders)
		 GROUP BY r.entry_id",
		...$dead_uuids
	)
);
$dist = array();
foreach ( $rows as $r ) {
	$dist[ $r->n ] = ( $dist[ $r->n ] ?? 0 ) + 1;
}
ksort( $dist );
echo "entries by dead-pick count: " . json_encode( $dist ) . "\n";

$awards = $wpdb->get_results( "SELECT COUNT(*) AS n, SUM(award_delta) AS pts FROM {$wpdb->prefix}obitleague_awards WHERE season = 2026" );
echo "2026 awards rows: " . (int) $awards[0]->n . " total points: " . (int) $awards[0]->pts . "\n";

// How many events exist for 2026 (approved deaths)?
$events = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_events WHERE approved_at IS NOT NULL AND retracted_at IS NULL AND death_date IS NOT NULL" );
echo "approved death events: " . (int) $events . "\n";

// Sample a two-death entry if any exist in picks but not awards.
$two = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT r.entry_id FROM {$wpdb->prefix}obitleague_entry_picks p
		 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'
		 WHERE p.person_uuid IN ($placeholders)
		 GROUP BY r.entry_id HAVING COUNT(DISTINCT p.person_uuid) >= 2 LIMIT 3",
		...$dead_uuids
	)
);
echo "sample 2-death entries: " . json_encode( $two ) . "\n";
