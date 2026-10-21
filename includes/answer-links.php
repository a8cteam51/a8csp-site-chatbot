<?php
/**
 * Links in chat answers: article link extraction, flight and hotel search links, external link verification and the link policy.
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link settings from the plugin options; everything is off when unset.
 */
function a8csp_cws_get_link_settings() {
	$options = get_option( 'a8csp_chat_with_site_options', array() );
	if ( ! is_array( $options ) ) {
		$options = array();
	}

	return array(
		'recommendations' => ! empty( $options['recommendation_links'] ),
		'flight_links'    => ! empty( $options['flight_links'] ),
		'hotel_links'     => ! empty( $options['hotel_links'] ),
		'tracking_label'  => a8csp_cws_sanitize_tracking_label( $options['link_tracking_label'] ?? '' ),
		'skimlinks_id'    => a8csp_cws_sanitize_skimlinks_site_id( $options['skimlinks_site_id'] ?? '' ),
	);
}

/**
 * Skimlinks site IDs look like 12345X678901; anything else is dropped.
 */
function a8csp_cws_sanitize_skimlinks_site_id( $site_id ) {
	if ( ! is_scalar( $site_id ) ) {
		return '';
	}

	$site_id = strtoupper( trim( (string) $site_id ) );

	return preg_match( '/^\d{1,12}X\d{1,12}$/', $site_id ) ? $site_id : '';
}

/**
 * Skimlinks sends this value as the Custom ID (xcust), which only accepts these characters.
 */
function a8csp_cws_sanitize_tracking_label( $label ) {
	if ( ! is_scalar( $label ) ) {
		return '';
	}

	return substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $label ), 0, 50 );
}

function a8csp_cws_strip_www( $host ) {
	return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
}

function a8csp_cws_get_site_host() {
	return a8csp_cws_strip_www( wp_parse_url( home_url(), PHP_URL_HOST ) );
}

/**
 * Comparison key for allow-list checks only, never a URL to output.
 */
function a8csp_cws_normalize_url( $url ) {
	if ( ! is_string( $url ) ) {
		return '';
	}

	$parts = wp_parse_url( trim( $url ) );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}

	$scheme = strtolower( $parts['scheme'] );
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		return '';
	}

	$port = '';
	if ( isset( $parts['port'] ) && ! ( ( 'http' === $scheme && 80 === (int) $parts['port'] ) || ( 'https' === $scheme && 443 === (int) $parts['port'] ) ) ) {
		$port = ':' . (int) $parts['port'];
	}

	$path = rtrim( $parts['path'] ?? '', '/' );
	if ( '' === $path ) {
		$path = '/';
	}

	$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

	return $scheme . '://' . a8csp_cws_strip_www( $parts['host'] ) . $port . $path . $query;
}

/**
 * Absolute http(s) URL as it will be output, or '' when it is anything else or carries credentials.
 */
function a8csp_cws_sanitize_http_url( $url ) {
	$url = trim( (string) $url );
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		return '';
	}

	// Check the escaped form so the URL we inspect is the one the browser gets.
	$url = esc_url_raw( $url, array( 'http', 'https' ) );
	$parts = wp_parse_url( $url );
	if ( '' === $url || ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return '';
	}

	return $url;
}

/**
 * Decoded href from a tag's attribute string, or ''.
 */
function a8csp_cws_get_link_href( $attributes ) {
	return a8csp_cws_get_link_attribute( $attributes, 'href' );
}

/**
 * Decoded value of one attribute from a tag's attribute string, or ''.
 */
