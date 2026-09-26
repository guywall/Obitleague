<?php
/**
 * Option storage helpers.
 *
 * Thin wrappers so domain-facing code never touches get_option directly.
 * Game-critical state (leagues, entries, awards) moves to plugin tables in
 * the next milestone; this store is for installation state only.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Support;

final class Options {

	public const DB_VERSION = 'obitleague_db_version';

	private function __construct() {}

	public static function get( string $key, $default = false ) {
		return \get_option( 'obitleague_' . $key, $default );
	}

	public static function set( string $key, $value ): bool {
		return \update_option( 'obitleague_' . $key, $value );
	}

	public static function delete( string $key ): bool {
		return \delete_option( 'obitleague_' . $key );
	}
}
