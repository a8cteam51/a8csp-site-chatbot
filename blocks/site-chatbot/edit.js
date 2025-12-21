( function( blocks, element, blockEditor, i18n, components ) {
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var ColorPicker = components.ColorPicker;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var registerBlockType = blocks.registerBlockType;

	function Edit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var primaryColor = attributes.primaryColor;
		var botName = attributes.botName;
		var initialMessage = attributes.initialMessage;

		var blockProps = useBlockProps({
			style: {
				'--a8csp-chatbot-primary-color': primaryColor
			}
		});

		return el( 'div', {},
			el( InspectorControls, {},
				el( PanelBody, { 
					title: __( 'Chatbot Settings', 'a8csp-site-chatbot' ),
					initialOpen: true 
				},
					el( TextControl, {
						label: __( 'Bot Name', 'a8csp-site-chatbot' ),
						help: __( 'The name displayed for the chatbot in conversations.', 'a8csp-site-chatbot' ),
						value: botName,
						onChange: function( value ) {
							setAttributes( { botName: value } );
						}
					} ),
					el( TextareaControl, {
						label: __( 'Initial Message', 'a8csp-site-chatbot' ),
						help: __( 'The greeting message displayed when visitors first see the chatbot.', 'a8csp-site-chatbot' ),
						value: initialMessage,
						onChange: function( value ) {
							setAttributes( { initialMessage: value } );
						}
					} )
				),
				el( PanelBody, { 
					title: __( 'Chatbot Colors', 'a8csp-site-chatbot' ),
					initialOpen: false 
				},
					el( 'p', {},
						el( 'strong', {}, __( 'Primary Color', 'a8csp-site-chatbot' ) )
					),
					el( ColorPicker, {
						color: primaryColor,
						onChangeComplete: function( color ) {
							setAttributes( { primaryColor: color.hex } );
						},
						disableAlpha: true
					} )
				)
			),
			el( 'div', blockProps,
				el( 'div', { className: 'a8csp-chatbot' },
					el( 'div', { id: 'a8csp-chat-history' },
						el( 'div', { className: 'a8csp-chat-message bot-message' },
							el( 'strong', {}, botName + ':' ),
							' ' + initialMessage
						)
					),
					el( 'div', { className: 'a8csp-chat-editor-note' },
						__( 'This is a preview - visitors will see a functional chat interface', 'a8csp-site-chatbot' )
					)
				)
			)
		);
	}

	registerBlockType( 'a8csp/site-chatbot', {
		title: __( 'A8CSP Site Chatbot', 'a8csp-site-chatbot' ),
		description: __( 'A chatbot interface for interacting with site content.', 'a8csp-site-chatbot' ),
		category: 'widgets',
		icon: 'format-chat',
		attributes: {
			primaryColor: {
				type: 'string',
				default: '#007cba'
			},
			botName: {
				type: 'string',
				default: 'Chatbot'
			},
			initialMessage: {
				type: 'string',
				default: 'What can I help you with today?'
			}
		},
		supports: {
			html: false,
		},
		edit: Edit,
	} );
} )( 
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.i18n,
	window.wp.components
);
