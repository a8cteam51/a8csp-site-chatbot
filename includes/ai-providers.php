<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * AI Provider Registry
 *
 * Each provider defines its complete set of fields — both for chat completions
 * and for text embeddings. To add a new provider, add an entry to the array
 * returned by a8csp_cws_get_ai_providers(). The settings page, field rendering,
 * validation, and PHP constants will all be handled automatically.
 *
 * Provider entry keys:
 *   'label'      => Display name shown in the provider dropdown.
 *   'fields'     => Associative array of field_id => config. Each field becomes
 *                   a settings row and a PHP constant (UPPER_CASE of field_id).
 *     Field config keys:
 *       'label'       => Row label in the settings table.
 *       'type'        => 'text', 'password', or 'select'.
 *       'size'        => Input size attribute (text/password only, default 50/70).
 *       'description' => Help text shown below the input.
 *       'default'     => Default value when no option is stored.
 *       'options'     => Associative array of value => label (select type only).
 *       'required'    => (bool) If true, a warning is shown when the field is empty.
 *   'help_steps' => Array of HTML strings rendered as numbered steps in the help box.
 */
function a8csp_cws_get_ai_providers() {
	return array(
		'openai' => array(
			'label' => 'OpenAI (GPT)',
			'fields' => array(
				'openai_api_key' => array(
					'label' => 'API Key',
					'type' => 'password',
					'size' => 70,
					'description' => 'Your OpenAI API key (starts with "sk-")',
					'default' => '',
					'required' => true,
				),
				'openai_org_id' => array(
					'label' => 'Organization ID (Optional)',
					'type' => 'text',
					'size' => 50,
					'description' => 'Optional organization ID (only needed for organizations)',
					'default' => '',
				),
				'openai_model' => array(
					'label' => 'Chat Model',
					'type' => 'select',
					'description' => 'Model used for chat responses',
					'default' => 'gpt-4o-mini',
					'options' => array(
						'gpt-4o-mini' => 'GPT-4o Mini (Recommended)',
						'gpt-5-mini' => 'GPT-5 Mini',
						'gpt-5-nano' => 'GPT-5 Nano',
					),
				),
				'openai_embedding_model' => array(
					'label' => 'Embedding Model',
					'type' => 'select',
					'description' => 'Model used for generating embeddings. Make sure your Pinecone index dimensions match!',
					'default' => 'text-embedding-3-small',
					'options' => array(
						'text-embedding-3-small' => 'text-embedding-3-small (1536 dimensions, Recommended)',
						'text-embedding-3-large' => 'text-embedding-3-large (3072 dimensions)',
						'text-embedding-ada-002' => 'text-embedding-ada-002 (1536 dimensions, Legacy)',
					),
				),
			),
			'help_steps' => array(
				'Create an account at <a href="https://openai.com" target="_blank" rel="noopener noreferrer">openai.com</a>',
				'Generate an API key from your dashboard',
				'Select appropriate models for chat and embeddings',
				'Organization ID is optional (only needed for organizations)',
			),
		),
		'anthropic' => array(
			'label' => 'Anthropic (Claude)',
			'fields' => array(
				'anthropic_api_key' => array(
					'label' => 'Anthropic API Key',
					'type' => 'password',
					'size' => 70,
					'description' => 'Your Anthropic API key (starts with "sk-ant-")',
					'default' => '',
					'required' => true,
				),
				'anthropic_model' => array(
					'label' => 'Claude Chat Model',
					'type' => 'select',
					'description' => 'Model used for chat responses',
					'default' => 'claude-sonnet-5',
					'options' => array(
						'claude-sonnet-5' => 'Claude Sonnet 5 (Recommended)',
						'claude-haiku-4-5' => 'Claude Haiku 4.5 (Faster)',
						'claude-opus-5' => 'Claude Opus 5',
					),
				),
				'voyage_api_key' => array(
					'label' => 'Voyage AI API Key (for Embeddings)',
					'type' => 'password',
					'size' => 70,
					'description' => 'Anthropic recommends <a href="https://voyageai.com" target="_blank" rel="noopener noreferrer">Voyage AI</a> for text embeddings',
					'default' => '',
					'required' => true,
				),
				'voyage_embedding_model' => array(
					'label' => 'Voyage Embedding Model',
					'type' => 'select',
					'description' => 'Model used for generating embeddings. Make sure your Pinecone index dimensions match!',
					'default' => 'voyage-3-large',
					'options' => array(
						'voyage-3-large' => 'voyage-3-large (1024 dimensions, Recommended)',
						'voyage-4-large' => 'voyage-4-large (1024 dimensions)',
						'voyage-4-lite' => 'voyage-4-lite (1024 dimensions, Faster)',
					),
				),
			),
			'help_steps' => array(
				'Create an account at <a href="https://console.anthropic.com" target="_blank" rel="noopener noreferrer">console.anthropic.com</a> for chat',
				'Create an account at <a href="https://dash.voyageai.com" target="_blank" rel="noopener noreferrer">dash.voyageai.com</a> for embeddings',
				'Generate API keys from both dashboards',
				'Anthropic does not offer its own embeddings — Voyage AI is their recommended partner',
			),
		),
		'google' => array(
			'label' => 'Google (Gemini)',
			'fields' => array(
				'google_api_key' => array(
					'label' => 'Google AI API Key',
					'type' => 'password',
					'size' => 70,
					'description' => 'Your Google AI Studio API key (starts with "AIza")',
					'default' => '',
					'required' => true,
				),
				'google_model' => array(
					'label' => 'Gemini Chat Model',
					'type' => 'select',
					'description' => 'Model used for chat responses',
					'default' => 'gemini-2.5-flash',
					'options' => array(
						'gemini-2.5-flash' => 'Gemini 2.5 Flash (Recommended)',
						'gemini-2.5-pro' => 'Gemini 2.5 Pro',
						'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite (Faster)',
					),
				),
				'google_embedding_model' => array(
					'label' => 'Gemini Embedding Model',
					'type' => 'select',
					'description' => 'Model used for generating embeddings. Make sure your Pinecone index dimensions match!',
					'default' => 'gemini-embedding-001',
					'options' => array(
						'gemini-embedding-001' => 'gemini-embedding-001 (768–3072 dimensions, Recommended)',
						'text-embedding-004' => 'text-embedding-004 (768 dimensions, Legacy)',
					),
				),
			),
			'help_steps' => array(
				'Create an account at <a href="https://aistudio.google.com" target="_blank" rel="noopener noreferrer">aistudio.google.com</a>',
				'Generate an API key from the dashboard',
				'One API key covers both chat and embeddings',
				'Select a Gemini model for chat and an embedding model for vectorization',
			),
		),
	);
}

