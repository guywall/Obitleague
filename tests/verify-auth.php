<?php
declare( strict_types = 1 );

use Obitleague\Modules\Auth;
use Obitleague\Modules\Game_Pages;

if ( ! defined( 'ABSPATH' ) || ! WP_CLI ) {
	return;
}

$failures = array();
$login = Auth::frontend_login_url( site_url( '/wp-login.php' ), home_url( '/my-leagues/' ), false );
if ( ! str_starts_with( $login, home_url( '/login/' ) ) || str_contains( $login, 'wp-login.php' ) ) {
	$failures[] = 'Generated player login links should use the branded /login/ route';
}
$logout = Auth::frontend_logout_url( site_url( '/wp-login.php?action=logout' ), home_url( '/' ) );
if ( ! str_starts_with( $logout, home_url( '/login/' ) ) || ! str_contains( $logout, 'ob_logout=1' ) ) {
	$failures[] = 'Logout links should use the branded front-end sign-out flow';
}
if ( home_url( '/login/' ) !== Game_Pages::lostpassword_url( site_url( '/wp-login.php?action=lostpassword' ), '' ) ) {
	$failures[] = 'Password recovery should stay on the branded /login/ route';
}
if ( home_url( '/register/' ) !== Game_Pages::register_url( site_url( '/wp-login.php?action=register' ) ) ) {
	$failures[] = 'Registration links should use the branded /register/ route';
}
if ( ! str_contains( Auth::admin_test_url(), 'ob_admin_token=' ) ) {
	$failures[] = 'Administrator testing login should require a signed temporary token';
}
$anonymous_logout = Auth::frontend_logout_url( site_url( '/wp-login.php?action=logout' ), home_url( '/' ) );
if ( ! str_starts_with( $anonymous_logout, home_url( '/login/' ) ) ) {
	$failures[] = 'Player sign-out links should not route to WordPress login';
}
$generated_reset = Game_Pages::password_reset_message( '', 'probe-key', 'probe-user', (object) array( 'user_email' => 'probe@example.org' ) );
if ( ! str_contains( $generated_reset, home_url( '/login/?action=reset' ) ) || str_contains( $generated_reset, 'wp-login.php' ) ) {
	$failures[] = 'Generated reset email should link to the branded reset form';
}
$generated_reset = Game_Pages::password_reset_message( '', 'probe-key', 'probe-user', (object) array( 'user_email' => 'probe@example.org' ) );
if ( ! str_contains( $generated_reset, home_url( '/login/?action=reset' ) ) || str_contains( $generated_reset, 'wp-login.php' ) ) {
	$failures[] = 'Generated password reset emails should direct to the branded reset form';
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		\WP_CLI::warning( $failure );
	}
	\WP_CLI::error( implode( '; ', $failures ) );
}

\WP_CLI::success( 'Branded login, recovery, registration and admin-test routing verified' );
