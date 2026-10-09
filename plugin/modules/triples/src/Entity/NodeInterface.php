<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Contract of the nodes of a statement.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * Something that can be the object of a statement: an entity or a literal.
 *
 * Both are stored as a pair (type, key): the entity type and the id of an entity, the datatype and the normalized value of a literal.
 */
interface NodeInterface {
	/**
	 * Returns the entity type slug, or the datatype name.
	 *
	 * @return string
	 */
	public function type();

	/**
	 * Returns the id of the entity, or the value of the literal.
	 *
	 * @return string
	 */
	public function key();

	/**
	 * Tells whether another node has the same type and key.
	 *
	 * @param NodeInterface $other Other node.
	 * @return bool
	 */
	public function equals( NodeInterface $other );
}
