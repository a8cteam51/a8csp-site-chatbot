<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_enqueue_scripts', 'a8csp_cws_sync_jobs_admin_enqueue' );
add_action( 'wp_ajax_a8csp_cws_sync_job_start', 'a8csp_cws_ajax_sync_job_start' );
add_action( 'wp_ajax_a8csp_cws_sync_job_status', 'a8csp_cws_ajax_sync_job_status' );
add_action( 'wp_ajax_a8csp_cws_sync_job_cancel', 'a8csp_cws_ajax_sync_job_cancel' );
add_action( 'wp_ajax_a8csp_cws_sync_job_retry_failed', 'a8csp_cws_ajax_sync_job_retry_failed' );
add_action( 'wp_ajax_a8csp_cws_sync_job_dismiss', 'a8csp_cws_ajax_sync_job_dismiss' );

/**
 * Render the background sync panel on the Content Library page.
 */
function a8csp_cws_render_sync_job_panel( $post_type, $category, $tag, $matching_count ) {
	if ( ! a8csp_cws_sync_jobs_available() ) {
		?>
		<div id="a8csp-sync-job" class="a8csp-sync-job">
			<div class="notice notice-warning inline">
				<p><?php echo wp_kses( __( 'Background sync is unavailable because Action Scheduler isn\'t loaded. Run <code>composer install</code> in the plugin folder.', 'a8csp-site-chatbot' ), array( 'code' => array() ) ); ?></p>
			</div>
		</div>
		<?php
		return;
	}

	$post_type = (string) $post_type;
	$category = 'post' === $post_type ? max( 0, (int) $category ) : 0;
	$tag = 'post' === $post_type ? max( 0, (int) $tag ) : 0;
	$matching_count = max( 0, (int) $matching_count );
	$missing = a8csp_cws_check_required_settings( a8csp_cws_get_api_settings() );
	$can_start = empty( $missing ) && $matching_count > 0;
	?>
	<div id="a8csp-sync-job" class="a8csp-sync-job"
		data-post-type="<?php echo esc_attr( $post_type ); ?>"
		data-category="<?php echo esc_attr( $category ); ?>"
		data-tag="<?php echo esc_attr( $tag ); ?>"
		data-matching-count="<?php echo esc_attr( $matching_count ); ?>"
		data-settings-ready="<?php echo empty( $missing ) ? '1' : '0'; ?>">
		<h2 class="a8csp-sync-job-title"><?php esc_html_e( 'Background sync', 'a8csp-site-chatbot' ); ?></h2>

		<?php if ( ! empty( $missing ) ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'Complete the plugin settings to enable background sync:', 'a8csp-site-chatbot' ); ?></strong></p>
				<ul class="a8csp-sync-job-missing">
					<?php foreach ( $missing as $warning ) : ?>
						<li><?php echo esc_html( $warning ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=chat-with-site-settings' ) ); ?>"><?php esc_html_e( 'Go to Settings', 'a8csp-site-chatbot' ); ?></a></p>
			</div>
		<?php endif; ?>

		<div class="a8csp-sync-job-start">
			<p class="a8csp-sync-job-lead">
				<?php
				if ( $matching_count > 0 ) {
					printf(
						esc_html( _n( 'Sync the %s post matching the current filters.', 'Sync all %s posts matching the current filters.', $matching_count, 'a8csp-site-chatbot' ) ),
						'<strong>' . esc_html( number_format_i18n( $matching_count ) ) . '</strong>'
					);
				} else {
					esc_html_e( 'No published posts match the current filters.', 'a8csp-site-chatbot' );
				}
				?>
			</p>
			<p>
				<label for="a8csp-sync-job-skip-synced">
					<input type="checkbox" id="a8csp-sync-job-skip-synced" data-sync-role="skip-synced" value="1" checked="checked"<?php disabled( ! $can_start ); ?>>
					<?php esc_html_e( 'Skip posts already synced with the current settings', 'a8csp-site-chatbot' ); ?>
				</label>
			</p>
			<p>
				<button type="button" class="button button-primary" data-sync-role="start" aria-describedby="a8csp-sync-job-hint"<?php disabled( ! $can_start ); ?>><?php esc_html_e( 'Sync all matching posts', 'a8csp-site-chatbot' ); ?></button>
			</p>
			<p id="a8csp-sync-job-hint" class="description a8csp-sync-job-hint">
				<?php esc_html_e( 'The sync runs in the background and keeps going if you close this page, but it advances fastest while this page is open, because admin page requests trigger the background queue.', 'a8csp-site-chatbot' ); ?>
			</p>
		</div>

		<div class="a8csp-sync-job-message" data-sync-role="message" role="alert"></div>

		<div class="a8csp-sync-job-status" data-sync-role="status" hidden>
			<h3 class="a8csp-sync-job-status-heading" data-sync-role="status-heading" tabindex="-1"><?php esc_html_e( 'Latest sync', 'a8csp-site-chatbot' ); ?></h3>
			<p class="a8csp-sync-job-status-line">
				<span class="a8csp-sync-job-badge" data-sync-role="status-label" aria-live="polite" aria-atomic="true"></span>
				<span class="spinner" data-sync-role="spinner" aria-hidden="true"></span>
				<span class="a8csp-sync-job-filters" data-sync-role="filters"></span>
			</p>
			<label class="screen-reader-text" for="a8csp-sync-job-progress"><?php esc_html_e( 'Sync progress', 'a8csp-site-chatbot' ); ?></label>
			<progress id="a8csp-sync-job-progress" class="a8csp-sync-job-progress" data-sync-role="progress" max="1" value="0" aria-describedby="a8csp-sync-job-progress-text"></progress>
			<p id="a8csp-sync-job-progress-text" class="a8csp-sync-job-progress-text" data-sync-role="progress-text"></p>
			<ul class="a8csp-sync-job-counts">
				<li><?php esc_html_e( 'Synced:', 'a8csp-site-chatbot' ); ?> <strong data-sync-role="count-synced">0</strong></li>
				<li><?php esc_html_e( 'Skipped:', 'a8csp-site-chatbot' ); ?> <strong data-sync-role="count-skipped">0</strong></li>
				<li><?php esc_html_e( 'Failed:', 'a8csp-site-chatbot' ); ?> <strong data-sync-role="count-failed">0</strong></li>
				<li data-sync-role="skipped-at-start" hidden><?php esc_html_e( 'Already synced, left out:', 'a8csp-site-chatbot' ); ?> <strong data-sync-role="count-skipped-at-start">0</strong></li>
			</ul>
			<p class="description a8csp-sync-job-meta" data-sync-role="meta" hidden></p>
			<div class="notice notice-warning inline a8csp-sync-job-error" data-sync-role="error" hidden>
				<p data-sync-role="error-line"><strong><?php esc_html_e( 'Last error:', 'a8csp-site-chatbot' ); ?></strong> <span data-sync-role="error-text"></span></p>
				<p data-sync-role="next-run" hidden></p>
			</div>
			<details class="a8csp-sync-job-failures" data-sync-role="failures" hidden>
				<summary data-sync-role="failures-summary"></summary>
				<ul class="a8csp-sync-job-failure-list" data-sync-role="failures-list"></ul>
				<p class="description" data-sync-role="failures-more" hidden></p>
			</details>
			<p class="a8csp-sync-job-actions">
				<button type="button" class="button" data-sync-role="cancel" hidden><?php esc_html_e( 'Cancel sync', 'a8csp-site-chatbot' ); ?></button>
				<button type="button" class="button" data-sync-role="retry" hidden><?php esc_html_e( 'Retry failed posts', 'a8csp-site-chatbot' ); ?></button>
				<button type="button" class="button" data-sync-role="dismiss" hidden><?php esc_html_e( 'Dismiss', 'a8csp-site-chatbot' ); ?></button>
				<a href="" data-sync-role="reload" hidden><?php esc_html_e( 'Reload the page to refresh the statuses below', 'a8csp-site-chatbot' ); ?></a>
			</p>
		</div>
	</div>
	<?php
}

/**
 * Enqueue the background sync script on the Content Library page.
 */
function a8csp_cws_sync_jobs_admin_enqueue( $hook ) {
	if ( 'toplevel_page_chat-with-site-sync' !== $hook ) {
		return;
	}

	if ( ! a8csp_cws_sync_jobs_available() ) {
		return;
	}

	wp_enqueue_script(
		'a8csp-sync-jobs',
		plugins_url( 'assets/sync-jobs.js', A8CSP_CWS_PLUGIN_FILE ),
		array(),
		filemtime( plugin_dir_path( A8CSP_CWS_PLUGIN_FILE ) . 'assets/sync-jobs.js' ),
		true
	);

	wp_localize_script( 'a8csp-sync-jobs', 'a8cspSyncJobs', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'a8csp_cws_sync_job' ),
		'pollInterval' => 3000,
		'job' => a8csp_cws_sync_job_summary( a8csp_cws_sync_job_get() ),
		'i18n' => array(
			'requestFailed' => __( 'The request failed. Check your connection and try again.', 'a8csp-site-chatbot' ),
			'sessionExpired' => __( 'Your session has expired. Reload the page and try again.', 'a8csp-site-chatbot' ),
			'statusFailed' => __( 'Couldn\'t refresh the sync status. Trying again shortly.', 'a8csp-site-chatbot' ),
			'confirmCancel' => __( 'Cancel the background sync? Posts synced so far stay synced.', 'a8csp-site-chatbot' ),
			'progress' => __( '%1$s of %2$s processed', 'a8csp-site-chatbot' ),
			'startedAt' => __( 'Started %s', 'a8csp-site-chatbot' ),
			'finishedAt' => __( 'Finished %s', 'a8csp-site-chatbot' ),
			'nextRetry' => __( 'Next attempt in about %s.', 'a8csp-site-chatbot' ),
			'retryingSoon' => __( 'Next attempt shortly.', 'a8csp-site-chatbot' ),
			'failuresSummary' => __( 'Failed posts (%s)', 'a8csp-site-chatbot' ),
			'failuresMore' => __( 'Showing the first %1$s of %2$s failed posts.', 'a8csp-site-chatbot' ),
			'postFallback' => __( 'Post #%s', 'a8csp-site-chatbot' ),
		),
	) );
}

