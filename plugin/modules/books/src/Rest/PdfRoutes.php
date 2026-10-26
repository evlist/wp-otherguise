<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The REST routes of the PDF preview.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Rest;

use Otherguise\Books\Pdf\PdfJobs;

defined( 'ABSPATH' ) || exit;

/**
 * The routes of namespace `books/v1` (slice 220): `POST /pdf/<id>` asks for the PDF of a post, `GET /pdf/<id>` gives the state of the
 * conversion, `GET /pdf/<id>/file` says where the PDF is (its name, its pages, and the address of the next route) and `GET /pdf/<id>/download`
 * **sends the file** (slice 226: in pieces, with the byte ranges that a PDF viewer asks for, never in memory). Each one asks for the
 * capability to edit that post; the cookie authentication of the REST API checks the nonce (`X-WP-Nonce`, or `_wpnonce` in the address,
 * which is what an `iframe` or a link can carry).
 */
final class PdfRoutes {
	/**
	 * Namespace of the routes.
	 */
	public const REST_NAMESPACE = 'books/v1';

	/**
	 * Jobs.
	 *
	 * @var PdfJobs
	 */
	private $jobs;

	/**
	 * Tells whether the current user may edit a post: receives the post id.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Gives the address of the route that sends the file: receives the post id and the mode.
	 *
	 * @var callable
	 */
	private $address;

	/**
	 * Sends the file: receives what `PdfJobs::download()` gives and the header `Range` of the request.
	 *
	 * @var callable
	 */
	private $send;

	/**
	 * Builds the routes.
	 *
	 * @param PdfJobs       $jobs     Jobs.
	 * @param callable|null $can_edit Receives a post id; defaults to `current_user_can( 'edit_post', $id )`.
	 * @param callable|null $address  Receives a post id and a mode; defaults to the address of the route with a nonce of the REST API.
	 * @param callable|null $send     Receives the file and the range asked for; defaults to `serve()`, which ends the request.
	 */
	public function __construct( PdfJobs $jobs, $can_edit = null, $address = null, $send = null ) {
		$this->jobs     = $jobs;
		$this->can_edit = $can_edit ?? static function ( $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};
		$this->address  = $address ?? static function ( $post_id, $mode ) {
			return add_query_arg(
				array(
					'mode'     => $mode,
					'_wpnonce' => wp_create_nonce( 'wp_rest' ),
				),
				rest_url( self::REST_NAMESPACE . '/pdf/' . (int) $post_id . '/download' )
			);
		};
		$this->send     = $send ?? array( self::class, 'serve' );
	}

	/**
	 * Registers the routes. Action `rest_api_init`.
	 *
	 * @return void
	 */
	public function register() {
		$arguments = array(
			'id'   => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'mode' => array(
				'type'              => 'string',
				'default'           => 'print',
				'sanitize_callback' => array( $this, 'sanitize_mode' ),
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/pdf/(?P<id>[0-9]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'start' ),
					'permission_callback' => array( $this, 'can' ),
					'args'                => $arguments + array(
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'can' ),
					'args'                => $arguments,
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/pdf/(?P<id>[0-9]+)/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'file' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => $arguments,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/pdf/(?P<id>[0-9]+)/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => $arguments,
			)
		);
	}

