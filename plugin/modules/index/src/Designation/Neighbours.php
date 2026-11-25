<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The topics that are close to a motif.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Designations;
use Otherguise\Index\Folding;
use Otherguise\Index\Observations;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * The topics to offer first when a person changes the topic of a motif (slice 633): the ones that **a person would take it for**, found like the first
 * rule of the suggestions of doubles (slice 616), **the same name once what is in parentheses is left out** (*aisne (02)* and *Aisne (affluent de l'Oise)*),
 * from the label of the motif **and** from the label of the topic it is assigned to. The topic it is assigned to is not offered. The rule of the
 * letters that differ is **not** used here: it compares every topic with every other, which is too heavy for a click.
 */
final class Neighbours {
	/**
	 * The motifs.
	 *
	 * @var Designations
	 */
	private $designations;

	/**
	 * The observations and assignments.
	 *
	 * @var Observations
	 */
	private $observations;

	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * The finder.
	 *
	 * @var TopicFinder
	 */
	private $finder;

	/**
	 * Builds the finder of neighbours.
	 *
	 * @param Designations $designations The motifs.
	 * @param Observations $observations The observations.
	 * @param Topics       $topics       The topics.
	 * @param TopicFinder  $finder       The finder.
	 */
	public function __construct( Designations $designations, Observations $observations, Topics $topics, TopicFinder $finder ) {
		$this->designations = $designations;
		$this->observations = $observations;
		$this->topics       = $topics;
		$this->finder       = $finder;
	}

	/**
	 * The close topics, the closest first.
	 *
	 * @param int $id    The motif.
	 * @param int $limit At most this many.
	 * @return array<int, array{id:int,label:string,kind:string,chain:string}>
	 */
	public function of( $id, $limit = 12 ) {
		$one  = $this->designations->get( (int) $id );
		$have = $this->observations->assignment( (int) $id );

		if ( null === $one ) {
			return array();
		}

		$current = null === $have ? 0 : (int) $have['topic'];
		$names   = array( self::bare( (string) ( $one['evidence']['label'] ?? $one['key'] ) ) );

		if ( $current > 0 && null !== $this->topics->get( $current ) ) {
			$names[] = self::bare( (string) $this->topics->get( $current )['label'] );
		}

		$names = array_values( array_unique( array_filter( $names, static fn( $name ) => strlen( $name ) >= 2 ) ) );
		$found = array();

		foreach ( $names as $name ) {
			foreach ( $this->finder->search( $name, 40 ) as $line ) {
				$topic = $this->topics->get( (int) $line['id'] );

				if ( (int) $line['id'] === $current || null === $topic || Topics::REFUSED === $topic['status'] ) {
					continue;
				}

				$bare  = self::bare( (string) $topic['label'] );
				$close = $bare === $name ? 0 : ( 0 === strpos( $bare, $name ) ? 1 : 2 );

				if ( ! isset( $found[ $line['id'] ] ) || $close < $found[ $line['id'] ]['close'] ) {
					$found[ $line['id'] ] = array(
						'close' => $close,
						'line'  => $line,
					);
				}
			}
		}

		uasort( $found, static fn( $a, $b ) => $a['close'] <=> $b['close'] );

		return array_slice( array_values( array_column( $found, 'line' ) ), 0, max( 1, (int) $limit ) );
	}

	/**
	 * A label without what is in parentheses, folded.
	 *
	 * @param string $label A label.
	 * @return string
	 */
	private static function bare( $label ) {
		return Folding::key( trim( (string) preg_replace( '/\s*\([^)]*\)/u', '', $label ) ) );
	}
}
