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
 * A kind of relation: what it may relate, how many, whether it is symmetric, and which predicates may qualify its statements.
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
		'symmetric',
		'on_delete',
		'iri',
		'qualified_by',
		'qualifies',
	);

	/**
	 * Entry of `qualifies` meaning "any predicate".
	 */
	public const WILDCARD = '*';

	/**
	 * Maximum length of a slug.
	 */
	public const MAX_SLUG_LENGTH = 64;

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
	 * Predicates that may qualify the statements of this predicate.
	 *
	 * @var string[]
	 */
	private $qualified_by;

	/**
	 * Predicates whose statements this predicate may qualify (`*`: any).
	 *
	 * @var string[]
	 */
	private $qualifies;

	/**
	 * Builds a definition from an array.
	 *
	 * Required keys: `slug` and `label`. Optional keys: `inverse_label`, `subject_types` (entity types), `object_types` (entity types
	 * or datatypes), `max_objects_per_subject`, `max_subjects_per_object`, `symmetric`, `on_delete`, `iri`, `qualified_by` and
	 * `qualifies` (lists of predicate slugs; `*` is accepted in `qualifies`).
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

		if ( ! is_string( $slug ) || ! self::is_valid_slug( $slug ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid predicate slug "%s": expected "owner/name" in lower case, at most 64 characters.', esc_html( is_string( $slug ) ? $slug : '' ) ) );
		}

		$this->slug = $slug;

		$reader = new DefinitionReader( $args, sprintf( 'Predicate "%s"', $slug ) );

		$this->label         = $reader->required_string( 'label' );
		$this->inverse_label = isset( $args['inverse_label'] ) ? $reader->required_string( 'inverse_label' ) : null;

		$this->subject_types = $reader->types( 'subject_types' );
		$this->object_types  = $reader->types( 'object_types' );

		$this->max_objects_per_subject = $reader->limit( 'max_objects_per_subject' );
		$this->max_subjects_per_object = $reader->limit( 'max_subjects_per_object' );

		$this->symmetric = $reader->flag( 'symmetric' );

		$this->on_delete = $args['on_delete'] ?? 'remove';

		if ( ! in_array( $this->on_delete, array( 'remove', 'keep' ), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Predicate "%s": "on_delete" must be "remove" or "keep".', esc_html( $slug ) ) );
		}

		$this->iri          = $reader->iri( 'iri' );
		$this->qualified_by = $reader->predicate_slugs( 'qualified_by', false );
		$this->qualifies    = $reader->predicate_slugs( 'qualifies', true );
	}

	/**
	 * Tells whether a string is a valid predicate slug: `owner/name`, lower case, at most 64 characters.
	 *
	 * @param mixed $slug Candidate.
	 * @return bool
	 */
	public static function is_valid_slug( $slug ) {
		return is_string( $slug )
			&& strlen( $slug ) <= self::MAX_SLUG_LENGTH
			&& 1 === preg_match( '/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*\z/', $slug );
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
	 * Returns the predicates that may qualify the statements of this predicate.
	 *
	 * @return string[]
	 */
	public function qualified_by() {
		return $this->qualified_by;
	}

	/**
	 * Returns the predicates whose statements this predicate may qualify (`*`: any).
	 *
	 * @return string[]
	 */
	public function qualifies() {
		return $this->qualifies;
	}
}
