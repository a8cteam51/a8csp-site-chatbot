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

	$result = a8csp_cws_upsert_vectors_to_pinecone( array(
		array(
			'id' => (string) $post_id,
			'values' => $embedding,
			'metadata' => $metadata,
		),
	) );

	if ( in_array( (string) $post_id, $result['upserted'], true ) ) {
		return true;
	}

	$error = isset( $result['errors'][ $post_id ] ) ? $result['errors'][ $post_id ] : $result['fatal'];
	if ( ! is_wp_error( $error ) ) {
		$error = new WP_Error('pinecone_upsert_failed', 'Failed to upsert to Pinecone', array('status' => 500));
	}

	error_log( 'A8CSP: Pinecone upsert failed: ' . $error->get_error_message() );
	return $error;
}

/**
 * Upsert vectors in chunks capped by count and request size; each input ID lands in exactly one result bucket.
 */
function a8csp_cws_upsert_vectors_to_pinecone( array $vectors, array $args = array() ) {
	$max_retries = isset( $args['max_retries'] ) ? max( 0, (int) $args['max_retries'] ) : 2;

	$order = array();
	$valid = array();
	$sizes = array();
	$errors = array();
	$position = 0;

	foreach ( $vectors as $vector ) {
		$id = a8csp_cws_pinecone_vector_key( $vector, $position++ );
		$order[ $id ] = true;
		unset( $valid[ $id ], $sizes[ $id ], $errors[ $id ] );

		$prepared = a8csp_cws_prepare_pinecone_vector( $vector );
		if ( is_wp_error( $prepared ) ) {
			$errors[ $id ] = $prepared;
			continue;
		}

		$json = wp_json_encode( $prepared );
		if ( false === $json ) {
			$errors[ $id ] = a8csp_cws_api_error( 'a8csp_bad_input', 'The vector could not be encoded as JSON.', 'pinecone', 400, false );
			continue;
		}

		$valid[ $id ] = $prepared;
		$sizes[ $id ] = strlen( $json );
	}

	$upserted = array();
	$fatal = empty( $valid ) ? null : a8csp_cws_check_pinecone_config();

	if ( ! empty( $valid ) && null === $fatal ) {
		$request = array(
			'url' => PINECONE_SERVER_URL . '/vectors/upsert',
			'headers' => array(
				'Content-Type' => 'application/json',
				'Api-Key' => PINECONE_API_KEY,
			),
			'namespace' => ( defined('PINECONE_NAMESPACE') && ! empty( PINECONE_NAMESPACE ) ) ? PINECONE_NAMESPACE : '',
			'max_retries' => $max_retries,
		);

		$base_body = array( 'vectors' => array() );
		if ( '' !== $request['namespace'] ) {
			$base_body['namespace'] = $request['namespace'];
		}
		$base_bytes = strlen( wp_json_encode( $base_body ) );
		$max_count = min( 1000, max( 1, (int) apply_filters( 'a8csp_cws_pinecone_upsert_batch_size', 100 ) ) );
		$max_bytes = max( 1, (int) apply_filters( 'a8csp_cws_pinecone_upsert_max_bytes', 1500000 ) );

		$chunks = array();
		$current = array();
		$current_bytes = 0;
		foreach ( $valid as $id => $prepared ) {
			// Each vector after the first adds a one-byte comma to the encoded body.
			$next_bytes = $base_bytes + $current_bytes + count( $current ) + $sizes[ $id ];
			if ( ! empty( $current ) && ( count( $current ) >= $max_count || $next_bytes > $max_bytes ) ) {
				$chunks[] = $current;
				$current = array();
				$current_bytes = 0;
			}
			$current[ $id ] = $prepared;
			$current_bytes += $sizes[ $id ];
		}
		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		foreach ( $chunks as $chunk ) {
			$fatal = a8csp_cws_send_pinecone_upsert_chunk( $chunk, $request, $upserted, $errors );
			if ( null !== $fatal ) {
				break;
			}
		}
	}

	if ( null !== $fatal ) {
		error_log( 'A8CSP: Pinecone upsert stopped: ' . $fatal->get_error_message() );
	}

	$result = array(
		'upserted' => array(),
		'errors' => array(),
		'pending' => array(),
		'fatal' => $fatal,
	);
	foreach ( array_keys( $order ) as $id ) {
		if ( isset( $upserted[ $id ] ) ) {
			$result['upserted'][] = (string) $id;
		} elseif ( isset( $errors[ $id ] ) ) {
			$result['errors'][ $id ] = $errors[ $id ];
		} else {
			$result['pending'][] = (string) $id;
		}
	}

	return $result;
}

/**
 * Send one upsert chunk; a splittable 400 is retried in halves to isolate the bad vector. Returns a fatal WP_Error or null.
 */