function a8csp_cws_get_link_attribute( $attributes, $name ) {
	if ( ! preg_match( '/(?:^|\s)' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', (string) $attributes, $match ) ) {
		return '';
	}

	$value = $match[3] ?? ( '' !== ( $match[2] ?? '' ) ? $match[2] : $match[1] );

	return trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

function a8csp_cws_strip_anchor_tags( $html ) {
	return preg_replace( '/<\/?a\b[^>]*>/i', '', $html );
}

function a8csp_cws_is_social_host( $host ) {
	$social_domains = array( 'instagram.com', 'facebook.com', 'twitter.com', 'x.com', 'pinterest.com', 'tiktok.com', 'linkedin.com', 'threads.net' );

	foreach ( $social_domains as $domain ) {
		if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
			return true;
		}
	}

	return false;
}

/**
 * Raw post content without what the visitor can't see: anything past a Jetpack paywall, HTML comments and shortcodes.
 */
function a8csp_cws_get_visible_raw_content( $post ) {
	$content = (string) $post->post_content;

	if ( ! empty( $post->ID ) && class_exists( 'Jetpack_Memberships' ) && method_exists( 'Jetpack_Memberships', 'user_can_view_post' ) && ! Jetpack_Memberships::user_can_view_post( (int) $post->ID ) ) {
		$paywall = strpos( $content, '<!-- wp:jetpack/paywall' );
		// Without a paywall block, the whole post is for members only.
		if ( false === $paywall ) {
			return '';
		}
		$content = substr( $content, 0, $paywall );
	}

	$content = preg_replace( '/<!--.*?-->/s', '', (string) $content );

	$gated = (array) apply_filters( 'a8csp_cws_gated_shortcodes', array( 'mepr-show', 'mepr-active', 'restrict', 'members_only', 'pmpro_member', 'rcp_is_paid', 'swpm_protected', 'wcm_restrict', 'um_loggedin' ) );
	if ( ! empty( $gated ) ) {
		$content = preg_replace( '/' . get_shortcode_regex( $gated ) . '/', '', (string) $content );
	}

	// Layout shortcodes wrap visible text, so keep their content and drop only the tags.
	for ( $pass = 0; $pass < 3 && null !== $content; $pass++ ) {
		$unwrapped = preg_replace( '/' . get_shortcode_regex() . '/', '$5', (string) $content );
		if ( null === $unwrapped || $unwrapped === $content ) {
			break;
		}
		$content = $unwrapped;
	}

	return null === $content ? '' : (string) $content;
}

/**
 * Off-site, non-social links the visitor can see in the post's raw content (the_content filters not applied).
 */
function a8csp_cws_extract_article_links( $post, $max = 8 ) {
	$max = (int) $max;
	if ( $max < 1 || ! is_object( $post ) || empty( $post->post_content ) ) {
		return array();
	}

	if ( ! preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', a8csp_cws_get_visible_raw_content( $post ), $matches, PREG_SET_ORDER ) ) {
		return array();
	}

	$site_host = a8csp_cws_get_site_host();
	$links = array();
	$seen = array();

	foreach ( $matches as $match ) {
		$url = a8csp_cws_sanitize_http_url( a8csp_cws_get_link_href( $match[1] ) );
		if ( '' === $url ) {
			continue;
		}

		$host = a8csp_cws_strip_www( wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || $site_host === $host || a8csp_cws_is_social_host( $host ) ) {
			continue;
		}

		$text = html_entity_decode( wp_strip_all_tags( $match[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$collapsed = preg_replace( '/[\s\x{00A0}]+/u', ' ', $text );
		$text = trim( A8CSP_CWS_Utils::safe_substr( trim( null === $collapsed ? preg_replace( '/\s+/', ' ', $text ) : $collapsed ), 0, 80 ) );
		if ( '' === $text ) {
			continue;
		}

		$normalized = a8csp_cws_normalize_url( $url );
		if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
			continue;
		}
		$seen[ $normalized ] = true;

		$links[] = array(
			'url'  => $url,
			'text' => $text,
		);

		if ( count( $links ) >= $max ) {
			break;
		}
	}

	return $links;
}

function a8csp_cws_is_valid_date( $date ) {
	return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
}

/**
 * Prefilled Expedia flight search, or '' when the route, dates or travellers are invalid.
 */
function a8csp_cws_build_flight_search_url( $origin, $destination, $depart, $return = '', $adults = 1 ) {
	$origin = strtoupper( trim( (string) $origin ) );
	$destination = strtoupper( trim( (string) $destination ) );
	$depart = trim( (string) $depart );
	$return = trim( (string) $return );

	if ( ! preg_match( '/^[A-Z]{3}$/', $origin ) || ! preg_match( '/^[A-Z]{3}$/', $destination ) || $origin === $destination ) {
		return '';
	}

	if ( null === $adults || '' === $adults ) {
		$adults = 1;
	}
	if ( ! is_int( $adults ) && ! ( is_string( $adults ) && preg_match( '/^\d+$/', trim( $adults ) ) ) ) {
		return '';
	}
	$adults = (int) $adults;
	if ( $adults < 1 || $adults > 6 ) {
		return '';
	}

	// Expedia silently shows no results beyond 330 days.
	$today = wp_date( 'Y-m-d' );
	$latest = ( new DateTimeImmutable( $today, wp_timezone() ) )->modify( '+330 days' )->format( 'Y-m-d' );

	if ( ! a8csp_cws_is_valid_date( $depart ) || strcmp( $depart, $today ) < 0 || strcmp( $depart, $latest ) > 0 ) {
		return '';
	}
	if ( '' !== $return && ( ! a8csp_cws_is_valid_date( $return ) || strcmp( $return, $depart ) < 0 || strcmp( $return, $latest ) > 0 ) ) {
		return '';
	}

	$url = sprintf(
		'https://www.expedia.com/go/flight/search/%s/%s/%s?FromAirport=%s&ToAirport=%s&NumAdult=%d',
		'' === $return ? 'oneway' : 'roundtrip',
		$depart,
		'' === $return ? $depart : $return,
		$origin,
		$destination,
		$adults
	);

	$url = apply_filters(
		'a8csp_cws_flight_search_url',
		$url,
		array(
			'origin'      => $origin,
			'destination' => $destination,
			'depart'      => $depart,
			'return'      => $return,
			'adults'      => $adults,
		)
	);

	return is_string( $url ) ? a8csp_cws_sanitize_http_url( $url ) : '';
}

/**
 * Turns [label](flight:JFK-LIS/2027-05-12/2027-05-19/2) markers into search links, or into plain label text when off or invalid.
 */
function a8csp_cws_replace_flight_markers( $markdown, $enabled ) {
	$urls = array();

	$replaced = preg_replace_callback(
		'/\[([^\[\]]*)\]\(\s*flight:([^)]*)\)/i',
		function ( $match ) use ( $enabled, &$urls ) {
			if ( ! $enabled || ! preg_match( '/^([a-z]{3})\s*-\s*([a-z]{3})\s*\/\s*(\d{4}-\d{2}-\d{2})(?:\s*\/\s*(\d{4}-\d{2}-\d{2}))?(?:\s*\/\s*(\d))?$/i', trim( $match[2] ), $marker ) ) {
				return $match[1];
			}

			$url = a8csp_cws_build_flight_search_url( $marker[1], $marker[2], $marker[3], $marker[4] ?? '', $marker[5] ?? '' );
			if ( '' === $url ) {
				return $match[1];
			}

			$urls[] = $url;
			return '[' . $match[1] . '](' . $url . ')';
		},
		(string) $markdown
	);

	return array(
		'markdown' => null === $replaced ? (string) $markdown : $replaced,
		'urls'     => array_values( array_unique( $urls ) ),
	);
}

/**
 * Booking.com search for a hotel by name and city, or '' without a name.
 */
function a8csp_cws_build_hotel_search_url( $name, $city = '' ) {
	$name = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $name ) ) );
	$city = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/[^\p{L}\p{M}\s\'.-]/u', '', wp_strip_all_tags( (string) $city ) ) ) );
	$name = mb_substr( $name, 0, 120 );
	$city = mb_substr( $city, 0, 60 );
	if ( '' === $name ) {
		return '';
	}

	$query = ( '' === $city || preg_match( '/' . preg_quote( $city, '/' ) . '/iu', $name ) ) ? $name : $name . ', ' . $city;
	$url = apply_filters(
		'a8csp_cws_hotel_search_url',
		'https://www.booking.com/searchresults.html?ss=' . rawurlencode( $query ),
		array(
			'name' => $name,
			'city' => $city,
		)
	);

	return is_string( $url ) ? a8csp_cws_sanitize_http_url( $url ) : '';
}

