<?php
/**
 * Humans vs AI statistics dynamic tag.
 *
 * Typed tag exposing the comparison snapshot to Elementor templates: team
 * counts, points, averages, highest team and the current leaders for each
 * side. Values come from the shared standings via Vs_Stats — the tag never
 * recomputes scores and never adjusts them.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Elementor;

use Obitleague\Modules\Pick_Stats;
use Obitleague\Modules\Vs_Stats;

if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
	return;
}

final class Tag_Vs_Stat extends \Elementor\Core\DynamicTags\Tag {

	public const FIELD_HUMAN_TEAMS       = 'human_teams';
	public const FIELD_AI_TEAMS          = 'ai_teams';
	public const FIELD_HUMAN_POINTS      = 'human_points';
	public const FIELD_AI_POINTS         = 'ai_points';
	public const FIELD_HUMAN_AVERAGE     = 'human_average';
	public const FIELD_AI_AVERAGE        = 'ai_average';
	public const FIELD_HIGHEST_HUMAN     = 'highest_human';
	public const FIELD_HIGHEST_AI        = 'highest_ai';
	public const FIELD_LEADING_HUMAN     = 'leading_human';
	public const FIELD_LEADING_AI        = 'leading_ai';

	public function get_name(): string {
		return 'obitleague-vs-stat';
	}

	public function get_title(): string {
		return \__( 'Humans vs AI Stat', 'obitleague' );
	}

	public function get_group(): string {
		return 'obitleague';
	}

	public function get_categories(): array {
		return array( \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY );
	}

	protected function register_controls(): void {
		$this->add_control(
			'field',
			array(
				'label'   => \__( 'Statistic', 'obitleague' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => self::FIELD_HUMAN_TEAMS,
				'options' => array(
					self::FIELD_HUMAN_TEAMS   => \__( 'Active human teams', 'obitleague' ),
					self::FIELD_AI_TEAMS      => \__( 'Active AI teams', 'obitleague' ),
					self::FIELD_HUMAN_POINTS  => \__( 'Human points total', 'obitleague' ),
					self::FIELD_AI_POINTS     => \__( 'AI points total', 'obitleague' ),
					self::FIELD_HUMAN_AVERAGE => \__( 'Average human team points', 'obitleague' ),
					self::FIELD_AI_AVERAGE    => \__( 'Average AI team points', 'obitleague' ),
					self::FIELD_HIGHEST_HUMAN => \__( 'Highest human team score', 'obitleague' ),
					self::FIELD_HIGHEST_AI    => \__( 'Highest AI team score', 'obitleague' ),
					self::FIELD_LEADING_HUMAN => \__( 'Leading human (name)', 'obitleague' ),
					self::FIELD_LEADING_AI    => \__( 'Leading AI (name)', 'obitleague' ),
				),
			)
		);
	}

	/** Render the requested statistic for the in-play season. */
	public function render(): void {
		$field   = (string) $this->get_settings_for_display( 'field' );
		$season  = Pick_Stats::season_in_play();
		$stats   = Vs_Stats::snapshot( $season );

		$group = 'human_' === substr( $field, 0, 6 ) ? ( $stats['humans'] ?? array() ) : ( $stats['ai'] ?? array() );
		if ( ! is_array( $group ) ) {
			return;
		}

		switch ( $field ) {
			case self::FIELD_HUMAN_TEAMS:
			case self::FIELD_AI_TEAMS:
				echo \esc_html( number_format_i18n( (int) ( $group['teams'] ?? 0 ) ) );
				return;
			case self::FIELD_HUMAN_POINTS:
			case self::FIELD_AI_POINTS:
				echo \esc_html( number_format_i18n( (int) ( $group['points'] ?? 0 ) ) );
				return;
			case self::FIELD_HUMAN_AVERAGE:
			case self::FIELD_AI_AVERAGE:
				echo \esc_html( number_format_i18n( (float) ( $group['average_points'] ?? 0 ), 1 ) );
				return;
			case self::FIELD_HIGHEST_HUMAN:
			case self::FIELD_HIGHEST_AI:
				echo \esc_html( number_format_i18n( (int) ( $group['highest_team'] ?? 0 ) ) );
				return;
			case self::FIELD_LEADING_HUMAN:
			case self::FIELD_LEADING_AI:
				$leader = $group['leader'] ?? null;
				echo \esc_html( is_array( $leader ) ? (string) ( $leader['player'] ?? '' ) : '' );
				return;
		}
	}
}
