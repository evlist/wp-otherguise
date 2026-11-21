<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The rules of a branch, for the editor.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Rest;

use Otherguise\Wiki\Tree\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * `wiki/v1/rules/<id>` (slice 500): `GET` the rules set on a page and its **effective** rules, with the page that sets each (its id and its
 * title), for the panel of the editor. The cookie nonce of REST protects it and the capability `edit_post` on the page is needed.
 */
final class RulesRoutes {
	public const REST_NAMESPACE = 'wiki/v1';

	/**
	 * The rules.
	 *
	 * @var Rules
	 */
	private $rules;

	/**
	 * Tells whether the user may edit a page.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Returns the title of a page.
	 *
	 * @var callable
	 */
	private $title_of;

	/**
	 * Builds the routes.
	 *
	 * @param Rules         $rules    The rules.
	 * @param callable|null $can_edit Default `current_user_can( 'edit_post', $id )`.
	 * @param callable|null $title_of Receives a page id; default the title of the post.
	 */
	public function __construct( Rules $rules, $can_edit = null, $title_of = null ) {
		$this->rules    = $rules;
		$this->can_edit = $can_edit ?? static function ( $id ) {
			return current_user_can( 'edit_post', $id );
		};
		$this->title_of = $title_of ?? static function ( $id ) {
			return (string) get_post_field( 'post_title', (int) $id, 'raw' );
		};
	}

	/**
	 * Registers the route. Action `rest_api_init`.
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/rules/(?P<id>[0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'state' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Tells whether the user may read the rules of a page.
	 *
	 * @param mixed $request The request.
	 * @return bool
	 */
	public function can( $request ) {
		return (bool) ( $this->can_edit )( (int) $request['id'] );
	}

	/**
	 * The rules set on a page and its effective rules.
	 *
	 * @param mixed $request The request.
	 * @return array{id: int, own: array<string, string>, effective: array<string, array{value: string, from: int, title: string, own: bool}>}
	 */
	public function state( $request ) {
		$id        = (int) $request['id'];
		$effective = array();

		$this->rules->forget();

		foreach ( $this->rules->effective( $id ) as $key => $rule ) {
			$effective[ $key ] = array(
				'value' => $rule['value'],
				'from'  => $rule['from'],
				'title' => (string) ( $this->title_of )( $rule['from'] ),
				'own'   => $rule['from'] === $id,
			);
		}

		return array(
			'id'        => $id,
			'own'       => $this->rules->own( $id ),
			'effective' => $effective,
		);
	}
}