function a8csp_cws_link_cache_key( $url ) {
	return 'a8csp_cws_link_' . md5( a8csp_cws_normalize_url( $url ) );
}

/**
 * The URL as written, with only the scheme and host lowercased and the fragment dropped.
 */
function a8csp_cws_link_spelling( $url ) {
	$url = explode( '#', (string) $url, 2 )[0];

	return preg_match( '#^([a-z][a-z0-9+.-]*://[^/?]*)(.*)$#is', $url, $match ) ? strtolower( $match[1] ) . $match[2] : $url;
}

/**
 * Cached verification result: the URL to link to ('' when it failed), or null when not checked recently.
 */
function a8csp_cws_get_cached_link_check( $url ) {
	$cached = get_transient( a8csp_cws_link_cache_key( $url ) );
	if ( ! is_array( $cached ) || ! isset( $cached['url'] ) || ! is_string( $cached['url'] ) ) {
		return null;
	}

	$spelling = a8csp_cws_link_spelling( $url );

	// The key ignores www and trailing slashes, but those spellings can be different servers or pages.
	if ( '' === $cached['url'] ) {
		return isset( $cached['checked'] ) && a8csp_cws_link_spelling( $cached['checked'] ) !== $spelling ? null : '';
	}

	return a8csp_cws_link_spelling( $cached['url'] ) === $spelling ? $url : $cached['url'];
}