function a8csp_cws_send_pinecone_upsert_chunk( array $chunk, array $request, array &$upserted, array &$errors, $outcome = null ) {
	if ( null === $outcome ) {
		$outcome = a8csp_cws_post_pinecone_upsert( $chunk, $request );
	}

	if ( true === $outcome ) {
		foreach ( array_keys( $chunk ) as $id ) {
			$upserted[ $id ] = true;
		}
		return null;
	}

	if ( ! a8csp_cws_is_splittable_error( $outcome ) ) {
		return $outcome;
	}

	if ( count( $chunk ) > 1 ) {
		return a8csp_cws_split_failed_chunk(
			$chunk,
			$outcome,
			function ( $part ) use ( $request ) {
				return a8csp_cws_post_pinecone_upsert( $part, $request );
			},
			function ( $part, $part_outcome ) use ( $request, &$upserted, &$errors ) {
				return a8csp_cws_send_pinecone_upsert_chunk( $part, $request, $upserted, $errors, $part_outcome );
			}
		);
	}

	$ids = array_keys( $chunk );
	$id = $ids[0];
	$errors[ $id ] = a8csp_cws_bad_input_from_error( $outcome );
	error_log( 'A8CSP: Pinecone rejected vector ' . sanitize_text_field( (string) $id ) . ': ' . $outcome->get_error_message() );
	return null;
}

/**
 * POST one upsert request: true when Pinecone confirms every vector, otherwise a WP_Error.
 */
function a8csp_cws_post_pinecone_upsert( array $chunk, array $request ) {
	$body = array( 'vectors' => array_values( $chunk ) );
	if ( '' !== $request['namespace'] ) {
		$body['namespace'] = $request['namespace'];
	}

	$response = a8csp_cws_post_with_retry( $request['url'], array(
		'headers' => $request['headers'],
		'body' => wp_json_encode( $body ),
		'timeout' => 60,
	), $request['max_retries'] );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return a8csp_cws_classify_http_error( $response, 'pinecone' );
	}

	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
	$count = ( is_array( $decoded ) && isset( $decoded['upsertedCount'] ) && is_numeric( $decoded['upsertedCount'] ) ) ? (int) $decoded['upsertedCount'] : null;

	if ( count( $chunk ) === $count ) {
		return true;
	}

	return a8csp_cws_invalid_response_error( 'pinecone', sprintf( 'expected %d upserted vectors, got %s', count( $chunk ), null === $count ? 'none' : $count ) );
}

/**
 * Process both halves of a failed chunk; if both fail exactly like the whole chunk, no single item is to blame and the error is fatal.
 */
function a8csp_cws_split_failed_chunk( array $chunk, WP_Error $error, callable $send, callable $process ) {
	$half = (int) ceil( count( $chunk ) / 2 );
	$left = array_slice( $chunk, 0, $half, true );
	$right = array_slice( $chunk, $half, null, true );

	$left_outcome = $send( $left );
	if ( ! a8csp_cws_is_same_split_error( $left_outcome, $error ) ) {
		$fatal = $process( $left, $left_outcome );
		return null !== $fatal ? $fatal : $process( $right, null );
	}

	$right_outcome = $send( $right );
	if ( a8csp_cws_is_same_split_error( $right_outcome, $error ) ) {
		return a8csp_cws_request_level_error( $error );
	}

	// The right half goes first so its successful result is kept if the left half stops on a fatal error.
	$fatal = $process( $right, $right_outcome );
	return null !== $fatal ? $fatal : $process( $left, $left_outcome );
}

/**
 * Whether a half-chunk's outcome is the same splittable error (status and provider message) as its parent's.
 */
function a8csp_cws_is_same_split_error( $outcome, WP_Error $error ) {
	return a8csp_cws_is_splittable_error( $outcome ) && $outcome->get_error_message() === $error->get_error_message();
}

/**
 * Turn a splittable error into a non-retryable fatal one for the whole request.
 */
function a8csp_cws_request_level_error( WP_Error $error ) {
	$data = $error->get_error_data();
	unset( $data['splittable'] );
	$data['retryable'] = false;
	return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
}

/**
 * Result key for a vector: its ID, or a positional placeholder when the ID is unusable.
 */
function a8csp_cws_pinecone_vector_key( $vector, $position ) {
	if ( is_array( $vector ) && isset( $vector['id'] ) && ( is_string( $vector['id'] ) || is_int( $vector['id'] ) ) && '' !== (string) $vector['id'] ) {
		return (string) $vector['id'];
	}
	return '#' . (int) $position;
}

/**
 * Validate one input vector and build the upsert payload entry, or return an a8csp_bad_input WP_Error.
 */
function a8csp_cws_prepare_pinecone_vector( $vector ) {
	if ( ! is_array( $vector ) ) {
		return a8csp_cws_api_error( 'a8csp_bad_input', 'The vector must be an array.', 'pinecone', 400, false );
	}

	$id = isset( $vector['id'] ) ? $vector['id'] : '';
	if ( ! ( is_string( $id ) || is_int( $id ) ) || '' === (string) $id ) {
		return a8csp_cws_api_error( 'a8csp_bad_input', 'The vector ID is missing.', 'pinecone', 400, false );
	}
	if ( A8CSP_CWS_Utils::safe_strlen( (string) $id ) > 512 ) {
		return a8csp_cws_api_error( 'a8csp_bad_input', 'The vector ID is longer than 512 characters.', 'pinecone', 400, false );
	}

	$values = a8csp_cws_validate_embedding( isset( $vector['values'] ) ? $vector['values'] : null );
	if ( null === $values ) {
		return a8csp_cws_api_error( 'a8csp_bad_input', 'The vector values must be a non-empty list of numbers.', 'pinecone', 400, false );
	}
	if ( ! array_filter( $values ) ) {
		return a8csp_cws_api_error( 'a8csp_bad_input', 'The vector values are all zero, which Pinecone rejects.', 'pinecone', 400, false );
	}

	$metadata = isset( $vector['metadata'] ) ? $vector['metadata'] : array();
	if ( ! is_array( $metadata ) ) {
		return a8csp_cws_api_error( 'a8csp_bad_input', 'The vector metadata must be an array.', 'pinecone', 400, false );
	}

	$prepared = array(
		'id' => (string) $id,
		'values' => $values,
	);

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

	// An empty PHP array encodes as a JSON list, not the object Pinecone expects for metadata.
	if ( ! empty( $sanitized_metadata ) ) {
		$prepared['metadata'] = $sanitized_metadata;
	}

	return $prepared;
}

