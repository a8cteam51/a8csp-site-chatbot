<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

function chat_with_site_admin_menu() {
	// Main menu page is now Content Library (most used feature)
	add_menu_page('51 Site Chatbot', '51 Chatbot', 'manage_options', 'chat-with-site-sync', 'chat_with_site_sync_page', 'dashicons-format-chat');
	add_submenu_page('chat-with-site-sync', 'Content Library', 'Content Library', 'manage_options', 'chat-with-site-sync', 'chat_with_site_sync_page');
	add_submenu_page('chat-with-site-sync', 'Settings', 'Settings', 'manage_options', 'chat-with-site-settings', 'chat_with_site_settings_page');
}

function chat_with_site_settings_page() {
	// Check for missing required settings
	$options = get_option('a8csp_chat_with_site_options', array());
	$warnings = chat_with_site_check_required_settings($options);
	
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
			<div class="postbox-container" style="width: 75%;">
				<div class="meta-box-sortables">
					<div class="postbox">
						<div class="postbox-header">
							<h2 class="hndle">Plugin Configuration</h2>
						</div>
						<div class="inside">
							<form method="post" action="options.php">
								<?php
								// Security: WordPress Settings API automatically handles nonces via settings_fields()
								settings_fields('chat_with_site_settings');
								do_settings_sections('chat_with_site_settings');
								submit_button('Save Settings', 'primary', 'submit', true, array('style' => 'margin-top: 20px;'));
								?>
							</form>
						</div>
					</div>
				</div>
			</div>
			
			<div class="postbox-container" style="width: 25%;">
				<div class="meta-box-sortables">
					<div class="postbox">
						<div class="postbox-header">
							<h2 class="hndle">Quick Help</h2>
						</div>
						<div class="inside">
							<h4>API Keys Required</h4>
							<p><small>Both Pinecone and OpenAI API keys are required for the chatbot to function.</small></p>
							<p><small><strong>Pinecone</strong> stores embeddings (vector representations) of your content, enabling fast semantic search. <br><strong>OpenAI</strong> generates these embeddings, Pinecone's vector database is essential for efficiently finding relevant content when users ask questions.</small></p>
							<p><small>API keys are stored securely on your WordPress database, and never logged or sent to any other servers.</small></p>

							
							<h4>Model Recommendations</h4>
							<ul style="font-size: 12px; margin-left: 15px;">
								<li><strong>Chat:</strong> gpt-4o-mini (cost-effective)</li>
								<li><strong>Embeddings:</strong> text-embedding-3-small</li>
							</ul>

							<h4>Cost Optimization</h4>
							<ul style="font-size: 12px; margin-left: 15px;">
								<li>Sync only relevant content</li>
								<li>Use shorter responses (fewer tokens)</li>
								<li>Monitor API usage regularly</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
}

function chat_with_site_pinecone_section_callback() {
	echo '<p style="margin-top: 0;">Configure your Pinecone vector database settings. Pinecone stores the vectorized content for similarity search.</p>';
	echo '<div style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin-bottom: 20px;">';
	echo '<strong>Pinecone Setup Steps:</strong><br>';
	echo '1. Create an account at <a href="https://pinecone.io" target="_blank">pinecone.io</a><br>';
	echo '2. Create an index with <strong>1536 dimensions</strong><br>';
	echo '3. Copy your API key and index URL from the dashboard';
	echo '</div>';
}

function chat_with_site_openai_section_callback() {
	echo '<p style="margin-top: 0;">Configure your OpenAI API settings. OpenAI provides the embeddings and chat completion services.</p>';
	echo '<div style="background: #f0f8ff; border: 1px solid #c3d9ff; padding: 15px; border-radius: 4px; margin-bottom: 20px;">';
	echo '<strong>OpenAI Setup Steps:</strong><br>';
	echo '1. Create an account at <a href="https://openai.com" target="_blank">openai.com</a><br>';
	echo '2. Generate an API key from your dashboard<br>';
	echo '3. Organization ID is optional (only needed for organizations)';
	echo '</div>';
}

function chat_with_site_pinecone_api_key_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['pinecone_api_key']) ? $options['pinecone_api_key'] : '';
	echo '<input type="password" id="pinecone_api_key" name="a8csp_chat_with_site_options[pinecone_api_key]" value="' . esc_attr($value) . '" size="70" />';
	echo '<p class="description">Your Pinecone API key (starts with "pcsk_")</p>';
}

function chat_with_site_pinecone_server_url_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['pinecone_server_url']) ? $options['pinecone_server_url'] : '';
	echo '<input type="url" id="pinecone_server_url" name="a8csp_chat_with_site_options[pinecone_server_url]" value="' . esc_attr($value) . '" size="70" />';
	echo '<p class="description">Your Pinecone index URL (e.g., https://your-index-abc123.svc.region.pinecone.io)</p>';
}