/**
 * Allowance shared by the link checks of one answer: verifications, requests and an absolute deadline.
 */
function a8csp_cws_new_link_budget() {
	return array(
		'checks'   => 0,
		'requests' => 0,
		'deadline' => microtime( true ) + 10,
	);
}

function a8csp_cws_link_time_left( array $budget ) {
	return $budget['deadline'] - microtime( true );
}

/**
 * Whether an IPv4 address is publicly routable: not private, loopback, link-local, shared, reserved or multicast.
 */
function a8csp_cws_is_public_ipv4( $ip ) {
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return false;
	}

	$long = ip2long( $ip );
	foreach ( array( '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4' ) as $range ) {
		list( $subnet, $bits ) = explode( '/', $range );
		$mask = -1 << ( 32 - (int) $bits );
		if ( ( $long & $mask ) === ( ip2long( $subnet ) & $mask ) ) {
			return false;
		}
	}

	return true;
}

/**
 * The URL with an internationalized host converted to punycode, or '' when it can't be.
 */
function a8csp_cws_to_ascii_url( $url ) {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! preg_match( '/[\x80-\xff]/', $host ) ) {
		return $url;
	}

	try {
		$ascii = \WpOrg\Requests\IdnaEncoder::encode( $host );
	} catch ( Exception $e ) {
		return '';
	}

	$position = strpos( $url, $host );

	return false === $position ? '' : substr_replace( $url, $ascii, $position, strlen( $host ) );
}

/**
 * Whether requests go through cURL, the only transport whose connection can be pinned to a vetted address.
 */
function a8csp_cws_can_pin_requests( $scheme ) {
	return defined( 'CURLOPT_RESOLVE' ) && class_exists( '\WpOrg\Requests\Transport\Curl' ) && \WpOrg\Requests\Transport\Curl::test( array( \WpOrg\Requests\Capability::SSL => 'https' === $scheme ) );
}

/**
 * Where a link check may connect: the URL with an ASCII host and the public IPv4 address to pin it to, or null.
 */
