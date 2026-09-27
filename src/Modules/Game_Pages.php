<?php
/**
 * Game pages: league detail, my leagues and join/create league.
 *
 * Routes are plain rewrites intercepted at template_include, mirroring the
 * catalogue's public/private split: league and overall standings are public
 * (the plan's launch keeps standings visible per league and overall), while
 * pre-lock picks stay hidden and join/create forms enforce authentication.
 *
 * Shortcodes here complement the statistics blocks; Elementor remains the
 * presentation layer (plan §14) and these blocks reuse the same design
 * system classes as the shortcode module.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Game_Pages {

	private function __construct() {}

	public static function boot(): void {
		add_action( 'init', array( self::class, 'rewrites' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'template_include', array( self::class, 'maybe_route' ), 30 );
		add_shortcode( 'obitleague_overall_standings', array( self::class, 'overall_shortcode' ) );
		add_shortcode( 'obitleague_join', array( self::class, 'join_shortcode' ) );
	}

	/** Rewrite /league/<id>/ and register query vars. */
	public static function rewrites(): void {
		add_rewrite_rule( '^league/(\d+)/?$', 'index.php?ob_league_id=$matches[1]', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'ob_league_id';
		return $vars;
	}

	/** Intercept league pages; everything else falls through. */
	public static function maybe_route( string $template ): string {
		$league_id = (int) get_query_var( 'ob_league_id' );
		if ( $league_id > 0 ) {
			return OBITLEAGUE_DIR . 'src/Templates/league-detail.php';
		}

		// Static game pages by slug (created by the demo page builder).
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$path = trim( $path, '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		if ( 'my-leagues' === $path ) {
			return OBITLEAGUE_DIR . 'src/Templates/my-leagues.php';
		}
		if ( 'join' === $path ) {
			return OBITLEAGUE_DIR . 'src/Templates/join-league.php';
		}
		return $template;
	}

	/* ---------- overall standings shortcode ---------- */

	public static function overall_shortcode( $atts = array() ): string {
		$a      = shortcode_atts( array( 'season' => Shortcodes::season(), 'top' => 0 ), $atts, 'obitleague_overall_standings' );
		$season = (int) $a['season'];
		$rows   = Overall_Standings::for_season( $season, (int) $a['top'] > 0 ? (int) $a['top'] : 0 );

		$out = '<section class="ob-card ob-anim"><h2 class="ob-card__title">Overall rankings &middot; season ' . esc_html( (string) $season ) . '</h2>';
		if ( ! $rows ) {
			$out .= '<p><em>No published standings yet for this season.</em></p></section>';
			return Shortcodes::enqueue() . $out;
		}
		$lead = (int) ( $rows[0]['points'] ?? 0 );
		$out .= '<table class="ob-table ob-table--overall"><thead><tr><th>#</th><th>Player</th><th>Best score</th><th>Scoring</th><th>Leagues</th><th>Leading</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$rank  = (int) $row['rank'];
			$medal = '<span class="ob-medal ob-medal--' . $rank . '">' . $rank . '</span>';
			$lead_class = ( (int) $row['points'] === $lead && $lead > 0 ) ? ' ob-lead' : '';
			$leading = $row['leading'] ? ' <span class="ob-crown" title="Leads ' . esc_attr( implode( ', ', $row['leading'] ) ) . '">✓</span>' : '';
			$out .= '<tr>';
			$out .= '<td class="ob-rank">' . $medal . '</td>';
			$out .= '<td><span class="ob-player">' . esc_html( (string) $row['player'] ) . '</span>' . $leading . '<span class="ob-player__leagues">' . esc_html( implode( ' · ', (array) $row['league_names'] ) ) . '</span></td>';
			$out .= '<td class="ob-pts' . $lead_class . '">' . esc_html( (string) $row['points'] ) . '</td>';
			$out .= '<td>' . esc_html( (string) $row['scoring_picks'] ) . '</td>';
			$out .= '<td>' . esc_html( (string) $row['leagues'] ) . '</td>';
			$out .= '<td class="ob-overall-lead">' . esc_html( (string) ( $row['leading'] ? implode( ', ', $row['leading'] ) : '—' ) ) . '</td>';
			$out .= '</tr>';
		}
		$out .= '</tbody></table>';
		$out .= '<p class="ob-overall__note">Overall rank uses each player\'s best league score; ties share positions (1, 2, 2, 4).</p>';
		$out .= '</section>';
		return Shortcodes::enqueue() . $out;
	}

	/* ---------- join / create league shortcode ---------- */

	public static function join_shortcode( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return Shortcodes::enqueue() . '<section class="ob-card ob-join-card"><h2 class="ob-card__title">Join a league</h2><p>Sign in to join a league with an invite code, or to create your own.</p><p><a class="ob-btn" href="' . esc_url( wp_login_url( home_url( '/join/' ) ) ) . '">Sign in to continue</a></p></section>';
		}

		$nonce = wp_create_nonce( 'wp_rest' );
		$season = League_Service::current_season();
		$out   = Shortcodes::enqueue();
		$out  .= '<div class="ob-join-grid">';

		// Join by token.
		$out .= '<section class="ob-card ob-join-card"><h2 class="ob-card__title">Join with an invite</h2>';
		$out .= '<p>Paste the invite code a league owner shared with you. Codes expire after ' . (int) \Obitleague\Domain\League_Rules::INVITE_TTL_DAYS . ' days.</p>';
		$out .= '<form class="ob-join-form" data-ob-join>';
		$out .= wp_nonce_field( 'wp_rest', '_obnonce', true, false );
		$out .= '<input type="text" name="token" required placeholder="Invite code" autocomplete="off" />';
		$out .= '<button type="submit" class="ob-btn">Join league</button>';
		$out .= '</form><p class="ob-join-msg" data-ob-join-msg aria-live="polite"></p></section>';

		// Create league.
		$out .= '<section class="ob-card ob-join-card"><h2 class="ob-card__title">Create a league</h2>';
		$out .= '<p>Start an invite-only league for season ' . esc_html( (string) $season ) . '. You become the owner and get an invite code to share.</p>';
		$out .= '<form class="ob-join-form" data-ob-create>';
		$out .= wp_nonce_field( 'wp_rest', '_obnonce', true, false );
		$out .= '<input type="text" name="name" required maxlength="120" placeholder="League name (e.g. Office Pool 2027)" />';
		$out .= '<button type="submit" class="ob-btn ob-btn--secondary">Create league</button>';
		$out .= '</form><p class="ob-join-msg" data-ob-create-msg aria-live="polite"></p></section>';

		$out .= '</div>';

		$rest = esc_url( rest_url( 'obitleague/v1' ) );
		$my_url = esc_url( home_url( '/my-leagues/' ) );
		$out   .= <<<HTML
