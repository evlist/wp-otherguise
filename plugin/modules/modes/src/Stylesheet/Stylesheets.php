<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The stylesheets of the modes.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Stylesheet;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Statements;

defined( 'ABSPATH' ) || exit;

/**
 * Which stylesheets (files of the Media Library) a mode has: statements `mode modes/stylesheet attachment`. The statement holds a
 * reference, not the CSS: an object is a literal of at most 191 bytes.
 */
final class Stylesheets {
	/**
	 * Predicate of the relation.
	 */
	public const PREDICATE = 'modes/stylesheet';

	/**
	 * Statements.
	 *
	 * @var Statements
	 */
	private $statements;

	/**
	 * Files of the Media Library.
	 *
	 * @var StylesheetFiles
	 */
	private $files;

	/**
	 * Builds the service.
	 *
	 * @param Statements      $statements Statements.
	 * @param StylesheetFiles $files      Files of the Media Library.
	 */
	public function __construct( Statements $statements, StylesheetFiles $files ) {
		$this->statements = $statements;
		$this->files      = $files;
	}

	/**
	 * Returns the attachments a mode has, in the order of their ids.
	 *
	 * @param mixed $mode A `ModeDefinition` or a reference.
	 * @return int[]
	 * @throws StylesheetException When it is not a mode.
	 */
	public function for_mode( $mode ) {
		$mode = $this->mode( $mode );
		$ids  = array();

		foreach ( $this->statements->match( $mode, self::PREDICATE ) as $statement ) {
			$ids[] = (int) $statement->object()->id();
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * Gives a stylesheet to a mode. Idempotent.
	 *
	 * @param mixed $mode          A `ModeDefinition` or a reference.
	 * @param int   $attachment_id Attachment of the Media Library.
	 * @return void
	 * @throws StylesheetException When the mode is not a mode or the attachment not a stylesheet.
	 */
	public function add( $mode, $attachment_id ) {
		$mode = $this->mode( $mode );

		if ( null === $this->files->describe( (int) $attachment_id ) ) {
			StylesheetException::refuse( StylesheetException::NOT_A_STYLESHEET, 'Attachment ' . (int) $attachment_id . ' is not a stylesheet.' );
		}

		$this->statements->triple( $mode, self::PREDICATE, new EntityRef( 'attachment', (string) (int) $attachment_id ) );
	}

	/**
	 * Takes a stylesheet away from a mode (the file stays in the Media Library).
	 *
	 * @param mixed $mode          A `ModeDefinition` or a reference.
	 * @param int   $attachment_id Attachment.
	 * @return int Number of statements deleted.
	 * @throws StylesheetException When it is not a mode.
	 */
	public function remove( $mode, $attachment_id ) {
		return $this->statements->remove( $this->mode( $mode ), self::PREDICATE, new EntityRef( 'attachment', (string) (int) $attachment_id ) );
	}

	/**
	 * Reads a mode.
	 *
	 * @param mixed $value A `ModeDefinition` or a reference.
	 * @return EntityRef
	 * @throws StylesheetException When it is not a mode.
	 */
	private function mode( $value ) {
		$mode = $this->statements->entity( $value );

		if ( 'mode' !== $mode->type() ) {
			StylesheetException::refuse( StylesheetException::NOT_A_MODE, 'Expected a mode, not ' . $mode . '.' );
		}

		return $mode;
	}
}
