<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The spaces of the wiki.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Space;

defined( 'ABSPATH' ) || exit;

/**
 * The spaces (slice 500): the kinds of sections of the wiki (the encyclopaedic part, the opinions, the biography...), kept in the option
 * `wiki_spaces`, an array of records (a handful of spaces: no table). A record has a `name` (the word of the address, such as
 * `Biography`), a `label`, the `template` a new page starts with, a CSS `class` for the body, a `status` line shown on every page and an
 * `order`. The name is unique **without regard to the case**: a request in another case is redirected to the registered form (slice 503).
 * The other slices read the spaces through `all()`, `find()` and `canonical()`.
 */
final class Spaces {
	/**
	 * Name of the option.
	 */
	public const OPTION = 'wiki_spaces';

	/**
	 * Settings error code, for the notices of the screen.
	 */
	public const NOTICE = 'wiki_spaces';

	/**
	 * Longest name of a space, in characters: the qualified name of a page (`Space:Title`) is at most 191 bytes (slice 501).
	 */
	public const MAX_NAME = 40;

	/**
	 * Returns the spaces, by order and then by name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$spaces = array();

		if ( is_array( $stored ) ) {
			foreach ( $stored as $record ) {
				$record = is_array( $record ) ? self::record( $record ) : null;

				if ( null !== $record ) {
					$spaces[] = $record;
				}
			}
		}

		usort(
			$spaces,
			static function ( $a, $b ) {
				return array( $a['order'], $a['name'] ) <=> array( $b['order'], $b['name'] );
			}
		);

		return $spaces;
	}

	/**
	 * Finds a space by its name, without regard to the case.
	 *
	 * @param mixed $name Name of the space, in any case.
	 * @return array<string, mixed>|null The record, or null.
	 */
	public static function find( $name ) {
		$name = self::normalize_name( $name );

		if ( null === $name ) {
			return null;
		}

		$wanted = self::fold( $name );

		foreach ( self::all() as $space ) {
			if ( self::fold( $space['name'] ) === $wanted ) {
				return $space;
			}
		}

		return null;
	}

	/**
	 * Returns the registered form of a name.
	 *
	 * @param mixed $name Name of the space, in any case.
	 * @return string|null The name as registered, or null when there is no such space.
	 */
	public static function canonical( $name ) {
		$space = self::find( $name );

		return null === $space ? null : $space['name'];
	}

