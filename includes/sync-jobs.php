<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

define( 'A8CSP_CWS_SYNC_JOB_OPTION', 'a8csp_cws_sync_job' );
define( 'A8CSP_CWS_SYNC_JOB_HOOK', 'a8csp_cws_sync_job_batch' );
define( 'A8CSP_CWS_SYNC_JOB_GROUP', 'a8csp-site-chatbot' );
define( 'A8CSP_CWS_SYNC_JOB_LOCK_OPTION', 'a8csp_cws_sync_job_lock' );
define( 'A8CSP_CWS_SYNC_JOB_CANCEL_OPTION', 'a8csp_cws_sync_job_cancelled' );
define( 'A8CSP_CWS_SYNC_JOB_IDS_OPTION_PREFIX', 'a8csp_cws_sync_job_ids_' );
define( 'A8CSP_CWS_SYNC_JOB_IDS_PER_OPTION', 1000 );
// Longer than the slowest plausible batch, so a live runner's lock is never taken over.
define( 'A8CSP_CWS_SYNC_JOB_LOCK_TTL', 900 );
define( 'A8CSP_CWS_SYNC_JOB_MAX_CONSECUTIVE_ERRORS', 12 );
define( 'A8CSP_CWS_SYNC_JOB_MAX_FAILURES', 500 );

add_action( A8CSP_CWS_SYNC_JOB_HOOK, 'a8csp_cws_sync_job_process', 10, 1 );
// Lets queue runs (WP-Cron, async runner, WP-CLI) revive a stalled job even when nobody has the admin page open.
add_action( 'action_scheduler_before_process_queue', 'a8csp_cws_sync_job_ensure_running', 10, 0 );

/**
 * Whether Action Scheduler is loaded and its data store is ready.
 */
function a8csp_cws_sync_jobs_available() {
	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		return false;
	}

	if ( did_action( 'action_scheduler_init' ) ) {
		return true;
	}

	return class_exists( 'ActionScheduler', false ) && ActionScheduler::is_initialized();
}

function a8csp_cws_sync_job_defaults() {
	return array(
		'id' => '',
		'status' => 'queued',
		'filters' => array(
			'post_type' => 'post',
			'category' => 0,
			'tag' => 0,
		),
		'skip_synced' => true,
		'fingerprint' => array(),
		'settings_fingerprint' => array(),
		'cursor' => 0,
		'requeue' => array(),
		'total' => 0,
		'synced' => 0,
		'skipped' => 0,
		'failed' => 0,
		'failures' => array(),
		'skipped_at_start' => 0,
		'consecutive_errors' => 0,
		'last_error' => '',
		'last_error_code' => '',
		'next_run_at' => 0,
		'started_by' => 0,
		'created_at' => 0,
		'updated_at' => 0,
		'finished_at' => 0,
		'in_flight' => array(),
		'isolate' => 0,
		'batch_cap' => 0,
		'retry_of' => '',
	);
}

/**
 * Validate filters like the Content Library page; an empty post_type means it is not a public post type.
 */
function a8csp_cws_sync_job_normalize_filters( array $filters ) {
	$post_type = isset( $filters['post_type'] ) ? sanitize_key( $filters['post_type'] ) : 'post';
	if ( ! in_array( $post_type, get_post_types( array( 'public' => true ), 'names' ), true ) ) {
		$post_type = '';
	}

	$category = isset( $filters['category'] ) ? max( 0, (int) $filters['category'] ) : 0;
	$tag = isset( $filters['tag'] ) ? max( 0, (int) $filters['tag'] ) : 0;

	if ( 'post' !== $post_type ) {
		$category = 0;
		$tag = 0;
	}

	return array(
		'post_type' => $post_type,
		'category' => $category,
		'tag' => $tag,
	);
}

/**
 * Collect the IDs of published posts matching the filters, newest first.
 */
function a8csp_cws_sync_job_query_post_ids( array $filters, $skip_synced ) {
	$filters = a8csp_cws_sync_job_normalize_filters( $filters );
	$result = array(
		'ids' => array(),
		'skipped' => 0,
	);

	if ( '' === $filters['post_type'] ) {
		return $result;
	}

	$args = array(
		'post_type' => $filters['post_type'],
		'post_status' => 'publish',
		'has_password' => false,
		'fields' => 'ids',
		'posts_per_page' => -1,
		'no_found_rows' => true,
		'orderby' => 'date',
		'order' => 'DESC',
		'ignore_sticky_posts' => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	if ( 'post' === $filters['post_type'] ) {
		if ( $filters['category'] > 0 ) {
			$args['cat'] = $filters['category'];
		}
		if ( $filters['tag'] > 0 ) {
			$args['tag_id'] = $filters['tag'];
		}
	}

	$query = new WP_Query( $args );
	$ids = array_values( array_unique( array_map( 'intval', $query->posts ) ) );

	if ( ! $skip_synced || empty( $ids ) ) {
		$result['ids'] = $ids;
		return $result;
	}

	$current_fp = a8csp_cws_current_sync_fingerprint();
	// Priming meta for every post at once could exhaust memory on large sites.
	$release_meta = ! wp_using_ext_object_cache();

	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		update_meta_cache( 'post', $chunk );
		foreach ( $chunk as $post_id ) {
			if ( 'synced' === a8csp_cws_get_sync_state( $post_id, $current_fp ) ) {
				$result['skipped']++;
			} else {
				$result['ids'][] = $post_id;
			}
			if ( $release_meta ) {
				wp_cache_delete( $post_id, 'post_meta' );
			}
		}
	}

	return $result;
}

/**
 * Read an option straight from the database so concurrent runners never act on a cached copy.
 */
function a8csp_cws_sync_job_read_option( $option ) {
	global $wpdb;

	wp_cache_delete( $option, 'options' );
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );

	return null === $value ? null : maybe_unserialize( $value );
}

