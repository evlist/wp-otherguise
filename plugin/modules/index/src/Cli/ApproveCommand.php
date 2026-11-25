<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The command that approves ambiguous topics.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Cli;

use Otherguise\Index\Folding;
use Otherguise\Index\Kinds;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * `wp otherguise index approve [--kind=<kind>] [--search=<text>] [--apply]` (slice 635): the **ambiguous** topics (slice 642: they were called *candidates*) are outside the index;
 * this puts them in. A dry run by default.
 */
final class ApproveCommand {
	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * Prints a line.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Says it worked.
	 *
	 * @var callable
	 */
	private $success;

	/**
	 * Says it failed.
	 *
	 * @var callable
	 */
	private $fail;

	/**
	 * Builds the command.
	 *
	 * @param Topics        $topics  The topics.
	 * @param callable|null $log     Default `WP_CLI::log`.
	 * @param callable|null $success Default `WP_CLI::success`.
	 * @param callable|null $fail    Default `WP_CLI::error`.
	 */
	public function __construct( Topics $topics, $log = null, $success = null, $fail = null ) {
		$this->topics  = $topics;
		$this->log     = $log ?? array( 'WP_CLI', 'log' );
		$this->success = $success ?? array( 'WP_CLI', 'success' );
		$this->fail    = $fail ?? array( 'WP_CLI', 'error' );
	}

	/**
	 * Approves the ambiguous topics: they enter the index. Nothing is changed without --apply.
	 *
	 * ## OPTIONS
	 *
	 * [--kind=<kind>]
	 * : Only the topics of this kind (and its sub-kinds).
	 *
	 * [--search=<text>]
	 * : Only the topics whose label contains this text (accents and case ignored).
	 *
	 * [--apply]
	 * : Do it (without it, only list what would be approved).
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc ) {
		unset( $args );

		$kind   = isset( $assoc['kind'] ) ? sanitize_key( (string) $assoc['kind'] ) : '';
		$search = isset( $assoc['search'] ) ? (string) $assoc['search'] : '';

		if ( '' !== $kind && ! Kinds::is_kind( $kind ) ) {
			( $this->fail )( 'Unknown kind "' . $kind . '".' );

			return;
		}

		$count = 0;

		foreach ( $this->topics->all( array( 'status' => Topics::AMBIGUOUS ) ) as $id => $topic ) {
			if ( ( '' !== $search && false === strpos( Folding::key( (string) $topic['label'] ), Folding::key( $search ) ) )
				|| ( '' !== $kind && ! Kinds::is_a( (string) $topic['kind'], $kind ) ) ) {
				continue;
			}

			++$count;
			( $this->log )( $id . "\t" . $topic['label'] . "\t" . $topic['kind'] );

			if ( isset( $assoc['apply'] ) ) {
				$this->topics->set_status( (int) $id, Topics::APPROVED );
			}
		}

		( $this->success )( $count . ' ambiguous topic(s) ' . ( isset( $assoc['apply'] ) ? 'approved.' : 'would be approved: nothing was changed, add --apply to do it.' ) );
	}
}