	/**
	 * Cleans a name: trimmed, in normal form C, made of letters, digits, hyphens and underscores (no colon, no slash, no space), at most
	 * `MAX_NAME` characters.
	 *
	 * @param mixed $name Name as typed.
	 * @return string|null The name, or null when it is not one.
	 */
	public static function normalize_name( $name ) {
		if ( ! is_string( $name ) ) {
			return null;
		}

		$name = trim( $name );

		if ( class_exists( 'Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $name, \Normalizer::FORM_C );
			$name       = is_string( $normalized ) ? $normalized : $name;
		}

		if ( 1 !== preg_match( '/^[\p{L}\p{N}_-]+$/u', $name ) || mb_strlen( $name ) > self::MAX_NAME ) {
			return null;
		}

		return $name;
	}

	/**
	 * Tells whether a space holds pages. The pages come with slice 502, which answers the filter `wiki_space_has_pages`; until then none.
	 *
	 * @param string $name Name of the space.
	 * @return bool
	 */
	public static function has_pages( $name ) {
		return (bool) apply_filters( 'wiki_space_has_pages', false, $name );
	}

	/**
	 * Sanitizes the option when the screen is saved. The list posted is the whole list: a row is a record, with the name it had before
	 * (`was`) and a box `remove`. A row that has no name and no label is the empty row of the form and is ignored; a bad name, a name that
	 * repeats one above it without regard to the case, the removal and the renaming of a space that holds pages are refused with a notice
	 * (a refused change leaves the record as it was). The server decides: the browser is trusted for nothing.
	 *
	 * @param mixed $input The rows posted.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$before  = array();
		$kept    = array();
		$taken   = array();
		$handled = array();

		foreach ( self::all() as $space ) {
			$before[ $space['name'] ] = $space;
		}

		foreach ( $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$was    = isset( $row['was'] ) && is_string( $row['was'] ) && isset( $before[ $row['was'] ] ) ? $row['was'] : '';
			$typed  = isset( $row['name'] ) && is_string( $row['name'] ) ? trim( $row['name'] ) : '';
			$label  = isset( $row['label'] ) && is_string( $row['label'] ) ? trim( $row['label'] ) : '';
			$remove = ! empty( $row['remove'] );

			if ( '' === $was && '' === $typed && '' === $label ) {
				continue;
			}

			if ( '' !== $was ) {
				$handled[ $was ] = true;
			}

			if ( $remove && '' !== $was ) {
				if ( self::has_pages( $was ) ) {
					self::notice( 'remove-' . $was, sprintf( /* translators: %s: name of the space. */ __( 'The space %s holds pages: it cannot be removed.', 'otherguise' ), $was ) );
					$kept[] = $before[ $was ];
					$taken[ self::fold( $was ) ] = true;
				}

				continue;
			}

			if ( $remove ) {
				continue;
			}

			$name = self::normalize_name( $typed );

			if ( null === $name ) {
				self::notice( 'name-' . $typed, sprintf( /* translators: %s: the name typed. */ __( '"%s" is not a name of space: use letters, digits, hyphens and underscores, without colon, slash or space.', 'otherguise' ), $typed ) );

				if ( '' !== $was ) {
					$kept[]                      = $before[ $was ];
					$taken[ self::fold( $was ) ] = true;
				}

				continue;
			}

			if ( '' !== $was && $name !== $was && self::has_pages( $was ) ) {
				self::notice( 'rename-' . $was, sprintf( /* translators: %s: name of the space. */ __( 'The space %s holds pages: it cannot be renamed (a rename moves every address).', 'otherguise' ), $was ) );
				$kept[]                      = $before[ $was ];
				$taken[ self::fold( $was ) ] = true;

				continue;
			}

			if ( isset( $taken[ self::fold( $name ) ] ) ) {
				self::notice( 'duplicate-' . $name, sprintf( /* translators: %s: name of the space. */ __( 'The name %s is already used by another space (the case does not count).', 'otherguise' ), $name ) );

				continue;
			}

			$taken[ self::fold( $name ) ] = true;
			$kept[]                       = self::clean( $row, $name, count( $kept ) );
		}

		// A registered space that is not in the list posted is removed, with the same refusal.
		foreach ( $before as $name => $space ) {
			if ( isset( $handled[ $name ] ) ) {
				continue;
			}

			if ( self::has_pages( $name ) ) {
				self::notice( 'remove-' . $name, sprintf( /* translators: %s: name of the space. */ __( 'The space %s holds pages: it cannot be removed.', 'otherguise' ), $name ) );
				$kept[] = $space;
			}
		}

		return $kept;
	}

	/**
	 * Builds a record from a row posted.
	 *
	 * @param array<string, mixed> $row      The row.
	 * @param string               $name     Name, already cleaned.
	 * @param int                  $position Position in the list, for the default order.
	 * @return array<string, mixed>
	 */
	private static function clean( array $row, $name, $position ) {
		$text = static function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) && is_string( $row[ $key ] ) ? sanitize_text_field( $row[ $key ] ) : '';
		};

		return self::record(
			array(
				'name'     => $name,
				'label'    => $text( 'label' ),
				'template' => $text( 'template' ),
				'class'    => $text( 'class' ),
				'status'   => $text( 'status' ),
				'order'    => isset( $row['order'] ) && is_numeric( $row['order'] ) ? (int) $row['order'] : ( $position + 1 ) * 10,
			)
		);
	}

	/**
	 * Gives a record its shape: the six keys and nothing else, each cleaned; null when the name is not one.
	 *
	 * @param array<string, mixed> $record A record, stored or posted.
	 * @return array<string, mixed>|null
	 */
	private static function record( array $record ) {
		$name = self::normalize_name( $record['name'] ?? null );

		if ( null === $name ) {
			return null;
		}

		$string = static function ( $key ) use ( $record ) {
			return isset( $record[ $key ] ) && is_string( $record[ $key ] ) ? trim( $record[ $key ] ) : '';
		};
		$label  = $string( 'label' );

		return array(
			'name'     => $name,
			'label'    => '' === $label ? $name : $label,
			'template' => (string) preg_replace( '/[^a-z0-9_\/-]/', '', strtolower( $string( 'template' ) ) ),
			'class'    => implode( ' ', array_filter( array_map( static fn( $token ) => (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $token ), explode( ' ', $string( 'class' ) ) ) ) ),
			'status'   => $string( 'status' ),
			'order'    => isset( $record['order'] ) && is_numeric( $record['order'] ) ? (int) $record['order'] : 0,
		);
	}

	/**
	 * Lowers the case of a name, to compare two of them.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function fold( $name ) {
		return mb_strtolower( $name );
	}

	/**
	 * Tells the user that a change was refused. The notice is shown by the screen.
	 *
	 * @param string $code    Code of the notice.
	 * @param string $message Text.
	 * @return void
	 */
	private static function notice( $code, $message ) {
		add_settings_error( self::NOTICE, $code, $message, 'error' );
	}
}
