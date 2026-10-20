<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A client of the Gotenberg server.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Calls a Gotenberg server (version 8) over HTTP with `wp_remote_request()` (slice 220; no library, see `docs/design/books.md`). The routes
 * and the fields are those of its documentation: `POST /forms/chromium/convert/url` (the sizes are in inches when they have no unit),
 * `POST /forms/pdfengines/metadata/read` (the number of pages is `PageCount`) and `GET /health`.
 */
final class GotenbergClient implements Renderer {
	/**
	 * Address of the server, without a trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Sends a request: receives the address and the arguments, returns what `wp_remote_request()` returns.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Makes a boundary.
	 *
	 * @var callable
	 */
	private $boundary;

	/**
	 * Builds the client.
	 *
	 * @param string        $base     Address of the server (see `ServerAddress`).
	 * @param callable|null $http     Defaults to `wp_remote_request`: the address is set by an administrator and is internal on purpose, so
	 *                                the "safe" variant, which refuses private addresses, is not used.
	 * @param callable|null $boundary Defaults to `Multipart::boundary()`.
	 */
	public function __construct( $base, $http = null, $boundary = null ) {
		$this->base     = rtrim( (string) $base, '/' );
		$this->http     = $http ?? 'wp_remote_request';
		$this->boundary = $boundary ?? array( Multipart::class, 'boundary' );
	}

	/**
	 * Converts a page to PDF.
	 *
	 * @param string               $url     Address of the page.
	 * @param array<string, float> $format  Format of the page.
	 * @param array<string, mixed> $options Options.
	 * @return string|\WP_Error
	 */
	public function convert_url( $url, array $format, array $options = array() ) {
		$fields = array(
			'url'             => (string) $url,
			'paperWidth'      => self::inches( $format['width_mm'] ),
			'paperHeight'     => self::inches( $format['height_mm'] ),
			'marginTop'       => self::inches( $format['margin_mm'] ),
			'marginBottom'    => self::inches( $format['margin_mm'] ),
			'marginLeft'      => self::inches( $format['margin_mm'] ),
			'marginRight'     => self::inches( $format['margin_mm'] ),
			'printBackground' => 'true',
		);

		if ( isset( $options['media'] ) && in_array( $options['media'], array( 'print', 'screen' ), true ) ) {
			$fields['emulatedMediaType'] = $options['media'];
		}

		if ( isset( $options['wait_delay'] ) && (float) $options['wait_delay'] > 0 ) {
			$fields['waitDelay'] = rtrim( rtrim( number_format( (float) $options['wait_delay'], 1, '.', '' ), '0' ), '.' ) . 's';
		}

		foreach ( array(
			'wait_expression' => 'waitForExpression',
			'wait_selector'   => 'waitForSelector',
		) as $key => $field ) {
			if ( isset( $options[ $key ] ) && is_string( $options[ $key ] ) && '' !== $options[ $key ] ) {
				$fields[ $field ] = $options[ $key ];
			}
		}

		$timeout = isset( $options['timeout'] ) ? max( 5, (int) $options['timeout'] ) : 120;
		$result  = $this->post( '/forms/chromium/convert/url', $fields, array(), $timeout );

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		if ( 0 !== strpos( $result, '%PDF-' ) ) {
			return new \WP_Error( 'books_gotenberg_not_pdf', __( 'The server answered, but not with a PDF.', 'otherguise' ) );
		}

		return $result;
	}

	/**
	 * Gives the number of pages of a PDF, through the route that reads the metadata; null when it cannot be had.
	 *
	 * @param string $pdf Bytes of the PDF.
	 * @return int|null
	 */
	public function page_count( $pdf ) {
		$result = $this->post(
			'/forms/pdfengines/metadata/read',
			array(),
			array(
				array(
					'name'     => 'files',
					'filename' => 'book.pdf',
					'type'     => 'application/pdf',
					'data'     => (string) $pdf,
				),
			),
			30
		);

		if ( $result instanceof \WP_Error ) {
			return null;
		}

		$data = json_decode( $result, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		foreach ( $data as $tags ) {
			if ( is_array( $tags ) && isset( $tags['PageCount'] ) && is_numeric( $tags['PageCount'] ) && (int) $tags['PageCount'] > 0 ) {
				return (int) $tags['PageCount'];
			}
		}

		return null;
	}

	/**
	 * Checks that the server answers: `GET /health`.
	 *
	 * @return array{up:bool, message:string}
	 */
	public function health() {
		$response = ( $this->http )(
			$this->base . '/health',
			array(
				'method'  => 'GET',
				'timeout' => 5,
			)
		);

		if ( $response instanceof \WP_Error ) {
			return array(
				'up'      => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) ( $response['response']['code'] ?? 0 );
		$data = json_decode( (string) ( $response['body'] ?? '' ), true );
		$up   = 200 === $code && is_array( $data ) && 'up' === ( $data['status'] ?? '' );

		return array(
			'up'      => $up,
			'message' => $up ? '' : sprintf( /* translators: %d: HTTP status code. */ __( 'The server answered with the status %d and is not up.', 'otherguise' ), $code ),
		);
	}

	/**
	 * Posts a multipart form and returns the body of the answer, or an error.
	 *
	 * @param string                                                                    $route   Route.
	 * @param array<string, string>                                                     $fields  Fields.
	 * @param array<int, array{name:string, filename:string, type:string, data:string}> $files   Files.
	 * @param int                                                                       $timeout Time limit, in seconds.
	 * @return string|\WP_Error
	 */
	private function post( $route, array $fields, array $files, $timeout ) {
		$boundary = (string) ( $this->boundary )();

		try {
			$body = Multipart::build( $fields, $files, $boundary );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'books_gotenberg_request', $exception->getMessage() );
		}

		$response = ( $this->http )(
			$this->base . $route,
			array(
				'method'  => 'POST',
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
				'body'    => $body,
			)
		);

		if ( $response instanceof \WP_Error ) {
			return new \WP_Error( 'books_gotenberg_unreachable', $response->get_error_message() );
		}

		$code    = (int) ( $response['response']['code'] ?? 0 );
		$content = (string) ( $response['body'] ?? '' );

		if ( 200 !== $code ) {
			$text = trim( (string) preg_replace( '/\s+/', ' ', substr( $content, 0, 300 ) ) );

			return new \WP_Error(
				'books_gotenberg_http',
				sprintf( /* translators: 1: HTTP status code, 2: message of the server. */ __( 'The server answered with the status %1$d: %2$s', 'otherguise' ), $code, '' !== $text ? $text : '-' ),
				array( 'status' => $code )
			);
		}

		return $content;
	}

	/**
	 * Converts millimetres to inches, the unit of Gotenberg when none is given.
	 *
	 * @param float $mm Millimetres.
	 * @return string
	 */
	private static function inches( $mm ) {
		return number_format( (float) $mm / 25.4, 4, '.', '' );
	}
}
