<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the migration of the native attachments, on a real database.
 *
 * @package Otherguise
 */

use Otherguise\Media\Cli\MigrateCommand;
use Otherguise\Media\Link\Links;
use Otherguise\Media\Migration\AttachmentSource;
use Otherguise\Media\Migration\NativeLinksMigration;
use Otherguise\Media\Module as MediaModule;

require_once __DIR__ . '/support/class-otherguise-test-database-case.php';
require_once __DIR__ . '/support/class-otherguise-test-fixtures.php';
require_once __DIR__ . '/support/class-otherguise-test-modes-site.php';
require_once __DIR__ . '/support/class-otherguise-test-attachment-source.php';

/**
 * The migration and its command.
 *
 * @covers \Otherguise\Media\Migration\NativeLinksMigration
 * @covers \Otherguise\Media\Cli\MigrateCommand
 */
class MediaMigrationDbTest extends Otherguise_Test_Database_Case {

	/**
	 * Modules wired together.
	 *
	 * @var Otherguise_Test_Modes_Site
	 */
	private $site;

	/**
	 * Links.
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * Source.
	 *
	 * @var Otherguise_Test_Attachment_Source
	 */
	private $source;

	/**
	 * Migration.
	 *
	 * @var NativeLinksMigration
	 */
	private $migration;

	/**
	 * Builds a site with two posts, a post that is missing and four attachments.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		otherguise_test_wp_objects(
			array(
				new WP_Post( 12 ),
				new WP_Post( 13 ),
				new WP_Post( 80, 'attachment' ),
				new WP_Post( 81, 'attachment' ),
				new WP_Post( 82, 'attachment' ),
				new WP_Post( 83, 'attachment' ),
			)
		);

		$media        = new MediaModule();
		$this->site   = new Otherguise_Test_Modes_Site(
			$this->wpdb,
			array(),
			array(
				static function ( $registry ) use ( $media ) {
					$media->register_predicate( $registry );
				},
			)
		);
		$this->links  = new Links( $this->site->statements );
		$this->source = new Otherguise_Test_Attachment_Source();

		$this->source->rows = array(
			array(
				'id'      => 80,
				'parent'  => 12,
				'mime'    => 'image/jpeg',
				'flagged' => true,
			),
			array(
				'id'      => 81,
				'parent'  => 12,
				'mime'    => 'image/jpeg',
				'flagged' => false,
			),
			array(
				'id'      => 82,
				'parent'  => 13,
				'mime'    => 'application/gpx+xml',
				'flagged' => false,
			),
			array(
				'id'      => 83,
				'parent'  => 999,
				'mime'    => 'image/png',
				'flagged' => false,
			),
		);

		$this->migration = new NativeLinksMigration( $this->links, array( 'web', 'print' ), $this->source );
	}

	/**
	 * The old behaviour is reproduced: flagged and other files in every mode, other images without the print mode.
	 *
	 * @return void
	 */
	public function test_the_modes_reproduce_the_old_behaviour(): void {
		$report = $this->migration->run();

		$this->assertSame( 3, $report['created'] );
		$this->assertSame( array( 'web', 'print' ), $this->links->modes_of( $this->links->find( 12, 80 ) ) );
		$this->assertSame( array( 'web' ), $this->links->modes_of( $this->links->find( 12, 81 ) ) );
		$this->assertSame( array( 'web', 'print' ), $this->links->modes_of( $this->links->find( 13, 82 ) ) );
		$this->assertSame(
			array(
				'web,print' => 2,
				'web' => 1,
			),
			$report['by_modes']
		);
	}

	/**
	 * A parent that does not exist is reported and nothing is stored for it.
	 *
	 * @return void
	 */
	public function test_a_missing_parent_is_skipped(): void {
		$report = $this->migration->run();

		$this->assertCount( 1, $report['skipped'] );
		$this->assertStringContainsString( 'attachment 83, post 999', $report['skipped'][0] );
		$this->assertNull( $this->links->find( 999, 83 ) );
	}

	/**
	 * Running again changes nothing, and an existing link keeps its modes.
	 *
	 * @return void
	 */
	public function test_it_is_idempotent_and_leaves_existing_links_alone(): void {
		$existing = $this->links->link( 12, 81 );
		$this->links->set_modes( $existing, array( 'print' ) );

		$first = $this->migration->run();
		$total = count( $this->site->statements->match() );
		$again = $this->migration->run();

		$this->assertSame( 2, $first['created'] );
		$this->assertSame( 1, $first['existing'] );
		$this->assertSame( 0, $again['created'] );
		$this->assertSame( 3, $again['existing'] );
		$this->assertSame( $total, count( $this->site->statements->match() ) );
		$this->assertSame( array( 'print' ), $this->links->modes_of( $this->links->find( 12, 81 ) ) );
	}

