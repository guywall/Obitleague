<?php
/**
 * Sync portraits + occupations for published people from Wikidata.
 * Run via wp-cli eval-file. Bounded; safe to re-run (skips filled posts).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stats = \Obitleague\Modules\People_Sync::sync_all( 400 );
echo 'portraits+occupations: checked ' . $stats['checked'] . ', updated ' . $stats['updated'] . "\n";