/**
 * Fingerprint of the saved settings row; a long-running process keeps the constants it booted with.
 */
function a8csp_cws_sync_job_settings_fingerprint() {
	$settings = a8csp_cws_sync_job_read_option( 'a8csp_chat_with_site_options' );
	return a8csp_cws_current_sync_fingerprint( is_array( $settings ) ? $settings : array() );
}

/**
 * Write a non-autoloaded option, bypassing any stale cached copy.
 */
function a8csp_cws_sync_job_write_option( $option, $value ) {
	wp_cache_delete( $option, 'options' );
	return update_option( $option, $value, false );
}

function a8csp_cws_sync_job_ids_option( $job_id, $index ) {
	return A8CSP_CWS_SYNC_JOB_IDS_OPTION_PREFIX . $job_id . '_' . (int) $index;
}

/**
 * Store a job's post IDs in chunks of non-autoloaded options, so a batch never loads or rewrites the whole list.
 */
function a8csp_cws_sync_job_store_ids( $job_id, array $ids ) {
	global $wpdb;

	foreach ( array_chunk( $ids, A8CSP_CWS_SYNC_JOB_IDS_PER_OPTION ) as $index => $chunk ) {
		$stored = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", a8csp_cws_sync_job_ids_option( $job_id, $index ), implode( ',', $chunk ) ) );
		if ( ! $stored ) {
			error_log( 'A8CSP: Could not store the sync job post IDs: ' . $wpdb->last_error );
			a8csp_cws_sync_job_delete_ids( $job_id );
			return false;
		}
	}

	return true;
}

/**
 * Up to $length of a job's post IDs starting at $offset (never past the end of one stored chunk), or null when they are missing.
 */
function a8csp_cws_sync_job_load_ids( $job_id, $offset, $length ) {
	$per_option = A8CSP_CWS_SYNC_JOB_IDS_PER_OPTION;
	$value = a8csp_cws_sync_job_read_option( a8csp_cws_sync_job_ids_option( $job_id, (int) floor( $offset / $per_option ) ) );
	if ( ! is_string( $value ) || '' === $value ) {
		return null;
	}

	$ids = array_slice( array_map( 'intval', explode( ',', $value ) ), $offset % $per_option, $length );
	return empty( $ids ) ? null : $ids;
}

/**
 * Delete the stored post IDs of one job, or of every job when $job_id is empty.
 */
function a8csp_cws_sync_job_delete_ids( $job_id = '' ) {
	global $wpdb;

	$prefix = A8CSP_CWS_SYNC_JOB_IDS_OPTION_PREFIX . ( '' !== (string) $job_id ? $job_id . '_' : '' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
}

/**
 * Current job (read fresh), or null when there is none.
 */
function a8csp_cws_sync_job_get() {
	$job = a8csp_cws_sync_job_read_option( A8CSP_CWS_SYNC_JOB_OPTION );
	if ( ! is_array( $job ) || empty( $job['id'] ) ) {
		return null;
	}

	return array_merge( a8csp_cws_sync_job_defaults(), $job );
}

/**
 * Save the job and return it, or a WP_Error when the database write failed.
 */
function a8csp_cws_sync_job_save( array $job ) {
	global $wpdb;

	$job['updated_at'] = time();
	$active = a8csp_cws_sync_job_is_active( $job );
	if ( ! $active ) {
		$job['requeue'] = array();
	}

	// update_option() also returns false when the value did not change.
	if ( ! a8csp_cws_sync_job_write_option( A8CSP_CWS_SYNC_JOB_OPTION, $job ) ) {
		$db_error = $wpdb->last_error;
		if ( maybe_serialize( a8csp_cws_sync_job_read_option( A8CSP_CWS_SYNC_JOB_OPTION ) ) !== maybe_serialize( $job ) ) {
			error_log( 'A8CSP: Could not save the sync job: ' . ( '' !== $db_error ? $db_error : 'unknown database error' ) );
			$message = 'Could not save the sync progress to the database.';
			if ( '' !== $db_error ) {
				$message .= ' Database error: ' . a8csp_cws_clean_error_text( $db_error );
			}
			return new WP_Error( 'a8csp_job_save_failed', $message );
		}
	}

	if ( ! $active ) {
		a8csp_cws_sync_job_delete_ids( $job['id'] );
	}

	return $job;
}

function a8csp_cws_sync_job_is_active( $job ) {
	return is_array( $job ) && isset( $job['status'] ) && in_array( $job['status'], array( 'queued', 'running', 'waiting' ), true );
}

function a8csp_cws_sync_job_cancel_requested( $job_id ) {
	$flag = a8csp_cws_sync_job_read_option( A8CSP_CWS_SYNC_JOB_CANCEL_OPTION );
	return '' !== (string) $job_id && is_string( $flag ) && $flag === (string) $job_id;
}

/**
 * Take the runner lock via INSERT IGNORE / compare-and-swap (add_option() is not atomic). Returns a token or false.
 */
function a8csp_cws_sync_job_acquire_lock() {
	global $wpdb;

	$token = time() . ':' . wp_generate_uuid4();
	$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", A8CSP_CWS_SYNC_JOB_LOCK_OPTION, $token ) );
	if ( $inserted ) {
		wp_cache_delete( A8CSP_CWS_SYNC_JOB_LOCK_OPTION, 'options' );
		return $token;
	}

	$current = a8csp_cws_sync_job_read_option( A8CSP_CWS_SYNC_JOB_LOCK_OPTION );
	if ( ! is_string( $current ) || '' === $current || a8csp_cws_sync_job_lock_value_is_fresh( $current ) ) {
		return false;
	}

	$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, A8CSP_CWS_SYNC_JOB_LOCK_OPTION, $current ) );
	wp_cache_delete( A8CSP_CWS_SYNC_JOB_LOCK_OPTION, 'options' );

	return $taken ? $token : false;
}

