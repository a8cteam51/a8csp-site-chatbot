<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

function a8csp_register_blocks() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}
	// Update the plugins_url calls to use __FILE__ from the main plugin file for consistency.
	$main_file = dirname(__DIR__) . '/a8csp-site-chatbot.php';

	wp_register_script(
		'a8csp-site-chatbot-block-editor',
		plugins_url( 'blocks/site-chatbot/edit.js', $main_file ),
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n' ),
		filemtime( plugin_dir_path( $main_file ) . 'blocks/site-chatbot/edit.js' )
	);

	wp_register_script(
		'a8csp-site-chatbot-frontend',
		plugins_url( 'blocks/site-chatbot/frontend.js', $main_file ),
		array( 'wp-api-fetch' ),
		filemtime( plugin_dir_path( $main_file ) . 'blocks/site-chatbot/frontend.js' )
	);

	wp_register_style(
		'a8csp-site-chatbot-style',
		plugins_url( 'blocks/site-chatbot/style.css', $main_file ),
		array(),
		filemtime( plugin_dir_path( $main_file ) . 'blocks/site-chatbot/style.css' )
	);

	register_block_type( 'a8csp/site-chatbot', array(
		'editor_script' => 'a8csp-site-chatbot-block-editor',
		'editor_style' => 'a8csp-site-chatbot-style',
		'view_script' => 'a8csp-site-chatbot-frontend',
		'style' => 'a8csp-site-chatbot-style',
		'render_callback' => 'a8csp_render_site_chatbot_block',
		'title' => __( 'A8CSP Site Chatbot', 'a8csp-site-chatbot' ),
		'description' => __( 'A chatbot interface for interacting with site content.', 'a8csp-site-chatbot' ),
		'category' => 'widgets',
		'icon' => 'format-chat',
		'supports' => array(
			'html' => false,
		),
	) );
}

add_action( 'init', 'a8csp_register_blocks' );

// Add AJAX handlers for chat messages
add_action( 'wp_ajax_a8csp_chat_message', 'a8csp_handle_chat_message' );
add_action( 'wp_ajax_nopriv_a8csp_chat_message', 'a8csp_handle_chat_message' );

// Security: Add nonce refresh handler for long sessions
add_action( 'wp_ajax_a8csp_refresh_nonce', 'a8csp_handle_nonce_refresh' );
add_action( 'wp_ajax_nopriv_a8csp_refresh_nonce', 'a8csp_handle_nonce_refresh' );

function a8csp_handle_chat_message() {
	// Verify nonce for security
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'a8csp-chat' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	// Check if message field exists and handle slashed data
	if ( ! isset( $_POST['message'] ) ) {
		wp_send_json_error( 'Missing message field' );
	}

	// Unslash and sanitize message (preserve newlines for textarea input)
	$message = wp_unslash( $_POST['message'] );
	$message = sanitize_textarea_field( $message ); // Better for multiline input
	$message = trim( $message );

	// Cap length to prevent abuse.
	$max_len = 2000;
	if ( strlen( $message ) > $max_len ) {
		$message = substr( $message, 0, $max_len );
	}

	if ( empty( $message ) ) {
		wp_send_json_error( 'Empty message' );
	}

	// Security: Start session with security settings
	if ( ! session_id() ) {
		// Security: Set secure session parameters
		if ( ! headers_sent() ) {
			session_set_cookie_params( array(
				'lifetime' => 0, // Session cookie
				'path' => '/',
				'domain' => '',
				'secure' => is_ssl(), // HTTPS only if available
				'httponly' => true, // Prevent XSS access to session cookie
				'samesite' => 'Strict' // CSRF protection
			) );
		}
		session_start();
		
		// Security: Regenerate session ID to prevent fixation
		if ( ! isset( $_SESSION['a8csp_session_started'] ) ) {
			session_regenerate_id( true );
			$_SESSION['a8csp_session_started'] = true;
		}
	}
	if ( ! isset( $_SESSION['frontend_chat_history'] ) ) {
		$_SESSION['frontend_chat_history'] = array();
	}

	// Add user message to history
	$_SESSION['frontend_chat_history'][] = array( 'role' => 'user', 'content' => $message );

	// Bound history growth - keep only last 20 messages (10 exchanges)
	$max_history = 20;
	if ( count( $_SESSION['frontend_chat_history'] ) > $max_history ) {
		$_SESSION['frontend_chat_history'] = array_slice( 
			$_SESSION['frontend_chat_history'], 
			-$max_history, 
			$max_history 
		);
	}

	// Guard against missing function
	if ( ! function_exists( 'get_bot_response' ) ) {
		wp_send_json_error( 'Chat service temporarily unavailable' );
	}

	// Get bot response with error handling
	try {
		$response = get_bot_response( $_SESSION['frontend_chat_history'] );
		
		// Validate response
		if ( empty( $response ) || ! is_string( $response ) ) {
			wp_send_json_error( 'Invalid response from chat service' );
		}

		// Add bot response to history
		$_SESSION['frontend_chat_history'][] = array( 'role' => 'assistant', 'content' => $response );

		wp_send_json_success( $response );

	} catch ( Exception $e ) {
		// Log error for debugging
		error_log( 'A8CSP Chat Error: ' . $e->getMessage() );
		wp_send_json_error( 'Chat service error occurred' );
	} catch ( Error $e ) {
		// Handle PHP fatal errors
		error_log( 'A8CSP Chat Fatal Error: ' . $e->getMessage() );
		wp_send_json_error( 'Chat service temporarily unavailable' );
	}
}

