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
		'script' => 'a8csp-site-chatbot-frontend',
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

// Add AJAX handler for chat messages
add_action( 'wp_ajax_a8csp_chat_message', 'a8csp_handle_chat_message' );
add_action( 'wp_ajax_nopriv_a8csp_chat_message', 'a8csp_handle_chat_message' );

function a8csp_handle_chat_message() {
	check_ajax_referer( 'a8csp-chat', 'nonce' );

	$message = sanitize_text_field( $_POST['message'] );
	if ( empty( $message ) ) {
		wp_send_json_error( 'Empty message' );
	}

	// Retrieve or initialize chat history from session
	if ( ! session_id() ) {
		session_start();
	}
	if ( ! isset( $_SESSION['frontend_chat_history'] ) ) {
		$_SESSION['frontend_chat_history'] = array();
	}

	$_SESSION['frontend_chat_history'][] = array( 'role' => 'user', 'content' => $message );
	$response = get_bot_response( $_SESSION['frontend_chat_history'] );
	$_SESSION['frontend_chat_history'][] = array( 'role' => 'assistant', 'content' => $response );

	wp_send_json_success( $response );
}

function a8csp_render_site_chatbot_block( $attributes ) {
	wp_enqueue_script( 'a8csp-site-chatbot-frontend' );
	wp_enqueue_style( 'a8csp-site-chatbot-style' );

	// Localize script for AJAX
	wp_localize_script( 'a8csp-site-chatbot-frontend', 'a8csp_ajax', array(
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'a8csp-chat' ),
	) );

	// Start session for frontend
	if ( ! session_id() ) {
		session_start();
	}

	$history = isset( $_SESSION['frontend_chat_history'] ) ? $_SESSION['frontend_chat_history'] : array();

	ob_start();
	?>
	<div class="a8csp-chatbot">
		<div id="a8csp-chat-history">
			<?php foreach ( $history as $msg ) : ?>
				<div class="a8csp-chat-message <?php echo esc_attr( $msg['role'] === 'user' ? 'user-message' : 'bot-message' ); ?>">
					<strong><?php echo ucfirst( $msg['role'] ); ?>:</strong> <?php echo esc_html( $msg['content'] ); ?>
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
