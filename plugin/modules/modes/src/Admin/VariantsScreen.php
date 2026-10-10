<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The body of the administration screen: the modes and the variants.
 *
 * @package Otherguise
 */

namespace Otherguise\Modes\Admin;

use Otherguise\Modes\Mode\ModeRegistry;
use Otherguise\Modes\Template\TemplateLookup;
use Otherguise\Modes\Template\TemplateRef;
use Otherguise\Modes\Variant\Variants;
use Otherguise\Triples\Entity\EntityRef;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the modes (and how to reach them), then for templates and for template parts the variants declared, with the way to withdraw
 * a mode or remove a relation, and the form that declares a variant. It prints only; `AdminActions` does the work.
 */
final class VariantsScreen {
	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Modes.
	 *
	 * @var ModeRegistry
	 */
	private $modes;

	/**
	 * Variants.
	 *
	 * @var Variants
	 */
	private $variants;

	/**
	 * Lookup of templates.
	 *
	 * @var TemplateLookup
	 */
	private $lookup;

	/**
	 * Panel of the setting.
	 *
	 * @var SettingsPanel
	 */
	private $panel;

	/**
	 * Builds the screen.
	 *
	 * @param Environment    $environment Environment.
	 * @param ModeRegistry   $modes       Modes.
	 * @param Variants       $variants    Variants.
	 * @param TemplateLookup $lookup      Lookup of templates.
	 * @param SettingsPanel  $panel        Panel of the setting that enables the modes.
	 */
	public function __construct( Environment $environment, ModeRegistry $modes, Variants $variants, TemplateLookup $lookup, SettingsPanel $panel ) {
		$this->environment = $environment;
		$this->modes       = $modes;
		$this->variants    = $variants;
		$this->lookup      = $lookup;
		$this->panel       = $panel;
	}

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public function render() {
		$this->panel->render();
		$this->modes_table();
		$this->section( TemplateRef::TEMPLATE, 'wp_template', __( 'Templates', 'otherguise' ), __( 'Template', 'otherguise' ) );
		$this->section( TemplateRef::PART, 'wp_template_part', __( 'Template parts', 'otherguise' ), __( 'Template part', 'otherguise' ) );
	}

