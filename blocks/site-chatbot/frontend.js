document.addEventListener( 'DOMContentLoaded', () => {
	const form = document.getElementById( 'a8csp-chat-form' );
	const input = document.getElementById( 'a8csp-chat-input' );
	const history = document.getElementById( 'a8csp-chat-history' );

	// Only run if form exists (on frontend, not in admin)
	if ( ! form || ! input || ! history ) {
		return;
	}

	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		const message = input.value.trim();
		if ( ! message ) return;

		// Add user message
		const userMsg = document.createElement( 'div' );
		userMsg.className = 'chat-message user-message';
		userMsg.innerHTML = `<strong>User:</strong> ${ message }`;
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

			const result = await response.json();
			
			if ( result.success ) {
				// Add bot response
				const botMsg = document.createElement( 'div' );
				botMsg.className = 'chat-message bot-message';
				botMsg.innerHTML = `<strong>Assistant:</strong> ${ result.data }`;
				history.appendChild( botMsg );
			} else {
				// Add error message
				const errorMsg = document.createElement( 'div' );
				errorMsg.className = 'chat-message bot-message error';
				errorMsg.innerHTML = `<strong>Error:</strong> ${ result.data || 'Something went wrong' }`;
				history.appendChild( errorMsg );
			}
		} catch ( error ) {
			// Add error message
			const errorMsg = document.createElement( 'div' );
			errorMsg.className = 'chat-message bot-message error';
			errorMsg.innerHTML = `<strong>Error:</strong> Network error occurred`;
			history.appendChild( errorMsg );
		}

		history.scrollTop = history.scrollHeight;
	} );
} );
