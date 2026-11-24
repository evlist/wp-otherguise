<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What `Mentions` does to take mentions away (slices 619 to 623).
 *
 * @package Otherguise
 */

namespace Otherguise\Index;

defined( 'ABSPATH' ) || exit;

/**
 * The removal of mentions: of a source, of every one, and the questions that precede it. A trait of `Mentions` (it uses its private helpers `statements()`
 * and `withdraw()`), kept apart so that the class stays under the size limit of a file.
 */
trait MentionsDropping {
	/**
	 * Removes a source from every mention (slice 620): what the source said is withdrawn (its `via`, its visibility), and **a mention that no other source
	 * holds is deleted**. The source stands for its sub-sources (`image` is `image/address` and the others). With `$dry` nothing is changed.
	 *
	 * @param string $source The source (a path).
	 * @param bool   $dry    Count only.
	 * @return array{mentions:int,orphaned:int[]} How many mentions had the source, and the topics that no source mentions any more.
	 */
	public function drop_source( $source, $dry = false ) {
		$statements = $this->statements();

		return $statements->transaction(
			function () use ( $statements, $source, $dry ) {
				$mentions  = $statements->match( null, self::MENTIONS );
				$about     = $statements->qualifications_of( $mentions );
				$hit       = 0;
				$touched   = array();
				$remaining = array();

				foreach ( $mentions as $mention ) {
					$topic   = (int) $mention->object()->id();
					$sources = array();

					foreach ( $about[ $mention->id() ][ self::SOURCE ] ?? array() as $statement ) {
						$sources[] = (string) $statement->object()->key();
					}

					$inside = array_values( array_filter( $sources, static fn( $one ) => Sources::is_a( $one, (string) $source ) ) );

					if ( array() === $inside ) {
						$remaining[ $topic ] = true;

						continue;
					}

					++$hit;
					$touched[ $topic ] = true;

					if ( count( $inside ) < count( $sources ) ) {
						$remaining[ $topic ] = true;
					}

					if ( $dry ) {
						continue;
					}

					foreach ( $inside as $one ) {
						$this->withdraw( $mention, $one );
					}

					if ( count( $inside ) === count( $sources ) ) {
						$statements->delete( $mention );
					}
				}

				// What a source saw goes with what it said (slice 626): its observations, not its designations (a decision of a person stays).
				if ( null !== $this->observations ) {
					$this->observations->drop_source( (string) $source, $dry );
				}

				return array(
					'mentions' => $hit,
					'orphaned' => array_keys( array_diff_key( $touched, $remaining ) ),
				);
			}
		);
	}

	/**
	 * The topics that a mention still holds once some sources are dropped (slice 623: what the dry run of `index reset` says would stay).
	 *
	 * @param string[] $sources The sources that would be dropped (paths, each standing for its sub-sources).
	 * @return array<int, true> The ids of the topics.
	 */
	public function mentioned_beyond( array $sources ) {
		$statements = $this->statements();
		$mentions   = $statements->match( null, self::MENTIONS );
		$about      = $statements->qualifications_of( $mentions );
		$found      = array();

		foreach ( $mentions as $mention ) {
			foreach ( $about[ $mention->id() ][ self::SOURCE ] ?? array() as $statement ) {
				$one = (string) $statement->object()->key();

				if ( array() === array_filter( $sources, static fn( $dropped ) => Sources::is_a( $one, (string) $dropped ) ) ) {
					$found[ (int) $mention->object()->id() ] = true;
				}
			}
		}

		return $found + $this->held_topics();
	}

	/**
	 * Deletes every mention, whoever made it (slice 623: `index reset --everything`).
	 *
	 * @param bool $dry Count only.
	 * @return int How many mentions there were.
	 */
	public function drop_all( $dry = false ) {
		$statements = $this->statements();

		return (int) $statements->transaction(
			function () use ( $statements, $dry ) {
				$mentions = $statements->match( null, self::MENTIONS );

				if ( ! $dry ) {
					foreach ( $mentions as $mention ) {
						$statements->delete( $mention );
					}
				}

				if ( null !== $this->observations ) {
					$this->observations->drop_source( '', $dry );
				}

				return count( $mentions );
			}
		);
	}

	/**
	 * The topics that at least one post mentions, in one query (slice 619: the cleaning of the topics nobody mentions).
	 *
	 * @return array<int, true> The ids of the topics.
	 */
	public function mentioned_topics() {
		$found = array();

		foreach ( $this->statements()->match( null, self::MENTIONS ) as $mention ) {
			$found[ (int) $mention->object()->id() ] = true;
		}

		return $found + $this->held_topics();
	}

	/**
	 * The topics that a decision of a person holds (slice 626): a designation that a person assigned to a topic keeps it, observed or not, so that the
	 * cleaning of the topics that nobody mentions does not leave the decision pointing at nothing.
	 *
	 * @return array<int, true>
	 */
	private function held_topics() {
		return null === $this->observations ? array() : $this->observations->manual_topics();
	}
}
