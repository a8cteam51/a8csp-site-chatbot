<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

function chat_with_site_vectorize_content($content) {
	$url = 'https://api.openai.com/v1/embeddings';
	
	$data = array(
		'model' => OPENAI_EMBEDDING_MODEL,
		'input' => $content,
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
		error_log('OpenAI API Error: ' . $response->get_error_message());
		return false;
	}
	
	$response_code = wp_remote_retrieve_response_code($response);
	if ($response_code !== 200) {
		error_log('OpenAI API HTTP Error: ' . $response_code);
		return false;
	}
	
	$body = json_decode(wp_remote_retrieve_body($response), true);
	
	if (isset($body['data'][0]['embedding'])) {
		$embedding = $body['data'][0]['embedding'];
		
		// Check if we need to truncate the embedding to match Pinecone index dimensions
		$target_dimensions = 1536; // Updated to match your Pinecone index dimensions
		
		if (count($embedding) > $target_dimensions) {
			error_log('Truncating embedding from ' . count($embedding) . ' to ' . $target_dimensions . ' dimensions');
			$embedding = array_slice($embedding, 0, $target_dimensions);
		} elseif (count($embedding) < $target_dimensions) {
			error_log('Warning: Embedding has ' . count($embedding) . ' dimensions, expected ' . $target_dimensions);
		}
		
		return $embedding;
	}
	
	error_log('OpenAI API Response Error: ' . print_r($body, true));
	return false;
}

function chat_with_site_upsert_to_pinecone($post_id, $embedding, $metadata) {
	$url = PINECONE_SERVER_URL . '/vectors/upsert';
	
	$data = array(
		'vectors' => array(
			array(
				'id' => (string)$post_id,
				'values' => $embedding,
				'metadata' => $metadata,
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
		error_log('Pinecone API Error: ' . $response->get_error_message());
		return new WP_Error('pinecone_error', 'Pinecone API connection failed', array('status' => 500));
	}
	
	
	$response_code = wp_remote_retrieve_response_code($response);

	if ($response_code !== 200) {
		$error_body = wp_remote_retrieve_body($response);
		error_log('Pinecone API HTTP Error: ' . $response_code . ' - ' . $error_body);
		return new WP_Error('pinecone_http_error', 'Pinecone API returned error: ' . $response_code, array('status' => 500));
	}
	
	$body = json_decode(wp_remote_retrieve_body($response), true);
	
	if (isset($body['upsertedCount']) && $body['upsertedCount'] > 0) {
		return true;
	}
	
	error_log('Pinecone Upsert Failed: ' . print_r($body, true));
	return new WP_Error('pinecone_upsert_failed', 'Failed to upsert to Pinecone', array('status' => 500));
}

function get_openai_embedding($text) {
	$url = 'https://api.openai.com/v1/embeddings';
	$data = [
		'model' => OPENAI_EMBEDDING_MODEL,
		'input' => $text,
	];
	$headers = [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . OPENAI_API_KEY,
	];
	if (OPENAI_ORG_ID) {
		$headers['OpenAI-Organization'] = OPENAI_ORG_ID;
	}
	$response = wp_remote_post($url, [
		'headers' => $headers,
		'body' => json_encode($data),
		'timeout' => 30,
	]);
	if (is_wp_error($response)) {
		return [];
	}
	$body = json_decode(wp_remote_retrieve_body($response), true);
	if (isset($body['data'][0]['embedding'])) {
		return $body['data'][0]['embedding'];
	}
	return [];
}

function query_pinecone($vector) {
	$url = PINECONE_SERVER_URL . '/query';
	$data = [
		'vector' => $vector,
		'top_k' => 5,
		'include_metadata' => true,
	];
	if (!empty(PINECONE_NAMESPACE)) {
		$data['namespace'] = PINECONE_NAMESPACE;
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
		return $response; // TODO: remove.
		return [];
	}
	$body = json_decode(wp_remote_retrieve_body($response), true);

	if (isset($body['matches'])) {
		return $body['matches'];
	}
	return [];
}

function get_openai_completion($messages) {
	$url = 'https://api.openai.com/v1/chat/completions';
	$data = [
		'model' => OPENAI_MODEL,
		'messages' => $messages,
		'temperature' => 0.5,
		'max_tokens' => 500,
	];
	$headers = [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . OPENAI_API_KEY,
	];
	if (OPENAI_ORG_ID) {
		$headers['OpenAI-Organization'] = OPENAI_ORG_ID;
	}
	$response = wp_remote_post($url, [
		'headers' => $headers,
		'body' => json_encode($data),
		'timeout' => 30,
	]);
	if (is_wp_error($response)) {
		return '';
	}
	$body = json_decode(wp_remote_retrieve_body($response), true);
	if (isset($body['choices'][0]['message']['content'])) {
		return trim($body['choices'][0]['message']['content']);
	}
	return '';
}