/**
 * Stop a sync job AJAX request unless the nonce, capability and job engine checks pass.
 */
function a8csp_cws_sync_job_ajax_guard( $require_scheduler = true ) {
	if ( ! check_ajax_referer( 'a8csp_cws_sync_job', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page and try again.', 'a8csp-site-chatbot' ) ), 403 );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to manage the background sync.', 'a8csp-site-chatbot' ) ), 403 );
	}

	if ( $require_scheduler && ! a8csp_cws_sync_jobs_available() ) {
		wp_send_json_error( array( 'message' => __( 'Background sync is unavailable because Action Scheduler isn\'t loaded. Run composer install in the plugin folder.', 'a8csp-site-chatbot' ) ), 400 );
	}
}

/**
 * Send the job summary as JSON, or the error with a status code matching its cause.
 */
function a8csp_cws_sync_job_ajax_respond( $job ) {
	if ( is_wp_error( $job ) ) {
		$code = $job->get_error_code();
		if ( in_array( $code, array( 'a8csp_unexpected', 'a8csp_job_save_failed' ), true ) ) {
			$status = 500;
		} elseif ( in_array( $code, array( 'a8csp_invalid_request', 'a8csp_invalid_filters', 'a8csp_jobs_unavailable', 'a8csp_config_missing' ), true ) ) {
			$status = 400;
		} else {
			$status = 409;
		}
		wp_send_json_error( array( 'message' => $job->get_error_message() ), $status );
	}

	wp_send_json_success( array( 'job' => is_array( $job ) ? a8csp_cws_sync_job_summary( $job ) : null ) );
}

