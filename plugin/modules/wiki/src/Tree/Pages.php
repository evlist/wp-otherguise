<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The pages of the tree.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

use Otherguise\Wiki\NameException;

defined( 'ABSPATH' ) || exit;

/**
 * The pages of the wiki (slice 502) are the pages of WordPress: `post_parent` is the tree, and the title is the title of the page. One
 * service, used by the hooks of the saving, the command line, the page that does not exist (slice 504) and, from outside the plugin, the script
 * of the migration (slice 508); nothing here knows MediaWiki.
 */
final class Pages {
	/**
	 * The statuses a page can be created with.
	 */
	public const STATUSES = array( 'draft', 'pending', 'private', 'publish' );

	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The posts.
	 *
	 * @var Nodes
	 */
	private $nodes;

	/**
	 * Builds the service.
	 *
	 * @param Keys  $keys  The index of the tree.
	 * @param Nodes $nodes The posts.
	 */
	public function __construct( Keys $keys, Nodes $nodes ) {
		$this->keys  = $keys;
		$this->nodes = $nodes;
	}

	/**
	 * Creates a page under a parent: the key is checked before the page is made, and the page is deleted again if its key cannot be claimed.
	 *
	 * @param int    $parent_id The parent, a page (0 for the first level).
	 * @param mixed  $title     The title.
	 * @param string $html      The content, kept as it is (blocks or HTML).
	 * @param string $status    `draft` (default), `pending`, `private` or `publish`.
	 * @return int The id of the page.
	 * @throws \InvalidArgumentException When the status is not one of the four, or WordPress cannot insert the post.
	 * @throws \Throwable                What the claim throws (a `NameException` when the parent does not exist, the title is refused or
	 *                                   another page has the key), after the page is deleted again.
	 */
	public function create( $parent_id, $title, $html = '', $status = 'draft' ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown status.' );
		}

		$parent_id = max( 0, (int) $parent_id );

		if ( $parent_id > 0 && null === $this->nodes->get( $parent_id ) ) {
			NameException::refuse( NameException::UNKNOWN_PARENT );
		}

		$refusal = $this->keys->check( 0, $parent_id, $title );

		if ( null !== $refusal ) {
			NameException::refuse( $refusal->error_code(), $refusal->holder(), $refusal->holder_has_it_as_current() );
		}

		$clean = Title::title( $title );
		$id    = $this->nodes->insert(
			array(
				'post_type'    => Nodes::TYPE,
				'post_title'   => $clean,
				'post_content' => (string) $html,
				'post_status'  => $status,
				'post_parent'  => $parent_id,
			)
		);

		if ( ! is_int( $id ) || $id <= 0 ) {
			throw new \InvalidArgumentException( 'WordPress could not insert the page.' );
		}

		try {
			$this->keys->claim( $id, $parent_id, $clean );
		} catch ( \Throwable $problem ) {
			$this->nodes->delete( $id );

			throw $problem;
		}

		return $id;
	}

	/**
	 * Gives a saved page its key from its parent and its title. A page with no title, in the trash, or an auto-draft, is left alone. Called
	 * on every save; it can be called twice. A new title is a rename and a new parent a move.
	 *
	 * @param int $id The page.
	 * @return array{key: string, parent: int, title: string, former: string|null}|null The key, or null when there was nothing to claim.
	 * @throws NameException When the key is refused or taken.
	 */
	public function sync( $id ) {
		$page = $this->nodes->get( $id );

		if ( null === $page || in_array( $page['status'], array( 'auto-draft', 'trash', 'inherit' ), true ) || '' === trim( $page['title'] ) ) {
			return null;
		}

		return $this->keys->claim( $page['id'], $page['parent'], $page['title'] );
	}

	/**
	 * Tells why a title cannot be the title of this page under this parent, without writing anything (the check before a save).
	 *
	 * @param int   $id     The page (0 for a new one).
	 * @param int   $parent The parent.
	 * @param mixed $title  The title.
	 * @return NameException|null
	 */
	public function check( $id, $parent, $title ) {
		return $this->keys->check( $id, $parent, $title );
	}

	/**
	 * Finds a page by the path of its titles.
	 *
	 * @param string[] $titles The titles from the first level.
	 * @return array{post: int|null, exact: bool, deepest: int, rest: string[]}
	 */
	public function find( array $titles ) {
		return $this->keys->resolve_path( $titles );
	}

	/**
	 * The index.
	 *
	 * @return Keys
	 */
	public function keys() {
		return $this->keys;
	}

	/**
	 * The posts.
	 *
	 * @return Nodes
	 */
	public function nodes() {
		return $this->nodes;
	}
}
