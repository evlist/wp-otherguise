<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The routes that change the topic of one motif.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Rest;

use Otherguise\Index\Admin\TopicsScreen;
use Otherguise\Index\Designation\Assigner;
use Otherguise\Index\Designation\Exclusion;
use Otherguise\Index\Designation\Neighbours;
use Otherguise\Index\Designation\TopicFinder;
use Otherguise\Index\Designations;
use Otherguise\Index\Kinds;
use Otherguise\Index\Observations;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /index/v1/motifs/<id>/neighbours` and `POST /index/v1/motifs/<id>/topic` (slice 633): the quick edit of the table of the assignments. The first
 * says the topics that are close to the motif (the ones that a person would take it for); the second assigns it **by hand** to a topic. **When the kinds
 * of the topic it leaves and of the one it goes to exclude each other, nothing is changed** and the answer says so (`conflict`, with both kinds): the person
 * asks again with `force`. The capability of the module (`index_capability`) is required, and the nonce of the REST API comes with the request.
 */
final class AssignmentRoute {
	/**
	 * The motifs.
	 *
	 * @var Designations
	 */
	private $designations;

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * The assignments.
	 *
	 * @var Assigner
	 */
	private $assigner;

	/**
	 * The close topics.
	 *
	 * @var Neighbours
	 */
	private $neighbours;

	/**
	 * The finder.
	 *
	 * @var TopicFinder
	 */
	private $finder;

	/**
	 * Takes motifs out of the index.
	 *
	 * @var Exclusion|null
	 */
	private $exclusion;

	/**
	 * Tells whether the current user may: `fn(): bool`.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Builds the routes.
	 *
	 * @param Designations   $designations The motifs.
	 * @param Topics         $topics       The topics.
	 * @param Assigner       $assigner     The assigner.
	 * @param Neighbours     $neighbours   The close topics.
	 * @param TopicFinder    $finder       The finder.
	 * @param callable|null  $can          Default: the capability of the module.
	 * @param Exclusion|null $exclusion   Takes a motif out of the index (slice 635).
	 */
	public function __construct( Designations $designations, Topics $topics, Assigner $assigner, Neighbours $neighbours, TopicFinder $finder, $can = null, ?Exclusion $exclusion = null ) {
		$this->exclusion    = $exclusion;
		$this->designations = $designations;
		$this->topics       = $topics;
		$this->assigner     = $assigner;
		$this->neighbours   = $neighbours;
		$this->finder       = $finder;
		$this->can          = $can ?? static function () {
			return current_user_can( (string) apply_filters( 'index_capability', 'edit_others_posts' ) );
		};
	}

	/**
	 * Registers the routes. Action `rest_api_init`.
	 *
	 * @return void
	 */
	public function register() {
		$id = array(
			'type'     => 'integer',
			'required' => true,
		);

		register_rest_route(
			TopicsRoute::REST_NAMESPACE,
			'/motifs/(?P<id>\d+)/neighbours',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'neighbours' ),
				'permission_callback' => $this->can,
				'args'                => array( 'id' => $id ),
			)
		);

		register_rest_route(
			TopicsRoute::REST_NAMESPACE,
			'/motifs/(?P<id>\d+)/topic',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'assign' ),
				'permission_callback' => $this->can,
				'args'                => array(
					'id'    => $id,
					'topic'   => array( 'type' => 'integer' ),
					'force'   => array( 'type' => 'boolean' ),
					'exclude' => array( 'type' => 'boolean' ),
				),
			)
		);
	}

	/**
	 * The topics that are close to the motif.
	 *
	 * @param object $request The request (`get_param`).
	 * @return array<int, array{id:int,label:string,kind:string,chain:string}>
	 */
	public function neighbours( $request ) {
		return $this->neighbours->of( absint( $request->get_param( 'id' ) ) );
	}

	/**
	 * Assigns the motif to the topic, by hand.
	 *
	 * @param object $request The request (`get_param`).
	 * @return array<string, mixed> `ok`, then the topic and its posts, or `error` (and `conflict` with the two kinds when they exclude each other); `excluded` when the
	 *         motif was taken out of the index.
	 */
	public function assign( $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$topic  = absint( $request->get_param( 'topic' ) );
		$target = $this->topics->get( $topic );

		// Take it out of the index: no topic, and a reading leaves it alone (slice 635).
		if ( (bool) $request->get_param( 'exclude' ) && null !== $this->exclusion && null !== $this->designations->get( $id ) ) {
			$this->exclusion->exclude( array( $id ) );

			return array(
				'ok'       => true,
				'excluded' => true,
				'by'       => Observations::HAND,
				'state'    => 'excluded',
				'status'   => '',
			);
		}

		if ( null === $this->designations->get( $id ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'This motif does not exist any more: reload the page.', 'otherguise' ),
			);
		}

		if ( null === $target ) {
			return array(
				'ok'    => false,
				'error' => __( 'Choose a topic.', 'otherguise' ),
			);
		}

		$done = $this->assigner->assign( array( $id ), $topic, false, (bool) $request->get_param( 'force' ) );

		if ( ! $done['ok'] ) {
			$conflict = (array) $done['conflict'];

			return array(
				'ok'       => false,
				'conflict' => array() !== $conflict,
				'error'    => array() === $conflict ? (string) $done['error'] : sprintf(
					/* translators: 1: the kind of the topic that the motif has, 2: the kind of the topic chosen. */
					__( 'The topic that it has is a %1$s, the one you chose is a %2$s: they cannot be one a sort of the other.', 'otherguise' ),
					(string) Kinds::label( (string) $conflict['was'] ),
					(string) Kinds::label( (string) $conflict['target'] )
				),
			);
		}

		return array(
			'ok'    => true,
			'topic' => array(
				'id'    => $topic,
				'label' => (string) $target['label'],
				'kind'  => (string) Kinds::label( (string) $target['kind'] ),
				'chain' => $this->finder->chain( $target ),
				'url'   => add_query_arg(
					array(
						'page'  => TopicsScreen::PAGE,
						'topic' => $topic,
					),
					admin_url( 'admin.php' )
				),
			),
			'by'     => Observations::HAND,
			'state'  => 'assigned',
			'status' => (string) $target['status'],
			'posts' => (int) $done['posts'],
		);
	}
}