function a8csp_cws_sync_job_release_lock( $token ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", A8CSP_CWS_SYNC_JOB_LOCK_OPTION, $token ) );
	wp_cache_delete( A8CSP_CWS_SYNC_JOB_LOCK_OPTION, 'options' );
}

function a8csp_cws_sync_job_owns_lock( $token ) {
	return a8csp_cws_sync_job_read_option( A8CSP_CWS_SYNC_JOB_LOCK_OPTION ) === $token;
}

function a8csp_cws_sync_job_lock_value_is_fresh( $value ) {
	return is_string( $value ) && '' !== $value && time() - (int) strtok( $value, ':' ) < A8CSP_CWS_SYNC_JOB_LOCK_TTL;
}

function a8csp_cws_sync_job_lock_is_fresh() {
	return a8csp_cws_sync_job_lock_value_is_fresh( a8csp_cws_sync_job_read_option( A8CSP_CWS_SYNC_JOB_LOCK_OPTION ) );
}

/**
 * Queue the next batch for a job: 0 = as soon as possible, otherwise after $delay seconds.
 */
function a8csp_cws_sync_job_schedule( $job_id, $delay ) {
	try {
		if ( $delay > 0 ) {
			$action_id = as_schedule_single_action( time() + (int) $delay, A8CSP_CWS_SYNC_JOB_HOOK, array( (string) $job_id ), A8CSP_CWS_SYNC_JOB_GROUP );
		} else {
			$action_id = as_enqueue_async_action( A8CSP_CWS_SYNC_JOB_HOOK, array( (string) $job_id ), A8CSP_CWS_SYNC_JOB_GROUP );
		}
	} catch ( Throwable $e ) {
		error_log( 'A8CSP: Could not schedule sync job batch: ' . $e->getMessage() );
		return 0;
	}

	if ( ! $action_id ) {
		error_log( 'A8CSP: Action Scheduler did not accept the sync job batch' );
	}

	return (int) $action_id;
}

/**
 * Whether the job has a batch waiting to run (not counting one that is running now).
 */
function a8csp_cws_sync_job_has_pending_action( $job_id ) {
	try {
		$pending = as_get_scheduled_actions(
			array(
				'hook' => A8CSP_CWS_SYNC_JOB_HOOK,
				'args' => array( (string) $job_id ),
				'group' => A8CSP_CWS_SYNC_JOB_GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby' => 'none',
			),
			'ids'
		);
	} catch ( Throwable $e ) {
		return true;
	}

	return ! empty( $pending );
}

/**
 * Cancel pending (not in-progress) batches for a job.
 */
function a8csp_cws_sync_job_unschedule( $job_id ) {
	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		return;
	}

	try {
		as_unschedule_all_actions( A8CSP_CWS_SYNC_JOB_HOOK, array( (string) $job_id ), A8CSP_CWS_SYNC_JOB_GROUP );
	} catch ( Throwable $e ) {
		error_log( 'A8CSP: Could not unschedule sync job batches: ' . $e->getMessage() );
	}
}

/**
 * Seconds to wait before retrying after a retryable batch error.
 */
function a8csp_cws_sync_retry_delay( $error, $consecutive_errors ) {
	$data = is_wp_error( $error ) ? $error->get_error_data() : null;
	$retry_after = ( is_array( $data ) && ! empty( $data['retry_after'] ) ) ? (int) $data['retry_after'] : 0;

	if ( is_wp_error( $error ) && 'a8csp_quota_exhausted' === $error->get_error_code() ) {
		return max( 30, $retry_after > 0 ? $retry_after : 3600 );
	}

	if ( $retry_after <= 0 ) {
		$retry_after = (int) min( 900, 60 * pow( 2, max( 0, min( 10, (int) $consecutive_errors - 1 ) ) ) );
	}

	return max( 30, min( 900, $retry_after ) );
}

/**
 * Make an error message safe to store and show in the admin.
 */
function a8csp_cws_sync_job_clean_message( $message ) {
	return a8csp_cws_clean_error_text( (string) $message, 300 );
}

function a8csp_cws_sync_job_add_failure( array $job, $post_id, $message ) {
	$job['failed']++;
	if ( count( $job['failures'] ) < A8CSP_CWS_SYNC_JOB_MAX_FAILURES ) {
		$job['failures'][ (int) $post_id ] = a8csp_cws_sync_job_clean_message( $message );
	}
	return $job;
}

function a8csp_cws_sync_job_mark_failed( array $job, $message, $code ) {
	$job['status'] = 'failed';
	$job['last_error'] = a8csp_cws_sync_job_clean_message( $message );
	$job['last_error_code'] = (string) $code;
	$job['next_run_at'] = 0;
	$job['in_flight'] = array();
	$job['finished_at'] = time();
	return $job;
}

function a8csp_cws_sync_job_mark_cancelled( array $job ) {
	$job['status'] = 'cancelled';
	$job['next_run_at'] = 0;
	$job['in_flight'] = array();
	if ( empty( $job['finished_at'] ) ) {
		$job['finished_at'] = time();
	}
	return $job;
}

/**
 * Record a retryable batch error; the job fails when it reaches the consecutive error limit.
 */
function a8csp_cws_sync_job_count_error( array $job, $message, $code ) {
	$job['consecutive_errors']++;
	$job['last_error'] = a8csp_cws_sync_job_clean_message( $message );
	$job['last_error_code'] = (string) $code;

	if ( $job['consecutive_errors'] >= A8CSP_CWS_SYNC_JOB_MAX_CONSECUTIVE_ERRORS ) {
		$job = a8csp_cws_sync_job_mark_failed( $job, sprintf( 'Stopped after %d consecutive errors. Last error: %s', A8CSP_CWS_SYNC_JOB_MAX_CONSECUTIVE_ERRORS, $message ), $code );
	}

	return $job;
}

