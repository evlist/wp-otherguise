<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the modes register in the Triples module for templates.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Integration;

use Otherguise\Modes\Template\TemplateLookup;
use Otherguise\Modes\Template\TemplateRef;
use Otherguise\Triples\Entity\EntityType;
use Otherguise\Triples\Predicate\PredicateDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The entity types `template` and `template_part` (ids `stylesheet//slug`; a `WP_Block_Template` object stands for its entity) and the
 * predicates `modes/has-variant` and `modes/has-part-variant`. A statement of these predicates is qualified by `modes/mode`: it says
 * "this template has that variant in that mode".
 */
final class TemplateIntegration {
	/**
	 * Lookup of templates.
	 *
	 * @var TemplateLookup
	 */
	private $lookup;

	/**
	 * Builds the integration.
	 *
	 * @param TemplateLookup $lookup Lookup of templates.
	 */
	public function __construct( TemplateLookup $lookup ) {
		$this->lookup = $lookup;
	}

	/**
	 * Registers the entity types. Action `triples_register_entity_types`.
	 *
	 * @param \Otherguise\Triples\Entity\EntityTypeRegistry $types Entity types.
	 * @return void
	 */
	public function register_entity_types( $types ) {
		$types->register( $this->entity_type( TemplateRef::TEMPLATE, 'Template', 'wp_template' ) );
		$types->register( $this->entity_type( TemplateRef::PART, 'Template part', 'wp_template_part' ) );
	}

	/**
	 * Registers the predicates. Action `triples_register_predicates`.
	 *
	 * @param \Otherguise\Triples\Predicate\PredicateRegistry $predicates Predicates.
	 * @return void
	 */
	public function register_predicates( $predicates ) {
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => 'modes/has-variant',
					'label'         => __( 'Has variant', 'otherguise' ),
					'inverse_label' => __( 'Variant of', 'otherguise' ),
					'subject_types' => array( TemplateRef::TEMPLATE ),
					'object_types'  => array( TemplateRef::TEMPLATE ),
					'qualified_by'  => array( 'modes/mode' ),
				)
			)
		);
		$predicates->register(
			PredicateDefinition::from_array(
				array(
					'slug'          => 'modes/has-part-variant',
					'label'         => __( 'Has template part variant', 'otherguise' ),
					'inverse_label' => __( 'Template part variant of', 'otherguise' ),
					'subject_types' => array( TemplateRef::PART ),
					'object_types'  => array( TemplateRef::PART ),
					'qualified_by'  => array( 'modes/mode' ),
				)
			)
		);
	}

	/**
	 * Builds one of the two entity types.
	 *
	 * @param string $slug  Entity type.
	 * @param string $label Label.
	 * @param string $post_type `wp_template` or `wp_template_part`: the type of the objects of WordPress.
	 * @return EntityType
	 */
	private function entity_type( $slug, $label, $post_type ) {
		$lookup = $this->lookup;

		return new EntityType(
			$slug,
			$label,
			array( TemplateRef::class, 'is_valid_id' ),
			null,
			function ( $id ) use ( $lookup, $post_type ) {
				return null !== $lookup->block_template( $id, $post_type )
					|| ( 'wp_template' === $post_type && TemplateRef::theme( $id ) === $lookup->stylesheet() && $lookup->php_template_exists( TemplateRef::slug( $id ) ) );
			},
			static function ( $value ) use ( $post_type ) {
				return $value instanceof \WP_Block_Template && $post_type === $value->type ? $value->id : null;
			},
			static function ( $id ) use ( $lookup, $post_type ) {
				return $lookup->block_template( $id, $post_type );
			},
			function ( $id ) use ( $lookup, $post_type ) {
				$template = $lookup->block_template( $id, $post_type );

				if ( null !== $template ) {
					return array(
						'label' => '' !== (string) $template->title ? $template->title : $template->slug,
						'url'   => $lookup->edit_url( $id, $post_type ),
					);
				}

				return TemplateRef::is_valid_id( $id ) ? array(
					'label' => TemplateRef::slug( $id ),
					'url'   => null,
				) : null;
			}
		);
	}
}
