<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Shorthand constructors of references to WordPress entities.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the reference to an entity when only its id is at hand; with an object at hand, pass the object itself.
 */
final class Ref {
	/**
	 * Reference to a post (any post type except attachments).
	 *
	 * @param int $id Post id.
	 * @return EntityRef
	 */
	public static function post( $id ) {
		return new EntityRef( 'post', (string) (int) $id );
	}

	/**
	 * Reference to a media item.
	 *
	 * @param int $id Attachment id.
	 * @return EntityRef
	 */
	public static function attachment( $id ) {
		return new EntityRef( 'attachment', (string) (int) $id );
	}

	/**
	 * Reference to a term.
	 *
	 * @param int $id Term id.
	 * @return EntityRef
	 */
	public static function term( $id ) {
		return new EntityRef( 'term', (string) (int) $id );
	}

	/**
	 * Reference to a user.
	 *
	 * @param int $id User id.
	 * @return EntityRef
	 */
	public static function user( $id ) {
		return new EntityRef( 'user', (string) (int) $id );
	}
}
