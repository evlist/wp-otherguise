<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Variants of templates and of template parts.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Variant;

use Otherguise\Modes\Template\TemplateLookup;
use Otherguise\Modes\Template\TemplateRef;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\Statement;

defined( 'ABSPATH' ) || exit;

/**
 * "Template `bar` is the `print` version of template `foo`": a statement `(template:theme//foo, modes/has-variant, template:theme//bar)`
 * and a statement about it, `(that statement, modes/mode, mode:print)`. The same holds for template parts with `modes/has-part-variant`.
 *
 * A template can have a variant in several modes and a variant can serve several templates; one template has **one** variant per mode.
 * Both ends are of the same kind (template or template part) and of the same theme. Nothing here changes what WordPress displays:
 * `map()` is what slice 203 reads to do it.
 */
final class Variants {
	/**
	 * Predicate by entity type.
	 */
	private const PREDICATES = array(
		TemplateRef::TEMPLATE => 'modes/has-variant',
		TemplateRef::PART     => 'modes/has-part-variant',
	);

	/**
	 * Service of the statements.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Lookup of templates.
	 *
	 * @var TemplateLookup
	 */
	private $lookup;

	/**
	 * Builds the service.
	 *
	 * @param Statements     $statements Service of the statements.
	 * @param TemplateLookup $lookup     Lookup of templates.
	 */
	public function __construct( Statements $statements, TemplateLookup $lookup ) {
		$this->statements = $statements;
		$this->lookup     = $lookup;
	}

	/**
	 * Returns the reference to a template of the active theme.
	 *
	 * @param string $slug Slug (`single`).
	 * @return EntityRef
	 */
	public function template( $slug ) {
		return TemplateRef::template( $slug, $this->lookup->stylesheet() );
	}

	/**
	 * Returns the reference to a template part of the active theme.
	 *
	 * @param string $slug Slug (`header`).
	 * @return EntityRef
	 */
	public function part( $slug ) {
		return TemplateRef::part( $slug, $this->lookup->stylesheet() );
	}

	/**
	 * Declares that a template (or a template part) has a variant in a mode.
	 *
	 * Idempotent. A template that already has another variant in this mode is refused (`mode_already_served`): withdraw it first.
	 *
	 * @param mixed $source  Template or part: a `WP_Block_Template` or a reference (see `template()` and `part()`).
	 * @param mixed $mode    Mode: a `ModeDefinition` or a reference.
	 * @param mixed $variant Template or part that replaces it in the mode.
	 * @return Statement The statement that holds the relation.
	 * @throws VariantException           When a rule of the variants is not respected.
	 * @throws \Otherguise\Triples\Service\InvalidStatementException When Triples refuses an argument or the statement.
	 */
	public function declare( $source, $mode, $variant ) {
		list( $source, $variant, $predicate ) = $this->ends( $source, $variant );

		$mode = $this->mode( $mode );

		if ( $source->id() === $variant->id() ) {
			VariantException::refuse( VariantException::SAME_TEMPLATE, 'A template cannot be its own variant.' );
		}

		if ( TemplateRef::theme( $source->id() ) !== TemplateRef::theme( $variant->id() ) ) {
			VariantException::refuse( VariantException::THEME_MISMATCH, 'A variant belongs to the theme of the template it replaces.' );
		}

		$existing = $this->variant_of( $source, $mode );

		if ( null !== $existing && ! $existing->equals( $variant ) ) {
			VariantException::refuse( VariantException::MODE_ALREADY_SERVED, $source . ' already has the variant ' . $existing . ' in the mode ' . $mode . '.' );
		}

		return $this->statements->transaction(
			function () use ( $source, $predicate, $variant, $mode ) {
				$link = $this->statements->triple( $source, $predicate, $variant );

				$this->statements->triple( $link, 'modes/mode', $mode );

				return $link;
			}
		);
	}

	/**
	 * Withdraws a variant from a mode. When it serves no other mode for this template the relation is deleted.
	 *
	 * @param mixed $source  Template or part.
	 * @param mixed $mode    Mode.
	 * @param mixed $variant Variant.
	 * @return int Number of statements deleted; 0 when the template had not this variant in this mode.
	 */
	public function withdraw( $source, $mode, $variant ) {
		list( $source, $variant, $predicate ) = $this->ends( $source, $variant );

		$mode = $this->mode( $mode );
		$link = $this->statements->find_by_triple( $source, $predicate, $variant );

		if ( null === $link ) {
			return 0;
		}

		$deleted = $this->statements->remove( $link, 'modes/mode', $mode );

		if ( $deleted > 0 && array() === $this->statements->match( $link, 'modes/mode' ) ) {
			$deleted += $this->statements->delete( $link );
		}

		return $deleted;
	}

	/**
	 * Deletes the relation between a template and a variant, in every mode.
	 *
	 * @param mixed $source  Template or part.
	 * @param mixed $variant Variant.
	 * @return int Number of statements deleted.
	 */
	public function remove( $source, $variant ) {
		list( $source, $variant, $predicate ) = $this->ends( $source, $variant );

		return $this->statements->remove( $source, $predicate, $variant );
	}

