<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page under Tools.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu entry and prints the frame of the screen: the title, the result of the last action, then the screen.
 */
final class AdminPage {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Screen.
	 *
	 * @var VariantsScreen
	 */
	private $screen;

	/**
	 * Builds the page.
	 *
	 * @param Environment    $environment Environment.
	 * @param VariantsScreen $screen      Screen.
	 */
	public function __construct( Environment $environment, VariantsScreen $screen ) {
		$this->environment = $environment;
		$this->screen      = $screen;
	}

	/**
	 * Adds the entry to the Tools menu. Called on `admin_menu`.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'tools.php',
			__( 'Modes', 'modes' ),
			__( 'Modes', 'modes' ),
			$this->environment->capability(),
			Environment::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->environment->can() ) {
			$this->environment->deny();

			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Modes', 'modes' ) . '</h1>';
		$this->notice( $this->environment->query() );
		$this->screen->render();
		echo '</div>';
	}

	/**
	 * Prints the result of the last action.
	 *
	 * @param array<string, string> $query Query string.
	 * @return void
	 */
	private function notice( array $query ) {
		$notice = $query['modes_notice'] ?? '';
		$class  = 'notice-success';

		if ( 'declared' === $notice ) {
			$message = __( 'Variant declared.', 'modes' );
		} elseif ( 'withdrawn' === $notice ) {
			$message = __( 'Variant withdrawn from the mode.', 'modes' );
		} elseif ( 'removed' === $notice ) {
			$message = __( 'Variant removed.', 'modes' );
		} elseif ( 'error' === $notice ) {
			$message = $this->error_message( $query['code'] ?? '' );
			$class   = 'notice-error';
		} else {
			return;
		}

		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Returns the message of a refusal.
	 *
	 * @param string $code Code given by the handlers.
	 * @return string
	 */
	private function error_message( $code ) {
		$messages = array(
			'invalid_request'     => __( 'The form was incomplete.', 'modes' ),
			'not_a_template'      => __( 'Choose a template or a template part.', 'modes' ),
			'kind_mismatch'       => __( 'A template and a template part cannot be variants of each other.', 'modes' ),
			'same_template'       => __( 'A template cannot be its own variant.', 'modes' ),
			'theme_mismatch'      => __( 'A variant belongs to the theme of the template it replaces.', 'modes' ),
			'not_a_mode'          => __( 'Choose a mode.', 'modes' ),
			'mode_already_served' => __( 'This template already has another variant in this mode: withdraw it first.', 'modes' ),
		);

		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ];
		}

		return 0 === strpos( $code, 'triples_' ) ? __( 'That template or mode does not exist or is not accepted.', 'modes' ) : __( 'The variant could not be saved.', 'modes' );
	}
}
