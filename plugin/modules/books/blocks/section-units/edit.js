/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Editor of the block books/section-units. Plain JavaScript, no build step: the dependencies are declared in edit.asset.php.
 * The inner blocks are the item shown for each unit in the list (the web mode); in print they are not used.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var blockEditor = wp.blockEditor;

	function edit( props ) {
		return el(
			wp.element.Fragment,
			null,
			el(
				blockEditor.InspectorControls,
				null,
				el(
					wp.components.PanelBody,
					{ title: __( 'Units of the section', 'otherguise' ) },
					el( wp.components.TextControl, {
						type: 'number',
						min: 0,
						label: __( 'Most units in the list', 'otherguise' ),
						help: __( '0: all of them. Only the list (web) is limited, not the print.', 'otherguise' ),
						value: props.attributes.limit,
						onChange: function ( value ) {
							props.setAttributes( { limit: Math.max( 0, parseInt( value, 10 ) || 0 ) } );
						},
					} )
				)
			),
			el(
				'div',
				blockEditor.useBlockProps(),
				el( 'p', { className: 'books-section-units__help' }, __( 'Units of the section. In print, their pages are put here. Elsewhere, the blocks below are shown for each unit.', 'otherguise' ) ),
				el( blockEditor.InnerBlocks, {
					template: [ [ 'core/post-title', { isLink: true, level: 3 } ], [ 'core/post-excerpt', {} ] ],
				} )
			)
		);
	}

	wp.blocks.registerBlockType( 'books/section-units', {
		edit: edit,
		save: function () {
			return el( blockEditor.InnerBlocks.Content );
		},
	} );
} )( window.wp );
