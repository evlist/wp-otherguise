<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Schema of the statements table.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the table of the statements.
 *
 * Changing the schema after a release: raise SCHEMA_VERSION, update the CREATE TABLE statement (dbDelta adds tables, columns and
 * indexes, it never drops or alters), and register a step for anything else (renames, drops, data conversion). Steps are idempotent,
 * run in version order, and a failing step leaves the stored version untouched.
 */
final class SchemaManager {
	/**
	 * Current version of the schema.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Option holding the version of the installed schema.
	 */
	public const VERSION_OPTION = 'triples_schema_version';

	/**
	 * Name of the table, without the site prefix.
	 */
	public const TABLE = 'triples_statements';

	/**
	 * Database.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Migration steps by version: callables receiving the Database and returning false on failure.
	 *
	 * @var array<int, callable>
	 */
	private $steps;

	/**
	 * Message of the last failure, or an empty string.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Builds the manager.
	 *
	 * @param Database                  $database Database.
	 * @param array<int, callable>|null $steps    Migration steps by version, or null for the steps of the schema.
	 */
	public function __construct( Database $database, $steps = null ) {
		$this->database = $database;
		$this->steps    = $steps ?? array();
	}

	/**
	 * Returns the name of the table of the current site.
	 *
	 * @return string
	 */
	public function table_name() {
		return $this->database->prefix() . self::TABLE;
	}

	/**
	 * Returns the statement creating the table, written for dbDelta.
	 *
	 * The statement follows the rules of dbDelta: one field per line, two spaces after PRIMARY KEY, KEY (not INDEX) with an explicit name, no backticks.
	 *
	 * @param string $table           Table name.
	 * @param string $charset_collate CHARACTER SET and COLLATE clause.
	 * @return string
	 */
	public static function create_table_sql( $table, $charset_collate ) {
		return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subject_type varchar(20) NOT NULL,
  subject_id varbinary(191) NOT NULL,
  predicate varchar(64) NOT NULL,
  object_type varchar(20) NOT NULL,
  object_id varbinary(191) NOT NULL,
  created_gmt datetime NOT NULL,
  updated_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY triple (subject_type,subject_id,predicate,object_type,object_id),
  KEY object_lookup (object_type,object_id,predicate),
  KEY predicate (predicate)
) {$charset_collate};";
	}

	/**
	 * Creates or upgrades the table when the stored version is older than the current one.
	 *
	 * Cheap when the schema is up to date: one option read.
	 *
	 * @return bool True when the schema is up to date after the call.
	 */
	public function maybe_upgrade() {
		$stored = (int) get_option( self::VERSION_OPTION, 0 );

		if ( $stored >= self::SCHEMA_VERSION ) {
			return true;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::create_table_sql( $this->table_name(), $this->database->charset_collate() ) );

		if ( ! $this->run_steps( $stored ) ) {
			return false;
		}

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );

		return true;
	}

	/**
	 * Returns the message of the last failed step, or an empty string.
	 *
	 * @return string
	 */
	public function last_error() {
		return $this->last_error;
	}

	/**
	 * Drops the table of the current site and forgets the installed version.
	 *
	 * @return void
	 */
	public function drop_table() {
		$table = $this->table_name();

		if ( 1 === preg_match( '/^[A-Za-z0-9_]+\z/', $table ) ) {
			$this->database->execute( $this->database->prepare( 'DROP TABLE IF EXISTS %i', array( $table ) ) );
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Runs the steps newer than the stored version, in version order.
	 *
	 * @param int $from_version Stored version.
	 * @return bool False when a step failed.
	 */
	private function run_steps( $from_version ) {
		$steps = $this->steps;

		ksort( $steps );

		foreach ( $steps as $version => $step ) {
			if ( $version <= $from_version || $version > self::SCHEMA_VERSION ) {
				continue;
			}

			try {
				$result = $step( $this->database );
			} catch ( \Throwable $problem ) {
				$this->last_error = sprintf( 'Step %1$d failed: %2$s', $version, $problem->getMessage() );

				return false;
			}

			if ( false === $result ) {
				$this->last_error = sprintf( 'Step %d failed.', $version );

				return false;
			}
		}

		$this->last_error = '';

		return true;
	}
}
