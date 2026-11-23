/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Editor of the block books/section-index. Plain JavaScript, no build step: the dependencies are declared in edit.asset.php.
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

	wp.blocks.registerBlockType( 'books/section-index', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var selected = attributes.modes || [];
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
						{ title: __( 'Index', 'otherguise' ) },
						el( components.TextControl, {
							label: __( 'Title', 'otherguise' ),
							value: attributes.title,
							onChange: function ( value ) {
								props.setAttributes( { title: value } );
							},
						} ),
						el( components.TextControl, {
							label: __( 'Include', 'otherguise' ),
							help: __( 'Tokens such as kind:place source:image visibility:print. Empty: everything.', 'otherguise' ),
							value: attributes.include,
							onChange: function ( value ) {
								props.setAttributes( { include: value } );
							},
						} ),
						el( components.TextControl, {
							label: __( 'Exclude', 'otherguise' ),
							help: __( 'The same tokens: whatever matches is left out.', 'otherguise' ),
							value: attributes.exclude,
							onChange: function ( value ) {
								props.setAttributes( { exclude: value } );
							},
						} ),
						el( components.TextControl, {
							label: __( 'Broader topics with a line of their own', 'otherguise' ),
							help: __( 'Kinds whose topics get a line with the pages of everything below them, such as kind:category, or all. Empty: none.', 'otherguise' ),
							value: attributes.rollup,
							onChange: function ( value ) {
								props.setAttributes( { rollup: value } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the broader topic in parentheses', 'otherguise' ),
							checked: !! attributes.context,
							onChange: function ( value ) {
								props.setAttributes( { context: value } );
							},
						} ),
						el( components.SelectControl, {
							label: __( 'Notice of the sources (OpenStreetMap, Wikimedia)', 'otherguise' ),
							value: attributes.attribution,
							options: [
								{ value: 'auto', label: __( 'Only when the index owes something to them', 'otherguise' ) },
								{ value: 'yes', label: __( 'Always', 'otherguise' ) },
								{ value: 'no', label: __( 'Never', 'otherguise' ) },
							],
							onChange: function ( value ) {
								props.setAttributes( { attribution: value } );
							},
						} )
					),
					el(
						components.PanelBody,
						{ title: __( 'Modes', 'otherguise' ), initialOpen: false },
						el( 'p', null, __( 'The index is shown in the modes checked. None checked: every mode.', 'otherguise' ) ),
						boxes
					)
				),
				el(
					'div',
					blockEditor.useBlockProps(),
					el( 'p', null, __( 'The index of the section is made here from the topics that its posts mention, with the block that merges the units (an editor or the PDF job gets it; nobody else).', 'otherguise' ) )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp, window.booksModes );
