<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Turns the HTML of a page into signals.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Harvest;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a rendered page (slice 601): a **pure function** of a string, with no fetching, no database and no network.
 *
 * The scopes, learnt from three pages of Eric's blog:
 *
 * - **the page**: inside the element `main` (the whole document when there is none);
 * - **the contents of the post**: inside the element of class `wp-block-post-content` or `topic-content` (the page when there is none).
 *   The generic readers of links read only the contents, so that a menu, a footer or a list of recent posts is not taken for a mention;
 * - **not read** (links, files, images): `nav`, `header`, `footer`, `aside`, `#comments`, the lists of other posts (`wp-block-query`,
 *   `wp-block-latest-posts`, `wp-block-comments`);
 * - **never read at all**: whatever is under an element with `data-index-ignore`.
 *
 * Explicit marks (`data-index`) are trusted everywhere except under `data-index-ignore`. The parser is the tag processor of WordPress with
 * a stack of open elements kept here (the HTML processor of the core refuses some markup and is not in every version): it is tolerant, and
 * exact for the well-formed markup that WordPress prints.
 */
final class PageReader {
	private const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	private const SKIPPED_TAGS = array( 'nav', 'header', 'footer', 'aside' );

	private const SKIPPED_CLASSES = array( 'wp-block-query', 'wp-block-latest-posts', 'wp-block-comments', 'wp-block-post-comments-form' );