function a8csp_cws_resolve_link_target( $url ) {
	$url = a8csp_cws_to_ascii_url( a8csp_cws_sanitize_http_url( $url ) );
	$parts = '' === $url ? false : wp_parse_url( $url );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return null;
	}

	$scheme = strtolower( $parts['scheme'] );
	$host = strtolower( $parts['host'] );
	$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
	if ( '.' === substr( $host, -1 ) || ! in_array( $port, array( 80, 443 ), true ) || ! a8csp_cws_can_pin_requests( $scheme ) ) {
		return null;
	}

	// Resolve before wp_http_validate_url(), whose own lookup then hits the resolver cache, so a dead domain blocks only once.
	$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
	if ( empty( $ips ) || ! wp_http_validate_url( $url ) ) {
		return null;
	}

	foreach ( $ips as $ip ) {
		if ( ! a8csp_cws_is_public_ipv4( $ip ) ) {
			return null;
		}
	}

	return array(
		'url'     => $url,
		'resolve' => $host . ':' . $port . ':' . $ips[0],
	);
}

/**
 * HEAD or GET that cURL must send to the vetted address, so a second DNS answer can't point it at an internal host.
 */
function a8csp_cws_pinned_request( $method, array $target, array $args ) {
	$pin = function ( $handle ) use ( $target ) {
		curl_setopt( $handle, CURLOPT_RESOLVE, array( $target['resolve'] ) );
		curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
	};

	add_action( 'http_api_curl', $pin, PHP_INT_MAX );
	try {
		return 'HEAD' === $method ? wp_safe_remote_head( $target['url'], $args ) : wp_safe_remote_get( $target['url'], $args );
	} finally {
		remove_action( 'http_api_curl', $pin, PHP_INT_MAX );
	}
}

/**
 * Response to a link check request, following up to 3 redirects itself so every hop is vetted, pinned and fits the budget.
 */
function a8csp_cws_fetch_link( $method, $url, array $args, array &$budget ) {
	$out_of_budget = new WP_Error( 'a8csp_cws_link_skipped', 'The link check budget is used up.' );

	for ( $hop = 0; $hop <= 3; $hop++ ) {
		if ( $budget['requests'] >= 24 || a8csp_cws_link_time_left( $budget ) < 1 ) {
			return $out_of_budget;
		}

		$target = a8csp_cws_resolve_link_target( $url );
		if ( null === $target ) {
			return new WP_Error( 0 === $hop ? 'a8csp_cws_link_unsafe' : 'a8csp_cws_link_failed', 'The URL is not a public web address.' );
		}

		// DNS lookups can't be given a timeout, so a slow one only shows up after it returns.
		$args['timeout'] = min( 4, a8csp_cws_link_time_left( $budget ) );
		if ( $args['timeout'] < 1 ) {
			return $out_of_budget;
		}
		$budget['requests']++;

		$response = a8csp_cws_pinned_request( $method, $target, $args );
		if ( is_wp_error( $response ) ) {
			// A timeout shortened by the deadline says nothing about the link.
			return $args['timeout'] < 4 && false !== stripos( $response->get_error_message(), 'timed out' ) ? $out_of_budget : $response;
		}

		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! in_array( (int) wp_remote_retrieve_response_code( $response ), array( 301, 302, 303, 307, 308 ), true ) || ! is_string( $location ) || '' === $location ) {
			return $response;
		}

		$url = WP_Http::make_absolute_url( $location, $url );
	}

	return new WP_Error( 'a8csp_cws_link_failed', 'Too many redirects.' );
}

function a8csp_cws_is_connection_error( $error ) {
	return is_wp_error( $error ) && (bool) preg_match( '/resolv|getaddrinfo|connect/i', $error->get_error_message() );
}

/**
 * Link check status for a failed request: 'skipped', 'unreachable', 'timeout' or 'failed'.
 */
function a8csp_cws_link_error_status( WP_Error $error ) {
	if ( 'a8csp_cws_link_skipped' === $error->get_error_code() ) {
		return 'skipped';
	}

	if ( 'a8csp_cws_link_unsafe' === $error->get_error_code() || a8csp_cws_is_connection_error( $error ) ) {
		return 'unreachable';
	}

	return false !== stripos( $error->get_error_message(), 'timed out' ) ? 'timeout' : 'failed';
}

/**
 * Whether a URL loads: 'verified', 'failed', 'timeout' (no response in time), 'unreachable' (not public, or no connection) or 'skipped' (out of budget).
 */
