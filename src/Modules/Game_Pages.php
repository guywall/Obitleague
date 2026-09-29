<?php
/** Game pages: public standings, league pages and invite flows. */
declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Game_Pages {
	private function __construct() {}

	public static function boot(): void {
		add_action( 'init', array( self::class, 'add_login_rewrite' ), 8 );
		add_action( 'init', array( self::class, 'rewrites' ) );
		add_action( 'init', array( self::class, 'ensure_campaign_pages' ), 20 );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'template_include', array( self::class, 'maybe_route' ), 30 );
		add_filter( 'template_include', array( self::class, 'campaign_template' ), 40 );
		add_filter( 'template_include', array( self::class, 'login_page_template' ), 45 );
		add_filter( 'login_redirect', array( self::class, 'login_redirect' ), 10, 3 );
		add_filter( 'lostpassword_url', array( self::class, 'lostpassword_url' ), 10, 2 );
		add_filter( 'register_url', array( self::class, 'register_url' ) );
		add_filter( 'retrieve_password_message', array( self::class, 'password_reset_message' ), 10, 4 );
		add_action( 'template_redirect', array( self::class, 'handle_logout' ), 1 );
		add_shortcode( 'obitleague_overall_standings', array( self::class, 'overall_shortcode' ) );
		add_shortcode( 'obitleague_join', array( self::class, 'join_shortcode' ) );
		Auth::boot();
		Campaign::boot();
	}

	/** Render campaign at the front page, keeping Elementor data intact. */
	public static function campaign_template( string $template ): string {
		if ( is_front_page() ) {
			return OBITLEAGUE_DIR . 'src/Templates/campaign-page.php';
		}
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		return 'register' === $path ? OBITLEAGUE_DIR . 'src/Templates/register-page.php' : $template;
	}

	/** Create dedicated authentication pages without enabling global registration. */
	public static function ensure_campaign_pages(): void {
		$pages = array(
			'login'        => array( 'title' => 'Sign in to Obitleague', 'content' => '[obitleague_login]' ),
			'register'     => array( 'title' => 'Create your Obitleague account', 'content' => '[obitleague_register]' ),
			'verify-email' => array( 'title' => 'Verify your Obitleague email', 'content' => '' ),
		);
		foreach ( $pages as $slug => $page ) {
			if ( get_page_by_path( $slug ) ) {
				continue;
			}
			wp_insert_post( array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $page['title'],
				'post_name'      => $slug,
				'post_content'   => $page['content'],
				'comment_status' => 'closed',
			) );
		}
	}

	public static function add_login_rewrite(): void {
		add_rewrite_rule( '^login/?$', 'index.php?pagename=login', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'ob_league_id';
		$vars[] = 'ob_team_id';
		return $vars;
	}

	public static function rewrites(): void {
		add_rewrite_rule( '^league/(\d+)/?$', 'index.php?ob_league_id=$matches[1]', 'top' );
		add_rewrite_rule( '^team/(\d+)/?$', 'index.php?ob_team_id=$matches[1]', 'top' );
	}

	public static function maybe_route( string $template ): string {
		$league_id = (int) get_query_var( 'ob_league_id' );
		if ( $league_id > 0 ) {
			return OBITLEAGUE_DIR . 'src/Templates/league-detail.php';
		}
		$team_id = (int) get_query_var( 'ob_team_id' );
		if ( $team_id > 0 ) {
			return OBITLEAGUE_DIR . 'src/Templates/team-detail.php';
		}
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		$routes = array(
			'my-leagues' => 'my-leagues.php',
			'join'       => 'join-league.php',
			'stats'      => 'stats.php',
		);
		return isset( $routes[ $path ] ) ? OBITLEAGUE_DIR . 'src/Templates/' . $routes[ $path ] : $template;
	}

	public static function login_page_template( string $template ): string {
		if ( 'login' !== Auth::request_path() ) {
			return $template;
		}
		status_header( 200 );
		return OBITLEAGUE_DIR . 'src/Templates/login-page.php';
	}

	/** Process front-end sign-out and administrator mode switch before output. */
	public static function handle_logout(): void {
		if ( 'login' !== Auth::request_path() ) {
			return;
		}
		if ( isset( $_GET['ob_switch'] ) && 'wordpress' === sanitize_key( (string) $_GET['ob_switch'] ) && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'obitleague_admin_switch' );
			wp_logout();
			wp_safe_redirect( Auth::admin_test_url() );
			exit;
		}
		if ( ! isset( $_GET['ob_logout'] ) ) {
			return;
		}
		$nonce = isset( $_GET['_ob_logout_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_ob_logout_nonce'] ) ) : '';
		if ( is_user_logged_in() && wp_verify_nonce( $nonce, 'obitleague_logout' ) ) {
			wp_logout();
		}
		$redirect = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( wp_unslash( (string) $_GET['redirect_to'] ), home_url( '/' ) ) : home_url( '/' );
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( $user instanceof \WP_User && user_can( $user, 'manage_options' ) ) {
			return $requested_redirect_to ?: admin_url();
		}
		return home_url( '/my-leagues/' );
	}

	public static function lostpassword_url( string $lostpassword_url, string $redirect ): string {
		return home_url( '/login/' );
	}

	public static function register_url( string $register_url ): string {
		return home_url( '/register/' );
	}

	/** Keep WordPress-generated reset emails on the branded form. */
	public static function password_reset_message( string $message, string $key, string $user_login, $user_data ): string {
		$url = add_query_arg( array( 'action' => 'reset', 'key' => $key, 'login' => $user_login ), home_url( '/login/' ) );
		return sprintf( "Someone has requested a password reset for the following Obitleague account:\n\n%s\n\nIf this was a mistake, ignore this email and nothing will happen. To reset your password, visit the following address:\n\n%s\n", $user_data->user_email, $url );
	}

	/** Main-season global standings. */
	public static function overall_shortcode( $atts = array() ): string {
		$requested = shortcode_atts( array( 'season' => Shortcodes::season(), 'top' => 0, 'page' => 1, 'per_page' => 50 ), $atts, 'obitleague_overall_standings' );
		$season = (int) $requested['season'];
		$published = (int) $GLOBALS['wpdb']->get_var(
			"SELECT season FROM {$GLOBALS['wpdb']->prefix}obitleague_standings_generations WHERE is_current = 1 AND season > 0 LIMIT 1"
		);
		if ( $published > 0 ) {
			$season = $published;
		}
		$page   = max( 1, absint( $requested['page'] ) );
		$limit  = (int) $requested['top'] > 0 ? min( 100, (int) $requested['top'] ) : min( 100, max( 1, (int) $requested['per_page'] ) );
		$offset = ( $page - 1 ) * $limit;
		$rows   = Overall_Standings::for_season( $season, $limit, $offset );
		$total  = Overall_Standings::count_for_season( $season );
		$out    = '<section class="ob-card ob-anim"><h2 class="ob-card__title">Overall rankings &middot; season ' . esc_html( (string) $season ) . '</h2>';
		if ( null === $rows ) {
			$out .= '<p><em>The main-season standings are not published yet.</em></p></section>';
			return Shortcodes::enqueue() . $out;
		}
		if ( ! $rows ) {
			$out .= '<p><em>No submitted main-season teams yet.</em></p></section>';
			return Shortcodes::enqueue() . $out;
		}
		$out .= '<div class="ob-table-scroll" role="region" tabindex="0" aria-label="Overall standings; scroll horizontally to see every column"><table class="ob-table ob-table--overall"><thead><tr><th>#</th><th>Player</th><th>Points</th><th>Scoring</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$rank = (int) $row['rank'];
			$out .= '<tr><td class="ob-rank"><span class="ob-medal ob-medal--' . $rank . '">' . esc_html( (string) $rank ) . '</span></td><td>';
			if ( ! empty( $row['entry_id'] ) ) {
				$out .= '<a class="ob-team-link" href="' . esc_url( home_url( '/team/' . (int) $row['entry_id'] . '/' ) ) . '">' . esc_html( (string) $row['player'] ) . '</a>';
			} else {
				$out .= esc_html( (string) $row['player'] );
			}
			if ( ! empty( $row['team_name'] ) ) {
				$out .= '<small class="ob-overall-owner">managed by ' . esc_html( (string) $row['owner'] ) . '</small>';
			}
			$out .= '</td><td class="ob-pts">' . esc_html( (string) $row['points'] ) . '</td><td>' . esc_html( (string) $row['scoring_picks'] ) . '</td></tr>';
		}
		$out .= '</tbody></table></div><p class="ob-overall__note">Overall ranking uses each player’s canonical main-season team. Side leagues are separate competitions.</p>';
		if ( $total > $limit ) {
			$out .= '<nav class="ob-pagination" aria-label="Overall standings pages">';
			if ( $page > 1 ) {
				$out .= '<a href="' . esc_url( add_query_arg( 'page', $page - 1 ) ) . '">&larr; Previous</a> ';
			}
			$out .= '<span>Page ' . esc_html( (string) $page ) . ' of ' . esc_html( (string) (int) ceil( $total / $limit ) ) . '</span>';
			if ( $page * $limit < $total ) {
				$out .= ' <a href="' . esc_url( add_query_arg( 'page', $page + 1 ) ) . '">Next &rarr;</a>';
			}
			$out .= '</nav>';
		}
		return Shortcodes::enqueue() . $out . '</section>';
	}

	/** Join or create optional invite-only side leagues. */
	public static function join_shortcode( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return Shortcodes::enqueue() . '<section class="ob-card ob-join-card"><h2 class="ob-card__title">Join a side league</h2><p>Sign in to join a side league with an invite code, or create your own. Your main-season team is separate.</p><p><a class="ob-btn" href="' . esc_url( home_url( '/login/?redirect_to=' . rawurlencode( home_url( '/join/' ) ) ) ) . '">Sign in to continue</a></p></section>';
		}
		$season = League_Service::current_season();
		$out = Shortcodes::enqueue() . '<div class="ob-join-grid">';
		$out .= '<section class="ob-card ob-join-card"><h2 class="ob-card__title">Join a side league</h2><p>Paste an invite code for a private, optional league. Side-league teams are separate from your main entry.</p><form class="ob-join-form" data-ob-join>';
		$out .= wp_nonce_field( 'wp_rest', '_obnonce', true, false ) . '<input type="text" name="token" required placeholder="Invite code" autocomplete="off" /><button type="submit" class="ob-btn">Join side league</button></form><p class="ob-join-msg" data-ob-join-msg aria-live="polite"></p></section>';
		$out .= '<section class="ob-card ob-join-card"><h2 class="ob-card__title">Create a side league</h2><p>Create an uncapped invite-only side league for season ' . esc_html( (string) $season ) . '.</p><form class="ob-join-form" data-ob-create>';
		$out .= wp_nonce_field( 'wp_rest', '_obnonce', true, false ) . '<input type="text" name="name" required maxlength="120" placeholder="League name (e.g. Office Pool)" /><button type="submit" class="ob-btn ob-btn--secondary">Create side league</button></form><p class="ob-join-msg" data-ob-create-msg aria-live="polite"></p></section></div>';
		$rest   = esc_url( rest_url( 'obitleague/v1' ) );
		$my_url = esc_url( home_url( '/my-leagues/' ) );
		$out   .= <<<HTML
