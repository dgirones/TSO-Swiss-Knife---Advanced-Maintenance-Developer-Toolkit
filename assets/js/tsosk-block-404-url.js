/**
 * TSO Swiss Knife – Block: URL causing 404 error.
 *
 * @package TSO_Swiss_Knife
 */

( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.blockEditor || ! wp.components || ! wp.i18n ) {
		return;
	}

	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var SelectControl     = wp.components.SelectControl;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var el                = wp.element.createElement;
	var __                = wp.i18n.__;

	registerBlockType( 'tsosk/404-url', {
		edit: function ( props ) {
			var display = props.attributes.display || 'full';
			var options = [
				{ label: __( 'Full URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), value: 'full' },
				{ label: __( 'Path only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), value: 'page' },
				{ label: __( 'Domain + path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), value: 'domainpath' }
			];

			return el(
				'div',
				useBlockProps( { className: 'tsosk-block-404-url' } ),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Display', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'URL format', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							value: display,
							options: options,
							onChange: function ( value ) {
								props.setAttributes( { display: value } );
							}
						} )
					)
				),
				el(
					'p',
					{ className: 'tsosk-block-404-url-preview' },
					el( 'strong', null, __( 'URL causing 404 error', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
					el( 'br' ),
					el( 'code', null, '[tsosk_404_url display="' + display + '"]' )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
