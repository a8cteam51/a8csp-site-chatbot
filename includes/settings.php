<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

function a8csp_cws_admin_menu() {
	// Main menu page is now Content Library (most used feature)
	add_menu_page('51 Site Chatbot', '51 Chatbot', 'manage_options', 'chat-with-site-sync', 'a8csp_cws_sync_page', 'dashicons-format-chat');
	add_submenu_page('chat-with-site-sync', 'Content Library', 'Content Library', 'manage_options', 'chat-with-site-sync', 'a8csp_cws_sync_page');
	add_submenu_page('chat-with-site-sync', 'Settings', 'Settings', 'manage_options', 'chat-with-site-settings', 'a8csp_cws_settings_page');
}

function a8csp_cws_settings_page() {
	// Check for missing required settings
	$options = get_option('a8csp_chat_with_site_options', array());
	$warnings = a8csp_cws_check_required_settings( $options );
	
	?>
	<div class="wrap">
		<h1>51 Chatbot Settings</h1>
		
		<?php if (!empty($warnings)) : ?>
			<div class="notice notice-warning">
				<p><strong>Configuration Required:</strong></p>
				<ul>
					<?php foreach ($warnings as $warning) : ?>
						<li><?php echo esc_html($warning); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		
		<div class="metabox-holder a8csp-settings">
			<div class="postbox-container">
				<form method="post" action="options.php">
					<?php
					// Security: WordPress Settings API automatically handles nonces via settings_fields()
					settings_fields('chat_with_site_settings');
					?>
					
					<div class="meta-box-sortables">
						<!-- Chatbot Configuration Box -->
						<div class="postbox">
							<div class="postbox-header">
								<h2 class="hndle">Chatbot Configuration</h2>
							</div>
							<div class="inside">
								<?php a8csp_cws_do_settings_section('chat_with_site_settings', 'ai_chat_section'); ?>
							</div>
						</div>

						<!-- AI Service Configuration Box -->
						<div class="postbox">
							<div class="postbox-header">
								<h2 class="hndle">AI Service</h2>
							</div>
							<div class="inside">
								<?php a8csp_cws_do_settings_section('chat_with_site_settings', 'openai_section'); ?>
							</div>
						</div>

						<!-- Embeddings Configuration Box -->
						<div class="postbox">
							<div class="postbox-header">
								<h2 class="hndle">Embeddings and Vector Database</h2>
							</div>
							<div class="inside">
								<?php a8csp_cws_do_settings_section('chat_with_site_settings', 'pinecone_section'); ?>
							</div>
						</div>
					</div>
					
					<?php submit_button('Save Settings', 'primary', 'submit', true, array('style' => 'margin-top: 20px;')); ?>
				</form>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Helper function to render a specific settings section
 */
function a8csp_cws_do_settings_section($page, $section_id) {
	global $wp_settings_sections, $wp_settings_fields;

	if ( !isset($wp_settings_sections[$page][$section_id]) ) {
		return;
	}

	$section = $wp_settings_sections[$page][$section_id];
	
	// Call the section callback to display description
	if ( $section['callback'] ) {
		call_user_func($section['callback'], $section);
	}

	// Display all fields for this section
	if ( !isset($wp_settings_fields[$page][$section_id]) ) {
		return;
	}

	echo '<table class="form-table" role="presentation">';
	foreach ( (array) $wp_settings_fields[$page][$section_id] as $field ) {
		echo '<tr>';
		
		if ( !empty($field['args']['label_for']) ) {
			echo '<th scope="row"><label for="' . esc_attr($field['args']['label_for']) . '">' . $field['title'] . '</label></th>';
		} else {
			echo '<th scope="row">' . $field['title'] . '</th>';
		}
		
		echo '<td>';
		call_user_func($field['callback'], $field['args']);
		echo '</td>';
		echo '</tr>';
	}
	echo '</table>';
}

function a8csp_cws_pinecone_section_callback() {
	echo '<p class="a8csp-section-description">Configure your vector database settings. This stores the vectorized content for similarity search and semantic matching.</p>';
	echo '<div class="a8csp-help-box a8csp-help-box-gray">';
	echo '<strong>Vector Database Setup Steps:</strong><br>';
	echo '1. Create an account at <a href="https://pinecone.io" target="_blank" rel="noopener noreferrer">pinecone.io</a><br>';
	echo '2. Create an index with <strong>dimensions matching your embedding model</strong> (e.g., 1536 for text-embedding-3-small, 3072 for text-embedding-3-large)<br>';
	echo '3. Copy your API key and index URL from the dashboard';
	echo '</div>';
	echo '<div class="a8csp-test-connection">';
	echo '<button type="button" class="button button-secondary" id="a8csp-test-pinecone">Test Connection</button>';
	echo '<span class="a8csp-test-status" id="a8csp-pinecone-status"></span>';
	echo '</div>';
}

function a8csp_cws_openai_section_callback() {
	echo '<p class="a8csp-section-description">Configure your AI service provider settings. This handles <strong>chat completions</strong> and <strong>text embeddings</strong> generation.</p>';
	echo '<div class="a8csp-help-box a8csp-help-box-blue">';
	echo '<strong>AI Service Setup Steps:</strong><br>';
	echo '1. Create an account at <a href="https://openai.com" target="_blank" rel="noopener noreferrer">openai.com</a> (or your preferred AI provider)<br>';
	echo '2. Generate an API key from your dashboard<br>';
	echo '3. Select appropriate models for chat and embeddings<br>';
	echo '4. Organization ID is optional (only needed for organizations)';
	echo '</div>';
	echo '<div class="a8csp-test-connection">';
	echo '<button type="button" class="button button-secondary" id="a8csp-test-openai">Test Connection</button>';
	echo '<span class="a8csp-test-status" id="a8csp-openai-status"></span>';
	echo '</div>';
}

function a8csp_cws_pinecone_api_key_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['pinecone_api_key']) ? $options['pinecone_api_key'] : '';
	echo '<input type="password" id="pinecone_api_key" name="a8csp_chat_with_site_options[pinecone_api_key]" value="' . esc_attr($value) . '" size="70" autocomplete="off" autocapitalize="none" spellcheck="false" />';
	echo '<p class="description">Your Pinecone API key (starts with "pcsk_")</p>';
}

