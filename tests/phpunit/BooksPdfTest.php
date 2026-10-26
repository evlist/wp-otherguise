<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the PDF conversion in the background: the files, the state, the jobs, the REST routes (slice 220).
 *
 * @package Otherguise
 */

use Otherguise\Books\Admin\SettingsScreen;
use Otherguise\Books\Module;
use Otherguise\Books\Pdf\JobState;
use Otherguise\Books\Pdf\PdfJobs;
use Otherguise\Books\Pdf\PdfPages;
use Otherguise\Books\Pdf\PdfStore;
use Otherguise\Books\Pdf\WordPressPosts;
use Otherguise\Books\Preview\EditorPreview;
use Otherguise\Books\Render\Renderer;
use Otherguise\Books\Rest\PdfRoutes;
use Otherguise\Books\Settings\PageFormat;
use Otherguise\Books\Settings\RendererSettings;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress, no network, no database: the files are in a temporary directory, the renderer and the posts are doubles.
 *
 * @covers \Otherguise\Books\Pdf\PdfStore
 * @covers \Otherguise\Books\Pdf\JobState
 * @covers \Otherguise\Books\Pdf\PdfJobs
 * @covers \Otherguise\Books\Pdf\WordPressPosts
 * @covers \Otherguise\Books\Rest\PdfRoutes
 * @covers \Otherguise\Books\Module
 * @covers \Otherguise\Books\Admin\SettingsScreen
 * @covers \Otherguise\Books\Preview\EditorPreview
 */
class BooksPdfTest extends TestCase {

	/**
	 * Directory of the files of a test.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Time of the test, in seconds.
	 *
	 * @var int
	 */
	private $time = 1000000;

	/**
	 * Values of the third argument received by the post reader of `draft_jobs()`: whether the capability of the user is asked.
	 *
	 * @var bool[]
	 */
	private $as_user = array();

	/**
	 * What the sender of the download route was given: the file and the range.
	 *
	 * @var array
	 */
	private $sent = array();

	/**
	 * A directory and no option.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		otherguise_test_reset();
		$this->directory = sys_get_temp_dir() . '/books-pdf-test-' . bin2hex( random_bytes( 4 ) );
		$this->time      = 1000000;
	}

	/**
	 * Removes the directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( (array) glob( $this->directory . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}

		otherguise_test_reset();
	}

	/**
	 * A key.
	 *
	 * @param string $seed Seed.
	 * @return string
	 */
	private function key( $seed = 'a' ) {
		return md5( $seed );
	}

	/**
	 * Builds the store, with the clock of the test.
	 *
	 * @return PdfStore
	 */
	private function store() {
		return new PdfStore( $this->directory . '/', fn() => $this->time, null, null, null, static fn( $from, $to ) => rename( $from, $to ) );
	}

	/**
	 * The files: only valid keys, a protected directory, read, cleanup by age, removal of everything.
	 *
	 * @return void
	 */
	public function test_the_store(): void {
		$store = $this->store();
		$key   = $this->key();

		$this->assertTrue( PdfStore::is_key( $key ) );

		foreach ( array( '', 'abc', str_repeat( 'g', 32 ), '../' . str_repeat( 'a', 29 ), null, 12 ) as $bad ) {
			$this->assertFalse( PdfStore::is_key( $bad ), 'Not a key: ' . var_export( $bad, true ) );
			$this->assertFalse( $store->has( $bad ) );
			$this->assertSame( '', $store->partial_path( $bad ) );
			$this->assertSame( 0, $store->size( $bad ) );
		}

		$this->assertFalse( $store->has( $key ) );
		$this->assertSame( 0, $store->size( $key ) );
		$this->assertFalse( $store->write( 'bad', '%PDF-1.7' ) );
		$this->assertFalse( $store->write( $key, '' ), 'Nothing is written for an empty file.' );
		$this->assertTrue( $store->write( $key, '%PDF-1.7 bytes' ) );
		$this->assertTrue( $store->has( $key ) );
		$this->assertSame( '%PDF-1.7 bytes', file_get_contents( $store->path( $key ) ) );
		$this->assertSame( 14, $store->size( $key ) );
		$this->assertSame( $this->directory . '/' . $key . '.pdf', $store->path( $key ) );
		$this->assertFileExists( $this->directory . '/index.php' );
		$this->assertStringContainsString( 'Deny from all', (string) file_get_contents( $this->directory . '/.htaccess' ) );

		$old = $this->key( 'old' );
		$store->write( $old, '%PDF-old' );
		touch( $store->path( $old ), $this->time - 10 * 86400 );
		touch( $store->path( $key ), $this->time - 2 * 86400 );

		$this->assertSame( 0, $store->cleanup( 30 ) );
		$this->assertSame( 1, $store->cleanup( 7 ), 'The file older than 7 days goes.' );
		$this->assertFalse( $store->has( $old ) );
		$this->assertTrue( $store->has( $key ) );
		$this->assertSame( 1, $store->cleanup( 0 ), 'At least one day.' );

		$store->write( $key, '%PDF-again' );
		$store->remove_all();
		$this->assertDirectoryDoesNotExist( $this->directory );

		$store->remove_all();
		$this->assertDirectoryDoesNotExist( $this->directory, 'Removing what is not there is harmless.' );
	}

	/**
	 * The directory is removed with the WordPress file system API; when that API is not usable, the directory stays.
	 *
	 * @return void
	 */
	public function test_the_directory_is_removed_through_the_file_system_api(): void {
		mkdir( $this->directory );
		$this->assertTrue( PdfStore::remove_directory( $this->directory ) );
		$this->assertDirectoryDoesNotExist( $this->directory );

		$store = new PdfStore( $this->directory, fn() => $this->time );
		$store->write( $this->key(), '%PDF-1.7' );
		$store->remove_all();
		$this->assertDirectoryDoesNotExist( $this->directory, 'The default removal goes through the API.' );
	}

	/**
	 * A directory that cannot be created, and a store that keeps what is foreign in the directory.
	 *
	 * @return void
	 */
	public function test_the_store_failures(): void {
		$store = new PdfStore( $this->directory, fn() => $this->time, 'wp_delete_file', static fn() => false );

		$this->assertFalse( $store->write( $this->key(), '%PDF-1.7' ) );

		mkdir( $this->directory );
		file_put_contents( $this->directory . '/mine.txt', 'x' );
		$this->store()->remove_all();
		$this->assertDirectoryExists( $this->directory, 'A directory with a foreign file stays.' );
		unlink( $this->directory . '/mine.txt' );
	}

