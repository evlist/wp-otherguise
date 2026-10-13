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
 * The modes reproduce the old behaviour: every image was shown on the web and only the flagged ones in print. "Flagged" is a criterion on
 * a meta of the attachment (by default `wpdfh.print` not empty); the mode that only the flagged ones get, and the MIME family the criterion
 * applies to, are options.
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
	 * @param array{mime:string, flagged:bool} $row    Attachment.
	 * @param string                           $mode   Mode reserved to the attachments that satisfy the criterion.
	 * @param string                           $family MIME family the criterion applies to (`image`).
	 * @return string[]
	 */
	public function modes_for( array $row, $mode, $family = 'image' ) {
		if ( 0 !== strpos( $row['mime'], $family . '/' ) || $row['flagged'] ) {
			return $this->modes;
		}

		return array_values( array_diff( $this->modes, array( $mode ) ) );
	}

	/**
	 * Runs the migration.
	 *
	 * @param array{dry_run?:bool, restrict_mode?:string, print_mode?:string, meta_key?:string, meta_value?:string|null, mime?:string, batch_size?:int, limit?:int} $options Options (`print_mode` is the old name of `restrict_mode`).
	 * @param callable|null                                                                                                                                         $each Called with a line of text per attachment.
	 * @return array{created:int, existing:int, skipped:array<int,string>, by_modes:array<string,int>, restrict_mode_missing:bool}
	 */
	public function run( array $options = array(), $each = null ) {
		$dry_run    = ! empty( $options['dry_run'] );
		$mode       = (string) ( $options['restrict_mode'] ?? $options['print_mode'] ?? 'print' );
		$meta_key   = isset( $options['meta_key'] ) && '' !== (string) $options['meta_key'] ? (string) $options['meta_key'] : AttachmentSource::FLAG;
		$meta_value = isset( $options['meta_value'] ) ? (string) $options['meta_value'] : null;
		$family     = isset( $options['mime'] ) && '' !== (string) $options['mime'] ? (string) $options['mime'] : 'image';
		$size       = max( 1, (int) ( $options['batch_size'] ?? 100 ) );
		$limit      = (int) ( $options['limit'] ?? 0 );
		$report     = array(
			'created'               => 0,
			'existing'              => 0,
			'skipped'               => array(),
			'by_modes'              => array(),
			'restrict_mode_missing' => ! in_array( $mode, $this->modes, true ),
		);
		$offset     = 0;

		do {
			$rows  = $this->source->batch( $offset, $size, $meta_key, $meta_value );
			$count = count( $rows );

			foreach ( $rows as $row ) {
				if ( $limit > 0 && $report['created'] >= $limit ) {
					return $report;
				}

				$this->migrate( $row, $mode, $family, $dry_run, $report, $each );
			}

			$offset += $size;
		} while ( $count === $size );

		return $report;
	}

	/**
	 * Migrates one attachment.
	 *
	 * @param array{id:int, parent:int, mime:string, flagged:bool} $row        Attachment.
	 * @param string                                               $mode       Mode reserved to the attachments that satisfy the criterion.
	 * @param string                                               $family     MIME family the criterion applies to.
	 * @param bool                                                 $dry_run    Whether to write nothing.
	 * @param array                                                $report     Report, updated.
	 * @param callable|null                                        $each       Called with a line of text.
	 * @return void
	 */
	private function migrate( array $row, $mode, $family, $dry_run, array &$report, $each ) {
		$label = sprintf( 'attachment %d, post %d', $row['id'], $row['parent'] );

		if ( null !== $this->links->find( $row['parent'], $row['id'] ) ) {
			++$report['existing'];
			$this->say( $each, $label . ': already linked' );
			return;
		}

		$modes = $this->modes_for( $row, $mode, $family );
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