/**
 * Return an a8csp_config_missing WP_Error when the Pinecone settings are unusable, else null.
 */
function a8csp_cws_check_pinecone_config() {
	if ( ! defined('PINECONE_SERVER_URL') || ! defined('PINECONE_API_KEY') || empty( PINECONE_SERVER_URL ) || empty( PINECONE_API_KEY ) ) {
		return a8csp_cws_api_error( 'a8csp_config_missing', 'The Pinecone API key or server URL is not configured. Check the plugin settings.', 'pinecone', 0, false );
	}
	if ( ! filter_var( PINECONE_SERVER_URL, FILTER_VALIDATE_URL ) ) {
		return a8csp_cws_api_error( 'a8csp_config_missing', 'The Pinecone server URL is not a valid URL. Check the plugin settings.', 'pinecone', 0, false );
	}
	return null;
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

	$result = a8csp_cws_get_embeddings_batch( array( $text ) );

	if ( isset( $result['embeddings'][0] ) ) {
		return $result['embeddings'][0];
	}

	$error = isset( $result['errors'][0] ) ? $result['errors'][0] : $result['fatal'];
	error_log( 'A8CSP: Embedding failed: ' . ( is_wp_error( $error ) ? $error->get_error_message() : 'unknown error' ) );
	return [];
}

/**
 * Backwards-compatible wrapper. Existing callers expect this name.
 */
function a8csp_cws_get_openai_embedding($text) {
	return a8csp_cws_get_embedding( $text );
}

function a8csp_cws_call_openai_embeddings($text) {
	return a8csp_cws_embed_single_text( 'a8csp_cws_call_openai_embeddings_batch', $text );
}

function a8csp_cws_call_voyage_embeddings($text) {
	return a8csp_cws_embed_single_text( 'a8csp_cws_call_voyage_embeddings_batch', $text );
}

function a8csp_cws_call_gemini_embeddings($text) {
	return a8csp_cws_embed_single_text( 'a8csp_cws_call_gemini_embeddings_batch', $text );
}

/**
 * Embed one text with a specific provider's batch function; returns float[] or [].
 */
function a8csp_cws_embed_single_text( $callback, $text ) {
	$text = is_string( $text ) ? sanitize_text_field( $text ) : '';
	if ( '' === $text ) {
		error_log('A8CSP: Invalid text provided for embedding');
		return [];
	}

	$vectors = call_user_func( $callback, array( $text ) );
	if ( is_wp_error( $vectors ) ) {
		error_log( 'A8CSP: Embedding failed: ' . $vectors->get_error_message() );
		return [];
	}

	return isset( $vectors[0] ) ? $vectors[0] : [];
}

/**
 * Embed many texts with the configured provider; each input key lands in exactly one result bucket.
 */
function a8csp_cws_get_embeddings_batch( array $texts, array $args = array() ) {
	$max_retries = isset( $args['max_retries'] ) ? max( 0, (int) $args['max_retries'] ) : 2;
	$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'openai';

	switch ( $provider ) {
		case 'google':
			$callback = 'a8csp_cws_call_gemini_embeddings_batch';
			$api = 'gemini';
			break;
		case 'anthropic':
			$callback = 'a8csp_cws_call_voyage_embeddings_batch';
			$api = 'voyage';
			break;
		case 'openai':
		default:
			$callback = 'a8csp_cws_call_openai_embeddings_batch';
			$api = 'openai';
			break;
	}

	$embeddings = array();
	$errors = array();
	$prepared = array();

	foreach ( $texts as $key => $text ) {
		$clean = is_string( $text ) ? a8csp_cws_prepare_embedding_text( $text ) : '';
		if ( '' === $clean ) {
			$errors[ $key ] = a8csp_cws_api_error( 'a8csp_bad_input', 'The text is empty after sanitization, so there is nothing to embed.', $api, 400, false );
			continue;
		}
		$prepared[ $key ] = $clean;
	}

	$limits = a8csp_cws_get_embedding_batch_limits( $provider );
	$chunks = array();
	$current = array();
	$current_chars = 0;
	foreach ( $prepared as $key => $text ) {
		$length = strlen( $text );
		if ( ! empty( $current ) && ( count( $current ) >= $limits['max_items'] || ( $limits['max_chars'] > 0 && $current_chars + $length > $limits['max_chars'] ) ) ) {
			$chunks[] = $current;
			$current = array();
			$current_chars = 0;
		}
		$current[ $key ] = $text;
		$current_chars += $length;
	}
	if ( ! empty( $current ) ) {
		$chunks[] = $current;
	}

	$fatal = null;
	foreach ( $chunks as $chunk ) {
		$fatal = a8csp_cws_embed_text_chunk( $callback, $api, $chunk, $max_retries, $embeddings, $errors );
		if ( null !== $fatal ) {
			error_log( 'A8CSP: Embedding batch stopped: ' . $fatal->get_error_message() );
			break;
		}
	}

	$result = array(
		'embeddings' => array(),
		'errors' => array(),
		'pending' => array(),
		'fatal' => $fatal,
	);
	foreach ( array_keys( $texts ) as $key ) {
		if ( isset( $embeddings[ $key ] ) ) {
			$result['embeddings'][ $key ] = $embeddings[ $key ];
		} elseif ( isset( $errors[ $key ] ) ) {
			$result['errors'][ $key ] = $errors[ $key ];
		} else {
			$result['pending'][] = $key;
		}
	}

	return $result;
}

