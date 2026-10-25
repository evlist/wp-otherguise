<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The REST route of the unlisted state.
 *
 * @package Otherguise
 */

namespace Otherguise\Access\Rest;

use Otherguise\Access\Unlisted\Unlisted;

defined( 'ABSPATH' ) || exit;

/**
 * `access/v1/posts/<id>/unlisted` (slice 402): `GET` the state, `POST` with `unlisted` (a boolean) to change it. The REST cookie nonce
 * protects it like the other routes of the editor, and the capability `edit_post` on the post is needed for both.
 */
final class UnlistedRoutes {
	/**
	 * The state.
	 *
	 * @var Unlisted
	 */
	private $unlisted;

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
	 * Builds the route.
	 *
	 * @param Unlisted      $unlisted The state.
	 * @param callable|null $can_edit Default `current_user_can( 'edit_post', $id )`.
	 * @param callable|null $get_post Default `get_post`.
	 */
	public function __construct( Unlisted $unlisted, $can_edit = null, $get_post = null ) {
		$this->unlisted = $unlisted;
		$this->can_edit = $can_edit ?? static function ( $id ) {
			return current_user_can( 'edit_post', $id );
		};
		$this->get_post = $get_post ?? 'get_post';
	}

	/**
	 * Registers the route. Action `rest_api_init`.
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
			LinkRoutes::REST_NAMESPACE,
			'/posts/(?P<id>[0-9]+)/unlisted',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'state' ),
					'permission_callback' => array( $this, 'can' ),
					'args'                => array( 'id' => $id ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'change' ),
					'permission_callback' => array( $this, 'can' ),
					'args'                => array(
						'id'       => $id,
						'unlisted' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
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
	 * The state of a post.
	 *
	 * @param object $request Request.
	 * @return mixed
	 */
	public function state( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = ( $this->get_post )( $id );

		if ( ! is_object( $post ) ) {
			return new \WP_Error( 'access_not_found', 'This post does not exist.', array( 'status' => 404 ) );
		}

		return $this->answer( $id, $post );
	}

	/**
	 * Changes the state of a post.
	 *
	 * @param object $request Request.
	 * @return mixed
	 */
	public function change( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = ( $this->get_post )( $id );

		if ( ! is_object( $post ) ) {
			return new \WP_Error( 'access_not_found', 'This post does not exist.', array( 'status' => 404 ) );
		}

		if ( ! $this->unlisted->eligible( $post ) ) {
			return new \WP_Error( 'access_not_eligible', 'This post cannot be unlisted: it is in the trash or it is not a page of the site.', array( 'status' => 400 ) );
		}

		$this->unlisted->set( $id, (bool) $request->get_param( 'unlisted' ) );

		return $this->answer( $id, $post );
	}

	/**
	 * What the route answers.
	 *
	 * @param int    $id   Id of the post.
	 * @param object $post The post.
	 * @return array<string, mixed>
	 */
	private function answer( $id, $post ) {
		return array(
			'id'       => $id,
			'unlisted' => $this->unlisted->is_unlisted( $id ),
			'eligible' => $this->unlisted->eligible( $post ),
		);
	}
}
