<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A stand-in for the table of the administration of WordPress.
 *
 * @package Otherguise
 */

if ( ! class_exists( 'WP_List_Table', false ) ) {
	/**
	 * The little of `WP_List_Table` that the table of the assignments uses: what it prepares is read by the tests, nothing is printed.
	 */
	class WP_List_Table {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidClassName.NotCamelCaps -- a stand-in.
		/**
		 * The lines.
		 *
		 * @var array
		 */
		public $items = array();

		/**
		 * The arguments.
		 *
		 * @var array
		 */
		public $_args = array();

		/**
		 * The columns, the hidden columns, the sortable columns and the primary column, which a table of a plugin gives itself.
		 *
		 * @var array
		 */
		public $_column_headers = array();

		/**
		 * The arguments of the pagination.
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
			$this->_args = $args;
		}

		/**
		 * Keeps the arguments of the pagination.
		 *
		 * @param array $args Arguments.
		 * @return void
		 */
		protected function set_pagination_args( $args ) {
			$this->pagination = $args;
		}

		/**
		 * The links of the actions of a line.
		 *
		 * @param array $actions Actions.
		 * @return string
		 */
		protected function row_actions( $actions ) {
			return '<div class="row-actions">' . implode( ' | ', $actions ) . '</div>';
		}
	}
}