/**
 * Requeue a batch that stopped unexpectedly, one post at a time, so a single post that breaks PHP can't stall the job.
 */
function a8csp_cws_sync_job_recover_in_flight( array $job, $job_message, $code, $post_message ) {
	$ids = array_values( array_filter( array_map( 'intval', $job['in_flight'] ) ) );
	$job['in_flight'] = array();

	if ( 1 === count( $ids ) ) {
		$job = a8csp_cws_sync_job_add_failure( $job, $ids[0], $post_message );
		$job['isolate'] = max( 0, $job['isolate'] - 1 );
	} elseif ( count( $ids ) > 1 ) {
		$job['requeue'] = array_merge( $ids, $job['requeue'] );
		$job['isolate'] = max( $job['isolate'], count( $ids ) );
	}

	return a8csp_cws_sync_job_count_error( $job, $job_message, $code );
}

/**
 * Stop a job whose progress could not be saved, so its batches don't re-run from stale saved state.
 */
function a8csp_cws_sync_job_halt( array $job, WP_Error $error ) {
	$job = a8csp_cws_sync_job_mark_failed( $job, $error->get_error_message(), $error->get_error_code() );
	if ( is_wp_error( a8csp_cws_sync_job_save( $job ) ) ) {
		// The small cancel flag stops process() and the watchdog even when the job itself can't be written.
		a8csp_cws_sync_job_write_option( A8CSP_CWS_SYNC_JOB_CANCEL_OPTION, $job['id'] );
	}
}

function a8csp_cws_sync_job_has_remaining_work( array $job ) {
	return ! empty( $job['requeue'] ) || $job['cursor'] < $job['total'];
}

/**
 * Start a background sync of every post matching $filters, or exactly $post_ids when given.
 */
function a8csp_cws_sync_job_start( array $filters, $skip_synced, $user_id = 0, ?array $post_ids = null, $retry_of = '' ) {
	if ( ! a8csp_cws_sync_jobs_available() ) {
		return new WP_Error( 'a8csp_jobs_unavailable', "Background sync is unavailable because Action Scheduler isn't loaded. Run composer install in the plugin folder." );
	}

	$missing = a8csp_cws_check_required_settings( a8csp_cws_get_api_settings() );
	if ( ! empty( $missing ) ) {
		return new WP_Error( 'a8csp_config_missing', 'Required settings are missing: ' . implode( '; ', $missing ) . '.' );
	}

	if ( a8csp_cws_sync_job_is_active( a8csp_cws_sync_job_get() ) ) {
		return new WP_Error( 'a8csp_job_active', 'A sync is already in progress. Wait for it to finish or cancel it first.' );
	}

	$filters = a8csp_cws_sync_job_normalize_filters( $filters );
	$skip_synced = (bool) $skip_synced;
	if ( null === $post_ids && '' === $filters['post_type'] ) {
		return new WP_Error( 'a8csp_invalid_filters', 'Choose a public post type to sync.' );
	}

	$skipped_at_start = 0;
	if ( null === $post_ids ) {
		$query = a8csp_cws_sync_job_query_post_ids( $filters, $skip_synced );
		$ids = $query['ids'];
		$skipped_at_start = $query['skipped'];
	} else {
		$ids = array();
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id > 0 ) {
				$ids[ $post_id ] = $post_id;
			}
		}
		$ids = array_values( $ids );
	}

	// Holding the runner lock stops a cancelled job's last batch from saving over the new job.
	$lock = a8csp_cws_sync_job_acquire_lock();
	if ( ! $lock ) {
		return new WP_Error( 'a8csp_job_busy', 'The previous sync is still finishing its last batch. Try again in a moment.' );
	}

	try {
		if ( a8csp_cws_sync_job_is_active( a8csp_cws_sync_job_get() ) ) {
			return new WP_Error( 'a8csp_job_active', 'A sync is already in progress. Wait for it to finish or cancel it first.' );
		}

		$now = time();
		$job = array_merge(
			a8csp_cws_sync_job_defaults(),
			array(
				'id' => wp_generate_uuid4(),
				'status' => 'queued',
				'filters' => $filters,
				'skip_synced' => $skip_synced,
				'fingerprint' => a8csp_cws_current_sync_fingerprint(),
				'settings_fingerprint' => a8csp_cws_sync_job_settings_fingerprint(),
				'total' => count( $ids ),
				'skipped_at_start' => $skipped_at_start,
				'started_by' => (int) $user_id,
				'created_at' => $now,
				'retry_of' => (string) $retry_of,
			)
		);

		a8csp_cws_sync_job_delete_ids();
		if ( ! a8csp_cws_sync_job_store_ids( $job['id'], $ids ) ) {
			return new WP_Error( 'a8csp_job_save_failed', 'Could not save the list of posts to sync to the database.' );
		}

		if ( 0 === $job['total'] ) {
			$job['status'] = 'completed';
			$job['finished_at'] = $now;
		}

		// Save before enqueueing so the first batch always finds the job.
		$job = a8csp_cws_sync_job_save( $job );
	} finally {
		a8csp_cws_sync_job_release_lock( $lock );
	}

	if ( is_wp_error( $job ) || 'completed' === $job['status'] ) {
		return $job;
	}

	if ( ! a8csp_cws_sync_job_schedule( $job['id'], 0 ) ) {
		a8csp_cws_sync_job_save( a8csp_cws_sync_job_mark_failed( $job, 'Could not schedule the background sync with Action Scheduler.', 'a8csp_jobs_unavailable' ) );
		return new WP_Error( 'a8csp_jobs_unavailable', 'Could not schedule the background sync with Action Scheduler.' );
	}

	return $job;
}

/**
 * Action Scheduler callback: process one batch of the current job.
 */
