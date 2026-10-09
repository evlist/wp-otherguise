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

require_once dirname( __DIR__, 2 ) . '/plugin/includes/Core/Autoloader.php';

\Otherguise\Core\Autoloader::register( dirname( __DIR__, 2 ) . '/plugin/' );
