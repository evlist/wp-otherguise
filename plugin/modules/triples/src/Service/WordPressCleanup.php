<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Cleanup of the statements when WordPress deletes something.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Service;

use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the deletion actions of WordPress and forgets what involved the deleted thing.
 *
 * The callbacks run at priority 10 and only delete rows of the table of this module, so they do not depend on the order of other
 * plugins. A trashed post is not deleted and keeps its statements. Not tried on a real WordPress site: the arguments are those the
 * WordPress documentation gives for `deleted_post`, `deleted_term` and `deleted_user`.
 */
final class WordPressCleanup {
	/**
	 * Service.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Builds the cleanup.
	 *
	 * @param Statements $statements Service.
	 */
	public function __construct( Statements $statements ) {
		$this->statements = $statements;
	}

	/**
	 * Adds the callbacks to the deletion actions.
	 *
	 * @param callable $add_action Adds an action: `add_action` in WordPress.
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'deleted_post', array( $this, 'post' ), 10, 1 );
		$add_action( 'deleted_term', array( $this, 'term' ), 10, 1 );
		$add_action( 'deleted_user', array( $this, 'user' ), 10, 1 );
	}

	/**
	 * A post was deleted: posts and media items share their ids, and the post type is gone, so both are forgotten.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function post( $post_id ) {
		$this->statements->forget( Ref::post( $post_id ) );
		$this->statements->forget( Ref::attachment( $post_id ) );
	}

	/**
	 * A term was deleted.
	 *
	 * @param int $term_id Term id.
	 * @return void
	 */
	public function term( $term_id ) {
		$this->statements->forget( Ref::term( $term_id ) );
	}

	/**
	 * A user was deleted; the user the posts are reassigned to does not matter, a statement about a user is not content.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function user( $user_id ) {
		$this->statements->forget( Ref::user( $user_id ) );
	}
}
