<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The index of the links between the pages of the tree.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Tree;

use Otherguise\Triples\Entity\Literal;
use Otherguise\Triples\Entity\Ref;
use Otherguise\Triples\Statements;

defined( 'ABSPATH' ) || exit;

/**
 * Which pages cite which (slice 506): an index of the ordinary links (slice 505), kept in `triples`, and its two readings.
 *
 * - `wiki/cites` (page to page, "Cited by" backwards): the page has a link to that page. It is kept **by id**, so it survives a rename or a
 *   move (an alias counts as a link to the page that has it).
 * - `wiki/wants` (page to a path of titles, `Biography/Nobody`): the page has a link to a path that **enters the tree and stops** (a red
 *   link of slice 505). A path longer than a literal (191 bytes) is not recorded.
 *
 * `sync()` brings the statements of a page in line with its content; `claimed()` turns the `wants` of a path into `cites` when a page gets
 * that path (a creation, a rename, a move). A page that is deleted is cleaned by Triples. A link to the page itself is left out.
 */
final class Citations {
	public const CITES = 'wiki/cites';
	public const WANTS = 'wiki/wants';

	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * Reads an address.
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * Returns the service of the statements; called when needed.
	 *
	 * @var callable
	 */
	private $statements;

	/**
	 * Builds the service.
	 *
	 * @param Keys     $keys       The index of the tree.
	 * @param Links    $links      Reads an address.
	 * @param callable $statements Returns the `Statements` service (the module Triples).
	 */
	public function __construct( Keys $keys, Links $links, $statements ) {
		$this->keys       = $keys;
		$this->links      = $links;
		$this->statements = $statements;
	}

	/**
	 * What the links of a content stand for.
	 *
	 * @param string $html The content.
	 * @return array{cites: int[], wants: string[]}
	 */
	public function read( $html ) {
		$cites = array();
		$wants = array();

		if ( false === stripos( (string) $html, '<a' ) || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return array(
				'cites' => array(),
				'wants' => array(),
			);
		}

		$tags = new \WP_HTML_Tag_Processor( (string) $html );

		while ( $tags->next_tag( 'a' ) ) {
			$href   = $tags->get_attribute( 'href' );
			$titles = is_string( $href ) ? $this->links->titles_of( $href ) : null;

			if ( null === $titles ) {
				continue;
			}

			$walk = $this->keys->resolve_path( $titles );

			if ( null !== $walk['post'] ) {
				$cites[ (int) $walk['post'] ] = (int) $walk['post'];
			} elseif ( $walk['deepest'] > 0 || array() !== $this->keys->similar( 0, $titles[0] ) ) {
				$path = $this->path_of_titles( $titles );

				if ( null !== $path ) {
					$wants[ $path ] = $path;
				}
			}
		}

		ksort( $cites );
		ksort( $wants );

		return array(
			'cites' => array_values( $cites ),
			'wants' => array_values( $wants ),
		);
	}

	/**
	 * Brings the statements of a page in line with its content. Nothing is written when nothing changed.
	 *
	 * @param int    $post_id The page.
	 * @param string $html    Its content.
	 * @return bool Whether something changed.
	 */
	public function sync( $post_id, $html ) {
		$post_id = (int) $post_id;
		$read    = $this->read( $html );
		$cites   = array_values( array_diff( $read['cites'], array( $post_id ) ) );

		return $this->statements()->transaction(
			function () use ( $post_id, $cites, $read ) {
				$page    = Ref::post( $post_id );
				$changed = $this->reconcile( $page, self::CITES, $cites, array( $this, 'target' ) );

				return $this->reconcile( $page, self::WANTS, $read['wants'], array( $this, 'literal' ) ) || $changed;
			}
		);
	}

	/**
	 * A page got its key: the pages that wanted its path now cite it. Call after a creation, a rename or a move.
	 *
	 * @param int $post_id The page.
	 * @return int The number of pages that cite it now because of that.
	 */
	public function claimed( $post_id ) {
		$titles = $this->keys->path_of( (int) $post_id );
		$path   = null === $titles ? null : $this->path_of_titles( $titles );

		if ( null === $path ) {
			return 0;
		}

		$done = 0;

		foreach ( $this->statements()->subjects_of( $this->literal( $path ), self::WANTS ) as $statement ) {
			$from = (int) $statement->subject()->id();

			if ( $from !== (int) $post_id ) {
				$this->statements()->triple( Ref::post( $from ), self::CITES, Ref::post( (int) $post_id ) );
				++$done;
			}

			$this->statements()->delete( $statement );
		}

		return $done;
	}

