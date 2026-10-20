<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Tests of the settings of the rendering server and of the Gotenberg client (slice 220).
 *
 * @package Otherguise
 */

use Otherguise\Books\Render\GotenbergClient;
use Otherguise\Books\Render\Multipart;
use Otherguise\Books\Render\ServerAddress;
use Otherguise\Books\Settings\RendererSettings;
use PHPUnit\Framework\TestCase;

/**
 * No network: the HTTP function is a double.
 *
 * @covers \Otherguise\Books\Render\ServerAddress
 * @covers \Otherguise\Books\Render\Multipart
 * @covers \Otherguise\Books\Render\GotenbergClient
 * @covers \Otherguise\Books\Settings\RendererSettings
 */
class BooksRenderTest extends TestCase {

	/**
	 * Starts without any option.
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
	 * The addresses accepted are http or https with a host; the trailing slash goes, a path stays.
	 *
	 * @return void
	 */
	public function test_valid_addresses(): void {
		$this->assertSame( 'http://10.0.0.159:3000', ServerAddress::normalize( 'http://10.0.0.159:3000/' ) );
		$this->assertSame( 'http://gotenberg-testing:3000', ServerAddress::normalize( '  http://gotenberg-testing:3000  ' ) );
		$this->assertSame( 'https://render.example.test/gotenberg', ServerAddress::normalize( 'https://render.example.test/gotenberg///' ) );
		$this->assertSame( 'HTTP://Host', ServerAddress::normalize( 'HTTP://Host' ) );
	}

	/**
	 * What is refused: another scheme, no host, credentials, a query, a fragment, spaces, quotes, a very long address, not a string.
	 *
	 * @return void
	 */
	public function test_invalid_addresses(): void {
		$bad = array(
			'',
			'   ',
			'localhost:3000',
			'ftp://host:3000',
			'file:///etc/passwd',
			'javascript:alert(1)',
			'http://',
			'http://user:pass@host:3000',
			'http://user@host',
			'http://host:3000?x=1',
			'http://host:3000/#top',
			'http://host:3000/?',
			"http://host:3000\nHost: evil",
			'http://ho st',
			'http://host/"quoted"',
			'http://host/<x>',
			'//host:3000',
			'http://' . str_repeat( 'a', 400 ),
		);

		foreach ( $bad as $address ) {
			$this->assertNull( ServerAddress::normalize( $address ), 'Refused: ' . var_export( $address, true ) );
		}

		foreach ( array( null, 3000, array( 'http://host' ), true ) as $value ) {
			$this->assertNull( ServerAddress::normalize( $value ) );
		}
	}

	/**
	 * By default: no server, no wait, the print media, seven days.
	 *
	 * @return void
	 */
	public function test_settings_defaults(): void {
		$settings = new RendererSettings();

		$this->assertSame(
			array(
				'gotenberg_url'   => '',
				'wait_delay'      => 0.0,
				'wait_expression' => '',
				'wait_selector'   => '',
				'media'           => 'print',
				'keep_days'       => 7,
			),
			$settings->get()
		);
		$this->assertFalse( $settings->is_configured() );

		update_option( RendererSettings::OPTION, 'garbage' );
		$this->assertFalse( $settings->is_configured() );
	}

	/**
	 * Valid values are kept; the others take their default.
	 *
	 * @return void
	 */
	public function test_settings_sanitize(): void {
		$messages = array();
		$settings = new RendererSettings(
			static function ( $message ) use ( &$messages ) {
				$messages[] = $message;
			}
		);

		$result = $settings->sanitize(
			array(
				'gotenberg_url'   => ' http://10.0.0.159:3000/ ',
				'wait_delay'      => '1,5',
				'wait_expression' => " window.status ===\n'ready' ",
				'wait_selector'   => '.leaflet-container',
				'media'           => 'screen',
				'keep_days'       => '30',
			)
		);

		$this->assertSame(
			array(
				'gotenberg_url'   => 'http://10.0.0.159:3000',
				'wait_delay'      => 1.5,
				'wait_expression' => "window.status === 'ready'",
				'wait_selector'   => '.leaflet-container',
				'media'           => 'screen',
				'keep_days'       => 30,
			),
			$result
		);
		$this->assertSame( array(), $messages );

		update_option( RendererSettings::OPTION, $result );
		$this->assertTrue( $settings->is_configured() );

		$bad = $settings->sanitize(
			array(
				'gotenberg_url'   => 'http://x',
				'wait_delay'      => 61,
				'wait_expression' => array( 'x' ),
				'wait_selector'   => str_repeat( 'a', 300 ),
				'media'           => 'tv',
				'keep_days'       => 0,
			)
		);

		$this->assertSame( 'http://x', $bad['gotenberg_url'] );
		$this->assertSame( 0.0, $bad['wait_delay'] );
		$this->assertSame( '', $bad['wait_expression'] );
		$this->assertSame( 200, strlen( $bad['wait_selector'] ) );
		$this->assertSame( 'print', $bad['media'] );
		$this->assertSame( 7, $bad['keep_days'] );

		foreach ( array( -1, 'x', 100, '1000', 1.5 ) as $days ) {
			$this->assertSame( 7, $settings->sanitize( array( 'keep_days' => $days ) )['keep_days'], 'Days: ' . var_export( $days, true ) );
		}
	}

