<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

use PDO;
use Throwable;

/** Owns the SQLite connection, the schema bootstrap, and atomic transactions. */
final class Database {
	private PDO $db;
	private string $root;
	private Clock $clock;

	public function __construct( string $database, string $root, Clock $clock ) {
		$this->root = rtrim( $root, '/\\' );
		$this->clock = $clock;
		$this->db = new PDO( 'sqlite:' . $database, null, null, array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		) );
		$this->db->exec( 'PRAGMA foreign_keys = ON' );
		$this->db->exec( 'PRAGMA busy_timeout = 5000' );
		$this->db->exec( (string) file_get_contents( $this->root . '/app/schema.sql' ) );
		$this->db->prepare( 'INSERT OR IGNORE INTO schema_migrations (version, applied_at) VALUES (1, ?)' )->execute( array( $this->clock->now() ) );
	}

	public function pdo(): PDO {
		return $this->db;
	}

	/**
	 * Run a unit of work atomically, rolling back if it throws.
	 *
	 * Reentrant: a unit that opens a transaction may call another that does too
	 * (e.g. approving an event reconciles the award ledger). The inner call joins
	 * the outer transaction, so the whole operation commits or rolls back as one.
	 */
	public function transaction( callable $work ): mixed {
		if ( $this->db->inTransaction() ) {
			return $work();
		}
		$this->db->beginTransaction();
		try {
			$result = $work();
			$this->db->commit();
			return $result;
		} catch ( Throwable $error ) {
			if ( $this->db->inTransaction() ) {
				$this->db->rollBack();
			}
			throw $error;
		}
	}
}
