<?php
/**
 * DEMO seed importer: people from the built seed files.
 *
 * Imports the Wikidata/Wikipedia-derived seed rows as private candidates,
 * then runs a demo editorial pass that approves them through the real
 * service. Real people only appear with facts sourced from the seed files;
 * nothing here invents a death.
 *
 * Run: wp eval-file <this-file>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Obitleague\Modules\Import_Service;

$work = dirname( __DIR__ ) . '/work';
$season = 2026;

$imported = 0;
$approved = 0;
$skipped  = 0;

$import_row = static function ( array $row ) use ( $season, &$imported, &$approved, &$skipped ): void {
	try {
		$post_id = Import_Service::import_person(
			array(
				'qid'        => $row['qid'],
				'name'       => $row['name'],
				'birth_date' => $row['birth'],
				'death_date' => $row['death'],
				// Kept as an editorial hint only; it never becomes a public
				// occupation term (see Import_Service::record_occupation_hint).
				'occupation' => $row['role'] ?? '',
				'role'       => $row['role'] ?? '',
				'enwiki'     => $row['enwiki'] ?? '',
			)
		);
		++$imported;

		Import_Service::approve_person( $post_id );
		++$approved;
	} catch ( \Throwable $e ) {
		++$skipped;
		echo '  ! ' . ( $row['name'] ?? '?' ) . ': ' . $e->getMessage() . "\n";
	}
};

echo "== importing 2026 deaths (public archive rows) ==\n";
foreach ( json_decode( file_get_contents( $work . '/seed-deaths-2026.json' ), true ) as $row ) {
	$import_row( $row );
}

echo "== importing pre-lock 2025 deaths (already-dead-at-lock demo) ==\n";
foreach ( json_decode( file_get_contents( $work . '/seed-prelock-deaths.json' ), true ) as $row ) {
	$import_row( $row );
}

echo "== importing living pool (selectable picks) ==\n";
foreach ( json_decode( file_get_contents( $work . '/seed-living-pool.json' ), true ) as $row ) {
	try {
		// No occupation here on purpose. This pool used to be imported with a
		// hardcoded "Public figure" occupation, which created a public
		// occupation archive page for that placeholder and tagged the whole
		// pickable pool with it. Occupations come from Wikidata via
		// People_Sync instead; run tests/sync-portraits.php afterwards.
		$post_id = Import_Service::import_person(
			array(
				'qid'        => $row['qid'],
				'name'       => $row['name'],
				'birth_date' => $row['birth'],
				'enwiki'     => $row['enwiki'] ?? '',
			)
		);
		++$imported;
		Import_Service::approve_person( $post_id );
		++$approved;
	} catch ( \Throwable $e ) {
		++$skipped;
		echo '  ! ' . ( $row['name'] ?? '?' ) . ': ' . $e->getMessage() . "\n";
	}
}

echo "\nimported={$imported} approved={$approved} skipped={$skipped}\n";
echo "Next: wp eval-file tests/sync-portraits.php  (fills portraits + occupations from Wikidata)\n";
