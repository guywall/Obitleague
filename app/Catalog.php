<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

use Obitleague\Domain\Discovery_Rules;

/**
 * Reads the people catalogue and owns pick selectability.
 *
 * {@see self::selectable()} is the single source of truth for whether a person
 * may be picked, so the picker list and the team save path can never diverge.
 * The public catalogue (`search`) is an editorial listing, not a pick list, and
 * deliberately does not apply the season age rule.
 */
final class Catalog {
	public function __construct( private Database $db ) {
	}

	/** Query people for the public surfaces, honouring the supported filters. */
	public function search( int $limit, array $query = array() ): array {
		$sql = 'SELECT uuid, name, birth_date, death_date FROM people WHERE ';
		$args = array();
		if ( '0' === (string) ( $query['living'] ?? '1' ) ) {
			$sql .= "eligibility = 'approved' AND death_date IS NOT NULL";
		} else {
			$sql .= "eligibility = 'approved' AND death_date IS NULL";
		}
		if ( isset( $query['season'] ) ) {
			$sql .= ' AND substr(death_date, 1, 4) = ?';
			$args[] = (string) (int) $query['season'];
		}
		if ( '' !== trim( (string) ( $query['q'] ?? '' ) ) ) {
			$sql .= ' AND lower(name) LIKE lower(?)';
			$args[] = '%' . trim( (string) $query['q'] ) . '%';
		}
		$order = isset( $query['recent_deaths'] ) ? ' ORDER BY death_date DESC, name COLLATE NOCASE' : ' ORDER BY name COLLATE NOCASE';
		$sql .= $order . ' LIMIT ' . max( 1, min( 100, $limit ) );
		$stmt = $this->db->pdo()->prepare( $sql );
		$stmt->execute( $args );
		return $stmt->fetchAll();
	}

	/**
	 * Whether a person may be picked for a season.
	 *
	 * The plugin's documented selectability: approved eligibility, no recorded
	 * death, an exact valid birth date, and old enough at the season start.
	 */
	public function selectable( array $person, int $season ): bool {
		return 'approved' === (string) $person['eligibility']
			&& null === $person['death_date']
			&& Discovery_Rules::is_old_enough( (string) $person['birth_date'], $season );
	}

	/**
	 * People a team may pick for a season, plus the team's own previously
	 * selected people so a now-unselectable pick still renders as selected.
	 *
	 * @param array<int, string> $selected
	 * @return list<array>
	 */
	public function picker( array $selected, int $season ): array {
		$stmt = $this->db->pdo()->prepare( "SELECT uuid, name, birth_date, death_date, eligibility FROM people WHERE eligibility = 'approved' AND death_date IS NULL ORDER BY name COLLATE NOCASE" );
		$stmt->execute();
		$people = array();
		$seen   = array();
		foreach ( $stmt->fetchAll() as $person ) {
			if ( $this->selectable( $person, $season ) ) {
				$people[] = $person;
				$seen[ (string) $person['uuid'] ] = true;
			}
		}
		foreach ( array_values( array_filter( $selected, 'is_string' ) ) as $uuid ) {
			if ( isset( $seen[ $uuid ] ) ) {
				continue;
			}
			$person = $this->find( $uuid );
			if ( $person ) {
				$people[] = $person;
				$seen[ $uuid ] = true;
			}
		}
		return $people;
	}

	/** Fetch one person row or null. */
	public function find( string $uuid ): ?array {
		$stmt = $this->db->pdo()->prepare( 'SELECT * FROM people WHERE uuid = ?' );
		$stmt->execute( array( $uuid ) );
		$row = $stmt->fetch();
		return $row ?: null;
	}
}
