<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The route that searches the topics.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Rest;

use Otherguise\Index\Designation\TopicFinder;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /index/v1/topics?search=…` (slice 630): the topics whose label contains what a person typed, each with its kind and its broader topics, for the field
 * that chooses the topic of an assignment. Read only; the capability of the module (`index_capability`) is required.
 */
final class TopicsRoute {
	public const REST_NAMESPACE = 'index/v1';
	public const LIMIT          = 20;

	/**
	 * The finder.
	 *
	 * @var TopicFinder
	 */
	private $finder;

	/**
	 * Tells whether the current user may: `fn(): bool`.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Builds the route.
	 *
	 * @param TopicFinder   $finder The finder.
	 * @param callable|null $can    Default: the capability of the module.
	 */
	public function __construct( TopicFinder $finder, $can = null ) {
		$this->finder = $finder;
		$this->can    = $can ?? static function () {
			return current_user_can( (string) apply_filters( 'index_capability', 'edit_others_posts' ) );
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
			'/topics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => $this->can,
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => static function ( $value ) {
							return sanitize_text_field( (string) $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Answers a search.
	 *
	 * @param object $request The request (`get_param`).
	 * @return array<int, array{id:int,label:string,kind:string,chain:string}>
	 */
	public function search( $request ) {
		return $this->finder->search( (string) $request->get_param( 'search' ), self::LIMIT );
	}
}
