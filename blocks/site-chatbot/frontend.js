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

	// Security: Function to refresh nonce if expired
	async function refreshNonceIfNeeded() {
		try {
			const response = await fetch( a8csp_ajax.ajax_url, {
				method: 'POST',
				body: new URLSearchParams({
					action: 'a8csp_refresh_nonce'
				})
			});

			if ( response.ok ) {
				const result = await response.json();
				if ( result.success && result.data.nonce ) {
					a8csp_ajax.nonce = result.data.nonce;
					return true;
				}
			}
		} catch ( error ) {
			console.error( 'A8CSP Chat: Nonce refresh failed', error );
		}
		return false;
	}

	// Shared function to handle message submission
	async function submitMessage() {
		const message = input.value.trim();
		if ( ! message ) return;

		// Add user message
		const userMsg = document.createElement( 'div' );
		userMsg.className = 'a8csp-chat-message user-message';
		const userLabel = document.createElement( 'strong' );
		userLabel.textContent = 'User:';
		userMsg.appendChild( userLabel );
		userMsg.appendChild( document.createTextNode( ' ' + message ) );
		history.appendChild( userMsg );
		input.value = '';

		// Show typing indicator
		const typingIndicator = document.createElement( 'div' );
		typingIndicator.className = 'a8csp-chat-message bot-message typing-indicator';
		const typingLabel = document.createElement( 'strong' );
		typingLabel.textContent = 'Assistant:';
		typingIndicator.appendChild( typingLabel );
		const typingDots = document.createElement( 'span' );
		typingDots.className = 'typing-dots';
		typingDots.innerHTML = ' <span></span><span></span><span></span>';
		typingIndicator.appendChild( typingDots );
		history.appendChild( typingIndicator );
		history.scrollTop = history.scrollHeight;

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
			
			// Remove typing indicator
			history.removeChild( typingIndicator );

			if ( result.success ) {
				// Add bot response
				const botMsg = document.createElement( 'div' );
				botMsg.className = 'a8csp-chat-message bot-message';
				const botLabel = document.createElement( 'strong' );
				botLabel.textContent = 'Assistant:';
				botMsg.appendChild( botLabel );
				
				// Create a span for the response content to allow HTML links from server
				const responseContent = document.createElement( 'span' );
                responseContent.innerHTML = ' ' + ( typeof result.data === 'string' ? result.data : String( result.data ) );
				botMsg.appendChild( responseContent );
				history.appendChild( botMsg );
			} else {
				// Security: Handle nonce expiration
				if ( result.data === 'Invalid nonce' ) {
					console.log( 'A8CSP Chat: Nonce expired, attempting refresh...' );
					const refreshed = await refreshNonceIfNeeded();
					if ( refreshed ) {
						// Retry the request with new nonce
						const retryFormData = new FormData();
						retryFormData.append( 'action', 'a8csp_chat_message' );
						retryFormData.append( 'message', message );
						retryFormData.append( 'nonce', a8csp_ajax.nonce );

						const retryResponse = await fetch( a8csp_ajax.ajax_url, {
							method: 'POST',
							body: retryFormData
						} );

						if ( retryResponse.ok ) {
							const retryResult = await retryResponse.json();
							if ( retryResult.success ) {
								// Add bot response from retry
								const botMsg = document.createElement( 'div' );
								botMsg.className = 'a8csp-chat-message bot-message';
								const botLabel = document.createElement( 'strong' );
								botLabel.textContent = 'Assistant:';
								botMsg.appendChild( botLabel );
								
								const responseContent = document.createElement( 'span' );
								responseContent.innerHTML = ' ' + ( typeof retryResult.data === 'string' ? retryResult.data : String( retryResult.data ) );
								botMsg.appendChild( responseContent );
								history.appendChild( botMsg );
								return; // Success, exit here
							}
						}
					}
				}

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
			// Remove typing indicator on error
			if ( history.contains( typingIndicator ) ) {
				history.removeChild( typingIndicator );
			}

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
	}

	// Handle Enter key to submit (but not Shift+Enter)
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			submitMessage();
		}
	} );

	// Handle form submission
	form.addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		submitMessage();
	} );
} );
