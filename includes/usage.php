<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function a8csp_cws_is_usage_day( $day ) {
	$day = (string) $day;

	return 1 === preg_match( '/^\d{8}$/D', $day ) && checkdate( (int) substr( $day, 4, 2 ), (int) substr( $day, 6, 2 ), (int) substr( $day, 0, 4 ) );
}

/**
 * The Ymd day in a daily count option's name, or '' when the name is malformed.
 */
function a8csp_cws_usage_day_from_option( $option ) {
	$day = substr( (string) $option, strlen( 'a8csp_cws_chat_count_' ) );

	return a8csp_cws_is_usage_day( $day ) ? $day : '';
}

/**
 * The Ymd key of a day counted back from today, in the site timezone.
 */
function a8csp_cws_usage_day_key( $days_ago ) {
	// Noon exists on every date, so stepping back can't land on an hour DST skips.
	$noon = current_datetime()->setTime( 12, 0 )->modify( '-' . (int) $days_ago . ' days' );

	return wp_date( 'Ymd', $noon->getTimestamp() );
}

function a8csp_cws_format_usage_day( $day ) {
	$noon = DateTimeImmutable::createFromFormat( 'Ymd H:i', $day . ' 12:00', wp_timezone() );

	return wp_date( get_option( 'date_format' ), $noon->getTimestamp() );
}

/**
 * Daily count rows still in the options table, as option name => stored count.
 */
function a8csp_cws_get_usage_rows() {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'a8csp_cws_chat_count_' ) . '%' ) );

	return wp_list_pluck( (array) $rows, 'option_value', 'option_name' );
}

/**
 * A stored value of the rolled-up usage option; a corrupt value reads as empty and bad entries are dropped.
 */
function a8csp_cws_parse_rolled_up_usage( $stored ) {
	$usage = array(
		'days'  => array(),
		'total' => 0,
		'since' => '',
	);

	if ( ! is_array( $stored ) || ! isset( $stored['days'], $stored['total'] ) || ! is_array( $stored['days'] ) || ! is_numeric( $stored['total'] ) ) {
		return $usage;
	}

	foreach ( $stored['days'] as $day => $count ) {
		if ( a8csp_cws_is_usage_day( $day ) && is_numeric( $count ) && $count > 0 ) {
			$usage['days'][ $day ] = (int) $count;
		}
	}

	$usage['total'] = max( 0, (int) $stored['total'] );

	if ( isset( $stored['since'] ) && is_scalar( $stored['since'] ) && a8csp_cws_is_usage_day( $stored['since'] ) ) {
		$usage['since'] = (string) $stored['since'];
	}

	return $usage;
}

/**
 * Add one daily count row to a usage array; malformed names and empty counts add nothing.
 */
function a8csp_cws_add_usage_row( $usage, $option, $value ) {
	$day = a8csp_cws_usage_day_from_option( $option );
	$count = max( 0, (int) $value );

	if ( '' === $day || 0 === $count ) {
		return $usage;
	}

	$usage['days'][ $day ] = ( $usage['days'][ $day ] ?? 0 ) + $count;
	$usage['total'] += $count;

	if ( '' === $usage['since'] || $day < $usage['since'] ) {
		$usage['since'] = $day;
	}

	return $usage;
}

/**
 * Move finished days from their count rows into the usage option, then delete those rows.
 */
function a8csp_cws_rollup_usage( $today_option ) {
	global $wpdb;

	// Not get_option(): a stale object cache or a failed read would overwrite all history below.
	$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'a8csp_cws_chat_usage' ) );
	if ( '' !== $wpdb->last_error ) {
		return;
	}

	$before = a8csp_cws_parse_rolled_up_usage( maybe_unserialize( $stored ) );
	$usage = $before;
	$finished = array();

	foreach ( a8csp_cws_get_usage_rows() as $option => $value ) {
		if ( $option === $today_option ) {
			continue;
		}

		$usage = a8csp_cws_add_usage_row( $usage, $option, $value );
		$finished[] = $option;
	}

	if ( $usage['total'] > $before['total'] ) {
		$oldest_kept = a8csp_cws_usage_day_key( 30 );

		foreach ( array_keys( $usage['days'] ) as $day ) {
			if ( $day < $oldest_kept ) {
				unset( $usage['days'][ $day ] );
			}
		}

		// Rows stay when the write fails, so the next rollup can retry them.
		if ( ! update_option( 'a8csp_cws_chat_usage', $usage, false ) ) {
			return;
		}
	}

	foreach ( $finished as $option ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
	}
}

