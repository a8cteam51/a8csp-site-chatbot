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
								<h2 class="hndle">Vector Database</h2>
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
	// Build JS config from the provider registry so the toggle logic is fully data-driven.
	$providers = a8csp_cws_get_ai_providers();
	$provider_field_map = array();
	foreach ($providers as $pid => $pconfig) {
		$provider_field_map[$pid] = array_keys($pconfig['fields']);
	}
	?>
	<script>
	(function() {
		var providerSelect = document.getElementById('ai_provider');
		if (!providerSelect) return;

		var providerFields = <?php echo wp_json_encode($provider_field_map); ?>;

		function toggleProviderFields() {
			var provider = providerSelect.value;

			Object.keys(providerFields).forEach(function(pid) {
				providerFields[pid].forEach(function(fieldId) {
					var el = document.getElementById(fieldId);
					if (el) el.closest('tr').style.display = pid === provider ? '' : 'none';
				});

				var helpBox = document.getElementById('a8csp-ai-help-' + pid);
				if (helpBox) helpBox.style.display = pid === provider ? '' : 'none';
			});
		}

		providerSelect.addEventListener('change', toggleProviderFields);
		toggleProviderFields();
	})();
	</script>
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
}

function a8csp_cws_openai_section_callback() {
	$providers = a8csp_cws_get_ai_providers();
	$options = get_option('a8csp_chat_with_site_options');
	$current = isset($options['ai_provider']) ? $options['ai_provider'] : 'openai';

	echo '<p class="a8csp-section-description">Configure your AI service provider settings. This handles <strong>chat completions</strong> and <strong>text embeddings</strong> generation.</p>';

	foreach ($providers as $provider_id => $provider) {
		$hidden = ($provider_id !== $current) ? ' style="display:none;"' : '';
		printf('<div class="a8csp-help-box a8csp-help-box-blue" id="a8csp-ai-help-%s"%s>', esc_attr($provider_id), $hidden);
		echo '<strong>AI Service Setup Steps:</strong><br>';
		$step_num = 1;
		foreach ($provider['help_steps'] as $step) {
			echo $step_num . '. ' . wp_kses_post($step) . '<br>';
			$step_num++;
		}
		echo '</div>';
	}
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
	
	// Validate AI Provider
	$providers = a8csp_cws_get_ai_providers();
	if (isset($input['ai_provider'])) {
		if (array_key_exists($input['ai_provider'], $providers)) {
			$validated['ai_provider'] = $input['ai_provider'];
		}
	}

	// Validate all provider-specific fields from the registry
	foreach ($providers as $provider_id => $provider) {
		foreach ($provider['fields'] as $field_id => $field_config) {
			if (!isset($input[$field_id])) {
				continue;
			}
			switch ($field_config['type']) {
				case 'password':
				case 'text':
					$validated[$field_id] = sanitize_text_field($input[$field_id]);
					break;
				case 'select':
					if (isset($field_config['options']) && array_key_exists($input[$field_id], $field_config['options'])) {
						$validated[$field_id] = $input[$field_id];
					}
					break;
			}
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
	$provider_id = isset($options['ai_provider']) ? $options['ai_provider'] : 'openai';
	$providers = a8csp_cws_get_ai_providers();
	$current = isset($providers[$provider_id]) ? $providers[$provider_id] : null;

	if (empty($options['pinecone_api_key'])) {
		$warnings[] = 'Pinecone API Key is required for vector storage';
	}

	if (empty($options['pinecone_server_url'])) {
		$warnings[] = 'Pinecone Server URL is required for vector storage';
	}

	// Check provider-specific required fields from the registry
	if ($current) {
		foreach ($current['fields'] as $field_id => $field_config) {
			if (!empty($field_config['required']) && empty($options[$field_id])) {
				$warnings[] = $field_config['label'] . ' is required';
			}
		}
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
		'Vector Database',
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
	
	// AI Provider Selector
	add_settings_field(
		'ai_provider',
		'AI Chat Provider',
		'a8csp_cws_ai_provider_callback',
		'chat_with_site_settings',
		'openai_section'
	);

	// All provider fields (chat + embedding) registered dynamically from the registry
	$providers = a8csp_cws_get_ai_providers();
	foreach ($providers as $provider_id => $provider) {
		foreach ($provider['fields'] as $field_id => $field_config) {
			add_settings_field(
				$field_id,
				$field_config['label'],
				'a8csp_cws_render_provider_field',
				'chat_with_site_settings',
				'openai_section',
				array(
					'field_id' => $field_id,
					'config' => $field_config,
				)
			);
		}
	}
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

	// Common settings (always present regardless of provider)
	$settings = array(
		'ai_provider' => isset($options['ai_provider']) ? $options['ai_provider'] : 'openai',
		'pinecone_api_key' => isset($options['pinecone_api_key']) ? $options['pinecone_api_key'] : '',
		'pinecone_server_url' => isset($options['pinecone_server_url']) ? $options['pinecone_server_url'] : '',
		'pinecone_namespace' => isset($options['pinecone_namespace']) ? $options['pinecone_namespace'] : '',
		'custom_prompt' => isset($options['custom_prompt']) ? $options['custom_prompt'] : '',
	);

	// Merge in all provider-specific settings with their defaults
	foreach (a8csp_cws_get_ai_providers() as $provider_id => $provider) {
		foreach ($provider['fields'] as $field_id => $field_config) {
			$default = isset($field_config['default']) ? $field_config['default'] : '';
			$settings[$field_id] = isset($options[$field_id]) ? $options[$field_id] : $default;
		}
	}

	return $settings;
}
