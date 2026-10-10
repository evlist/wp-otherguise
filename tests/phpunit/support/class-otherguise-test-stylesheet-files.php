<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Test double of the files of the Media Library that are stylesheets.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Stylesheet\StylesheetException;
use Otherguise\Modes\Stylesheet\StylesheetFiles;

/**
 * The stylesheets are given by the test; an upload returns what the test queued.
 */
final class Otherguise_Test_Stylesheet_Files extends StylesheetFiles {
	/**
	 * Description by attachment id.
	 *
	 * @var array<int, array>
	 */
	public $files = array();

	/**
	 * What the next uploads give: an attachment id, or the code of a refusal.
	 *
	 * @var array<int, int|string>
	 */
	public $uploads = array();

	/**
	 * Names of the fields uploaded.
	 *
	 * @var string[]
	 */
	public $uploaded = array();

	/**
	 * Declares a stylesheet of the Media Library.
	 *
	 * @param int    $id    Attachment id.
	 * @param string $label Title.
	 * @param int    $bytes Size.
	 * @return void
	 */
	public function file( $id, $label, $bytes = 1200 ) {
		$this->files[ $id ] = array(
			'id'      => $id,
			'label'   => $label,
			'url'     => 'http://example.test/wp-content/uploads/' . $label . '.css',
			'bytes'   => $bytes,
			'version' => '17000' . $id,
		);
	}

	/**
	 * Describes a stylesheet.
	 *
	 * @param int $id Attachment id.
	 * @return array|null
	 */
	public function describe( $id ) {
		return $this->files[ $id ] ?? null;
	}

	/**
	 * Lists the stylesheets.
	 *
	 * @return array<int, string>
	 */
	public function choices() {
		$choices = array();

		foreach ( $this->files as $id => $file ) {
			$choices[ $id ] = $file['label'];
		}

		return $choices;
	}

	/**
	 * Receives an upload: gives what the test queued.
	 *
	 * @param string $field Field.
	 * @return int
	 * @throws StylesheetException When the queued result is a code.
	 */
	public function upload( $field ) {
		$this->uploaded[] = $field;
		$result           = array_shift( $this->uploads );

		if ( is_string( $result ) ) {
			StylesheetException::refuse( $result, 'refused' );
		}

		return (int) $result;
	}
}
