/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Editor of the block books/section-merge. Plain JavaScript, no build step: the dependencies are declared in edit.asset.php.
 * The modes come from window.booksModes, set by an inline script.
 */
( function ( wp, modes ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var blockEditor = wp.blockEditor;
	var components = wp.components;

	function toggle( list, slug, on ) {
		var next = list.filter( function ( item ) {
			return item !== slug;
		} );
		if ( on ) {
			next.push( slug );
		}
		return next;
	}

	wp.blocks.registerBlockType( 'books/section-merge', {
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
						el( 'p', null, __( 'The units are merged in the modes checked. None checked: every mode.', 'otherguise' ) ),
						boxes
					)
				),
				el(
					'div',
					blockEditor.useBlockProps(),
					el( 'p', null, __( 'The pages of the units of the section are put here (an editor or the PDF job gets them; nobody else).', 'otherguise' ) )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp, window.booksModes );
