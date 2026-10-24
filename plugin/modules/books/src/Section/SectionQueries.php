<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the panel and the commands read about a section.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Section;

defined( 'ABSPATH' ) || exit;

/**
 * The reads of the sections (slices 223 and 224): the state of a post, the search among the sections, the posts that can be added and the
 * images of the posts of a section. They only read; every change is in `Sections`, which delegates to this class.
 */
final class SectionQueries {
	public const MAX_THUMBS = 20;

	/**
	 * The sections.
	 *
	 * @var Sections
	 */
	private $sections;

	/**
	 * The posts.
	 *
	 * @var PostStore
	 */
	private $posts;

	/**
	 * Gives the address of the print version of a post from its address, or an empty string.
	 *
	 * @var callable|null
	 */
	private $print_address;

	/**
	 * Builds the reads.
	 *
	 * @param Sections      $sections      The sections.
	 * @param PostStore     $posts         The posts.
	 * @param callable|null $print_address Gives the address of the print version from the address of a post, or an empty string.
	 */
	public function __construct( Sections $sections, PostStore $posts, $print_address = null ) {
		$this->sections      = $sections;
		$this->posts         = $posts;
		$this->print_address = $print_address;
	}

	/**
	 * The address of the print version of a post, from its address (the permalink, or the preview link of a post that is not published).
	 *
	 * @param string $address Address of the post.
	 * @return string An empty string when there is none.
	 */
	private function print_of( $address ) {
		if ( null === $this->print_address || '' === $address ) {
			return '';
		}

		$print = ( $this->print_address )( $address );

		return is_string( $print ) ? $print : '';
	}

	/**
	 * The state of a post for the panel: whether it is a section, what it contains in order, and the sections that contain it.
	 *
	 * @param int $id Id of the post.
	 * @return array
	 */
	public function state( $id ) {
		$id       = (int) $id;
		$entries  = $this->sections->entries( $id );
		$contents = array();
		$dates    = array();

		foreach ( $entries as $entry ) {
			$info = $this->posts->info( $entry['id'], true );

			if ( null !== $info ) {
				$info['print'] = $this->print_of( $info['view'] ?? '' );
				$contents[]    = $info + array( 'pinned' => $entry['pinned'] );
				$dates[]       = $info['date'];
			}
		}

		$containing = array();

		foreach ( $this->sections->containing( $id ) as $section ) {
			$info = $this->posts->info( $section );

			if ( null !== $info && $this->posts->can_read( $section ) ) {
				$containing[] = $info + array( 'can_edit' => $this->posts->can_edit( $section ) );
			}
		}

		$self = $this->posts->info( $id );

		return array(
			'id'         => $id,
			'title'      => null === $self ? '' : $self['title'],
			'section'    => $this->sections->is_section( $id ) || array() !== $contents,
			'range'      => array(
				'from' => array() === $dates ? '' : min( $dates ),
				'to'   => array() === $dates ? '' : max( $dates ),
			),
			'contents'   => $contents,
			'containing' => $containing,
		);
	}

	/**
	 * The addresses of the featured images of posts of a section (at most `MAX_THUMBS` at a time), by post id; an empty string for a post
	 * that has none. Only the posts of the section are answered.
	 *
	 * @param int   $section Id of the section.
	 * @param array $ids     Ids of the posts.
	 * @return array<int,string>
	 * @throws SectionException When the user may not edit the section or too many posts are asked for.
	 */
	public function thumbs( $section, array $ids ) {
		$section = (int) $section;

		$this->sections->editable( $section );

		$wanted = array();

		foreach ( $ids as $id ) {
			if ( ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) && (int) $id > 0 ) {
				$wanted[ (int) $id ] = (int) $id;
			}
		}

		if ( count( $wanted ) > self::MAX_THUMBS ) {
			SectionException::refuse( SectionException::TOO_MANY );
		}

		$inside = array_flip( $this->sections->order( $section ) );

		return $this->posts->thumbs( array_values( array_filter( $wanted, static fn( $id ) => isset( $inside[ $id ] ) ) ) );
	}

	/**
	 * Searches among the sections the user may edit.
	 *
	 * @param string $text     Text, possibly empty.
	 * @param int    $page     Page from 1.
	 * @param int    $per_page Results per page, at most 50.
	 * @return array{items:array,total:int}
	 */
	public function search( $text, $page = 1, $per_page = 20 ) {
		$found = $this->posts->search_among( $this->sections->marked(), (string) $text, max( 1, (int) $page ), min( 50, max( 1, (int) $per_page ) ) );
		$items = array();

		foreach ( $found['ids'] as $id ) {
			$info = $this->posts->info( $id );

			if ( null !== $info && $this->posts->can_edit( $id ) ) {
				$items[] = $info;
			}
		}

		return array(
			'items' => $items,
			'total' => $found['total'],
		);
	}

	/**
	 * The posts that can be added to a section, with whether they are in it already.
	 *
	 * @param int   $section   Id of the section.
	 * @param array $arguments Criteria of `PostStore::candidates()`.
	 * @return array{items:array,total:int}
	 * @throws SectionException When the user may not edit the section.
	 */
	public function candidates( $section, array $arguments ) {
		$section = (int) $section;

		$this->sections->editable( $section );

		$found = $this->posts->candidates( $arguments );
		$in    = array_fill_keys( $this->sections->order( $section ), true );

		$this->posts->prime( $found['ids'] );
		$items = array();

		foreach ( $found['ids'] as $id ) {
			$info = $this->posts->info( $id );

			if ( null !== $info && $id !== $section && $this->posts->can_read( $id ) ) {
				$items[] = $info + array( 'in_section' => isset( $in[ $id ] ) );
			}
		}

		return array(
			'items' => $items,
			'total' => $found['total'],
		);
	}
}