	/**
	 * The state is kept per post and mode, and forgotten on demand.
	 *
	 * @return void
	 */
	public function test_the_state(): void {
		$state = new JobState();

		$this->assertSame( array(), $state->get( 5, 'print' ) );

		$state->set( 5, 'print', array( 'status' => 'queued' ) );
		$state->set( 5, 'web', array( 'status' => 'done' ) );
		$state->set( 6, 'print', array( 'status' => 'failed' ) );

		$this->assertSame( array( 'status' => 'queued' ), $state->get( 5, 'print' ) );
		$this->assertSame( array( 'status' => 'done' ), $state->get( 5, 'web' ) );
		$this->assertSame( array( 'status' => 'failed' ), $state->get( 6, 'print' ) );

		$state->clear( 5, 'print' );
		$this->assertSame( array(), $state->get( 5, 'print' ) );
		$this->assertSame( array( 'status' => 'done' ), $state->get( 5, 'WEB!' ), 'A mode name is cleaned for the name of the transient.' );

		$GLOBALS['otherguise_test_transients']['books_pdf_7_print'] = 'garbage';
		$this->assertSame( array(), $state->get( 7, 'print' ) );
	}

	/**
	 * What the conversion asks of a post: its status, last change, slug, permalink; the address of a page in a mode.
	 *
	 * @return void
	 */
	public function test_posts(): void {
		$post = (object) array(
			'ID'                => 12,
			'post_status'       => 'publish',
			'post_modified_gmt' => '2026-10-20 10:00:00',
			'post_name'         => 'repos-a-fonti',
		);
		$ids  = array();
		$read = new WordPressPosts(
			static function ( $id ) use ( $post, &$ids ) {
				$ids[] = $id;

				return 12 === $id ? $post : null;
			},
			static fn( $found ) => 'https://x.test/repos/'
		);

		$this->assertSame(
			array(
				'status'   => 'publish',
				'modified' => '2026-10-20 10:00:00',
				'slug'      => 'repos-a-fonti',
				'url'       => 'https://x.test/repos/',
				'preview'   => false,
				'available' => true,
				'allowed'   => true,
			),
			$read->info( '12' )
		);
		$this->assertSame( array( 12 ), $ids );
		$this->assertNull( $read->info( 99 ) );

		$this->assertSame( 'https://x.test/repos/?mode=print', $read->address( 'https://x.test/repos/', 'print' ) );
		$this->assertSame( 'https://x.test/?p=12&mode=print', $read->address( 'https://x.test/?p=12', 'print' ) );
		$this->assertSame( 'https://x.test/?mode=a%26b', $read->default_address( 'https://x.test/', 'a&b' ) );

		$this->assertSame( 'U:print', ( new WordPressPosts( null, null, static fn( $url, $mode ) => 'U:' . $mode ) )->address( 'x', 'print' ) );

		$odd = new WordPressPosts( static fn() => (object) array( 'ID' => 3 ), static fn() => false );
		$this->assertSame(
			array(
				'status'    => '',
				'modified'  => '',
				'slug'      => '',
				'url'       => '',
				'preview'   => true,
				'available' => false,
				'allowed'   => false,
			),
			$odd->info( 3 )
		);
	}

	/**
	 * A renderer double that records what it is asked and answers from a queue.
	 *
	 * @param array $answers Answers of `convert_url`.
	 * @param int   $pages   Number of pages, or null.
	 * @param array $calls   Calls recorded.
	 * @return Renderer
	 */
	private function renderer( array $answers, $pages, array &$calls ) {
		return new class( $answers, $pages, $calls ) implements Renderer {
			/**
			 * Answers.
			 *
			 * @var array
			 */
			private $answers;

			/**
			 * Pages.
			 *
			 * @var int|null
			 */
			private $pages;

			/**
			 * Calls.
			 *
			 * @var array
			 */
			private $calls;

			/**
			 * Builds the double.
			 *
			 * @param array    $answers Answers.
			 * @param int|null $pages   Pages.
			 * @param array    $calls   Calls.
			 */
			public function __construct( array $answers, $pages, array &$calls ) {
				$this->answers = $answers;
				$this->pages   = $pages;
				$this->calls   = &$calls;
			}

			/**
			 * Converts.
			 *
			 * @param string $url     Address.
			 * @param array  $format  Format.
			 * @param array  $options Options.
			 * @return string|WP_Error
			 */
			public function convert_url( $url, array $format, array $options = array() ) {
				$this->calls[] = array( $url, $format, $options );

				return array_shift( $this->answers );
			}

			/**
			 * Converts to a file: the answer is written there, as the real client does with the answer of the server.
			 *
			 * @param string $url     Address.
			 * @param array  $format  Format.
			 * @param array  $options Options.
			 * @param string $path    File.
			 * @return true|WP_Error
			 */
			public function convert_url_to_file( $url, array $format, array $options, $path ) {
				$answer = $this->convert_url( $url, $format, $options );

				if ( $answer instanceof WP_Error ) {
					return $answer;
				}

				file_put_contents( $path, $answer );

				return true;
			}

			/**
			 * Pages.
			 *
			 * @param string $pdf Bytes.
			 * @return int|null
			 */
			public function page_count( $pdf ) {
				return $this->pages;
			}

			/**
			 * Health.
			 *
			 * @return array
			 */
			public function health() {
				return array(
					'up'      => true,
					'message' => '',
				);
			}
		};
	}

	/**
	 * Builds the jobs with doubles. The post 12 is published, 13 is a draft, others do not exist.
	 *
	 * @param array $calls     Calls of the renderer.
	 * @param array $scheduled Events scheduled.
	 * @param array $answers   Answers of the renderer.
	 * @param int   $pages     Pages.
	 * @param array $info      Overrides of the info of the post 12.
	 * @return PdfJobs
	 */
	private function jobs( array &$calls, array &$scheduled, array $answers = array(), $pages = 2, array $info = array(), $rules = null ) {
		$renderer = $this->renderer( $answers, $pages, $calls );
		$posts    = array(
			12 => $info + array(
				'status'   => 'publish',
				'modified' => '2026-10-20 10:00:00',
				'slug'     => 'Repos à Fonti',
				'url'      => 'https://x.test/repos/',
			),
			13 => array(
				'status'   => 'draft',
				'modified' => 'x',
				'slug'     => 'brouillon',
				'url'      => 'https://x.test/?p=13',
			),
		);

		return new PdfJobs(
			new RendererSettings(),
			new PageFormat(),
			$this->store(),
			new JobState(),
			static fn( $id ) => $posts[ $id ] ?? null,
			static fn( $slug ) => in_array( $slug, array( 'web', 'print' ), true ),
			static fn( $url, $mode ) => $url . '?mode=' . $mode,
			static fn( $address ) => '' === $address ? null : $renderer,
			static function ( $post_id, $mode, $key ) use ( &$scheduled ) {
				$scheduled[] = array( $post_id, $mode, $key );
			},
			fn() => $this->time,
			null,
			$rules
		);
	}

	/**
	 * Configures the server.
	 *
	 * @param array $more Other settings.
	 * @return void
	 */
	private function configure( array $more = array() ) {
		update_option( RendererSettings::OPTION, array( 'gotenberg_url' => 'http://gotenberg:3000' ) + $more );
	}

