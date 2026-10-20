<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The settings of the PDF rendering server.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Settings;

use Otherguise\Books\Render\ServerAddress;

defined( 'ABSPATH' ) || exit;

/**
 * The option `books_renderer` (slice 220): the address of the Gotenberg server (empty by default: no server, no PDF mode), how the
 * conversion waits for the page (a fixed delay, a JavaScript expression, a CSS selector: Gotenberg waits for none by default), the media
 * the page is printed with, and for how many days a PDF is kept.
 */
final class RendererSettings {
	/**
	 * Name of the option.
	 */
	public const OPTION = 'books_renderer';

	/**
	 * Key of the address of the server.
	 */
	public const URL = 'gotenberg_url';

	/**
	 * Key of the fixed delay, in seconds.
	 */
	public const WAIT_DELAY = 'wait_delay';

	/**
	 * Key of the JavaScript expression to wait for.
	 */
	public const WAIT_EXPRESSION = 'wait_expression';

	/**
	 * Key of the CSS selector to wait for.
	 */
	public const WAIT_SELECTOR = 'wait_selector';

	/**
	 * Key of the media.
	 */
	public const MEDIA = 'media';

	/**
	 * Key of the number of days a PDF is kept.
	 */
	public const KEEP_DAYS = 'keep_days';

	/**
	 * Longest delay, in seconds.
	 */
	public const MAX_DELAY = 60.0;

	/**
	 * Longest expression, in characters.
	 */
	public const MAX_EXPRESSION = 500;

	/**
	 * Longest selector, in characters.
	 */
	public const MAX_SELECTOR = 200;

	/**
	 * Reports a refused address: receives the message. Defaults to `add_settings_error`.
	 *
	 * @var callable
	 */
	private $notify;

	/**
	 * Builds the settings.
	 *
	 * @param callable|null $notify Receives the message of a refused address; defaults to a notice of the Settings API.
	 */
	public function __construct( $notify = null ) {
		$this->notify = $notify ?? static function ( $message ) {
			add_settings_error( self::OPTION, 'books_renderer_address', $message );
		};
	}

	/**
	 * Returns the default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			self::URL             => '',
			self::WAIT_DELAY      => 0.0,
			self::WAIT_EXPRESSION => '',
			self::WAIT_SELECTOR   => '',
			self::MEDIA           => 'print',
			self::KEEP_DAYS       => 7,
		);
	}

	/**
	 * Returns the settings stored, with the default for each key that is missing or not valid.
	 *
	 * @return array<string, mixed>
	 */
	public function get() {
		$stored = get_option( self::OPTION, array() );

		return $this->normalize( is_array( $stored ) ? $stored : array(), '' );
	}

	/**
	 * Tells whether a rendering server is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->get()[ self::URL ];
	}

	/**
	 * Sanitizes the option when it is saved. An address that is not valid is refused with a notice and the address stored is kept.
	 *
	 * @param mixed $input Value posted.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = $this->get()[ self::URL ];
		$typed   = isset( $input[ self::URL ] ) && is_string( $input[ self::URL ] ) ? trim( $input[ self::URL ] ) : '';

		if ( '' !== $typed && null === ServerAddress::normalize( $typed ) ) {
			( $this->notify )( __( 'The address of the server is not valid: it must be an http or https address, without user, password, query or fragment. The previous address was kept.', 'otherguise' ) );
			$input[ self::URL ] = $current;
		}

		return $this->normalize( $input, $current );
	}

	/**
	 * Keeps the valid values and replaces the others by the defaults.
	 *
	 * @param array<string, mixed> $values  Values.
	 * @param string               $address Address to use when the one of the values is not valid.
	 * @return array<string, mixed>
	 */
	private function normalize( array $values, $address ) {
		$defaults = self::defaults();
		$raw      = $values[ self::URL ] ?? null;
		$url      = is_string( $raw ) && '' === trim( $raw ) ? '' : ( null !== $raw ? ServerAddress::normalize( $raw ) : null );
		$delay    = $values[ self::WAIT_DELAY ] ?? null;

		if ( is_string( $delay ) ) {
			$delay = str_replace( ',', '.', trim( $delay ) );
		}

		$delay = ( is_int( $delay ) || is_float( $delay ) || ( is_string( $delay ) && is_numeric( $delay ) ) ) ? round( (float) $delay, 1 ) : null;
		$days  = $values[ self::KEEP_DAYS ] ?? null;
		$media = $values[ self::MEDIA ] ?? null;

		return array(
			self::URL             => null !== $url ? $url : $address,
			self::WAIT_DELAY      => null !== $delay && is_finite( $delay ) && $delay >= 0 && $delay <= self::MAX_DELAY ? $delay : $defaults[ self::WAIT_DELAY ],
			self::WAIT_EXPRESSION => $this->text( $values[ self::WAIT_EXPRESSION ] ?? '', self::MAX_EXPRESSION ),
			self::WAIT_SELECTOR   => $this->text( $values[ self::WAIT_SELECTOR ] ?? '', self::MAX_SELECTOR ),
			self::MEDIA           => in_array( $media, array( 'print', 'screen' ), true ) ? $media : $defaults[ self::MEDIA ],
			self::KEEP_DAYS       => ( is_int( $days ) || ( is_string( $days ) && 1 === preg_match( '/^[0-9]{1,3}$/', $days ) ) ) && (int) $days >= 1 && (int) $days <= 90 ? (int) $days : $defaults[ self::KEEP_DAYS ],
		);
	}

	/**
	 * A one-line text without control characters, cut at a length; anything that is not a string is empty.
	 *
	 * @param mixed $value  Value.
	 * @param int   $length Longest length.
	 * @return string
	 */
	private function text( $value, $length ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', ' ', $value ) );

		return strlen( $value ) > $length ? substr( $value, 0, $length ) : $value;
	}
}
