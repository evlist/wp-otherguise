<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The request that the rendering server makes by the internal address of the site.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Render;

use Otherguise\Books\Settings\RendererSettings;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress sends a page asked for by another host name than its own to its own address (`redirect_canonical`): the rendering server,
 * which asks by the internal address of the site (slice 225), would be sent back to the public address and through the proxy. This
 * filter leaves the request alone when its host is the one of the internal address; nothing else changes.
 */
final class InternalHost {
	/**
	 * The settings.
	 *
	 * @var RendererSettings
	 */
	private $settings;

	/**
	 * Returns the host of the current request.
	 *
	 * @var callable
	 */
	private $request_host;

	/**
	 * Builds the filter.
	 *
	 * @param RendererSettings $settings     The settings.
	 * @param callable|null    $request_host Returns the host (and port) of the request; defaults to `HTTP_HOST`.
	 */
	public function __construct( RendererSettings $settings, $request_host = null ) {
		$this->settings     = $settings;
		$this->request_host = $request_host ?? static function () {
			return isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		};
	}

	/**
	 * Cancels the canonical redirect of a request made by the internal address. Filter `redirect_canonical`, early.
	 *
	 * @param mixed $redirect_url Address that WordPress would send the request to.
	 * @return mixed
	 */
	public function filter( $redirect_url ) {
		$internal = SiteAddress::host( $this->settings->get()[ RendererSettings::SITE ] );

		return null !== $internal && ( $this->request_host )() === $internal ? false : $redirect_url;
	}
}
