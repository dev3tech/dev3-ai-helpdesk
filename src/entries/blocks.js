import { registerBlockType } from '@wordpress/blocks';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

const box = { border: '1px dashed #c3c4c7', borderRadius: 8, padding: 24, textAlign: 'center' };

const placeholder = ( title, text ) =>
	function Edit() {
		const props = useBlockProps( { style: box } );
		return createElement( 'div', props, createElement( 'strong', null, title ), createElement( 'p', { style: { margin: '8px 0 0' } }, text ) );
	};

const common = { apiVersion: 2, category: 'widgets', supports: { html: false, align: [ 'wide', 'full' ] }, save: () => null };

registerBlockType( 'dev3hd/portal', {
	...common,
	title: __( 'Support portal', 'dev3-ai-helpdesk' ),
	description: __( 'Lets customers open tickets, see their tickets and reply.', 'dev3-ai-helpdesk' ),
	icon: 'sos',
	edit: placeholder( __( 'Support portal', 'dev3-ai-helpdesk' ), __( 'Customers open tickets here; logged-in customers also see “My tickets”. Preview the page to see it.', 'dev3-ai-helpdesk' ) ),
} );

registerBlockType( 'dev3hd/ticket-form', {
	...common,
	title: __( 'Ticket form', 'dev3-ai-helpdesk' ),
	description: __( 'A contact form that opens a support ticket.', 'dev3-ai-helpdesk' ),
	icon: 'email-alt',
	edit: placeholder( __( 'Ticket form', 'dev3-ai-helpdesk' ), __( 'A contact form that opens a support ticket, with help-article suggestions while customers type.', 'dev3-ai-helpdesk' ) ),
} );

registerBlockType( 'dev3hd/help-center', {
	...common,
	title: __( 'Help centre', 'dev3-ai-helpdesk' ),
	description: __( 'Searchable knowledge base with topics and popular articles.', 'dev3-ai-helpdesk' ),
	icon: 'book-alt',
	attributes: { title: { type: 'string', default: '' } },
	edit: function Edit( { attributes, setAttributes } ) {
		const props = useBlockProps( { style: box } );
		return createElement(
			'div',
			props,
			createElement(
				InspectorControls,
				null,
				createElement( PanelBody, { title: __( 'Help centre', 'dev3-ai-helpdesk' ) }, createElement( TextControl, { label: __( 'Heading', 'dev3-ai-helpdesk' ), value: attributes.title, placeholder: __( 'How can we help?', 'dev3-ai-helpdesk' ), onChange: ( title ) => setAttributes( { title } ) } ) )
			),
			createElement( 'strong', null, attributes.title || __( 'How can we help?', 'dev3-ai-helpdesk' ) ),
			createElement( 'p', { style: { margin: '8px 0 0' } }, __( 'Search box, help topics and popular articles. Preview the page to see it.', 'dev3-ai-helpdesk' ) )
		);
	},
} );
