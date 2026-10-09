<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Type of entity.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * A kind of thing that can be the subject or the object of a statement.
 */
final class EntityType {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Id validator.
	 *
	 * @var callable
	 */
	private $id_validator;

	/**
	 * IRI resolver, or null.
	 *
	 * @var callable|null
	 */
	private $iri_resolver;

	/**
	 * Builds an entity type.
	 *
	 * @param string        $slug         Slug, lower case.
	 * @param string        $label        Label.
	 * @param callable      $id_validator Receives an id (string) and returns whether it is well formed.
	 * @param callable|null $iri_resolver Receives an id and returns an IRI or null.
	 * @throws \InvalidArgumentException When the slug is malformed or the label empty.
	 */
	public function __construct( $slug, $label, $id_validator, $iri_resolver = null ) {
		if ( 1 !== preg_match( EntityRef::TYPE_PATTERN, (string) $slug ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid entity type slug "%s".', esc_html( (string) $slug ) ) );
		}

		if ( '' === trim( (string) $label ) ) {
			throw new \InvalidArgumentException( 'An entity type needs a label.' );
		}

		$this->slug         = (string) $slug;
		$this->label        = (string) $label;
		$this->id_validator = $id_validator;
		$this->iri_resolver = $iri_resolver;
	}

	/**
	 * Builds a type whose ids are positive integers without leading zeros, like WordPress object ids.
	 *
	 * @param string        $slug         Slug.
	 * @param string        $label        Label.
	 * @param callable|null $iri_resolver IRI resolver.
	 * @return self
	 */
	public static function positive_integer( $slug, $label, $iri_resolver = null ) {
		return new self(
			$slug,
			$label,
			static function ( $id ) {
				return 1 === preg_match( '/^[1-9][0-9]{0,17}\z/', (string) $id );
			},
			$iri_resolver
		);
	}

	/**
	 * Returns the slug.
	 *
	 * @return string
	 */
	public function slug() {
		return $this->slug;
	}

	/**
	 * Returns the label.
	 *
	 * @return string
	 */
	public function label() {
		return $this->label;
	}

	/**
	 * Tells whether an id is well formed for this type. Existence is not checked.
	 *
	 * @param string $id Entity id.
	 * @return bool
	 */
	public function is_valid_id( $id ) {
		return (bool) ( $this->id_validator )( (string) $id );
	}

	/**
	 * Returns the IRI of an entity, or null when the type has no resolver.
	 *
	 * @param string $id Entity id.
	 * @return string|null
	 */
	public function iri( $id ) {
		if ( null === $this->iri_resolver ) {
			return null;
		}

		$iri = ( $this->iri_resolver )( (string) $id );

		return is_string( $iri ) && '' !== $iri ? $iri : null;
	}
}
