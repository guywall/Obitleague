<?php
/**
 * Season selector: which competitive year the site displays.
 *
 * The entry season (League_Service::current_season()) is what new players
 * register for; the in-play season (Pick_Stats::season_in_play()) is where
 * deaths, standings and scores actually happen. The selector lists every
 * season that has published standings data, defaults to the in-play year,
 * and is driven by a validated ?season= query argument so every competitive
 * view (standings, stats, archive, header mega menus) follows one choice.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Season_Switcher {

	private function __construct() {}

	public static function boot(): void {
	}

	/** Every season that has at least one published standings generation. */
	public static function seasons_with_data(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT DISTINCT season FROM {$wpdb->prefix}obitleague_standings_generations WHERE season > 0 ORDER BY season DESC"
		);
		$seasons = array();
		foreach ( (array) $rows as $row ) {
			$season = (int) $row;
			if ( $season > 2000 && $season < 2100 ) {
				$seasons[] = $season;
			}
		}
		// Always offer the in-play year so the selector is never empty on a
		// fresh install before the first standings publish.
		$in_play = Pick_Stats::season_in_play();
		if ( ! in_array( $in_play, $seasons, true ) ) {
			$seasons[] = $in_play;
			sort( $seasons, SORT_NUMERIC );
			$seasons = array_reverse( $seasons );
		}
		return $seasons;
	}

	/**
	 * The season to display: ?season= when valid, else the in-play year.
	 *
	 * Only seasons that exist in the data may be selected; anything else
	 * falls back silently rather than rendering empty competitive pages.
	 */
	public static function displayed_season(): int {
		$requested = isset( $_GET['season'] ) ? absint( (string) $_GET['season'] ) : 0;
		if ( $requested > 0 && in_array( $requested, self::seasons_with_data(), true ) ) {
			return $requested;
		}
		return Pick_Stats::season_in_play();
	}

	/** Query args that keep the current selection across links. */
	public static function season_arg( int $season ): string {
		return 'season=' . (int) $season;
	}

	/** True when the displayed season is not the default, i.e. a selection is active. */
	public static function is_active_selection(): bool {
		return self::displayed_season() !== Pick_Stats::season_in_play();
	}	/** The toggle as a string, for shortcode-built markup. */
	public static function toggle_html(): string {
		ob_start();
		self::render_toggle();
		return (string) ob_get_clean();
	}

	/** Render the season toggle: a segmented control for competitive pages. */
	public static function render_toggle(): void {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$seasons   = self::seasons_with_data();
		$displayed = self::displayed_season();
		$in_play   = Pick_Stats::season_in_play();

		if ( count( $seasons ) < 2 ) {
			return;
		}
		$rendered = true;

		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		?>
		<nav class="ob-season-toggle" aria-label="Season to display">
			<?php foreach ( $seasons as $season ) : ?>
				<?php
				$url   = esc_url( home_url( $path . '?season=' . (int) $season ) );
				$label = (string) $season;
				$title = 'Season ' . $season;
				if ( $season === $in_play ) {
					$title .= ' · in play';
				} elseif ( $season > $in_play ) {
					$title .= ' · entry season';
				} else {
					$title .= ' · archive';
				}
				$active = $season === $displayed;
				?>
				<a class="ob-season-toggle__option<?php echo $active ? ' is-active' : ''; ?>"
					href="<?php echo $url; ?>"
					title="<?php echo esc_attr( $title ); ?>"
					<?php echo $active ? 'aria-current="true"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}
}
