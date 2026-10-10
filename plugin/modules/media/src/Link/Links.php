<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The links between posts and media items.
 *
 * @package Otherguise
 */

namespace Otherguise\Media\Link;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Statements;
use Otherguise\Triples\Storage\Statement;

defined( 'ABSPATH' ) || exit;

/**
 * The statements `post media/illustrated-by attachment`: a post, a media item, the modes in which the item is shown in this post (one
 * `modes/mode` statement about the link each) and an optional rank (`triples/position`, about the link). Nothing here calls WordPress but
 * the function that gives the MIME type of an attachment.
 */
final class Links {
	/**
	 * Predicate of the link.
	 */
	public const PREDICATE = 'media/illustrated-by';

	/**
	 * Statements.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Gives the MIME type of an attachment: `get_post_mime_type`.
	 *
	 * @var callable
	 */
	private $mime_of;

	/**
	 * Builds the service.
	 *
	 * @param Statements    $statements Statements.
	 * @param callable|null $mime_of    Receives an attachment id; defaults to WordPress `get_post_mime_type`.
	 */
	public function __construct( Statements $statements, $mime_of = null ) {
		$this->statements = $statements;
		$this->mime_of    = $mime_of ?? 'get_post_mime_type';
	}

	/**
	 * Runs several changes as one: all or nothing.
	 *
	 * @param callable $work Work to do; its result is returned.
	 * @return mixed
	 */
	public function atomically( $work ) {
		return $this->statements->transaction( $work );
	}

	/**
	 * Finds the link between a post and an attachment.
	 *
	 * @param int $post_id       Post.
	 * @param int $attachment_id Attachment.
	 * @return Statement|null
	 */
	public function find( $post_id, $attachment_id ) {
		return $this->statements->find_by_triple( $this->post( $post_id ), self::PREDICATE, $this->attachment( $attachment_id ) );
	}

	/**
	 * Links an attachment to a post, if it is not yet, and returns the link.
	 *
	 * @param int $post_id       Post.
	 * @param int $attachment_id Attachment.
	 * @return Statement
	 * @throws \Otherguise\Triples\Service\InvalidStatementException When the post or the attachment does not exist.
	 */
	public function link( $post_id, $attachment_id ) {
		return $this->statements->triple( $this->post( $post_id ), self::PREDICATE, $this->attachment( $attachment_id ) );
	}

	/**
	 * Deletes the link, its modes and its rank.
	 *
	 * @param int $post_id       Post.
	 * @param int $attachment_id Attachment.
	 * @return int Number of statements deleted.
	 */
	public function unlink( $post_id, $attachment_id ) {
		return $this->statements->remove( $this->post( $post_id ), self::PREDICATE, $this->attachment( $attachment_id ) );
	}

	/**
	 * Makes the modes of a link exactly these: adds the missing ones and removes the others.
	 *
	 * @param Statement $link  Link.
	 * @param string[]  $modes Slugs of registered modes.
	 * @return void
	 */
	public function set_modes( Statement $link, array $modes ) {
		$wanted = array_values( array_unique( $modes ) );

		foreach ( $this->modes_of( $link ) as $slug ) {
			if ( ! in_array( $slug, $wanted, true ) ) {
				$this->statements->remove( $link, 'modes/mode', new EntityRef( 'mode', $slug ) );
			}
		}

		foreach ( $wanted as $slug ) {
			$this->statements->triple( $link, 'modes/mode', new EntityRef( 'mode', $slug ) );
		}
	}

	/**
	 * Gives a link its rank, or takes the rank away.
	 *
	 * @param Statement $link     Link.
	 * @param int|null  $position Rank, or null for none.
	 * @return void
	 */
	public function set_position( Statement $link, $position ) {
		if ( null === $position ) {
			foreach ( $this->statements->objects_of( $link, 'triples/position' ) as $statement ) {
				$this->statements->delete( $statement );
			}

			return;
		}

		$this->statements->replace( $link, 'triples/position', (int) $position );
	}

	/**
	 * Returns the modes of a link, in the order they were given.
	 *
	 * @param Statement $link Link.
	 * @return string[]
	 */
	public function modes_of( Statement $link ) {
		$modes = array();

		foreach ( $this->statements->objects_of( $link, 'modes/mode' ) as $statement ) {
			$modes[] = $statement->object()->id();
		}

		return $modes;
	}

	/**
	 * Returns the rank of a link.
	 *
	 * @param Statement $link Link.
	 * @return int|null
	 */
	public function position_of( Statement $link ) {
		foreach ( $this->statements->objects_of( $link, 'triples/position' ) as $statement ) {
			return (int) $statement->object()->key();
		}

		return null;
	}

	/**
	 * Returns the links of an attachment: the post of each, in the order the links were made.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array<int, Statement> Link by post id.
	 */
	public function links_of( $attachment_id ) {
		$links = array();

		foreach ( $this->statements->subjects_of( $this->attachment( $attachment_id ), self::PREDICATE ) as $link ) {
			$links[ (int) $link->subject()->id() ] = $link;
		}

		return $links;
	}

	/**
	 * Returns the attachments of a post, in order.
	 *
	 * With the slug of a registered mode, only the links that have that mode, the pinned ranks of that mode applied; without, every link.
	 *
	 * @param int         $post_id Post.
	 * @param string|null $mode    Slug of a mode, or null.
	 * @param string      $mime    A MIME type or a family such as `image`; empty for all.
	 * @return int[]
	 */
	public function attached( $post_id, $mode, $mime ) {
		$options = null === $mode ? array() : array( 'scope' => array( 'modes/mode', new EntityRef( 'mode', $mode ) ) );
		$ids     = array();

		foreach ( $this->statements->listing( $this->post( $post_id ), self::PREDICATE, $options ) as $link ) {
			$id = (int) $link->object()->id();

			if ( $this->matches( $id, $mime ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Tells whether the MIME type of an attachment is the one asked for (a family such as `image` or a whole type).
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $mime          Wanted type or family; empty for any.
	 * @return bool
	 */
	private function matches( $attachment_id, $mime ) {
		if ( '' === $mime ) {
			return true;
		}

		$type = (string) ( $this->mime_of )( $attachment_id );

		return false === strpos( $mime, '/' ) ? 0 === strpos( $type, $mime . '/' ) : $type === $mime;
	}

	/**
	 * Builds the reference of a post.
	 *
	 * @param int $id Id.
	 * @return EntityRef
	 */
	private function post( $id ) {
		return new EntityRef( 'post', (string) (int) $id );
	}

	/**
	 * Builds the reference of an attachment.
	 *
	 * @param int $id Id.
	 * @return EntityRef
	 */
	private function attachment( $id ) {
		return new EntityRef( 'attachment', (string) (int) $id );
	}
}
