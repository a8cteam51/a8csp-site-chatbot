<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

function a8csp_cws_vectorize_content($content) {
	// Security: Validate and sanitize input
	if ( empty( $content ) || ! is_string( $content ) ) {
		error_log( 'A8CSP: Invalid content provided for vectorization' );
		return false;
	}

	// Security: Limit content length to prevent resource exhaustion
	// TODO: Vectorize a summary of the content instead of the full content.
	$max_length = 8000;
	if ( strlen( $content ) > $max_length ) {
		$content = substr( $content, 0, $max_length );
		error_log( 'A8CSP: Content truncated for vectorization due to length limit' );
	}

	$embedding = a8csp_cws_get_embedding( $content );

	if ( empty( $embedding ) || ! is_array( $embedding ) ) {
		return false;
	}

	foreach ( $embedding as $value ) {
		if ( ! is_numeric( $value ) ) {
			error_log( 'A8CSP: Invalid embedding value detected' );
			return false;
		}
	}

	return $embedding;
}

function a8csp_cws_upsert_to_pinecone($post_id, $embedding, $metadata) {
	// Security: Validate inputs
	$post_id = intval( $post_id );
	if ( $post_id <= 0 ) {
		return new WP_Error('invalid_post_id', 'Invalid post ID provided', array('status' => 400));
	}

	if ( ! is_array( $embedding ) || empty( $embedding ) ) {
		return new WP_Error('invalid_embedding', 'Invalid embedding data', array('status' => 400));
	}

	if ( ! is_array( $metadata ) ) {
		return new WP_Error('invalid_metadata', 'Invalid metadata provided', array('status' => 400));
	}

	// Security: Validate API configuration
	if ( empty( PINECONE_SERVER_URL ) || empty( PINECONE_API_KEY ) ) {
		return new WP_Error('missing_config', 'Pinecone API configuration missing', array('status' => 500));
	}

	// Security: Validate Pinecone URL format
	if ( ! filter_var( PINECONE_SERVER_URL, FILTER_VALIDATE_URL ) ) {
		return new WP_Error('invalid_url', 'Invalid Pinecone server URL', array('status' => 500));
	}

	$url = PINECONE_SERVER_URL . '/vectors/upsert';

	// Security: Sanitize metadata values
	$sanitized_metadata = array();
	foreach ( $metadata as $key => $value ) {
		$key = sanitize_key( $key );
		if ( is_string( $value ) ) {
			$sanitized_metadata[ $key ] = sanitize_text_field( $value );
		} elseif ( is_numeric( $value ) ) {
			$sanitized_metadata[ $key ] = $value;
		}
	}

	$data = array(
		'vectors' => array(
			array(
				'id' => (string) $post_id,
				'values' => array_values( $embedding ),
				'metadata' => $sanitized_metadata,
			)
		),
	);

	if (defined('PINECONE_NAMESPACE') && !empty(PINECONE_NAMESPACE)) {
		$data['namespace'] = PINECONE_NAMESPACE;
	}

	$headers = array(
		'Content-Type' => 'application/json',
		'Api-Key' => PINECONE_API_KEY,
	);

	$response = wp_remote_post($url, array(
		'headers' => $headers,
		'body' => json_encode($data),
		'timeout' => 60,
	));

	if (is_wp_error($response)) {
		error_log('A8CSP: Pinecone API connection failed');
		return new WP_Error('pinecone_error', 'Pinecone API connection failed', array('status' => 500));
	}

	$response_code = wp_remote_retrieve_response_code($response);

	if ($response_code !== 200) {
		error_log('A8CSP: Pinecone API returned HTTP ' . intval($response_code));
		return new WP_Error('pinecone_http_error', 'Pinecone API returned error: ' . intval($response_code), array('status' => 500));
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);

	if ( ! is_array( $body ) ) {
		error_log('A8CSP: Invalid Pinecone API response format');
		return new WP_Error('pinecone_invalid_response', 'Invalid Pinecone response format', array('status' => 500));
	}

	if (isset($body['upsertedCount']) && is_numeric($body['upsertedCount']) && $body['upsertedCount'] > 0) {
		return true;
	}

	error_log('A8CSP: Pinecone upsert operation failed');
	return new WP_Error('pinecone_upsert_failed', 'Failed to upsert to Pinecone', array('status' => 500));
}

/**
 * Embedding dispatcher — routes to the configured provider.
 * Returns the raw embedding array, or [] on failure.
 */
