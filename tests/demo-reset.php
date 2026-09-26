<?php
/* DEMO RESET: wipe game + demo data, keep sources/feed items/plugin schema. */
global $wpdb;
foreach ( array(
	'obitleague_standings_rows', 'obitleague_standings_generations', 'obitleague_outbox',
	'obitleague_review_cases', 'obitleague_events', 'obitleague_awards',
	'obitleague_entry_picks', 'obitleague_entry_revisions', 'obitleague_entries',
	'obitleague_league_members', 'obitleague_leagues',
) as $table ) {
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
}
$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'obit_person' ) );
foreach ( (array) $ids as $id ) {
	wp_delete_post( (int) $id, true );
}
$logins = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s", 'obitleague_demo_%' ) );
foreach ( (array) $logins as $id ) {
	wp_delete_user( (int) $id );
}
echo "reset done\n";
