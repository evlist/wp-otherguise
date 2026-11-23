<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The route that checks the options of the block of the index while they are typed.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * `GET books/v1/index-options` (slice 613): tells what is wrong with the options of the block `books/section-index` (`include`, `exclude`, `rollup`), field
 * by field, so that the editor can say it while they are typed. The check is **the one of the module of the index**: the filter `index_build` is asked for
 * the index of no post, which checks the options first, so that the kinds and the sources that are valid are the ones the module knows, whatever they
 * are now. Without the module (the filter answers nothing) the route says it is not available and the editor checks nothing. For the people who can
 * edit posts.
 */
final class IndexOptionsRoute {
	/**
	 * Namespace of the route.
	 */
	public const REST_NAMESPACE = 'books/v1';

	/**
	 * The fields that are checked.
	 */
	public const FIELDS = array( 'include', 'exclude', 'rollup' );

	/**
	 * Tells whether the current user may use the route.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Asks the module of the index: receives the options, returns its answer.
	 *
	 * @var callable
	 */
	private $ask;

	/**
	 * Builds the route.
	 *
	 * @param callable|null $can Whether the user may use it; default: who can edit posts.
	 * @param callable|null $ask The module of the index; default the filter `index_build` for no post.
	 */
	public function __construct( $can = null, $ask = null ) {
		$this->can = $can ?? static function () {
			return current_user_can( 'edit_posts' );
		};
		$this->ask = $ask ?? static function ( array $options ) {
			return apply_filters( 'index_build', null, array(), $options );
		};
	}

	/**
	 * Registers the route. Action `rest_api_init`.
	 *
	 * @return void
	 */
	public function register() {
		$args = array();

		foreach ( self::FIELDS as $field ) {
			$args[ $field ] = array(
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => static function ( $value ) {
					return sanitize_text_field( (string) $value );
				},
			);
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/index-options',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'check' ),
				'permission_callback' => array( $this, 'can_use' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Whether the current user may check options.
	 *
	 * @return bool
	 */
	public function can_use() {
		return (bool) ( $this->can )();
	}

	/**
	 * Checks the options, field by field.
	 *
	 * @param \WP_REST_Request|array $request The request, or the options.
	 * @return array{available:bool,errors:array<string,string[]>}
	 */
	public function check( $request ) {
		$errors    = array();
		$available = false;

		foreach ( self::FIELDS as $field ) {
			$value = is_array( $request ) ? (string) ( $request[ $field ] ?? '' ) : (string) $request->get_param( $field );
			$found = array();

			if ( '' !== trim( $value ) ) {
				$answer = ( $this->ask )( array( $field => $value ) );

				if ( is_array( $answer ) ) {
					$available = true;
					$found     = array_values( array_map( 'strval', (array) ( $answer['errors'] ?? array() ) ) );
				}
			}

			$errors[ $field ] = $found;
		}

		// With nothing typed the module is not asked: the editor then asks only once there is something to check.
		return array(
			'available' => $available,
			'errors'    => $errors,
		);
	}
}
