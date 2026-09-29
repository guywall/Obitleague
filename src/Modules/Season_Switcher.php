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
	}

	/** Render the header selector: a small <select> styled like the old badge. */
	public static function render(): void {
		$seasons   = self::seasons_with_data();
		$displayed = self::displayed_season();
		$in_play   = Pick_Stats::season_in_play();

		if ( count( $seasons ) < 2 ) {
			// Single season: keep the plain badge, no selector chrome.
			echo '<span class="ob-header__season">' . esc_html( (string) $displayed ) . '</span>';
			return;
		}

		?>
		<label class="ob-season-select" aria-label="Season to display">
			<span class="screen-reader-text">Season to display</span>
			<select id="ob-season-select" data-ob-season>
				<?php foreach ( $seasons as $season ) : ?>
					<option value="<?php echo esc_attr( (string) $season ); ?>" <?php selected( $season, $displayed ); ?>>
						<?php
						$label = (string) $season;
						if ( $season === $in_play ) {
							$label .= ' · in play';
						} elseif ( $season > $in_play ) {
							$label .= ' · entry';
						}
						echo esc_html( $label );
						?>				</option>
			<?php endforeach; ?>
			</select>
		</label>
		<script>
		(function(){
			var sel=document.getElementById('ob-season-select');
			if(!sel){return;}
			sel.addEventListener('change',function(){
				var url=new URL(window.location.href);
				url.searchParams.set('season',sel.value);
				window.location.href=url.toString();
			});
		})();
		</script>
		<?php
	}
}
