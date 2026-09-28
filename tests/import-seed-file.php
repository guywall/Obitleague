<?php
/**
 * Generic seed importer: load any builder-produced seed file into the catalogue.
 *
 * `demo-import-people.php` knows the three demo files by name. This one takes
 * a path, so a new cohort — such as work/seed-cohort-1946.json, built by
 * work/build-cohort-1946.cjs — can be loaded without editing a script.
 *
 * People are imported as private candidates, which is how Import_Service
 * stores everything. Pass --approve to publish them in the same run; without
 * it nothing becomes public and nothing needs an editor.
 *
 * Run:
 *   wp eval-file tests/import-seed-file.php <path>
 *
 * The switches are read from the environment rather than the command line,
 * because WP-CLI validates `eval-file` arguments against its own synopsis and
 * rejects an unknown `--flag` before the script ever sees it:
 *
 *   OBITLEAGUE_SEED_APPROVE=1   publish the imported people in the same run
 *   OBITLEAGUE_SEED_LIMIT=10    import only the first N rows (a trial run)
 *   OBITLEAGUE_ALLOW_SEED_IMPORT=1  required to write on a production site
 *
 * A bare `--flag` is still accepted when the caller can pass one through, so
 * the script stays usable from a plain PHP runner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Obitleague\Modules\Import_Service;

/** @var array<int,string> $args Passed by WP-CLI after the script path. */
$args = isset( $args ) && is_array( $args ) ? $args : array();

$path    = '';
$approve = false;
$limit   = 0;

foreach ( $args as $arg ) {
	if ( 0 === strpos( $arg, '--approve' ) ) {
		$approve = true;
	} elseif ( preg_match( '/^--limit=(\d+)$/', $arg, $m ) ) {
		$limit = (int) $m[1];
	} elseif ( '' === $path && '-' !== substr( $arg, 0, 1 ) ) {
		$path = $arg;
	}
}

// WP_CLI_ARGS is reserved by WP-CLI itself, so the fallback for callers that
// cannot pass arguments is a dedicated variable rather than the obvious one.
if ( '' === $path && getenv( 'OBITLEAGUE_SEED_FILE' ) ) {
	$path = (string) getenv( 'OBITLEAGUE_SEED_FILE' );
}

if ( ! $approve && getenv( 'OBITLEAGUE_SEED_APPROVE' ) ) {
	$approve = true;
}

if ( 0 === $limit && getenv( 'OBITLEAGUE_SEED_LIMIT' ) ) {
	$limit = (int) getenv( 'OBITLEAGUE_SEED_LIMIT' );
}

if ( '' === $path ) {
	fwrite( STDERR, "usage: wp eval-file tests/import-seed-file.php <path> [--approve] [--limit=N]\n" );
	exit( 1 );
}

if ( ! str_starts_with( $path, '/' ) ) {
	$path = dirname( __DIR__ ) . '/' . ltrim( $path, '/' );
}

if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "cannot read seed file: {$path}\n" );
	exit( 1 );
}

$environment = (string) ( getenv( 'WP_ENVIRONMENT_TYPE' ) ?: wp_get_environment_type() );
if ( 'production' === $environment && '1' !== getenv( 'OBITLEAGUE_ALLOW_SEED_IMPORT' ) ) {
	fwrite( STDERR, "refusing to import into a production environment; set OBITLEAGUE_ALLOW_SEED_IMPORT=1 to override\n" );
	exit( 1 );
}

$rows = json_decode( (string) file_get_contents( $path ), true );
if ( ! is_array( $rows ) || ! $rows ) {
	fwrite( STDERR, "seed file is not a non-empty JSON list: {$path}\n" );
	exit( 1 );
}

if ( $limit > 0 ) {
	$rows = array_slice( $rows, 0, $limit );
}

$imported = 0;
$approved = 0;
$failed   = 0;
$problem  = 0;

foreach ( $rows as $row ) {
	if ( ! is_array( $row ) || empty( $row['qid'] ) || empty( $row['name'] ) || empty( $row['birth'] ) ) {
		++$problem;
		continue;
	}

	$label = (string) $row['name'];

	try {
		$post_id = Import_Service::import_person(
			array(
				'qid'        => $row['qid'],
				'name'       => $label,
				'birth_date' => $row['birth'],
				'death_date' => $row['death'] ?? '',
				// The occupation is an editorial hint. It never becomes a
				// public occupation term on its own: People_Sync is the sole
				// writer of that taxonomy.
				'occupation' => $row['occupation'] ?? ( $row['role'] ?? '' ),
				'role'       => $row['role'] ?? '',
				'enwiki'     => $row['enwiki'] ?? '',
			)
		);
		++$imported;

		if ( $approve ) {
			Import_Service::approve_person( $post_id );
			++$approved;
		}
	} catch ( \Throwable $e ) {
		++$failed;
		fwrite( STDERR, "  ! {$label}: {$e->getMessage()}\n" );
	}
}

echo "file:      {$path}\n";
echo "rows:      " . count( $rows ) . "\n";
echo "imported:  {$imported}\n";
echo "approved:  {$approved}\n";
echo "failed:    {$failed}\n";
if ( $problem ) {
	echo "unusable:  {$problem} (missing qid, name or birth)\n";
}
if ( ! $approve ) {
	echo "\nCandidates are private. Add --approve to publish them in the same run.\n";
}
echo "Next: wp eval-file tests/sync-portraits.php  (fills portraits and occupation terms from Wikidata)\n";