	/**
	 * The pages that cite a page.
	 *
	 * @param int $post_id The page.
	 * @return int[]
	 */
	public function cited_by( $post_id ) {
		$ids = array();

		foreach ( $this->statements()->subjects_of( Ref::post( (int) $post_id ), self::CITES ) as $statement ) {
			$ids[] = (int) $statement->subject()->id();
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * The wanted pages: every path that some page links to and that nobody has yet.
	 *
	 * @return array<string, int[]> Path of titles joined by a slash => the pages that want it, most wanted first, then by path.
	 */
	public function wanted() {
		$wanted = array();

		foreach ( $this->statements()->match( null, self::WANTS ) as $statement ) {
			$wanted[ $statement->object()->key() ][] = (int) $statement->subject()->id();
		}

		foreach ( $wanted as $path => $from ) {
			sort( $from );
			$wanted[ $path ] = $from;
		}

		uksort(
			$wanted,
			static function ( $a, $b ) use ( $wanted ) {
				return array( count( $wanted[ $b ] ), $a ) <=> array( count( $wanted[ $a ] ), $b );
			}
		);

		return $wanted;
	}

	/**
	 * Reads every page again: the statements are brought in line with the content.
	 *
	 * @param iterable<int, array{id: int, content: string}> $pages The pages, with their content.
	 * @param bool                                           $apply False reads and counts only (a dry run).
	 * @return array{pages: int, changed: int}
	 */
	public function rebuild( $pages, $apply = true ) {
		$report = array(
			'pages'   => 0,
			'changed' => 0,
		);

		foreach ( $pages as $page ) {
			++$report['pages'];

			if ( $apply ) {
				$report['changed'] += $this->sync( (int) $page['id'], (string) $page['content'] ) ? 1 : 0;
			} elseif ( $this->differs( (int) $page['id'], (string) $page['content'] ) ) {
				++$report['changed'];
			}
		}

		return $report;
	}

	/**
	 * Deletes the statements of pages that no longer exist: the links they made (`wiki/cites`, `wiki/wants`) and the links to them. A deletion
	 * made by a way that skips the hooks of WordPress (`wp site empty` truncates the tables) leaves them behind.
	 *
	 * @param int[] $pages The ids of the pages that exist.
	 * @return int The number of statements deleted.
	 */
	public function purge( array $pages ) {
		$known   = array_flip( array_map( 'intval', $pages ) );
		$deleted = 0;

		foreach ( $this->statements()->match( null, self::WANTS ) as $statement ) {
			if ( ! isset( $known[ (int) $statement->subject()->id() ] ) ) {
				$this->statements()->delete( $statement );
				++$deleted;
			}
		}

		foreach ( $this->statements()->match( null, self::CITES ) as $statement ) {
			if ( ! isset( $known[ (int) $statement->subject()->id() ], $known[ (int) $statement->object()->id() ] ) ) {
				$this->statements()->delete( $statement );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Whether the statements of a page differ from what its content says.
	 *
	 * @param int    $post_id The page.
	 * @param string $html    Its content.
	 * @return bool
	 */
	private function differs( $post_id, $html ) {
		$read  = $this->read( $html );
		$cites = array_values( array_diff( $read['cites'], array( $post_id ) ) );
		$page  = Ref::post( $post_id );
		$now   = array();
		$want  = array();

		foreach ( $this->statements()->objects_of( $page, self::CITES ) as $statement ) {
			$now[] = (int) $statement->object()->id();
		}

		foreach ( $this->statements()->objects_of( $page, self::WANTS ) as $statement ) {
			$want[] = $statement->object()->key();
		}

		sort( $now );
		sort( $want );

		return $now !== $cites || $want !== $read['wants'];
	}

	/**
	 * Makes the statements of a predicate for a page the list given: the missing ones added, the extra ones removed.
	 *
	 * @param mixed    $page      The page.
	 * @param string   $predicate The predicate.
	 * @param array    $wanted    The objects wanted (ids or strings).
	 * @param callable $make      Turns one of them into an object of a statement.
	 * @return bool Whether something changed.
	 */
	private function reconcile( $page, $predicate, array $wanted, $make ) {
		$statements = $this->statements();
		$keep       = array();
		$changed    = false;

		foreach ( $statements->objects_of( $page, $predicate ) as $statement ) {
			$object = self::CITES === $predicate ? (int) $statement->object()->id() : $statement->object()->key();

			if ( in_array( $object, $wanted, true ) ) {
				$keep[] = $object;
			} else {
				$statements->delete( $statement );
				$changed = true;
			}
		}

		foreach ( array_diff( $wanted, $keep ) as $object ) {
			$statements->triple( $page, $predicate, $make( $object ) );
			$changed = true;
		}

		return $changed;
	}

	/**
	 * The object of a `cites` statement.
	 *
	 * @param int $id The page.
	 * @return mixed
	 */
	private function target( $id ) {
		return Ref::post( (int) $id );
	}

	/**
	 * A literal of the datatype `string`.
	 *
	 * @param string $value Value.
	 * @return Literal
	 */
	private function literal( $value ) {
		return new Literal( 'string', $value );
	}

	/**
	 * The path of titles joined by a slash, the titles put through the rules; null when one is refused or the path does not fit a literal.
	 *
	 * @param string[] $titles The titles.
	 * @return string|null
	 */
	private function path_of_titles( array $titles ) {
		try {
			$path = implode( '/', array_map( array( Title::class, 'title' ), $titles ) );
		} catch ( \Otherguise\Wiki\NameException $refusal ) {
			return null;
		}

		return strlen( $path ) > Title::MAX_BYTES ? null : $path;
	}

	/**
	 * The statements of Triples.
	 *
	 * @return Statements
	 */
	private function statements() {
		return ( $this->statements )();
	}
}
