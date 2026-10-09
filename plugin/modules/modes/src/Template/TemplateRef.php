<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Identifiers of templates and template parts.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Template;

use Otherguise\Triples\Entity\EntityRef;

defined( 'ABSPATH' ) || exit;

/**
 * A template and a template part are identified like WordPress does: `stylesheet//slug` (`twentytwentyfive//single`), whether the
 * template is a database post, a file of a block theme, or the PHP file of a classic theme. The entity types are `template` and
 * `template_part`: a template and a template part can have the same slug.
 */
final class TemplateRef {
	/**
	 * Entity type of the templates.
	 */
	public const TEMPLATE = 'template';

	/**
	 * Entity type of the template parts.
	 */
	public const PART = 'template_part';

	/**
	 * Pattern of an id: the theme, two slashes, the slug.
	 */
	public const ID_PATTERN = '/^[A-Za-z0-9_.\-]+\/\/[A-Za-z0-9_.\-]+\z/';

	/**
	 * Tells whether an id is well formed.
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	public static function is_valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( self::ID_PATTERN, $id );
	}

	/**
	 * Returns the theme of an id.
	 *
	 * @param string $id Valid id.
	 * @return string
	 */
	public static function theme( $id ) {
		return substr( $id, 0, (int) strpos( $id, '//' ) );
	}

	/**
	 * Returns the slug of an id.
	 *
	 * @param string $id Valid id.
	 * @return string
	 */
	public static function slug( $id ) {
		return substr( $id, (int) strpos( $id, '//' ) + 2 );
	}

	/**
	 * Builds the reference to a template.
	 *
	 * @param string $slug  Slug of the template (`single`, `page-print`).
	 * @param string $theme Stylesheet of the theme.
	 * @return EntityRef
	 * @throws \InvalidArgumentException When the result is not a valid id.
	 */
	public static function template( $slug, $theme ) {
		return new EntityRef( self::TEMPLATE, self::id( $slug, $theme ) );
	}

	/**
	 * Builds the reference to a template part.
	 *
	 * @param string $slug  Slug of the template part (`header`).
	 * @param string $theme Stylesheet of the theme.
	 * @return EntityRef
	 * @throws \InvalidArgumentException When the result is not a valid id.
	 */
	public static function part( $slug, $theme ) {
		return new EntityRef( self::PART, self::id( $slug, $theme ) );
	}

	/**
	 * Builds an id.
	 *
	 * @param string $slug  Slug.
	 * @param string $theme Stylesheet.
	 * @return string
	 * @throws \InvalidArgumentException When the id is malformed.
	 */
	private static function id( $slug, $theme ) {
		$id = $theme . '//' . $slug;

		if ( ! self::is_valid_id( $id ) ) {
			throw new \InvalidArgumentException( 'The slug and the theme of a template are made of letters, digits, dots, dashes and underscores.' );
		}

		return $id;
	}
}
