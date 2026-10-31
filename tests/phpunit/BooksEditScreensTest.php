<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the lists and the edit screens of the printer profiles and of the book projects (slice 234).
 *
 * @package Otherguise
 */

use Otherguise\Books\Project\EditScreens;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress and no database: the titles, the relations, the meta and the boxes are doubles.
 *
 * @covers \Otherguise\Books\Project\EditScreens
 */
class BooksEditScreensTest extends TestCase {

	/**
	 * Starts clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
	}

	/**
	 * Cleans up.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		otherguise_test_reset();
	}

	/**
	 * Builds the screens.
	 *
	 * @param array $meta  The meta by id and key.
	 * @param array $boxes The boxes of a screen, as WordPress keeps them.
	 * @return EditScreens
	 */
	private function screens( array $meta = array(), array $boxes = array() ) {
		$links = new class() {
			/**
			 * Root section of a project.
			 *
			 * @param int $id Project.
			 * @return int
			 */
			public function root( $id ) {
				return 7 === $id ? 10 : 0;
			}

			/**
			 * Printer profile of a project.
			 *
			 * @param int $id Project.
			 * @return int
			 */
			public function profile( $id ) {
				return 7 === $id ? 20 : 0;
			}
		};

		return new EditScreens(
			static fn() => array( 10 => 'Livre 2025' ),
			static fn() => array( 20 => 'CoolLibri A5' ),
			$links,
			static fn( $id, $key ) => $meta[ $id ][ $key ] ?? '',
			static fn() => $boxes
		);
	}

	/**
	 * No quick edit and no bulk edit for the two types, and the actions of the other types are left alone.
	 *
	 * @return void
	 */
	public function test_no_quick_edit(): void {
		$screens = $this->screens();
		$actions = array(
			'edit'                 => '<a>Edit</a>',
			'inline hide-if-no-js' => '<button>Quick Edit</button>',
			'trash'                => '<a>Trash</a>',
		);

		foreach ( array( 'books_printer', 'books_project' ) as $type ) {
			$this->assertSame(
				array(
					'edit'  => '<a>Edit</a>',
					'trash' => '<a>Trash</a>',
				),
				$screens->row_actions( $actions, (object) array( 'post_type' => $type ) ),
				$type
			);
		}

		$this->assertSame( $actions, $screens->row_actions( $actions, (object) array( 'post_type' => 'post' ) ) );
		$this->assertSame( 'x', $screens->row_actions( 'x', (object) array( 'post_type' => 'books_printer' ) ) );
		$this->assertSame(
			array( 'trash' => 'Move to the trash' ),
			$screens->bulk_actions(
				array(
					'edit'  => 'Edit',
					'trash' => 'Move to the trash',
				)
			)
		);
		$this->assertSame( 5, $screens->bulk_actions( 5 ) );
	}

	/**
	 * The list keeps "All" and "Trash": no status to filter by.
	 *
	 * @return void
	 */
	public function test_the_views(): void {
		$views = array(
			'all'     => 'All (2)',
			'mine'    => 'Mine (1)',
			'publish' => 'Published (2)',
			'draft'   => 'Draft (0)',
			'trash'   => 'Trash (1)',
		);

		$this->assertSame(
			array(
				'all'   => 'All (2)',
				'trash' => 'Trash (1)',
			),
			$this->screens()->views( $views )
		);
		$this->assertSame( 'x', $this->screens()->views( 'x' ) );
	}

	/**
	 * The columns say what a profile and a project are, instead of the date.
	 *
	 * @return void
	 */
	public function test_the_columns(): void {
		$screens = $this->screens(
			array(
				5 => array(
					'_books_profile' => array(
						'width_mm'  => 148,
						'height_mm' => 210,
						'margin_mm' => 10,
						'gutter_mm' => 20,
						'folio'     => 'outside',
					),
				),
				6 => array(
					'_books_profile' => array(
						'width_mm'  => 210,
						'height_mm' => 297,
						'margin_mm' => 12.5,
						'folio'     => 'none',
					),
				),
				7 => array( '_books_project' => array( 'scaling' => 'zoom' ) ),
				8 => array(),
			)
		);
		$default = array(
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'date'  => 'Date',
			'other' => 'Another plugin',
		);

		$this->assertSame( array( 'cb', 'title', 'books_page', 'books_folio' ), array_keys( $screens->profile_columns( $default ) ), 'The date and the columns of the other plugins are gone.' );
		$this->assertSame( array( 'cb', 'title', 'books_root', 'books_profile', 'books_scaling' ), array_keys( $screens->project_columns( $default ) ) );
		$this->assertSame( '<input type="checkbox" />', $screens->profile_columns( 5 )['cb'], 'The check box is made when the columns are not an array.' );

		$cell = static function ( $callback, $column, $id ) {
			ob_start();
			$callback( $column, $id );

			return (string) ob_get_clean();
		};

		$this->assertSame( '148 × 210 mm, margin 10 mm, gutter 20 mm', $cell( array( $screens, 'profile_column' ), 'books_page', 5 ) );
		$this->assertSame( 'Bottom, on the outside', $cell( array( $screens, 'profile_column' ), 'books_folio', 5 ) );
		$this->assertSame( '210 × 297 mm, margin 12.5 mm', $cell( array( $screens, 'profile_column' ), 'books_page', 6 ), 'No gutter when it is the margin.' );
		$this->assertSame( 'None', $cell( array( $screens, 'profile_column' ), 'books_folio', 6 ) );
		$this->assertSame( '', $cell( array( $screens, 'profile_column' ), 'unknown', 5 ) );

		$this->assertSame( 'Livre 2025', $cell( array( $screens, 'project_column' ), 'books_root', 7 ) );
		$this->assertSame( 'CoolLibri A5', $cell( array( $screens, 'project_column' ), 'books_profile', 7 ) );
		$this->assertSame( 'Zoom', $cell( array( $screens, 'project_column' ), 'books_scaling', 7 ) );
		$this->assertSame( '—', $cell( array( $screens, 'project_column' ), 'books_root', 8 ), 'A project without a relation.' );
		$this->assertSame( '—', $cell( array( $screens, 'project_column' ), 'books_profile', 8 ) );
		$this->assertSame( 'Adapt', $cell( array( $screens, 'project_column' ), 'books_scaling', 8 ), 'Adapt by default.' );
	}