<script>
(function(){
 var rest='{$rest}', myUrl='{$my_url}';
 function bind(sel,msgSel,path,success){var form=document.querySelector(sel);if(!form)return;form.addEventListener('submit',function(e){e.preventDefault();var msg=document.querySelector(msgSel),body={};new FormData(form).forEach(function(v,k){if(k!=='_obnonce')body[k]=v;});msg.textContent='Working…';fetch(rest+path,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':form.querySelector('input[name=_obnonce]').value},body:JSON.stringify(body)}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});}).then(function(res){if(!res.ok){msg.textContent=res.j.message||'Something went wrong.';return;}msg.textContent=success(res.j);}).catch(function(){msg.textContent='Network error — try again.';});});}
 bind('[data-ob-join]','[data-ob-join-msg]','/leagues/join',function(j){if(j.already_member)return'Already a member.';setTimeout(function(){location.href=myUrl;},900);return'Joined! Opening your leagues…';});
 bind('[data-ob-create]','[data-ob-create-msg]','/leagues',function(j){var code=j.invite&&j.invite.token?j.invite.token:'(see admin)';setTimeout(function(){location.href=myUrl;},2500);return'Created. Invite code: '+code+' — it expires in 14 days.';});
})();
</script>
HTML;
		return $out;
	}
}
