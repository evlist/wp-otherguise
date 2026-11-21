/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 500: the panel "Branch rules" of the page editor. Plain JavaScript, no build step: the dependencies are declared in RulesPanel.php.
 * The post type, the route and the keys come from window.wikiRules, set by an inline script.
 *
 * A rule set on a page applies to the page and to all its descendants; the nearest page that sets a key wins. The panel edits the rules set
 * on this page (the meta wiki_rules, saved with the page) and shows what the page inherits and from which page, read from the server.
 */
( function ( wp, settings ) {
	if ( ! wp || ! settings || ! wp.plugins || ! wp.data || ! wp.element || ! wp.components || ! wp.i18n || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = function ( text ) {
		return wp.i18n.__( text, 'otherguise' );
	};
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! Panel ) {
		return;
	}

	var labels = {
		template: __( 'Template' ),
		class: __( 'CSS class' ),
		status: __( 'Status line' ),
	};
	var helps = {
		template: __( 'The slug of a template that shows the pages of this branch, for example page-wide.' ),
		class: __( 'Classes added to the body of the pages of this branch.' ),
		status: __( 'A line shown on every page of this branch: fact, source, interpretation.' ),
	};

	function RulesPanel() {
		var state = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return {
				type: editor.getCurrentPostType(),
				id: editor.getCurrentPostId(),
				parent: editor.getEditedPostAttribute( 'parent' ),
				saving: editor.isSavingPost(),
				own: ( editor.getEditedPostAttribute( 'meta' ) || {} ).wiki_rules || {},
			};
		}, [] );
		var dispatch = wp.data.useDispatch( 'core/editor' );
		var info = wp.element.useState( null );

		wp.element.useEffect( function () {
			if ( state.type !== settings.postType || ! state.id || state.saving ) {
				return;
			}

			wp.apiFetch( { path: settings.path + '/' + state.id } ).then( info[ 1 ] ).catch( function () {
				info[ 1 ]( null );
			} );
		}, [ state.id, state.parent, state.saving ] );

		if ( state.type !== settings.postType ) {
			return null;
		}

		var inherited = ( info[ 0 ] && info[ 0 ].effective ) || {};

		return el(
			Panel,
			{ name: 'wiki-rules', title: __( 'Branch rules' ), className: 'wiki-rules-panel' },
			settings.keys.map( function ( key ) {
				var from = inherited[ key ] && ! inherited[ key ].own ? inherited[ key ] : null;
				var help = from
					? /* translators: 1: value, 2: title of the page that sets it. */ __( 'Inherited:' ) + ' "' + from.value + '" (' + from.title + ')'
					: helps[ key ];

				return el( wp.components.TextControl, {
					key: key,
					label: labels[ key ],
					value: state.own[ key ] || '',
					help: help,
					disabled: ! settings.canEdit,
					onChange: function ( value ) {
						var next = Object.assign( {}, state.own );
						next[ key ] = value;
						dispatch.editPost( { meta: { wiki_rules: next } } );
					},
				} );
			} )
		);
	}

	wp.plugins.registerPlugin( 'wiki-rules', { render: RulesPanel } );
}( window.wp, window.wikiRules ) );
