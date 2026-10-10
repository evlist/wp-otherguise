<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The attachment method of Otherguise, for Media Helper.
 *
 * @package Otherguise
 */

namespace Otherguise\Media\Method;

use Otherguise\Media\Link\Links;
use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Triples\Service\InvalidStatementException;
use WP_Media_Helper\Attachment\Method;

defined( 'ABSPATH' ) || exit;

/**
 * How Media Helper attaches a media item when Otherguise is the method in use: to several posts, in the modes chosen, at a rank, through
 * statements. This class is loaded only when Media Helper has declared the interface it implements (see `Module`).
 */
final class MediaHelperMethod implements Method {
	/**
	 * Identifier of the method.
	 */
	public const ID = 'otherguise';

	/**
	 * Links.
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * Modes.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Parents of the attachments.
	 *
	 * @var PostParents
	 */
	private $parents;

	/**
	 * Tells whether the user may change an attachment, given its id.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Builds the method.
	 *
	 * @param Links         $links    Links.
	 * @param ModeRegistry  $modes    Modes.
	 * @param PostParents   $parents  Parents of the attachments.
	 * @param callable|null $can_edit Receives an attachment id; defaults to `current_user_can( 'edit_post', $id )`.
	 */
	public function __construct( Links $links, ModeRegistry $modes, PostParents $parents, $can_edit = null ) {
		$this->links    = $links;
		$this->modes    = $modes;
		$this->parents  = $parents;
		$this->can_edit = $can_edit ?? static function ( $id ) {
			return current_user_can( 'edit_post', $id );
		};
	}

	/**
	 * Identifier of the method.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Name shown in the settings.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Otherguise (several posts, modes and ranks)', 'otherguise' );
	}

	/**
	 * Several posts, and two fields: the modes and the rank.
	 *
	 * @return array{multiple_posts:bool, fields:array<int, array<string, mixed>>}
	 */
	public function capabilities(): array {
		$options = array();

		foreach ( $this->modes->all() as $mode ) {
			$options[] = array(
				'value' => $mode->slug(),
				'label' => $mode->label(),
			);
		}

		return array(
			'multiple_posts' => true,
			'fields'         => array(
				array(
					'key'     => 'modes',
					'label'   => __( 'Shown in the modes', 'otherguise' ),
					'type'    => 'multiselect',
					'options' => $options,
					'default' => array_keys( $this->modes->all() ),
				),
				array(
					'key'     => 'position',
					'label'   => __( 'Position', 'otherguise' ),
					'type'    => 'integer',
					'default' => null,
				),
			),
		);
	}

	/**
	 * How each attachment stands for a post.
	 *
	 * @param int[] $attachment_ids Attachments of the page.
	 * @param int   $post_id        Post.
	 * @return array<int, array{attached_here:bool, elsewhere:int[], protected:string|null, data:array<string, mixed>}>
	 */
	public function describe( array $attachment_ids, int $post_id ): array {
		$described = array();

		foreach ( array_unique( array_map( 'intval', $attachment_ids ) ) as $id ) {
			$links     = $this->links->links_of( $id );
			$here      = $links[ $post_id ] ?? null;
			$elsewhere = array_values( array_filter( array_keys( $links ), static fn( $post ) => $post !== $post_id ) );

			$described[ $id ] = array(
				'attached_here' => null !== $here && 0 !== $post_id,
				'elsewhere'     => $elsewhere,
				'protected'     => null,
				'data'          => null === $here || 0 === $post_id ? array() : array(
					'modes'    => $this->links->modes_of( $here ),
					'position' => $this->links->position_of( $here ),
				),
			);
		}

		return $described;
	}

	/**
	 * Links the attachment to the post: the link, its modes (given, else every mode) and its rank (when given).
	 *
	 * @param int                  $attachment_id Attachment.
	 * @param int                  $post_id       Post.
	 * @param array<string, mixed> $data         `modes`, `position`.
	 * @return true|\WP_Error
	 */
	public function attach( int $attachment_id, int $post_id, array $data ) {
		if ( ! ( $this->can_edit )( $attachment_id ) ) {
			return new \WP_Error( 'not_allowed', __( 'You are not allowed to attach this media.', 'otherguise' ) );
		}

		if ( ! array_key_exists( 'modes', $data ) ) {
			$data['modes'] = array_keys( $this->modes->all() );
		}

		$checked = $this->check( $data );

		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		try {
			$this->links->atomically(
				function () use ( $post_id, $attachment_id, $checked ) {
					$this->write( $post_id, $attachment_id, $checked );
				}
			);
		} catch ( InvalidStatementException $problem ) {
			return new \WP_Error( 'attach_failed', __( 'Unable to attach the media to this post.', 'otherguise' ) );
		}

		if ( 0 === $this->parents->get( $attachment_id ) ) {
			$this->parents->set( $attachment_id, $post_id );
		}

		return true;
	}

