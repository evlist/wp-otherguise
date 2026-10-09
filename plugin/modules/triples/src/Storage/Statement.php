<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A statement.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Storage;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Entity\NodeInterface;
use Otherguise\Triples\Predicate\PredicateDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable triple (subject, predicate, object) with an identity once it is stored.
 *
 * A statement has no mutable part: a qualification is another statement whose subject is this one.
 */
final class Statement {
	/**
	 * Id, or null before the statement is stored.
	 *
	 * @var int|null
	 */
	private $id;

	/**
	 * Subject.
	 *
	 * @var EntityRef
	 */
	private $subject;

	/**
	 * Predicate slug.
	 *
	 * @var string
	 */
	private $predicate;

	/**
	 * Object.
	 *
	 * @var NodeInterface
	 */
	private $object;

	/**
	 * Creation time, GMT, `Y-m-d H:i:s`, or null before the statement is stored.
	 *
	 * @var string|null
	 */
	private $created_gmt;

	/**
	 * Last update time, GMT, or null before the statement is stored.
	 *
	 * @var string|null
	 */
	private $updated_gmt;

	/**
	 * Builds a statement.
	 *
	 * @param EntityRef     $subject     Subject.
	 * @param string        $predicate   Predicate slug.
	 * @param NodeInterface $target      Object.
	 * @param int|null      $id          Id, for a stored statement.
	 * @param string|null   $created_gmt Creation time.
	 * @param string|null   $updated_gmt Update time.
	 * @throws \InvalidArgumentException When the predicate slug or the id is malformed.
	 */
	public function __construct( EntityRef $subject, $predicate, NodeInterface $target, $id = null, $created_gmt = null, $updated_gmt = null ) {
		if ( ! PredicateDefinition::is_valid_slug( $predicate ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid predicate slug "%s".', esc_html( (string) $predicate ) ) );
		}

		if ( null !== $id && ( ! is_int( $id ) || $id < 1 ) ) {
			throw new \InvalidArgumentException( 'A statement id is a positive integer.' );
		}

		$this->subject     = $subject;
		$this->predicate   = $predicate;
		$this->object      = $target;
		$this->id          = $id;
		$this->created_gmt = $created_gmt;
		$this->updated_gmt = $updated_gmt;
	}

	/**
	 * Returns the id, or null when the statement is not stored.
	 *
	 * @return int|null
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Returns the subject.
	 *
	 * @return EntityRef
	 */
	public function subject() {
		return $this->subject;
	}

	/**
	 * Returns the predicate slug.
	 *
	 * @return string
	 */
	public function predicate() {
		return $this->predicate;
	}

	/**
	 * Returns the object.
	 *
	 * @return NodeInterface
	 */
	public function object() {
		return $this->object;
	}

	/**
	 * Returns the creation time, or null.
	 *
	 * @return string|null
	 */
	public function created_gmt() {
		return $this->created_gmt;
	}

	/**
	 * Returns the update time, or null.
	 *
	 * @return string|null
	 */
	public function updated_gmt() {
		return $this->updated_gmt;
	}

	/**
	 * Returns the reference `statement:ID` used as the subject of a statement about this one.
	 *
	 * @return EntityRef
	 * @throws \LogicException When the statement is not stored yet.
	 */
	public function as_entity() {
		if ( null === $this->id ) {
			throw new \LogicException( 'A statement that is not stored has no identity.' );
		}

		return new EntityRef( 'statement', (string) $this->id );
	}

	/**
	 * Tells whether another statement holds the same triple.
	 *
	 * @param self $other Other statement.
	 * @return bool
	 */
	public function same_triple( self $other ) {
		return $this->subject->equals( $other->subject ) && $this->predicate === $other->predicate && $this->object->equals( $other->object );
	}
}
