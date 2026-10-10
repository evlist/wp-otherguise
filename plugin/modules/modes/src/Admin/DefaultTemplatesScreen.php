<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The section of the screen that chooses the template of new posts.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Admin;

use Otherguise\Modes\Settings\DefaultTemplates;
use Otherguise\Modes\Template\TemplateLookup;
use Otherguise\Modes\Template\TemplateRef;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the option with the Settings API (which checks the nonce; the capability is the one of the screen) and prints the form: one
 * list of the templates of the theme for each type of content, with the default template as the first choice.
 */
final class DefaultTemplatesScreen {
	/**
	 * Settings group.
	 */
	public const GROUP = 'modes_default_templates_group';

	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Settings.
	 *
	 * @var DefaultTemplates
	 */
	private $settings;

	/**
	 * Lookup of templates.
	 *
	 * @var TemplateLookup
	 */
	private $lookup;

	/**
	 * Builds the section.
	 *
	 * @param Environment      $environment Environment.
	 * @param DefaultTemplates $settings    Settings.
	 * @param TemplateLookup   $lookup      Lookup of templates.
	 */
	public function __construct( Environment $environment, DefaultTemplates $settings, TemplateLookup $lookup ) {
		$this->environment = $environment;
		$this->settings    = $settings;
		$this->lookup      = $lookup;
	}

	/**
	 * Registers the option. Called on `admin_init`.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			DefaultTemplates::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Returns the capability needed to save the settings of the group. Filter `option_page_capability_{group}`.
	 *
	 * @return string
	 */
	public function capability() {
		return $this->environment->capability();
	}

	/**
	 * Prints the section.
	 *
	 * @return void
	 */
	public function render() {
		$chosen    = $this->settings->all();
		$templates = $this->lookup->templates( 'wp_template' );

		echo '<h2>' . esc_html__( 'Template of new posts', 'otherguise' ) . '</h2>';
		echo '<p>' . esc_html__( 'A new post of a type starts with the template chosen here, which saves choosing it in every post. The author can still choose another one. Posts that exist are not changed. This does not depend on the modes.', 'otherguise' ) . '</p>';
		echo '<form method="post" action="' . esc_url( $this->environment->admin_url( 'options.php' ) ) . '">';
		$this->environment->print_settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $this->lookup->post_types() as $type => $label ) {
			echo '<tr><th scope="row"><label for="modes-template-' . esc_attr( $type ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<select id="modes-template-' . esc_attr( $type ) . '" name="' . esc_attr( DefaultTemplates::OPTION . '[' . $type . ']' ) . '">';
			echo '<option value="">' . esc_html__( '— Default template —', 'otherguise' ) . '</option>';

			foreach ( $templates as $id => $name ) {
				$slug = TemplateRef::slug( $id );

				echo '<option value="' . esc_attr( $slug ) . '"' . ( ( $chosen[ $type ] ?? '' ) === $slug ? ' selected="selected"' : '' ) . '>' . esc_html( $name ) . '</option>';
			}

			echo '</select></td></tr>';
		}

		echo '</tbody></table><p><input type="submit" class="button" value="' . esc_attr__( 'Save', 'otherguise' ) . '" /></p></form>';
	}
}