function a8csp_cws_pinecone_server_url_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['pinecone_server_url']) ? $options['pinecone_server_url'] : '';
	echo '<input type="url" id="pinecone_server_url" name="a8csp_chat_with_site_options[pinecone_server_url]" value="' . esc_attr($value) . '" size="70" />';
	echo '<p class="description">Your Pinecone index URL (e.g., https://your-index-abc123.svc.region.pinecone.io)</p>';
}

function a8csp_cws_pinecone_namespace_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['pinecone_namespace']) ? $options['pinecone_namespace'] : '';
	echo '<input type="text" id="pinecone_namespace" name="a8csp_chat_with_site_options[pinecone_namespace]" value="' . esc_attr($value) . '" size="50" />';
	echo '<p class="description">Optional namespace for organizing vectors</p>';
}

function a8csp_cws_openai_api_key_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
	echo '<input type="password" id="openai_api_key" name="a8csp_chat_with_site_options[openai_api_key]" value="' . esc_attr($value) . '" size="70" autocomplete="off" autocapitalize="none" spellcheck="false" />';
	echo '<p class="description">Your OpenAI API key (starts with "sk-")</p>';
}

function a8csp_cws_openai_org_id_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['openai_org_id']) ? $options['openai_org_id'] : '';
	echo '<input type="text" id="openai_org_id" name="a8csp_chat_with_site_options[openai_org_id]" value="' . esc_attr($value) . '" size="50" />';
	echo '<p class="description">Optional organization ID (only needed for organizations)</p>';
}

function a8csp_cws_openai_model_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['openai_model']) ? $options['openai_model'] : 'gpt-4o-mini';
	
	$models = array(
		'gpt-4o-mini' => 'GPT-4o Mini (Recommended)',
		'gpt-5-mini' => 'GPT-5 Mini',
		'gpt-5-nano' => 'GPT-5 Nano',
	);
	
	echo '<select id="openai_model" name="a8csp_chat_with_site_options[openai_model]">';
	foreach ($models as $model_key => $model_name) {
		echo '<option value="' . esc_attr($model_key) . '"' . selected($value, $model_key, false) . '>' . esc_html($model_name) . '</option>';
	}
	echo '</select>';
	echo '<p class="description">Model used for chat responses</p>';
}

function a8csp_cws_openai_embedding_model_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['openai_embedding_model']) ? $options['openai_embedding_model'] : 'text-embedding-3-small';
	
	$models = array(
		'text-embedding-3-small' => 'text-embedding-3-small (1536 dimensions, Recommended)',
		'text-embedding-3-large' => 'text-embedding-3-large (3072 dimensions)',
		'text-embedding-ada-002' => 'text-embedding-ada-002 (1536 dimensions, Legacy)',
	);
	
	echo '<select id="openai_embedding_model" name="a8csp_chat_with_site_options[openai_embedding_model]">';
	foreach ($models as $model_key => $model_name) {
		echo '<option value="' . esc_attr($model_key) . '"' . selected($value, $model_key, false) . '>' . esc_html($model_name) . '</option>';
	}
	echo '</select>';
	echo '<p class="description">Model used for generating embeddings. Make sure your Pinecone index dimensions match!</p>';
}

