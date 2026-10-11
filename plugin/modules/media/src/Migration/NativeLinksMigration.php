<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Turns the parents of attachments and the flag of wp-pdf-helper into links.
 *
 * @package Otherguise
 */

namespace Otherguise\Media\Migration;

use Otherguise\Media\Link\Links;

defined( 'ABSPATH' ) || exit;

/**
 * For each attachment that has a parent and no link to it yet, creates the link and its modes. Nothing is changed or deleted on the
 * attachment itself, and a link that exists is left alone, so the migration can be run again.
 *
 * The modes reproduce the old behaviour: every image was shown on the web and only the flagged ones in print.
 */
final class NativeLinksMigration {
	/**
	 * Links.
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * Slugs of the registered modes.
	 *
	 * @var string[]
	 */
	private $modes;

	/**
	 * Source of the attachments.
	 *
	 * @var AttachmentSource
	 */
	private $source;

	/**
	 * Builds the migration.
	 *
	 * @param Links            $links  Links.
	 * @param string[]         $modes  Slugs of the registered modes.
	 * @param AttachmentSource $source Source of the attachments.
	 */
	public function __construct( Links $links, array $modes, AttachmentSource $source ) {
		$this->links  = $links;
		$this->modes  = array_values( $modes );
		$this->source = $source;
	}

	/**
	 * Returns the modes an attachment gets.
	 *
	 * @param array{mime:string, flagged:bool} $row        Attachment.
	 * @param string                           $print_mode Mode of the print flag.
	 * @return string[]
	 */
	public function modes_for( array $row, $print_mode ) {
		$image = 0 === strpos( $row['mime'], 'image/' );

		if ( ! $image || $row['flagged'] ) {
			return $this->modes;
		}

		return array_values( array_diff( $this->modes, array( $print_mode ) ) );
	}

	/**
	 * Runs the migration.
	 *
	 * @param array{dry_run?:bool, print_mode?:string, batch_size?:int, limit?:int} $options Options.
	 * @param callable|null                                                         $each    Called with a line of text per attachment.
	 * @return array{created:int, existing:int, skipped:array<int,string>, by_modes:array<string,int>, print_mode_missing:bool}
	 */
	public function run( array $options = array(), $each = null ) {
		$dry_run    = ! empty( $options['dry_run'] );
		$print_mode = isset( $options['print_mode'] ) ? (string) $options['print_mode'] : 'print';
		$size       = max( 1, (int) ( $options['batch_size'] ?? 100 ) );
		$limit      = (int) ( $options['limit'] ?? 0 );
		$report     = array(
			'created'            => 0,
			'existing'           => 0,
			'skipped'            => array(),
			'by_modes'           => array(),
			'print_mode_missing' => ! in_array( $print_mode, $this->modes, true ),
		);
		$offset     = 0;

		do {
			$rows  = $this->source->batch( $offset, $size );
			$count = count( $rows );

			foreach ( $rows as $row ) {
				if ( $limit > 0 && $report['created'] >= $limit ) {
					return $report;
				}

				$this->migrate( $row, $print_mode, $dry_run, $report, $each );
			}

			$offset += $size;
		} while ( $count === $size );

		return $report;
	}

	/**
	 * Migrates one attachment.
	 *
	 * @param array{id:int, parent:int, mime:string, flagged:bool} $row        Attachment.
	 * @param string                                               $print_mode Mode of the print flag.
	 * @param bool                                                 $dry_run    Whether to write nothing.
	 * @param array                                                $report     Report, updated.
	 * @param callable|null                                        $each       Called with a line of text.
	 * @return void
	 */
	private function migrate( array $row, $print_mode, $dry_run, array &$report, $each ) {
		$label = sprintf( 'attachment %d, post %d', $row['id'], $row['parent'] );

		if ( null !== $this->links->find( $row['parent'], $row['id'] ) ) {
			++$report['existing'];
			$this->say( $each, $label . ': already linked' );
			return;
		}

		$modes = $this->modes_for( $row, $print_mode );
		$key   = implode( ',', $modes );

		if ( $dry_run ) {
			if ( ! get_post( $row['parent'] ) ) {
				$report['skipped'][] = $label . ': the parent does not exist';
				return;
			}
		} else {
			try {
				$this->links->atomically(
					function () use ( $row, $modes ) {
						$link = $this->links->link( $row['parent'], $row['id'] );
						$this->links->set_modes( $link, $modes );
					}
				);
			} catch ( \Exception $e ) {
				$report['skipped'][] = $label . ': ' . $e->getMessage();
				return;
			}
		}

		++$report['created'];
		$report['by_modes'][ $key ] = ( $report['by_modes'][ $key ] ?? 0 ) + 1;
		$this->say( $each, $label . ': ' . ( $dry_run ? 'would be linked' : 'linked' ) . ' in ' . ( '' === $key ? 'no mode' : $key ) );
	}

	/**
	 * Sends a line to the callback, if any.
	 *
	 * @param callable|null $each Callback.
	 * @param string        $line Line.
	 * @return void
	 */
	private function say( $each, $line ) {
		if ( null !== $each ) {
			$each( $line );
		}
	}
}