/**
 * Embed one chunk; a splittable 400 is retried in halves to isolate the bad text. Returns a fatal WP_Error or null.
 */
function a8csp_cws_embed_text_chunk( $callback, $api, array $chunk, $max_retries, array &$embeddings, array &$errors, $vectors = null ) {
	$keys = array_keys( $chunk );
	if ( null === $vectors ) {
		$vectors = call_user_func( $callback, array_values( $chunk ), $max_retries );
	}

	if ( is_array( $vectors ) && count( $vectors ) === count( $keys ) ) {
		foreach ( $keys as $position => $key ) {
			$embeddings[ $key ] = $vectors[ $position ];
		}
		return null;
	}

	if ( ! is_wp_error( $vectors ) ) {
		return a8csp_cws_invalid_response_error( $api, sprintf( 'expected %d embeddings', count( $keys ) ) );
	}

	if ( ! a8csp_cws_is_splittable_error( $vectors ) ) {
		return $vectors;
	}

	if ( count( $chunk ) > 1 ) {
		return a8csp_cws_split_failed_chunk(
			$chunk,
			$vectors,
			function ( $part ) use ( $callback, $max_retries ) {
				return call_user_func( $callback, array_values( $part ), $max_retries );
			},
			function ( $part, $part_vectors ) use ( $callback, $api, $max_retries, &$embeddings, &$errors ) {
				return a8csp_cws_embed_text_chunk( $callback, $api, $part, $max_retries, $embeddings, $errors, $part_vectors );
			}
		);
	}

	$errors[ $keys[0] ] = a8csp_cws_bad_input_from_error( $vectors );
	error_log( 'A8CSP: Embedding rejected for one text: ' . $vectors->get_error_message() );
	return null;
}

/**
 * Same preprocessing as the single-text path: cap at 8000 bytes, then sanitize_text_field().
 */
function a8csp_cws_prepare_embedding_text( $text ) {
	$max_length = 8000;
	if ( strlen( $text ) > $max_length ) {
		$cut = substr( $text, 0, $max_length );
		// A byte cut inside a multibyte character makes sanitize_text_field() return '' for the whole text.
		if ( function_exists( 'mb_strcut' ) && function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $cut, 'UTF-8' ) ) {
			$safe_cut = mb_strcut( $text, 0, $max_length, 'UTF-8' );
			if ( mb_check_encoding( $safe_cut, 'UTF-8' ) ) {
				$cut = $safe_cut;
			}
		}
		$text = $cut;
	}

	return sanitize_text_field( $text );
}

/**
 * Per-request chunk limits for a provider (AI_PROVIDER value): max_items, and max_chars (0 = no cap).
 */
function a8csp_cws_get_embedding_batch_limits( $provider ) {
	switch ( $provider ) {
		case 'google':
			// Keeps one request (about 15K tokens) under the free tier's per-minute token limit.
			$limits = array( 'max_items' => 100, 'max_chars' => 60000 );
			break;
		case 'anthropic':
			$model = defined('VOYAGE_EMBEDDING_MODEL') ? VOYAGE_EMBEDDING_MODEL : '';
			$limits = array( 'max_items' => 128, 'max_chars' => ( 'voyage-4-lite' === $model ) ? 1500000 : 200000 );
			break;
		case 'openai':
		default:
			$limits = array( 'max_items' => 256, 'max_chars' => 600000 );
			break;
	}

	$filtered = apply_filters( 'a8csp_cws_embedding_batch_limits', $limits, $provider );
	if ( is_array( $filtered ) ) {
		$limits = array_merge( $limits, $filtered );
	}

	return array(
		'max_items' => max( 1, (int) $limits['max_items'] ),
		'max_chars' => max( 0, (int) $limits['max_chars'] ),
	);
}

