<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The state of the PDF conversion of a post.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the state of the conversion of a post in a given mode (slice 220) in a transient: `queued`, `running`, `done` or `failed`, with
 * the key of the cache it is about, a message, the number of pages when known, and the times.
 */
final class JobState {
	/**
	 * How long a state is kept, in seconds.
	 */
	public const LIFETIME = 86400;

	/**
	 * Returns the state of a post and a mode: an empty array when there is none.
	 *
	 * @param int    $post_id Post.
	 * @param string $mode    Slug of the mode.
	 * @return array<string, mixed>
	 */
	public function get( $post_id, $mode ) {
		$state = get_transient( $this->name( $post_id, $mode ) );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Stores the state.
	 *
	 * @param int                  $post_id Post.
	 * @param string               $mode    Slug of the mode.
	 * @param array<string, mixed> $state   State.
	 * @return void
	 */
	public function set( $post_id, $mode, array $state ) {
		set_transient( $this->name( $post_id, $mode ), $state, self::LIFETIME );
	}

	/**
	 * Forgets the state.
	 *
	 * @param int    $post_id Post.
	 * @param string $mode    Slug of the mode.
	 * @return void
	 */
	public function clear( $post_id, $mode ) {
		delete_transient( $this->name( $post_id, $mode ) );
	}

	/**
	 * Name of the transient.
	 *
	 * @param int    $post_id Post.
	 * @param string $mode    Slug of the mode.
	 * @return string
	 */
	private function name( $post_id, $mode ) {
		return 'books_pdf_' . (int) $post_id . '_' . substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $mode ) ), 0, 40 );
	}
}
