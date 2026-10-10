/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Editor of the block modes/link. Plain JavaScript, no build step: the dependencies are declared in edit.asset.php.
 * The modes and the icons come from window.modesLink, set by an inline script.
 */
( function ( wp, settings ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var modes = ( settings && settings.modes ) || [];
	var icons = ( settings && settings.icons ) || [];

	function svgOf( slug ) {
		for ( var i = 0; i < icons.length; i++ ) {
			if ( icons[ i ].value === slug ) {
				return icons[ i ].svg;
			}
		}
		return '';
	}

	function edit( props ) {
		var blockProps = blockEditor.useBlockProps();
		var svg = svgOf( props.attributes.icon );
		// The icon stands for the content of the link while the block has no inner block.
		var inner = wp.data.useSelect( function ( select ) {
			return select( blockEditor.store || 'core/block-editor' ).getBlocks( props.clientId ).length;
		}, [ props.clientId ] );
		var showIcon = '' !== svg && 0 === inner;

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
						options: [ { value: '', label: __( '— Select —', 'otherguise' ) } ].concat( modes ),
						onChange: function ( value ) {
							props.setAttributes( { mode: value } );
						},
					} ),
					el( components.SelectControl, {
						label: __( 'Icon', 'otherguise' ),
						help: __( 'Shown when the block has no content of its own. Choose "None" to put your own content in the link.', 'otherguise' ),
						value: props.attributes.icon,
						options: [ { value: '', label: __( 'None', 'otherguise' ) } ].concat( icons.map( function ( icon ) {
							return { value: icon.value, label: icon.label };
						} ) ),
						onChange: function ( value ) {
							props.setAttributes( { icon: value } );
						},
					} ),
					el( components.TextControl, {
						label: __( 'Accessible label', 'otherguise' ),
						help: __( 'Read by screen readers; useful when the content is only an icon. Empty: the name of the mode.', 'otherguise' ),
						value: props.attributes.label,
						onChange: function ( value ) {
							props.setAttributes( { label: value } );
						},
					} )
				)
			),
			showIcon
				? el( 'div', Object.assign( {}, blockProps, { dangerouslySetInnerHTML: { __html: svg } } ) )
				: el( 'div', blockProps, el( blockEditor.InnerBlocks, { template: [ [ 'core/paragraph', {} ] ] } ) )
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
				attributes: { mode: 'print', icon: 'print', label: __( 'Print version', 'otherguise' ) },
				scope: [ 'inserter' ],
				isDefault: false,
			},
			{
				name: 'web',
				title: __( 'Link to the web version', 'otherguise' ),
				description: __( 'A globe icon that links to the web version.', 'otherguise' ),
				attributes: { mode: 'web', icon: 'web', label: __( 'Web version', 'otherguise' ) },
				scope: [ 'inserter' ],
				isDefault: false,
			},
		],
	} );
} )( window.wp, window.modesLink );