<script>
(function(){
	var rest = '{$rest}';
	var MY_URL = '{$my_url}';
	function bind(formSel, msgSel, path, okMsg){
		var form = document.querySelector(formSel);
		if(!form) return;
		form.addEventListener('submit', function(e){
			e.preventDefault();
			var msg = document.querySelector(msgSel);
			var fd = new FormData(form);
			var body = {};
			fd.forEach(function(v,k){ if(k!=='_obnonce') body[k]=v; });
			msg.textContent = 'Working…';
			fetch(rest + path, {
				method:'POST',
				credentials:'same-origin',
				headers:{'Content-Type':'application/json','X-WP-Nonce':form.querySelector('input[name=_obnonce]').value},
				body: JSON.stringify(body)
			}).then(function(r){ return r.json().then(function(j){ return {ok:r.ok, j:j}; }); })
			.then(function(res){
				if(!res.ok){
					msg.textContent = (res.j && res.j.message) ? res.j.message : 'Something went wrong.';
					return;
				}
				msg.textContent = okMsg(res.j);
			})
			.catch(function(){ msg.textContent = 'Network error — try again.'; });
		});
	}
	bind('[data-ob-join]', '[data-ob-join-msg]', '/leagues/join', function(j){
		if (j.already_member) { return 'You are already a member of that league.'; }
		setTimeout(function(){ window.location.href = MY_URL; }, 900);
		return 'Joined! Opening your leagues…';
	});
	bind('[data-ob-create]', '[data-ob-create-msg]', '/leagues', function(j){
		var code = (j.invite && j.invite.token) ? j.invite.token : '(see admin)';
		setTimeout(function(){ window.location.href = MY_URL; }, 2500);
		return 'League created. Invite code: ' + code + ' — it expires in 14 days. Copy it now; taking you to your leagues…';
	});
})();
</script>
HTML;

		return $out;
	}
}