/**
 * Security: Handle nonce refresh for long chat sessions
 */
function a8csp_handle_nonce_refresh() {
	// Security: Rate limiting for nonce refresh (max 1 per minute per IP)
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	$rate_key = 'a8csp_nonce_refresh_' . md5( $ip );
	$last_refresh = get_transient( $rate_key );
	
	if ( $last_refresh ) {
		wp_send_json_error( 'Rate limited' );
	}
	
	// Set rate limit
	set_transient( $rate_key, time(), 60 ); // 1 minute
	
	// Return new nonce
	wp_send_json_success( array(
		'nonce' => wp_create_nonce( 'a8csp-chat' )
	) );
}

function a8csp_render_site_chatbot_block( $attributes ) {
	wp_enqueue_script( 'a8csp-site-chatbot-frontend' );
	wp_enqueue_style( 'a8csp-site-chatbot-style' );

	// Security: Localize script with secure AJAX configuration
	wp_localize_script( 'a8csp-site-chatbot-frontend', 'a8csp_ajax', array(
		'ajax_url' => esc_url( admin_url( 'admin-ajax.php' ) ),
		'nonce'    => wp_create_nonce( 'a8csp-chat' ),
		// Security: Add nonce refresh capability for long sessions
		'nonce_refresh_action' => 'a8csp_refresh_nonce',
	) );

	// Security: Start session with security settings
	if ( ! session_id() ) {
		// Security: Set secure session parameters
		if ( ! headers_sent() ) {
			session_set_cookie_params( array(
				'lifetime' => 0, // Session cookie
				'path' => '/',
				'domain' => '',
				'secure' => is_ssl(), // HTTPS only if available
				'httponly' => true, // Prevent XSS access to session cookie
				'samesite' => 'Strict' // CSRF protection
			) );
		}
		session_start();
		
		// Security: Regenerate session ID to prevent fixation
		if ( ! isset( $_SESSION['a8csp_session_started'] ) ) {
			session_regenerate_id( true );
			$_SESSION['a8csp_session_started'] = true;
		}
	}

	$history = isset( $_SESSION['frontend_chat_history'] ) ? $_SESSION['frontend_chat_history'] : array();

	ob_start();
	?>
	<div class="a8csp-chatbot">
		<div id="a8csp-chat-history">
			<?php foreach ( $history as $msg ) : ?>
				<div class="a8csp-chat-message <?php echo esc_attr( $msg['role'] === 'user' ? 'user-message' : 'bot-message' ); ?>">
					<strong><?php echo esc_html( ucfirst( $msg['role'] ) ); ?>:</strong> 
					<?php 
					if ( $msg['role'] === 'assistant' ) {
						// Bot messages may contain HTML from Markdown conversion
						echo wp_kses_post( $msg['content'] );
					} else {
						// User messages should be plain text
						echo esc_html( $msg['content'] );
					}
					?>
				</div>
			<?php endforeach; ?>
		</div>
		<form id="a8csp-chat-form">
			<textarea id="a8csp-chat-input" placeholder="Type your message..."></textarea>
			<button type="submit"><span class="dashicons dashicons-arrow-up-alt"></span></button>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