	/**
	 * Removes the link with this post only, and keeps the primary parent meaningful.
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $post_id       Post.
	 * @return true|\WP_Error
	 */
	public function detach( int $attachment_id, int $post_id ) {
		if ( null === $this->links->find( $post_id, $attachment_id ) ) {
			return true;
		}

		if ( ! ( $this->can_edit )( $attachment_id ) ) {
			return new \WP_Error( 'not_allowed', __( 'You are not allowed to change this media.', 'otherguise' ) );
		}

		$this->links->unlink( $post_id, $attachment_id );

		if ( $post_id === $this->parents->get( $attachment_id ) ) {
			$remaining = array_keys( $this->links->links_of( $attachment_id ) );

			$this->parents->set( $attachment_id, array() === $remaining ? 0 : $remaining[0] );
		}

		return true;
	}

	/**
	 * Changes the modes or the rank of an existing link: only the keys that are given.
	 *
	 * @param int                  $attachment_id Attachment.
	 * @param int                  $post_id       Post.
	 * @param array<string, mixed> $data         `modes`, `position`.
	 * @return true|\WP_Error
	 */
	public function update( int $attachment_id, int $post_id, array $data ) {
		if ( null === $this->links->find( $post_id, $attachment_id ) ) {
			return new \WP_Error( 'not_attached', __( 'This media is not attached to this post.', 'otherguise' ) );
		}

		if ( ! ( $this->can_edit )( $attachment_id ) ) {
			return new \WP_Error( 'not_allowed', __( 'You are not allowed to change this media.', 'otherguise' ) );
		}

		$checked = $this->check( $data );

		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		try {
			$this->links->atomically(
				function () use ( $post_id, $attachment_id, $checked ) {
					$this->write( $post_id, $attachment_id, $checked );
				}
			);
		} catch ( InvalidStatementException $problem ) {
			return new \WP_Error( 'update_failed', __( 'Unable to change the link.', 'otherguise' ) );
		}

		return true;
	}

	/**
	 * The attachments of a post, in order; `context` is the slug of a mode.
	 *
	 * @param int                  $post_id Post.
	 * @param array<string, mixed> $args   `mime_type`, `context`.
	 * @return int[]
	 */
	public function attached( int $post_id, array $args ): array {
		$context = isset( $args['context'] ) && is_string( $args['context'] ) && $this->modes->has( $args['context'] ) ? $args['context'] : null;
		$mime    = isset( $args['mime_type'] ) && is_string( $args['mime_type'] ) ? $args['mime_type'] : '';

		return $this->links->attached( $post_id, $context, $mime );
	}

	/**
	 * The posts of each attachment, the primary link first.
	 *
	 * @param int[] $attachment_ids Attachments of the page.
	 * @return array<int, int[]>
	 */
	public function posts_of( array $attachment_ids ): array {
		$posts = array();

		foreach ( array_unique( array_map( 'intval', $attachment_ids ) ) as $id ) {
			$posts[ $id ] = array_keys( $this->links->links_of( $id ) );
		}

		return $posts;
	}

	/**
	 * The trash is accepted (the user was asked, and the links go with the attachment); removing from the library is refused while the
	 * attachment is linked to a post.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $context      `remove` or `trash`.
	 * @return true|\WP_Error
	 */
	public function may_remove( int $attachment_id, string $context = 'remove' ) {
		if ( 'trash' !== $context && array() !== $this->links->links_of( $attachment_id ) ) {
			return new \WP_Error( 'attached', __( 'This media is attached to a post.', 'otherguise' ) );
		}

		return true;
	}

	/**
	 * Validates the data of a link: the modes must be registered, the rank an integer or empty. Only the keys that are given are kept.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check( array $data ) {
		$checked = array();

		if ( array_key_exists( 'modes', $data ) ) {
			$modes = is_array( $data['modes'] ) ? array_values( $data['modes'] ) : null;

			if ( null === $modes || count( $modes ) !== count( array_unique( $modes, SORT_REGULAR ) ) ) {
				return new \WP_Error( 'invalid_data', __( 'The modes are not valid.', 'otherguise' ) );
			}

			foreach ( $modes as $slug ) {
				if ( ! is_string( $slug ) || ! $this->modes->has( $slug ) ) {
					return new \WP_Error( 'invalid_data', __( 'The modes are not valid.', 'otherguise' ) );
				}
			}

			$checked['modes'] = $modes;
		}

		if ( array_key_exists( 'position', $data ) ) {
			$position = $data['position'];

			if ( null !== $position && '' !== $position && ! is_int( $position ) && ! ( is_string( $position ) && 1 === preg_match( '/^-?[0-9]{1,9}\z/', $position ) ) ) {
				return new \WP_Error( 'invalid_data', __( 'The position must be a whole number.', 'otherguise' ) );
			}

			$checked['position'] = null === $position || '' === $position ? null : (int) $position;
		}

		return $checked;
	}

	/**
	 * Writes the modes and the rank given, in one transaction.
	 *
	 * @param int                  $post_id       Post.
	 * @param int                  $attachment_id Attachment.
	 * @param array<string, mixed> $checked      Validated data.
	 * @return void
	 */
	private function write( $post_id, $attachment_id, array $checked ) {
		$link = $this->links->link( $post_id, $attachment_id );

		if ( array_key_exists( 'modes', $checked ) ) {
			$this->links->set_modes( $link, $checked['modes'] );
		}

		if ( array_key_exists( 'position', $checked ) ) {
			$this->links->set_position( $link, $checked['position'] );
		}
	}
}