function a8csp_cws_validate_options($input) {
	$validated = array();
	
	// Validate Pinecone API Key
	if (isset($input['pinecone_api_key'])) {
		$validated['pinecone_api_key'] = sanitize_text_field($input['pinecone_api_key']);
	}
	
	// Validate Pinecone Server URL
	if (isset($input['pinecone_server_url'])) {
		$url = esc_url_raw($input['pinecone_server_url']);
		if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
			$validated['pinecone_server_url'] = $url;
		} else if (!empty($input['pinecone_server_url'])) {
			add_settings_error('a8csp_chat_with_site_options', 'invalid_url', 'Invalid Pinecone Server URL');
		}
	}
	
	// Validate Pinecone Namespace (commented out but keeping validation ready)
	if (isset($input['pinecone_namespace'])) {
		$validated['pinecone_namespace'] = sanitize_text_field($input['pinecone_namespace']);
	}
	
	// Validate OpenAI API Key
	if (isset($input['openai_api_key'])) {
		$validated['openai_api_key'] = sanitize_text_field($input['openai_api_key']);
	}
	
	// Validate OpenAI Org ID
	if (isset($input['openai_org_id'])) {
		$validated['openai_org_id'] = sanitize_text_field($input['openai_org_id']);
	}
	
	// Validate OpenAI Model
	if (isset($input['openai_model'])) {
		$valid_models = array('gpt-4o-mini', 'gpt-5-mini', 'gpt-5-nano');
		if (in_array($input['openai_model'], $valid_models)) {
			$validated['openai_model'] = $input['openai_model'];
		}
	}
	
	// Validate OpenAI Embedding Model
	if (isset($input['openai_embedding_model'])) {
		$valid_models = array('text-embedding-3-small', 'text-embedding-3-large', 'text-embedding-ada-002');
		if (in_array($input['openai_embedding_model'], $valid_models)) {
			$validated['openai_embedding_model'] = $input['openai_embedding_model'];
		}
	}
	
	// Validate Custom Prompt
	if (isset($input['custom_prompt'])) {
		$custom_prompt = sanitize_textarea_field($input['custom_prompt']);
		// Security: Limit prompt length to prevent resource exhaustion
		if (strlen($custom_prompt) > 2000) {
			$custom_prompt = substr($custom_prompt, 0, 2000);
			add_settings_error('a8csp_chat_with_site_options', 'prompt_too_long', 'Custom prompt was truncated to 2000 characters.');
		}
		$validated['custom_prompt'] = $custom_prompt;
	}
	
	return $validated;
}

function a8csp_cws_check_required_settings($options) {
	$warnings = array();
	
	if (empty($options['pinecone_api_key'])) {
		$warnings[] = 'Pinecone API Key is required for vector storage';
	}
	
	if (empty($options['pinecone_server_url'])) {
		$warnings[] = 'Pinecone Server URL is required for vector storage';
	}
	
	if (empty($options['openai_api_key'])) {
		$warnings[] = 'OpenAI API Key is required for embeddings and chat';
	}
	
	return $warnings;
}