	/**
	 * A dry run writes nothing but reports the same counts.
	 *
	 * @return void
	 */
	public function test_a_dry_run_writes_nothing(): void {
		$lines  = array();
		$report = $this->migration->run(
			array( 'dry_run' => true ),
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			}
		);

		$this->assertSame( 3, $report['created'] );
		$this->assertCount( 1, $report['skipped'] );
		$this->assertSame( array(), $this->site->statements->match() );
		$this->assertSame( 'attachment 80, post 12: would be linked in web,print', $lines[0] );
	}

	/**
	 * The batches cover every attachment, and the limit stops the run.
	 *
	 * @return void
	 */
	public function test_batches_and_limit(): void {
		$report = $this->migration->run( array( 'batch_size' => 1 ) );

		$this->assertSame( 3, $report['created'] );
		$this->assertCount( 1, $report['skipped'] );

		$this->setUp();
		$limited = $this->migration->run(
			array(
				'limit' => 2,
				'batch_size' => 1,
			)
		);

		$this->assertSame( 2, $limited['created'] );
		$this->assertNull( $this->links->find( 13, 82 ) );
	}

	/**
	 * Without the print mode every attachment gets every mode, and the report says so.
	 *
	 * @return void
	 */
	public function test_without_the_print_mode_everything_gets_every_mode(): void {
		$report = $this->migration->run( array( 'print_mode' => 'book' ) );

		$this->assertTrue( $report['restrict_mode_missing'] );
		$this->assertSame( array( 'web', 'print' ), $this->links->modes_of( $this->links->find( 12, 81 ) ) );
		$this->assertFalse( $this->migration->run( array( 'print_mode' => 'print' ) )['restrict_mode_missing'] );
	}

	/**
	 * The mode of the flag can be another one.
	 *
	 * @return void
	 */
	public function test_the_print_mode_is_an_option(): void {
		$this->migration->run( array( 'print_mode' => 'web' ) );

		$this->assertSame( array( 'print' ), $this->links->modes_of( $this->links->find( 12, 81 ) ) );
		$this->assertSame( array( 'web', 'print' ), $this->links->modes_of( $this->links->find( 12, 80 ) ) );
	}

	/**
	 * The criterion is a meta key and, optionally, a value: they reach the source, with the old flag as the default.
	 *
	 * @return void
	 */
	public function test_the_criterion_reaches_the_source(): void {
		$this->migration->run();
		$this->assertSame( array( array( 'wpdfh.print', null ) ), array_unique( $this->source->criteria, SORT_REGULAR ) );

		$this->source->criteria = array();
		$this->migration->run(
			array(
				'meta_key' => 'book_ok',
				'meta_value' => 'yes',
			)
		);
		$this->assertSame( array( array( 'book_ok', 'yes' ) ), array_unique( $this->source->criteria, SORT_REGULAR ) );
	}

	/**
	 * `restrict_mode` is the new name of `print_mode`, and wins over it.
	 *
	 * @return void
	 */
	public function test_restrict_mode_is_the_new_name_of_print_mode(): void {
		$this->migration->run(
			array(
				'restrict_mode' => 'web',
				'print_mode' => 'print',
			)
		);

		$this->assertSame( array( 'print' ), $this->links->modes_of( $this->links->find( 12, 81 ) ) );
	}

	/**
	 * The criterion applies to one MIME family; the other files get every mode.
	 *
	 * @return void
	 */
	public function test_the_criterion_applies_to_a_mime_family(): void {
		$this->migration->run( array( 'mime' => 'application' ) );

		$this->assertSame( array( 'web', 'print' ), $this->links->modes_of( $this->links->find( 12, 81 ) ), 'An image: every mode.' );
		$this->assertSame( array( 'web', 'print' ), $this->links->modes_of( $this->links->find( 12, 80 ) ) );
	}

	/**
	 * `--mime=all` applies the criterion to every type of media, and a list of families to those families.
	 *
	 * @return void
	 */
	public function test_the_criterion_applies_to_every_type_of_media(): void {
		$this->migration->run( array( 'mime' => 'all' ) );

		$this->assertSame( array( 'web' ), $this->links->modes_of( $this->links->find( 13, 82 ) ), 'The GPX file does not satisfy the criterion: no print.' );
		$this->assertSame( array( 'web', 'print' ), $this->links->modes_of( $this->links->find( 12, 80 ) ), 'The flagged image keeps every mode.' );
		$this->assertTrue( NativeLinksMigration::in_families( 'video/mp4', '*' ) );
		$this->assertTrue( NativeLinksMigration::in_families( 'video/mp4', 'image, VIDEO' ) );
		$this->assertTrue( NativeLinksMigration::in_families( 'application/gpx+xml', 'image,application' ) );
		$this->assertFalse( NativeLinksMigration::in_families( 'application/pdf', 'image,video' ) );
		$this->assertFalse( NativeLinksMigration::in_families( 'imagery/x', 'image' ), 'A family is a whole word before the slash.' );
		$this->assertFalse( NativeLinksMigration::in_families( 'image/png', '' ) );
	}

	/**
	 * A list of posts limits the attachments to those of these posts.
	 *
	 * @return void
	 */
	public function test_a_list_of_posts_limits_the_migration(): void {
		$report = $this->migration->run( array( 'post_ids' => array( 12 ) ) );

		$this->assertSame( 2, $report['created'] );
		$this->assertNotNull( $this->links->find( 12, 80 ) );
		$this->assertNotNull( $this->links->find( 12, 81 ) );
		$this->assertNull( $this->links->find( 13, 82 ) );
		$this->assertSame( array( array( 12 ) ), array_unique( $this->source->posts, SORT_REGULAR ) );

		$this->source->posts = array();
		$this->migration->run();
		$this->assertSame( array( array() ), array_unique( $this->source->posts, SORT_REGULAR ), 'Every post when no list is given.' );
	}

	/**
	 * A value of a meta satisfies the criterion when it is not empty, or equal to the value asked.
	 *
	 * @return void
	 */
	public function test_a_meta_satisfies_the_criterion(): void {
		$this->assertTrue( AttachmentSource::matches( 'always' ) );
		$this->assertTrue( AttachmentSource::matches( '1' ) );
		$this->assertFalse( AttachmentSource::matches( '' ) );
		$this->assertTrue( AttachmentSource::matches( '0' ), 'Not empty as text: "0" counts, as it did.' );
		$this->assertTrue( AttachmentSource::matches( array( 'a' ) ) );
		$this->assertFalse( AttachmentSource::matches( array() ) );
		$this->assertTrue( AttachmentSource::matches( 'yes', 'yes' ) );
		$this->assertFalse( AttachmentSource::matches( 'no', 'yes' ) );
		$this->assertFalse( AttachmentSource::matches( '', 'yes' ) );
		$this->assertTrue( AttachmentSource::matches( 1, '1' ), 'Compared as text.' );
		$this->assertFalse( AttachmentSource::matches( array( 'yes' ), 'yes' ) );
	}

	/**
	 * The command prints the report, honours the options and stops when the modules are missing.
	 *
	 * @return void
	 */
	public function test_the_command(): void {
		$lines   = array();
		$errors  = array();
		$command = new MigrateCommand(
			fn() => $this->migration,
			static function ( $line ) use ( &$lines ) {
				$lines[] = $line;
			},
			static function ( $message ) use ( &$errors ) {
				$errors[] = $message;
			}
		);

		$command( array(), array( 'dry-run' => true ) );

		$this->assertSame( array(), $this->site->statements->match() );
		$this->assertContains( '  2 in web,print', $lines );
		$this->assertContains( '  1 in web', $lines );
		$this->assertContains( 'Dry run, nothing written: 3 link(s) to create, 0 already linked, 1 skipped.', $lines );
		$this->assertCount( 1, preg_grep( '/^Skipped, attachment 83, post 999/', $lines ) );

		$lines = array();
		$command(
			array(),
			array(
				'verbose' => true,
				'limit' => '1',
			)
		);

		$this->assertSame( 'attachment 80, post 12: linked in web,print', $lines[0] );
		$this->assertContains( 'Done: 1 link(s) created, 0 already linked, 0 skipped.', $lines );

		$lines = array();
		$this->source->criteria = array();
		$command(
			array(),
			array(
				'dry-run' => true,
				'meta-key' => 'book_ok',
				'meta-value' => 'yes',
				'restrict-mode' => 'book',
				'mime' => 'image',
			)
		);
		$this->assertSame( array( array( 'book_ok', 'yes' ) ), array_unique( $this->source->criteria, SORT_REGULAR ) );
		$this->assertContains( 'Warning: the mode "book" is not registered, so every attachment gets every registered mode.', $lines );

		foreach ( array( '12,x', '0', ',' ) as $bad ) {
			$errors = array();
			$command( array(), array( 'post-ids' => $bad ) );
			$this->assertCount( 1, $errors, 'A bad list of posts is refused: ' . $bad );
		}

		$lines                  = array();
		$this->source->posts    = array();
		$this->source->criteria = array();
		$command(
			array(),
			array(
				'dry-run' => true,
				'post-ids' => '12, 13',
				'mime' => 'all',
			)
		);
		$this->assertSame( array( array( 12, 13 ) ), array_unique( $this->source->posts, SORT_REGULAR ) );
		$this->assertContains( 'Dry run, nothing written: 2 link(s) to create, 1 already linked, 0 skipped.', $lines );

		$missing = new MigrateCommand(
			fn() => null,
			null,
			static function ( $message ) use ( &$errors ) {
				$errors[] = $message;
			}
		);
		$errors = array();
		$missing( array(), array() );

		$this->assertSame( array( 'The modules Triples, Modes and Media must be active.' ), $errors );
	}
}
