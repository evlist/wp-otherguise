/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Editor of the block books/section-units, and the variation "Units of the section" of the Query Loop. Plain JavaScript, no build step: the
 * dependencies are declared in edit.asset.php.
 *
 * The variation is a Query Loop whose query carries books_units: the server then lists the units of the section in their order, whatever
 * the other settings say (the editor shows the posts that those settings would give, as a sample).
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var blockEditor = wp.blockEditor;

	wp.blocks.registerBlockType( 'books/section-units', {
		edit: function () {
			return el(
				'div',
				blockEditor.useBlockProps(),
				el( 'p', null, __( 'In print, the pages of the units of the section are put here. Place this block at the root of the print template; it shows nothing on the web.', 'otherguise' ) )
			);
		},
		save: function () {
			return null;
		},
	} );

	wp.domReady( function () {
		wp.blocks.registerBlockVariation( 'core/query', {
			name: 'books-section-units',
			title: __( 'Units of the section', 'otherguise' ),
			description: __( 'The units (posts and sub-sections) of the section of the page, in the order of the section.', 'otherguise' ),
			icon: 'book-alt',
			scope: [ 'inserter' ],
			isActive: [ 'namespace' ],
			allowedControls: [],
			attributes: {
				namespace: 'books-section-units',
				query: {
					perPage: 100,
					pages: 0,
					offset: 0,
					postType: 'post',
					order: 'asc',
					orderBy: 'date',
					author: '',
					search: '',
					exclude: [],
					sticky: '',
					inherit: false,
					books_units: true,
				},
			},
			innerBlocks: [
				[
					'core/post-template',
					{},
					[ [ 'core/post-title', { isLink: true, level: 3 } ], [ 'core/post-excerpt' ] ],
				],
			],
		} );
	} );
} )( window.wp );