	private const IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'heic', 'heif', 'webp', 'tif', 'tiff' );

	private const IDENTIFIER = '/^(wikidata|osm|geonames|wikipedia):\S+$/i';

	private const NAMESPACES = '/^(special|spécial|file|fichier|image|category|catégorie|help|aide|wikipedia|wikipédia|portal|portail|template|modèle|talk|discussion|user|utilisateur):/iu';

	/**
	 * Reads a page.
	 *
	 * @param string $html    The HTML of the page (or of a part of it).
	 * @param int    $post_id The post, 0 when unknown (kept for the signature of the harvesters of the next slices).
	 * @return Signal[] In the order of the document.
	 */
	public function signals( $html, $post_id = 0 ) {
		unset( $post_id );

		return $this->scan( (string) $html, null )['signals'];
	}

	/**
	 * Reads a page and stamps the elements that carry a signal the caller wants (slice 612): the attribute `data-index-mention` is set, on the element,
	 * to what the callback says of the first of its signals that it wants. Only those elements are touched, so the cost stays near that of a plain reading.
	 *
	 * @param string   $html  The HTML of the page (or of a part of it).
	 * @param callable $value Receives a signal, returns the value of the attribute for it, or an empty string to leave its element alone.
	 * @return string The HTML, stamped.
	 */
	public function stamped( $html, $value ) {
		return $this->scan( (string) $html, $value )['html'];
	}

	/**
	 * Reads a page, and stamps the elements when asked.
	 *
	 * @param string        $html  The HTML.
	 * @param callable|null $stamp Receives a signal, returns the value to stamp on its element, or an empty string; null: no stamp.
	 * @return array{html:string, signals:Signal[]}
	 */
	private function scan( $html, $stamp ) {
		if ( '' === $html || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return array(
				'html'    => $html,
				'signals' => array(),
			);
		}

		$has_main    = false !== stripos( $html, '<main' );
		$has_content = (bool) preg_match( '/class\s*=\s*["\'][^"\']*\b(wp-block-post-content|topic-content)\b/i', $html );
		$tags        = new \WP_HTML_Tag_Processor( $html );
		$stack       = array();
		$signals     = array();

		while ( $tags->next_token() ) {
			if ( '#tag' !== $tags->get_token_type() ) {
				continue;
			}

			$tag = strtolower( (string) $tags->get_tag() );

			if ( $tags->is_tag_closer() ) {
				$this->close( $stack, $tag );

				continue;
			}

			$parent = array() === $stack ? array(
				'ignored' => false,
				'skipped' => false,
				'main'    => ! $has_main,
				'content' => ! $has_content,
				'image'   => '',
			) : $stack[ count( $stack ) - 1 ];

			$state = $this->state( $tags, $tag, $parent );

			$before = count( $signals );

			$this->read( $tags, $tag, $state, $signals );

			$after = count( $signals );

			for ( $at = $before; null !== $stamp && $at < $after; $at++ ) {
				$wanted = (string) $stamp( $signals[ $at ] );

				if ( '' !== $wanted ) {
					$tags->set_attribute( 'data-index-mention', $wanted );
					break;
				}
			}

			if ( ! in_array( $tag, self::VOID, true ) ) {
				$state['tag'] = $tag;
				$stack[]      = $state;
			}
		}

		return array(
			'html'    => null !== $stamp ? $tags->get_updated_html() : $html,
			'signals' => $signals,
		);
	}

	/**
	 * The scopes in force inside an element.
	 *
	 * @param \WP_HTML_Tag_Processor $tags   The processor, on the element.
	 * @param string                 $tag    Its name.
	 * @param array<string, mixed>   $parent The scopes in force around it.
	 * @return array<string, mixed>
	 */
	private function state( $tags, $tag, array $parent ) {
		$state = $parent;

		if ( null !== $tags->get_attribute( 'data-index-ignore' ) ) {
			$state['ignored'] = true;
		}

		if ( in_array( $tag, self::SKIPPED_TAGS, true ) || 'comments' === $tags->get_attribute( 'id' ) ) {
			$state['skipped'] = true;
		}

		foreach ( self::SKIPPED_CLASSES as $class ) {
			if ( $tags->has_class( $class ) ) {
				$state['skipped'] = true;
			}
		}

		if ( 'main' === $tag ) {
			$state['main'] = true;
		}

		if ( $tags->has_class( 'wp-block-post-content' ) || $tags->has_class( 'topic-content' ) ) {
			$state['content'] = true;
		}

		return $state;
	}

	/**
	 * Pops the stack down to the element a closing tag closes (a closing tag that matches nothing is ignored).
	 *
	 * @param array<int, array<string, mixed>> $stack The stack.
	 * @param string                           $tag   The closing tag.
	 * @return void
	 */
	private function close( array &$stack, $tag ) {
		for ( $i = count( $stack ) - 1; $i >= 0; --$i ) {
			if ( $stack[ $i ]['tag'] === $tag ) {
				array_splice( $stack, $i );

				return;
			}
		}
	}

	/**
	 * Reads the signals of one element.
	 *
	 * @param \WP_HTML_Tag_Processor $tags    The processor, on the element.
	 * @param string                 $tag     Its name.
	 * @param array<string, mixed>   $state   The scopes in force on it (it is modified: the address of an image link).
	 * @param Signal[]               $signals Receives the signals.
	 * @return void
	 */
	private function read( $tags, $tag, array &$state, array &$signals ) {
		if ( $state['ignored'] ) {
			return;
		}

		$scope = $state['content'] && $state['main'] ? 'content' : 'page';

		// An explicit mark is trusted wherever it is, except under `data-index-ignore`.
		$this->read_marks( $tags, $signals );

		if ( $state['skipped'] || ! $state['main'] ) {
			return;
		}

		foreach ( $this->addresses( $tags ) as $attribute => $address ) {
			if ( $this->is_gpx( $address ) ) {
				$signals[] = new Signal( Signal::GPX_FILE, $address, $scope, $attribute );
			}
		}

		if ( 'a' === $tag ) {
			$href = $tags->get_attribute( 'href' );
			$href = is_string( $href ) ? trim( $href ) : '';

			if ( '' !== $href && $this->is_image( $href ) && false === strpos( (string) wp_parse_url( $href, PHP_URL_PATH ), '/wiki/' ) ) {
				$signals[]      = new Signal( Signal::IMAGE, $href, $scope, 'href' );
				$state['image'] = $href;
			} elseif ( '' !== $href && $state['content'] ) {
				$this->read_link( $href, $scope, $signals );
			}
		}

		if ( 'img' === $tag && '' === $state['image'] ) {
			$src = $tags->get_attribute( 'src' );
			$src = is_string( $src ) ? trim( $src ) : '';

			if ( '' !== $src && 0 !== stripos( $src, 'data:' ) ) {
				$signals[] = new Signal( Signal::IMAGE, $src, $scope, 'src' );
			}
		}
	}

	/**
	 * The explicit marks: `data-index`, in **two forms** (slice 636). The text form, `data-index="wikidata:Q9"` or `data-index="Beaufort > Les Arberons | Doron"`,
	 * with its companions `data-index-kind`, `-label`, `-sort`, `-main`, `-modes` and `-source`; and **the JSON form**, a value that starts with `{` (an object) or `[`
	 * (a list of objects): `{"topic":"wikidata:Q9","kind":"species","label":"Merle noir","source":"whobird"}`, where `topic` (or `id`) is what the text form holds
	 * and the other keys are the companions. **In the JSON form the keys win over the companions**, which fill what it lacks. A JSON that does not parse says nothing.
	 *
	 * @param \WP_HTML_Tag_Processor $tags    The processor, on the element.
	 * @param Signal[]               $signals Receives the signals.
	 * @return void
	 */
	private function read_marks( $tags, array &$signals ) {
		$mark = $tags->get_attribute( 'data-index' );

		if ( ! is_string( $mark ) || '' === trim( $mark ) ) {
			return;
		}

		$id        = $tags->get_attribute( 'id' );
		$anchor    = is_string( $id ) ? $id : '';
		$companion = self::fields(
			array(
				'kind'   => $tags->get_attribute( 'data-index-kind' ),
				'label'  => $tags->get_attribute( 'data-index-label' ),
				'sort'   => $tags->get_attribute( 'data-index-sort' ),
				'source' => $tags->get_attribute( 'data-index-source' ),
				'modes'  => $tags->get_attribute( 'data-index-modes' ),
				'main'   => null !== $tags->get_attribute( 'data-index-main' ),
			)
		);
		$text      = trim( $mark );

		if ( '{' !== $text[0] && '[' !== $text[0] ) {
			$this->add_topics( $signals, $text, $companion, $anchor );

			return;
		}

		$items = json_decode( $text, true );

		if ( ! is_array( $items ) ) {
			return;
		}

		// An object is one mark; a list is several.
		$items = isset( $items[0] ) ? $items : array( $items );

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$this->add_topics( $signals, trim( (string) ( $item['topic'] ?? $item['id'] ?? '' ) ), array_merge( $companion, self::fields( $item ) ), $anchor );
			}
		}
	}

	/**
	 * The fields of a mark, cleaned: the strings trimmed (empty ones dropped), the source a slug of at most 31 characters (letters, digits, hyphens), the flag
	 * `main`, the modes as slugs. The same for the companion attributes and for the keys of the JSON form.
	 *
	 * @param array<string, mixed> $raw `kind`, `label`, `sort`, `source`, `modes` (a string or a list) and `main`.
	 * @return array<string, mixed>
	 */
	private static function fields( array $raw ) {
		$fields = array();

		foreach ( array( 'kind', 'label', 'sort' ) as $name ) {
			if ( isset( $raw[ $name ] ) && is_string( $raw[ $name ] ) && '' !== trim( $raw[ $name ] ) ) {
				$fields[ $name ] = trim( $raw[ $name ] );
			}
		}

		$source = isset( $raw['source'] ) && is_string( $raw['source'] ) ? strtolower( trim( $raw['source'] ) ) : '';

		if ( 1 === preg_match( '/^[a-z][a-z0-9-]{0,30}$/', $source ) ) {
			$fields['source'] = $source;
		}

		if ( true === ( $raw['main'] ?? false ) || 'true' === ( $raw['main'] ?? '' ) || 1 === ( $raw['main'] ?? 0 ) ) {
			$fields['main'] = true;
		}

		// The modes in which the mark is shown (slice 605), as slugs; absent: every mode.
		$modes = $raw['modes'] ?? null;
		$modes = is_array( $modes ) ? implode( ' ', array_map( 'strval', array_filter( $modes, 'is_scalar' ) ) ) : $modes;

		if ( is_string( $modes ) ) {
			$words = preg_split( '/[\s,]+/', strtolower( $modes ) );
			$slugs = array_values( array_filter( false === $words ? array() : $words, static fn( $slug ) => 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', $slug ) ) );

			if ( array() !== $slugs ) {
				$fields['modes'] = array_values( array_unique( $slugs ) );
			}
		}

		return $fields;
	}

	/**
	 * Makes the signals of the topics that a mark names (`a | b > c`): an identifier, or a path.
	 *
	 * @param Signal[]             $signals Receives the signals.
	 * @param string               $value   What the mark names.
	 * @param array<string, mixed> $extra   The fields of the mark.
	 * @param string               $anchor  The `id` of the element.
	 * @return void
	 */
	private function add_topics( array &$signals, $value, array $extra, $anchor ) {
		foreach ( explode( '|', (string) $value ) as $part ) {
			$path = array_values( array_filter( array_map( 'trim', explode( '>', $part ) ), 'strlen' ) );

			if ( array() === $path ) {
				continue;
			}

			$data               = $extra;
			$data['path']       = $path;
			$data['identifier'] = 1 === count( $path ) && 1 === preg_match( self::IDENTIFIER, $path[0] );

			$signals[] = new Signal( Signal::TOPIC, implode( ' > ', $path ), 'page', 'data-index', $data, $anchor );
		}
	}

	/**
	 * Reads a link of the contents: Wikipedia, Wikidata, an OpenStreetMap object. The label always comes from the address, never from the
	 * text of the link.
	 *
	 * @param string   $href    The address.
	 * @param string   $scope   The scope.
	 * @param Signal[] $signals Receives the signals.
	 * @return void
	 */
	private function read_link( $href, $scope, array &$signals ) {
		$parts = wp_parse_url( 0 === strpos( $href, '//' ) ? 'https:' . $href : $href );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '';

		if ( '' === $host ) {
			return;
		}

		if ( 1 === preg_match( '/^(?:([a-z-]+)\.)?(?:m\.)?wikipedia\.org$/', $host, $found ) && '' !== (string) ( $found[1] ?? '' ) ) {
			$title = '';

			if ( 0 === strpos( $path, '/wiki/' ) ) {
				$title = substr( $path, 6 );
			} elseif ( '/w/index.php' === $path && isset( $parts['query'] ) ) {
				parse_str( (string) $parts['query'], $query );
				$title = isset( $query['title'] ) && is_string( $query['title'] ) ? $query['title'] : '';
			}

			$title = trim( str_replace( '_', ' ', rawurldecode( $title ) ) );

			if ( '' !== $title && 1 !== preg_match( self::NAMESPACES, $title ) ) {
				$signals[] = new Signal( Signal::WIKIPEDIA_LINK, $title, $scope, 'href', array( 'language' => $found[1] ) );
			}

			return;
		}

		if ( 1 === preg_match( '/^(?:www\.)?wikidata\.org$/', $host ) && 1 === preg_match( '#^/(?:wiki|entity)/(Q\d+)$#', $path, $found ) ) {
			$signals[] = new Signal( Signal::WIKIDATA_LINK, $found[1], $scope, 'href' );

			return;
		}

		if ( 1 === preg_match( '/^(?:www\.)?openstreetmap\.org$/', $host ) && 1 === preg_match( '#^/(node|way|relation)/(\d+)$#', $path, $found ) ) {
			$signals[] = new Signal( Signal::OSM_OBJECT, $found[1] . '/' . $found[2], $scope, 'href' );
		}
	}

	/**
	 * The addresses an element carries: `href`, `src` and the `data-*` attributes.
	 *
	 * @param \WP_HTML_Tag_Processor $tags The processor, on the element.
	 * @return array<string, string>
	 */
	private function addresses( $tags ) {
		$found = array();

		foreach ( (array) $tags->get_attribute_names_with_prefix( '' ) as $name ) {
			if ( 'href' !== $name && 'src' !== $name && 0 !== strpos( $name, 'data-' ) ) {
				continue;
			}

			$value = $tags->get_attribute( $name );

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$found[ $name ] = trim( $value );
			}
		}

		return $found;
	}

	/**
	 * Whether an address names a GPX file (the query string and the fragment do not count).
	 *
	 * @param string $address Address.
	 * @return bool
	 */
	private function is_gpx( $address ) {
		return 'gpx' === $this->extension( $address );
	}

	/**
	 * Whether an address names an image file.
	 *
	 * @param string $address Address.
	 * @return bool
	 */
	private function is_image( $address ) {
		return in_array( $this->extension( $address ), self::IMAGE_EXTENSIONS, true );
	}

	/**
	 * The extension of the file an address names, in lower case.
	 *
	 * @param string $address Address.
	 * @return string
	 */
	private function extension( $address ) {
		$path = (string) preg_replace( '/[?#].*$/s', '', $address );

		return strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	}
}
