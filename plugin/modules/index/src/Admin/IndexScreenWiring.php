<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The screen of the index, built on the options of WordPress.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Admin;

use Otherguise\Index\Enrich\EnrichService;
use Otherguise\Index\Job\PagesService;
use Otherguise\Index\Job\SyncService;
use Otherguise\Index\Photo\PhotosService;

defined( 'ABSPATH' ) || exit;

/**
 * Builds `IndexScreen` with the real options (slice 622): the ones that the commands `index pages enable`, `photos enable`, `enrich enable` and
 * `sync auto` write, so that a command and the screen always agree.
 */
final class IndexScreenWiring {
	public const CONTACT = 'index_wikidata_contact';

	/**
	 * Builds the screen.
	 *
	 * @param SyncService   $sync   The synchronisation.
	 * @param EnrichService $enrich The enrichment.
	 * @param PhotosService $photos The photographs.
	 * @return IndexScreen
	 */
	public static function screen( SyncService $sync, EnrichService $enrich, PhotosService $photos ) {
		return new IndexScreen(
			$sync,
			static function () use ( $enrich, $photos ) {
				$sources = get_option( PagesService::SOURCES, array( 'categories', 'tags' ) );

				return array(
					'sources' => is_array( $sources ) ? array_values( array_map( 'strval', $sources ) ) : array( 'categories', 'tags' ),
					'enrich'  => $enrich->is_on(),
					'contact' => (string) get_option( self::CONTACT, '' ),
					'auto'    => '0' !== (string) get_option( SyncService::AUTO, '1' ),
					'photos'  => $photos->settings(),
				);
			},
			static function ( array $features ) use ( $enrich ) {
				update_option( PagesService::SOURCES, array_values( $features['sources'] ) );
				$enrich->set_on( (bool) $features['enrich'] );
				update_option( self::CONTACT, (string) $features['contact'], false );
				update_option( SyncService::AUTO, $features['auto'] ? '1' : '0' );
				update_option( PhotosService::SETTINGS, array_map( 'intval', $features['photos'] ), false );
			}
		);
	}
}