function a8csp_cws_call_openai_embeddings_batch( array $texts, $max_retries = 2 ) {
	if ( empty( OPENAI_API_KEY ) || empty( OPENAI_EMBEDDING_MODEL ) ) {
		return a8csp_cws_api_error( 'a8csp_config_missing', 'The OpenAI API key or embedding model is not configured. Check the plugin settings.', 'openai', 0, false );
	}

	$texts = array_values( $texts );
	if ( empty( $texts ) ) {
		return array();
	}

	$headers = array(
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . OPENAI_API_KEY,
	);
	if ( defined('OPENAI_ORG_ID') && OPENAI_ORG_ID ) {
		$headers['OpenAI-Organization'] = OPENAI_ORG_ID;
	}

	$response = a8csp_cws_post_with_retry( 'https://api.openai.com/v1/embeddings', array(
		'headers' => $headers,
		'body' => wp_json_encode( array(
			'model' => OPENAI_EMBEDDING_MODEL,
			'input' => $texts,
		) ),
		'timeout' => 60,
	), $max_retries );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return a8csp_cws_classify_http_error( $response, 'openai' );
	}

	return a8csp_cws_parse_indexed_embeddings( json_decode( wp_remote_retrieve_body( $response ), true ), count( $texts ), 'openai' );
}

function a8csp_cws_call_voyage_embeddings_batch( array $texts, $max_retries = 2 ) {
	if ( empty( VOYAGE_API_KEY ) || empty( VOYAGE_EMBEDDING_MODEL ) ) {
		return a8csp_cws_api_error( 'a8csp_config_missing', 'The Voyage AI API key or embedding model is not configured. Check the plugin settings.', 'voyage', 0, false );
	}

	$texts = array_values( $texts );
	if ( empty( $texts ) ) {
		return array();
	}

	$response = a8csp_cws_post_with_retry( 'https://api.voyageai.com/v1/embeddings', array(
		'headers' => array(
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . VOYAGE_API_KEY,
		),
		'body' => wp_json_encode( array(
			'model' => VOYAGE_EMBEDDING_MODEL,
			'input' => $texts,
		) ),
		'timeout' => 60,
	), $max_retries );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return a8csp_cws_classify_http_error( $response, 'voyage' );
	}

	return a8csp_cws_parse_indexed_embeddings( json_decode( wp_remote_retrieve_body( $response ), true ), count( $texts ), 'voyage' );
}

function a8csp_cws_call_gemini_embeddings_batch( array $texts, $max_retries = 2 ) {
	if ( empty( GOOGLE_API_KEY ) || empty( GOOGLE_EMBEDDING_MODEL ) ) {
		return a8csp_cws_api_error( 'a8csp_config_missing', 'The Google API key or Gemini embedding model is not configured. Check the plugin settings.', 'gemini', 0, false );
	}

	$texts = array_values( $texts );
	if ( empty( $texts ) ) {
		return array();
	}

	$requests = array();
	foreach ( $texts as $text ) {
		$requests[] = array(
			'model' => 'models/' . GOOGLE_EMBEDDING_MODEL,
			'content' => array(
				'parts' => array(
					array( 'text' => $text ),
				),
			),
		);
	}

	$url = sprintf(
		'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents?key=%s',
		rawurlencode( GOOGLE_EMBEDDING_MODEL ),
		rawurlencode( GOOGLE_API_KEY )
	);

	$response = a8csp_cws_post_with_retry( $url, array(
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body' => wp_json_encode( array( 'requests' => $requests ) ),
		'timeout' => 60,
	), $max_retries );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return a8csp_cws_classify_http_error( $response, 'gemini' );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || ! isset( $body['embeddings'] ) || ! is_array( $body['embeddings'] ) ) {
		return a8csp_cws_invalid_response_error( 'gemini', 'the response has no embeddings' );
	}
	if ( count( $body['embeddings'] ) !== count( $texts ) ) {
		return a8csp_cws_invalid_response_error( 'gemini', sprintf( 'expected %d embeddings, got %d', count( $texts ), count( $body['embeddings'] ) ) );
	}

	$vectors = array();
	foreach ( array_values( $body['embeddings'] ) as $position => $item ) {
		$values = a8csp_cws_validate_embedding( ( is_array( $item ) && isset( $item['values'] ) ) ? $item['values'] : null );
		if ( null === $values ) {
			return a8csp_cws_invalid_response_error( 'gemini', sprintf( 'embedding %d is empty or not numeric', $position ) );
		}
		$vectors[] = $values;
	}

	return $vectors;
}

/**
 * Map an OpenAI/Voyage style `data[i].index` response back to input order, validating every embedding.
 */
function a8csp_cws_parse_indexed_embeddings( $body, $expected, $provider ) {
	if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
		return a8csp_cws_invalid_response_error( $provider, 'the response has no embeddings' );
	}
	if ( count( $body['data'] ) !== $expected ) {
		return a8csp_cws_invalid_response_error( $provider, sprintf( 'expected %d embeddings, got %d', $expected, count( $body['data'] ) ) );
	}

	$vectors = array();
	foreach ( $body['data'] as $item ) {
		$index = ( is_array( $item ) && isset( $item['index'] ) ) ? $item['index'] : null;
		if ( ! is_int( $index ) || $index < 0 || $index >= $expected || isset( $vectors[ $index ] ) ) {
			return a8csp_cws_invalid_response_error( $provider, 'an embedding has a missing, duplicate or out-of-range index' );
		}

		$values = a8csp_cws_validate_embedding( isset( $item['embedding'] ) ? $item['embedding'] : null );
		if ( null === $values ) {
			return a8csp_cws_invalid_response_error( $provider, sprintf( 'embedding %d is empty or not numeric', $index ) );
		}
		$vectors[ $index ] = $values;
	}

	ksort( $vectors );
	return array_values( $vectors );
}

