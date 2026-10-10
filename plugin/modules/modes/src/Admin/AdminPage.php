<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page under Settings.
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
	 * Adds the entry to the Settings menu. Called on `admin_menu`.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'options-general.php',
			__( 'Otherguise modes', 'otherguise' ),
			__( 'Otherguise modes', 'otherguise' ),
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

		echo '<div class="wrap"><h1>' . esc_html__( 'Otherguise modes', 'otherguise' ) . '</h1>';
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
			$message = __( 'Variant declared.', 'otherguise' );
		} elseif ( 'withdrawn' === $notice ) {
			$message = __( 'Variant withdrawn from the mode.', 'otherguise' );
		} elseif ( 'removed' === $notice ) {
			$message = __( 'Variant removed.', 'otherguise' );
		} elseif ( 'stylesheet_added' === $notice ) {
			$message = __( 'Stylesheet added to the mode.', 'otherguise' );
		} elseif ( 'stylesheet_removed' === $notice ) {
			$message = __( 'Stylesheet removed from the mode.', 'otherguise' );
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
			'invalid_request'     => __( 'The form was incomplete.', 'otherguise' ),
			'not_a_template'      => __( 'Choose a template or a template part.', 'otherguise' ),
			'kind_mismatch'       => __( 'A template and a template part cannot be variants of each other.', 'otherguise' ),
			'same_template'       => __( 'A template cannot be its own variant.', 'otherguise' ),
			'theme_mismatch'      => __( 'A variant belongs to the theme of the template it replaces.', 'otherguise' ),
			'not_a_mode'          => __( 'Choose a mode.', 'otherguise' ),
			'mode_already_served' => __( 'This template already has another variant in this mode: withdraw it first.', 'otherguise' ),
			'not_a_stylesheet'    => __( 'Choose a CSS file of the Media Library.', 'otherguise' ),
			'upload_failed'       => __( 'The file could not be uploaded: choose a .css file.', 'otherguise' ),
			'too_large'           => __( 'The stylesheet is too large.', 'otherguise' ),
		);

		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ];
		}

		return 0 === strpos( $code, 'triples_' ) ? __( 'That template or mode does not exist or is not accepted.', 'otherguise' ) : __( 'The variant could not be saved.', 'otherguise' );
	}
}
