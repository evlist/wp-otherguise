<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Which modules are enabled on a site.
 *
 * @package Otherguise
 */

namespace Otherguise\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The switch per module (slice 500): the option `otherguise_modules` holds what the site chose, module by module; a module that was never
 * chosen is **enabled, unless it implements `DisabledByDefault`**. A module is never enabled without the modules it needs. The filter
 * `otherguise_enabled_modules` has the last word: it receives the list and may return another (`null` for every module).
 *
 * The switches are on the **overview** of the menu Otherguise, for a user who may `manage_options`. A module that is switched on later is
 * activated at once (its tables, its rules), and boots from the next request.
 */
final class ModuleSwitches {
	/**
	 * Name of the option.
	 */
	public const OPTION = 'otherguise_modules';

	/**
	 * Settings group.
	 */
	public const GROUP = 'otherguise_modules_group';

	/**
	 * Name of the filter.
	 */
	public const FILTER = 'otherguise_enabled_modules';

	/**
	 * All the known modules, by identifier.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private $modules = array();

	/**
	 * Reads the option.
	 *
	 * @var callable
	 */
	private $get_option;

	/**
	 * Applies the filter.
	 *
	 * @var callable
	 */
	private $filter;

	/**
	 * Tells whether the user has a capability.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Tells the user that a choice was changed.
	 *
	 * @var callable
	 */
	private $notice;

	/**
	 * Builds the switches.
	 *
	 * @param ModuleInterface[] $modules    All the known modules.
	 * @param callable|null     $get_option Default `get_option`.
	 * @param callable|null     $filter     Receives the list and returns the filtered one; default the filter `otherguise_enabled_modules`.
	 * @param callable|null     $can        Default `current_user_can`.
	 * @param callable|null     $notice     Receives a code and a message; default `add_settings_error`.
	 */
	public function __construct( array $modules, $get_option = null, $filter = null, $can = null, $notice = null ) {
		foreach ( $modules as $module ) {
			$this->modules[ $module->id() ] = $module;
		}

		$this->get_option = $get_option ?? 'get_option';
		$this->filter     = $filter ?? static function ( $list ) {
			return apply_filters( self::FILTER, $list );
		};
		$this->can        = $can ?? 'current_user_can';
		$this->notice     = $notice ?? static function ( $code, $message ) {
			add_settings_error( self::OPTION, $code, $message, 'warning' );
		};
	}

	/**
	 * The identifiers of the modules that are enabled when the site chose nothing.
	 *
	 * @return string[]
	 */
	public function defaults() {
		$ids = array();

		foreach ( $this->modules as $id => $module ) {
			if ( ! $module instanceof DisabledByDefault ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * What the site chose: the identifier of a known module and whether it is on.
	 *
	 * @return array<string, bool>
	 */
	public function stored() {
		$value  = ( $this->get_option )( self::OPTION, array() );
		$stored = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $id => $on ) {
				if ( isset( $this->modules[ $id ] ) ) {
					$stored[ (string) $id ] = (bool) $on;
				}
			}
		}

		return $stored;
	}

	/**
	 * The identifiers of the modules that are enabled, in the order of the composition root: what the site chose (the defaults for a module
	 * it never chose), as the filter changes it, **with the modules they need**.
	 *
	 * @return string[]
	 */
	public function enabled() {
		$stored = $this->stored();
		$list   = array();

		foreach ( $this->modules as $id => $module ) {
			if ( array_key_exists( $id, $stored ) ? $stored[ $id ] : ! $module instanceof DisabledByDefault ) {
				$list[] = $id;
			}
		}

		$filtered = ( $this->filter )( $list );
		$filtered = is_array( $filtered ) ? array_map( 'strval', $filtered ) : array_keys( $this->modules );

		return $this->closed( array_values( array_intersect( array_keys( $this->modules ), $filtered ) ) );
	}

	/**
	 * The enabled modules that need a module.
	 *
	 * @param string $id Identifier.
	 * @return string[]
	 */
	public function needed_by( $id ) {
		$by = array();

		foreach ( $this->enabled() as $other ) {
			if ( $other !== $id && in_array( $id, $this->modules[ $other ]->dependencies(), true ) ) {
				$by[] = $other;
			}
		}

		return $by;
	}

	/**
	 * Cleans what the form posted: a known module is on when its box is ticked and off when it is not; **a module that another enabled module
	 * needs stays on**, with a notice. A value that is not a list changes nothing.
	 *
	 * @param mixed $input The posted list, by identifier.
	 * @return array<string, bool>
	 */
	public function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$current = array();

			foreach ( $this->modules as $id => $module ) {
				$current[ $id ] = in_array( $id, $this->enabled(), true );
			}

			return $current;
		}

		$on = array();

		foreach ( $this->modules as $id => $module ) {
			$on[ $id ] = ! empty( $input[ $id ] );
		}