/**
 * Return the embedding as a list when it is a non-empty array of finite numbers, else null.
 */
function a8csp_cws_validate_embedding( $values ) {
	if ( ! is_array( $values ) || empty( $values ) ) {
		return null;
	}
	foreach ( $values as $value ) {
		if ( ! ( is_int( $value ) || is_float( $value ) ) || ! is_finite( (float) $value ) ) {
			return null;
		}
	}
	return array_values( $values );
}

/**
 * Classify a failed HTTP call (WP_Error or non-200 response) into one of the a8csp_* error codes.
 */
function a8csp_cws_classify_http_error( $response, $provider ) {
	$label = a8csp_cws_api_provider_label( $provider );

	if ( is_wp_error( $response ) ) {
		$message = a8csp_cws_append_error_detail( sprintf( 'Could not connect to %s.', $label ), a8csp_cws_clean_error_text( $response->get_error_message() ) );
		return a8csp_cws_api_error( 'a8csp_provider_error', $message, $provider, 0, true );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw = (string) wp_remote_retrieve_body( $response );
	$body = json_decode( $raw, true );
	$detail = a8csp_cws_extract_error_detail( $raw, $body );
	$retry_header = wp_remote_retrieve_header( $response, 'retry-after' );

	if ( $status <= 0 ) {
		return a8csp_cws_api_error( 'a8csp_provider_error', a8csp_cws_append_error_detail( sprintf( 'Could not connect to %s.', $label ), $detail ), $provider, 0, true );
	}

	if ( 429 === $status ) {
		$quota_code = ( 'openai' === $provider ) ? a8csp_cws_get_openai_quota_code( $body ) : '';
		if ( '' !== $quota_code ) {
			$message = sprintf( 'OpenAI quota or billing limit reached (%s). Check your OpenAI plan and billing details.', $quota_code );
			return a8csp_cws_api_error( 'a8csp_quota_exhausted', a8csp_cws_append_error_detail( $message, $detail ), $provider, 429, false, array( 'retry_after' => null ) );
		}

		// Pinecone's monthly usage caps also answer 429, and retrying can't help until the limit resets.
		if ( 'pinecone' === $provider && preg_match( '/\bmonth(?:ly)?\b|\bbilling (?:period|cycle)\b/i', $raw ) ) {
			$message = 'Pinecone plan limit reached (monthly read units, write units or embedding tokens). Check or upgrade the Pinecone plan; retrying will not help until the limit resets.';
			return a8csp_cws_api_error( 'a8csp_quota_exhausted', a8csp_cws_append_error_detail( $message, $detail ), $provider, 429, false, array( 'retry_after' => null ) );
		}

		$retry_source = $retry_header;
		if ( 'gemini' === $provider ) {
			$gemini = a8csp_cws_parse_gemini_error_details( $body );
			if ( $gemini['daily'] ) {
				$reset = new DateTime( 'tomorrow', new DateTimeZone( 'America/Los_Angeles' ) );
				$extra = array(
					'retry_after' => max( 60, $reset->getTimestamp() - time() ) + 300,
					'daily_quota' => true,
				);
				$message = 'Gemini daily quota exhausted; it resets at midnight Pacific time.';
				return a8csp_cws_api_error( 'a8csp_quota_exhausted', a8csp_cws_append_error_detail( $message, $detail ), $provider, 429, true, $extra );
			}
			if ( null !== $gemini['retry_delay'] ) {
				$retry_source = $gemini['retry_delay'];
			}
		}

		$extra = array();
		if ( '' !== $retry_source ) {
			$extra['retry_after'] = a8csp_cws_parse_retry_after( $retry_source );
		}
		$message = sprintf( '%s rate limit reached.', $label );
		return a8csp_cws_api_error( 'a8csp_rate_limited', a8csp_cws_append_error_detail( $message, $detail ), $provider, 429, true, $extra );
	}

	if ( 401 === $status || 403 === $status ) {
		$message = sprintf( '%s rejected the request (HTTP %d). Check the API key.', $label, $status );
		return a8csp_cws_api_error( 'a8csp_provider_error', a8csp_cws_append_error_detail( $message, $detail ), $provider, $status, false );
	}

	if ( 404 === $status ) {
		$hint = ( 'pinecone' === $provider ) ? 'Check the Pinecone server URL.' : 'Check the embedding model setting.';
		$message = sprintf( '%s returned HTTP 404. %s', $label, $hint );
		return a8csp_cws_api_error( 'a8csp_provider_error', a8csp_cws_append_error_detail( $message, $detail ), $provider, 404, false );
	}

	if ( 400 === $status || 413 === $status ) {
		$request_level = a8csp_cws_is_request_level_error( $provider, $body, $detail );
		$message = sprintf( '%s rejected the request (HTTP %d).', $label, $status );
		if ( $request_level && false !== stripos( $detail, 'api key' ) ) {
			$message .= ' Check the API key.';
		}
		return a8csp_cws_api_error( 'a8csp_provider_error', a8csp_cws_append_error_detail( $message, $detail ), $provider, $status, false, array( 'splittable' => ! $request_level ) );
	}

	if ( $status >= 500 ) {
		$extra = array();
		if ( '' !== $retry_header ) {
			$extra['retry_after'] = a8csp_cws_parse_retry_after( $retry_header );
		}
		$message = sprintf( '%s returned a server error (HTTP %d).', $label, $status );
		return a8csp_cws_api_error( 'a8csp_provider_error', a8csp_cws_append_error_detail( $message, $detail ), $provider, $status, true, $extra );
	}

	if ( $status >= 200 && $status < 300 ) {
		return a8csp_cws_invalid_response_error( $provider, sprintf( 'HTTP %d', $status ) );
	}

	$message = sprintf( '%s rejected the request (HTTP %d).', $label, $status );
	return a8csp_cws_api_error( 'a8csp_provider_error', a8csp_cws_append_error_detail( $message, $detail ), $provider, $status, false );
}

/**
 * True for a 400/413 that may be caused by one item in the batch (so halving the batch can isolate it).
 */
function a8csp_cws_is_splittable_error( $error ) {
	if ( ! is_wp_error( $error ) || 'a8csp_provider_error' !== $error->get_error_code() ) {
		return false;
	}
	$data = $error->get_error_data();
	return is_array( $data ) && ! empty( $data['splittable'] );
}

/**
 * True when an a8csp_* error says a later retry may succeed (rate limit, retryable quota, network or 5xx).
 */
function a8csp_cws_is_retryable_error( $error ) {
	$data = is_wp_error( $error ) ? $error->get_error_data() : null;
	return is_array( $data ) && ! empty( $data['retryable'] );
}

/**
 * Detect 400s caused by configuration (key, model, index dimension) rather than by an item in the batch.
 */
function a8csp_cws_is_request_level_error( $provider, $body, $detail ) {
	if ( false !== stripos( $detail, 'api key' ) ) {
		return true;
	}
	if ( 'gemini' === $provider && is_array( $body ) && isset( $body['error']['status'] ) && in_array( $body['error']['status'], array( 'FAILED_PRECONDITION', 'PERMISSION_DENIED', 'UNAUTHENTICATED' ), true ) ) {
		return true;
	}
	if ( 'pinecone' === $provider && false !== stripos( $detail, 'dimension' ) ) {
		return true;
	}
	if ( 'pinecone' === $provider && is_array( $body ) && isset( $body['code'] ) && in_array( $body['code'], array( 9, 'FAILED_PRECONDITION' ), true ) ) {
		return true;
	}
	return (bool) preg_match( '/\bmodel\b.{0,80}?\b(?:not supported|not found|does not exist)/i', $detail );
}

/**
 * Returns the OpenAI billing/quota error code from a 429 body, or '' when it is an ordinary rate limit.
 */
function a8csp_cws_get_openai_quota_code( $body ) {
	if ( ! is_array( $body ) || ! isset( $body['error'] ) || ! is_array( $body['error'] ) ) {
		return '';
	}

	$quota_codes = array(
		'insufficient_quota',
		'credit_balance_exhausted',
		'organization_spend_limit_exceeded',
		'project_spend_limit_exceeded',
		'organization_usage_limit_exceeded',
		'billing_hard_limit_reached',
	);
	$code = ( isset( $body['error']['code'] ) && is_string( $body['error']['code'] ) ) ? $body['error']['code'] : '';
	$type = ( isset( $body['error']['type'] ) && is_string( $body['error']['type'] ) ) ? $body['error']['type'] : '';

	if ( in_array( $code, $quota_codes, true ) ) {
		return $code;
	}
	return ( 'insufficient_quota' === $type ) ? $type : '';
}

/**
 * Read Gemini's QuotaFailure (daily quota?) and RetryInfo (retryDelay) details from an error body.
 */
function a8csp_cws_parse_gemini_error_details( $body ) {
	$info = array(
		'daily' => false,
		'retry_delay' => null,
	);
	if ( ! is_array( $body ) || ! isset( $body['error']['details'] ) || ! is_array( $body['error']['details'] ) ) {
		return $info;
	}

	foreach ( $body['error']['details'] as $detail ) {
		if ( ! is_array( $detail ) ) {
			continue;
		}
		$type = isset( $detail['@type'] ) && is_string( $detail['@type'] ) ? $detail['@type'] : '';

		if ( false !== strpos( $type, 'QuotaFailure' ) && isset( $detail['violations'] ) && is_array( $detail['violations'] ) ) {
			foreach ( $detail['violations'] as $violation ) {
				$quota_id = ( is_array( $violation ) && isset( $violation['quotaId'] ) && is_string( $violation['quotaId'] ) ) ? $violation['quotaId'] : '';
				if ( false !== stripos( $quota_id, 'PerDay' ) || false !== stripos( $quota_id, 'Daily' ) ) {
					$info['daily'] = true;
				}
			}
		} elseif ( false !== strpos( $type, 'RetryInfo' ) && isset( $detail['retryDelay'] ) ) {
			$info['retry_delay'] = $detail['retryDelay'];
		}
	}

	return $info;
}

/**
 * Parse Retry-After or retryDelay ("34", "34s", "1.5s", HTTP date) into whole seconds, clamped to 1..3600.
 */
function a8csp_cws_parse_retry_after( $value ) {
	if ( is_array( $value ) ) {
		$value = reset( $value );
	}
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';
	$seconds = 60;

	if ( preg_match( '/^(\d+(?:\.\d+)?)\s*s?$/i', $value, $matches ) ) {
		$seconds = (int) ceil( (float) $matches[1] );
	} elseif ( preg_match( '/GMT|UTC/i', $value ) ) {
		$timestamp = strtotime( $value );
		if ( false !== $timestamp ) {
			$seconds = $timestamp - time();
		}
	}

	return (int) min( 3600, max( 1, $seconds ) );
}

/**
 * Pull the provider's own error message out of a JSON or plain-text body, sanitized and truncated.
 */
function a8csp_cws_extract_error_detail( $raw, $body ) {
	$detail = '';
	if ( is_array( $body ) ) {
		if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
			$detail = $body['error']['message'];
		} elseif ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
			$detail = $body['error'];
		} elseif ( isset( $body['detail'] ) && is_string( $body['detail'] ) ) {
			$detail = $body['detail'];
		} elseif ( isset( $body['detail'][0]['msg'] ) && is_string( $body['detail'][0]['msg'] ) ) {
			$detail = $body['detail'][0]['msg'];
		} elseif ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
			$detail = $body['message'];
		}
	}

	if ( '' === $detail ) {
		$detail = A8CSP_CWS_Utils::safe_substr( $raw, 0, 2000 );
	}

	return a8csp_cws_clean_error_text( $detail );
}

