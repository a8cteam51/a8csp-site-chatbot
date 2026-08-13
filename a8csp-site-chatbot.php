<?php
/**
 * Plugin Name: 51 Site Chatbot
 * Plugin URI:  https://github.com/a8cteam51/a8csp-site-chatbot
 * Update URI:  https://github.com/a8cteam51/a8csp-site-chatbot/
 * Description: Create embeddings from your site's content and chat with your site.
 * Version:     1.2.1
 * Author:      Automattic Special Projects (Team 51)
 * Author URI:  https://specialprojects.automattic.com
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// Load Composer autoloader
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Load AI provider registry (needed before constants)
require_once plugin_dir_path(__FILE__) . 'includes/ai-providers.php';

// Get the options array
$chat_options = get_option('a8csp_chat_with_site_options', array());

// Initialize constants from options array
// TODO: Encrypt these values in the database.
define('AI_PROVIDER', $chat_options['ai_provider'] ?? 'openai');
define('PINECONE_API_KEY', $chat_options['pinecone_api_key'] ?? '');
define('PINECONE_SERVER_URL', $chat_options['pinecone_server_url'] ?? '');
define('PINECONE_NAMESPACE', $chat_options['pinecone_namespace'] ?? '');

// Provider-specific constants defined dynamically from the registry.
// Each field_id becomes an UPPER_CASE constant (e.g. openai_model → OPENAI_MODEL).
foreach (a8csp_cws_get_ai_providers() as $a8csp_pid => $a8csp_pconf) {
	foreach ($a8csp_pconf['fields'] as $a8csp_fid => $a8csp_fconf) {
		$a8csp_const = strtoupper($a8csp_fid);
		if (!defined($a8csp_const)) {
			define($a8csp_const, $chat_options[$a8csp_fid] ?? ($a8csp_fconf['default'] ?? ''));
		}
	}
}
unset($a8csp_pid, $a8csp_pconf, $a8csp_fid, $a8csp_fconf, $a8csp_const);



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