		foreach ( $this->closed( array_keys( array_filter( $on ) ) ) as $id ) {
			if ( ! $on[ $id ] ) {
				$on[ $id ] = true;
				( $this->notice )(
					'needed-' . $id,
					sprintf( /* translators: %s: name of a module. */ __( 'The module %s stays on: another module that is on needs it.', 'otherguise' ), $this->title( $id ) )
				);
			}
		}

		return $on;
	}

	/**
	 * Registers the setting and the activation of what is switched on. To be called on `admin_init`.
	 *
	 * @param callable $add_action Adds an action.
	 * @return void
	 */
	public function register( $add_action ) {
		$add_action( 'admin_init', array( $this, 'register_setting' ), 10, 0 );
		$add_action( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ), 10, 0 );
		$add_action( 'update_option_' . self::OPTION, array( $this, 'activate_new' ), 10, 0 );
		$add_action( 'add_option_' . self::OPTION, array( $this, 'activate_new' ), 10, 0 );
	}

	/**
	 * Registers the option. Action `admin_init`.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * The capability of the switches and of their saving.
	 *
	 * @return string
	 */
	public function capability() {
		return 'manage_options';
	}

	/**
	 * Activates the modules that are enabled (their tables, their rules): the call is made when the option is saved, so that a module that was
	 * just switched on is ready when it boots on the next request. The activation of a module can be repeated.
	 *
	 * @return void
	 */
	public function activate_new() {
		( new ModuleLoader( array_values( $this->modules ), $this->enabled() ) )->activate();
	}

	/**
	 * Prints the section of the switches, for the overview of the menu.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! (bool) ( $this->can )( $this->capability() ) ) {
			return;
		}

		$enabled = $this->enabled();

		echo '<h2>' . esc_html__( 'Modules', 'otherguise' ) . '</h2>';
		echo '<p>' . esc_html__( 'A module that is off adds nothing to the site. A module that is switched on starts with the next page loaded; one that another needs stays on.', 'otherguise' ) . '</p>';
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="widefat striped" style="max-width:60em"><tbody>';

		foreach ( $this->modules as $id => $module ) {
			$needed = $this->needed_by( $id );
			$needs  = array_map( array( $this, 'title' ), $module->dependencies() );
			$notes  = array();

			if ( array() !== $needs ) {
				/* translators: %s: names of modules. */
				$notes[] = sprintf( __( 'Needs: %s.', 'otherguise' ), implode( ', ', $needs ) );
			}

			if ( array() !== $needed ) {
				/* translators: %s: names of modules. */
				$notes[] = sprintf( __( 'Needed by: %s.', 'otherguise' ), implode( ', ', array_map( array( $this, 'title' ), $needed ) ) );
			}

			if ( $module instanceof DisabledByDefault ) {
				$notes[] = __( 'Off until you switch it on.', 'otherguise' );
			}

			// A box that is disabled is not posted: the module that others need is posted by a hidden field, so that saving does not complain.
			echo '<tr><td style="width:2em">'
				. ( array() !== $needed ? '<input type="hidden" name="' . esc_attr( self::OPTION . '[' . $id . ']' ) . '" value="1" />' : '' )
				. '<input type="checkbox" id="otherguise-module-' . esc_attr( $id ) . '"'
				. ( array() !== $needed ? ' disabled="disabled"' : ' name="' . esc_attr( self::OPTION . '[' . $id . ']' ) . '"' ) . ' value="1"'
				. ( in_array( $id, $enabled, true ) ? ' checked="checked"' : '' ) . ' /></td>';
			echo '<td><label for="otherguise-module-' . esc_attr( $id ) . '"><strong>' . esc_html( $this->title( $id ) ) . '</strong></label>';
			echo $module instanceof DescribedModule ? ' &mdash; ' . esc_html( $module->description() ) : '';
			echo array() === $notes ? '' : '<br /><span class="description">' . esc_html( implode( ' ', $notes ) ) . '</span>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p><input type="submit" class="button button-primary" value="' . esc_attr__( 'Save the modules', 'otherguise' ) . '" /></p></form>';
	}

	/**
	 * The name of a module.
	 *
	 * @param string $id Identifier.
	 * @return string
	 */
	public function title( $id ) {
		$module = $this->modules[ $id ] ?? null;

		return $module instanceof DescribedModule ? $module->title() : ucfirst( (string) $id );
	}

	/**
	 * Adds to a list the modules that its modules need, at any depth, and puts it in the order of the composition root.
	 *
	 * @param string[] $ids Identifiers.
	 * @return string[]
	 */
	private function closed( array $ids ) {
		$wanted = array_flip( $ids );
		$queue  = $ids;

		while ( array() !== $queue ) {
			$id = array_shift( $queue );

			foreach ( isset( $this->modules[ $id ] ) ? $this->modules[ $id ]->dependencies() : array() as $dependency ) {
				if ( isset( $this->modules[ $dependency ] ) && ! isset( $wanted[ $dependency ] ) ) {
					$wanted[ $dependency ] = true;
					$queue[]               = $dependency;
				}
			}
		}

		return array_values( array_intersect( array_keys( $this->modules ), array_keys( $wanted ) ) );
	}
}