/**
 * Sanitize provider-supplied text for admin display: strip markup, redact secrets, truncate.
 */
function a8csp_cws_clean_error_text( $text, $max_length = 200 ) {
	if ( ! is_scalar( $text ) ) {
		return '';
	}

	$text = a8csp_cws_redact_secrets( sanitize_text_field( (string) $text ) );
	if ( A8CSP_CWS_Utils::safe_strlen( $text ) > $max_length ) {
		$text = rtrim( A8CSP_CWS_Utils::safe_substr( $text, 0, $max_length - 1 ) ) . '…';
	}

	return $text;
}

function a8csp_cws_redact_secrets( $text ) {
	foreach ( array( 'OPENAI_API_KEY', 'VOYAGE_API_KEY', 'GOOGLE_API_KEY', 'PINECONE_API_KEY', 'ANTHROPIC_API_KEY' ) as $constant ) {
		$secret = defined( $constant ) ? constant( $constant ) : '';
		if ( is_string( $secret ) && strlen( $secret ) >= 8 ) {
			$text = str_replace( array( $secret, rawurlencode( $secret ) ), '[redacted]', $text );
		}
	}

	$redacted = preg_replace(
		array(
			'/([?&](?:key|api_key|apikey)=)[^&\s]+/i',
			'/\b(?:sk|pa|pcsk)[-_][A-Za-z0-9_\-]{16,}/',
			'/\bAIza[0-9A-Za-z_\-]{20,}/',
		),
		array( '$1[redacted]', '[redacted]', '[redacted]' ),
		$text
	);

	return is_string( $redacted ) ? $redacted : '';
}