/**
 * Figures for the Usage page. Reads the count rows too, because a day isn't rolled up until the next day's first message.
 */
function a8csp_cws_get_usage_stats() {
	$usage = a8csp_cws_parse_rolled_up_usage( get_option( 'a8csp_cws_chat_usage' ) );

	foreach ( a8csp_cws_get_usage_rows() as $option => $value ) {
		$usage = a8csp_cws_add_usage_row( $usage, $option, $value );
	}

	$stats = array(
		'days'    => array(),
		'last_7'  => 0,
		'last_30' => 0,
		'total'   => $usage['total'],
		'since'   => $usage['since'],
	);

	for ( $days_ago = 0; $days_ago < 30; $days_ago++ ) {
		$day = a8csp_cws_usage_day_key( $days_ago );
		$count = $usage['days'][ $day ] ?? 0;

		$stats['days'][ $day ] = $count;
		$stats['last_30'] += $count;
		$stats['last_7'] += $days_ago < 7 ? $count : 0;
	}

	return $stats;
}

function a8csp_cws_usage_today_summary( $count, $limit ) {
	$today = number_format_i18n( $count );

	if ( $limit <= 0 ) {
		return sprintf( __( '%s (no daily limit)', 'a8csp-site-chatbot' ), $today );
	}

	$summary = sprintf( __( '%1$s of %2$s', 'a8csp-site-chatbot' ), $today, number_format_i18n( $limit ) );

	return $count >= $limit ? sprintf( __( '%s (limit reached)', 'a8csp-site-chatbot' ), $summary ) : $summary;
}

function a8csp_cws_usage_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'a8csp-site-chatbot' ), 403 );
	}

	$limits = a8csp_cws_get_daily_limits();
	$stats = a8csp_cws_get_usage_stats();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Usage', 'a8csp-site-chatbot' ); ?></h1>
		<p><?php esc_html_e( 'Counts messages that passed the limits, even if the AI service then failed to answer. Only counts are stored, never message text or who sent them. Days follow the site timezone.', 'a8csp-site-chatbot' ); ?></p>

		<dl class="a8csp-usage-summary">
			<div>
				<dt><?php esc_html_e( 'Today', 'a8csp-site-chatbot' ); ?></dt>
				<dd><?php echo esc_html( a8csp_cws_usage_today_summary( a8csp_cws_get_site_message_count(), $limits['site'] ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Last 7 days', 'a8csp-site-chatbot' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $stats['last_7'] ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Last 30 days', 'a8csp-site-chatbot' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $stats['last_30'] ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'All time', 'a8csp-site-chatbot' ); ?></dt>
				<dd>
					<?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?>
					<?php if ( '' !== $stats['since'] ) : ?>
						<?php echo esc_html( sprintf( __( 'since %s', 'a8csp-site-chatbot' ), a8csp_cws_format_usage_day( $stats['since'] ) ) ); ?>
					<?php endif; ?>
				</dd>
			</div>
		</dl>

		<?php if ( $stats['total'] > 0 ) : ?>
			<table class="widefat striped a8csp-usage-days">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'a8csp-site-chatbot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Messages', 'a8csp-site-chatbot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stats['days'] as $day => $count ) : ?>
						<tr>
							<td><?php echo esc_html( a8csp_cws_format_usage_day( $day ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No messages have been counted yet. Daily counts will appear here once visitors start chatting.', 'a8csp-site-chatbot' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}
