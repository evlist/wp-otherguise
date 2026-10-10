<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Handlers of the forms of the administration screen.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Admin;

use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Template\TemplateRef;
use Otherguise\Modes\Variant\VariantException;
use Otherguise\Modes\Variant\Variants;
use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Service\InvalidStatementException;

defined( 'ABSPATH' ) || exit;

/**
 * The `admin_post_` actions that declare, withdraw and remove variants. Each one checks the capability, then the nonce, before reading
 * anything; reads only values that name a kind, two template ids and a registered mode; and redirects to the screen with the result.
 * What the rules of the variants or Triples refuse is not an error page: it is a notice.
 */
final class AdminActions {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Variants.
	 *
	 * @var Variants
	 */
	private $variants;

	/**
	 * Modes.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Builds the handlers.
	 *
	 * @param Environment  $environment Environment.
	 * @param Variants     $variants    Variants.
	 * @param ModeRegistry $modes       Modes.
	 */
	public function __construct( Environment $environment, Variants $variants, ModeRegistry $modes ) {
		$this->environment = $environment;
		$this->variants    = $variants;
		$this->modes       = $modes;
	}

	/**
	 * Declares that a template has a variant in a mode.
	 *
	 * @return void
	 */
	public function declare_variant() {
		$this->handle(
			'modes_declare',
			'declared',
			function ( EntityRef $source, EntityRef $variant, EntityRef $mode ) {
				$this->variants->declare( $source, $mode, $variant );
			}
		);
	}

	/**
	 * Withdraws a variant from a mode.
	 *
	 * @return void
	 */
	public function withdraw_variant() {
		$this->handle(
			'modes_withdraw',
			'withdrawn',
			function ( EntityRef $source, EntityRef $variant, EntityRef $mode ) {
				$this->variants->withdraw( $source, $mode, $variant );
			}
		);
	}

	/**
	 * Removes a relation in every mode.
	 *
	 * @return void
	 */
	public function remove_variant() {
		$this->handle(
			'modes_remove',
			'removed',
			function ( EntityRef $source, EntityRef $variant ) {
				$this->variants->remove( $source, $variant );
			},
			false
		);
	}

	/**
	 * Checks the capability and the nonce, reads the form, does the work, and redirects.
	 *
	 * @param string   $action     Action of the nonce.
	 * @param string   $notice     Notice on success.
	 * @param callable $work       Receives the source, the variant and, when asked, the mode.
	 * @param bool     $with_mode  Whether the form carries a mode.
	 * @return void
	 */
	private function handle( $action, $notice, $work, $with_mode = true ) {
		if ( ! $this->environment->can() ) {
			$this->environment->deny();
		}

		$form  = $this->environment->form();
		$nonce = $form['_wpnonce'] ?? '';

		if ( ! $this->environment->verify_nonce( $nonce, $action ) ) {
			$this->environment->deny();
		}

		$kind    = $form['kind'] ?? '';
		$source  = $form['source'] ?? '';
		$variant = $form['variant'] ?? '';
		$mode    = $form['mode'] ?? '';

		if ( ! in_array( $kind, array( TemplateRef::TEMPLATE, TemplateRef::PART ), true ) || ! TemplateRef::is_valid_id( $source ) || ! TemplateRef::is_valid_id( $variant ) || ( $with_mode && ! $this->modes->has( $mode ) ) ) {
			$this->done( 'error', 'invalid_request' );

			return;
		}

		try {
			$work( new EntityRef( $kind, $source ), new EntityRef( $kind, $variant ), new EntityRef( 'mode', $with_mode ? $mode : 'web' ) );
		} catch ( VariantException $problem ) {
			$this->done( 'error', $problem->error_code() );

			return;
		} catch ( InvalidStatementException $problem ) {
			$this->done( 'error', 'triples_' . $problem->error_code() );

			return;
		}

		$this->done( $notice );
	}

	/**
	 * Redirects to the screen with the result.
	 *
	 * @param string      $notice Result.
	 * @param string|null $code   Code of the refusal.
	 * @return void
	 */
	private function done( $notice, $code = null ) {
		$this->environment->redirect(
			$this->environment->page_url( array( 'modes_notice' => $notice ) + ( null === $code ? array() : array( 'code' => $code ) ) )
		);
	}
}
