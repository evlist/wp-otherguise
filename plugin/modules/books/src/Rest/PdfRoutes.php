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
 * conversion, `GET /pdf/<id>/file` gives the PDF itself (its bytes in base64 in a JSON answer, with the name of the file). Each one asks
 * for the capability to edit that post; the cookie authentication of the REST API checks the nonce (`X-WP-Nonce`).
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
	 * Builds the routes.
	 *
	 * @param PdfJobs       $jobs     Jobs.
	 * @param callable|null $can_edit Receives a post id; defaults to `current_user_can( 'edit_post', $id )`.
	 */
	public function __construct( PdfJobs $jobs, $can_edit = null ) {
		$this->jobs     = $jobs;
		$this->can_edit = $can_edit ?? static function ( $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};
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
	 * Gives the PDF.
	 *
	 * @param object $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function file( $request ) {
		return $this->jobs->file( (int) $request->get_param( 'id' ), $this->sanitize_mode( $request->get_param( 'mode' ) ) );
	}
}