function a8csp_cws_append_error_detail( $message, $detail ) {
	return ( '' !== $detail ) ? $message . ' Provider message: ' . $detail : $message;
}

function a8csp_cws_api_provider_label( $provider ) {
	$labels = array(
		'openai' => 'OpenAI',
		'voyage' => 'Voyage AI',
		'gemini' => 'Gemini',
		'pinecone' => 'Pinecone',
	);
	return isset( $labels[ $provider ] ) ? $labels[ $provider ] : 'The provider';
}

function a8csp_cws_api_error( $code, $message, $provider, $status, $retryable, array $extra = array() ) {
	return new WP_Error( $code, $message, array_merge( array(
		'status' => (int) $status,
		'provider' => $provider,
		'retryable' => (bool) $retryable,
	), $extra ) );
}

function a8csp_cws_invalid_response_error( $provider, $reason ) {
	$message = sprintf( '%s returned an unexpected response (%s).', a8csp_cws_api_provider_label( $provider ), $reason );
	return a8csp_cws_api_error( 'a8csp_invalid_response', $message, $provider, 200, true );
}

/**
 * Turn a splittable provider error for a single item into that item's a8csp_bad_input error.
 */
function a8csp_cws_bad_input_from_error( WP_Error $error ) {
	$data = $error->get_error_data();
	return a8csp_cws_api_error(
		'a8csp_bad_input',
		$error->get_error_message(),
		( is_array( $data ) && isset( $data['provider'] ) ) ? $data['provider'] : '',
		( is_array( $data ) && isset( $data['status'] ) ) ? $data['status'] : 400,
		false
	);
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
			'maxOutputTokens' => 1024,
			'temperature' => 0.3,
		],
	];

	// Thinking tokens count against maxOutputTokens and can leave no room for the answer.
	if ( 0 === strpos( GOOGLE_MODEL, 'gemini-2.5-' ) ) {
		$data['generationConfig']['thinkingConfig'] = [ 'thinkingBudget' => 0 ];
	} elseif ( 0 === strpos( GOOGLE_MODEL, 'gemini-3' ) ) {
		$data['generationConfig']['thinkingConfig'] = [ 'thinkingLevel' => 'minimal' ];
	}
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