function a8csp_cws_register_settings() {
	// Register setting group with modern WordPress syntax
	register_setting('chat_with_site_settings', 'a8csp_chat_with_site_options', array(
		'sanitize_callback' => 'a8csp_cws_validate_options',
		'default' => array(),
	));
	
	// AI Chat Settings Section - First section for main functionality
	add_settings_section(
		'ai_chat_section',
		'Chatbot Configuration',
		'a8csp_cws_ai_chat_section_callback',
		'chat_with_site_settings'
	);
	
	// OpenAI Settings Section  
	add_settings_section(
		'openai_section',
		'AI Service',
		'a8csp_cws_openai_section_callback',
		'chat_with_site_settings'
	);

	// Pinecone Settings Section
	add_settings_section(
		'pinecone_section',
		'Embeddings and Vector Database',
		'a8csp_cws_pinecone_section_callback',
		'chat_with_site_settings'
	);
	
	
	// AI Chat Settings - First field for main functionality
	add_settings_field(
		'custom_prompt',
		'Customize Chatbot Prompt (Optional)',
		'a8csp_cws_custom_prompt_callback',
		'chat_with_site_settings',
		'ai_chat_section'
	);
	
	// Pinecone Settings
	add_settings_field(
		'pinecone_api_key',
		'Pinecone API Key',
		'a8csp_cws_pinecone_api_key_callback',
		'chat_with_site_settings',
		'pinecone_section'
	);
	
	add_settings_field(
		'pinecone_server_url',
		'Pinecone Server URL',
		'a8csp_cws_pinecone_server_url_callback',
		'chat_with_site_settings',
		'pinecone_section'
	);
	
	add_settings_field(
		'pinecone_namespace',
		'Pinecone Namespace (Optional)',
		'a8csp_cws_pinecone_namespace_callback',
		'chat_with_site_settings',
		'pinecone_section'
	);
	
	// OpenAI Settings
	add_settings_field(
		'openai_api_key',
		'OpenAI API Key',
		'a8csp_cws_openai_api_key_callback',
		'chat_with_site_settings',
		'openai_section'
	);
	
	add_settings_field(
		'openai_org_id',
		'OpenAI Organization ID (Optional)',
		'a8csp_cws_openai_org_id_callback',
		'chat_with_site_settings',
		'openai_section'
	);
	
	add_settings_field(
		'openai_model',
		'OpenAI Chat Model',
		'a8csp_cws_openai_model_callback',
		'chat_with_site_settings',
		'openai_section'
	);
	
	add_settings_field(
		'openai_embedding_model',
		'OpenAI Embedding Model',
		'a8csp_cws_openai_embedding_model_callback',
		'chat_with_site_settings',
		'openai_section'
	);
}

function a8csp_cws_ai_chat_section_callback() {
	echo '<p class="a8csp-section-description">Customize how your Chatbot behaves and responds to users.</p>';
}

function a8csp_cws_custom_prompt_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value   = isset($options['custom_prompt']) ? $options['custom_prompt'] : '';
	$placeholder = "Personalize your chatbot's behavior, personality, tone, and expertise: (e.g. 'Your responses should be based solely on the provided context from the website's pages and posts...')";
	
	echo '<textarea id="custom_prompt" name="a8csp_chat_with_site_options[custom_prompt]" rows="8" cols="70" maxlength="1000" placeholder="' . esc_attr($placeholder) . '" spellcheck="false">' . esc_textarea($value) . '</textarea>';
}

function a8csp_cws_get_api_settings() {
	$options = get_option('a8csp_chat_with_site_options', array());
	return array(
		'pinecone_api_key' => isset($options['pinecone_api_key']) ? $options['pinecone_api_key'] : '',
		'pinecone_server_url' => isset($options['pinecone_server_url']) ? $options['pinecone_server_url'] : '',
		'pinecone_namespace' => isset($options['pinecone_namespace']) ? $options['pinecone_namespace'] : '',
		'openai_api_key' => isset($options['openai_api_key']) ? $options['openai_api_key'] : '',
		'openai_org_id' => isset($options['openai_org_id']) ? $options['openai_org_id'] : '',
		'openai_model' => isset($options['openai_model']) ? $options['openai_model'] : 'gpt-5-mini',
		'openai_embedding_model' => isset($options['openai_embedding_model']) ? $options['openai_embedding_model'] : 'text-embedding-3-small',
		'custom_prompt' => isset($options['custom_prompt']) ? $options['custom_prompt'] : '',
	);
}

/**
 * AJAX handler to test OpenAI API connection
 */
function a8csp_cws_test_openai_connection() {
	// Security: Verify nonce and capabilities
	check_ajax_referer('a8csp_test_connection', 'nonce');
	
	if (!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => 'Unauthorized'));
	}
	
	$options = get_option('a8csp_chat_with_site_options', array());
	$api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
	$org_id = isset($options['openai_org_id']) ? $options['openai_org_id'] : '';
	
	if (empty($api_key)) {
		wp_send_json_error(array('message' => 'OpenAI API Key is not configured'));
	}
	
	// Test connection by listing models (lightweight endpoint)
	$url = 'https://api.openai.com/v1/models';
	
	$headers = array(
		'Authorization' => 'Bearer ' . $api_key,
	);
	
	if (!empty($org_id)) {
		$headers['OpenAI-Organization'] = $org_id;
	}
	
	$response = wp_remote_get($url, array(
		'headers' => $headers,
		'timeout' => 15,
	));
	
	if (is_wp_error($response)) {
		wp_send_json_error(array('message' => 'Connection failed: ' . $response->get_error_message()));
	}
	
	$response_code = wp_remote_retrieve_response_code($response);
	
	if ($response_code === 200) {
		wp_send_json_success(array('message' => 'Connection successful!'));
	} elseif ($response_code === 401) {
		wp_send_json_error(array('message' => 'Invalid API key'));
	} elseif ($response_code === 403) {
		wp_send_json_error(array('message' => 'Access denied - check your API key permissions'));
	} else {
		wp_send_json_error(array('message' => 'Connection failed with HTTP ' . $response_code));
	}
}
add_action('wp_ajax_a8csp_test_openai', 'a8csp_cws_test_openai_connection');