	/**
	 * What cannot be asked: no server, an unknown mode, a post that does not exist, a draft.
	 *
	 * @return void
	 */
	public function test_requests_that_are_refused(): void {
		$calls     = array();
		$scheduled = array();
		$jobs      = $this->jobs( $calls, $scheduled );

		foreach ( array( 'request', 'status', 'download' ) as $method ) {
			$error = $jobs->$method( 12, 'print' );
			$this->assertSame( 'books_pdf_disabled', $error->get_error_code(), $method . ' without a server' );
			$this->assertSame( array( 'status' => 501 ), $error->get_error_data() );
		}

		$this->configure();

		$cases = array(
			array( 12, 'tv', 'books_pdf_unknown_mode', 400 ),
			array( 12, '', 'books_pdf_unknown_mode', 400 ),
			array( 99, 'print', 'books_pdf_not_found', 404 ),
			array( 13, 'print', 'books_pdf_not_published', 400 ),
		);

		foreach ( $cases as $case ) {
			foreach ( array( 'request', 'status', 'download' ) as $method ) {
				$error = $jobs->$method( $case[0], $case[1] );
				$this->assertInstanceOf( WP_Error::class, $error );
				$this->assertSame( $case[2], $error->get_error_code(), $method . ' ' . $case[2] );
				$this->assertSame( array( 'status' => $case[3] ), $error->get_error_data() );
			}
		}

		$this->assertSame( array(), $scheduled );
	}

	/**
	 * A request queues the conversion once; asking again while it waits does not queue it again.
	 *
	 * @return void
	 */
	public function test_a_request_queues_the_conversion_once(): void {
		$calls     = array();
		$scheduled = array();
		$jobs      = $this->jobs( $calls, $scheduled );
		$this->configure();

		$first = $jobs->request( 12, 'print' );

		$this->assertSame( 'queued', $first['status'] );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( 12, $scheduled[0][0] );
		$this->assertSame( 'print', $scheduled[0][1] );
		$this->assertTrue( PdfStore::is_key( $scheduled[0][2] ) );

		$this->time += 5;
		$again = $jobs->request( 12, 'print' );

		$this->assertSame( 'queued', $again['status'] );
		$this->assertCount( 1, $scheduled, 'Not queued twice.' );
		$this->assertSame( 'queued', $jobs->status( 12, 'print' )['status'] );
		$this->assertSame( 'none', $jobs->status( 12, 'web' )['status'], 'Another mode has no conversion.' );

		$this->time += PdfJobs::STALE + 1;
		$this->assertSame( 'failed', $jobs->status( 12, 'print' )['status'], 'A conversion that never ran is taken for lost.' );
		$this->assertStringContainsString( 'WP-Cron', $jobs->status( 12, 'print' )['message'] );

		$jobs->request( 12, 'print' );
		$this->assertCount( 2, $scheduled, 'A lost conversion is queued again.' );
	}

	/**
	 * The cron event runs the conversion: the page, the format and the options reach the renderer, the file is kept, the pages counted.
	 *
	 * @return void
	 */
	public function test_the_conversion_runs(): void {
		$calls     = array();
		$scheduled = array();
		$jobs      = $this->jobs( $calls, $scheduled, array( '%PDF-1.7 bytes' ), 2 );
		$this->configure(
			array(
				'wait_delay' => 2.5,
				'media'      => 'screen',
			)
		);

		$jobs->request( 12, 'print' );
		$jobs->run( ...$scheduled[0] );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'https://x.test/repos/?mode=print', $calls[0][0] );
		$this->assertSame( 148.0, $calls[0][1]['width_mm'] );
		$this->assertSame( 'screen', $calls[0][2]['media'] );
		$this->assertSame( 2.5, $calls[0][2]['wait_delay'] );
		$this->assertSame( 300, $calls[0][2]['timeout'] );

