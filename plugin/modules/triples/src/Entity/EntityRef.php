<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Reference to an entity.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable pair (type, id) written "type:id", for example "post:123" or "ext:youtube:abc".
 *
 * The id is opaque here: its format is checked by its entity type.
 */
final class EntityRef implements NodeInterface {
	/**
	 * Pattern of an entity type slug, or of a datatype name: lower case, at most 20 characters.
	 */
	public const TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,19}\z/';

	/**
	 * Maximum length of an id.
	 */
	public const MAX_ID_LENGTH = 191;

	/**
	 * Entity type slug.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Entity id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Builds a reference.
	 *
	 * @param string $type Entity type slug.
	 * @param string $id   Entity id.
	 * @throws \InvalidArgumentException When the type or the id is malformed.
	 */
	public function __construct( $type, $id ) {
		if ( 1 !== preg_match( self::TYPE_PATTERN, (string) $type ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid entity type "%s".', esc_html( (string) $type ) ) );
		}

		if ( 1 !== preg_match( '/^[^\s[:cntrl:]]+\z/', (string) $id ) || strlen( (string) $id ) > self::MAX_ID_LENGTH ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid entity id "%s".', esc_html( (string) $id ) ) );
		}

		$this->type = (string) $type;
		$this->id   = (string) $id;
	}

	/**
	 * Parses "type:id". The id is everything after the first colon.
	 *
	 * @param string $value Reference.
	 * @return self
	 * @throws \InvalidArgumentException When the value is not "type:id".
	 */
	public static function parse( $value ) {
		$position = strpos( (string) $value, ':' );

		if ( false === $position ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid entity reference "%s".', esc_html( (string) $value ) ) );
		}

		return new self( substr( $value, 0, $position ), substr( $value, $position + 1 ) );
	}

	/**
	 * Returns the entity type slug.
	 *
	 * @return string
	 */
	public function type() {
		return $this->type;
	}

	/**
	 * Returns the entity id.
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Returns the id, which is the key of an entity.
	 *
	 * @return string
	 */
	public function key() {
		return $this->id;
	}

	/**
	 * Tells whether another node is the same entity.
	 *
	 * @param NodeInterface $other Other node.
	 * @return bool
	 */
	public function equals( NodeInterface $other ) {
		return $other instanceof self && $this->type === $other->type && $this->id === $other->id;
	}

	/**
	 * Returns "type:id".
	 *
	 * @return string
	 */
	public function __toString() {
		return $this->type . ':' . $this->id;
	}
}
