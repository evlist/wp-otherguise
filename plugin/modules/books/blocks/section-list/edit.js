/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Editor of the block books/section-list, and the variation "Units of the section" of the Query Loop. Plain JavaScript, no build step: the
 * dependencies are declared in edit.asset.php. The modes come from window.booksModes, set by an inline script.
 *
 * The variation is a Query Loop whose query carries books_units: the server then lists the units of the section in their order, whatever the
 * other settings say (the editor shows the posts those settings would give, as a sample).
 */
( function ( wp, modes ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var blockEditor = wp.blockEditor;
	var components = wp.components;

	var loop = [
		'core/query',
		{
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
		[ [ 'core/post-template', {}, [ [ 'core/post-title', { isLink: true, level: 3 } ], [ 'core/post-excerpt' ] ] ] ],
	];

	function toggle( list, slug, on ) {
		var next = list.filter( function ( item ) {
			return item !== slug;
		} );
		if ( on ) {
			next.push( slug );
		}
		return next;
	}

	wp.blocks.registerBlockType( 'books/section-list', {
		edit: function ( props ) {
			var selected = props.attributes.modes || [];
			var boxes = ( modes || [] ).map( function ( mode ) {
				return el( components.CheckboxControl, {
					key: mode.value,
					label: mode.label,
					checked: -1 !== selected.indexOf( mode.value ),
					onChange: function ( on ) {
						props.setAttributes( { modes: toggle( selected, mode.value, on ) } );
					},
				} );
			} );

			return el(
				wp.element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Modes', 'otherguise' ) },
						el( 'p', null, __( 'The list is shown in the modes checked. None checked: every mode.', 'otherguise' ) ),
						boxes
					)
				),
				el( 'div', blockEditor.useBlockProps(), el( blockEditor.InnerBlocks, { template: [ loop ] } ) )
			);
		},
		save: function () {
			return el( blockEditor.InnerBlocks.Content );
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
			attributes: loop[ 1 ],
			innerBlocks: loop[ 2 ],
		} );
	} );
} )( window.wp, window.booksModes );