function a8csp_cws_sync_job_process( $job_id ) {
	$job_id = is_scalar( $job_id ) ? (string) $job_id : '';
	$job = a8csp_cws_sync_job_get();

	if ( '' === $job_id || ! $job || $job['id'] !== $job_id || ! a8csp_cws_sync_job_is_active( $job ) ) {
		return;
	}
	if ( a8csp_cws_sync_job_cancel_requested( $job_id ) ) {
		return;
	}

	$lock = a8csp_cws_sync_job_acquire_lock();
	if ( ! $lock ) {
		// The lock holder may have died or belong to a batch that won't queue this job's next one.
		if ( ! a8csp_cws_sync_job_has_pending_action( $job_id ) ) {
			a8csp_cws_sync_job_schedule( $job_id, 60 );
		}
		return;
	}

	// finally{} does not run on fatal errors; this frees the lock and schedules a retry instead.
	register_shutdown_function( 'a8csp_cws_sync_job_shutdown', $job_id, $lock );

	try {
		// Re-read under the lock so this run builds on the latest saved state.
		$job = a8csp_cws_sync_job_get();
		if ( $job && $job['id'] === $job_id && a8csp_cws_sync_job_is_active( $job ) ) {
			a8csp_cws_sync_job_run_batch( $job, $lock );
		}
	} catch ( Throwable $e ) {
		a8csp_cws_sync_job_handle_exception( $job_id, $lock, $e );
	} finally {
		a8csp_cws_sync_job_release_lock( $lock );
	}
}

function a8csp_cws_sync_job_run_batch( array $job, $lock ) {
	if ( ! empty( $job['in_flight'] ) ) {
		$job = a8csp_cws_sync_job_recover_in_flight(
			$job,
			'The previous batch stopped unexpectedly (PHP fatal error or timeout). Check the PHP error log.',
			'a8csp_job_crashed',
			'Syncing this post stopped PHP unexpectedly (fatal error or timeout). Check the PHP error log.'
		);
		if ( ! a8csp_cws_sync_job_is_active( $job ) ) {
			a8csp_cws_sync_job_finish_run( $job, $lock, null );
			return;
		}
	}

	if ( a8csp_cws_current_sync_fingerprint() !== $job['fingerprint'] || a8csp_cws_sync_job_settings_fingerprint() !== $job['settings_fingerprint'] ) {
		$job = a8csp_cws_sync_job_mark_failed( $job, 'Plugin settings changed during the sync (provider, embedding model or Pinecone index). Start a new sync.', 'a8csp_settings_changed' );
		a8csp_cws_sync_job_finish_run( $job, $lock, null );
		return;
	}

	$missing = a8csp_cws_check_required_settings( a8csp_cws_get_api_settings() );
	if ( ! empty( $missing ) ) {
		$job = a8csp_cws_sync_job_mark_failed( $job, 'Required settings are missing: ' . implode( '; ', $missing ) . '.', 'a8csp_config_missing' );
		a8csp_cws_sync_job_finish_run( $job, $lock, null );
		return;
	}

	$max_batch_size = max( 1, min( 100, (int) apply_filters( 'a8csp_cws_sync_batch_size', 25 ) ) );
	$batch_size = $job['batch_cap'] > 0 ? min( $max_batch_size, $job['batch_cap'] ) : $max_batch_size;
	$job['isolate'] = min( $job['isolate'], count( $job['requeue'] ) );
	if ( $job['isolate'] > 0 ) {
		$batch_size = 1;
	}

	$ids = array();
	while ( count( $ids ) < $batch_size && ! empty( $job['requeue'] ) ) {
		$ids[] = (int) array_shift( $job['requeue'] );
	}
	while ( count( $ids ) < $batch_size && $job['cursor'] < $job['total'] ) {
		$slice = a8csp_cws_sync_job_load_ids( $job['id'], $job['cursor'], min( $batch_size - count( $ids ), $job['total'] - $job['cursor'] ) );
		if ( null === $slice ) {
			$job = a8csp_cws_sync_job_mark_failed( $job, 'The list of posts for this sync is missing from the database. Start a new sync.', 'a8csp_job_ids_missing' );
			a8csp_cws_sync_job_finish_run( $job, $lock, null );
			return;
		}
		foreach ( $slice as $post_id ) {
			$job['cursor']++;
			if ( $post_id > 0 ) {
				$ids[] = $post_id;
			}
		}
	}

	$valid = array();
	if ( ! empty( $ids ) ) {
		if ( a8csp_cws_sync_job_cancel_requested( $job['id'] ) ) {
			a8csp_cws_sync_job_finish_run( $job, $lock, null );
			return;
		}

		// Persisted before touching the posts so a run that dies mid-batch is detected by the next one.
		$job['in_flight'] = $ids;
		$job['status'] = 'running';
		$job['next_run_at'] = 0;
		if ( ! a8csp_cws_sync_job_owns_lock( $lock ) ) {
			return;
		}
		$saved = a8csp_cws_sync_job_save( $job );
		if ( is_wp_error( $saved ) ) {
			a8csp_cws_sync_job_halt( $job, $saved );
			return;
		}
		$job = $saved;

		_prime_post_caches( $ids, false, false );
		foreach ( $ids as $post_id ) {
			if ( a8csp_cws_post_is_syncable( $post_id ) ) {
				$valid[] = $post_id;
			} else {
				$job['skipped']++;
			}
		}
		$job['in_flight'] = $valid;
	}

	$delay = null;
	$clean_batch = true;
	$errors_before = $job['consecutive_errors'];

	if ( ! empty( $valid ) ) {
		try {
			// No in-request retries: the job waits between attempts itself, and long sleeps would outlast the lock.
			$result = a8csp_cws_sync_posts( $valid, array( 'max_retries' => 0 ) );
		} catch ( Throwable $e ) {
			$result = null;
			error_log( 'A8CSP: Sync job batch failed unexpectedly: ' . a8csp_cws_sync_job_clean_message( $e->getMessage() ) );
			$message = 'Unexpected error while syncing: ' . $e->getMessage();
			$job = a8csp_cws_sync_job_recover_in_flight( $job, $message, 'a8csp_job_exception', $message );
			$clean_batch = false;
			// A single post that throws is recorded as failed, so the job moves on without waiting.
			if ( count( $valid ) > 1 && a8csp_cws_sync_job_is_active( $job ) ) {
				$delay = a8csp_cws_sync_retry_delay( null, $job['consecutive_errors'] );
				$job['status'] = 'waiting';
				$job['next_run_at'] = time() + $delay;
			}
		}

		if ( null !== $result ) {
			$job['in_flight'] = array();
			$job['synced'] += count( $result['synced'] );
			foreach ( $result['failed'] as $post_id => $message ) {
				$job = a8csp_cws_sync_job_add_failure( $job, $post_id, $message );
			}

			$pending = array_values( array_filter( array_map( 'intval', $result['pending'] ) ) );
			if ( ! empty( $pending ) ) {
				$job['requeue'] = array_merge( $pending, $job['requeue'] );
			}
			$job['isolate'] = max( 0, $job['isolate'] - ( count( $ids ) - count( $pending ) ) );

			$fatal = is_wp_error( $result['fatal'] ) ? $result['fatal'] : null;
			if ( null !== $fatal ) {
				$clean_batch = false;
				$job = a8csp_cws_sync_job_handle_fatal( $job, $fatal, count( $valid ) );
				if ( a8csp_cws_sync_job_is_active( $job ) ) {
					$delay = a8csp_cws_sync_retry_delay( $fatal, $job['consecutive_errors'] );
					$job['status'] = 'waiting';
					$job['next_run_at'] = time() + $delay;
				}
			}
		}
	} else {
		$job['isolate'] = max( 0, $job['isolate'] - count( $ids ) );
	}

	if ( a8csp_cws_sync_job_is_active( $job ) && 'waiting' !== $job['status'] ) {
		if ( $clean_batch ) {
			// errors_before is 0 only when the previous batch was clean too, so a rate-limit cap grows back after two clean batches.
			if ( $job['batch_cap'] > 0 && 0 === $errors_before ) {
				$job['batch_cap'] = $job['batch_cap'] * 2 >= $max_batch_size ? 0 : $job['batch_cap'] * 2;
			}
			$job['consecutive_errors'] = 0;
			$job['last_error'] = '';
			$job['last_error_code'] = '';
		}

		if ( a8csp_cws_sync_job_has_remaining_work( $job ) ) {
			$job['status'] = 'running';
			$job['next_run_at'] = 0;
			$delay = max( 0, (int) apply_filters( 'a8csp_cws_sync_batch_delay', 0 ) );
		} else {
			$job['status'] = 'completed';
			$job['next_run_at'] = 0;
			$job['finished_at'] = time();
			$delay = null;
		}
	}

	a8csp_cws_sync_job_finish_run( $job, $lock, $delay );
}