	/**
	 * Returns the variant of a template in a mode.
	 *
	 * @param mixed $source Template or part.
	 * @param mixed $mode   Mode.
	 * @return EntityRef|null
	 */
	public function variant_of( $source, $mode ) {
		$source = $this->entity( $source );
		$mode   = $this->mode( $mode );
		$kind   = $this->kind( $source );
		$id     = $this->map( $mode, $kind )[ $source->id() ] ?? null;

		return null === $id ? null : new EntityRef( $kind, $id );
	}

	/**
	 * Returns all the variants of a mode, templates or template parts, with two reads (the relations, then what is said about them).
	 *
	 * @param mixed  $mode Mode.
	 * @param string $kind `template` or `template_part`.
	 * @return array<string, string> Id of the variant by id of the template. When a template somehow has several variants in the mode, the oldest relation wins.
	 */
	public function map( $mode, $kind = TemplateRef::TEMPLATE ) {
		$mode      = $this->mode( $mode );
		$predicate = self::PREDICATES[ $kind ] ?? self::PREDICATES[ TemplateRef::TEMPLATE ];
		$links     = $this->statements->match( null, $predicate );
		$map       = array();

		if ( array() === $links ) {
			return $map;
		}

		$qualifications = $this->statements->qualifications_of( $links );

		foreach ( $links as $link ) {
			foreach ( $qualifications[ $link->id() ]['modes/mode'] ?? array() as $in_mode ) {
				if ( $in_mode->object()->equals( $mode ) ) {
					$map[ $link->subject()->id() ] = $map[ $link->subject()->id() ] ?? $link->object()->id();
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * Describes an entity for a screen: a label, a link when there is one, and whether it exists (null when its type cannot tell).
	 *
	 * @param EntityRef $entity Template, template part or mode.
	 * @return array{label: string, url: string|null, exists: bool|null}
	 */
	public function describe( EntityRef $entity ) {
		return $this->statements->describe( $entity );
	}

	/**
	 * Returns every relation of a kind with the modes it serves, for the screens.
	 *
	 * @param string $kind `template` or `template_part`.
	 * @return array<int, array{source: EntityRef, variant: EntityRef, modes: string[]}> In the order of creation. A relation that serves no mode
	 *                                                                                 (it cannot be made by `declare()`) has an empty list.
	 */
	public function relations( $kind = TemplateRef::TEMPLATE ) {
		$predicate = self::PREDICATES[ $kind ] ?? self::PREDICATES[ TemplateRef::TEMPLATE ];
		$links     = $this->statements->match( null, $predicate );
		$relations = array();

		if ( array() === $links ) {
			return $relations;
		}

		$qualifications = $this->statements->qualifications_of( $links );

		foreach ( $links as $link ) {
			$modes = array();

			foreach ( $qualifications[ $link->id() ]['modes/mode'] ?? array() as $in_mode ) {
				$modes[] = $in_mode->object()->id();
			}

			$relations[] = array(
				'source'  => $link->subject(),
				'variant' => $link->object(),
				'modes'   => $modes,
			);
		}

		return $relations;
	}

	/**
	 * Reads the two ends of a relation and returns them with the predicate that fits their kind.
	 *
	 * @param mixed $source  Template or part.
	 * @param mixed $variant Variant.
	 * @return array{0: EntityRef, 1: EntityRef, 2: string}
	 * @throws VariantException When the ends are not templates, or not of the same kind.
	 */
	private function ends( $source, $variant ) {
		$source  = $this->entity( $source );
		$variant = $this->entity( $variant );
		$kind    = $this->kind( $source );

		if ( $variant->type() !== $kind ) {
			VariantException::refuse( VariantException::KIND_MISMATCH, 'A template and a template part cannot be variants of each other.' );
		}

		return array( $source, $variant, self::PREDICATES[ $kind ] );
	}

	/**
	 * Reads a template or a template part.
	 *
	 * @param mixed $value Value.
	 * @return EntityRef
	 * @throws VariantException When it is not a template or a template part.
	 */
	private function entity( $value ) {
		$entity = $this->statements->entity( $value );

		$this->kind( $entity );

		return $entity;
	}

	/**
	 * Returns the kind of an entity: its type, when it is a template or a template part.
	 *
	 * @param EntityRef $entity Entity.
	 * @return string
	 * @throws VariantException When it is neither.
	 */
	private function kind( EntityRef $entity ) {
		if ( ! isset( self::PREDICATES[ $entity->type() ] ) ) {
			VariantException::refuse( VariantException::NOT_A_TEMPLATE, 'Expected a template or a template part, not ' . $entity . '.' );
		}

		return $entity->type();
	}

	/**
	 * Reads a mode.
	 *
	 * @param mixed $value Value: a `ModeDefinition` or a reference.
	 * @return EntityRef
	 * @throws VariantException When it is not a mode.
	 */
	private function mode( $value ) {
		$mode = $this->statements->entity( $value );

		if ( 'mode' !== $mode->type() ) {
			VariantException::refuse( VariantException::NOT_A_MODE, 'Expected a mode, not ' . $mode . '.' );
		}

		return $mode;
	}
}