	/**
	 * An address that is not valid is refused with a notice and the address stored is kept; an empty one turns the server off.
	 *
	 * @return void
	 */
	public function test_settings_refuse_a_bad_address(): void {
		$messages = array();
		$settings = new RendererSettings(
			static function ( $message ) use ( &$messages ) {
				$messages[] = $message;
			}
		);

		update_option( RendererSettings::OPTION, array( 'gotenberg_url' => 'http://old:3000' ) );

		$this->assertSame( 'http://old:3000', $settings->sanitize( array( 'gotenberg_url' => 'ftp://nope' ) )['gotenberg_url'] );
		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'not valid', $messages[0] );

		$this->assertSame( '', $settings->sanitize( array( 'gotenberg_url' => '  ' ) )['gotenberg_url'], 'Empty: no server.' );
		$this->assertSame( 'http://old:3000', $settings->sanitize( 'not an array' )['gotenberg_url'], 'No address in the input: the stored one is kept.' );
		$this->assertCount( 1, $messages, 'No notice for an empty address.' );
	}

	/**
	 * The default notice goes to the Settings API.
	 *
	 * @return void
	 */
	public function test_the_default_notice(): void {
		( new RendererSettings() )->sanitize( array( 'gotenberg_url' => 'nope' ) );

		$calls = array_values( array_filter( $GLOBALS['otherguise_test_calls'], static fn( $call ) => 'add_settings_error' === $call[0] ) );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'books_renderer', $calls[0][1] );
	}

	/**
	 * The multipart body has the fields and the files between boundaries; names that break the headers are refused.
	 *
	 * @return void
	 */
	public function test_multipart(): void {
		$body = Multipart::build(
			array(
				'url'  => 'https://x.test/a',
				'wait' => '1s',
			),
			array(
				array(
					'name'     => 'files',
					'filename' => 'a.pdf',
					'type'     => 'application/pdf',
					'data'     => "%PDF-1.7\r\n",
				),
			),
			'BND'
		);

		$this->assertSame(
			"--BND\r\nContent-Disposition: form-data; name=\"url\"\r\n\r\nhttps://x.test/a\r\n" .
			"--BND\r\nContent-Disposition: form-data; name=\"wait\"\r\n\r\n1s\r\n" .
			"--BND\r\nContent-Disposition: form-data; name=\"files\"; filename=\"a.pdf\"\r\nContent-Type: application/pdf\r\n\r\n%PDF-1.7\r\n\r\n" .
			"--BND--\r\n",
			$body
		);
		$this->assertMatchesRegularExpression( '/^books[0-9a-f]{32}$/', Multipart::boundary() );

		foreach ( array( array( 'a"b' => 'x' ), array( "a\r\nb" => 'x' ), array( '' => 'x' ) ) as $fields ) {
			try {
				Multipart::build( $fields, array(), 'BND' );
				$this->fail( 'A name that breaks the headers is refused.' );
			} catch ( InvalidArgumentException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}

		$this->expectException( InvalidArgumentException::class );
		Multipart::build( array( 'x' => 'has BND inside' ), array(), 'BND' );
	}

	/**
	 * Builds a client whose HTTP function records the requests and answers from a queue.
	 *
	 * @param array $answers  Answers, in order.
	 * @param array $requests Requests recorded.
	 * @return GotenbergClient
	 */
	private function client( array $answers, array &$requests ) {
		return new GotenbergClient(
			'http://gotenberg:3000/',
			static function ( $url, $args ) use ( &$answers, &$requests ) {
				$requests[] = array( $url, $args );

				return array_shift( $answers );
			},
			static fn() => 'BND'
		);
	}

	/**
	 * Builds an answer of the HTTP function.
	 *
	 * @param int    $code Status.
	 * @param string $body Body.
	 * @return array
	 */
	private function answer( $code, $body ) {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}

	/**
	 * The conversion sends the page, the paper size and margins in inches, the media and the waits; it returns the PDF.
	 *
	 * @return void
	 */
	public function test_convert_url(): void {
		$requests = array();
		$client   = $this->client( array( $this->answer( 200, "%PDF-1.7\nbytes" ) ), $requests );

		$pdf = $client->convert_url(
			'https://testing.vli.st/post/?mode=print',
			array(
				'width_mm'  => 210.0,
				'height_mm' => 297.0,
				'margin_mm' => 10.0,
			),
			array(
				'media'           => 'screen',
				'wait_delay'      => 2.5,
				'wait_expression' => "window.status === 'ready'",
				'wait_selector'   => '.leaflet-container',
				'timeout'         => 90,
			)
		);

		$this->assertSame( "%PDF-1.7\nbytes", $pdf );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'http://gotenberg:3000/forms/chromium/convert/url', $requests[0][0] );
		$this->assertSame( 'POST', $requests[0][1]['method'] );
		$this->assertSame( 90, $requests[0][1]['timeout'] );
		$this->assertSame( 'multipart/form-data; boundary=BND', $requests[0][1]['headers']['Content-Type'] );

		$body = $requests[0][1]['body'];

		foreach ( array(
			'url'               => 'https://testing.vli.st/post/?mode=print',
			'paperWidth'        => '8.2677',
			'paperHeight'       => '11.6929',
			'marginTop'         => '0.3937',
			'marginBottom'      => '0.3937',
			'marginLeft'        => '0.3937',
			'marginRight'       => '0.3937',
			'printBackground'   => 'true',
			'emulatedMediaType' => 'screen',
			'waitDelay'         => '2.5s',
			'waitForExpression' => "window.status === 'ready'",
			'waitForSelector'   => '.leaflet-container',
		) as $name => $value ) {
			$this->assertStringContainsString( 'name="' . $name . "\"\r\n\r\n" . $value . "\r\n", $body, $name );
		}
	}

	/**
	 * Without options, nothing is waited for and the media is left to the server.
	 *
	 * @return void
	 */
	public function test_convert_url_without_options(): void {
		$requests = array();
		$client   = $this->client( array( $this->answer( 200, '%PDF-1.7' ) ), $requests );
		$format   = array(
			'width_mm'  => 148.0,
			'height_mm' => 210.0,
			'margin_mm' => 10.0,
		);

		$client->convert_url(
			'https://x.test/',
			$format,
			array(
				'wait_delay' => 0,
				'wait_expression' => '',
				'media' => 'tv',
			)
		);

		$body = $requests[0][1]['body'];

		$this->assertStringNotContainsString( 'waitDelay', $body );
		$this->assertStringNotContainsString( 'waitForExpression', $body );
		$this->assertStringNotContainsString( 'waitForSelector', $body );
		$this->assertStringNotContainsString( 'emulatedMediaType', $body );
		$this->assertSame( 120, $requests[0][1]['timeout'], 'The default time limit.' );

		$requests = array();
		$client   = $this->client( array( $this->answer( 200, '%PDF-1.7' ) ), $requests );
		$client->convert_url( 'https://x.test/', $format, array( 'wait_delay' => 5 ) );
		$this->assertStringContainsString( "name=\"waitDelay\"\r\n\r\n5s\r\n", $requests[0][1]['body'], 'Whole seconds have no decimal.' );
	}

	/**
	 * The failures: the server unreachable, an HTTP error with its message, an answer that is not a PDF, a field that breaks the request.
	 *
	 * @return void
	 */
	public function test_convert_url_errors(): void {
		$format   = array(
			'width_mm'  => 148.0,
			'height_mm' => 210.0,
			'margin_mm' => 10.0,
		);
		$requests = array();

		$error = $this->client( array( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ), $requests )->convert_url( 'https://x.test/', $format );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'books_gotenberg_unreachable', $error->get_error_code() );
		$this->assertStringContainsString( 'timed out', $error->get_error_message() );

		$error = $this->client( array( $this->answer( 503, "convert URL to PDF:\n convert to PDF: context deadline exceeded" ) ), $requests )->convert_url( 'https://x.test/', $format );
		$this->assertSame( 'books_gotenberg_http', $error->get_error_code() );
		$this->assertStringContainsString( '503', $error->get_error_message() );
		$this->assertStringContainsString( 'context deadline exceeded', $error->get_error_message() );
		$this->assertSame( array( 'status' => 503 ), $error->get_error_data() );

		$error = $this->client( array( $this->answer( 400, '' ) ), $requests )->convert_url( 'https://x.test/', $format );
		$this->assertStringContainsString( '400: -', $error->get_error_message(), 'An empty message.' );

		$error = $this->client( array( $this->answer( 200, '<html>login</html>' ) ), $requests )->convert_url( 'https://x.test/', $format );
		$this->assertSame( 'books_gotenberg_not_pdf', $error->get_error_code() );

		$error = $this->client( array(), $requests )->convert_url( 'https://x.test/BND', $format );
		$this->assertSame( 'books_gotenberg_request', $error->get_error_code(), 'An address that contains the boundary.' );
	}

	/**
	 * The number of pages comes from the `PageCount` of the metadata; null when the server cannot say.
	 *
	 * @return void
	 */
	public function test_page_count(): void {
		$requests = array();
		$client   = $this->client(
			array(
				$this->answer( 200, '{"book.pdf":{"PDFVersion":1.7,"PageCount":2}}' ),
				$this->answer( 200, '{"book.pdf":{"Producer":"x"}}' ),
				$this->answer( 200, 'not json' ),
				$this->answer( 503, 'timeout' ),
				new WP_Error( 'http_request_failed', 'down' ),
				$this->answer( 200, '{"book.pdf":{"PageCount":0}}' ),
			),
			$requests
		);

		$this->assertSame( 2, $client->page_count( '%PDF-1.7' ) );
		$this->assertSame( 'http://gotenberg:3000/forms/pdfengines/metadata/read', $requests[0][0] );
		$this->assertStringContainsString( 'name="files"; filename="book.pdf"', $requests[0][1]['body'] );
		$this->assertStringContainsString( "Content-Type: application/pdf\r\n\r\n%PDF-1.7", $requests[0][1]['body'] );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertNull( $client->page_count( '%PDF-1.7' ), 'No usable page count, case ' . $i );
		}
	}

	/**
	 * The health check: up, down with a status, and unreachable.
	 *
	 * @return void
	 */
	public function test_health(): void {
		$requests = array();
		$client   = $this->client(
			array(
				$this->answer( 200, '{"status":"up","details":{"chromium":{"status":"up"}}}' ),
				$this->answer( 503, '{"status":"down"}' ),
				$this->answer( 200, 'ok' ),
				new WP_Error( 'http_request_failed', 'Could not resolve host' ),
			),
			$requests
		);

		$this->assertSame(
			array(
				'up'      => true,
				'message' => '',
			),
			$client->health()
		);
		$this->assertSame( 'http://gotenberg:3000/health', $requests[0][0] );
		$this->assertSame( 'GET', $requests[0][1]['method'] );

		$down = $client->health();
		$this->assertFalse( $down['up'] );
		$this->assertStringContainsString( '503', $down['message'] );

		$this->assertFalse( $client->health()['up'], 'An answer that is not the JSON of Gotenberg.' );
		$this->assertSame(
			array(
				'up'      => false,
				'message' => 'Could not resolve host',
			),
			$client->health()
		);
	}

	/**
	 * The default HTTP function is the one of WordPress that accepts private addresses.
	 *
	 * @return void
	 */
	public function test_the_default_http_function(): void {
		$property = ( new ReflectionClass( GotenbergClient::class ) )->getProperty( 'http' );
		$property->setAccessible( true );

		$this->assertSame( 'wp_remote_request', $property->getValue( new GotenbergClient( 'http://x' ) ) );
	}
}