/**
 * Render the AI provider selector dropdown.
 * Options are populated dynamically from the provider registry.
 */
function a8csp_cws_ai_provider_callback() {
	$options = get_option('a8csp_chat_with_site_options');
	$value = isset($options['ai_provider']) ? $options['ai_provider'] : 'openai';
	$providers = a8csp_cws_get_ai_providers();

	echo '<select id="ai_provider" name="a8csp_chat_with_site_options[ai_provider]">';
	foreach ($providers as $key => $provider) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr($key),
			selected($value, $key, false),
			esc_html($provider['label'])
		);
	}
	echo '</select>';
	echo '<p class="description">Select your AI provider for chat completions</p>';
}

/**
 * Generic renderer for provider-specific settings fields.
 * Reads field type and configuration from the provider registry.
 */
function a8csp_cws_render_provider_field($args) {
	$options = get_option('a8csp_chat_with_site_options');
	$field_id = $args['field_id'];
	$config = $args['config'];
	$value = isset($options[$field_id]) ? $options[$field_id] : ($config['default'] ?? '');

	switch ($config['type']) {
		case 'password':
			printf(
				'<input type="password" id="%s" name="a8csp_chat_with_site_options[%s]" value="%s" size="%d" autocomplete="off" autocapitalize="none" spellcheck="false" />',
				esc_attr($field_id),
				esc_attr($field_id),
				esc_attr($value),
				intval($config['size'] ?? 70)
			);
			break;
		case 'text':
			printf(
				'<input type="text" id="%s" name="a8csp_chat_with_site_options[%s]" value="%s" size="%d" />',
				esc_attr($field_id),
				esc_attr($field_id),
				esc_attr($value),
				intval($config['size'] ?? 50)
			);
			break;
		case 'select':
			printf(
				'<select id="%s" name="a8csp_chat_with_site_options[%s]">',
				esc_attr($field_id),
				esc_attr($field_id)
			);
			foreach (($config['options'] ?? array()) as $opt_key => $opt_label) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr($opt_key),
					selected($value, $opt_key, false),
					esc_html($opt_label)
				);
			}
			echo '</select>';
			break;
	}

	if (!empty($config['description'])) {
		echo '<p class="description">' . wp_kses_post($config['description']) . '</p>';
	}
}
