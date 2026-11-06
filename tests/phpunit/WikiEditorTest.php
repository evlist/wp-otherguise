<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the editor of the wiki pages (slice 502): the panel loader, the classic box, the list.
 *
 * @package Otherguise
 */

use Otherguise\Wiki\Editor\SpacePanel;
use Otherguise\Wiki\Page\Lists;
use Otherguise\Wiki\Page\SpaceBox;
use Otherguise\Wiki\Space\Spaces;
use PHPUnit\Framework\TestCase;

/**
 * No database and no WordPress: stubs and doubles.
 *
 * @covers \Otherguise\Wiki\Editor\SpacePanel
 * @covers \Otherguise\Wiki\Page\SpaceBox
 * @covers \Otherguise\Wiki\Page\Lists
 */
class WikiEditorTest extends TestCase {

	/**
	 * Starts with two spaces and no recorded call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		update_option(
			Spaces::OPTION,
			array(
				array(
					'name' => 'Biography',
					'label' => 'Autobiography',
					'order' => 10,
				),
				array(
					'name' => 'Opinion',
					'order' => 20,
				),
			)
		);
	}

	/**
	 * The calls recorded for a WordPress function.
	 *
	 * @param string $name Function.
	 * @return array<int, array>
	 */
	private function calls( $name ) {
		return array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => $call[0] === $name ) );
	}

	/**
	 * The script and the spaces are loaded for a wiki page that the user may edit, and for nothing else.
	 *
	 * @return void
	 */
	public function test_the_panel_is_loaded_for_a_wiki_page(): void {
		$panel = static function ( $type, $id, $can ) {
			return new SpacePanel(
				static fn( $file ) => 'https://example.test/space/' . $file,
				static fn() => array(
					'id' => $id,
					'type' => $type,
				),
				static fn( $capability, $post ) => $can && 'edit_post' === $capability && $post === $id
			);
		};

		$panel( 'page', 12, true )->enqueue();
		$panel( 'wiki_page', 0, true )->enqueue();
		$panel( 'wiki_page', 12, false )->enqueue();

		$this->assertSame( array(), $this->calls( 'wp_enqueue_script' ) );

		$panel( 'wiki_page', 12, true )->enqueue();

		$scripts = $this->calls( 'wp_enqueue_script' );

		$this->assertSame( 'wiki-space', $scripts[0][1] );
		$this->assertSame( 'https://example.test/space/space.js', $scripts[0][2] );

		foreach ( array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-components', 'wp-i18n' ) as $dependency ) {
			$this->assertContains( $dependency, $scripts[0][3] );
		}

		$inline = $this->calls( 'wp_add_inline_script' );

		$this->assertSame(
			'window.wikiSpace = {"postType":"wiki_page","spaces":[{"name":"Biography","label":"Autobiography"},{"name":"Opinion","label":"Opinion"}]};',
			$inline[0][2]
		);
		$this->assertSame( 'before', $inline[0][3] );
	}

	/**
	 * Without a post the default reader finds nothing, and with the global post it finds its id and type.
	 *
	 * @return void
	 */
	public function test_the_default_reader_of_the_post(): void {
		unset( $GLOBALS['post'] );
		( new SpacePanel( static fn( $file ) => $file ) )->enqueue();
		$this->assertSame( array(), $this->calls( 'wp_enqueue_script' ) );

		$GLOBALS['post'] = new WP_Post( 31, 'wiki_page' );
		( new SpacePanel( static fn( $file ) => $file ) )->enqueue();
		$this->assertCount( 1, $this->calls( 'wp_enqueue_script' ), 'The global post is a wiki page and the stub lets the user edit it.' );

		unset( $GLOBALS['post'] );
	}

	/**
	 * The box is a back-compat meta box: shown on the classic screen, hidden by the block editor.
	 *
	 * @return void
	 */
	public function test_the_box_is_registered(): void {
		( new SpaceBox() )->register();

		$box = $this->calls( 'add_meta_box' )[0];

		$this->assertSame( 'wiki-space', $box[1] );
		$this->assertSame( 'wiki_page', $box[4] );
		$this->assertSame( 'side', $box[5] );
		$this->assertSame( array( '__back_compat_meta_box' => true ), $box[7] );
	}

	/**
	 * The box offers the spaces to a page that has none, and shows the space of a page that has one.
	 *
	 * @return void
	 */
	public function test_the_box(): void {
		ob_start();
		( new SpaceBox() )->render( new WP_Post( 5, 'wiki_page' ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( '<select name="wiki_space"', $html );
		$this->assertStringContainsString( '<option value="Biography">Autobiography</option>', $html );
		$this->assertStringContainsString( '<option value="Opinion">Opinion</option>', $html );

		update_post_meta( 5, 'wiki_space', 'Biography' );

		ob_start();
		( new SpaceBox() )->render( new WP_Post( 5, 'wiki_page' ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( '<strong>Biography</strong>', $html );
		$this->assertStringNotContainsString( '<select', $html );
	}

	/**
	 * The space is saved only with a valid nonce, by someone who may edit the page, and when it is a registered one.
	 *
	 * @return void
	 */
	public function test_saving_the_space(): void {
		$box = static function ( $posted, $valid = true, $can = true ) {
			return new SpaceBox( static fn() => $can, static fn() => $valid, static fn( $name ) => $posted[ $name ] ?? null );
		};
		$all = array(
			'wiki_space_nonce' => 'n',
			'wiki_space'       => 'biography',
		);

		$box( $all )->save( 7 );
		$this->assertSame( 'biography', get_post_meta( 7, 'wiki_space', true ), 'Saved as typed; the meta cleans it to the registered form.' );

		foreach ( array(
			array( $all, false, true ),
			array( $all, true, false ),
			array( array( 'wiki_space' => 'Opinion' ) ),
			array( array( 'wiki_space_nonce' => 'n' ) ),
			array( array_merge( $all, array( 'wiki_space' => 'Nowhere' ) ) ),
		) as $index => $case ) {
			$box( ...$case )->save( 8 + $index );
			$this->assertSame( '', get_post_meta( 8 + $index, 'wiki_space', true ), 'Case ' . $index );
		}
	}

	/**
	 * The list shows the space and the name, and takes the quick edit away from the wiki pages only.
	 *
	 * @return void
	 */
	public function test_the_list(): void {
		$list = new Lists( static fn( $id ) => 5 === $id ? array( 'qualified' => 'Biography:Paris' ) : null );

		$this->assertSame(
			array( 'cb', 'title', 'wiki_space', 'wiki_name', 'date' ),
			array_keys(
				$list->columns(
					array(
						'cb' => '<input />',
						'date' => 'When',
					)
				)
			)
		);
		$this->assertSame( 'When', $list->columns( array( 'date' => 'When' ) )['date'] );

		update_post_meta( 5, 'wiki_space', 'Biography' );

		ob_start();
		$list->column( 'wiki_space', 5 );
		$list->column( 'wiki_name', 5 );
		$list->column( 'wiki_name', 6 );
		$list->column( 'other', 5 );
		$this->assertSame( 'BiographyBiography:Paris&mdash;', ob_get_clean() );

		$row = array(
			'edit' => 'Edit',
			'inline hide-if-no-js' => 'Quick Edit',
		);

		$this->assertSame( array( 'edit' => 'Edit' ), $list->row_actions( $row, new WP_Post( 5, 'wiki_page' ) ) );
		$this->assertSame( $row, $list->row_actions( $row, new WP_Post( 5, 'post' ) ) );
		$this->assertSame( 'x', $list->row_actions( 'x', new WP_Post( 5, 'wiki_page' ) ) );
	}
}
