<?php
/**
 * Public search metadata and crawl control.
 *
 * The catalogue of people and the occupation archive are the site's organic
 * surface, so this module owns the metadata search engines read: per-person
 * titles and descriptions, canonical URLs, Open Graph / Twitter cards, and
 * Person structured data built from the approved facts already stored.
 *
 * It also marks account, team, league and forum routes as noindex, follow.
 * Those pages are private by default and thin by design; the architecture
 * notes already require them to stay out of shared caches and sitemaps.
 *
 * Every value is derived from approved record fields. Nothing here invents a
 * fact, and partial dates are never widened to a day-precision claim.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Age;
use Obitleague\Domain\Value\Cause_Status;
use Obitleague\Domain\Value\Ruleset;

final class Seo {

	/** Routes that must never enter a search index. */
	private const PRIVATE_ROUTES = array( 'my-leagues', 'join', 'register', 'verify-email', 'team', 'league', 'forum' );

	private function __construct() {}

	public static function boot(): void {
		add_filter( 'document_title_parts', array( self::class, 'title_parts' ) );
		add_filter( 'document_title_separator', array( self::class, 'title_separator' ) );
		add_action( 'wp_head', array( self::class, 'head_meta' ), 1 );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	/* ---------- titles ---------- */

	public static function title_separator(): string {
		return '·';
	}

	/**
	 * Per-person and per-occupation titles. Page titles elsewhere keep the
	 * theme default so the site's own pages are never double-suffixed.
	 *
	 * @param array<string,string> $parts Title parts.
	 * @return array<string,string>
	 */
	public static function title_parts( array $parts ): array {
		if ( is_singular( Catalogue::POST_TYPE ) ) {
			$post_id = (int) get_the_ID();
			$name    = (string) get_the_title( $post_id );
			$span    = self::life_span( $post_id );
			$parts['title'] = '' !== $span ? $name . ' (' . $span . ')' : $name;
			return $parts;
		}

		if ( is_tax( Catalogue::TAX_OCCUPATION ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$parts['title'] = sprintf( '%s deaths and picks', $term->name );
			}
			return $parts;
		}

		if ( is_post_type_archive( Catalogue::POST_TYPE ) ) {
			$parts['title'] = 'People catalogue';
			return $parts;
		}

		return $parts;
	}

	/* ---------- crawl control ---------- */

	/**
	 * Account, team, league and forum routes are private or thin: keep them
	 * out of the index but let crawlers follow the links they contain.
	 *
	 * @param array<string,bool> $robots Robots directives.
	 * @return array<string,bool>
	 */
	public static function robots( array $robots ): array {
		if ( self::is_private_route() ) {
			$robots['noindex']  = true;
			$robots['follow']   = true;
			$robots['nofollow'] = false;
		}
		return $robots;
	}

	private static function is_private_route(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}
		if ( is_page() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				return in_array( (string) $post->post_name, self::PRIVATE_ROUTES, true );
			}
		}
		foreach ( array( 'ob_team_id', 'ob_league_id', 'ob_forum', 'ob_forum_topic' ) as $var ) {
			if ( '' !== (string) get_query_var( $var ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- head metadata ---------- */

	public static function head_meta(): void {
		$description = self::description();
		$canonical   = self::canonical();
		$image       = self::social_image();

		echo "\n<!-- Obitleague -->\n";

		if ( '' !== $description ) {
			printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $description ) );
		}
		if ( '' !== $canonical ) {
			printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( $canonical ) );
		}

		$title = wp_get_document_title();
		if ( '' !== $title ) {
			printf( "<meta property=\"og:type\" content=\"website\" />\n" );
			printf( "<meta property=\"og:title\" content=\"%s\" />\n", esc_attr( $title ) );
			printf( "<meta property=\"og:site_name\" content=\"%s\" />\n", esc_attr( (string) get_bloginfo( 'name' ) ) );
			if ( '' !== $canonical ) {
				printf( "<meta property=\"og:url\" content=\"%s\" />\n", esc_url( $canonical ) );
			}
			if ( '' !== $description ) {
				printf( "<meta property=\"og:description\" content=\"%s\" />\n", esc_attr( $description ) );
			}
			if ( '' !== $image ) {
				printf( "<meta property=\"og:image\" content=\"%s\" />\n", esc_url( $image ) );
				printf( "<meta name=\"twitter:card\" content=\"summary_large_image\" />\n" );
			} else {
				printf( "<meta name=\"twitter:card\" content=\"summary\" />\n" );
			}
			printf( "<meta name=\"twitter:title\" content=\"%s\" />\n", esc_attr( $title ) );
			if ( '' !== $description ) {
				printf( "<meta name=\"twitter:description\" content=\"%s\" />\n", esc_attr( $description ) );
			}
			if ( '' !== $image ) {
				printf( "<meta name=\"twitter:image\" content=\"%s\" />\n", esc_url( $image ) );
			}
		}

		$schema = self::schema();
		if ( array() !== $schema ) {
			printf(
				"<script type=\"application/ld+json\">%s</script>\n",
				wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			);
		}
		echo "<!-- /Obitleague -->\n\n";
	}

	/* ---------- fact helpers ---------- */

	/** Exact Y-m-d only; partial dates must never be widened for a schema. */
	private static function exact_date( int $post_id, string $meta_key ): string {
		$raw = (string) get_post_meta( $post_id, $meta_key, true );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
	}

	/** "1942–2023" for a confirmed death, or "born 1942" while living. */
	private static function life_span( int $post_id ): string {
		$birth_raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
		$death_raw = (string) get_post_meta( $post_id, 'obit_death_date', true );
		$birth     = preg_match( '/^(\d{4})/', $birth_raw, $m ) ? (int) $m[1] : 0;
		$death     = preg_match( '/^(\d{4})/', $death_raw, $d ) ? (int) $d[1] : 0;

		if ( $birth && $death ) {
			return $birth . '–' . $death;
		}
		if ( $birth ) {
			return 'born ' . $birth;
		}
		if ( $death ) {
			return 'died ' . $death;
		}
		return '';
	}

	/** Age at death, or null when it cannot be stated exactly. */
	private static function age_at_death( int $post_id ): ?int {
		$birth_raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
		$death_raw = (string) get_post_meta( $post_id, 'obit_death_date', true );
		if ( '' === $birth_raw || '' === $death_raw ) {
			return null;
		}
		try {
			$birth = Catalogue::partial_date_from_stored( $birth_raw );
			$death = Catalogue::partial_date_from_stored( $death_raw );
			if ( ! $birth->is_exact() || ! $death->is_exact() ) {
				return null;
			}
			return Age::completed_at( $birth, $death->interpretations()[0] );
		} catch ( \InvalidArgumentException ) {
			return null;
		}
	}

	private static function description(): string {
		if ( is_singular( Catalogue::POST_TYPE ) ) {
			return self::person_description( (int) get_the_ID() );
		}
		if ( is_tax( Catalogue::TAX_OCCUPATION ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$count = (int) ( $term->count ?? 0 );
				return sprintf(
					'%s in the Obitleague catalogue: %d %s with approved birth dates, pickable while living, and confirmed death records where an editor has approved them.',
					$term->name,
					$count,
					1 === $count ? 'person' : 'people'
				);
			}
		}
		return (string) get_bloginfo( 'description' );
	}

	/**
	 * A factual, dignified summary for one person. Only approved fields are
	 * used, and the wording stays neutral.
	 */
	private static function person_description( int $post_id ): string {
		$name  = (string) get_the_title( $post_id );
		$role  = (string) get_post_meta( $post_id, 'obit_role', true );
		$occs  = People_Sync::occupation_labels( $post_id );
		$birth = (string) get_post_meta( $post_id, 'obit_birth_date', true );
		$death = (string) get_post_meta( $post_id, 'obit_death_date', true );
		$age   = self::age_at_death( $post_id );

		$descriptor = '' !== $role ? $role : ( array() !== $occs ? implode( ', ', array_slice( $occs, 0, 2 ) ) : '' );
		$parts      = array( $name );

		if ( '' !== $descriptor ) {
			$parts[] = $descriptor;
		}

		if ( '' !== $death ) {
			$year = preg_match( '/^(\d{4})/', $death, $m ) ? $m[1] : '';
			$bits = array();
			if ( '' !== $year ) {
				$bits[] = 'confirmed death in ' . $year;
			}
			if ( null !== $age ) {
				$bits[] = 'aged ' . $age;
			}
			if ( array() !== $bits ) {
				$parts[] = implode( ', ', $bits );
			}
		} elseif ( '' !== $birth ) {
			$year = preg_match( '/^(\d{4})/', $birth, $m ) ? $m[1] : '';
			if ( '' !== $year ) {
				$parts[] = 'born ' . $year;
			}
		}

		$sentence = implode( ' · ', $parts ) . '.';
		if ( '' !== $death ) {
			$sentence .= ' Facts on this page are editor-approved.';
		} else {
			$sentence .= ' Pickable in the Obitleague catalogue while living.';
		}

		return self::truncate( $sentence, 300 );
	}

	private static function truncate( string $text, int $max ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = substr( $text, 0, $max );
		$sp  = strrpos( $cut, ' ' );
		return rtrim( false === $sp ? $cut : substr( $cut, 0, $sp ), " \t\n\r,;" ) . '…';
	}

	private static function canonical(): string {
		if ( is_singular() ) {
			$link = get_permalink( (int) get_the_ID() );
			return $link ? (string) $link : '';
		}
		if ( is_tax() || is_category() || is_tag() ) {
			$link = get_term_link( (int) get_queried_object_id() );
			return is_wp_error( $link ) ? '' : (string) $link;
		}
		if ( is_post_type_archive() ) {
			$link = get_post_type_archive_link( Catalogue::POST_TYPE );
			return $link ? (string) $link : '';
		}
		return '';
	}

	/** Prefer the synced Wikimedia portrait so shares render with an image. */
	private static function social_image(): string {
		if ( ! is_singular( Catalogue::POST_TYPE ) ) {
			return '';
		}
		$portrait = (string) get_post_meta( (int) get_the_ID(), People_Sync::META_IMAGE_URL, true );
		return '' !== $portrait ? $portrait : '';
	}

	/**
	 * Person structured data for a single profile, plus WebSite for the
	 * front page. Only exact dates are emitted; a partial date is omitted
	 * rather than guessed, because schema.org dates are assertions.
	 *
	 * @return array<string,mixed>
	 */
	private static function schema(): array {
		if ( is_front_page() ) {
			return array(
				'@context' => 'https://schema.org',
				'@type'    => 'WebSite',
				'name'     => (string) get_bloginfo( 'name' ),
				'url'      => home_url( '/' ),
			);
		}

		if ( ! is_singular( Catalogue::POST_TYPE ) ) {
			return array();
		}

		$post_id = (int) get_the_ID();
		$name    = (string) get_the_title( $post_id );
		$role    = (string) get_post_meta( $post_id, 'obit_role', true );
		$qid     = (string) get_post_meta( $post_id, 'obit_qid', true );
		$enwiki  = (string) get_post_meta( $post_id, 'obit_enwiki', true );
		$occs     = People_Sync::occupation_labels( $post_id );

		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Person',
			'name'     => $name,
			'url'      => (string) get_permalink( $post_id ),
		);

		$description = self::person_description( $post_id );
		if ( '' !== $description ) {
			$data['description'] = $description;
		}

		$birth = self::exact_date( $post_id, 'obit_birth_date' );
		if ( '' !== $birth ) {
			$data['birthDate'] = $birth;
		}
		$death = self::exact_date( $post_id, 'obit_death_date' );
		if ( '' !== $death ) {
			$data['deathDate'] = $death;
		}
		if ( '' !== $role ) {
			$data['jobTitle'] = $role;
		}
		if ( array() !== $occs ) {
			$data['hasOccupation'] = $occs;
		}

		$portrait = (string) get_post_meta( $post_id, People_Sync::META_IMAGE_URL, true );
		if ( '' !== $portrait ) {
			$data['image'] = $portrait;
		}

		$same_as = array();
		if ( '' !== $enwiki ) {
			$same_as[] = 'https://en.wikipedia.org/wiki/' . rawurlencode( $enwiki );
		}
		if ( '' !== $qid ) {
			$same_as[] = 'https://www.wikidata.org/wiki/' . rawurlencode( $qid );
		}
		if ( array() !== $same_as ) {
			$data['sameAs'] = $same_as;
		}

		$cause = (string) get_post_meta( $post_id, 'obit_cause_text', true );
		if ( '' !== $cause ) {
			$data['description'] = rtrim( $description, '.' ) . ' · cause of death: ' . $cause . '.';
		}

		return $data;
	}
}
