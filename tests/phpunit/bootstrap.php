<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * PHPUnit bootstrap and WordPress test stubs.
 *
 * @package Otherguise
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../../' );
}

if ( ! function_exists( 'esc_html' ) ) {
    /**
     * Stub of esc_html().
     *
     * @param string $text Text to escape.
     * @return string
     */
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

$GLOBALS['otherguise_test_options']      = array();
$GLOBALS['otherguise_test_dbdelta']      = array();
$GLOBALS['otherguise_test_site']         = 1;
$GLOBALS['otherguise_test_sites']        = null;
$GLOBALS['otherguise_test_site_options'] = array();

/**
 * Resets the state of the WordPress stubs.
 *
 * @return void
 */
function otherguise_test_reset() {
    $GLOBALS['otherguise_test_options']      = array();
    $GLOBALS['otherguise_test_dbdelta']      = array();
    $GLOBALS['otherguise_test_site']         = 1;
    $GLOBALS['otherguise_test_sites']        = null;
    $GLOBALS['otherguise_test_site_options'] = array();
    $GLOBALS['otherguise_test_objects']      = array();
    $GLOBALS['otherguise_test_cache']        = array();
    $GLOBALS['otherguise_test_actions']      = array();
    $GLOBALS['otherguise_test_calls']        = array();
    unset( $GLOBALS['wpdb'] );
}

/**
 * Stub of get_option(): options are kept per site.
 *
 * @param string $name          Option name.
 * @param mixed  $default_value Value when the option does not exist.
 * @return mixed
 */
function get_option( $name, $default_value = false ) {
    $site = $GLOBALS['otherguise_test_site'];

    return $GLOBALS['otherguise_test_site_options'][ $site ][ $name ] ?? $default_value;
}

/**
 * Stub of update_option().
 *
 * @param string $name  Option name.
 * @param mixed  $value Value.
 * @return bool
 */
function update_option( $name, $value ) {
    $GLOBALS['otherguise_test_site_options'][ $GLOBALS['otherguise_test_site'] ][ $name ] = $value;

    return true;
}

/**
 * Stub of delete_option().
 *
 * @param string $name Option name.
 * @return bool
 */
function delete_option( $name ) {
    unset( $GLOBALS['otherguise_test_site_options'][ $GLOBALS['otherguise_test_site'] ][ $name ] );

    return true;
}

/**
 * Stub of dbDelta(): records the statement and, when a database object is available, creates the table if it does not exist.
 *
 * The real dbDelta compares the table with the statement and alters it; this stub cannot check that.
 *
 * @param string $sql CREATE TABLE statement.
 * @return array
 */
function dbDelta( $sql ) {
    $GLOBALS['otherguise_test_dbdelta'][] = $sql;

    if ( isset( $GLOBALS['wpdb'] ) ) {
        $GLOBALS['wpdb']->query( preg_replace( '/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql ) );
    }

    return array();
}

/**
 * Stub of is_multisite().
 *
 * @return bool
 */
function is_multisite() {
    return null !== $GLOBALS['otherguise_test_sites'];
}

/**
 * Stub of get_sites().
 *
 * @return int[]
 */
function get_sites() {
    return $GLOBALS['otherguise_test_sites'];
}

/**
 * Stub of switch_to_blog(): changes the current site and the prefix of the database object.
 *
 * @param int $site_id Site id.
 * @return bool
 */
function switch_to_blog( $site_id ) {
    $GLOBALS['otherguise_test_site'] = $site_id;

    if ( isset( $GLOBALS['wpdb'] ) ) {
        $GLOBALS['wpdb']->prefix = 'wp_' . $site_id . '_';
    }

    return true;
}

/**
 * Stub of restore_current_blog().
 *
 * @return bool
 */
function restore_current_blog() {
    return switch_to_blog( 1 );
}

/**
 * Stub of WP_Post.
 */
class WP_Post {
    /**
     * Id.
     *
     * @var int
     */
    public $ID;

    /**
     * Post type.
     *
     * @var string
     */
    public $post_type;

    /**
     * Title.
     *
     * @var string
     */
    public $post_title;

    /**
     * Builds a post.
     *
     * @param int    $id        Id.
     * @param string $post_type Post type.
     * @param string $title     Title.
     */
    public function __construct( $id, $post_type = 'post', $title = '' ) {
        $this->ID         = $id;
        $this->post_type  = $post_type;
        $this->post_title = $title;
    }
}

/**
 * Stub of WP_Term.
 */
class WP_Term {
    /**
     * Id.
     *
     * @var int
     */
    public $term_id;

    /**
     * Name.
     *
     * @var string
     */
    public $name;

    /**
     * Builds a term.
     *
     * @param int    $id   Id.
     * @param string $name Name.
     */
    public function __construct( $id, $name = 'term' ) {
        $this->term_id = $id;
        $this->name    = $name;
    }
}

/**
 * Stub of WP_User.
 */
class WP_User {
    /**
     * Id.
     *
     * @var int
     */
    public $ID;

    /**
     * Display name.
     *
     * @var string
     */
    public $display_name;

    /**
     * Builds a user.
     *
     * @param int    $id           Id.
     * @param string $display_name Display name.
     */
    public function __construct( $id, $display_name = 'user' ) {
        $this->ID           = $id;
        $this->display_name = $display_name;
    }
}

/**
 * Stub of WP_Block_Template.
 */
class WP_Block_Template {
    /**
     * Id: `theme//slug`.
     *
     * @var string
     */
    public $id = '';

    /**
     * Slug.
     *
     * @var string
     */
    public $slug = '';

    /**
     * Type: `wp_template` or `wp_template_part`.
     *
     * @var string
     */
    public $type = 'wp_template';

    /**
     * Title.
     *
     * @var string
     */
    public $title = '';

    /**
     * Builds a template.
     *
     * @param string $id    Id.
     * @param string $type  Type.
     * @param string $title Title.
     */
    public function __construct( $id, $type = 'wp_template', $title = '' ) {
        $this->id    = $id;
        $this->slug  = substr( $id, strpos( $id, '//' ) + 2 );
        $this->type  = $type;
        $this->title = $title;
    }
}

$GLOBALS['otherguise_test_objects'] = array();

/**
 * Declares the WordPress objects that exist in the stubs: `get_post()`, `get_term()` and `get_userdata()` find them by id.
 *
 * @param object[] $objects WP_Post, WP_Term and WP_User objects.
 * @return void
 */
function otherguise_test_wp_objects( array $objects ) {
    $GLOBALS['otherguise_test_objects'] = array();

    foreach ( $objects as $object ) {
        $id = $object->ID ?? $object->term_id;

        $GLOBALS['otherguise_test_objects'][ get_class( $object ) ][ $id ] = $object;
    }
}

/**
 * Stub of get_post().
 *
 * @param int $id Post id.
 * @return WP_Post|null
 */
function get_post( $id ) {
    return $GLOBALS['otherguise_test_objects']['WP_Post'][ $id ] ?? null;
}

/**
 * Stub of get_term().
 *
 * @param int $id Term id.
 * @return WP_Term|null
 */
function get_term( $id ) {
    return $GLOBALS['otherguise_test_objects']['WP_Term'][ $id ] ?? null;
}

/**
 * Stub of get_edit_post_link().
 *
 * @param int    $id      Post id.
 * @param string $context Context.
 * @return string
 */
function get_edit_post_link( $id, $context = 'display' ) {
    return 'post.php?post=' . $id . '&action=edit';
}

/**
 * Stub of get_edit_term_link().
 *
 * @param int $id Term id.
 * @return string
 */
function get_edit_term_link( $id ) {
    return 'term.php?tag_ID=' . $id;
}

/**
 * Stub of get_edit_user_link().
 *
 * @param int $id User id.
 * @return string
 */
function get_edit_user_link( $id ) {
    return 'user-edit.php?user_id=' . $id;
}

/**
 * Stub of get_userdata().
 *
 * @param int $id User id.
 * @return WP_User|false
 */
function get_userdata( $id ) {
    return $GLOBALS['otherguise_test_objects']['WP_User'][ $id ] ?? false;
}

$GLOBALS['otherguise_test_cache']   = array();
$GLOBALS['otherguise_test_actions'] = array();

/**
 * Stub of wp_cache_get(): an array for the current process.
 *
 * @param string $key   Key.
 * @param string $group Group.
 * @return mixed
 */
function wp_cache_get( $key, $group = '' ) {
    return $GLOBALS['otherguise_test_cache'][ $group ][ $key ] ?? false;
}

/**
 * Stub of wp_cache_set().
 *
 * @param string $key   Key.
 * @param mixed  $value Value.
 * @param string $group Group.
 * @return bool
 */
function wp_cache_set( $key, $value, $group = '' ) {
    $GLOBALS['otherguise_test_cache'][ $group ][ $key ] = $value;

    return true;
}

/**
 * Stub of add_action(): records the callbacks by hook.
 *
 * @param string   $hook          Hook.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Number of arguments.
 * @return bool
 */
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['otherguise_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );

    return true;
}

$GLOBALS['otherguise_test_admin']      = true;
$GLOBALS['otherguise_test_calls']      = array();

/**
 * Records a call of a WordPress function that has no effect in the tests.
 *
 * @param string $name      Function.
 * @param array  $arguments Arguments.
 * @return void
 */
function otherguise_test_record( $name, array $arguments ) {
    $GLOBALS['otherguise_test_calls'][] = array_merge( array( $name ), $arguments );
}

/**
 * Stub of apply_filters(): returns the value unchanged.
 *
 * @param string $hook  Hook.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
    return $value;
}

/**
 * Stub of is_admin().
 *
 * @return bool
 */
function is_admin() {
    return false;
}

/**
 * Stub of esc_attr().
 *
 * @param string $text Text.
 * @return string
 */
function esc_attr( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Stub of esc_url().
 *
 * @param string $url URL.
 * @return string
 */
function esc_url( $url ) {
    return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

/**
 * Stub of __().
 *
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {
    return $text;
}

/**
 * Stub of esc_html__().
 *
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function esc_html__( $text, $domain = 'default' ) {
    return esc_html( $text );
}

/**
 * Stub of esc_attr__().
 *
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return string
 */
function esc_attr__( $text, $domain = 'default' ) {
    return esc_attr( $text );
}

/**
 * Stub of esc_html_e().
 *
 * @param string $text   Text.
 * @param string $domain Domain.
 * @return void
 */
function esc_html_e( $text, $domain = 'default' ) {
    echo esc_html( $text );
}

/**
 * Stub of _n().
 *
 * @param string $single Singular.
 * @param string $plural Plural.
 * @param int    $number Number.
 * @param string $domain Domain.
 * @return string
 */
function _n( $single, $plural, $number, $domain = 'default' ) {
    return 1 === (int) $number ? $single : $plural;
}

/**
 * Stub of wp_kses_post(): the tests only check what the code escapes, so it returns the HTML unchanged.
 *
 * @param string $html HTML.
 * @return string
 */
function wp_kses_post( $html ) {
    return $html;
}

/**
 * Stub of absint().
 *
 * @param mixed $value Value.
 * @return int
 */
function absint( $value ) {
    return abs( (int) $value );
}

/**
 * Stub of delete_metadata().
 *
 * @return bool
 */
function delete_metadata() {
    otherguise_test_record( 'delete_metadata', func_get_args() );

    return true;
}

/**
 * Stub of add_submenu_page(): records the call and returns a hook suffix.
 *
 * @return string
 */
function add_submenu_page() {
    otherguise_test_record( 'add_submenu_page', func_get_args() );

    return 'tools_page_triples';
}

/**
 * Stub of add_screen_option().
 *
 * @return void
 */
function add_screen_option() {
    otherguise_test_record( 'add_screen_option', func_get_args() );
}

/**
 * Stub of register_setting().
 *
 * @return void
 */
function register_setting() {
    otherguise_test_record( 'register_setting', func_get_args() );
}

/**
 * Stub of settings_fields(): prints a marker.
 *
 * @param string $group Group.
 * @return void
 */
function settings_fields( $group ) {
    echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
}

/**
 * Stub of WP_List_Table: the part of the API the statements table uses, with a plain display().
 */
class WP_List_Table {
    /**
     * Items.
     *
     * @var array
     */
    public $items = array();

    /**
     * Column headers.
     *
     * @var array
     */
    public $_column_headers = array();

    /**
     * Pagination arguments.
     *
     * @var array
     */
    public $pagination = array();

    /**
     * Builds the table.
     *
     * @param array $args Arguments.
     */
    public function __construct( $args = array() ) {
        unset( $args );
    }

    /**
     * Stub of set_pagination_args().
     *
     * @param array $args Arguments.
     * @return void
     */
    protected function set_pagination_args( $args ) {
        $this->pagination = $args;
    }

    /**
     * Stub of row_actions().
     *
     * @param array $actions Actions.
     * @return string
     */
    protected function row_actions( $actions ) {
        return '<div class="row-actions">' . implode( ' | ', $actions ) . '</div>';
    }

    /**
     * Prints the table.
     *
     * @return void
     */
    public function display() {
        $columns = $this->get_columns();

        echo '<table class="wp-list-table"><thead><tr>';

        foreach ( $columns as $title ) {
            echo '<th>' . $title . '</th>';
        }

        echo '</tr></thead><tbody>';

        if ( array() === $this->items ) {
            echo '<tr><td colspan="' . count( $columns ) . '">';
            $this->no_items();
            echo '</td></tr>';
        }

        foreach ( $this->items as $item ) {
            echo '<tr>';

            foreach ( array_keys( $columns ) as $name ) {
                echo '<td>' . $this->{'column_' . $name}( $item ) . '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Core/Autoloader.php';

\Otherguise\Core\Autoloader::register( dirname( __DIR__, 2 ) . '/plugin/' );

if ( ! function_exists( 'size_format' ) ) {
    /**
     * Stub of size_format().
     *
     * @param int $bytes Bytes.
     * @return string
     */
    function size_format( $bytes ) {
        return $bytes . ' B';
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    /**
     * Minimal stand-in for WP_Error.
     */
    class WP_Error {
        /**
         * Code.
         *
         * @var string
         */
        private $code;

        /**
         * Builds the error.
         *
         * @param string $code    Code.
         * @param string $message Message.
         */
        public function __construct( $code = '', $message = '' ) {
            $this->code = $code;
        }

        /**
         * Returns the code.
         *
         * @return string
         */
        public function get_error_code() {
            return $this->code;
        }
    }
}