	/**
	 * Keeps a mode slug: lower case letters, digits, hyphen and underscore.
	 *
	 * @param mixed $mode Value.
	 * @return string
	 */
	public function sanitize_mode( $mode ) {
		return is_string( $mode ) ? substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $mode ) ), 0, 40 ) : '';
	}

	/**
	 * Permission: the user may edit the post.
	 *
	 * @param object $request Request.
	 * @return bool
	 */
	public function can( $request ) {
		$post_id = (int) $request->get_param( 'id' );

		return $post_id > 0 && (bool) ( $this->can_edit )( $post_id );
	}

	/**
	 * Asks for the PDF.
	 *
	 * @param object $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function start( $request ) {
		return $this->jobs->request( (int) $request->get_param( 'id' ), $this->sanitize_mode( $request->get_param( 'mode' ) ), (bool) $request->get_param( 'refresh' ) );
	}

	/**
	 * Gives the state of the conversion.
	 *
	 * @param object $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function status( $request ) {
		return $this->jobs->status( (int) $request->get_param( 'id' ), $this->sanitize_mode( $request->get_param( 'mode' ) ) );
	}

	/**
	 * Says where the PDF is: its name, its pages, whether it is a preview, and the address that sends it.
	 *
	 * @param object $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function file( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$mode = $this->sanitize_mode( $request->get_param( 'mode' ) );
		$info = $this->jobs->download( $id, $mode );

		if ( $info instanceof \WP_Error ) {
			return $info;
		}

		return array(
			'filename' => $info['filename'],
			'pages'    => $info['pages'],
			'preview'  => $info['preview'],
			'size'     => $info['size'],
			'url'      => ( $this->address )( $id, $mode ),
		);
	}

	/**
	 * Sends the PDF.
	 *
	 * @param object $request Request.
	 * @return mixed An error, or what the sender gives (it ends the request).
	 */
	public function download( $request ) {
		$info = $this->jobs->download( (int) $request->get_param( 'id' ), $this->sanitize_mode( $request->get_param( 'mode' ) ) );

		if ( $info instanceof \WP_Error ) {
			return $info;
		}

		return ( $this->send )( $info, (string) $request->get_header( 'range' ) );
	}

	/**
	 * Works out the part of a file that a header `Range` asks for (a single range; another form gives the whole file).
	 *
	 * @param string $header Value of the header, empty when there is none.
	 * @param int    $size   Size of the file.
	 * @return array{start:int, end:int, partial:bool}|null Null when the range cannot be satisfied.
	 */
	public static function range( $header, $size ) {
		$size = (int) $size;
		$all  = array(
			'start'   => 0,
			'end'     => max( 0, $size - 1 ),
			'partial' => false,
		);

		if ( 1 !== preg_match( '/^\s*bytes=(\d*)-(\d*)\s*$/', (string) $header, $found ) || ( '' === $found[1] && '' === $found[2] ) ) {
			return $all;
		}

		if ( '' === $found[1] ) {
			$start = max( 0, $size - (int) $found[2] );
			$end   = $size - 1;
		} else {
			$start = (int) $found[1];
			$end   = '' === $found[2] ? $size - 1 : min( $size - 1, (int) $found[2] );
		}

		if ( $size <= 0 || $start >= $size || $start > $end ) {
			return null;
		}

		return array(
			'start'   => $start,
			'end'     => $end,
			'partial' => true,
		);
	}

	/**
	 * Sends a file to the browser in pieces and ends the request: the headers of a PDF that the viewer of the browser shows, the byte ranges
	 * that it asks for, no copy of the file in memory. Not tried by the unit tests (it ends the request).
	 *
	 * @param array{path:string, filename:string, size:int} $info  The file.
	 * @param string                                        $range Header `Range` of the request.
	 * @return void
	 */
	public static function serve( array $info, $range ) {
		$part = self::range( $range, $info['size'] );

		if ( null === $part ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . (int) $info['size'] );
			exit;
		}

		status_header( $part['partial'] ? 206 : 200 );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $info['filename'] . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $part['end'] - $part['start'] + 1 ) );
		header( 'Cache-Control: private, max-age=0, must-revalidate' );
		header( 'X-Content-Type-Options: nosniff' );

		if ( $part['partial'] ) {
			header( 'Content-Range: bytes ' . $part['start'] . '-' . $part['end'] . '/' . (int) $info['size'] );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		for ( $position = $part['start']; $position <= $part['end']; $position += 1048576 ) {
			$piece = file_get_contents( $info['path'], false, null, $position, min( 1048576, $part['end'] - $position + 1 ) );

			if ( false === $piece || '' === $piece ) {
				break;
			}

			file_put_contents( 'php://output', $piece );
			flush();
		}

		exit;
	}
}