/**
 * Apply a batch-level provider error to the job: fail it, or count the error so the caller can wait and retry.
 */
function a8csp_cws_sync_job_handle_fatal( array $job, WP_Error $fatal, $batch_count ) {
	$data = $fatal->get_error_data();
	$code = $fatal->get_error_code();

	if ( ! a8csp_cws_is_retryable_error( $fatal ) ) {
		return a8csp_cws_sync_job_mark_failed( $job, $fatal->get_error_message(), $code );
	}

	// The previous batch already waited for the daily reset, so the quota is still exhausted after it.
	if ( ! empty( $data['daily_quota'] ) && 'a8csp_quota_exhausted' === $job['last_error_code'] ) {
		return a8csp_cws_sync_job_mark_failed( $job, 'The Gemini daily quota was still exhausted after it reset. Check the Gemini plan and quota limits, then start a new sync. ' . $fatal->get_error_message(), $code );
	}

	// A rate limit that repeats on the same posts usually means one request needs more tokens than the per-minute limit.
	if ( 'a8csp_rate_limited' === $code && 'a8csp_rate_limited' === $job['last_error_code'] && $batch_count > 1 ) {
		$job['batch_cap'] = max( 1, (int) floor( $batch_count / 2 ) );
	}

	return a8csp_cws_sync_job_count_error( $job, $fatal->get_error_message(), $code );
}

/**
 * Save the run's result and queue the next batch, unless the job was cancelled or another runner took over.
 */
function a8csp_cws_sync_job_finish_run( array $job, $lock, $delay ) {
	if ( a8csp_cws_sync_job_cancel_requested( $job['id'] ) ) {
		$job = a8csp_cws_sync_job_mark_cancelled( $job );
		$delay = null;
	}

	if ( ! a8csp_cws_sync_job_owns_lock( $lock ) ) {
		error_log( 'A8CSP: Sync job lock was taken over by another runner; discarding this batch result' );
		return;
	}

	// Dismissing a job deletes its cancel flag, so its late runner must not bring it back.
	$stored = a8csp_cws_sync_job_get();
	if ( ! $stored || $stored['id'] !== $job['id'] ) {
		error_log( 'A8CSP: Sync job was dismissed or replaced during this batch; discarding this batch result' );
		return;
	}

	$saved = a8csp_cws_sync_job_save( $job );
	if ( is_wp_error( $saved ) ) {
		a8csp_cws_sync_job_halt( $job, $saved );
		return;
	}
	$job = $saved;

	if ( null === $delay || ! a8csp_cws_sync_job_is_active( $job ) ) {
		return;
	}

	// Collapses duplicate chains (e.g. a watchdog re-queue racing a live runner) into one.
	a8csp_cws_sync_job_unschedule( $job['id'] );
	a8csp_cws_sync_job_schedule( $job['id'], $delay );

	// A cancel that landed between the check above and the save would otherwise be overwritten.
	if ( a8csp_cws_sync_job_cancel_requested( $job['id'] ) ) {
		a8csp_cws_sync_job_unschedule( $job['id'] );
		$fresh = a8csp_cws_sync_job_get();
		if ( $fresh && $fresh['id'] === $job['id'] && a8csp_cws_sync_job_is_active( $fresh ) ) {
			a8csp_cws_sync_job_save( a8csp_cws_sync_job_mark_cancelled( $fresh ) );
		}
	}
}

