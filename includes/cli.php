<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Sync site content to Pinecone and manage background sync jobs.
 */
class A8CSP_CWS_CLI_Command extends WP_CLI_Command {

	/**
	 * Sync published posts to Pinecone.
	 *
	 * By default only posts not yet synced with the current provider, embedding model and Pinecone index are sent. Runs in the foreground unless --background is given.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Public post type to sync.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--category=<id-or-slug>]
	 * : Only sync posts in this category (post type "post" only).
	 *
	 * [--tag=<id-or-slug>]
	 * : Only sync posts with this tag (post type "post" only).
	 *
	 * [--all]
	 * : Re-sync posts that are already synced with the current settings.
	 *
	 * [--batch-size=<n>]
	 * : Posts per embedding/upsert batch in the foreground (1-100). Default 50.
	 *
	 * [--background]
	 * : Start a background job processed by Action Scheduler instead of syncing now.
	 *
	 * [--dry-run]
	 * : Show how many posts would be synced and skipped, then exit.
	 *
	 * ## EXAMPLES
	 *
	 *     # Sync every post that is not yet synced.
	 *     $ wp site-chatbot sync
	 *
	 *     # Re-sync all pages.
	 *     $ wp site-chatbot sync --post_type=page --all
	 *
	 *     # Preview a category sync.
	 *     $ wp site-chatbot sync --category=travel --dry-run
	 *
	 *     # Start a background job and process it right away.
	 *     $ wp site-chatbot sync --background
	 *     $ wp action-scheduler run --hooks=a8csp_cws_sync_job_batch
	 */
	public function sync( $args, $assoc_args ) {
		$missing = a8csp_cws_check_required_settings( a8csp_cws_get_api_settings() );
		if ( ! empty( $missing ) ) {
			WP_CLI::error( 'Required settings are missing: ' . implode( '; ', $missing ) . '.' );
		}

		$post_type = sanitize_key( (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'post_type', 'post' ) );
		if ( ! in_array( $post_type, get_post_types( array( 'public' => true ), 'names' ), true ) ) {
			WP_CLI::error( sprintf( '"%s" is not a public post type.', $post_type ) );
		}

		$category_arg = WP_CLI\Utils\get_flag_value( $assoc_args, 'category', '' );
		$tag_arg = WP_CLI\Utils\get_flag_value( $assoc_args, 'tag', '' );
		if ( 'post' !== $post_type && ( '' !== (string) $category_arg || '' !== (string) $tag_arg ) ) {
			WP_CLI::error( '--category and --tag can only be used with --post_type=post.' );
		}

		$filters = array(
			'post_type' => $post_type,
			'category' => '' !== (string) $category_arg ? $this->resolve_term( $category_arg, 'category' ) : 0,
			'tag' => '' !== (string) $tag_arg ? $this->resolve_term( $tag_arg, 'post_tag' ) : 0,
		);
		$skip_synced = ! WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$background = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'background', false );

