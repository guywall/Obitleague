<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

/** Renders responses from the template files. */
final class View {
	private string $root;

	public function __construct( string $root ) {
		$this->root = rtrim( $root, '/\\' );
	}

	public function render( int $status, string $template, array $data = array() ): array {
		ob_start();
		extract( $data, EXTR_SKIP );
		require $this->root . '/app/views/' . $template;
		return array(
			'status' => $status,
			'headers' => array( 'Content-Type' => 'text/html; charset=utf-8' ),
			'body' => (string) ob_get_clean(),
		);
	}

	/** Render a page that must never be cached. */
	public function private_render( array $data, string $template = 'team.php' ): array {
		$response = $this->render( 200, $template, $data );
		$response['headers']['Cache-Control'] = 'private, no-store';
		return $response;
	}
}
