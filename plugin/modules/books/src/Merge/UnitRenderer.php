<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Renders a post as its own print page.
 *
 * @package Otherguise
 */

namespace Otherguise\Books\Merge;

defined( 'ABSPATH' ) || exit;

/**
 * What `MergedPrint` asks for each unit (slice 231).
 */
final class UnitRenderer {
	/**
	 * Renders a unit as WordPress would render its own page in the mode of the request: the main query is made to ask for that post, the
	 * template that WordPress finds for it (the Modes module has put the variant in the hierarchy) is rendered, and the query is put back as it
	 * was. The current post is the unit while its template renders: the shortcodes of a template (`[modes_url]`, hence the QR codes) read it.
	 * A classic theme, or a template that cannot be found, gives the content of the post.
	 *
	 * @param int $id Id of the unit.
	 * @return string
	 */
	public function render( $id ) {
		global $wp_query, $_wp_current_template_content;

		$saved = clone $wp_query;

		$wp_query->query(
			array(
				'p'                   => (int) $id,
				'post_type'           => 'any',
				'post_status'         => 'publish',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);

		try {
			if ( ! $wp_query->have_posts() ) {
				return '';
			}

			$wp_query->the_post();

			// A block template is found when the answer is the canvas that renders it; the globals that hold it are read right after.
			if ( is_page() ) {
				$template = get_page_template();
			} elseif ( is_single() ) {
				$template = get_single_template();
			} else {
				$template = get_singular_template();
			}

			if ( ABSPATH . WPINC . '/template-canvas.php' === $template && ! empty( $_wp_current_template_content ) ) {
				return (string) get_the_block_template_html();
			}

			ob_start();
			the_content();

			return (string) ob_get_clean();
		} finally {
			foreach ( get_object_vars( $saved ) as $name => $value ) {
				$wp_query->$name = $value;
			}

			wp_reset_postdata();
		}
	}
}