/**
 * AJAX handler to test Pinecone API connection
 */
function a8csp_cws_test_pinecone_connection() {
	// Security: Verify nonce and capabilities
	check_ajax_referer('a8csp_test_connection', 'nonce');
	
	if (!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => 'Unauthorized'));
	}
	
	$options = get_option('a8csp_chat_with_site_options', array());
	$api_key = isset($options['pinecone_api_key']) ? $options['pinecone_api_key'] : '';
	$server_url = isset($options['pinecone_server_url']) ? $options['pinecone_server_url'] : '';
	
	if (empty($api_key)) {
		wp_send_json_error(array('message' => 'Pinecone API Key is not configured'));
	}
	
	if (empty($server_url)) {
		wp_send_json_error(array('message' => 'Pinecone Server URL is not configured'));
	}
	
	// Validate URL format
	if (!filter_var($server_url, FILTER_VALIDATE_URL)) {
		wp_send_json_error(array('message' => 'Invalid Pinecone Server URL format'));
	}
	
	// Test connection by fetching index stats (lightweight endpoint)
	$url = rtrim($server_url, '/') . '/describe_index_stats';
	
	$headers = array(
		'Api-Key' => $api_key,
		'Content-Type' => 'application/json',
	);
	
	$response = wp_remote_post($url, array(
		'headers' => $headers,
		'body' => '{}',
		'timeout' => 15,
	));
	
	if (is_wp_error($response)) {
		wp_send_json_error(array('message' => 'Connection failed: ' . $response->get_error_message()));
	}
	
	$response_code = wp_remote_retrieve_response_code($response);
	
	if ($response_code === 200) {
		$body = json_decode(wp_remote_retrieve_body($response), true);
		$vector_count = isset($body['totalVectorCount']) ? intval($body['totalVectorCount']) : 0;
		wp_send_json_success(array('message' => 'Connection successful! Index contains ' . number_format($vector_count) . ' vectors.'));
	} elseif ($response_code === 401 || $response_code === 403) {
		wp_send_json_error(array('message' => 'Invalid API key or access denied'));
	} elseif ($response_code === 404) {
		wp_send_json_error(array('message' => 'Index not found - check your Server URL'));
	} else {
		wp_send_json_error(array('message' => 'Connection failed with HTTP ' . $response_code));
	}
}
add_action('wp_ajax_a8csp_test_pinecone', 'a8csp_cws_test_pinecone_connection');

/**
 * Enqueue settings page JavaScript
 */
function a8csp_cws_settings_scripts($hook) {
	// Only load on our settings page
	if (strpos($hook, 'chat-with-site-settings') === false) {
		return;
	}
	
	wp_add_inline_script('jquery', '
		jQuery(document).ready(function($) {
			var nonce = "' . wp_create_nonce('a8csp_test_connection') . '";
			
			$("#a8csp-test-openai").on("click", function() {
				var $btn = $(this);
				var $status = $("#a8csp-openai-status");
				
				$btn.prop("disabled", true);
				$status.removeClass("success error").addClass("loading").text("Testing...");
				
				$.post(ajaxurl, {
					action: "a8csp_test_openai",
					nonce: nonce
				}, function(response) {
					$btn.prop("disabled", false);
					$status.removeClass("loading");
					
					if (response.success) {
						$status.addClass("success").text(response.data.message);
					} else {
						$status.addClass("error").text(response.data.message);
					}
				}).fail(function() {
					$btn.prop("disabled", false);
					$status.removeClass("loading").addClass("error").text("Request failed");
				});
			});
			
			$("#a8csp-test-pinecone").on("click", function() {
				var $btn = $(this);
				var $status = $("#a8csp-pinecone-status");
				
				$btn.prop("disabled", true);
				$status.removeClass("success error").addClass("loading").text("Testing...");
				
				$.post(ajaxurl, {
					action: "a8csp_test_pinecone",
					nonce: nonce
				}, function(response) {
					$btn.prop("disabled", false);
					$status.removeClass("loading");
					
					if (response.success) {
						$status.addClass("success").text(response.data.message);
					} else {
						$status.addClass("error").text(response.data.message);
					}
				}).fail(function() {
					$btn.prop("disabled", false);
					$status.removeClass("loading").addClass("error").text("Request failed");
				});
			});
		});
	');
}
add_action('admin_enqueue_scripts', 'a8csp_cws_settings_scripts');
