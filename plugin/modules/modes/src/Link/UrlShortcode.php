<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The shortcode that gives the address of a page in a mode.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Link;

use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * `[modes_url mode="web"]`: the address of the current post (or of `post="<id>"`) in the mode `web`, nothing but the address, so that another
 * shortcode can use it, for instance `[kaya_qrcode_dynamic content_url="1"][modes_url mode="web"][/kaya_qrcode_dynamic]`. Empty when the modes are
 * disabled or when the mode does not exist. The default mode has no argument in its address.
 */
final class UrlShortcode {
	/**
	 * Shortcode tag.
	 */
	public const TAG = 'modes_url';

	/**
	 * Modes.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Receives a post id (0 when none) and returns the address of the page.
	 *
	 * @var callable
	 */
	private $page_url;

	/**
	 * Returns the id of the current post, 0 when none.
	 *
	 * @var callable
	 */
	private $current_post;

	/**
	 * Escapes an address.
	 *
	 * @var callable
	 */
	private $escape;

	/**
	 * Builds the shortcode.
	 *
	 * @param ModeRegistry  $modes        Modes.
	 * @param Settings      $settings     Settings.
	 * @param callable      $page_url     Receives a post id (0 when none) and returns an address: `LinkBlock::default_page_url`.
	 * @param callable|null $current_post Returns the id of the current post; defaults to `get_the_ID()`.
	 * @param callable|null $escape       Escapes an address; defaults to `esc_url_raw`.
	 */
	public function __construct( ModeRegistry $modes, Settings $settings, $page_url, $current_post = null, $escape = null ) {
		$this->modes        = $modes;
		$this->settings     = $settings;
		$this->page_url     = $page_url;
		$this->current_post = $current_post ?? static function () {
			return (int) get_the_ID();
		};
		$this->escape       = $escape ?? 'esc_url_raw';
	}

	/**
	 * Registers the shortcode.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Returns the address.
	 *
	 * @param array|string $atts Attributes: `mode` (a slug), `post` (an id, the current post by default).
	 * @return string The address, or an empty string.
	 */
	public function render( $atts = array() ) {
		$atts = is_array( $atts ) ? $atts : array();
		$slug = isset( $atts['mode'] ) && is_string( $atts['mode'] ) ? trim( $atts['mode'] ) : '';

		if ( ! $this->settings->is_enabled() || ! $this->modes->has( $slug ) ) {
			return '';
		}

		$post_id = isset( $atts['post'] ) ? absint( $atts['post'] ) : 0;

		if ( 0 === $post_id ) {
			$post_id = (int) ( $this->current_post )();
		}

		$url = ( $this->page_url )( $post_id );

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		return (string) ( $this->escape )( ModeUrl::build( $url, $this->modes->get( $slug ), $this->modes ) );
	}
}
