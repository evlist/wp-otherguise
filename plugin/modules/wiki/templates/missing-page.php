<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The 404 of an address of the wiki, for a classic theme (slice 504): the header and the footer of the theme around the offer.
 *
 * @package Otherguise
 */

use Otherguise\Core\Modules;
use Otherguise\Wiki\Missing\MissingPage;

defined( 'ABSPATH' ) || exit;

get_header();

$wiki_module = Modules::get( 'wiki' );

echo '<main id="primary" class="site-main">' . wp_kses( null === $wiki_module ? '' : $wiki_module->missing()->render(), MissingPage::allowed_html() ) . '</main>';

get_footer();
