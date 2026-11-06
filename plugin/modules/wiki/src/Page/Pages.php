<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The pages of the wiki.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Page;

use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Names;
use Otherguise\Wiki\Space\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * The pages (slice 502): a page has a **space** (a meta, chosen at its creation) and a **title that is a name** (slice 501). One service,
 * used by the screens, the command line, the page that does not exist (slice 504) and, from outside the plugin, the script of the migration
 * (slice 508); nothing here knows MediaWiki. The title of a page is the title of its name (a name that differs from the title shown would be
 * renamed by the next save: a display title is another feature).
 */
final class Pages {
	/**
	 * The statuses a page can be created with.
	 */
	public const STATUSES = array( 'draft', 'pending', 'private', 'publish' );

	/**
	 * The names.
	 *
	 * @var Names
	 */
	private $names;

	/**
	 * The posts.
	 *
	 * @var PageStore
	 */
	private $store;

	/**
	 * Returns the space of a space name, as registered, with its template.
	 *
	 * @var callable
	 */
	private $find_space;

	/**
	 * Builds the service.
	 *
	 * @param Names         $names      The names.
	 * @param PageStore     $store      The posts.
	 * @param callable|null $find_space Receives a name of space and returns its record or null; defaults to `Spaces::find()`.
	 */
	public function __construct( Names $names, PageStore $store, $find_space = null ) {
		$this->names      = $names;
		$this->store      = $store;
		$this->find_space = $find_space ?? array( Spaces::class, 'find' );
	}

	/**
	 * Creates a page: the rules and the claim of the name (slice 501), the space, the template of the space. All or nothing: a page whose name
	 * cannot be claimed is not left behind.
	 *
	 * @param mixed  $space   The space.
	 * @param mixed  $title   The title, as the name will be made from it.
	 * @param string $html    The content, kept as it is (blocks or HTML).
	 * @param string $status  `draft` (default), `pending`, `private` or `publish`.
	 * @return int The id of the page.
	 * @throws \InvalidArgumentException When the status is not one of the four, or WordPress cannot insert the post.
	 * @throws \Throwable                What the claim throws (a `NameException` when the space does not exist, the title is refused or
	 *                                    another page has the name), after the page is deleted again.
	 */
	public function create( $space, $title, $html = '', $status = 'draft' ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown status.' );
		}

		$parts = $this->names->qualify( $space, $title );

		$holder = $this->names->resolve( $parts['space'], $parts['title'] );

		if ( null !== $holder ) {
			NameException::refuse( NameException::TAKEN, $holder['post'], $holder['current'] );
		}

		$id = $this->store->insert(
			array(
				'post_type'    => PageStore::TYPE,
				'post_title'   => $parts['title'],
				'post_content' => (string) $html,
				'post_status'  => $status,
				'meta_input'   => array( PageStore::SPACE_META => $parts['space'] ),
			)
		);

		if ( ! is_int( $id ) || $id <= 0 ) {
			throw new \InvalidArgumentException( 'WordPress could not insert the page.' );
		}

		try {
			$this->names->claim( $id, $parts['space'], $parts['title'] );
			$this->start_template( $id, $parts['space'] );
		} catch ( \Throwable $problem ) {
			$this->store->delete( $id );

			throw $problem;
		}

		return $id;
	}

	/**
	 * Gives a saved page its name: the claim of slice 501 from the title and the space it has. A page with no title, no space, or in the
	 * trash, is left alone. Called on every save; it can be called twice.
	 *
	 * @param int $id The page.
	 * @return array{name: string, space: string, title: string, former: string|null}|null The name, or null when there was nothing to claim.
	 * @throws NameException When the name is refused or taken.
	 */
	public function sync( $id ) {
		$page = $this->store->get( $id );

		if ( null === $page || in_array( $page['status'], array( 'auto-draft', 'trash', 'inherit' ), true ) || '' === trim( $page['title'] ) || '' === $page['space'] ) {
			return null;
		}

		$done = $this->names->claim( $page['id'], $page['space'], $page['title'] );
		$this->start_template( $page['id'], $page['space'] );

		return $done;
	}

	/**
	 * Tells why a title cannot be the name of this page, without writing anything: the check made before a save, so that the editor can be
	 * told at once.
	 *
	 * @param int   $id    The page (0 for a new one).
	 * @param mixed $space The space.
	 * @param mixed $title The title.
	 * @return NameException|null The refusal, or null when the name is free (or already the page's own).
	 */
	public function check( $id, $space, $title ) {
		try {
			$parts  = $this->names->qualify( $space, $title );
			$holder = $this->names->resolve( $parts['space'], $parts['title'] );

			if ( null !== $holder && $holder['post'] !== (int) $id ) {
				NameException::refuse( NameException::TAKEN, $holder['post'], $holder['current'] );
			}
		} catch ( NameException $refusal ) {
			return $refusal;
		}

		return null;
	}

	/**
	 * The space of a page, as registered, or an empty string.
	 *
	 * @param int $id The page.
	 * @return string
	 */
	public function space_of( $id ) {
		$canonical = ( $this->find_space )( $this->store->meta( $id, PageStore::SPACE_META ) );

		return is_array( $canonical ) ? $canonical['name'] : '';
	}

	/**
	 * The registered form of a space name, or an empty string.
	 *
	 * @param mixed $space The space, in any case.
	 * @return string
	 */
	public function canonical_space( $space ) {
		$record = ( $this->find_space )( $space );

		return is_array( $record ) ? $record['name'] : '';
	}

	/**
	 * The posts, for the commands.
	 *
	 * @return PageStore
	 */
	public function store() {
		return $this->store;
	}

	/**
	 * The names, for the commands.
	 *
	 * @return Names
	 */
	public function names() {
		return $this->names;
	}

	/**
	 * A new page starts with the template of its space (slice 209: the template that a new post of a type starts with), when the space has
	 * one and the page has none.
	 *
	 * @param int    $id    The page.
	 * @param string $space The space.
	 * @return void
	 */
	private function start_template( $id, $space ) {
		$record = ( $this->find_space )( $space );

		if ( is_array( $record ) && '' !== $record['template'] && '' === $this->store->meta( $id, PageStore::TEMPLATE_META ) ) {
			$this->store->set_meta( $id, PageStore::TEMPLATE_META, $record['template'] );
		}
	}
}
