document.addEventListener( 'DOMContentLoaded', () => {
	// TODO: Using getElementById assumes 1 block per page. 
	// We might want to revisit this in the future.
	const form = document.getElementById( 'a8csp-chat-form' );
	const input = document.getElementById( 'a8csp-chat-input' );
	const history = document.getElementById( 'a8csp-chat-history' );

	// Only run if form exists (on frontend, not in admin)
	if ( ! form || ! input || ! history ) {
		return;
	}

	// Guard against missing localization object
	if ( typeof a8csp_ajax === 'undefined' || ! a8csp_ajax.ajax_url || ! a8csp_ajax.nonce ) {
		console.error( 'A8CSP Chat: Missing AJAX configuration' );
		return;
	}

	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		const message = input.value.trim();
		if ( ! message ) return;
		// Send to server via AJAX
		// Validate AJAX config before sending
		if ( ! window.a8csp_ajax || ! a8csp_ajax.ajax_url || ! a8csp_ajax.nonce ) {
			const errorMsg = document.createElement( 'div' );
			errorMsg.className = 'chat-message bot-message error';
			const errorLabel = document.createElement( 'strong' );
			errorLabel.textContent = 'Error:';
			errorMsg.appendChild( errorLabel );
			errorMsg.append( ' ', 'Chat configuration missing. Please reload the page.' );
			history.appendChild( errorMsg );
			history.scrollTop = history.scrollHeight;
			return;
		}
		try {
			const formData = new FormData();
			formData.append( 'action', 'a8csp_chat_message' );
			formData.append( 'message', message );
			formData.append( 'nonce', a8csp_ajax.nonce );
		userMsg.appendChild( document.createTextNode( ' ' + message ) );
		history.appendChild( userMsg );
		input.value = '';

		// Send to server via AJAX
		try {
			const formData = new FormData();
			formData.append( 'action', 'a8csp_chat_message' );
			formData.append( 'message', message );
			formData.append( 'nonce', a8csp_ajax.nonce );

			const response = await fetch( a8csp_ajax.ajax_url, {
				method: 'POST',
				body: formData
			} );

			// Check if HTTP request was successful
			if ( ! response.ok ) {
				throw new Error( `HTTP error! status: ${response.status}` );
			}

			const result = await response.json();

			// Validate JSON response structure
			if ( typeof result !== 'object' || result === null ) {
				throw new Error( 'Invalid response format' );
			}
			
			if ( result.success ) {
				// Add bot response
				const botMsg = document.createElement( 'div' );
				botMsg.className = 'a8csp-chat-message bot-message';
				const botLabel = document.createElement( 'strong' );
				botLabel.textContent = 'Assistant:';
				botMsg.appendChild( botLabel );
				
				// Create a span for the response content to allow HTML links from server
				const responseContent = document.createElement( 'span' );
				responseContent.innerHTML = ' ' + result.data; // Server response is already sanitized
				botMsg.appendChild( responseContent );
				history.appendChild( botMsg );
			} else {
				// Add error message
				const errorMsg = document.createElement( 'div' );
				errorMsg.className = 'a8csp-chat-message bot-message error';
				const errorLabel = document.createElement( 'strong' );
				errorLabel.textContent = 'Error:';
				errorMsg.appendChild( errorLabel );
				errorMsg.appendChild( document.createTextNode( ' ' + ( result.data || 'Something went wrong' ) ) );
				history.appendChild( errorMsg );
			}
		} catch ( error ) {
			// Log error for debugging
			console.error( 'A8CSP Chat error:', error );
			
			// Add user-friendly error message
			const errorMsg = document.createElement( 'div' );
			errorMsg.className = 'a8csp-chat-message bot-message error';
			const errorLabel = document.createElement( 'strong' );
			errorLabel.textContent = 'Error:';
			errorMsg.appendChild( errorLabel );
			
			// Provide specific error messages based on error type
			let errorText = ' Network error occurred';
			if ( error.message.includes( 'HTTP error' ) ) {
				errorText = ' Server error occurred. Please try again.';
			} else if ( error.message.includes( 'Invalid response' ) ) {
				errorText = ' Invalid server response. Please try again.';
			} else if ( error.name === 'TypeError' ) {
				errorText = ' Connection failed. Please check your internet connection.';
			}
			
			errorMsg.appendChild( document.createTextNode( errorText ) );
			history.appendChild( errorMsg );
		}

		history.scrollTop = history.scrollHeight;
	} );
} );
