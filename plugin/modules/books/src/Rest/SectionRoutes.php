<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The REST routes of the sections.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Rest;

use Otherguise\Books\Section\SectionException;
use Otherguise\Books\Section\Sections;

defined( 'ABSPATH' ) || exit;

/**
 * Routes `books/v1/sections…` (slice 223). The REST cookie nonce protects them like the other routes of the editor; every refusal of the
 * service becomes an error with its status and a message that can be translated.
 */
final class SectionRoutes {
	public const REST_NAMESPACE = 'books/v1';

	/**
	 * The sections.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * Tells whether the user may edit a post.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Tells whether the user may write posts at all.
	 *
	 * @var callable
	 */
	private $can_write;

	/**
	 * Builds the routes.
	 *
	 * @param Sections      $sections  The sections.
	 * @param callable|null $can_edit  Default: `current_user_can( 'edit_post', $id )`.
	 * @param callable|null $can_write Default: `current_user_can( 'edit_posts' )`.
	 */
	public function __construct( Sections $sections, $can_edit = null, $can_write = null ) {
		$this->sections  = $sections;
		$this->can_edit  = $can_edit ?? static function ( $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};
		$this->can_write = $can_write ?? static function () {
			return current_user_can( 'edit_posts' );
		};
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
			'/sections',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'can_write' ),
				'args'                => array(
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
					'page'   => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/sections/(?P<id>[0-9]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'state' ),
					'permission_callback' => array( $this, 'can' ),
					'args'                => array( 'id' => $id ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'mark' ),
					'permission_callback' => array( $this, 'can' ),
					'args'                => array(
						'id'      => $id,
						'section' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/sections/(?P<id>[0-9]+)/contents',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'id'  => $id,
					'ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/sections/(?P<id>[0-9]+)/contents/(?P<post>[0-9]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'id'   => $id,
					'post' => $id,
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/sections/(?P<id>[0-9]+)/contents/(?P<post>[0-9]+)/move',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'move' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'id'   => $id,
					'post' => $id,
					'to'   => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/sections/(?P<id>[0-9]+)/order',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'reset' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array( 'id' => $id ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/sections/(?P<id>[0-9]+)/candidates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'candidates' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'id'       => $id,
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'after'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( $this, 'sanitize_date' ),
					),
					'before'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( $this, 'sanitize_date' ),
					),
					'statuses' => array(
						'type'    => 'array',
						'default' => array( 'publish' ),
						'items'   => array( 'type' => 'string' ),
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);
	}

	/**
	 * A date `Y-m-d`, or an empty string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function sanitize_date( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}\z/', $value ) ? $value : '';
	}

	/**
	 * Whether the user may write posts (to search the sections).
	 *
	 * @return bool
	 */
	public function can_write() {
		return (bool) ( $this->can_write )();
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
	 * Searches the sections.
	 *
	 * @param object $request Request.
	 * @return array
	 */
	public function search( $request ) {
		return $this->sections->search( (string) $request->get_param( 'search' ), (int) $request->get_param( 'page' ) );
	}

	/**
	 * The state of a post: whether it is a section, its contents and its sections.
	 *
	 * @param object $request Request.
	 * @return array
	 */
	public function state( $request ) {
		return $this->sections->state( (int) $request->get_param( 'id' ) );
	}

	/**
	 * Marks or unmarks a post as a section.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	public function mark( $request ) {
		return $this->run(
			function () use ( $request ) {
				$id = (int) $request->get_param( 'id' );

				if ( $request->get_param( 'section' ) ) {
					$this->sections->mark( $id );
				} else {
					$this->sections->unmark( $id );
				}

				return $this->sections->state( $id );
			}
		);
	}

	/**
	 * Adds posts to a section.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	public function add( $request ) {
		return $this->run(
			function () use ( $request ) {
				$id     = (int) $request->get_param( 'id' );
				$report = $this->sections->add( $id, (array) $request->get_param( 'ids' ) );

				return array( 'report' => $report ) + $this->sections->state( $id );
			}
		);
	}

	/**
	 * Takes a post out of a section.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	public function remove( $request ) {
		return $this->run(
			function () use ( $request ) {
				$id      = (int) $request->get_param( 'id' );
				$removed = $this->sections->remove( $id, (int) $request->get_param( 'post' ) );

				return array( 'removed' => $removed ) + $this->sections->state( $id );
			}
		);
	}

	/**
	 * Moves a post: `up`, `down` or a rank.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	public function move( $request ) {
		return $this->run(
			function () use ( $request ) {
				$id = (int) $request->get_param( 'id' );
				$to = (string) $request->get_param( 'to' );

				$this->sections->move( $id, (int) $request->get_param( 'post' ), ctype_digit( $to ) ? (int) $to : $to );

				return $this->sections->state( $id );
			}
		);
	}

	/**
	 * Resets the order of a section to the dates.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	public function reset( $request ) {
		return $this->run(
			function () use ( $request ) {
				$id = (int) $request->get_param( 'id' );

				$this->sections->reset_order( $id );

				return $this->sections->state( $id );
			}
		);
	}

	/**
	 * The posts that can be added to a section.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	public function candidates( $request ) {
		return $this->run(
			function () use ( $request ) {
				return $this->sections->candidates(
					(int) $request->get_param( 'id' ),
					array(
						'search'   => (string) $request->get_param( 'search' ),
						'after'    => $this->sanitize_date( $request->get_param( 'after' ) ),
						'before'   => $this->sanitize_date( $request->get_param( 'before' ) ),
						'statuses' => (array) $request->get_param( 'statuses' ),
						'page'     => (int) $request->get_param( 'page' ),
						'per_page' => 50,
					)
				);
			}
		);
	}

	/**
	 * Runs a change and turns a refusal into a REST error.
	 *
	 * @param callable $work The change.
	 * @return array|\WP_Error
	 */
	private function run( $work ) {
		try {
			return $work();
		} catch ( SectionException $refusal ) {
			return new \WP_Error( $refusal->error_code(), $refusal->getMessage(), array( 'status' => $refusal->status() ) );
		}
	}
}