function a8csp_cws_check_link_loads( $url, array &$budget ) {
	$args = array(
		'redirection' => 0,
		'user-agent'  => (string) apply_filters( 'a8csp_cws_link_check_user_agent', 'Mozilla/5.0 (compatible; A8CSP-Site-Chatbot/' . A8CSP_CWS_VERSION . '; +' . home_url( '/' ) . ')', $url ),
	);

	$response = a8csp_cws_fetch_link( 'HEAD', $url, $args, $budget );
	if ( is_wp_error( $response ) ) {
		$status = a8csp_cws_link_error_status( $response );
		if ( 'unreachable' === $status || 'skipped' === $status ) {
			return $status;
		}
	} else {
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return 'verified';
		}
		// Servers that refuse HEAD or bot-like requests often still serve a GET.
		if ( ! in_array( $code, array( 400, 403, 405, 429, 501, 503 ), true ) ) {
			return 'failed';
		}
	}

	$args['limit_response_size'] = 4096;
	$response = a8csp_cws_fetch_link( 'GET', $url, $args, $budget );
	if ( is_wp_error( $response ) ) {
		return a8csp_cws_link_error_status( $response );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	return $code >= 200 && $code < 300 ? 'verified' : 'failed';
}

/**
 * Hosts shared by many places (social, maps, review, booking and hotel chain sites), whose home page says nothing about one place.
 */
function a8csp_cws_is_platform_host( $host ) {
	return a8csp_cws_is_social_host( $host ) || (bool) preg_match( '/(?:^|\.)(?:google|goo|apple|tripadvisor|yelp|booking|expedia|hotels|airbnb|vrbo|opentable|thefork|resy|sevenrooms|viator|getyourguide|michelin|marriott|hilton|hyatt|ihg|accor|linktr)(?:\.com?)?\.[a-z]{2,}$/', $host );
}

/**
 * The site's home page to try when a deep link fails, or '' when the URL is the home page or its host is a shared platform.
 */
function a8csp_cws_get_link_fallback_root( $url ) {
	$parts = wp_parse_url( $url );
	$path = $parts['path'] ?? '';
	if ( '' === $path || '/' === $path ) {
		return '';
	}

	if ( ! apply_filters( 'a8csp_cws_link_root_fallback', ! a8csp_cws_is_platform_host( a8csp_cws_strip_www( $parts['host'] ) ), $url ) ) {
		return '';
	}

	return strtolower( $parts['scheme'] ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . '/';
}

/**
 * The URL to link to, '' when neither it nor its site's home page loads, or null when the budget ran out first.
 */
function a8csp_cws_check_external_url( $url, array &$budget ) {
	$cached = a8csp_cws_get_cached_link_check( $url );
	if ( null !== $cached ) {
		return $cached;
	}

	$status = a8csp_cws_check_link_loads( $url, $budget );
	if ( 'skipped' === $status ) {
		return null;
	}

	// The original URL, not the redirect target, which can be localised to the server's location.
	$verified = 'verified' === $status ? $url : '';

	$root = 'failed' === $status || 'timeout' === $status ? a8csp_cws_get_link_fallback_root( $url ) : '';
	if ( '' !== $root ) {
		$verified = a8csp_cws_check_external_url( $root, $budget );
		if ( null === $verified ) {
			return null;
		}
	}

	if ( '' !== $verified ) {
		set_transient( a8csp_cws_link_cache_key( $url ), array( 'url' => $verified ), DAY_IN_SECONDS );
	} else {
		set_transient( a8csp_cws_link_cache_key( $url ), array( 'url' => '', 'checked' => $url ), 6 * HOUR_IN_SECONDS );
	}

	return $verified;
}

/**
 * The URL to link to when it loads (or its site's home page loads), otherwise ''.
 */
function a8csp_cws_verify_external_url( $url ) {
	$url = a8csp_cws_sanitize_http_url( $url );
	if ( '' === $url ) {
		return '';
	}

	$budget = a8csp_cws_new_link_budget();

	return (string) a8csp_cws_check_external_url( $url, $budget );
}

/**
 * Absolute http(s) URL for a link target; root-relative paths resolve against the site.
 */
function a8csp_cws_resolve_link_url( $href ) {
	if ( preg_match( '#^/(?![/\\\\])#', $href ) ) {
		$home = wp_parse_url( home_url() );
		if ( empty( $home['scheme'] ) || empty( $home['host'] ) ) {
			return '';
		}
		$href = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' ) . $href;
	} elseif ( a8csp_cws_is_schemeless_web_link( $href ) ) {
		$href = 'https://' . $href;
	}

	return a8csp_cws_sanitize_http_url( $href );
}

/**
 * Whether an href is a domain name without a scheme ("example.com/page"), which models sometimes write.
 */
function a8csp_cws_is_schemeless_web_link( $href ) {
	return (bool) preg_match( '#^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?!(?:html?|php|aspx?|jsp|pdf)(?:[/?\#]|$))[a-z]{2,63}(?:[/?\#]|$)#i', (string) $href );
}

function a8csp_cws_is_public_post_url( $url ) {
	$post_id = url_to_postid( $url );
	if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
		return false;
	}

	$post_type = get_post_type_object( get_post_type( $post_id ) );

	return $post_type && $post_type->public;
}

