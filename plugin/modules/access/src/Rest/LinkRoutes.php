<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The REST routes of the private links.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Rest;

use Otherguise\Access\Link\Links;

defined( 'ABSPATH' ) || exit;

/**
 * `access/v1/posts/<id>` (slice 400): `GET` the state of the link of a post, `POST` to create it or, with `regenerate`, to replace it,
 * `DELETE` to revoke it. The REST cookie nonce protects them like the other routes of the editor, and the capability `edit_post` on the
 * post is needed for each: the token is given to nobody else.
 */
final class LinkRoutes {
	public const REST_NAMESPACE = 'access/v1';

	/**
	 * The links.
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * Tells whether the user may edit a post.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Reads a post.
	 *
	 * @var callable
	 */
	private $get_post;

	/**
	 * Builds the routes.
	 *
	 * @param Links         $links    The links.
	 * @param callable|null $can_edit Default `current_user_can( 'edit_post', $id )`.
	 * @param callable|null $get_post Default `get_post`.
	 */
	public function __construct( Links $links, $can_edit = null, $get_post = null ) {
		$this->links    = $links;
		$this->can_edit = $can_edit ?? static function ( $id ) {
			return current_user_can( 'edit_post', $id );
		};
		$this->get_post = $get_post ?? 'get_post';
	}

	/**
	 * Registers the routes. Action `rest_api_init`.
	 *
	 * @return void
	 */
	public function register() {
		$id = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>[0-9]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'state' ),
					'permission_callback' => array( $this, 'can' ),
					'args'                => array( 'id' => $id ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can' ),
					'args'                => array(
						'id'         => $id,
						'regenerate' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => array( $this, 'can' ),
					'args'                => array( 'id' => $id ),
				),
			)
		);
	}

	/**
	 * Whether the user may edit the post of the route.
	 *
	 * @param object $request Request.
	 * @return bool
	 */
	public function can( $request ) {
		$id = (int) $request->get_param( 'id' );

		return $id > 0 && (bool) ( $this->can_edit )( $id );
	}

	/**
	 * The state of the link of a post.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	public function state( $request ) {
		return $this->describe( (int) $request->get_param( 'id' ) );
	}

	/**
	 * Makes the link of a post, or replaces it.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	public function create( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = ( $this->get_post )( $id );

		if ( ! is_object( $post ) ) {
			return new \WP_Error( 'access_not_found', __( 'This post does not exist.', 'otherguise' ), array( 'status' => 404 ) );
		}

		if ( ! $this->links->eligible( $post ) ) {
			return new \WP_Error( 'access_not_eligible', __( 'This post cannot have a link: it is in the trash or it is not a page of the site.', 'otherguise' ), array( 'status' => 400 ) );
		}

		if ( $request->get_param( 'regenerate' ) ) {
			$this->links->regenerate( $id );
		} else {
			$this->links->create( $id );
		}

		return $this->describe( $id );
	}

	/**
	 * Takes the link of a post away.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	public function revoke( $request ) {
		$id = (int) $request->get_param( 'id' );

		$this->links->revoke( $id );

		return $this->describe( $id );
	}

	/**
	 * What the panel shows: whether there is a link, its address and its date.
	 *
	 * @param int $id Id of the post.
	 * @return array|\WP_Error
	 */
	private function describe( $id ) {
		if ( ! is_object( ( $this->get_post )( $id ) ) ) {
			return new \WP_Error( 'access_not_found', __( 'This post does not exist.', 'otherguise' ), array( 'status' => 404 ) );
		}

		$url = $this->links->address( $id );

		return array(
			'id'      => $id,
			'active'  => false !== $url,
			'url'     => false === $url ? '' : $url,
			'created' => $this->links->created( $id ),
		);
	}
}
