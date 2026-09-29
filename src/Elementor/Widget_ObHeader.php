<?php
declare( strict_types = 1 );

namespace Obitleague\Elementor;

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

final class Widget_ObHeader extends \Elementor\Widget_Base {

	public function get_name(): string {
		return 'obitleague-ob-header';
	}

	public function get_title(): string {
		return __( 'Obitleague Header', 'obitleague' );
	}

	public function get_icon(): string {
		return 'eicon-menu-bar';
	}

	public function get_categories(): array {
		return array( 'general' );
	}

	public function get_keywords(): array {
		return array( 'obitleague', 'header', 'mega menu' );
	}

	protected function register_controls(): void {
		$this->register_section_controls();
		$this->register_mega_controls();
		$this->register_picks_controls();

		if ( function_exists( '\Elementor\Controls_Manager' ) ) {
			$this->add_control(
				'hero_cta_label',
				array(
					'label'   => __( 'Hero CTA label', 'obitleague' ),
					'type'    => \Elementor\Controls_Manager::TEXT,
					'default' => '',
					'dynamic' => true,
				)
			);
		}
	}

	private function register_section_controls(): void {
		$this->start_controls_section(
			'section_main',
			array( 'label' => __( 'Main links', 'obitleague' ) )
		);

		$this->add_control(
			'primary',
			array(
				'label'       => __( 'Primary links', 'obitleague' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => array(
					array(
						'name'     => 'label',
						'label'    => __( 'Label', 'obitleague' ),
						'type'     => \Elementor\Controls_Manager::TEXT,
						'default'  => '',
						'required' => true,
					),
					array(
						'name'        => 'url',
						'label'       => __( 'URL', 'obitleague' ),
						'type'        => \Elementor\Controls_Manager::URL,
						'default'     => '',
						'placeholder' => 'https://',
					),
					array(
						'name'       => 'mega',
						'label'      => __( 'Open mega menu?', 'obitleague' ),
						'type'       => \Elementor\Controls_Manager::SWITCHER,
						'default'    => 'no',
						'options'    => array(
							'yes' => __( 'Yes', 'obitleague' ),
							'no'  => __( 'No', 'obitleague' ),
						),
					),
				),
				'default'     => array(
					array( 'label' => 'People', 'url' => '', 'mega' => 'yes' ),
					array( 'label' => 'Picks', 'url' => '', 'mega' => 'yes' ),
					array( 'label' => 'Standings', 'url' => '', 'mega' => 'no' ),
					array( 'label' => 'Stats', 'url' => '', 'mega' => 'no' ),
					array( 'label' => 'Rules', 'url' => '', 'mega' => 'no' ),
					array( 'label' => 'Forum', 'url' => '', 'mega' => 'no' ),
				),
				'separator'   => 'before',
			)
		);

		$this->end_controls_section();
	}

	private function register_mega_controls(): void {
		$this->start_controls_section(
			'section_mega',
			array( 'label' => __( 'Mega menu columns', 'obitleague' ) )
		);

		$this->add_control(
			'mega_columns',
			array(
				'label'   => __( 'Mega menu contents', 'obitleague' ),
				'type'    => \Elementor\Controls_Manager::GROUPED_Controls,
				'fields'  => array(
					array(
						'name'    => 'heading',
						'label'   => __( 'Heading', 'obitleague' ),
						'type'    => \Elementor\Controls_Manager::TEXT,
						'default' => '',
					),
					array(
						'name'       => 'links',
						'label'      => __( 'Links', 'obitleague' ),
						'type'       => \Elementor\Controls_Manager::REPEATER,
						'fields'     => array(
							array(
								'name'     => 'label',
								'label'    => __( 'Label', 'obitleague' ),
								'type'     => \Elementor\Controls_Manager::TEXT,
								'default'  => '',
								'required' => true,
							),
							array(
								'name'        => 'url',
								'label'       => __( 'URL', 'obitleague' ),
								'type'        => \Elementor\Controls_Manager::URL,
								'default'     => '',
								'placeholder' => 'https://',
							),
						),
						'default'    => array(),
					),
				),
				'default' => array(
					array(
						'heading' => 'Most picked',
						'links'   => array(
							array( 'label' => 'Most picked', 'url' => '' ),
							array( 'label' => 'Flying under the radar', 'url' => '' ),
						),
					),
					array(
						'heading' => 'Browse',
						'links'   => array(
							array( 'label' => 'By occupation', 'url' => '' ),
							array( 'label' => 'By birth year', 'url' => '' ),
							array( 'label' => 'By age band', 'url' => '' ),
						),
					),
				),
			)
		);

		$this->end_controls_section();
	}

	private function register_picks_controls(): void {
		$this->start_controls_section(
			'section_picks',
			array( 'label' => __( 'Picks quick stats', 'obitleague' ) )
		);

		$this->add_control(
			'picks_stats',
			array(
				'label'   => __( 'Picks stats block', 'obitleague' ),
				'type'    => \Elementor\Controls_Manager::GROUPED_Controls,
				'fields'  => array(
					array(
						'name'     => 'team_label',
						'label'    => __( 'Team label', 'obitleague' ),
						'type'     => \Elementor\Controls_Manager::TEXT,
						'default'  => '',
					),
					array(
						'name'       => 'count',
						'label'      => __( 'Picks count', 'obitleague' ),
						'type'       => \Elementor\Controls_Manager::NUMBER,
						'default'    => 0,
						'min'        => 0,
						'max'        => 99999,
						'step'       => 1,
					),
					array(
						'name'       => 'percent',
						'label'      => __( 'Share of field', 'obitleague' ),
						'type'       => \Elementor\Controls_Manager::NUMBER,
						'default'    => 0,
						'min'        => 0,
						'max'        => 100,
						'step'       => 0.01,
					),
				),
				'default' => array(
					array( 'team_label' => 'Picks', 'count' => 0, 'percent' => 0 ),
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display( 'primary' );
		$columns  = $this->get_settings_for_display( 'mega_columns' );
		$picks    = $this->get_settings_for_display( 'picks_stats' );

		$logged_in    = is_user_logged_in();
		$current_user = $logged_in ? get_current_user_id() : 0;

		$entry_season = \Obitleague\Modules\League_Service::current_season();

		$search_url = \Obitleague\Modules\Shortcodes::people_search_url();
		$search_url = $search_url ? $search_url : home_url( '/people/' );

		$cta_label = $this->get_settings_for_display( 'hero_cta_label' );
		$cta_label = $cta_label ?: ( $logged_in ? 'My game' : 'Choose your ' . $entry_season . ' team' );
		$cta_url   = $logged_in
			? home_url( '/my-leagues/#build-team' )
			: home_url( '/register/' );

		$signin_url = home_url( '/login/?redirect_to=' . rawurlencode( home_url( '/my-leagues/' ) ) );

		?>
<header class="ob-header ob-js" data-ob-header>
	<div class="ob-header__inner">
		<a class="ob-header__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Obitleague home">
			<span class="ob-mark" aria-hidden="true">O</span>
			<span class="ob-header__name">Obitleague</span>
			<span class="ob-header__season"><?php echo esc_html( (string) $entry_season ); ?></span>
		</a>

		<div class="ob-header__search">
			<?php if ( $search_url ) : ?>
			<form class="ob-header__search-form" method="get" action="<?php echo esc_url( $search_url ); ?>" role="search" aria-label="Search people">
				<input
					type="search"
					name="s"
					placeholder="Search people"
					autocomplete="off"
					aria-label="Search people"
					required
				/>
				<button type="submit" class="ob-btn ob-btn--secondary">Search</button>
			</form>
			<?php endif; ?>
		</div>

		<div class="ob-header__actions">
			<?php foreach ( $settings as $item ) : ?>
				<?php
				$label = isset( $item['label'] ) ? (string) $item['label'] : '';
				$url   = isset( $item['url'] ) && is_array( $item['url'] ) ? \Elementor\Controls_Manager::get_url_from_array( $item['url'] ) : '';
				$mega  = ( isset( $item['mega'] ) && 'yes' === $item['mega'] );
				?>
				<?php if ( '' === $label ) : ?>
					<?php continue; ?>
				<?php endif; ?>

				<?php if ( $mega && $columns ) : ?>
					<div class="ob-header__item">
						<a class="ob-header__link" href="<?php echo esc_url( $url ?: '#' ); ?>"><?php echo esc_html( $label ); ?></a>
						<span class="ob-header__chev" aria-hidden="true">⌵</span>
						<div class="ob-header__sub" data-ob-mega>
							<div class="ob-header__sub-grid">
								<?php foreach ( $columns as $col ) : ?>
									<?php
									$heading = isset( $col['heading'] ) ? (string) $col['heading'] : '';
									$links   = isset( $col['links'] ) ? (array) $col['links'] : array();
									?>
									<?php if ( '' !== $heading ) : ?>
										<div class="ob-header__sub-col">
											<h4><?php echo esc_html( $heading ); ?></h4>
											<ul>
												<?php foreach ( $links as $link ) : ?>
													<?php
													$link_label = isset( $link['label'] ) ? (string) $link['label'] : '';
													$link_url   = isset( $link['url'] ) && is_array( $link['url'] ) ? \Elementor\Controls_Manager::get_url_from_array( $link['url'] ) : '';
													?>
													<?php if ( '' === $link_label ) : ?>
														<?php continue; ?>
													<?php endif; ?>
													<li>
														<a href="<?php echo esc_url( $link_url ?: '#' ); ?>"><?php echo esc_html( $link_label ); ?></a>
													</li>
												<?php endforeach; ?>
											</ul>
										</div>
									<?php endif; ?>
								<?php endforeach; ?>

								<?php if ( ! empty( $picks ) ) : ?>
									<div class="ob-header__picks">
										<h4><?php esc_html_e( 'Picks', 'obitleague' ); ?></h4>
										<div class="ob-header__picks-row">
											<?php foreach ( $picks as $pick ) : ?>
												<?php
												$team_label = isset( $pick['team_label'] ) ? (string) $pick['team_label'] : '';
												$count      = isset( $pick['count'] ) ? (int) $pick['count'] : 0;
												$percent    = isset( $pick['percent'] ) ? (float) $pick['percent'] : 0.0;
												?>
												<div class="ob-header__picks-item">
													<strong><?php echo esc_html( $team_label ); ?></strong>
													<span class="ob-header__meta"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
													<span class="ob-header__meta"><?php echo esc_html( number_format_i18n( round( $percent, 1 ), 1 ) ); ?>%</span>
												</div>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php else : ?>
					<a class="ob-header__link" href="<?php echo esc_url( $url ?: '#' ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>

			<?php if ( $logged_in ) : ?>
				<a class="ob-header__link" href="<?php echo esc_url( home_url( '/my-leagues/' ) ); ?>">My leagues</a>
				<a class="ob-header__link" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Log out</a>
			<?php else : ?>
				<a class="ob-header__link" href="<?php echo esc_url( $signin_url ); ?>">Sign in</a>
				<a class="ob-header__cta" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
			<?php endif; ?>
		</div>

		<button class="ob-header__toggle" type="button" aria-expanded="false" aria-controls="ob-header-menu" aria-label="Menu">
			<span></span><span></span><span></span>
		</button>
	</div>

	<?php if ( ! $logged_in ) : ?>
	<div class="ob-header__mobile-join">
		<a class="ob-header__cta" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
	</div>
	<?php endif; ?>
</header>
<?php
	}

	protected function get_script_depends(): array {
		return array( 'obitleague-header' );
	}
}