/**
 * One link under the link policy: re-rendered with our attributes, or reduced to its inner HTML.
 */
function a8csp_cws_apply_link_rule( $attributes, $inner, array $allowed, array $settings, array &$budget ) {
	$inner = (string) a8csp_cws_strip_anchor_tags( $inner );

	$href = a8csp_cws_get_link_href( $attributes );
	if ( '' === $href ) {
		return a8csp_cws_apply_hotel_search_fallback( $attributes, $inner, $settings );
	}

	if ( preg_match( '/^(mailto|tel):/i', $href ) ) {
		$href = esc_url( $href, array( 'mailto', 'tel' ) );
		return '' === $href ? $inner : '<a href="' . $href . '">' . $inner . '</a>';
	}

	$url = a8csp_cws_resolve_link_url( $href );
	if ( '' === $url ) {
		return a8csp_cws_apply_hotel_search_fallback( $attributes, $inner, $settings );
	}

	$normalized = a8csp_cws_normalize_url( $url );
	$is_site_host = a8csp_cws_strip_www( wp_parse_url( $url, PHP_URL_HOST ) ) === a8csp_cws_get_site_host();

	if ( a8csp_cws_is_schemeless_web_link( $href ) ) {
		$home_scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		if ( $is_site_host && in_array( $home_scheme, array( 'http', 'https' ), true ) ) {
			$url = set_url_scheme( $url, $home_scheme );
		} elseif ( ! isset( $allowed[ $normalized ] ) && isset( $allowed[ a8csp_cws_normalize_url( set_url_scheme( $url, 'http' ) ) ] ) ) {
			// Older articles often link with http://.
			$url = set_url_scheme( $url, 'http' );
		}
		$normalized = a8csp_cws_normalize_url( $url );
	}

	if ( $is_site_host ) {
		// A query on the home page (?p=, ?page_id=) can address any post, so only the bare home page is always kept.
		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$is_home = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) === $home_path && null === wp_parse_url( $url, PHP_URL_QUERY );
		if ( isset( $allowed[ $normalized ] ) || $is_home || a8csp_cws_is_public_post_url( $url ) ) {
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $inner . '</a>';
		}
		return $inner;
	}

	$final = '';
	if ( isset( $allowed[ $normalized ] ) ) {
		// The context's spelling: a www or trailing-slash variant of it may not exist.
		$final = $allowed[ $normalized ];
		$fragment = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
		if ( '' !== $fragment ) {
			$final = explode( '#', $final, 2 )[0] . '#' . $fragment;
		}
	} elseif ( ! empty( $settings['recommendations'] ) ) {
		$final = a8csp_cws_get_cached_link_check( $url );
		if ( null === $final && $budget['checks'] < 8 && a8csp_cws_link_time_left( $budget ) >= 1 ) {
			$budget['checks']++;
			$final = a8csp_cws_check_external_url( $url, $budget );
		}
		$final = (string) $final;
	}

	if ( '' === $final ) {
		return a8csp_cws_apply_hotel_search_fallback( $attributes, $inner, $settings );
	}

	return a8csp_cws_render_external_link( $final, $inner, $settings );
}

