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
	$max_length = 8000; // OpenAI's token limit is ~8192, leave buffer
	if ( strlen( $content ) > $max_length ) {
		$content = substr( $content, 0, $max_length );
		error_log( 'A8CSP: Content truncated for vectorization due to length limit' );
	}

	// Security: Validate API configuration
	if ( empty( OPENAI_API_KEY ) || empty( OPENAI_EMBEDDING_MODEL ) ) {
		error_log( 'A8CSP: Missing OpenAI API configuration' );
		return false;
	}

	$url = 'https://api.openai.com/v1/embeddings';
	
	$data = array(
		'model' => OPENAI_EMBEDDING_MODEL,
		'input' => sanitize_text_field( $content ),
	);
	
	$headers = array(
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . OPENAI_API_KEY,
	);
	
	if (defined('OPENAI_ORG_ID') && OPENAI_ORG_ID) {
		$headers['OpenAI-Organization'] = OPENAI_ORG_ID;
	}
	
	$response = wp_remote_post($url, array(
		'headers' => $headers,
		'body' => json_encode($data),
		'timeout' => 60,
	));
	
	if (is_wp_error($response)) {
		// Security: Don't log detailed WP_Error messages that might contain sensitive data
		error_log('A8CSP: OpenAI API request failed');
		return false;
	}
	
	$response_code = wp_remote_retrieve_response_code($response);
	if ($response_code !== 200) {
		// Security: Log only status code, not response body
		error_log('A8CSP: OpenAI API returned HTTP ' . intval($response_code));
		return false;
	}
	
	$body = json_decode(wp_remote_retrieve_body($response), true);
	
	// Security: Validate response structure
	if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
		error_log('A8CSP: Invalid OpenAI API response structure');
		return false;
	}

	if (isset($body['data'][0]['embedding']) && is_array($body['data'][0]['embedding'])) {
		$embedding = $body['data'][0]['embedding'];
		
		// Security: Validate embedding is numeric array
		if ( ! is_array( $embedding ) || empty( $embedding ) ) {
			error_log('A8CSP: Invalid embedding data received');
			return false;
		}

		// Check if we need to truncate the embedding to match Pinecone index dimensions
		$target_dimensions = 1536; // Updated to match your Pinecone index dimensions
		
		if (count($embedding) > $target_dimensions) {
			error_log('A8CSP: Truncating embedding from ' . count($embedding) . ' to ' . $target_dimensions . ' dimensions');
			$embedding = array_slice($embedding, 0, $target_dimensions);
		} elseif (count($embedding) < $target_dimensions) {
			error_log('A8CSP: Warning - Embedding has ' . count($embedding) . ' dimensions, expected ' . $target_dimensions);
		}
		
		// Security: Validate all embedding values are numeric
		foreach ( $embedding as $value ) {
			if ( ! is_numeric( $value ) ) {
				error_log('A8CSP: Invalid embedding value detected');
				return false;
			}
		}
		
		return $embedding;
	}
	
	error_log('A8CSP: OpenAI API response missing embedding data');
	return false;
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
				'values' => array_values( $embedding ), // Ensure indexed array
				'metadata' => $sanitized_metadata,
			)
		),
	);
	
	// Add namespace if defined
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
		// Security: Don't log detailed error messages
		error_log('A8CSP: Pinecone API connection failed');
		return new WP_Error('pinecone_error', 'Pinecone API connection failed', array('status' => 500));
	}
	
	$response_code = wp_remote_retrieve_response_code($response);

	if ($response_code !== 200) {
		// Security: Log only status code, not response body which might contain sensitive data
		error_log('A8CSP: Pinecone API returned HTTP ' . intval($response_code));
		return new WP_Error('pinecone_http_error', 'Pinecone API returned error: ' . intval($response_code), array('status' => 500));
	}
	
	$body = json_decode(wp_remote_retrieve_body($response), true);
	
	// Security: Validate response structure
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

