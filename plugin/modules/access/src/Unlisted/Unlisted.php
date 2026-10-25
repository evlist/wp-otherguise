<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The unlisted state of a post.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Unlisted;

use Otherguise\Access\Link\Links;
use Otherguise\Access\Link\PostMeta;

defined( 'ABSPATH' ) || exit;

/**
 * A post is **unlisted** (slice 402): its address works, but it is left out of the lists of the site. The state is a protected post meta
 * (`_access_unlisted` = `1`), not a custom status of WordPress (the block editor handles them badly). This class knows the state; the
 * filters that act on it are in `Filters`.
 */
final class Unlisted {
	public const META = '_access_unlisted';

	/**
	 * Where the state is kept (see `PostMeta`).
	 *
	 * @var object
	 */
	private $meta;

	/**
	 * Tells whether a post can be seen on the front end (the rule of the links).
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * The ids of the unlisted posts, read once per request.
	 *
	 * @var int[]|null
	 */
	private $ids = null;

	/**
	 * True while the ids are being read: `pre_get_posts` runs even for the query that reads them, which must not ask for them again.
	 *
	 * @var bool
	 */
	private $loading = false;

	/**
	 * Builds the service.
	 *
	 * @param Links       $links The links, for the rule of which posts can be seen.
	 * @param object|null $meta  Default `PostMeta`.
	 */
	public function __construct( Links $links, $meta = null ) {
		$this->links = $links;
		$this->meta  = $meta ?? new PostMeta();
	}

	/**
	 * Tells whether a post can be unlisted: the posts that can have a link (not an attachment, not in the trash, a type that has a page).
	 * A draft can: the state holds when it is published.
	 *
	 * @param mixed $post A post.
	 * @return bool
	 */
	public function eligible( $post ) {
		return $this->links->eligible( $post );
	}

	/**
	 * Tells whether a post is unlisted.
	 *
	 * @param int $id Id of the post.
	 * @return bool
	 */
	public function is_unlisted( $id ) {
		return '1' === $this->meta->get( (int) $id, self::META );
	}

	/**
	 * Sets or removes the state.
	 *
	 * @param int  $id       Id of the post.
	 * @param bool $unlisted True to hide the post from the lists.
	 * @return void
	 */
	public function set( $id, $unlisted ) {
		if ( $unlisted ) {
			$this->meta->set( (int) $id, self::META, '1' );
		} else {
			$this->meta->delete( (int) $id, self::META );
		}

		$this->ids = null;
	}

	/**
	 * The ids of the unlisted posts, read once per request (the filters ask for them at each query).
	 *
	 * @return int[]
	 */
	public function ids() {
		if ( $this->loading ) {
			return array();
		}

		if ( null === $this->ids ) {
			$this->loading = true;

			try {
				$this->ids = $this->meta->find( self::META, '1' );
			} finally {
				$this->loading = false;
			}
		}

		return $this->ids;
	}
}
