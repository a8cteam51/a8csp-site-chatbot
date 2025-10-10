<?php
/**
 * Plugin Name: 51 Site Chatbot
 * Plugin URI:  https://github.com/a8cteam51/a8csp-site-chatbot
 * Update URI:  https://github.com/a8cteam51/a8csp-site-chatbot/
 * Description: Create embeddings from your site's content and chat with your site.
 * Version:     1.0.5
 * Author:      Automattic Special Projects (Team 51)
 * Author URI:  https://specialprojects.automattic.com
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

// Enqueue admin styles for all plugin pages
function a8csp_cws_admin_enqueue_scripts($hook) {
    // Only load on our plugin pages
    // Hook examples: 'toplevel_page_chat-with-site-sync', 'chatbot_page_chat-with-site-settings'
    if (strpos($hook, 'chat-with-site') === false) {
        return;
    }
    
    // Enqueue WordPress native admin styles for proper pagination and table styling
    wp_enqueue_style('list-tables');
    wp_enqueue_style('buttons');
    
    // Enqueue our custom admin styles (for settings page and additional styling)
    wp_enqueue_style(
        'a8csp-admin-style',
        plugins_url('assets/admin.css', __FILE__),
        array('list-tables', 'buttons'), // Depend on WordPress styles
        filemtime(plugin_dir_path(__FILE__) . 'assets/admin.css')
    );
}

// Include additional files
require_once plugin_dir_path(__FILE__) . 'includes/utils.php';
require_once plugin_dir_path(__FILE__) . 'includes/api-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/content-library.php';
require_once plugin_dir_path(__FILE__) . 'includes/chat-core.php';
require_once plugin_dir_path(__FILE__) . 'includes/block.php';


// Define all admin page functions first


add_action('admin_menu', 'a8csp_cws_admin_menu');
add_action('admin_enqueue_scripts', 'a8csp_cws_admin_enqueue_scripts');

add_action('admin_init', 'a8csp_cws_register_settings');

