<?php
/**
 * Recompose the body content of every published person record.
 *
 * Person pages carry a body assembled from editor-approved facts (see
 * Person_Content). This backfills it for records published before that existed.
 * Safe to re-run: a record whose composed output already matches is skipped.
 *
 * Run: wp eval-file tests/build-person-content.php [limit]
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

use Obitleague\Modules\Person_Content;

$limit    = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 1000;
$stats    = Person_Content::regenerate_all( $limit );
WP_CLI::success(
	sprintf(
		'Person bodies: checked %d, wrote %d, unchanged %d.',
		$stats['checked'],
		$stats['written'],
		$stats['skipped']
	)
);