/**
 * A hotel search link for a link titled "hotel, City" whose own URL can't be shown, or the link's inner HTML.
 */
function a8csp_cws_apply_hotel_search_fallback( $attributes, $inner, array $settings ) {
	if ( empty( $settings['hotel_links'] ) || ! preg_match( '/^hotel\b\s*(?:[,:-]\s*|in\s+)?(.*)$/isu', a8csp_cws_get_link_attribute( $attributes, 'title' ), $title ) ) {
		return $inner;
	}

	$url = a8csp_cws_build_hotel_search_url( html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $title[1] );

	return '' === $url ? $inner : a8csp_cws_render_external_link( $url, $inner, $settings );
}

/**
 * An external link with our attributes, through the Skimlinks link wrapper when a site ID is set.
 */
function a8csp_cws_render_external_link( $final, $inner, array $settings ) {
	$label = a8csp_cws_sanitize_tracking_label( $settings['tracking_label'] ?? '' );
	$site_id = a8csp_cws_sanitize_skimlinks_site_id( $settings['skimlinks_id'] ?? '' );
	if ( '' !== $site_id ) {
		// The Skimlinks script only affiliates links present when the page loads, so answer links use its link wrapper.
		$href = 'https://go.skimresources.com/?id=' . rawurlencode( $site_id ) . '&url=' . rawurlencode( $final ) . ( '' !== $label ? '&xcust=' . rawurlencode( $label ) : '' );
		$tracking = '';
	} else {
		$href = $final;
		$tracking = '' !== $label ? ' data-skimlinks-tracking="' . esc_attr( $label ) . '"' : '';
	}

	return '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener nofollow"' . $tracking . '>' . $inner . '</a>';
}

/**
 * Keeps only links the answer may show: context URLs, published internal pages, and verified external pages.
 */
function a8csp_cws_apply_link_policy( $html, array $allowed_urls, array $settings ) {
	$html = str_replace( "\x1A", '', (string) $html );

	$allowed = array();
	foreach ( $allowed_urls as $allowed_url ) {
		$allowed_url = is_string( $allowed_url ) ? a8csp_cws_sanitize_http_url( $allowed_url ) : '';
		$normalized = a8csp_cws_normalize_url( $allowed_url );
		if ( '' !== $normalized && ! isset( $allowed[ $normalized ] ) ) {
			$allowed[ $normalized ] = $allowed_url;
		}
	}

	$budget = a8csp_cws_new_link_budget();
	$rendered = array();

	$result = preg_replace_callback(
		'/<a\b([^>]*)>(.*?)<\/a>/is',
		function ( $match ) use ( $allowed, $settings, &$budget, &$rendered ) {
			$rendered[] = a8csp_cws_apply_link_rule( $match[1], $match[2], $allowed, $settings, $budget );
			return "\x1A" . ( count( $rendered ) - 1 ) . "\x1A";
		},
		$html
	);

	// Unclosed or stray anchor tags never pass through unchecked.
	if ( null !== $result ) {
		$result = a8csp_cws_strip_anchor_tags( $result );
	}

	if ( null !== $result ) {
		$result = preg_replace_callback(
			'/\x1A(\d+)\x1A/',
			function ( $match ) use ( $rendered ) {
				return $rendered[ (int) $match[1] ] ?? '';
			},
			$result
		);
	}

	if ( null === $result ) {
		error_log( 'A8CSP: Link policy failed to parse the answer, removing all links' );
		$result = strip_tags( $html, '<p><br><strong><b><em><i><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre>' );
	}

	return apply_filters( 'a8csp_cws_answer_html', $result, $settings );
}
