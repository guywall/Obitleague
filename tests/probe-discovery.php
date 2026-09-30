<?php
global $wpdb;

$rows = $wpdb->get_results(
	"SELECT pm2.meta_value AS status, COUNT(DISTINCT p.ID) AS n
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = 'obit_discovery_source'
	 LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = 'obit_discovery_status'
	 WHERE p.post_type = 'obit_person' AND pm1.meta_value = 'wikidata-wdqs'
	 GROUP BY pm2.meta_value"
);
echo "discovery candidates by status: ";
foreach ( $rows as $r ) {
	echo ( $r->status ?: 'none' ) . '=' . (int) $r->n . ' ';
}
echo "\n";

$pending = $wpdb->get_results(
	"SELECT DISTINCT p.ID, p.post_title, p.post_status
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = 'obit_discovery_source' AND pm1.meta_value = 'wikidata-wdqs'
	 JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = 'obit_discovery_status' AND pm2.meta_value = 'pending'
	 LIMIT 20"
);
echo "pending rows: " . count( $pending ) . "\n";
foreach ( $pending as $p ) {
	$death = (string) get_post_meta( (int) $p->ID, 'obit_death_date', true );
	echo "  #{$p->ID} {$p->post_title} [{$p->post_status}] death='" . $death . "'\n";
}

// The five queued this morning, whatever their status now.
$named = $wpdb->get_results(
	"SELECT p.ID, p.post_title, p.post_status, pm2.meta_value AS status
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = 'obit_discovery_source' AND pm1.meta_value = 'wikidata-wdqs'
	 LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = 'obit_discovery_status'
	 WHERE p.post_title IN ( 'Ichiro Hosotani', 'Miguel Ángel Félix Gallardo', 'Mario Zenari', 'Joseph Deiss', 'John Piper' )"
);
foreach ( $named as $p ) {
	echo "morning-candidate #{$p->ID} {$p->post_title}: post_status={$p->post_status} discovery_status=" . ( $p->status ?: 'unset' ) . "\n";
}
