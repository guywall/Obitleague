<?php
global $wpdb;

$main = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}obitleague_leagues WHERE is_main = 1 AND main_season_key = 2026 ORDER BY id DESC LIMIT 1" );
echo "main league id: {$main}\n";

$gen = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}obitleague_standings_generations WHERE league_id = %d AND season = %d AND is_current = 1 ORDER BY id DESC LIMIT 1",
		$main,
		2026
	)
);
echo "current generation: {$gen}\n";

$spread = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT scoring_picks, COUNT(*) AS n FROM {$wpdb->prefix}obitleague_standings_rows WHERE generation_id = %d GROUP BY scoring_picks ORDER BY scoring_picks",
		$gen
	)
);
echo "standings spread: ";
foreach ( $spread as $s ) {
	echo (int) $s->scoring_picks . 'x' . (int) $s->n . ' ';
}
echo "\n";

$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT s.rank_pos, u.display_name, s.points, s.scoring_picks
		 FROM {$wpdb->prefix}obitleague_standings_rows s
		 JOIN {$wpdb->users} u ON u.ID = s.user_id
		 WHERE s.generation_id = %d ORDER BY s.rank_pos ASC LIMIT 6",
		$gen
	)
);
foreach ( $rows as $r ) {
	printf( "  %d. %s %d pts (%d scoring)\n", (int) $r->rank_pos, $r->display_name, (int) $r->points, (int) $r->scoring_picks );
}

$zero = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_standings_rows WHERE generation_id = %d AND points = 0",
		$gen
	)
);
echo "teams on 0 points: {$zero}\n";
