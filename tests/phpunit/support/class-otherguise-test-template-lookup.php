<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Test double of the lookup of templates.
 *
 * @package Otherguise
 */

use Otherguise\Modes\Template\TemplateLookup;

/**
 * Templates, template parts and PHP templates given by the test.
 */
class Otherguise_Test_Template_Lookup extends TemplateLookup {
	/**
	 * Block templates by `type|id`.
	 *
	 * @var WP_Block_Template[]
	 */
	public $templates = array();

	/**
	 * Slugs of the PHP templates of the (classic) theme.
	 *
	 * @var string[]
	 */
	public $php = array();

	/**
	 * Stylesheet of the active theme.
	 *
	 * @var string
	 */
	public $theme = 'twentytwentyfive';

	/**
	 * Returns the stylesheet.
	 *
	 * @return string
	 */
	public function stylesheet() {
		return $this->theme;
	}

	/**
	 * Finds a block template.
	 *
	 * @param string $id   Id.
	 * @param string $type Type.
	 * @return WP_Block_Template|null
	 */
	public function block_template( $id, $type ) {
		return $this->templates[ $type . '|' . $id ] ?? null;
	}

	/**
	 * Tells whether a PHP template exists.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function php_template_exists( $slug ) {
		return in_array( $slug, $this->php, true );
	}

	/**
	 * Returns the link of the editor.
	 *
	 * @param string $id   Id.
	 * @param string $type Type.
	 * @return string
	 */
	public function edit_url( $id, $type ) {
		return 'editor?' . $type . '=' . $id;
	}
}
