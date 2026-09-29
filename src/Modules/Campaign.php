<?php
/** 2027 season campaign page and non-persistent sample-team demo. */
declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Campaign {

	private function __construct() {}

	public static function boot(): void {
		add_shortcode( 'obitleague_2027_campaign', array( self::class, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/** Enqueue before the theme emits wp_head; the front page renders via a plugin template. */
	public static function assets(): void {
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		if ( ! is_front_page() && 'register' !== $path ) {
			return;
		}
		wp_enqueue_style( 'obitleague-campaign', OBITLEAGUE_DIR_URL . 'assets/campaign.css', array( 'ob-chrome' ), OBITLEAGUE_VERSION );
		if ( is_front_page() ) {
			wp_enqueue_script( 'obitleague-campaign', OBITLEAGUE_DIR_URL . 'assets/campaign.js', array(), OBITLEAGUE_VERSION, true );
		}
	}

	public static function render(): string {
		// Every year reference follows the open entry season so the campaign
		// never advertises a season whose deadline has already closed.
		$season    = League_Service::current_season();
		$cta_url = is_user_logged_in() ? home_url( '/my-leagues/#build-team' ) : home_url( '/register/' );
		$cta_label = is_user_logged_in() ? 'Choose your ' . $season . ' team' : 'Create an account & choose your team';
		$cta = '<a class="ob-btn ob-campaign__button" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_label ) . '</a>';

		$out = '<div class="ob-campaign">';
		$in_play = Pick_Stats::season_in_play();
		$live_note = $in_play < $season
			? '<p class="ob-campaign__fine">The ' . (int) $in_play . ' season is being played right now — <a href="' . esc_url( home_url( '/standings/' ) ) . '">see the live leaderboard</a>.</p>'
			: '';
		$out .= '<section class="ob-campaign__hero"><div class="ob-campaign__hero-copy"><span class="ob-campaign__eyebrow">The ' . (int) $season . ' season starts with your picks</span><h1>Ten names.<br><em>One year.</em><br>A place in the story.</h1><p>Build your team of ten public figures before the season locks. Follow the year, see confirmed scores, and find out how your choices stack up.</p><div class="ob-campaign__actions">' . $cta . '<a class="ob-campaign__text-link" href="#try-the-demo">Try the interactive demo <span aria-hidden="true">↓</span></a></div>' . $live_note . '<p class="ob-campaign__fine">Free to play · Open registration · No invite needed for the main ' . (int) $season . ' game</p></div><div class="ob-campaign__hero-art" aria-hidden="true"><span class="ob-campaign__orbit ob-campaign__orbit--one"></span><span class="ob-campaign__orbit ob-campaign__orbit--two"></span><span class="ob-campaign__hero-year">' . (int) $season . '</span><span class="ob-campaign__hero-caption">A year<br>to watch</span></div></section>';

		$out .= '<section class="ob-campaign__intro"><span class="ob-campaign__eyebrow">A season-long game of judgement</span><h2>Pick your ten before the year begins.</h2><p>Choose from the approved people catalogue, save your draft, and submit before the deadline. Then follow the editor-confirmed events and the live overall leaderboard.</p><div class="ob-campaign__steps"><article><span>01</span><h3>Choose</h3><p>Build a team of ten eligible people from the catalogue.</p></article><article><span>02</span><h3>Lock it in</h3><p>Submit before 00:00 London time on 1 January ' . (int) $season . '.</p></article><article><span>03</span><h3>Follow</h3><p>Only a human editor’s confirmed event can score. Track your team through the season.</p></article></div></section>';

		$out .= '<section class="ob-campaign__demo" id="try-the-demo"><div class="ob-campaign__demo-heading"><span class="ob-campaign__eyebrow">Try a sample team</span><h2>See the scoring in action.</h2><p>Choose a sample pick and preview what a hypothetical, editor-confirmed event would score. This demo changes nothing in the game.</p></div><div class="ob-campaign__demo-grid"><div class="ob-campaign__sample"><div class="ob-campaign__sample-top"><strong>Sample team</strong><span>Illustrative only</span></div><div class="ob-campaign__sample-picks" data-demo-picks>';
		$sample = array(
			array( 'Mara Example', 42, 'Novelist' ), array( 'Rowan Sample', 57, 'Composer' ), array( 'Ellis Fiction', 68, 'Explorer' ), array( 'Ari Placeholder', 73, 'Scientist' ), array( 'Sage Imaginary', 81, 'Athlete' ),
			array( 'Robin Demo', 49, 'Actor' ), array( 'Casey Sample', 61, 'Historian' ), array( 'Jamie Fiction', 76, 'Musician' ), array( 'Taylor Example', 88, 'Artist' ), array( 'Morgan Placeholder', 94, 'Writer' ),
		);
		foreach ( $sample as $index => $person ) {
			$selected = $index < 2;
			$out .= '<button class="ob-campaign__pick' . ( $selected ? ' is-selected' : '' ) . '" type="button" data-demo-pick data-age="' . (int) $person[1] . '" data-name="' . esc_attr( $person[0] ) . '" aria-pressed="' . ( $selected ? 'true' : 'false' ) . '"><span class="ob-campaign__pick-number">' . esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ) . '</span><span><strong>' . esc_html( $person[0] ) . '</strong><small>' . esc_html( $person[2] ) . ' · sample profile</small></span><span class="ob-campaign__pick-mark" aria-hidden="true">+</span></button>';
		}
		$out .= '</div><p class="ob-campaign__demo-disclaimer">All sample names and profiles are fictional. No real person is selected or scored here.</p></div><aside class="ob-campaign__score" aria-live="polite"><span class="ob-campaign__score-label">Illustrative team total</span><strong class="ob-campaign__score-number" data-demo-score>101</strong><span class="ob-campaign__score-unit">points</span><div class="ob-campaign__score-rule"><span data-demo-result>2 fictional picks selected</span><span>Hypothetical event · max(1, 100 − age)</span></div><p>This is a fictional scoring example, not a prediction. Real points are awarded only after an editor confirms an eligible event and date.</p><button class="ob-campaign__another" type="button" data-demo-random>Add a random sample pick ↻</button></aside></div></section>';

		$out .= '<section class="ob-campaign__trust"><div><span class="ob-campaign__eyebrow">A game with a human at the centre</span><h2>Curiosity, with care.</h2></div><p>Obitleague covers real lives through public reporting and editorially confirmed records. Nothing scores automatically; the game waits for careful verification. Browse the rules and sources, then make your own choices.</p><a href="' . esc_url( home_url( '/rules/' ) ) . '">Read the rules &amp; how scoring works →</a></section>';
		$out .= '<section class="ob-campaign__final"><span class="ob-campaign__eyebrow">Your ' . (int) $season . ' team is yours to choose</span><h2>Start with ten names.</h2><p>Create your account, verify your email, and build your team.</p>' . $cta . '</section></div>';
		return Shortcodes::enqueue() . $out;
	}
}