		$batch_size = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 50 );
		if ( $batch_size < 1 || $batch_size > 100 ) {
			WP_CLI::error( '--batch-size must be between 1 and 100.' );
		}

		$label = a8csp_cws_sync_job_filters_label( $filters );

		if ( $dry_run ) {
			$query = a8csp_cws_sync_job_query_post_ids( $filters, $skip_synced );
			WP_CLI::log( $label );
			WP_CLI::log( sprintf( 'Would sync %d post(s).', count( $query['ids'] ) ) );
			WP_CLI::log( sprintf( 'Would skip %d post(s) already synced with the current settings.', $query['skipped'] ) );
			return;
		}

		if ( $background ) {
			if ( isset( $assoc_args['batch-size'] ) ) {
				WP_CLI::warning( '--batch-size does not apply to background jobs; use the a8csp_cws_sync_batch_size filter.' );
			}

			$job = a8csp_cws_sync_job_start( $filters, $skip_synced, get_current_user_id() );
			if ( is_wp_error( $job ) ) {
				WP_CLI::error( $job->get_error_message() );
			}

			if ( 'completed' === $job['status'] ) {
				WP_CLI::success( sprintf( 'Nothing to sync (%d post(s) already synced).', $job['skipped_at_start'] ) );
				return;
			}

			WP_CLI::success( sprintf( 'Started background sync job %s for %d post(s).', $job['id'], $job['total'] ) );
			WP_CLI::log( 'Check progress: wp site-chatbot status' );
			WP_CLI::log( 'Process it now: wp action-scheduler run --hooks=' . A8CSP_CWS_SYNC_JOB_HOOK );
			return;
		}

		$this->sync_foreground( $filters, $skip_synced, $batch_size, $label );
	}

	/**
	 * Show the current background sync job.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp site-chatbot status
	 *     $ wp site-chatbot status --format=json
	 */
	public function status( $args, $assoc_args ) {
		$summary = a8csp_cws_sync_job_summary( a8csp_cws_sync_job_ensure_running() );
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $summary ) );
			return;
		}

		if ( null === $summary ) {
			WP_CLI::log( 'No sync job.' );
			return;
		}

		$rows = array(
			'Job' => $summary['id'],
			'Status' => $summary['status_label'],
			'Filters' => $summary['filters_label'],
			'Progress' => sprintf( '%d of %d processed (%d%%)', $summary['processed'], $summary['total'], $summary['percent'] ),
			'Synced' => $summary['synced'],
			'Skipped' => $summary['skipped'],
			'Failed' => $summary['failed'],
			'Already synced at start' => $summary['skipped_at_start'],
			'Started' => $summary['started_at'] ? wp_date( 'Y-m-d H:i:s', $summary['started_at'] ) : '',
			'Finished' => $summary['finished_at'] ? wp_date( 'Y-m-d H:i:s', $summary['finished_at'] ) : '',
		);
		if ( '' !== $summary['last_error'] ) {
			$rows['Last error'] = $summary['last_error'];
		}
		if ( '' !== $summary['next_run_human'] ) {
			$rows['Next retry'] = 'in ' . $summary['next_run_human'];
		}

		$items = array();
		foreach ( $rows as $field => $value ) {
			$items[] = array(
				'Field' => $field,
				'Value' => $value,
			);
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'Field', 'Value' ) );

		if ( ! empty( $summary['failures'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Failures (showing %d of %d):', count( $summary['failures'] ), $summary['failed'] ) );
			WP_CLI\Utils\format_items( 'table', $summary['failures'], array( 'post_id', 'title', 'message' ) );
		}
	}

	/**
	 * Cancel the active background sync job.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp site-chatbot cancel
	 */
	public function cancel( $args, $assoc_args ) {
		$job = a8csp_cws_sync_job_cancel();
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		$summary = a8csp_cws_sync_job_summary( $job );
		if ( 'cancelled' !== $summary['status'] ) {
			WP_CLI::warning( sprintf( 'The job finished before it could be cancelled (status: %s).', $summary['status_label'] ) );
			return;
		}

		WP_CLI::success( sprintf( 'Cancelled sync job %s (%d of %d posts processed).', $summary['id'], $summary['processed'], $summary['total'] ) );
	}

	private function sync_foreground( array $filters, $skip_synced, $batch_size, $label ) {
		if ( a8csp_cws_sync_job_is_active( a8csp_cws_sync_job_get() ) ) {
			WP_CLI::warning( 'A background sync job is also running; posts may be synced twice. Cancel it with: wp site-chatbot cancel' );
		}

		$query = a8csp_cws_sync_job_query_post_ids( $filters, $skip_synced );
		$queue = $query['ids'];
		$total = count( $queue );

		WP_CLI::log( $label );
		if ( $query['skipped'] > 0 ) {
			WP_CLI::log( sprintf( 'Skipping %d post(s) already synced with the current settings (use --all to re-sync them).', $query['skipped'] ) );
		}
		if ( 0 === $total ) {
			WP_CLI::success( 'Nothing to sync.' );
			return;
		}

		$synced = 0;
		$skipped = 0;
		$failed = 0;
		$failures = array();
		$consecutive_errors = 0;
		$last_error_code = '';
		$isolate = 0;
		$max_errors = A8CSP_CWS_SYNC_JOB_MAX_CONSECUTIVE_ERRORS;

		$progress = WP_CLI\Utils\make_progress_bar( 'Syncing posts', $total );

		while ( ! empty( $queue ) ) {
			$batch = array_splice( $queue, 0, $isolate > 0 ? 1 : $batch_size );

			_prime_post_caches( $batch, false, false );
			$valid = array();
			foreach ( $batch as $post_id ) {
				if ( a8csp_cws_post_is_syncable( $post_id ) ) {
					$valid[] = $post_id;
				} else {
					$skipped++;
					$progress->tick();
				}
			}
			if ( empty( $valid ) ) {
				$isolate = max( 0, $isolate - count( $batch ) );
				continue;
			}

			try {
				$result = a8csp_cws_sync_posts( $valid, array( 'max_retries' => 2 ) );
			} catch ( Throwable $e ) {
				$consecutive_errors++;
				$last_error_code = 'a8csp_job_exception';
				$message = 'Unexpected error while syncing: ' . a8csp_cws_sync_job_clean_message( $e->getMessage() );
				if ( $consecutive_errors >= $max_errors ) {
					$progress->finish();
					WP_CLI::error( sprintf( 'Giving up after %d consecutive errors. %s', $max_errors, $this->counts_line( $synced, $skipped, $failed, count( $queue ) + count( $valid ) ) ) );
				}
				if ( count( $valid ) > 1 ) {
					WP_CLI::warning( $message . ' Retrying these posts one at a time.' );
					$queue = array_merge( $valid, $queue );
					$isolate = max( $isolate, count( $valid ) );
				} else {
					$failed++;
					$failures[ $valid[0] ] = $message;
					$isolate = max( 0, $isolate - count( $batch ) );
					$progress->tick();
				}
				continue;
			}

			$synced += count( $result['synced'] );
			foreach ( $result['failed'] as $post_id => $message ) {
				$failed++;
				$failures[ (int) $post_id ] = $message;
			}
			$isolate = max( 0, $isolate - ( count( $batch ) - count( $result['pending'] ) ) );
			$progress->tick( count( $result['synced'] ) + count( $result['failed'] ) );

			if ( is_wp_error( $result['fatal'] ) ) {
				$fatal = $result['fatal'];
				$queue = array_merge( array_map( 'intval', $result['pending'] ), $queue );

				if ( ! a8csp_cws_is_retryable_error( $fatal ) ) {
					$progress->finish();
					WP_CLI::error( $fatal->get_error_message() . ' ' . $this->counts_line( $synced, $skipped, $failed, count( $queue ) ) );
				}

				$fatal_data = $fatal->get_error_data();
				if ( ! empty( $fatal_data['daily_quota'] ) ) {
					$progress->finish();
					WP_CLI::error( $fatal->get_error_message() . ' Run the command again after the reset, or use --background to have a job wait for it. ' . $this->counts_line( $synced, $skipped, $failed, count( $queue ) ) );
				}

				$consecutive_errors++;
				if ( $consecutive_errors >= $max_errors ) {
					$progress->finish();
					WP_CLI::error( sprintf( 'Giving up after %d consecutive errors. Last error: %s %s', $max_errors, $fatal->get_error_message(), $this->counts_line( $synced, $skipped, $failed, count( $queue ) ) ) );
				}

				// A rate limit that repeats on the same posts usually means one request needs more tokens than the per-minute limit.
				if ( 'a8csp_rate_limited' === $fatal->get_error_code() && 'a8csp_rate_limited' === $last_error_code && count( $valid ) > 1 ) {
					$batch_size = max( 1, (int) floor( count( $valid ) / 2 ) );
					WP_CLI::log( sprintf( 'Lowering the batch size to %d after repeated rate limits.', $batch_size ) );
				}
				$last_error_code = $fatal->get_error_code();

				$delay = ! empty( $fatal_data['retry_after'] ) ? max( 1, (int) $fatal_data['retry_after'] ) : a8csp_cws_sync_retry_delay( $fatal, $consecutive_errors );
				WP_CLI::log( sprintf( 'Waiting %ds: %s', $delay, $fatal->get_error_message() ) );
				sleep( $delay );
				continue;
			}

			$consecutive_errors = 0;
			$last_error_code = '';

			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		$progress->finish();

		WP_CLI::log( sprintf( 'Synced %d, skipped %d, failed %d of %d post(s).', $synced, $skipped, $failed, $total ) );

		if ( empty( $failures ) ) {
			WP_CLI::success( 'Sync complete.' );
			return;
		}

		$rows = array();
		foreach ( $failures as $post_id => $message ) {
			$post = get_post( $post_id );
			$rows[] = array(
				'post_id' => $post_id,
				'title' => $post ? $post->post_title : '',
				'message' => $message,
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'post_id', 'title', 'message' ) );
		WP_CLI::warning( sprintf( '%d post(s) failed to sync.', $failed ) );
	}

	private function counts_line( $synced, $skipped, $failed, $remaining ) {
		return sprintf( 'Synced %d, skipped %d, failed %d; %d post(s) not processed.', $synced, $skipped, $failed, $remaining );
	}

	private function resolve_term( $value, $taxonomy ) {
		$value = trim( (string) $value );
		$term = null;

		if ( ctype_digit( $value ) ) {
			$term = get_term( (int) $value, $taxonomy );
		}
		if ( ! ( $term instanceof WP_Term ) ) {
			$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		}
		if ( ! ( $term instanceof WP_Term ) ) {
			WP_CLI::error( sprintf( '%s "%s" not found.', 'category' === $taxonomy ? 'Category' : 'Tag', $value ) );
		}

		return (int) $term->term_id;
	}
}

WP_CLI::add_command( 'site-chatbot', 'A8CSP_CWS_CLI_Command' );