/**
 * Log an exception thrown by the job engine and return an admin-safe error.
 */
function a8csp_cws_sync_job_ajax_exception( $e ) {
	$message = a8csp_cws_redact_secrets( $e->getMessage() );
	error_log( 'A8CSP: Sync job request failed: ' . $message );

	return new WP_Error( 'a8csp_unexpected', __( 'Something went wrong with the background sync. Check the PHP error log for details.', 'a8csp-site-chatbot' ) );
}

function a8csp_cws_sync_job_request_field( $key, $default = '' ) {
	if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
		return $default;
	}

	return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
}

/**
 * AJAX: start a background sync for the current Content Library filters.
 */
function a8csp_cws_ajax_sync_job_start() {
	a8csp_cws_sync_job_ajax_guard();

	$post_type = sanitize_key( a8csp_cws_sync_job_request_field( 'post_type', 'post' ) );
	if ( ! in_array( $post_type, get_post_types( array( 'public' => true ), 'names' ), true ) ) {
		a8csp_cws_sync_job_ajax_respond( new WP_Error( 'a8csp_invalid_request', __( 'Invalid post type.', 'a8csp-site-chatbot' ) ) );
	}

	$category = 0;
	$tag = 0;
	if ( 'post' === $post_type ) {
		$category = max( 0, (int) a8csp_cws_sync_job_request_field( 'category', '0' ) );
		$tag = max( 0, (int) a8csp_cws_sync_job_request_field( 'tag', '0' ) );
	}

	if ( $category > 0 && ! term_exists( $category, 'category' ) ) {
		a8csp_cws_sync_job_ajax_respond( new WP_Error( 'a8csp_invalid_request', __( 'The selected category no longer exists. Reload the page and try again.', 'a8csp-site-chatbot' ) ) );
	}

	if ( $tag > 0 && ! term_exists( $tag, 'post_tag' ) ) {
		a8csp_cws_sync_job_ajax_respond( new WP_Error( 'a8csp_invalid_request', __( 'The selected tag no longer exists. Reload the page and try again.', 'a8csp-site-chatbot' ) ) );
	}

	$skip_synced = a8csp_cws_sync_job_request_field( 'skip_synced', '1' );
	if ( ! in_array( $skip_synced, array( '0', '1' ), true ) ) {
		a8csp_cws_sync_job_ajax_respond( new WP_Error( 'a8csp_invalid_request', __( 'Invalid value for skipping synced posts.', 'a8csp-site-chatbot' ) ) );
	}

	$filters = array(
		'post_type' => $post_type,
		'category' => $category,
		'tag' => $tag,
	);

	try {
		$job = a8csp_cws_sync_job_start( $filters, '1' === $skip_synced, get_current_user_id() );
	} catch ( Throwable $e ) {
		$job = a8csp_cws_sync_job_ajax_exception( $e );
	}

	a8csp_cws_sync_job_ajax_respond( $job );
}

