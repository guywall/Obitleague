<?php
/**
 * Sync portraits for published people from Wikidata P18.
 * Run via wp-cli eval-file. Bounded; safe to re-run (skips filled posts).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stats = \Obitleague\Modules\People_Sync::sync_all( 400 );
echo 'portraits: checked ' . $stats['checked'] . ', updated ' . $stats['updated'] . "\n";