/**
 * Last-resort handler for errors thrown outside the API calls (after the lock was taken).
 */
function a8csp_cws_sync_job_handle_exception( $job_id, $lock, Throwable $e ) {
	error_log( 'A8CSP: Sync job batch failed unexpectedly: ' . a8csp_cws_sync_job_clean_message( $e->getMessage() ) );

	$job = a8csp_cws_sync_job_get();
	if ( ! $job || $job['id'] !== $job_id || ! a8csp_cws_sync_job_is_active( $job ) ) {
		return;
	}

	$message = 'Unexpected error while syncing: ' . $e->getMessage();
	$job = a8csp_cws_sync_job_recover_in_flight( $job, $message, 'a8csp_job_exception', $message );

	$delay = null;
	if ( a8csp_cws_sync_job_is_active( $job ) ) {
		$delay = a8csp_cws_sync_retry_delay( null, $job['consecutive_errors'] );
		$job['status'] = 'waiting';
		$job['next_run_at'] = time() + $delay;
	}

	try {
		a8csp_cws_sync_job_finish_run( $job, $lock, $delay );
	} catch ( Throwable $inner ) {
		error_log( 'A8CSP: Could not record sync job error: ' . a8csp_cws_sync_job_clean_message( $inner->getMessage() ) );
	}
}

/**
 * Shutdown handler for a batch run: only acts when the run died before releasing its lock.
 */
function a8csp_cws_sync_job_shutdown( $job_id, $lock ) {
	try {
		if ( ! a8csp_cws_sync_job_owns_lock( $lock ) ) {
			return;
		}

		a8csp_cws_sync_job_release_lock( $lock );
		error_log( 'A8CSP: Sync job batch ended unexpectedly; retrying in 60s' );

		$job = a8csp_cws_sync_job_get();
		if ( $job && $job['id'] === $job_id && a8csp_cws_sync_job_is_active( $job ) && ! a8csp_cws_sync_job_cancel_requested( $job_id ) ) {
			a8csp_cws_sync_job_unschedule( $job_id );
			a8csp_cws_sync_job_schedule( $job_id, 60 );
		}
	} catch ( Throwable $e ) {
		error_log( 'A8CSP: Sync job shutdown handler failed: ' . a8csp_cws_sync_job_clean_message( $e->getMessage() ) );
	}
}

/**
 * Cancel the active job. Batches already running finish their current API calls, then stop.
 */
function a8csp_cws_sync_job_cancel() {
	$job = a8csp_cws_sync_job_get();
	if ( ! a8csp_cws_sync_job_is_active( $job ) ) {
		return new WP_Error( 'a8csp_no_active_job', 'There is no sync in progress to cancel.' );
	}

	// The flag is set first so a runner mid-batch sees it before it saves.
	a8csp_cws_sync_job_write_option( A8CSP_CWS_SYNC_JOB_CANCEL_OPTION, $job['id'] );

	$job_id = $job['id'];
	$fresh = a8csp_cws_sync_job_get();
	if ( $fresh && $fresh['id'] === $job_id ) {
		$job = $fresh;
	}
	if ( a8csp_cws_sync_job_is_active( $job ) ) {
		$job = a8csp_cws_sync_job_save( a8csp_cws_sync_job_mark_cancelled( $job ) );
	}

	a8csp_cws_sync_job_unschedule( $job_id );

	return $job;
}

/**
 * Start a new job for the posts that failed in the last (finished) job.
 */
function a8csp_cws_sync_job_retry_failed( $user_id = 0 ) {
	$job = a8csp_cws_sync_job_get();
	if ( ! $job ) {
		return new WP_Error( 'a8csp_no_job', 'There is no sync to retry.' );
	}
	if ( a8csp_cws_sync_job_is_active( $job ) ) {
		return new WP_Error( 'a8csp_job_active', 'A sync is already in progress. Wait for it to finish or cancel it first.' );
	}

	$post_ids = array_values( array_filter( array_map( 'intval', array_keys( $job['failures'] ) ) ) );
	if ( empty( $post_ids ) ) {
		return new WP_Error( 'a8csp_no_failures', 'The last sync has no failed posts to retry.' );
	}

	return a8csp_cws_sync_job_start( $job['filters'], false, $user_id, $post_ids, $job['id'] );
}

/**
 * Forget a finished job. Returns false while a job is still active.
 */
function a8csp_cws_sync_job_clear() {
	$job = a8csp_cws_sync_job_get();
	if ( a8csp_cws_sync_job_is_active( $job ) ) {
		return false;
	}

	if ( $job ) {
		a8csp_cws_sync_job_unschedule( $job['id'] );
	}
	delete_option( A8CSP_CWS_SYNC_JOB_OPTION );
	delete_option( A8CSP_CWS_SYNC_JOB_CANCEL_OPTION );
	a8csp_cws_sync_job_delete_ids();

	return true;
}

/**
 * Watchdog: re-queue an active job that has no pending or running batch. Cheap enough for every status poll.
 */
