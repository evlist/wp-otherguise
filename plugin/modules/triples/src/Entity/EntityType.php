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
	 * Existence check, or null.
	 *
	 * @var callable|null
	 */
	private $exists;

	/**
	 * Recognizer, or null.
	 *
	 * @var callable|null
	 */
	private $identify;

	/**
	 * Loader, or null.
	 *
	 * @var callable|null
	 */
	private $load;

	/**
	 * Describer, or null.
	 *
	 * @var callable|null
	 */
	private $describe;

	/**
	 * Builds an entity type.
	 *
	 * The last three callables are optional and let the service work with the objects the caller holds:
	 *
	 * - `$exists( $id )` returns whether the entity exists; a type without it (and without a loader) is not checked;
	 * - `$identify( $value )` returns the id when the value is an object of this type (a `WP_Term`, say), or null; the recognizers of the
	 *   registered types must be exclusive;
	 * - `$load( $id )` returns the object for an id, or null when there is none;
	 * - `$describe( $id )` returns `array( 'label' => string, 'url' => string|null )` for the administration screen, or null when the
	 *   entity cannot be described.
	 *
	 * @param string        $slug         Slug, lower case.
	 * @param string        $label        Label.
	 * @param callable      $id_validator Receives an id (string) and returns whether it is well formed.
	 * @param callable|null $iri_resolver Receives an id and returns an IRI or null.
	 * @param callable|null $exists       Existence check.
	 * @param callable|null $identify     Recognizer.
	 * @param callable|null $load         Loader.
	 * @param callable|null $describe     Describer.
	 * @throws \InvalidArgumentException When the slug is malformed or the label empty.
	 */
	public function __construct( $slug, $label, $id_validator, $iri_resolver = null, $exists = null, $identify = null, $load = null, $describe = null ) {
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
		$this->exists       = $exists;
		$this->identify     = $identify;
		$this->load         = $load;
		$this->describe     = $describe;
	}

	/**
	 * Builds a type whose ids are positive integers without leading zeros, like WordPress object ids.
	 *
	 * @param string        $slug         Slug.
	 * @param string        $label        Label.
	 * @param callable|null $iri_resolver IRI resolver.
	 * @param callable|null $exists       Existence check.
	 * @param callable|null $identify     Recognizer.
	 * @param callable|null $load         Loader.
	 * @param callable|null $describe     Describer.
	 * @return self
	 */
	public static function positive_integer( $slug, $label, $iri_resolver = null, $exists = null, $identify = null, $load = null, $describe = null ) {
		return new self(
			$slug,
			$label,
			static function ( $id ) {
				return 1 === preg_match( '/^[1-9][0-9]{0,17}\z/', (string) $id );
			},
			$iri_resolver,
			$exists,
			$identify,
			$load,
			$describe
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

	/**
	 * Tells whether an entity exists.
	 *
	 * @param string $id Entity id.
	 * @return bool|null True or false, or null when the type has no way to know (nothing is checked then).
	 */
	public function exists( $id ) {
		if ( null !== $this->exists ) {
			return (bool) ( $this->exists )( (string) $id );
		}

		if ( null !== $this->load ) {
			return null !== ( $this->load )( (string) $id );
		}

		return null;
	}

	/**
	 * Returns the id of an entity of this type when the value is an object that represents one.
	 *
	 * @param mixed $value Any value.
	 * @return string|null
	 */
	public function identify( $value ) {
		if ( null === $this->identify ) {
			return null;
		}

		$id = ( $this->identify )( $value );

		return null === $id || '' === (string) $id ? null : (string) $id;
	}

	/**
	 * Loads the object of an entity.
	 *
	 * @param string $id Entity id.
	 * @return mixed The object, or null when the type has no loader or the entity does not exist.
	 */
	public function load( $id ) {
		return null === $this->load ? null : ( $this->load )( (string) $id );
	}

	/**
	 * Describes an entity for a screen.
	 *
	 * @param string $id Entity id.
	 * @return array{label: string, url: string|null}|null Null when the type has no describer or the entity cannot be described.
	 */
	public function describe( $id ) {
		if ( null === $this->describe ) {
			return null;
		}

		$description = ( $this->describe )( (string) $id );

		if ( ! is_array( $description ) || ! isset( $description['label'] ) || '' === (string) $description['label'] ) {
			return null;
		}

		$url = $description['url'] ?? null;

		return array(
			'label' => (string) $description['label'],
			'url'   => is_string( $url ) && '' !== $url ? $url : null,
		);
	}
}
