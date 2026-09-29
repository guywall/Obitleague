<?php
/**
 * Production probe: death-wire queue + sources status.
 * Usage: wp eval-file probe-death-wire.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
global $wpdb;

$rows = $wpdb->get_results(
	"SELECT id, request_kind, status, attempts, LEFT(last_error, 140) AS err
	 FROM {$wpdb->prefix}obitleague_wiki_queue
	 WHERE status != 'done'
	 ORDER BY id ASC
	 LIMIT 12"
);
echo "== queue (not done) ==\n";
foreach ( (array) $rows as $x ) {
	echo $x->id . ' ' . $x->request_kind . ' ' . $x->status . ' try=' . $x->attempts . ' | ' . $x->err . "\n";
}

$counts = $wpdb->get_results(
	"SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}obitleague_wiki_queue GROUP BY status"
);
echo "== queue totals ==\n";
foreach ( (array) $counts as $c ) {
	echo $c->status . ': ' . $c->n . "\n";
}

echo 'sources_rows=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_death_sources" ) . "\n";
echo 'feed_marked=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_feed_items WHERE wire_state != ''" ) . "\n";

$last = get_option( 'obitleague_death_wire_last_run' );
echo 'last_run=' . wp_json_encode( $last ) . "\n";
echo 'months_done=' . wp_json_encode( get_option( 'obitleague_death_wire_months_done' ) ) . "\n";
$pause = (int) get_option( 'obitleague_discovery_pause_until' );
echo 'wikimedia_pause=' . ( $pause > time() ? 'active until ' . gmdate( 'H:i:s', $pause ) : 'clear' ) . "\n";
