<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Definition of a predicate.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Predicate;

defined( 'ABSPATH' ) || exit;

/**
 * A kind of relation: what it may relate, how many, whether it is ordered or symmetric, and which qualifiers it carries.
 *
 * Immutable. The checks that need the entity and qualifier types are made when the definition is registered.
 */
final class PredicateDefinition {
	/**
	 * Accepted keys of the definition.
	 */
	private const KEYS = array(
		'slug',
		'label',
		'inverse_label',
		'subject_types',
		'object_types',
		'max_objects_per_subject',
		'max_subjects_per_object',
		'allow_repeats',
		'ordered',
		'symmetric',
		'on_delete',
		'iri',
		'qualifiers',
	);

	/**
	 * Slug: `owner/name`.
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
	 * Label of the inverse direction, or null.
	 *
	 * @var string|null
	 */
	private $inverse_label;

	/**
	 * Entity types allowed as subject (empty: any).
	 *
	 * @var string[]
	 */
	private $subject_types;

	/**
	 * Entity types allowed as object (empty: any).
	 *
	 * @var string[]
	 */
	private $object_types;

	/**
	 * Maximum number of objects per subject, or null.
	 *
	 * @var int|null
	 */
	private $max_objects_per_subject;

	/**
	 * Maximum number of subjects per object, or null.
	 *
	 * @var int|null
	 */
	private $max_subjects_per_object;

	/**
	 * Whether several statements may share the same subject, predicate and object.
	 *
	 * @var bool
	 */
	private $allow_repeats;

	/**
	 * Whether the statements of a subject carry a position.
	 *
	 * @var bool
	 */
	private $ordered;

	/**
	 * Whether (a, p, b) implies (b, p, a).
	 *
	 * @var bool
	 */
	private $symmetric;

	/**
	 * What happens to the statements when the subject or the object disappears: `remove` or `keep`.
	 *
	 * @var string
	 */
	private $on_delete;

	/**
	 * IRI of the predicate, or null.
	 *
	 * @var string|null
	 */
	private $iri;

	/**
	 * Qualifiers by name.
	 *
	 * @var array<string, QualifierDefinition>
	 */
	private $qualifiers = array();

	/**
	 * Builds a definition from an array.
	 *
	 * Required keys: `slug` and `label`. Optional keys: `inverse_label`, `subject_types`, `object_types`, `max_objects_per_subject`,
	 * `max_subjects_per_object`, `allow_repeats`, `ordered`, `symmetric`, `on_delete`, `iri` and `qualifiers` (a list of arrays or of
	 * QualifierDefinition).
	 *
	 * @param array<string, mixed> $args Definition.
	 * @return self
	 */
	public static function from_array( array $args ) {
		return new self( $args );
	}

	/**
	 * Validates and stores the definition.
	 *
	 * @param array<string, mixed> $args Definition.
	 * @throws \InvalidArgumentException When the definition is malformed.
	 */
	private function __construct( array $args ) {
		$unknown = array_diff( array_keys( $args ), self::KEYS );

		if ( array() !== $unknown ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown predicate key "%s".', esc_html( (string) reset( $unknown ) ) ) );
		}

		$slug = $args['slug'] ?? '';

		if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*\z/', $slug ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid predicate slug "%s": expected "owner/name" in lower case.', esc_html( is_string( $slug ) ? $slug : '' ) ) );
		}

		$this->slug = $slug;

		$reader = new DefinitionReader( $args, sprintf( 'Predicate "%s"', $slug ) );

		$this->label         = $reader->required_string( 'label' );
		$this->inverse_label = isset( $args['inverse_label'] ) ? $reader->required_string( 'inverse_label' ) : null;

		$this->subject_types = $reader->types( 'subject_types' );
		$this->object_types  = $reader->types( 'object_types' );

		$this->max_objects_per_subject = $reader->limit( 'max_objects_per_subject' );
		$this->max_subjects_per_object = $reader->limit( 'max_subjects_per_object' );

		$this->allow_repeats = $reader->flag( 'allow_repeats' );
		$this->ordered       = $reader->flag( 'ordered' );
		$this->symmetric     = $reader->flag( 'symmetric' );

		if ( $this->ordered && $this->symmetric ) {
			throw new \InvalidArgumentException( sprintf( 'Predicate "%s" cannot be both ordered and symmetric.', esc_html( $slug ) ) );
		}

		$this->on_delete = $args['on_delete'] ?? 'remove';

		if ( ! in_array( $this->on_delete, array( 'remove', 'keep' ), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Predicate "%s": "on_delete" must be "remove" or "keep".', esc_html( $slug ) ) );
		}

		$this->iri = $reader->iri( 'iri' );

		foreach ( $reader->qualifiers( 'qualifiers' ) as $qualifier ) {
			if ( isset( $this->qualifiers[ $qualifier->name() ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Predicate "%1$s" declares the qualifier "%2$s" twice.', esc_html( $slug ), esc_html( $qualifier->name() ) ) );
			}

			$this->qualifiers[ $qualifier->name() ] = $qualifier;
		}
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
	 * Returns the label of the inverse direction, or null.
	 *
	 * @return string|null
	 */
	public function inverse_label() {
		return $this->inverse_label;
	}

	/**
	 * Returns the entity types allowed as subject (empty: any).
	 *
	 * @return string[]
	 */
	public function subject_types() {
		return $this->subject_types;
	}

	/**
	 * Returns the entity types allowed as object (empty: any).
	 *
	 * @return string[]
	 */
	public function object_types() {
		return $this->object_types;
	}

	/**
	 * Returns the maximum number of objects per subject, or null for many.
	 *
	 * @return int|null
	 */
	public function max_objects_per_subject() {
		return $this->max_objects_per_subject;
	}

	/**
	 * Returns the maximum number of subjects per object, or null for many.
	 *
	 * @return int|null
	 */
	public function max_subjects_per_object() {
		return $this->max_subjects_per_object;
	}

	/**
	 * Tells whether the same subject, predicate and object may appear in several statements.
	 *
	 * @return bool
	 */
	public function allows_repeats() {
		return $this->allow_repeats;
	}

	/**
	 * Tells whether the statements of a subject carry a position.
	 *
	 * @return bool
	 */
	public function is_ordered() {
		return $this->ordered;
	}

	/**
	 * Tells whether the predicate is symmetric.
	 *
	 * @return bool
	 */
	public function is_symmetric() {
		return $this->symmetric;
	}

	/**
	 * Returns `remove` or `keep`.
	 *
	 * @return string
	 */
	public function on_delete() {
		return $this->on_delete;
	}

	/**
	 * Returns the IRI of the predicate, or null.
	 *
	 * @return string|null
	 */
	public function iri() {
		return $this->iri;
	}

	/**
	 * Returns the qualifiers by name.
	 *
	 * @return array<string, QualifierDefinition>
	 */
	public function qualifiers() {
		return $this->qualifiers;
	}
}