	/**
	 * On the edit screen the boxes of the other plugins and the box "Publish" are taken away, the box of the type is kept, and a box "Save" is
	 * added; the other types are left alone.
	 *
	 * @return void
	 */
	public function test_the_boxes(): void {
		$boxes = array(
			'normal' => array(
				'high' => array(
					'books-profile' => array( 'id' => 'books-profile' ),
					'osm-box'       => array( 'id' => 'osm-box' ),
				),
				'core' => array( 'slugdiv' => array( 'id' => 'slugdiv' ) ),
			),
			'side'   => array(
				'core' => array(
					'submitdiv' => array( 'id' => 'submitdiv' ),
					'kaya-qr'   => array( 'id' => 'kaya-qr' ),
				),
			),
		);

		$this->screens( array(), $boxes )->boxes( (object) array( 'post_type' => 'books_printer' ) );

		$removed = array_map(
			static fn( $call ) => array( $call[1], $call[2], $call[3] ),
			array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'remove_meta_box' === $call[0] ) )
		);

		$this->assertSame(
			array(
				array( 'osm-box', 'books_printer', 'normal' ),
				array( 'slugdiv', 'books_printer', 'normal' ),
				array( 'submitdiv', 'books_printer', 'side' ),
				array( 'kaya-qr', 'books_printer', 'side' ),
			),
			$removed,
			'Everything but the box of the type.'
		);

		$added = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'add_meta_box' === $call[0] ) );

		$this->assertCount( 1, $added );
		$this->assertSame( 'books-save', $added[0][1] );
		$this->assertSame( array( 'books_printer', 'side', 'high' ), array_slice( $added[0], 4, 3 ) );

		otherguise_test_reset();
		$this->screens( array(), $boxes )->boxes( (object) array( 'post_type' => 'post' ) );
		$this->screens( array(), $boxes )->boxes( 'not a post' );

		$this->assertSame( array(), $GLOBALS['otherguise_test_calls'], 'A post of another type is left alone.' );
	}

	/**
	 * The box "Save": a button, and the link to the trash for what exists already.
	 *
	 * @return void
	 */
	public function test_the_box_save(): void {
		$screens = $this->screens();

		ob_start();
		$screens->render_save(
			(object) array(
				'ID'          => 7,
				'post_status' => 'publish',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="save"', $html );
		$this->assertStringContainsString( 'value="Save"', $html );
		$this->assertStringContainsString( 'Move to the trash', $html );

		ob_start();
		$screens->render_save(
			(object) array(
				'ID'          => 9,
				'post_status' => 'auto-draft',
			)
		);

		$this->assertStringNotContainsString( 'Move to the trash', (string) ob_get_clean(), 'Nothing to trash before the first save.' );
	}

	/**
	 * After a save the message is the one of the type; the messages of the other types are kept.
	 *
	 * @return void
	 */
	public function test_the_messages(): void {
		$messages = $this->screens()->messages( array( 'post' => array( 1 => 'Post updated.' ) ) );

		$this->assertSame( 'Post updated.', $messages['post'][1], 'The other types are kept.' );
		$this->assertSame( 'Printer profile saved.', $messages['books_printer'][1] );
		$this->assertSame( 'Book project saved.', $messages['books_project'][6], 'Also when it is published.' );
		$this->assertSame( '', $messages['books_project'][0] );
		$this->assertCount( 2, $this->screens()->messages( 'not an array' ) );
	}

	/**
	 * What is saved is published; an auto-draft, the trash and the other types are left alone.
	 *
	 * @return void
	 */
	public function test_what_is_saved_is_published(): void {
		$screens = $this->screens();

		foreach ( array( 'books_printer', 'books_project' ) as $type ) {
			foreach ( array( 'draft', 'pending', 'future' ) as $status ) {
				$this->assertSame(
					'publish',
					$screens->publish(
						array(
							'post_type'   => $type,
							'post_status' => $status,
						)
					)['post_status'],
					$type . ' ' . $status
				);
			}

			foreach ( array( 'auto-draft', 'trash', 'publish' ) as $status ) {
				$data = array(
					'post_type'   => $type,
					'post_status' => $status,
				);

				$this->assertSame( $data, $screens->publish( $data ), $type . ' ' . $status );
			}
		}

		$post = array(
			'post_type'   => 'post',
			'post_status' => 'draft',
		);

		$this->assertSame( $post, $screens->publish( $post ), 'A post stays a draft.' );
		$this->assertSame( 'x', $screens->publish( 'x' ) );
	}
}