		$state = $jobs->status( 12, 'print' );
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 2, $state['pages'] );

		$file = $jobs->download( 12, 'print' );
		$this->assertSame( 'repos-fonti.pdf', $file['filename'], 'The name is made of the slug.' );
		$this->assertSame( '%PDF-1.7 bytes', file_get_contents( $file['path'] ) );
		$this->assertSame( 14, $file['size'] );
		$this->assertSame( 2, $file['pages'] );
		$this->assertSame( array(), glob( $this->directory . '/*.part' ), 'The file that was being written is gone.' );

		$again = $jobs->request( 12, 'print' );
		$this->assertSame( 'done', $again['status'], 'The same conversion is served from the files.' );
		$this->assertCount( 1, $scheduled, 'Nothing is queued.' );

		$jobs->run( ...$scheduled[0] );
		$this->assertCount( 1, $calls, 'A job that already ran does nothing.' );
	}

	/**
	 * A book project that covers the post gives its format, its CSS and its address to the conversion, and changes the key (slice 230).
	 *
	 * @return void
	 */
	public function test_the_rules_of_a_book_project(): void {
		$calls     = array();
		$scheduled = array();
		$rules     = array(
			'project' => 77,
			'format'  => array(
				'width_mm'  => 150.0,
				'height_mm' => 210.0,
				'margin_mm' => 12.0,
			),
			'css'     => '@page{size:150mm 210mm}',
		);
		$this->configure();

		$plain = $this->jobs( $calls, $scheduled, array( '%PDF-1.7 a' ) );
		$plain->request( 12, 'print' );
		$plain_key = $scheduled[0][2];
		$plain->run( ...$scheduled[0] );

		$this->assertSame( 148.0, $calls[0][1]['width_mm'] );
		$this->assertFalse( $calls[0][2]['prefer_css_page_size'] );
		$this->assertSame( 'https://x.test/repos/?mode=print', $calls[0][0] );

		$calls     = array();
		$scheduled = array();
		$ruled     = $this->jobs( $calls, $scheduled, array( '%PDF-1.7 b' ), 2, array(), static fn( $post_id ) => 12 === $post_id ? $rules : null );
		$ruled->request( 12, 'print' );

		$this->assertNotSame( $plain_key, $scheduled[0][2], 'The project is part of what the PDF depends on.' );
		$ruled->run( ...$scheduled[0] );

		$this->assertSame( 150.0, $calls[0][1]['width_mm'], 'The format of the profile.' );
		$this->assertTrue( $calls[0][2]['prefer_css_page_size'] );
		$this->assertStringContainsString( 'books_project=77', $calls[0][0], 'The page is asked for the project.' );

		$other = $rules;

		$other['css'] = '@page{size:150mm 210mm;margin:5mm}';
		$calls        = array();
		$scheduled    = array();
		$changed      = $this->jobs( $calls, $scheduled, array(), 2, array(), static fn( $post_id ) => $other );
		$changed->request( 12, 'print' );
		$this->assertNotSame( $plain_key, $scheduled[0][2] );
		$this->assertNotSame( $ruled->status( 12, 'print' )['key'] ?? '', $scheduled[0][2], 'A change of the CSS makes another key.' );
	}

	/**
	 * A new request with `force` makes a new conversion; a change of the post or of the format makes another key.
	 *
	 * @return void
	 */
	public function test_force_and_the_key(): void {
		$calls     = array();
		$scheduled = array();
		$jobs      = $this->jobs( $calls, $scheduled, array( '%PDF-1', '%PDF-2' ) );
		$this->configure();

		$jobs->request( 12, 'print' );
		$jobs->run( ...$scheduled[0] );

		$jobs->request( 12, 'print', true );
		$this->assertCount( 2, $scheduled, 'Forced: queued again although the file exists.' );
		$this->assertSame( $scheduled[0][2], $scheduled[1][2], 'The same key.' );
		$jobs->run( ...$scheduled[1] );
		$this->assertSame( '%PDF-2', file_get_contents( $jobs->download( 12, 'print' )['path'] ), 'The file was replaced.' );

		$other = $this->jobs( $calls, $scheduled, array(), 2, array( 'modified' => '2026-10-21 08:00:00' ) );
		$other->request( 12, 'print' );
		$this->assertNotSame( $scheduled[1][2], $scheduled[2][2], 'The post changed: another key.' );
		$this->assertSame( 'queued', $other->status( 12, 'print' )['status'] );

		update_option( PageFormat::OPTION, array( 'width_mm' => 210 ) );
		$jobs->request( 12, 'print' );
		$this->assertCount( 4, $scheduled, 'The format changed: a new conversion.' );
		$this->assertNotSame( $scheduled[1][2], $scheduled[3][2] );

		$this->assertSame( 'books_pdf_not_ready', $other->download( 12, 'print' )->get_error_code() );
		$this->assertSame( array( 'status' => 409 ), $other->download( 12, 'print' )->get_error_data() );
	}

	/**
	 * The failures of the conversion are kept with their message: the server, the file, the post, the configuration.
	 *
	 * @return void
	 */
	public function test_the_failures(): void {
		$calls     = array();
		$scheduled = array();
		$jobs      = $this->jobs( $calls, $scheduled, array( new WP_Error( 'books_gotenberg_http', 'The server answered with the status 503: deadline' ) ) );
		$this->configure();

		$jobs->request( 12, 'print' );
		$jobs->run( ...$scheduled[0] );

		$state = $jobs->status( 12, 'print' );
		$this->assertSame( 'failed', $state['status'] );
		$this->assertStringContainsString( '503', $state['message'] );

		$jobs->request( 12, 'print' );
		$this->assertCount( 2, $scheduled, 'A failed conversion is queued again on request.' );

		// The directory cannot be written (it does not exist and cannot be made: the failed job above created it, so it is removed).
		$this->store()->remove_all();

		$blocked = $this->jobs( $calls, $scheduled, array( '%PDF-1' ) );
		$ref     = new ReflectionProperty( PdfJobs::class, 'store' );
		$ref->setAccessible( true );
		$ref->setValue( $blocked, new PdfStore( $this->directory, fn() => $this->time, 'wp_delete_file', static fn() => false ) );
		$blocked->request( 12, 'web' );
		$blocked->run( ...$scheduled[2] );
		$this->assertStringContainsString( 'could not be written', $blocked->status( 12, 'web' )['message'] );

		// The post is not published any more when the job runs: the double of the posts changes its answer.
		$draft = $this->jobs( $calls, $scheduled, array( '%PDF-1' ) );
		$draft->request( 12, 'print' );
		$state = $this->private_state( $draft );
		$this->assertNotNull( $state );
	}

	/**
	 * Reads the state class of the jobs, to prove that it is the shared one.
	 *
	 * @param PdfJobs $jobs Jobs.
	 * @return JobState|null
	 */
	private function private_state( PdfJobs $jobs ) {
		$property = new ReflectionProperty( PdfJobs::class, 'state' );
		$property->setAccessible( true );
		$state = $property->getValue( $jobs );

		return $state instanceof JobState ? $state : null;
	}

	/**
	 * A job whose server was removed from the settings after it was queued fails with a message, and a job that is not the queued one does
	 * nothing.
	 *
	 * @return void
	 */
	public function test_a_job_that_cannot_run(): void {
		$calls     = array();
		$scheduled = array();
		$jobs      = $this->jobs( $calls, $scheduled, array( '%PDF-1' ) );
		$this->configure();

		$jobs->request( 12, 'print' );
		$jobs->run( 12, 'print', $this->key( 'other' ) );
		$this->assertSame( 'queued', $jobs->status( 12, 'print' )['status'], 'Another key: nothing happens.' );
		$jobs->run( 77, 'print', $scheduled[0][2] );
		$this->assertCount( 0, $calls );

		delete_option( RendererSettings::OPTION );
		$jobs->run( ...$scheduled[0] );
		$this->assertCount( 0, $calls, 'No server: nothing is converted.' );
		$state = $this->private_state( $jobs )->get( 12, 'print' );
		$this->assertSame( 'failed', $state['status'] );
		$this->assertStringContainsString( 'No rendering server', $state['message'] );
	}

	/**
	 * A post that is no longer published when its job runs.
	 *
	 * @return void
	 */
	public function test_a_post_unpublished_before_its_job_runs(): void {
		$calls     = array();
		$scheduled = array();
		$status    = 'publish';
		$jobs      = new PdfJobs(
			new RendererSettings(),
			new PageFormat(),
			$this->store(),
			new JobState(),
			static function () use ( &$status ) {
				return array(
					'status'   => $status,
					'modified' => 'm',
					'slug'     => 's',
					'url'      => 'https://x.test/',
				);
			},
			static fn() => true,
			static fn( $url ) => $url,
			function () use ( &$calls ) {
				return $this->renderer( array(), null, $calls );
			},
			static function ( $post_id, $mode, $key ) use ( &$scheduled ) {
				$scheduled[] = array( $post_id, $mode, $key );
			},
			fn() => $this->time
		);
		$this->configure();

		$jobs->request( 5, 'print' );
		$status = 'trash';
		$jobs->run( ...$scheduled[0] );

		$this->assertSame( array(), $calls );
		$this->assertStringContainsString( 'not published', $this->private_state( $jobs )->get( 5, 'print' )['message'] );
	}

	/**
	 * A post that is not published is read through its private link, made only when asked (slice 403).
	 *
	 * @return void
	 */
	public function test_a_post_that_is_not_published_is_read_through_its_link(): void {
		$post   = (object) array(
			'ID'                => 13,
			'post_status'       => 'draft',
			'post_modified_gmt' => 'm',
			'post_name'         => 'brouillon',
		);
		$made   = array();
		$linked = true;
		$read   = new WordPressPosts(
			static fn() => $post,
			static fn() => 'https://x.test/?p=13',
			null,
			static function ( $found, $create ) use ( &$made ) {
				$made[] = $create;

				return $create ? 'https://x.test/?shared=T' : '';
			},
			static function () use ( &$linked ) {
				return $linked;
			},
			static fn() => true
		);

		$without = $read->info( 13 );

		$this->assertSame(
			array(
				'url' => '',
				'preview' => true,
				'available' => true,
			),
			array_intersect_key( $without, array_flip( array( 'preview', 'available', 'url' ) ) ),
			'No link yet: the polling does not make one.'
		);
		$this->assertSame( 'https://x.test/?shared=T', $read->info( 13, true )['url'] );
		$this->assertSame( array( false, true ), $made );
		$this->assertSame( 'https://x.test/?shared=T&mode=print', $read->address( 'https://x.test/?shared=T', 'print' ) );

		$linked = false;
		$made   = array();

		$this->assertSame(
			array(
				'url' => '',
				'available' => false,
			),
			array_intersect_key( $read->info( 13, true ), array_flip( array( 'available', 'url' ) ) )
		);
		$this->assertSame( array(), $made, 'No link is asked for when none can exist.' );
	}

	/**
	 * Builds jobs whose post is a draft with a private link. `$create` collects what was asked of the link.
	 *
	 * @param array $info      Info of the post 13.
	 * @param array $calls     Calls of the renderer.
	 * @param array $scheduled Events scheduled.
	 * @param array $creates   Values of `$create` received.
	 * @param array $answers   Answers of the renderer.
	 * @return PdfJobs
	 */
	private function draft_jobs( array $info, array &$calls, array &$scheduled, array &$creates, array $answers = array() ) {
		$renderer = $this->renderer( $answers, 3, $calls );

		return new PdfJobs(
			new RendererSettings(),
			new PageFormat(),
			$this->store(),
			new JobState(),
			function ( $id, $create = false, $as_user = true ) use ( $info, &$creates ) {
				$creates[] = $create;
				$this->as_user[] = $as_user;

				return $create || '' === ( $info['url'] ?? '' ) ? $info : array_merge( $info, array( 'url' => '' ) );
			},
			static fn( $slug ) => 'print' === $slug,
			static fn( $url, $mode ) => $url . '&mode=' . $mode,
			static fn( $address ) => $renderer,
			static function ( $post_id, $mode, $key ) use ( &$scheduled ) {
				$scheduled[] = array( $post_id, $mode, $key );
			},
			fn() => $this->time
		);
	}

	/**
	 * A draft is converted through its private link, as a preview: flagged in the state and the file, the link made only by the request
	 * and the job, never by the polling (slice 403).
	 *
	 * @return void
	 */
	public function test_a_draft_is_converted_as_a_preview(): void {
		$calls     = array();
		$scheduled = array();
		$creates   = array();
		$jobs      = $this->draft_jobs(
			array(
				'status'    => 'draft',
				'modified'  => 'm',
				'slug'      => 'brouillon',
				'url'       => 'https://x.test/?shared=T',
				'preview'   => true,
				'available' => true,
			),
			$calls,
			$scheduled,
			$creates,
			array( '%PDF-1' )
		);
		$this->configure();

		$status = $jobs->status( 13, 'print' );

		$this->assertSame( 'none', $status['status'] );
		$this->assertTrue( $status['preview'] );
		$this->assertSame( array( false ), $creates, 'The polling does not make a link.' );

		$queued = $jobs->request( 13, 'print' );

		$this->assertSame( 'queued', $queued['status'] );
		$this->assertTrue( $queued['preview'] );
		$this->assertSame( true, $creates[1], 'The request makes it.' );

		$jobs->run( ...$scheduled[0] );

		$this->assertSame( 'https://x.test/?shared=T&mode=print', $calls[0][0] );
		$this->assertSame( true, end( $creates ), 'The job makes it too: it may have been revoked since the request.' );
		$this->assertSame( false, end( $this->as_user ), 'The job has no user (WP-Cron): it must not ask for a capability.' );
		$this->assertContains( true, $this->as_user, 'The request and the polling do.' );

		$file = $jobs->download( 13, 'print' );

		$this->assertSame( 'brouillon-preview.pdf', $file['filename'], 'The name says that it is a preview.' );
		$this->assertTrue( $file['preview'] );
		$this->assertSame( 3, $file['pages'] );
		$this->assertTrue( $jobs->status( 13, 'print' )['preview'] );

		$published = $this->jobs( $calls, $scheduled );
		$this->assertFalse( $published->status( 12, 'print' )['preview'] );
	}

	/**
	 * The setting of the Access module that says who may make a link applies to the PDF of a post that is not published (slice 403).
	 *
	 * @return void
	 */
	public function test_the_capability_of_the_links_applies(): void {
		$post    = (object) array(
			'ID'          => 13,
			'post_status' => 'draft',
		);
		$allowed = false;
		$made    = array();
		$read    = new WordPressPosts(
			static fn() => $post,
			null,
			null,
			static function ( $found, $create ) use ( &$made ) {
				$made[] = $create;

				return 'https://x.test/?shared=T';
			},
			static fn() => true,
			static function () use ( &$allowed ) {
				return $allowed;
			}
		);

		$no = $read->info( 13, true );

		$this->assertFalse( $no['allowed'] );
		$this->assertSame( '', $no['url'], 'No link, and none is made, for a user who may not.' );
		$this->assertNotContains( true, $made );

		$cron = $read->info( 13, true, false );

		$this->assertSame( 'https://x.test/?shared=T', $cron['url'], 'The job of WP-Cron has no user: the link is made without the capability.' );
		$this->assertTrue( $cron['allowed'] );

		$allowed = true;
		$yes     = $read->info( 13, true );

		$this->assertTrue( $yes['allowed'] );
		$this->assertSame( 'https://x.test/?shared=T', $yes['url'] );

		$calls     = array();
		$scheduled = array();
		$creates   = array();
		$this->configure();

		$jobs = $this->draft_jobs(
			array(
				'status'    => 'draft',
				'modified'  => 'm',
				'slug'      => 'b',
				'url'       => 'https://x.test/?shared=T',
				'preview'   => true,
				'available' => true,
				'allowed'   => false,
			),
			$calls,
			$scheduled,
			$creates
		);

		foreach ( array( 'request', 'status', 'download' ) as $method ) {
			$error = $jobs->$method( 13, 'print' );
			$this->assertSame( 'books_pdf_link_forbidden', $error->get_error_code(), $method );
			$this->assertSame( array( 'status' => 403 ), $error->get_error_data() );
		}

		$this->assertSame( array(), $scheduled );
	}

	/**
	 * A draft that cannot have a link is refused as before; one whose link could not be made is refused with its own code, and a job that
	 * finds no link fails.
	 *
	 * @return void
	 */
	public function test_a_draft_without_a_link(): void {
		$calls     = array();
		$scheduled = array();
		$creates   = array();
		$base      = array(
			'status'   => 'draft',
			'modified' => 'm',
			'slug'     => 'b',
			'preview'  => true,
		);
		$this->configure();

		$off = $this->draft_jobs(
			$base + array(
				'available' => false,
				'url' => '',
			),
			$calls,
			$scheduled,
			$creates
		);
		$this->assertSame( 'books_pdf_not_published', $off->request( 13, 'print' )->get_error_code() );
		$this->assertStringContainsString( 'private link', $off->request( 13, 'print' )->get_error_message() );

		$none = $this->draft_jobs(
			$base + array(
				'available' => true,
				'url' => '',
			),
			$calls,
			$scheduled,
			$creates
		);
		$this->assertSame( 'books_pdf_no_link', $none->request( 13, 'print' )->get_error_code() );
		$this->assertSame( array(), $scheduled );

		$lost = $this->draft_jobs(
			$base + array(
				'available' => true,
				'url' => '',
			),
			$calls,
			$scheduled,
			$creates
		);
		$this->assertSame( 'none', $lost->status( 13, 'print' )['status'] );

		$closed = $this->draft_jobs(
			$base + array(
				'available' => false,
				'url' => '',
			),
			$calls,
			$scheduled,
			$creates
		);
		$state  = $this->private_state( $closed );
		$state->set(
			13,
			'print',
			array(
				'status' => 'queued',
				'key' => 'k',
				'preview' => true,
			)
		);
		$closed->run( 13, 'print', 'k' );
		$this->assertStringContainsString( 'cannot have a private link', $state->get( 13, 'print' )['message'] );

		$empty = $this->draft_jobs(
			$base + array(
				'available' => true,
				'url' => '',
			),
			$calls,
			$scheduled,
			$creates
		);
		$state = $this->private_state( $empty );
		$state->set(
			13,
			'print',
			array(
				'status' => 'queued',
				'key' => 'k',
				'preview' => true,
			)
		);
		$empty->run( 13, 'print', 'k' );
		$this->assertStringContainsString( 'could not be made', $state->get( 13, 'print' )['message'] );
		$this->assertSame( array(), $calls );
	}

	/**
	 * The renderer is given the address by which it reaches the site, not the public one (slice 225).
	 *
	 * @return void
	 */
	public function test_the_renderer_gets_the_internal_address(): void {
		$calls     = array();
		$scheduled = array();
		$jobs      = new PdfJobs(
			new RendererSettings(),
			new PageFormat(),
			$this->store(),
			new JobState(),
			static fn() => array(
				'status'   => 'publish',
				'modified' => 'm',
				'slug'     => 's',
				'url'      => 'https://testing.vli.st/livre/',
			),
			static fn() => true,
			static fn( $url, $mode ) => $url . '?mode=' . $mode,
			function () use ( &$calls ) {
				return $this->renderer( array( '%PDF-1' ), 2, $calls );
			},
			static function ( $post_id, $mode, $key ) use ( &$scheduled ) {
				$scheduled[] = array( $post_id, $mode, $key );
			},
			fn() => $this->time,
			static fn( $address ) => str_replace( 'https://testing.vli.st', 'http://wordpress', $address )
		);
		$this->configure();

		$jobs->request( 5, 'print' );
		$jobs->run( ...$scheduled[0] );

		$this->assertSame( 'http://wordpress/livre/?mode=print', $calls[0][0] );
	}

	/**
	 * A file is written where `partial_path()` says, then becomes the file of the key when it is a PDF; anything else is deleted (slice 226).
	 *
	 * @return void
	 */
	public function test_the_store_partial_files(): void {
		$store = $this->store();
		$key   = $this->key();

		$this->assertSame( $this->directory . '/' . $key . '.part', $store->partial_path( $key ) );
		$this->assertFileExists( $this->directory . '/.htaccess', 'The directory is protected as soon as it is made.' );

		file_put_contents( $store->partial_path( $key ), "%PDF-1.7\nbytes" );
		$this->assertFalse( $store->has( $key ), 'Not a file of the cache yet.' );
		$this->assertTrue( $store->commit( $key ) );
		$this->assertTrue( $store->has( $key ) );
		$this->assertFileDoesNotExist( $this->directory . '/' . $key . '.part' );
		$this->assertSame( 14, $store->size( $key ) );

		$other = str_repeat( 'b', 32 );

		file_put_contents( $store->partial_path( $other ), 'an error page' );
		$this->assertFalse( $store->commit( $other ), 'A file that is not a PDF is refused.' );
		$this->assertFileDoesNotExist( $this->directory . '/' . $other . '.part' );

		$this->assertFalse( $store->commit( $other ), 'Nothing to commit.' );
		file_put_contents( $store->partial_path( $other ), '' );
		$this->assertFalse( $store->commit( $other ), 'An empty file is refused.' );

		file_put_contents( $store->partial_path( $other ), '%PDF-x' );
		$store->discard( $other );
		$store->discard( 'bad' );
		$this->assertFileDoesNotExist( $this->directory . '/' . $other . '.part' );
		$this->assertSame( '', $store->partial_path( 'bad' ) );

		$old = str_repeat( 'c', 32 );

		file_put_contents( $store->partial_path( $old ), '%PDF-old' );
		touch( $this->directory . '/' . $old . '.part', $this->time - 10 * 86400 );
		$this->assertSame( 1, $store->cleanup( 7 ), 'A file that was never finished is cleaned up too.' );
		$this->assertTrue( $store->has( $key ), 'The recent file stays.' );
	}

	/**
	 * The pages are counted by reading the file in pieces: the pages and not the page tree, and a mark cut by the end of a piece.
	 *
	 * @return void
	 */
	public function test_the_pages_are_counted_in_the_file(): void {
		$path = $this->directory . '-pages.pdf';
		$body = "%PDF-1.4\n1 0 obj\n<< /Type /Pages /Count 3 >>\nendobj\n"
			. "2 0 obj\n<< /Type /Page /Parent 1 0 R >>\nendobj\n"
			. "3 0 obj\n<</Type/Page/Parent 1 0 R>>\nendobj\n"
			. str_repeat( 'x', 300 )
			. "4 0 obj\n<< /Type  /Page\n/Parent 1 0 R >>\nendobj\n"
			. "5 0 obj\n<< /Type /Pagesx >>\nendobj\n";

		file_put_contents( $path, $body );
		$this->assertSame( 3, PdfPages::count( $path ) );

		// A small piece makes the marks fall on the end of a piece: still counted once each.
		foreach ( array( 128, 129, 131, 137, 150, 211 ) as $chunk ) {
			$this->assertSame( 3, PdfPages::count( $path, $chunk ), 'Piece of ' . $chunk . ' bytes.' );
		}

		file_put_contents( $path, "%PDF-1.5\n1 0 obj\n<< /Type /ObjStm >>\nendobj\n" );
		$this->assertNull( PdfPages::count( $path ), 'No page object that can be read.' );
		$this->assertNull( PdfPages::count( $path . '.missing' ) );
		$this->assertNull( PdfPages::count( $path, 10 ), 'A piece too small is refused.' );
		wp_delete_file( $path );
	}

	/**
	 * The part of a file that a header Range asks for.
	 *
	 * @return void
	 */
	public function test_the_range(): void {
		$whole = array(
			'start'   => 0,
			'end'     => 99,
			'partial' => false,
		);

		$this->assertSame( $whole, PdfRoutes::range( '', 100 ) );
		$this->assertSame( $whole, PdfRoutes::range( 'items=0-5', 100 ), 'Another unit: the whole file.' );
		$this->assertSame( $whole, PdfRoutes::range( 'bytes=-', 100 ) );
		$this->assertSame(
			array(
				'start'   => 10,
				'end'     => 19,
				'partial' => true,
			),
			PdfRoutes::range( 'bytes=10-19', 100 )
		);
		$this->assertSame(
			array(
				'start'   => 90,
				'end'     => 99,
				'partial' => true,
			),
			PdfRoutes::range( 'bytes=90-', 100 )
		);
		$this->assertSame(
			array(
				'start'   => 80,
				'end'     => 99,
				'partial' => true,
			),
			PdfRoutes::range( 'bytes=-20', 100 ),
			'The last 20 bytes.'
		);
		$this->assertSame( 99, PdfRoutes::range( 'bytes=10-500', 100 )['end'], 'An end past the file is cut.' );
		$this->assertNull( PdfRoutes::range( 'bytes=100-', 100 ) );
		$this->assertNull( PdfRoutes::range( 'bytes=50-10', 100 ) );
		$this->assertNull( PdfRoutes::range( 'bytes=0-5', 0 ), 'An empty file cannot be asked a range.' );
	}

	/**
	 * A conversion that fails or gives something else than a PDF leaves no file; a file that Chromium wrote without readable pages is
	 * counted by the server when it is small.
	 *
	 * @return void
	 */
	public function test_the_job_leaves_no_partial_file(): void {
		$calls     = array();
		$scheduled = array();
		$this->configure();

		$jobs = $this->jobs( $calls, $scheduled, array( new WP_Error( 'books_gotenberg_http', 'deadline' ) ) );
		$jobs->request( 12, 'print' );
		$jobs->run( ...$scheduled[0] );

		$this->assertSame( array(), glob( $this->directory . '/*' ) ? array_diff( array_map( 'basename', glob( $this->directory . '/*' ) ), array( 'index.php' ) ) : array(), 'No file.' );

		$jobs = $this->jobs( $calls, $scheduled, array( '<html>not a pdf</html>' ) );
		$jobs->request( 12, 'web' );
		$jobs->run( ...$scheduled[1] );

		$this->assertStringContainsString( 'could not be written', $jobs->status( 12, 'web' )['message'] );
		$this->assertSame( array(), glob( $this->directory . '/*.part' ) );
		$this->assertSame( array(), glob( $this->directory . '/*.pdf' ) );

		// No readable page object, a small file: the server counts (the double answers 2).
		$jobs = $this->jobs( $calls, $scheduled, array( "%PDF-1.5\nno page object" ), 7 );
		$jobs->request( 12, 'print' );
		$jobs->run( ...$scheduled[2] );

		$this->assertSame( 7, $jobs->status( 12, 'print' )['pages'] );
	}

	/**
	 * Without a page count from the server the pages are unknown; the daily cleanup uses the days of the settings.
	 *
	 * @return void
	 */
	public function test_unknown_pages_and_the_cleanup(): void {
		$calls     = array();
		$scheduled = array();
		$jobs      = $this->jobs( $calls, $scheduled, array( '%PDF-1' ), null );
		$this->configure( array( 'keep_days' => 3 ) );

		$jobs->request( 12, 'print' );
		$jobs->run( ...$scheduled[0] );

		$this->assertNull( $jobs->status( 12, 'print' )['pages'] );
		$this->assertNull( $jobs->download( 12, 'print' )['pages'] );

		touch( $this->store()->path( $scheduled[0][2] ), $this->time - 4 * 86400 );
		clearstatcache();
		$this->assertSame( 1, $jobs->cleanup() );
		$this->assertSame( 'none', $jobs->status( 12, 'print' )['status'], 'The state is for a file that is gone.' );
	}

	/**
	 * A file that exists with no state (the transient expired): the state is rebuilt as done.
	 *
	 * @return void
	 */
	public function test_a_file_without_a_state(): void {
		$calls     = array();
		$scheduled = array();
		$jobs      = $this->jobs( $calls, $scheduled, array( '%PDF-1' ) );
		$this->configure();

		$jobs->request( 12, 'print' );
		$jobs->run( ...$scheduled[0] );
		$GLOBALS['otherguise_test_transients'] = array();

		$this->assertSame( 'done', $jobs->status( 12, 'print' )['status'] );
		$this->assertSame( 'done', $jobs->request( 12, 'print' )['status'] );
		$this->assertCount( 1, $scheduled );
	}

	/**
	 * The routes: registered with the capability and the arguments, and answering with what the jobs answer.
	 *
	 * @return void
	 */
	public function test_the_routes(): void {
		$calls     = array();
		$scheduled = array();
		$jobs      = $this->jobs( $calls, $scheduled, array( '%PDF-1.7 x' ) );
		$allowed   = array( 12 );
		$routes    = new PdfRoutes(
			$jobs,
			static fn( $id ) => in_array( $id, $allowed, true ),
			static fn( $id, $mode ) => 'https://x.test/download/' . $id . '/' . $mode,
			function ( $info, $range ) {
				$this->sent = array( $info, $range );

				return 'sent';
			}
		);
		$this->configure();

		$routes->register();
		$registered = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'register_rest_route' === $call[0] ) );

		$this->assertCount( 3, $registered );
		$this->assertSame( 'books/v1', $registered[0][1] );
		$this->assertSame( '/pdf/(?P<id>[0-9]+)', $registered[0][2] );
		$this->assertSame( 'POST', $registered[0][3][0]['methods'] );
		$this->assertSame( 'GET', $registered[0][3][1]['methods'] );
		$this->assertSame( array( $routes, 'can' ), $registered[0][3][0]['permission_callback'] );
		$this->assertArrayHasKey( 'refresh', $registered[0][3][0]['args'] );
		$this->assertSame( 'print', $registered[0][3][1]['args']['mode']['default'] );
		$this->assertSame( '/pdf/(?P<id>[0-9]+)/file', $registered[1][2] );
		$this->assertSame( array( $routes, 'file' ), $registered[1][3]['callback'] );
		$this->assertSame( '/pdf/(?P<id>[0-9]+)/download', $registered[2][2] );
		$this->assertSame( array( $routes, 'download' ), $registered[2][3]['callback'] );
		$this->assertSame( array( $routes, 'can' ), $registered[2][3]['permission_callback'] );

		$request = static fn( array $params ) => new class( $params ) {
			/**
			 * Parameters.
			 *
			 * @var array
			 */
			private $params;

			/**
			 * Builds the request.
			 *
			 * @param array $params Parameters.
			 */
			public function __construct( array $params ) {
				$this->params = $params;
			}

			/**
			 * Reads a parameter.
			 *
			 * @param string $name Name.
			 * @return mixed
			 */
			public function get_param( $name ) {
				return $this->params[ $name ] ?? null;
			}

			/**
			 * Reads a header: the parameter `range` stands for the header `Range`.
			 *
			 * @param string $name Name.
			 * @return string|null
			 */
			public function get_header( $name ) {
				return 'range' === $name ? ( $this->params['range'] ?? null ) : null;
			}
		};

		$this->assertTrue( $routes->can( $request( array( 'id' => '12' ) ) ) );
		$this->assertFalse( $routes->can( $request( array( 'id' => '13' ) ) ), 'A post the user may not edit.' );
		$this->assertFalse( $routes->can( $request( array( 'id' => '0' ) ) ) );
		$this->assertFalse( $routes->can( $request( array() ) ) );

		$this->assertSame( 'print', $routes->sanitize_mode( 'Print!' ) );
		$this->assertSame( '', $routes->sanitize_mode( array( 'x' ) ) );
		$this->assertSame( 40, strlen( $routes->sanitize_mode( str_repeat( 'a', 90 ) ) ) );

		$started = $routes->start(
			$request(
				array(
					'id' => '12',
					'mode' => 'print',
				)
			)
		);
		$this->assertSame( 'queued', $started['status'] );
		$this->assertCount( 1, $scheduled );

		$this->assertSame(
			'queued',
			$routes->status(
				$request(
					array(
						'id' => '12',
						'mode' => 'print',
					)
				)
			)['status']
		);

		$jobs->run( ...$scheduled[0] );
		$file = $routes->file(
			$request(
				array(
					'id' => '12',
					'mode' => 'print',
				)
			)
		);
		$this->assertSame( 'https://x.test/download/12/print', $file['url'] );
		$this->assertSame( 2, $file['pages'] );
		$this->assertSame( 10, $file['size'] );

		$sent = $routes->download(
			$request(
				array(
					'id' => '12',
					'mode' => 'print',
					'range' => 'bytes=0-4',
				)
			)
		);
		$this->assertSame( 'sent', $sent );
		$this->assertSame( 'repos-fonti.pdf', $this->sent[0]['filename'] );
		$this->assertSame( '%PDF-1.7 x', file_get_contents( $this->sent[0]['path'] ) );
		$this->assertSame( 'bytes=0-4', $this->sent[1] );

		$this->sent = array();
		$this->assertSame(
			'books_pdf_not_ready',
			$routes->download(
				$request(
					array(
						'id' => '12',
						'mode' => 'web',
					)
				)
			)->get_error_code()
		);
		$this->assertSame( array(), $this->sent, 'Nothing is sent when there is no file.' );

		$routes->start(
			$request(
				array(
					'id' => '12',
					'mode' => 'print',
					'refresh' => true,
				)
			)
		);
		$this->assertCount( 2, $scheduled, 'refresh: a new conversion.' );

		$this->assertSame(
			'books_pdf_unknown_mode',
			$routes->start(
				$request(
					array(
						'id' => '12',
						'mode' => 'tv',
					)
				)
			)->get_error_code()
		);
		$this->assertSame( 'books_pdf_unknown_mode', $routes->status( $request( array( 'id' => '12' ) ) )->get_error_code(), 'No mode: none.' );
	}

	/**
	 * The settings screen shows whether the server answers, when one is set.
	 *
	 * @return void
	 */
	public function test_the_screen_checks_the_server(): void {
		$answers = array(
			'http://up:3000'   => array(
				'up'      => true,
				'message' => '',
			),
			'http://down:3000' => array(
				'up'      => false,
				'message' => 'Could not resolve host',
			),
		);

		foreach ( $answers as $address => $answer ) {
			update_option( RendererSettings::OPTION, array( 'gotenberg_url' => $address ) );

			$screen = new SettingsScreen( new PageFormat(), new RendererSettings(), static fn() => true, static fn( $asked ) => $asked === $address ? $answer : array() );

			ob_start();
			$screen->render();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'value="' . $address . '"', $html );

			if ( $answer['up'] ) {
				$this->assertStringContainsString( 'The server answers.', $html );
			} else {
				$this->assertStringContainsString( 'does not answer: </p>' === '' ? '' : 'The server does not answer: Could not resolve host', $html );
			}
		}

		update_option(
			RendererSettings::OPTION,
			array(
				'gotenberg_url' => 'http://x:3000',
				'media' => 'screen',
			)
		);
		ob_start();
		( new SettingsScreen( new PageFormat(), new RendererSettings(), static fn() => true, static fn() => array() ) )->render();
		$this->assertStringContainsString( '<option value="screen" selected="selected">', (string) ob_get_clean() );
	}

	/**
	 * The editor learns whether the PDF mode exists.
	 *
	 * @return void
	 */
	public function test_the_editor_data_has_the_pdf_flag(): void {
		foreach ( array( true, false ) as $enabled ) {
			otherguise_test_reset();

			$loader = new EditorPreview(
				new PageFormat(),
				static fn() => array(
					array(
						'value' => 'print',
						'label' => 'Print',
					),
				),
				static fn( $file ) => $file,
				static fn() => 5,
				static fn() => true,
				static fn() => false,
				static fn() => $enabled
			);
			$loader->enqueue();

			$inline = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_add_inline_script' === $call[0] ) );
			$data   = json_decode( substr( $inline[0][2], strlen( 'window.booksPreview = ' ), -1 ), true );

			$this->assertSame( $enabled, $data['pdf']['enabled'] );
			$this->assertSame( '/books/v1/pdf', $data['pdf']['path'] );
		}

		$GLOBALS['post'] = (object) array( 'ID' => 5 );
		otherguise_test_reset();
		( new EditorPreview( new PageFormat(), static fn() => array(), static fn( $file ) => $file, static fn() => 5, static fn() => true ) )->enqueue();
		$inline = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_add_inline_script' === $call[0] ) );
		$this->assertStringContainsString( '"enabled":false', $inline[0][2], 'No flag given: no PDF mode.' );
		unset( $GLOBALS['post'] );
	}

	/**
	 * The module: the store is in the uploads, the modes come from the Modes module, the cleanup is scheduled once.
	 *
	 * @return void
	 */
	public function test_the_module_wiring(): void {
		$GLOBALS['otherguise_test_uploads'] = $this->directory;
		$module                              = new Module( static function () {}, static fn( $file ) => $file );

		$this->assertSame( $this->directory . '/books-pdf/' . $this->key() . '.pdf', Module::store()->path( $this->key() ) );

		$this->assertSame( array(), $module->preview_modes() );
		$this->assertFalse( $module->mode_exists( 'print' ) );
		$this->assertSame( 'https://x.test/p/?mode=print', $module->page_address( 'https://x.test/p/', 'print' ), 'Without the Modes module: the plain argument.' );

		$module->schedule_cleanup();
		$scheduled = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'wp_schedule_event' === $call[0] ) );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( 'daily', $scheduled[0][2] );
		$this->assertSame( 'books_pdf_cleanup', $scheduled[0][3] );

		$jobs = $module->jobs( new PageFormat(), new RendererSettings() );
		$this->assertInstanceOf( PdfJobs::class, $jobs );

		unset( $GLOBALS['otherguise_test_uploads'] );
	}
}
