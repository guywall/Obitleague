<?php
/**
 * League standings widget.
 *
 * Membership is checked on every render — never trusted to Elementor display
 * conditions, page caches or client state. Non-members see a neutral prompt,
 * not another player's data. Rows come from the standings service via a
 * filter until the leagues module lands; the guard contract is final.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Elementor;

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

final class Widget_League_Standings extends \Elementor\Widget_Base {

	public function get_name(): string {
		return 'obitleague-league-standings';
	}

	public function get_title(): string {
		return \__( 'Obitleague Standings', 'obitleague' );
	}

	public function get_icon(): string {
		return 'eicon-table';
	}

	public function get_categories(): array {
		return array( 'general' );
	}

	public function get_keywords(): array {
		return array( 'obitleague', 'league', 'standings' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'section_content',
			array( 'label' => \__( 'League', 'obitleague' ) )
		);

		$this->add_control(
			'league_id',
			array(
				'label'   => \__( 'League', 'obitleague' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 0,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render with guarded access. Unauthenticated and non-member visitors get
	 * a join prompt; members get the published standings generation.
	 */
	protected function render(): void {
		$league_id = (int) $this->get_settings_for_display( 'league_id' );

		if ( ! is_user_logged_in() ) {
			printf(
				'<p class="obitleague-standings__signin">%s</p>',
				\esc_html__( 'Sign in and join this league to see the standings.', 'obitleague' )
			);
			return;
		}

		if ( ! \apply_filters( 'obitleague_user_is_league_member', false, $league_id, \get_current_user_id() ) ) {
			printf(
				'<p class="obitleague-standings__join">%s</p>',
				\esc_html__( 'You are not a member of this league yet.', 'obitleague' )
			);
			return;
		}

		/**
		 * Standings rows for a league: array of {rank, player, points,
		 * scoring_picks, updated_at}. Empty until the leagues module lands.
		 *
		 * @var array $rows
		 */
		$rows = \apply_filters( 'obitleague_league_standings_rows', array(), $league_id );

		if ( ! $rows ) {
			printf(
				'<p class="obitleague-standings__empty">%s</p>',
				\esc_html__( 'Standings are not available yet for this league.', 'obitleague' )
			);
			return;
		}

		echo '<table class="obitleague-standings"><thead><tr>';
		echo '<th scope="col">' . \esc_html__( 'Rank', 'obitleague' ) . '</th>';
		echo '<th scope="col">' . \esc_html__( 'Player', 'obitleague' ) . '</th>';
		echo '<th scope="col">' . \esc_html__( 'Points', 'obitleague' ) . '</th>';
		echo '<th scope="col">' . \esc_html__( 'Scoring picks', 'obitleague' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . \esc_html( (string) ( $row['rank'] ?? '' ) ) . '</td>';
			echo '<td>' . \esc_html( (string) ( $row['player'] ?? '' ) ) . '</td>';
			echo '<td>' . \esc_html( (string) ( $row['points'] ?? '' ) ) . '</td>';
			echo '<td>' . \esc_html( (string) ( $row['scoring_picks'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
