<?php
/**
 * Utility functions for the A8CSP Site Chatbot plugin
 *
 * @package A8CSP_Site_Chatbot
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utility class with static helper methods for Chat With Site plugin
 */
class A8CSP_CWS_Utils {

	/**
	 * Safe substring function that handles multibyte characters properly
	 *
	 * @param string $string The input string
	 * @param int    $start  The starting position
	 * @param int    $length The maximum length
	 * @return string The truncated string
	 */
	public static function safe_substr( $string, $start, $length ) {
		return function_exists( 'mb_substr' ) 
			? mb_substr( $string, $start, $length ) 
			: substr( $string, $start, $length );
	}

	/**
	 * Safe string length function that handles multibyte characters properly
	 *
	 * @param string $string The input string
	 * @return int The string length
	 */
	public static function safe_strlen( $string ) {
		return function_exists( 'mb_strlen' ) 
			? mb_strlen( $string ) 
			: strlen( $string );
	}

	/**
	 * Sanitize and truncate a response for logging purposes
	 *
	 * @param mixed $response The response to sanitize
	 * @param int   $max_length Maximum length for the snippet (default: 2000)
	 * @return string Sanitized and truncated string
	 */
	public static function sanitize_for_log( $response, $max_length = 2000 ) {
		$string = is_string( $response ) ? $response : print_r( $response, true );
		return self::safe_substr( $string, 0, $max_length );
	}
}
