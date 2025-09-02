( function( blocks, element, blockEditor, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var registerBlockType = blocks.registerBlockType;

	function Edit() {
		var blockProps = useBlockProps();
		return el( 'div', blockProps,
			el( 'div', { className: 'a8csp-chatbot' },
				el( 'div', { id: 'a8csp-chat-history' },
					el( 'div', { className: 'a8csp-chat-message user-message' },
						el( 'strong', {}, __( 'User:', 'a8csp-site-chatbot' ) ),
						__( 'Hi!', 'a8csp-site-chatbot' )
					),
					el( 'div', { className: 'a8csp-chat-message bot-message' },
						el( 'strong', {}, __( 'Assistant:', 'a8csp-site-chatbot' ) ),
						__( 'Hey! I\'m here to help you with questions about this site. Try me out on the frontend!', 'a8csp-site-chatbot' )
					)
				),
				el( 'div', { className: 'a8csp-chat-editor-note' },
					__( 'This is a preview - visitors will see a functional chat interface', 'a8csp-site-chatbot' )
				)
			)
		);
	}

	registerBlockType( 'a8csp/site-chatbot', {
		title: __( 'A8CSP Site Chatbot', 'a8csp-site-chatbot' ),
		description: __( 'A chatbot interface for interacting with site content.', 'a8csp-site-chatbot' ),
		category: 'widgets',
		icon: 'format-chat',
		supports: {
			html: false,
		},
		edit: Edit,
	} );
} )( 
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.i18n
);