/**
 * AJAX: return the current job summary, re-queueing a stalled job first.
 */
function a8csp_cws_ajax_sync_job_status() {
	a8csp_cws_sync_job_ajax_guard( false );

	try {
		$job = a8csp_cws_sync_job_ensure_running();
	} catch ( Throwable $e ) {
		a8csp_cws_sync_job_ajax_exception( $e );
		try {
			$job = a8csp_cws_sync_job_get();
		} catch ( Throwable $e ) {
			$job = a8csp_cws_sync_job_ajax_exception( $e );
		}
	}

	a8csp_cws_sync_job_ajax_respond( $job );
}

function a8csp_cws_ajax_sync_job_cancel() {
	a8csp_cws_sync_job_ajax_guard();

	try {
		$job = a8csp_cws_sync_job_cancel();
	} catch ( Throwable $e ) {
		$job = a8csp_cws_sync_job_ajax_exception( $e );
	}

	a8csp_cws_sync_job_ajax_respond( $job );
}

function a8csp_cws_ajax_sync_job_retry_failed() {
	a8csp_cws_sync_job_ajax_guard();

	try {
		$job = a8csp_cws_sync_job_retry_failed( get_current_user_id() );
	} catch ( Throwable $e ) {
		$job = a8csp_cws_sync_job_ajax_exception( $e );
	}

	a8csp_cws_sync_job_ajax_respond( $job );
}

/**
 * AJAX: clear a finished job from the panel.
 */
function a8csp_cws_ajax_sync_job_dismiss() {
	a8csp_cws_sync_job_ajax_guard( false );

	try {
		a8csp_cws_sync_job_clear();
		$job = a8csp_cws_sync_job_get();
	} catch ( Throwable $e ) {
		$job = a8csp_cws_sync_job_ajax_exception( $e );
	}

	if ( is_array( $job ) ) {
		$message = a8csp_cws_sync_job_is_active( $job )
			? __( 'Cancel the running sync before dismissing it.', 'a8csp-site-chatbot' )
			: __( 'The sync status could not be dismissed. Try again.', 'a8csp-site-chatbot' );
		$job = new WP_Error( 'a8csp_job_active', $message );
	}

	a8csp_cws_sync_job_ajax_respond( $job );
}