function a8csp_cws_sync_job_ensure_running() {
	$job = a8csp_cws_sync_job_get();
	if ( ! a8csp_cws_sync_job_is_active( $job ) || ! a8csp_cws_sync_jobs_available() ) {
		return $job;
	}

	if ( a8csp_cws_sync_job_lock_is_fresh() ) {
		return $job;
	}

	// A runner that died after overwriting a cancel would leave the job active forever.
	if ( a8csp_cws_sync_job_cancel_requested( $job['id'] ) ) {
		a8csp_cws_sync_job_unschedule( $job['id'] );
		$saved = a8csp_cws_sync_job_save( a8csp_cws_sync_job_mark_cancelled( $job ) );
		return is_wp_error( $saved ) ? $job : $saved;
	}

	try {
		$has_action = as_has_scheduled_action( A8CSP_CWS_SYNC_JOB_HOOK, array( $job['id'] ), A8CSP_CWS_SYNC_JOB_GROUP );
	} catch ( Throwable $e ) {
		return $job;
	}
	if ( $has_action ) {
		return $job;
	}

	$now = time();
	if ( 'waiting' === $job['status'] && $job['next_run_at'] > 0 ) {
		$stalled = $now - $job['next_run_at'] > 300;
	} else {
		$stalled = $now - $job['updated_at'] > 120;
	}

	if ( $stalled ) {
		error_log( 'A8CSP: Sync job had no scheduled batch; queueing one' );
		a8csp_cws_sync_job_schedule( $job['id'], 0 );
	}

	return $job;
}

function a8csp_cws_sync_job_filters_label( array $filters ) {
	$parts = array( 'Post type: ' . ( isset( $filters['post_type'] ) && '' !== $filters['post_type'] ? $filters['post_type'] : '(none)' ) );

	$taxonomies = array(
		'category' => array( 'category', 'Category' ),
		'tag' => array( 'post_tag', 'Tag' ),
	);
	foreach ( $taxonomies as $key => $taxonomy ) {
		$term_id = isset( $filters[ $key ] ) ? (int) $filters[ $key ] : 0;
		if ( $term_id <= 0 ) {
			continue;
		}
		$term = get_term( $term_id, $taxonomy[0] );
		$parts[] = $taxonomy[1] . ': ' . ( ( $term instanceof WP_Term ) ? $term->name : '#' . $term_id );
	}

	return implode( ' · ', $parts );
}

/**
 * Job with display labels, progress percent and up to 50 resolved failures, or null when there is no job.
 */
function a8csp_cws_sync_job_summary( $job ) {
	if ( ! is_array( $job ) || empty( $job['id'] ) ) {
		return null;
	}

	$labels = array(
		'queued' => 'Queued',
		'running' => 'Running',
		'waiting' => 'Waiting to retry',
		'completed' => 'Completed',
		'cancelled' => 'Cancelled',
		'failed' => 'Failed',
	);

	$total = $job['total'];
	$processed = $job['synced'] + $job['failed'] + $job['skipped'];
	if ( 0 === $total || 'completed' === $job['status'] ) {
		$percent = 100;
	} else {
		$percent = (int) min( 100, floor( $processed * 100 / $total ) );
	}

	$next_run_human = ( 'waiting' === $job['status'] && $job['next_run_at'] > time() ) ? human_time_diff( time(), $job['next_run_at'] ) : '';

	$filters_label = a8csp_cws_sync_job_filters_label( $job['filters'] );
	if ( '' !== $job['retry_of'] ) {
		$filters_label = 'Retry of failed posts · ' . $filters_label;
	}

	$failures = array();
	$failure_ids = array_slice( array_keys( $job['failures'] ), 0, 50 );
	if ( ! empty( $failure_ids ) ) {
		_prime_post_caches( array_map( 'intval', $failure_ids ), false, false );
	}
	foreach ( $failure_ids as $post_id ) {
		$post_id = (int) $post_id;
		$post = get_post( $post_id );
		if ( $post ) {
			$title = '' !== $post->post_title ? $post->post_title : '(no title)';
			$edit_url = (string) get_edit_post_link( $post_id, 'raw' );
		} else {
			$title = sprintf( '(deleted post #%d)', $post_id );
			$edit_url = '';
		}
		$failures[] = array(
			'post_id' => $post_id,
			'title' => $title,
			'edit_url' => $edit_url,
			'message' => $job['failures'][ $post_id ],
		);
	}

	return array(
		'id' => $job['id'],
		'status' => $job['status'],
		'status_label' => isset( $labels[ $job['status'] ] ) ? $labels[ $job['status'] ] : ucfirst( $job['status'] ),
		'is_active' => a8csp_cws_sync_job_is_active( $job ),
		'runner_busy' => a8csp_cws_sync_job_lock_is_fresh(),
		'total' => $total,
		'processed' => $processed,
		'synced' => $job['synced'],
		'skipped' => $job['skipped'],
		'failed' => $job['failed'],
		'skipped_at_start' => $job['skipped_at_start'],
		'percent' => $percent,
		'last_error' => $job['last_error'],
		'last_error_code' => $job['last_error_code'],
		'next_run_at' => $job['next_run_at'],
		'next_run_human' => $next_run_human,
		'started_at' => $job['created_at'],
		'updated_at' => $job['updated_at'],
		'finished_at' => $job['finished_at'],
		'filters_label' => $filters_label,
		'failures' => $failures,
		'has_failures' => ! empty( $job['failures'] ),
	);
}

/**
 * Deactivation: drop queued batches. The job record stays, so the watchdog resumes it after reactivation.
 */
function a8csp_cws_sync_job_deactivate() {
	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		return;
	}

	try {
		as_unschedule_all_actions( '', array(), A8CSP_CWS_SYNC_JOB_GROUP );
	} catch ( Throwable $e ) {
		error_log( 'A8CSP: Could not unschedule sync job batches on deactivation: ' . a8csp_cws_sync_job_clean_message( $e->getMessage() ) );
	}
}
