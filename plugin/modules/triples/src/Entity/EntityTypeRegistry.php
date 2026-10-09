<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Registry of entity types.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples\Entity;

use Otherguise\Triples\Support\LazyRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Entity types by slug.
 */
final class EntityTypeRegistry extends LazyRegistry {
	/**
	 * Builds a registry holding the built-in types: post, attachment, term and user (provided by WordPress), and statement.
	 *
	 * @param callable|null                          $initializer Called once with the registry before its first use, to register other types.
	 * @param array<string, array<string, callable>> $behaviors   Optional `exists`, `identify` and `load` callables by slug (see `WordPressEntities`).
	 * @return self
	 */
	public static function with_builtins( $initializer = null, array $behaviors = array() ) {
		$registry = new self( $initializer );

		foreach ( array(
			'post'       => 'Post',
			'attachment' => 'Media item',
			'term'       => 'Term',
			'user'       => 'User',
			'statement'  => 'Statement',
		) as $slug => $label ) {
			$own = $behaviors[ $slug ] ?? array();

			$registry->add(
				$slug,
				EntityType::positive_integer( $slug, $label, null, $own['exists'] ?? null, $own['identify'] ?? null, $own['load'] ?? null ),
				'Entity type'
			);
		}

		return $registry;
	}

	/**
	 * Registers an entity type.
	 *
	 * @param EntityType $type Entity type.
	 * @return void
	 */
	public function register( EntityType $type ) {
		$this->ensure_initialized();
		$this->add( $type->slug(), $type, 'Entity type' );
	}

	/**
	 * Returns an entity type.
	 *
	 * @param string $slug Slug.
	 * @return EntityType
	 */
	public function get( $slug ) {
		return $this->item( $slug, 'Entity type' );
	}
}