function chat_with_site_pinecone_namespace_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['pinecone_namespace']) ? $options['pinecone_namespace'] : '';
	echo '<input type="text" id="pinecone_namespace" name="a8csp_chat_with_site_options[pinecone_namespace]" value="' . esc_attr($value) . '" size="50" />';
	echo '<p class="description">Optional namespace for organizing vectors</p>';
}

function chat_with_site_openai_api_key_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
	echo '<input type="password" id="openai_api_key" name="a8csp_chat_with_site_options[openai_api_key]" value="' . esc_attr($value) . '" size="70" />';
	echo '<p class="description">Your OpenAI API key (starts with "sk-")</p>';
}

function chat_with_site_openai_org_id_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['openai_org_id']) ? $options['openai_org_id'] : '';
	echo '<input type="text" id="openai_org_id" name="a8csp_chat_with_site_options[openai_org_id]" value="' . esc_attr($value) . '" size="50" />';
	echo '<p class="description">Optional organization ID (only needed for organizations)</p>';
}

function chat_with_site_openai_model_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['openai_model']) ? $options['openai_model'] : 'gpt-4o-mini';
	
	$models = array(
		'gpt-4o-mini' => 'GPT-4o Mini (Recommended)',
		'gpt-4o' => 'GPT-4o',
		'gpt-4-turbo' => 'GPT-4 Turbo',
		'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
	);
	
	echo '<select id="openai_model" name="a8csp_chat_with_site_options[openai_model]">';
	foreach ($models as $model_key => $model_name) {
		echo '<option value="' . esc_attr($model_key) . '"' . selected($value, $model_key, false) . '>' . esc_html($model_name) . '</option>';
	}
	echo '</select>';
	echo '<p class="description">Model used for chat responses</p>';
}

function chat_with_site_openai_embedding_model_callback() {
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

function chat_with_site_validate_options($input) {
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
	
	// Validate Pinecone Namespace
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
		$valid_models = array('gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo');
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
	
	return $validated;
}

function chat_with_site_check_required_settings($options) {
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

function chat_with_site_register_settings() {
	// Register setting group
	register_setting('chat_with_site_settings', 'a8csp_chat_with_site_options', 'chat_with_site_validate_options');
	
	// Pinecone Settings Section
	add_settings_section(
		'pinecone_section',
		'Pinecone Configuration',
		'chat_with_site_pinecone_section_callback',
		'chat_with_site_settings'
	);
	
	// OpenAI Settings Section  
	add_settings_section(
		'openai_section',
		'OpenAI Configuration',
		'chat_with_site_openai_section_callback',
		'chat_with_site_settings'
	);
	
	// Pinecone Settings
	add_settings_field(
		'pinecone_api_key',
		'Pinecone API Key',
		'chat_with_site_pinecone_api_key_callback',
		'chat_with_site_settings',
		'pinecone_section'
	);
	
	add_settings_field(
		'pinecone_server_url',
		'Pinecone Server URL',
		'chat_with_site_pinecone_server_url_callback',
		'chat_with_site_settings',
		'pinecone_section'
	);
	
	// Namespace field disabled for now - will be handled in Content Library
	// add_settings_field(
	// 	'pinecone_namespace',
	// 	'Pinecone Namespace (Optional)',
	// 	'chat_with_site_pinecone_namespace_callback',
	// 	'chat_with_site_settings',
	// 	'pinecone_section'
	// );
	
	// OpenAI Settings
	add_settings_field(
		'openai_api_key',
		'OpenAI API Key',
		'chat_with_site_openai_api_key_callback',
		'chat_with_site_settings',
		'openai_section'
	);
	
	add_settings_field(
		'openai_org_id',
		'OpenAI Organization ID (Optional)',
		'chat_with_site_openai_org_id_callback',
		'chat_with_site_settings',
		'openai_section'
	);
	
	add_settings_field(
		'openai_model',
		'OpenAI Chat Model',
		'chat_with_site_openai_model_callback',
		'chat_with_site_settings',
		'openai_section'
	);
	
	add_settings_field(
		'openai_embedding_model',
		'OpenAI Embedding Model',
		'chat_with_site_openai_embedding_model_callback',
		'chat_with_site_settings',
		'openai_section'
	);
}

function chat_with_site_get_api_settings() {
	$options = get_option('a8csp_chat_with_site_options', array());
	return array(
		'pinecone_api_key' => isset($options['pinecone_api_key']) ? $options['pinecone_api_key'] : '',
		'pinecone_server_url' => isset($options['pinecone_server_url']) ? $options['pinecone_server_url'] : '',
		'pinecone_namespace' => isset($options['pinecone_namespace']) ? $options['pinecone_namespace'] : '',
		'openai_api_key' => isset($options['openai_api_key']) ? $options['openai_api_key'] : '',
		'openai_org_id' => isset($options['openai_org_id']) ? $options['openai_org_id'] : '',
		'openai_model' => isset($options['openai_model']) ? $options['openai_model'] : 'gpt-4o-mini',
		'openai_embedding_model' => isset($options['openai_embedding_model']) ? $options['openai_embedding_model'] : 'text-embedding-3-small',
	);
}