function a8csp_cws_get_embedding($text) {
	if ( empty( $text ) || ! is_string( $text ) ) {
		error_log('A8CSP: Invalid text provided for embedding');
		return [];
	}

	$max_length = 8000;
	if ( strlen( $text ) > $max_length ) {
		$text = substr( $text, 0, $max_length );
	}

	$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'openai';

	switch ( $provider ) {
		case 'google':
			return a8csp_cws_call_gemini_embeddings( $text );
		case 'anthropic':
			return a8csp_cws_call_voyage_embeddings( $text );
		case 'openai':
		default:
			return a8csp_cws_call_openai_embeddings( $text );
	}
}

/**
 * Backwards-compatible wrapper. Existing callers expect this name.
 */
function a8csp_cws_get_openai_embedding($text) {
	return a8csp_cws_get_embedding( $text );
}

function a8csp_cws_call_openai_embeddings($text) {
	if ( empty( OPENAI_API_KEY ) || empty( OPENAI_EMBEDDING_MODEL ) ) {
		error_log('A8CSP: Missing OpenAI API configuration');
		return [];
	}

	$url = 'https://api.openai.com/v1/embeddings';
	$data = [
		'model' => OPENAI_EMBEDDING_MODEL,
		'input' => sanitize_text_field( $text ),
	];
	$headers = [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . OPENAI_API_KEY,
	];
	if ( defined('OPENAI_ORG_ID') && OPENAI_ORG_ID ) {
		$headers['OpenAI-Organization'] = OPENAI_ORG_ID;
	}

	$response = a8csp_cws_post_with_retry( $url, [
		'headers' => $headers,
		'body' => json_encode( $data ),
		'timeout' => 60,
	] );

	if ( is_wp_error( $response ) ) {
		error_log('A8CSP: OpenAI embedding request failed');
		return [];
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code !== 200 ) {
		error_log('A8CSP: OpenAI API returned HTTP ' . intval( $response_code ));
		return [];
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( is_array( $body ) && isset( $body['error'] ) ) {
		$error_message = $body['error']['message'] ?? 'Unknown error';
		$error_type = $body['error']['type'] ?? 'unknown';
		error_log( sprintf( 'A8CSP: OpenAI API error - Type: %s, Message: %s', $error_type, $error_message ) );
		return [];
	}

	if ( is_array( $body ) && isset( $body['data'][0]['embedding'] ) && is_array( $body['data'][0]['embedding'] ) ) {
		return $body['data'][0]['embedding'];
	}

	error_log('A8CSP: Invalid OpenAI embedding response');
	return [];
}

function a8csp_cws_call_voyage_embeddings($text) {
	if ( empty( VOYAGE_API_KEY ) || empty( VOYAGE_EMBEDDING_MODEL ) ) {
		error_log('A8CSP: Missing Voyage AI API configuration');
		return [];
	}

	$url = 'https://api.voyageai.com/v1/embeddings';
	$data = [
		'model' => VOYAGE_EMBEDDING_MODEL,
		'input' => sanitize_text_field( $text ),
	];
	$headers = [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . VOYAGE_API_KEY,
	];

	$response = a8csp_cws_post_with_retry( $url, [
		'headers' => $headers,
		'body' => json_encode( $data ),
		'timeout' => 60,
	] );

	if ( is_wp_error( $response ) ) {
		error_log('A8CSP: Voyage AI embedding request failed');
		return [];
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code !== 200 ) {
		error_log('A8CSP: Voyage AI returned HTTP ' . intval( $response_code ));
		return [];
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( is_array( $body ) && isset( $body['data'][0]['embedding'] ) && is_array( $body['data'][0]['embedding'] ) ) {
		return $body['data'][0]['embedding'];
	}

	error_log('A8CSP: Invalid Voyage AI embedding response');
	return [];
}

function a8csp_cws_call_gemini_embeddings($text) {
	if ( empty( GOOGLE_API_KEY ) || empty( GOOGLE_EMBEDDING_MODEL ) ) {
		error_log('A8CSP: Missing Google API configuration');
		return [];
	}

	$url = sprintf(
		'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent?key=%s',
		rawurlencode( GOOGLE_EMBEDDING_MODEL ),
		rawurlencode( GOOGLE_API_KEY )
	);

	$data = [
		'model' => 'models/' . GOOGLE_EMBEDDING_MODEL,
		'content' => [
			'parts' => [
				[ 'text' => sanitize_text_field( $text ) ],
			],
		],
	];

	$response = a8csp_cws_post_with_retry( $url, [
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body' => json_encode( $data ),
		'timeout' => 60,
	] );

	if ( is_wp_error( $response ) ) {
		error_log('A8CSP: Gemini embedding request failed');
		return [];
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code !== 200 ) {
		error_log('A8CSP: Gemini API returned HTTP ' . intval( $response_code ));
		return [];
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( is_array( $body ) && isset( $body['embedding']['values'] ) && is_array( $body['embedding']['values'] ) ) {
		return $body['embedding']['values'];
	}

	error_log('A8CSP: Invalid Gemini embedding response');
	return [];
}

function a8csp_cws_query_pinecone($vector) {
	// Security: Validate input vector
	if ( ! is_array( $vector ) || empty( $vector ) ) {
		error_log('A8CSP: Invalid vector provided for Pinecone query');
		return [];
	}

	foreach ( $vector as $value ) {
		if ( ! is_numeric( $value ) ) {
			error_log('A8CSP: Invalid vector value in Pinecone query');
			return [];
		}
	}

	if ( empty( PINECONE_SERVER_URL ) || empty( PINECONE_API_KEY ) ) {
		error_log('A8CSP: Missing Pinecone configuration for query');
		return [];
	}

	if ( ! filter_var( PINECONE_SERVER_URL, FILTER_VALIDATE_URL ) ) {
		error_log('A8CSP: Invalid Pinecone server URL');
		return [];
	}

	$url = PINECONE_SERVER_URL . '/query';
	$data = [
		'vector' => array_values( $vector ),
		'top_k' => 5,
		'include_metadata' => true,
	];

	if ( ! empty( PINECONE_NAMESPACE ) ) {
		$data['namespace'] = sanitize_text_field( PINECONE_NAMESPACE );
	}

	$headers = [
		'Content-Type' => 'application/json',
		'Api-Key' => PINECONE_API_KEY,
	];
	$response = wp_remote_post($url, [
		'headers' => $headers,
		'body' => json_encode($data),
		'timeout' => 30,
	]);
	if (is_wp_error($response)) {
		error_log('A8CSP: Pinecone query request failed');
		return [];
	}
	$body = json_decode(wp_remote_retrieve_body($response), true);

	if ( is_array( $body ) && isset( $body['matches'] ) && is_array( $body['matches'] ) ) {
		$validated_matches = [];
		foreach ( $body['matches'] as $match ) {
			if ( is_array( $match ) && isset( $match['metadata'] ) ) {
				$validated_matches[] = $match;
			}
		}
		return $validated_matches;
	}

	error_log('A8CSP: Invalid Pinecone query response');
	return [];
}

/**
 * POST helper that retries on HTTP 503 and 429.
 *
 * Backoff schedule depends on the status code:
 *   503 (overload)      → 1s, 3s   — usually transient, clears quickly
 *   429 (rate-limited)  → 10s, 30s — limit windows are typically per-minute,
 *                                    short retries are wasted attempts
 *
 * If the response includes a numeric Retry-After header that is preferred
 * over the schedule (capped at 60s to bound worst-case runtime).
 */
function a8csp_cws_post_with_retry($url, $args, $max_retries = 2) {
	$backoffs = array(
		429 => array( 10, 30 ),
		503 => array( 1, 3 ),
	);
	$max_retry_after = 60;
	$response = null;

	for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( ! isset( $backoffs[ $code ] ) ) {
			return $response;
		}

		if ( $attempt >= $max_retries ) {
			break;
		}

		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( $retry_after !== '' && is_numeric( $retry_after ) ) {
			$delay = min( max( 1, intval( $retry_after ) ), $max_retry_after );
		} else {
			$schedule = $backoffs[ $code ];
			$delay = $schedule[ $attempt ] ?? end( $schedule );
		}

		error_log( sprintf( 'A8CSP: Provider returned HTTP %d, retrying in %ds (attempt %d/%d)', intval( $code ), $delay, $attempt + 1, $max_retries ) );
		sleep( $delay );
	}

	return $response;
}

/**
 * Completion dispatcher — routes to the configured provider.
 * Accepts the same OpenAI-style messages array used elsewhere
 * (one optional 'system' message at the head, then user/assistant turns).
 */
function a8csp_cws_get_completion($messages) {
	if ( ! is_array( $messages ) || empty( $messages ) ) {
		error_log('A8CSP: Invalid messages provided for completion');
		return '';
	}

	$validated_messages = [];
	foreach ( $messages as $message ) {
		if ( ! is_array( $message ) || ! isset( $message['role'] ) || ! isset( $message['content'] ) ) {
			error_log('A8CSP: Invalid message structure in completion');
			continue;
		}

		$role = sanitize_text_field( $message['role'] );
		$content = sanitize_textarea_field( $message['content'] );

		if ( ! in_array( $role, [ 'system', 'user', 'assistant' ], true ) ) {
			error_log('A8CSP: Invalid message role in completion');
			continue;
		}

		if ( strlen( $content ) > 4000 ) {
			$content = substr( $content, 0, 4000 );
		}

		$validated_messages[] = [
			'role' => $role,
			'content' => $content,
		];
	}

	if ( empty( $validated_messages ) ) {
		error_log('A8CSP: No valid messages for completion');
		return '';
	}

	$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'openai';

	switch ( $provider ) {
		case 'google':
			return a8csp_cws_call_gemini_completion( $validated_messages );
		case 'anthropic':
			return a8csp_cws_call_anthropic_completion( $validated_messages );
		case 'openai':
		default:
			return a8csp_cws_call_openai_completion( $validated_messages );
	}
}

/**
 * Backwards-compatible wrapper. Existing callers expect this name.
 */
function a8csp_cws_get_openai_completion($messages) {
	return a8csp_cws_get_completion( $messages );
}

function a8csp_cws_call_openai_completion($messages) {
	if ( empty( OPENAI_API_KEY ) || empty( OPENAI_MODEL ) ) {
		error_log('A8CSP: Missing OpenAI configuration for completion');
		return '';
	}

	$url = 'https://api.openai.com/v1/chat/completions';

	$model = OPENAI_MODEL;
	$is_gpt4_or_below = ( strpos( $model, 'gpt-3' ) === 0 || strpos( $model, 'gpt-4' ) === 0 );

	$data = [
		'model' => $model,
		'messages' => $messages,
	];

	if ( $is_gpt4_or_below ) {
		$data['temperature'] = 0.3;
		$data['max_tokens'] = 500;
	} else {
		$data['max_completion_tokens'] = 500;
	}

	$headers = [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . OPENAI_API_KEY,
	];
	if ( defined('OPENAI_ORG_ID') && OPENAI_ORG_ID ) {
		$headers['OpenAI-Organization'] = OPENAI_ORG_ID;
	}

	$response = a8csp_cws_post_with_retry( $url, [
		'headers' => $headers,
		'body' => json_encode( $data ),
		'timeout' => 30,
	] );

	if ( is_wp_error( $response ) ) {
		error_log('A8CSP: OpenAI completion request failed');
		return '';
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code !== 200 ) {
		error_log('A8CSP: OpenAI API returned HTTP ' . intval( $response_code ));
		return '';
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( is_array( $body ) && isset( $body['choices'][0]['message']['content'] ) ) {
		$content = $body['choices'][0]['message']['content'];
		if ( is_string( $content ) ) {
			return trim( $content );
		}
	}

	error_log('A8CSP: Invalid OpenAI completion response. ' . A8CSP_CWS_Utils::sanitize_for_log( $body ));
	return '';
}

function a8csp_cws_call_anthropic_completion($messages) {
	if ( empty( ANTHROPIC_API_KEY ) || empty( ANTHROPIC_MODEL ) ) {
		error_log('A8CSP: Missing Anthropic configuration for completion');
		return '';
	}

	// Anthropic requires system content as a top-level field, not in messages.
	$system_parts = [];
	$turns = [];
	foreach ( $messages as $message ) {
		if ( $message['role'] === 'system' ) {
			$system_parts[] = $message['content'];
		} else {
			$turns[] = [
				'role' => $message['role'],
				'content' => $message['content'],
			];
		}
	}

	if ( empty( $turns ) ) {
		error_log('A8CSP: Anthropic requires at least one user/assistant message');
		return '';
	}

	$data = [
		'model' => ANTHROPIC_MODEL,
		'max_tokens' => 500,
		'temperature' => 0.3,
		'messages' => $turns,
	];
	if ( ! empty( $system_parts ) ) {
		$data['system'] = implode( "\n\n", $system_parts );
	}

	$headers = [
		'Content-Type' => 'application/json',
		'x-api-key' => ANTHROPIC_API_KEY,
		'anthropic-version' => '2023-06-01',
	];

	$response = a8csp_cws_post_with_retry( 'https://api.anthropic.com/v1/messages', [
		'headers' => $headers,
		'body' => json_encode( $data ),
		'timeout' => 30,
	] );

	if ( is_wp_error( $response ) ) {
		error_log('A8CSP: Anthropic completion request failed');
		return '';
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code !== 200 ) {
		error_log('A8CSP: Anthropic API returned HTTP ' . intval( $response_code ));
		return '';
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( is_array( $body ) && isset( $body['content'] ) && is_array( $body['content'] ) ) {
		$text_parts = [];
		foreach ( $body['content'] as $block ) {
			if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'text' && isset( $block['text'] ) ) {
				$text_parts[] = $block['text'];
			}
		}
		if ( ! empty( $text_parts ) ) {
			return trim( implode( '', $text_parts ) );
		}
	}

	error_log('A8CSP: Invalid Anthropic completion response');
	return '';
}

function a8csp_cws_call_gemini_completion($messages) {
	if ( empty( GOOGLE_API_KEY ) || empty( GOOGLE_MODEL ) ) {
		error_log('A8CSP: Missing Google configuration for completion');
		return '';
	}

	// Gemini takes system content as systemInstruction and uses role "model" for assistant.
	$system_parts = [];
	$contents = [];
	foreach ( $messages as $message ) {
		if ( $message['role'] === 'system' ) {
			$system_parts[] = $message['content'];
			continue;
		}
		$role = ( $message['role'] === 'assistant' ) ? 'model' : 'user';
		$contents[] = [
			'role' => $role,
			'parts' => [
				[ 'text' => $message['content'] ],
			],
		];
	}

	if ( empty( $contents ) ) {
		error_log('A8CSP: Gemini requires at least one user/assistant message');
		return '';
	}

	$data = [
		'contents' => $contents,
		'generationConfig' => [
			'maxOutputTokens' => 500,
			'temperature' => 0.3,
		],
	];
	if ( ! empty( $system_parts ) ) {
		$data['systemInstruction'] = [
			'parts' => [
				[ 'text' => implode( "\n\n", $system_parts ) ],
			],
		];
	}

	$url = sprintf(
		'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
		rawurlencode( GOOGLE_MODEL ),
		rawurlencode( GOOGLE_API_KEY )
	);

	$response = a8csp_cws_post_with_retry( $url, [
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body' => json_encode( $data ),
		'timeout' => 30,
	] );

	if ( is_wp_error( $response ) ) {
		error_log('A8CSP: Gemini completion request failed');
		return '';
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code !== 200 ) {
		error_log('A8CSP: Gemini API returned HTTP ' . intval( $response_code ));
		return '';
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( is_array( $body ) && isset( $body['candidates'][0]['content']['parts'] ) && is_array( $body['candidates'][0]['content']['parts'] ) ) {
		$text_parts = [];
		foreach ( $body['candidates'][0]['content']['parts'] as $part ) {
			if ( is_array( $part ) && isset( $part['text'] ) ) {
				$text_parts[] = $part['text'];
			}
		}
		if ( ! empty( $text_parts ) ) {
			return trim( implode( '', $text_parts ) );
		}
	}

	error_log('A8CSP: Invalid Gemini completion response');
	return '';
}

/**
 * Convert Markdown to HTML using Parsedown library
 * Handles all standard Markdown syntax securely
 */
function a8csp_cws_markdown_to_html( $markdown ) {
	if ( empty( $markdown ) ) {
		return '';
	}

	$parsedown = new Parsedown();
	$parsedown->setSafeMode( true );
	$html = $parsedown->text( $markdown );

	$allowed_html = array(
		'p' => array(),
		'br' => array(),
		'strong' => array(),
		'b' => array(),
		'em' => array(),
		'i' => array(),
		'a' => array(
			'href' => array(),
			'title' => array(),
			'target' => array(),
		),
		'ul' => array(),
		'ol' => array(),
		'li' => array(),
		'h1' => array(),
		'h2' => array(),
		'h3' => array(),
		'h4' => array(),
		'h5' => array(),
		'h6' => array(),
		'blockquote' => array(),
		'code' => array(),
		'pre' => array(),
	);

	$html = wp_kses( $html, $allowed_html );
	$html = preg_replace( '/<a([^>]*href[^>]*)>/', '<a$1 target="_blank">', $html );

	return $html;
}

/**
 * Replace link emoji with SVG icon.
 * This is used to format links in the chatbot response.
 */
function a8csp_cws_reformat_links( $text ) {
	if ( empty( $text ) ) {
		return $text;
	}

	$svg_icon = '<svg fill="" width="14px" height="14px" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"></path></svg>';

	return str_replace( '🔗', $svg_icon, $text );
}
