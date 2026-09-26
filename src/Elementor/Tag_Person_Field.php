<?php
/**
 * Person dynamic tag.
 *
 * Typed tag bound to approved person fields. Only published posts and
 * approved fields resolve here; editor previews use synthetic data so no
 * private claim can leak into a template preview.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Elementor;

use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Modules\Catalogue;

if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
	return;
}

final class Tag_Person_Field extends \Elementor\Core\DynamicTags\Tag {

	public const FIELD_NAME          = 'name';
	public const FIELD_ROLE          = 'role';
	public const FIELD_BIRTH_DATE    = 'birth_date';
	public const FIELD_DEATH_DATE    = 'death_date';
	public const FIELD_PORTRAIT_CREDIT = 'portrait_credit';

	public function get_name(): string {
		return 'obitleague-person-field';
	}

	public function get_title(): string {
		return \__( 'Person Field', 'obitleague' );
	}

	public function get_group(): string {
		return 'obitleague';
	}

	/** Typed value categories keep Elementor's native style controls. */
	public function get_categories(): array {
		return array( \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY );
	}

	protected function register_controls(): void {
		$this->add_control(
			'field',
			array(
				'label'   => \__( 'Field', 'obitleague' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => self::FIELD_NAME,
				'options' => array(
					self::FIELD_NAME            => \__( 'Name', 'obitleague' ),
					self::FIELD_ROLE            => \__( 'Public role', 'obitleague' ),
					self::FIELD_BIRTH_DATE      => \__( 'Birth date', 'obitleague' ),
					self::FIELD_DEATH_DATE      => \__( 'Verified death date', 'obitleague' ),
					self::FIELD_PORTRAIT_CREDIT => \__( 'Portrait credit', 'obitleague' ),
				),
			)
		);
	}

	/**
	 * Render the field for the current post in the loop. Non-person contexts
	 * and unpublished posts render nothing.
	 */
	public function render(): void {
		$post_id = (int) get_the_ID();
		if ( ! $post_id || Catalogue::POST_TYPE !== (string) get_post_type( $post_id ) ) {
			return;
		}
		if ( 'publish' !== (string) get_post_status( $post_id ) ) {
			return; // Never resolve tags against unpublished people.
		}

		$field = (string) $this->get_settings_for_display( 'field' );

		echo \esc_html( (string) self::field_value( $post_id, $field ) );
	}

	/** Field values, labelled honestly when a fact is unknown. */
	public static function field_value( int $post_id, string $field ): string {
		switch ( $field ) {
			case self::FIELD_NAME:
				return (string) get_the_title( $post_id );

			case self::FIELD_ROLE:
				return (string) get_post_meta( $post_id, 'obit_role', true );

			case self::FIELD_BIRTH_DATE:
				return self::date_label( $post_id, 'obit_birth_date' );

			case self::FIELD_DEATH_DATE:
				return self::date_label( $post_id, 'obit_death_date' );

			case self::FIELD_PORTRAIT_CREDIT:
				return (string) get_post_meta( $post_id, 'obit_portrait_credit', true );
		}
		return '';
	}

	/** Dates keep their stored precision; nothing is invented. */
	private static function date_label( int $post_id, string $meta_key ): string {
		$raw = (string) get_post_meta( $post_id, $meta_key, true );
		if ( '' === $raw ) {
			return \__( 'Unknown', 'obitleague' );
		}
		try {
			return Catalogue::partial_date_from_stored( $raw )->label();
		} catch ( \InvalidArgumentException ) {
			return \__( 'Unknown', 'obitleague' );
		}
	}
}
