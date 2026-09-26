<?php
/**
 * DEMO pages: create Obitleague pages with Elementor container templates.
 *
 * Idempotent: pages are matched by slug. Each page gets _elementor_data
 * (containers + headings + Shortcode widgets bound to obitleague blocks),
 * _elementor_edit_mode = builder, and template type = page. Sets the front
 * page, creates a primary nav menu.
 *
 * Run: wp eval-file <this-file>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor JSON for one page: a stack of containers per section.
 * Sections: [ [heading, shortcode], ... ]
 */
function obit_el_section( string $heading, string $shortcode ): array {
	// Legacy section/column layout: supported across Elementor versions.
	$column_elements = array();
	if ( '' !== $heading ) {
		$column_elements[] = array(
			'id' => dechex( crc32( 'h' . $heading ) ),
			'elType' => 'widget',
			'widgetType' => 'heading',
			'settings' => array( 'title' => $heading, 'header_size' => 'h2' ),
			'elements' => array(),
		);
	}
	$column_elements[] = array(
		'id' => dechex( crc32( 's' . $shortcode ) ),
		'elType' => 'widget',
		'widgetType' => 'shortcode',
		'settings' => array( 'shortcode' => $shortcode ),
		'elements' => array(),
	);
	return array(
		'id' => dechex( crc32( $heading . $shortcode . 'sec' ) ),
		'elType' => 'section',
		'settings' => array( 'gap' => 'extended', 'structure' => '10' ),
		'elements' => array(
			array(
				'id' => dechex( crc32( $heading . $shortcode . 'col' ) ),
				'elType' => 'column',
				'settings' => array( '_column_size' => 100, '_column_size_mobile' => 100 ),
				'elements' => $column_elements,
				'isInner' => false,
			),
		),
		'isInner' => false,
	);
}

function obit_el_page( array $sections ): string {
	return wp_json_encode( array_values( $sections ) );
}

$pages = array(
	'home' => array(
		'title'    => 'Home',
		'front'    => true,
		'sections' => array(
			array( '', '[obitleague_hero]' ),
			array( 'Season at a glance', '[obitleague_stats]' ),
			array( 'League standings', '[obitleague_standings]' ),
			array( 'Recently confirmed deaths', '[obitleague_recent_deaths count=6]' ),
		),
	),
	'standings' => array(
		'title'    => 'Standings',
		'front'    => false,
		'sections' => array(
			array( 'Season standings', '[obitleague_standings]' ),
		),
	),
	'catalogue' => array(
		'title'    => 'People',
		'front'    => false,
		'sections' => array(
			array( 'The catalogue', '[obitleague_people living=1 per_page=24]' ),
			array( 'Confirmed deaths this season', '[obitleague_archive]' ),
		),
	),
	'archive' => array(
		'title'    => 'Death Archive',
		'front'    => false,
		'sections' => array(
			array( 'Death archive', '[obitleague_archive]' ),
		),
	),
	'rules' => array(
		'title'    => 'Rules',
		'front'    => false,
		'sections' => array(
			array( '', '[obitleague_hero]' ),
		),
	),
);

foreach ( $pages as $slug => $conf ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) {
		$page_id = (int) $existing->ID;
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => '',
			)
		);
	} else {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $conf['title'],
				'post_name'    => $slug,
				'comment_status' => 'closed',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			echo 'fail ' . $slug . ': ' . $page_id->get_error_message() . "\n";
			continue;
		}
	}

	$sections = array();
	foreach ( $conf['sections'] as [ $heading, $shortcode ] ) {
		$sections[] = obit_el_section( $heading, $shortcode );
	}

	update_post_meta( $page_id, '_elementor_data', obit_el_page( $sections ) );
	update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );
	update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
	update_post_meta( $page_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
	delete_post_meta( $page_id, '_elementor_css' );
	delete_post_meta( $page_id, '_elementor_element_cache' ); // Elementor caches rendered output; stale caches shadow new data.
	echo "page '{$slug}' id {$page_id} with " . count( $sections ) . " sections\n";
}

/* Front page + blog page. */
$home = get_page_by_path( 'home' );
if ( $home ) {
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $home->ID );
	update_option( 'page_for_posts', 0 );
	echo 'front page set to home (' . (int) $home->ID . ")\n";
}

/* Primary nav menu. */
$menu_name = 'Primary';
$menu      = wp_get_nav_menu_object( $menu_name );
if ( ! $menu ) {
	$menu_id = wp_create_nav_menu( $menu_name );
} else {
	$menu_id = (int) $menu->term_id;
}
foreach ( array( 'home' => 'Home', 'standings' => 'Standings', 'catalogue' => 'People', 'archive' => 'Death Archive', 'rules' => 'Rules' ) as $slug => $label ) {
	$pid = get_page_by_path( $slug );
	if ( $pid && ! wp_get_nav_menu_object( $label ) ) {
		// no-op guard; items added below.
	}
	if ( $pid ) {
		$existing_items = wp_get_nav_menu_items( $menu_id ) ?: array();
		$already = false;
		foreach ( $existing_items as $item ) {
			if ( (int) $item->object_id === (int) $pid->ID ) {
				$already = true;
				break;
			}
		}
		if ( ! $already ) {
			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'     => $label,
					'menu-item-object'    => 'page',
					'menu-item-object-id' => (int) $pid->ID,
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-url'       => get_permalink( (int) $pid->ID ),
				)
			);
		}
	}
}
$locations            = get_theme_mod( 'nav_menu_locations', array() );
$locations['primary'] = $menu_id;
set_theme_mod( 'nav_menu_locations', $locations );
echo "nav menu ready ({$menu_id})\n";

echo "pages done.\n";