	/**
	 * Prints the modes: how a request asks for each one, and a link that opens the home page in it.
	 *
	 * @return void
	 */
	private function modes_table() {
		$default = $this->modes->default_mode()->slug();

		echo '<h2>' . esc_html__( 'Modes', 'otherguise' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Mode', 'otherguise' ) . '</th><th>' . esc_html__( 'Query string', 'otherguise' ) . '</th><th>' . esc_html__( 'Default', 'otherguise' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $this->modes->all() as $mode ) {
			echo '<tr><td>' . esc_html( $mode->label() ) . ' <code>' . esc_html( $mode->slug() ) . '</code></td><td><code>?mode=' . esc_html( $mode->slug() ) . '</code>';

			if ( null !== $mode->alias() ) {
				echo ', <code>?' . esc_html( $mode->alias() ) . '</code>';
			}

			echo '</td><td>' . ( $mode->slug() === $default ? esc_html__( 'yes', 'otherguise' ) : '' ) . '</td><td>';
			echo '<a href="' . esc_url( $this->environment->front_url( array( 'mode' => $mode->slug() ) ) ) . '">' . esc_html__( 'Open the home page in this mode', 'otherguise' ) . '</a></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Prints the variants of a kind and the form that adds one.
	 *
	 * @param string $kind      Entity type.
	 * @param string $post_type `wp_template` or `wp_template_part`.
	 * @param string $title     Title of the section.
	 * @param string $noun      Singular noun for the headings.
	 * @return void
	 */
	private function section( $kind, $post_type, $title, $noun ) {
		$relations = $this->variants->relations( $kind );

		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( array() === $relations ) {
			echo '<p>' . esc_html__( 'No variant declared.', 'otherguise' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html( $noun ) . '</th><th>' . esc_html__( 'Variant', 'otherguise' ) . '</th><th>' . esc_html__( 'In the modes', 'otherguise' ) . '</th><th></th></tr></thead><tbody>';

			foreach ( $relations as $relation ) {
				echo '<tr><td>';
				$this->entity( $relation['source'] );
				echo '</td><td>';
				$this->entity( $relation['variant'] );
				echo '</td><td>';

				foreach ( $relation['modes'] as $slug ) {
					$this->form(
						'modes_withdraw',
						$kind,
						$relation,
						$slug,
						/* translators: %s: label of a mode. */
						sprintf( __( 'Withdraw from %s', 'otherguise' ), $this->modes->has( $slug ) ? $this->modes->get( $slug )->label() : $slug )
					);
				}

				echo '</td><td>';
				$this->form( 'modes_remove', $kind, $relation, null, __( 'Remove', 'otherguise' ) );
				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		$this->add_form( $kind, $post_type, $noun );
	}

	/**
	 * Prints a template: its label with the link of the editor, a mark when it does not exist, and its id.
	 *
	 * @param EntityRef $entity Template or template part.
	 * @return void
	 */
	private function entity( EntityRef $entity ) {
		$description = $this->variants->describe( $entity );

		if ( ! empty( $description['url'] ) && false !== $description['exists'] ) {
			echo '<a href="' . esc_url( $description['url'] ) . '">' . esc_html( $description['label'] ) . '</a>';
		} else {
			echo esc_html( $description['label'] );
		}

		if ( false === $description['exists'] ) {
			echo ' <strong>' . esc_html__( '(missing)', 'otherguise' ) . '</strong>';
		}

		echo '<br /><small><code>' . esc_html( $entity->id() ) . '</code></small>';
	}

	/**
	 * Prints a small form that withdraws a mode or removes a relation.
	 *
	 * @param string                                                        $action   `modes_withdraw` or `modes_remove`.
	 * @param string                                                        $kind     Entity type.
	 * @param array{source: EntityRef, variant: EntityRef, modes: string[]} $relation Relation.
	 * @param string|null                                                   $mode     Slug of the mode, or null.
	 * @param string                                                        $label    Label of the button.
	 * @return void
	 */
	private function form( $action, $kind, array $relation, $mode, $label ) {
		echo '<form method="post" style="display:inline-block;margin:0 .5em .25em 0" action="' . esc_url( $this->environment->admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" /><input type="hidden" name="kind" value="' . esc_attr( $kind ) . '" />';
		echo '<input type="hidden" name="source" value="' . esc_attr( $relation['source']->id() ) . '" /><input type="hidden" name="variant" value="' . esc_attr( $relation['variant']->id() ) . '" />';

		if ( null !== $mode ) {
			echo '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '" />';
		}

		$this->environment->print_nonce_field( $action );
		echo '<input type="submit" class="button button-small" value="' . esc_attr( $label ) . '" /></form>';
	}

	/**
	 * Prints the form that declares a variant.
	 *
	 * @param string $kind      Entity type.
	 * @param string $post_type `wp_template` or `wp_template_part`.
	 * @param string $noun      Singular noun.
	 * @return void
	 */
	private function add_form( $kind, $post_type, $noun ) {
		$templates = $this->lookup->templates( $post_type );

		echo '<h3>' . esc_html__( 'Add a variant', 'otherguise' ) . '</h3>';

		if ( array() === $templates ) {
			echo '<p>' . esc_html__( 'The active theme has nothing to choose from.', 'otherguise' ) . '</p>';

			return;
		}

		echo '<form method="post" action="' . esc_url( $this->environment->admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="modes_declare" /><input type="hidden" name="kind" value="' . esc_attr( $kind ) . '" />';
		$this->environment->print_nonce_field( 'modes_declare' );
		echo '<label style="display:inline-block;margin:0 1em .5em 0">' . esc_html( $noun ) . ' ';
		$this->select( 'source', $templates );
		echo '</label><label style="display:inline-block;margin:0 1em .5em 0">' . esc_html__( 'has the variant', 'otherguise' ) . ' ';
		$this->select( 'variant', $templates );
		echo '</label><label style="display:inline-block;margin:0 1em .5em 0">' . esc_html__( 'in the mode', 'otherguise' ) . ' <select name="mode" required>';

		// The default mode is the least likely to be chosen: the first of the others is selected, unless there is no other.
		$modes    = $this->modes->all();
		$default  = $this->modes->default_mode()->slug();
		$selected = (string) key( $modes );

		foreach ( $modes as $slug => $mode ) {
			if ( $slug !== $default ) {
				$selected = (string) $slug;
				break;
			}
		}

		foreach ( $modes as $slug => $mode ) {
			echo '<option value="' . esc_attr( $mode->slug() ) . '"' . ( (string) $slug === $selected ? ' selected="selected"' : '' ) . '>' . esc_html( $mode->label() ) . '</option>';
		}

		echo '</select></label><input type="submit" class="button button-primary" value="' . esc_attr__( 'Add', 'otherguise' ) . '" /></form>';
	}

	/**
	 * Prints a list of templates to choose from.
	 *
	 * @param string                $name      Name of the field.
	 * @param array<string, string> $templates Label by id.
	 * @return void
	 */
	private function select( $name, array $templates ) {
		echo '<select name="' . esc_attr( $name ) . '" required>';
		echo '<option value="">' . esc_html__( '— Select —', 'otherguise' ) . '</option>';

		foreach ( $templates as $id => $label ) {
			echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
	}
}
