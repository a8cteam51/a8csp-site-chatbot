<?php
/**
 * Chat history storage (a random chat ID in a cookie, with the conversation kept in a transient) and the history-aware archive search.
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'A8CSP_CWS_CHAT_COOKIE', 'a8csp_cws_chat' );
define( 'A8CSP_CWS_CHAT_MAX_MESSAGES', 20 );

/**
 * The chat ID from the visitor's cookie, or '' when it is missing or malformed.
 */
function a8csp_cws_get_chat_id() {
	$chat_id = isset( $_COOKIE[ A8CSP_CWS_CHAT_COOKIE ] ) && is_string( $_COOKIE[ A8CSP_CWS_CHAT_COOKIE ] ) ? $_COOKIE[ A8CSP_CWS_CHAT_COOKIE ] : '';

	return preg_match( '/^[a-f0-9]{32}$/', $chat_id ) ? $chat_id : '';
}

/**
 * Transient name for a chat; keyed by a salted hash so stored keys can't be used as cookies.
 */
function a8csp_cws_chat_history_key( $chat_id ) {
	return 'a8csp_cws_chat_' . substr( hash_hmac( 'sha256', (string) $chat_id, wp_salt( 'auth' ) ), 0, 32 );
}

/**
 * Seconds a chat is kept after its last message.
 */
function a8csp_cws_chat_history_ttl() {
	$ttl = (int) apply_filters( 'a8csp_cws_chat_history_ttl', 2 * HOUR_IN_SECONDS );

	return $ttl > 0 ? $ttl : 2 * HOUR_IN_SECONDS;
}

/**
 * Stored messages of a chat, oldest first; only well-formed user and assistant messages are returned.
 */
function a8csp_cws_get_chat_history( $chat_id ) {
	if ( '' === (string) $chat_id ) {
		return array();
	}

	$stored = get_transient( a8csp_cws_chat_history_key( $chat_id ) );
	if ( ! is_array( $stored ) ) {
		return array();
	}

	$history = array();
	foreach ( $stored as $message ) {
		if ( is_array( $message ) && isset( $message['role'], $message['content'] ) && in_array( $message['role'], array( 'user', 'assistant' ), true ) && is_string( $message['content'] ) ) {
			$history[] = array(
				'role'    => $message['role'],
				'content' => $message['content'],
			);
		}
	}

	return array_slice( $history, -A8CSP_CWS_CHAT_MAX_MESSAGES );
}

function a8csp_cws_save_chat_history( $chat_id, array $history ) {
	if ( '' === (string) $chat_id ) {
		return false;
	}

	return set_transient( a8csp_cws_chat_history_key( $chat_id ), array_values( array_slice( $history, -A8CSP_CWS_CHAT_MAX_MESSAGES ) ), a8csp_cws_chat_history_ttl() );
}

/**
 * Chat ID for a new message: the cookie's chat when it has stored messages, otherwise a new chat with a new cookie.
 */
function a8csp_cws_start_chat() {
	$chat_id = a8csp_cws_get_chat_id();
	// Only reuse IDs this site issued and still stores, so a planted cookie can't start a shared chat.
	if ( '' !== $chat_id && array() !== a8csp_cws_get_chat_history( $chat_id ) ) {
		return $chat_id;
	}

	try {
		$chat_id = bin2hex( random_bytes( 16 ) );
	} catch ( Exception $e ) {
		$chat_id = md5( wp_generate_password( 64, true, true ) );
	}

	if ( ! headers_sent() ) {
		setcookie(
			A8CSP_CWS_CHAT_COOKIE,
			$chat_id,
			array(
				'expires'  => 0,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}
	$_COOKIE[ A8CSP_CWS_CHAT_COOKIE ] = $chat_id;

	return $chat_id;
}

/**
 * Text the archive is searched with: the last few user messages in order, dropping the oldest to fit $max_bytes.
 */
function a8csp_cws_build_search_query( array $history, $max_user_messages = 3, $max_bytes = 2000 ) {
	$parts = array();
	$length = 0;
	for ( $i = count( $history ) - 1; $i >= 0 && count( $parts ) < $max_user_messages; $i-- ) {
		if ( ! is_array( $history[ $i ] ) || 'user' !== ( $history[ $i ]['role'] ?? '' ) ) {
			continue;
		}

		$text = trim( (string) ( $history[ $i ]['content'] ?? '' ) );
		if ( '' === $text ) {
			continue;
		}

		$room = $max_bytes - $length - ( empty( $parts ) ? 0 : 1 );
		if ( $room <= 0 ) {
			break;
		}
		if ( strlen( $text ) > $room ) {
			if ( ! empty( $parts ) ) {
				break;
			}
			$text = a8csp_cws_truncate_utf8( $text, $room );
		}

		array_unshift( $parts, $text );
		$length += strlen( $text ) + ( count( $parts ) > 1 ? 1 : 0 );
	}

	return implode( "\n", $parts );
}

/**
 * One match per post, best score first: the highest score a post got in any of the searches.
 */
function a8csp_cws_merge_matches( array $match_lists, $limit = 5 ) {
	$best = array();
	foreach ( $match_lists as $matches ) {
		foreach ( (array) $matches as $match ) {
			if ( ! is_array( $match ) ) {
				continue;
			}
			$key = isset( $match['metadata']['post_id'] ) ? 'post:' . (int) $match['metadata']['post_id'] : 'id:' . ( $match['id'] ?? '' );
			$score = isset( $match['score'] ) && is_numeric( $match['score'] ) ? (float) $match['score'] : 0.0;
			if ( ! isset( $best[ $key ] ) || $score > $best[ $key ]['score'] ) {
				$best[ $key ] = array(
					'score' => $score,
					'match' => $match,
				);
			}
		}
	}

	// Stable sort: equal scores keep the order of the first search.
	$order = array_keys( $best );
	uksort(
		$best,
		function ( $a, $b ) use ( $best, $order ) {
			return $best[ $b ]['score'] <=> $best[ $a ]['score'] ?: array_search( $a, $order, true ) <=> array_search( $b, $order, true );
		}
	);

	return array_slice( array_column( $best, 'match' ), 0, $limit );
}

/**
 * Archive matches for the latest message alone and with the earlier questions, or null when it can't be embedded.
 */
function a8csp_cws_find_archive_matches( array $history ) {
	$latest = '';
	for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
		if ( is_array( $history[ $i ] ) && 'user' === ( $history[ $i ]['role'] ?? '' ) ) {
			$latest = a8csp_cws_build_search_query( array( $history[ $i ] ) );
			break;
		}
	}

	$embedding = a8csp_cws_get_openai_embedding( $latest );
	if ( empty( $embedding ) ) {
		return null;
	}
	$match_lists = array( a8csp_cws_query_pinecone( $embedding ) );

	// A follow-up like "in January, for 2 people" matches weakly alone and strongly with the earlier questions; a new topic is the reverse.
	$combined = a8csp_cws_build_search_query( $history );
	if ( '' !== $combined && $combined !== $latest ) {
		$combined_embedding = a8csp_cws_get_openai_embedding( $combined );
		if ( ! empty( $combined_embedding ) ) {
			$match_lists[] = a8csp_cws_query_pinecone( $combined_embedding );
		}
	}

	return a8csp_cws_merge_matches( $match_lists );
}
