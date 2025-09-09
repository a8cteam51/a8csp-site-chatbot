<?php
/**
 * Plugin Name: A8CSP Site Chatbot
 * Description: Chat with your site.
 * Version: 1.0.3
 * Author: WPCOM Special Projects - Team 51
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// Load Composer autoloader
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Get the options array
$chat_options = get_option('a8csp_chat_with_site_options', array());

// Initialize constants from options array
// TODO: Encrypt these values in the database.
define('PINECONE_API_KEY', $chat_options['pinecone_api_key'] ?? '');
define('PINECONE_SERVER_URL', $chat_options['pinecone_server_url'] ?? '');
define('OPENAI_API_KEY', $chat_options['openai_api_key'] ?? '');
define('OPENAI_ORG_ID', $chat_options['openai_org_id'] ?? '');
define('OPENAI_MODEL', $chat_options['openai_model'] ?? 'gpt-4o-mini');
define('OPENAI_EMBEDDING_MODEL', $chat_options['openai_embedding_model'] ?? 'text-embedding-3-small');
define('PINECONE_NAMESPACE', $chat_options['pinecone_namespace'] ?? '');



// Plugin activation hook
register_activation_hook(__FILE__, 'a8csp_cws_activate');

function a8csp_cws_activate() {
    // Nothing specific needed for activation currently
}

// Include additional files
require_once plugin_dir_path(__FILE__) . 'includes/api-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/content-library.php';
require_once plugin_dir_path(__FILE__) . 'includes/chat-core.php';
require_once plugin_dir_path(__FILE__) . 'includes/block.php';


// Define all admin page functions first


add_action('admin_menu', 'a8csp_cws_admin_menu');

add_action('admin_init', 'a8csp_cws_register_settings');

