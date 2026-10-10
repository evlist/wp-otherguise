/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Editor of the block modes/link. Plain JavaScript, no build step: the dependencies are declared in edit.asset.php.
 * The modes come from window.modesLink.modes, set by an inline script.
 */
( function ( wp, settings ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var blockEditor = wp.blockEditor;
	var components = wp.components;

	var printer =
		'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">' +
		'<path d="M19 8H5a3 3 0 0 0-3 3v6h4v4h12v-4h4v-6a3 3 0 0 0-3-3zm-3 11H8v-5h8v5zm3-7a1 1 0 1 1 0-2 1 1 0 0 1 0 2zM18 3H6v4h12V3z"/></svg>';

	function edit( props ) {
		var modes = ( settings && settings.modes ) || [];
		var options = [ { value: '', label: __( '— Choose a mode —', 'otherguise' ) } ].concat( modes );
		var blockProps = blockEditor.useBlockProps();

		return el(
			wp.element.Fragment,
			null,
			el(
				blockEditor.InspectorControls,
				null,
				el(
					components.PanelBody,
					{ title: __( 'Link to a mode', 'otherguise' ) },
					el( components.SelectControl, {
						label: __( 'Target mode', 'otherguise' ),
						value: props.attributes.mode,
						options: options,
						onChange: function ( value ) {
							props.setAttributes( { mode: value } );
						},
					} ),
					el( components.TextControl, {
						label: __( 'Accessible label', 'otherguise' ),
						help: __( 'Read by screen readers; useful when the content is only an icon.', 'otherguise' ),
						value: props.attributes.label,
						onChange: function ( value ) {
							props.setAttributes( { label: value } );
						},
					} )
				)
			),
			el( 'div', blockProps, el( blockEditor.InnerBlocks, { template: [ [ 'core/paragraph', {} ] ] } ) )
		);
	}

	wp.blocks.registerBlockType( 'modes/link', {
		edit: edit,
		save: function () {
			return el( blockEditor.InnerBlocks.Content );
		},
		variations: [
			{
				name: 'print',
				title: __( 'Link to the print version', 'otherguise' ),
				description: __( 'A printer icon that links to the print version.', 'otherguise' ),
				attributes: { mode: 'print', label: __( 'Print version', 'otherguise' ) },
				innerBlocks: [ [ 'core/html', { content: printer } ] ],
				scope: [ 'inserter' ],
				isDefault: false,
			},
		],
	} );
} )( window.wp, window.modesLink );