function a8csp_cws_get_openai_embedding($text) {
	// Security: Validate input
	if ( empty( $text ) || ! is_string( $text ) ) {
		error_log('A8CSP: Invalid text provided for embedding');
		return [];
	}

	// Security: Limit text length
	$max_length = 8000;
	if ( strlen( $text ) > $max_length ) {
		$text = substr( $text, 0, $max_length );
	}

	// Security: Validate API configuration
	if ( empty( OPENAI_API_KEY ) || empty( OPENAI_EMBEDDING_MODEL ) ) {
		error_log('A8CSP: Missing OpenAI configuration for embedding');
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
	if ( ! empty( OPENAI_ORG_ID ) ) {
		$headers['OpenAI-Organization'] = OPENAI_ORG_ID;
	}
	$response = wp_remote_post($url, [
		'headers' => $headers,
		'body' => json_encode($data),
		'timeout' => 30,
	]);
	if (is_wp_error($response)) {
		error_log('A8CSP: OpenAI embedding request failed');
		return [];
	}
	$body = json_decode(wp_remote_retrieve_body($response), true);
	
	// Security: Validate response structure
	if ( is_array( $body ) && isset( $body['data'][0]['embedding'] ) && is_array( $body['data'][0]['embedding'] ) ) {
		return $body['data'][0]['embedding'];
	}
	
	error_log('A8CSP: Invalid OpenAI embedding response');
	return [];
}

function a8csp_cws_query_pinecone($vector) {
	// Security: Validate input vector
	if ( ! is_array( $vector ) || empty( $vector ) ) {
		error_log('A8CSP: Invalid vector provided for Pinecone query');
		return [];
	}

	// Security: Validate vector values are numeric
	foreach ( $vector as $value ) {
		if ( ! is_numeric( $value ) ) {
			error_log('A8CSP: Invalid vector value in Pinecone query');
			return [];
		}
	}

	// Security: Validate API configuration
	if ( empty( PINECONE_SERVER_URL ) || empty( PINECONE_API_KEY ) ) {
		error_log('A8CSP: Missing Pinecone configuration for query');
		return [];
	}

	// Security: Validate URL format
	if ( ! filter_var( PINECONE_SERVER_URL, FILTER_VALIDATE_URL ) ) {
		error_log('A8CSP: Invalid Pinecone server URL');
		return [];
	}

	$url = PINECONE_SERVER_URL . '/query';
	$data = [
		'vector' => array_values( $vector ), // Ensure indexed array
		'top_k' => 5, // Limit results to prevent resource exhaustion
		'include_metadata' => true,
	];
	
	// Security: Validate namespace if provided
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

	// Security: Validate response structure
	if ( is_array( $body ) && isset( $body['matches'] ) && is_array( $body['matches'] ) ) {
		// Security: Validate each match structure
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

function a8csp_cws_get_openai_completion($messages) {
	// Security: Validate input messages
	if ( ! is_array( $messages ) || empty( $messages ) ) {
		error_log('A8CSP: Invalid messages provided for OpenAI completion');
		return '';
	}

	// Security: Validate message structure and sanitize content
	$validated_messages = [];
	foreach ( $messages as $message ) {
		if ( ! is_array( $message ) || ! isset( $message['role'] ) || ! isset( $message['content'] ) ) {
			error_log('A8CSP: Invalid message structure in OpenAI completion');
			continue;
		}

		$role = sanitize_text_field( $message['role'] );
		$content = sanitize_textarea_field( $message['content'] );

		// Security: Validate role values
		if ( ! in_array( $role, [ 'system', 'user', 'assistant' ], true ) ) {
			error_log('A8CSP: Invalid message role in OpenAI completion');
			continue;
		}

		// Security: Limit content length
		if ( strlen( $content ) > 4000 ) {
			$content = substr( $content, 0, 4000 );
		}

		$validated_messages[] = [
			'role' => $role,
			'content' => $content,
		];
	}

	if ( empty( $validated_messages ) ) {
		error_log('A8CSP: No valid messages for OpenAI completion');
		return '';
	}

	// Security: Validate API configuration
	if ( empty( OPENAI_API_KEY ) || empty( OPENAI_MODEL ) ) {
		error_log('A8CSP: Missing OpenAI configuration for completion');
		return '';
	}

	$url = 'https://api.openai.com/v1/chat/completions';
	
	// Determine model generation for API parameter compatibility
	$model = OPENAI_MODEL;
	$is_gpt4_or_below = (strpos($model, 'gpt-3') === 0 || strpos($model, 'gpt-4') === 0);
	
	$data = [
		'model' => $model,
		'messages' => $validated_messages,
	];
	
	// GPT-4 and below support temperature and max_tokens
	if ($is_gpt4_or_below) {
		$data['temperature'] = 0.5;
		$data['max_tokens'] = 500; // Limit to prevent resource exhaustion
	} else {
		// GPT-5+ models use max_completion_tokens and don't support temperature
		$data['max_completion_tokens'] = 500; // Limit to prevent resource exhaustion
	}
	
	$headers = [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . OPENAI_API_KEY,
	];
	if ( ! empty( OPENAI_ORG_ID ) ) {
		$headers['OpenAI-Organization'] = OPENAI_ORG_ID;
	}
	$response = wp_remote_post($url, [
		'headers' => $headers,
		'body' => json_encode($data),
		'timeout' => 30,
	]);
	if (is_wp_error($response)) {
		error_log('A8CSP: OpenAI completion request failed');
		return '';
	}
	$body = json_decode(wp_remote_retrieve_body($response), true);
	
	// Security: Validate response structure
	if ( is_array( $body ) && isset( $body['choices'][0]['message']['content'] ) ) {
		$content = $body['choices'][0]['message']['content'];
		if ( is_string( $content ) ) {
			return trim( $content );
		}
	}
	
	error_log('A8CSP: Invalid OpenAI completion response. ' . A8CSP_CWS_Utils::sanitize_for_log($body));
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

	// Initialize Parsedown with security settings
	$parsedown = new Parsedown();
	
	// Enable safe mode to prevent XSS attacks
	$parsedown->setSafeMode( true );
	
	// Convert Markdown to HTML
	$html = $parsedown->text( $markdown );
	
	// Additional WordPress-specific sanitization
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
	
	// Sanitize the HTML output
	$html = wp_kses( $html, $allowed_html );
	
	return $html;
}
