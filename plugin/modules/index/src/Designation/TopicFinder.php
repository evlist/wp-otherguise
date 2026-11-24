<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * How a person names a topic.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Designation;

use Otherguise\Index\Folding;
use Otherguise\Index\Kinds;
use Otherguise\Index\Topics;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a topic by what a person types (slices 628 and 630): its number, or its label; and **searches** the topics for a field with suggestions, each with
 * its kind and the chain of its broader topics, which tells *Moulins (Allier)* from *Moulins* the river.
 */
final class TopicFinder {
	/**
	 * The topics.
	 *
	 * @var Topics
	 */
	private $topics;

	/**
	 * Builds the finder.
	 *
	 * @param Topics $topics The topics.
	 */
	public function __construct( Topics $topics ) {
		$this->topics = $topics;
	}

	/**
	 * Finds the topic that a person means by a number or a label.
	 *
	 * @param string $text A number, or a label.
	 * @return array{id:int,error:string}
	 */
	public function find( $text ) {
		$text = trim( (string) $text );

		if ( ctype_digit( $text ) && null !== $this->topics->get( (int) $text ) ) {
			return array(
				'id'    => (int) $text,
				'error' => '',
			);
		}

		$found = array();

		foreach ( $this->topics->all() as $id => $topic ) {
			if ( '' !== $text && Folding::key( (string) $topic['label'] ) === Folding::key( $text ) ) {
				$found[] = (int) $id;
			}
		}

		if ( 1 === count( $found ) ) {
			return array(
				'id'    => $found[0],
				'error' => '',
			);
		}

		return array(
			'id'    => 0,
			/* translators: %s: what was typed. */
			'error' => array() === $found ? sprintf( __( 'No topic is called "%s": type its number, or make a new topic.', 'otherguise' ), $text ) : sprintf( __( 'Several topics are called "%1$s": type the number of one of them (%2$s).', 'otherguise' ), $text, implode( ', ', $found ) ),
		);
	}

	/**
	 * The topics whose label contains what was typed (accents and case ignored), the ones that begin with it first.
	 *
	 * @param string $text  What was typed.
	 * @param int    $limit At most this many.
	 * @return array<int, array{id:int,label:string,kind:string,chain:string}>
	 */
	public function search( $text, $limit = 20 ) {
		$needle = Folding::key( trim( (string) $text ) );

		if ( '' === $needle ) {
			return array();
		}

		$first = array();
		$then  = array();

		foreach ( $this->topics->all() as $id => $topic ) {
			$where = strpos( Folding::key( (string) $topic['label'] ), $needle );

			if ( false === $where ) {
				continue;
			}

			$line = array(
				'id'    => (int) $id,
				'label' => (string) $topic['label'],
				'kind'  => (string) Kinds::label( (string) $topic['kind'] ),
				'chain' => $this->chain( $topic ),
			);

			if ( 0 === $where ) {
				$first[] = $line;
			} else {
				$then[] = $line;
			}
		}

		$by_label = static fn( $a, $b ) => strcmp( Folding::key( $a['label'] ), Folding::key( $b['label'] ) );

		usort( $first, $by_label );
		usort( $then, $by_label );

		return array_slice( array_merge( $first, $then ), 0, max( 1, (int) $limit ) );
	}

	/**
	 * What says where a topic is: its broader topics, from the highest.
	 *
	 * @param array $topic A topic.
	 * @return string
	 */
	public function chain( array $topic ) {
		$names = array();
		$up    = (int) $topic['parent'];

		for ( $level = 0; $up > 0 && $level < 8; $level++ ) {
			$above = $this->topics->get( $up );

			if ( null === $above ) {
				break;
			}

			array_unshift( $names, (string) $above['label'] );
			$up = (int) $above['parent'];
		}

		return implode( ' > ', $names );
	}
}
