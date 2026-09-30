<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Resolve the embedding model currently in use, based on the configured provider.
 */
function a8csp_cws_current_embedding_model() {
	$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'openai';
	switch ( $provider ) {
		case 'google':
			return defined('GOOGLE_EMBEDDING_MODEL') ? GOOGLE_EMBEDDING_MODEL : '';
		case 'anthropic':
			return defined('VOYAGE_EMBEDDING_MODEL') ? VOYAGE_EMBEDDING_MODEL : '';
		case 'openai':
		default:
			return defined('OPENAI_EMBEDDING_MODEL') ? OPENAI_EMBEDDING_MODEL : '';
	}
}

/**
 * Build a fingerprint describing where (and with what) a post would be synced
 * if synced right now. Stored alongside each synced post so we can detect when
 * the user has changed provider, embedding model, or Pinecone index/namespace
 * since the last sync, and surface that as "stale" in the UI.
 */
function a8csp_cws_current_sync_fingerprint( $options = null ) {
	if ( is_array( $options ) ) {
		return a8csp_cws_sync_fingerprint_from_options( $options );
	}

	return array(
		'provider'        => defined('AI_PROVIDER') ? AI_PROVIDER : '',
		'embedding_model' => a8csp_cws_current_embedding_model(),
		'pinecone_url'    => defined('PINECONE_SERVER_URL') ? PINECONE_SERVER_URL : '',
		'pinecone_ns'     => defined('PINECONE_NAMESPACE') ? PINECONE_NAMESPACE : '',
	);
}

/**
 * The sync fingerprint for a stored settings array, resolved the same way the plugin's constants are.
 */
function a8csp_cws_sync_fingerprint_from_options( array $options ) {
	$provider = isset( $options['ai_provider'] ) ? $options['ai_provider'] : 'openai';
	$model_fields = array(
		'google' => 'google_embedding_model',
		'anthropic' => 'voyage_embedding_model',
	);
	$model_field = isset( $model_fields[ $provider ] ) ? $model_fields[ $provider ] : 'openai_embedding_model';

	$model = null;
	if ( isset( $options[ $model_field ] ) ) {
		$model = $options[ $model_field ];
	} else {
		foreach ( a8csp_cws_get_ai_providers() as $provider_config ) {
			if ( isset( $provider_config['fields'][ $model_field ] ) ) {
				$model = $provider_config['fields'][ $model_field ]['default'] ?? '';
				break;
			}
		}
	}

	return array(
		'provider'        => $provider,
		'embedding_model' => null === $model ? '' : $model,
		'pinecone_url'    => $options['pinecone_server_url'] ?? '',
		'pinecone_ns'     => $options['pinecone_namespace'] ?? '',
	);
}

/**
 * Whether a post may be sent to the embedding provider: published and not password-protected.
 */
function a8csp_cws_post_is_syncable( $post_id ) {
	return 'publish' === get_post_status( $post_id ) && '' === (string) get_post_field( 'post_password', $post_id, 'raw' );
}

/**
 * Compare a post's stored sync fingerprint to the current configuration.
 * Returns one of: 'synced', 'stale', 'missing'. When 'stale', $reason is
 * populated with a short human-readable explanation.
 */
function a8csp_cws_get_sync_state($post_id, $current_fp, &$reason = '') {
	$reason = '';
	$sync_flag = get_post_meta( $post_id, '_pinecone_synced', true );

	if ( $sync_flag !== 'synced' ) {
		return 'missing';
	}

	$stored_fp = get_post_meta( $post_id, '_pinecone_sync_fingerprint', true );

	if ( ! is_array( $stored_fp ) || empty( $stored_fp ) ) {
		$reason = 'synced before destination tracking was added';
		return 'stale';
	}

	$diffs = array();
	if ( ( $stored_fp['provider'] ?? '' ) !== ( $current_fp['provider'] ?? '' ) ) {
		$diffs[] = 'provider';
	}
	if ( ( $stored_fp['embedding_model'] ?? '' ) !== ( $current_fp['embedding_model'] ?? '' ) ) {
		$diffs[] = 'embedding model';
	}
	if ( ( $stored_fp['pinecone_url'] ?? '' ) !== ( $current_fp['pinecone_url'] ?? '' ) ) {
		$diffs[] = 'Pinecone index';
	}
	if ( ( $stored_fp['pinecone_ns'] ?? '' ) !== ( $current_fp['pinecone_ns'] ?? '' ) ) {
		$diffs[] = 'Pinecone namespace';
	}

	if ( empty( $diffs ) ) {
		return 'synced';
	}

	$reason = 'synced to a different ' . implode( ' / ', $diffs );
	return 'stale';
}

function a8csp_cws_get_post_content_as_text($post) {
	// Security: Validate post object
	if ( ! $post || ! is_object( $post ) || empty( $post->post_content ) ) {
		return '';
	}

	// Get the post content
	$content = $post->post_content;
	
	// Security: Limit content length to prevent resource exhaustion
	// TODO: Have a summary of the content instead of the full content.
	$max_content_length = 50000; // 50KB limit
	if ( strlen( $content ) > $max_content_length ) {
		$content = substr( $content, 0, $max_content_length );
	}
	
	// Apply WordPress content filters (shortcodes, etc.)
	$content = apply_filters('the_content', $content);
	
	// Security: Use WordPress wp_kses for safe HTML sanitization
	// Define allowed HTML tags for content processing (very restrictive for AI context)
	$allowed_html = array(
		'p' => array(),
		'br' => array(),
		'strong' => array(),
		'b' => array(),
		'em' => array(),
		'i' => array(),
		'h1' => array(),
		'h2' => array(),
		'h3' => array(),
		'h4' => array(),
		'h5' => array(),
		'h6' => array(),
		'ul' => array(),
		'ol' => array(),
		'li' => array(),
		'blockquote' => array(),
		// Note: No script, style, img, video, audio, iframe, object, embed tags allowed
	);
	
	// Security: Sanitize with wp_kses - removes all dangerous HTML
	$content = wp_kses( $content, $allowed_html );
	
	// Strip remaining HTML tags to get plain text for AI context
	$content = strip_tags( $content );
	
	// Clean up whitespace
	$content = preg_replace( '/\s+/', ' ', $content );
	$content = trim( $content );
	
	// Security: Validate title
	$title = ! empty( $post->post_title ) ? sanitize_text_field( $post->post_title ) : '';
	
	// Combine title and content for better context
	$full_content = $title . "\n\n" . $content;
	
	// Security: Final length check
	if ( strlen( $full_content ) > $max_content_length ) {
		$full_content = substr( $full_content, 0, $max_content_length );
	}
	
	return $full_content;
}

/**
 * Helper function to render WordPress native pagination
 */
function a8csp_cws_render_pagination($posts_query, $post_type, $category, $tag, $paged, $position = 'bottom') {
	if ($posts_query->max_num_pages <= 1) {
		return;
	}
	
	// Build query args to preserve filters
	$pagination_args = array(
		'page' => 'chat-with-site-sync',
		'content_type' => $post_type,
	);
	
	// Add category and tag filters only for post type
	if ($post_type === 'post') {
		if ($category > 0) {
			$pagination_args['category'] = $category;
		}
		if ($tag > 0) {
			$pagination_args['tag'] = $tag;
		}
	}
	
	// Build base URL with preserved parameters
	$base_url = add_query_arg($pagination_args, admin_url('admin.php'));
	$base_url = add_query_arg('paged', '%#%', $base_url);
	
	// WordPress native tablenav structure
	echo '<div class="tablenav ' . esc_attr($position) . '">';
	echo '<div class="alignleft actions bulkactions">';
	// Bulk actions would go here if needed
	echo '</div>';
	echo '<div class="tablenav-pages">';
	echo '<span class="displaying-num">' . 
		 sprintf(_n('%s item', '%s items', $posts_query->found_posts), number_format_i18n($posts_query->found_posts)) . 
		 '</span>';
	
	echo paginate_links(array(
		'base' => $base_url,
		'format' => '',
		'current' => max(1, $paged),
		'total' => $posts_query->max_num_pages,
		'type' => 'plain',
		'prev_text' => '<span class="button">&laquo;</span>',
		'next_text' => '<span class="button">&raquo;</span>',
		'before_page_number' => '<span class="screen-reader-text">Page </span>',
		'mid_size' => 2,
		'end_size' => 1,
	));
	echo '</div>';
	echo '<br class="clear" />';
	echo '</div>';
}

/**
 * Build the Pinecone metadata stored alongside a post's vector.
 */
function a8csp_cws_build_post_vector_metadata( $post, $sync_fingerprint ) {
	$post_id = (int) $post->ID;

	$metadata = array(
		'post_id' => (string) $post_id,
		'post_title' => $post->post_title,
		'post_url' => get_permalink( $post_id ),
		'post_type' => $post->post_type,
		'provider' => $sync_fingerprint['provider'],
		'model' => $sync_fingerprint['embedding_model'],
	);

	$categories = get_the_category( $post_id );
	if ( ! empty( $categories ) ) {
		$category_names = array();
		$category_ids = array();
		foreach ( $categories as $category ) {
			$category_names[] = $category->name;
			$category_ids[] = (string) $category->term_id;
		}
		$metadata['categories'] = implode( ', ', $category_names );
		$metadata['category_ids'] = implode( ', ', $category_ids );
	}

	$tags = get_the_tags( $post_id );
	if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
		$tag_names = array();
		$tag_ids = array();
		foreach ( $tags as $tag ) {
			$tag_names[] = $tag->name;
			$tag_ids[] = (string) $tag->term_id;
		}
		$metadata['tags'] = implode( ', ', $tag_names );
		$metadata['tag_ids'] = implode( ', ', $tag_ids );
	}

	return $metadata;
}

/**
 * Embed and upsert a set of posts with one batched embedding call and one batched Pinecone upsert.
 */
function a8csp_cws_sync_posts( array $post_ids, array $args = array() ) {
	$max_retries = isset( $args['max_retries'] ) ? max( 0, (int) $args['max_retries'] ) : 2;

	$result = array(
		'synced' => array(),
		'failed' => array(),
		'pending' => array(),
		'fatal' => null,
	);

	$ids = array();
	foreach ( $post_ids as $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 ) {
			$result['failed'][ $post_id ] = "Invalid post ID: {$post_id}";
			continue;
		}
		if ( ! in_array( $post_id, $ids, true ) ) {
			$ids[] = $post_id;
		}
	}

	if ( empty( $ids ) ) {
		return $result;
	}

	_prime_post_caches( $ids, true, true );

	$sync_fingerprint = a8csp_cws_current_sync_fingerprint();
	$posts = array();
	$texts = array();

	foreach ( $ids as $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			$result['failed'][ $post_id ] = "Post ID {$post_id} not found";
			continue;
		}

		if ( '' !== (string) $post->post_password ) {
			$result['failed'][ $post_id ] = "Post '{$post->post_title}' is password-protected, so it is not synced";
			continue;
		}

		try {
			$post_content = a8csp_cws_get_post_content_as_text( $post );
		} catch ( Throwable $e ) {
			$result['failed'][ $post_id ] = "Exception for '{$post->post_title}': " . $e->getMessage();
			continue;
		}

		if ( empty( $post_content ) ) {
			$result['failed'][ $post_id ] = "Post '{$post->post_title}' has empty content";
			continue;
		}

		$posts[ $post_id ] = $post;
		$texts[ $post_id ] = $post_content;
	}

	$pending = array();
	$embedding_fatal = null;
	$upsert_fatal = null;

	if ( ! empty( $texts ) ) {
		$embedded = a8csp_cws_get_embeddings_batch( $texts, array( 'max_retries' => $max_retries ) );
		$embeddings = $embedded['embeddings'];
		$embedding_errors = $embedded['errors'];
		$embedding_pending = $embedded['pending'];
		$embedding_fatal = $embedded['fatal'];

		foreach ( $embedding_pending as $post_id ) {
			$pending[ (int) $post_id ] = true;
		}

		$vectors = array();
		foreach ( $posts as $post_id => $post ) {
			if ( isset( $pending[ $post_id ] ) ) {
				continue;
			}
			if ( isset( $embedding_errors[ $post_id ] ) ) {
				$result['failed'][ $post_id ] = "Failed to generate embedding for '{$post->post_title}': " . $embedding_errors[ $post_id ]->get_error_message();
				continue;
			}
			if ( empty( $embeddings[ $post_id ] ) || ! is_array( $embeddings[ $post_id ] ) ) {
				$result['failed'][ $post_id ] = "Failed to generate embedding for '{$post->post_title}'";
				continue;
			}

			try {
				$metadata = a8csp_cws_build_post_vector_metadata( $post, $sync_fingerprint );
			} catch ( Throwable $e ) {
				$result['failed'][ $post_id ] = "Exception for '{$post->post_title}': " . $e->getMessage();
				continue;
			}

			$vectors[] = array(
				'id' => (string) $post_id,
				'values' => $embeddings[ $post_id ],
				'metadata' => $metadata,
			);
		}

		if ( ! empty( $vectors ) ) {
			$upsert = a8csp_cws_upsert_vectors_to_pinecone( $vectors, array( 'max_retries' => $max_retries ) );
			$upserted = $upsert['upserted'];
			$upsert_errors = $upsert['errors'];
			$upsert_pending = $upsert['pending'];
			$upsert_fatal = $upsert['fatal'];

			$upserted_ids = array();
			foreach ( $upserted as $vector_id ) {
				$upserted_ids[ (int) $vector_id ] = true;
			}
			foreach ( $upsert_pending as $vector_id ) {
				$pending[ (int) $vector_id ] = true;
			}

			$sync_date = current_time( 'mysql' );
			foreach ( $vectors as $vector ) {
				$post_id = (int) $vector['id'];
				$title = $posts[ $post_id ]->post_title;

				if ( isset( $upserted_ids[ $post_id ] ) ) {
					// Record the destination so drift can be detected later.
					update_post_meta( $post_id, '_pinecone_synced', 'synced' );
					update_post_meta( $post_id, '_pinecone_sync_date', $sync_date );
					update_post_meta( $post_id, '_pinecone_sync_fingerprint', $sync_fingerprint );
					$result['synced'][] = $post_id;
				} elseif ( isset( $pending[ $post_id ] ) ) {
					continue;
				} else {
					$result['failed'][ $post_id ] = "Pinecone error for '{$title}': " . $upsert_errors[ $vector['id'] ]->get_error_message();
				}
			}
		}
	}

	foreach ( $ids as $post_id ) {
		if ( isset( $pending[ $post_id ] ) && ! isset( $result['failed'][ $post_id ] ) && ! in_array( $post_id, $result['synced'], true ) ) {
			$result['pending'][] = $post_id;
		}
	}

	if ( ! empty( $result['pending'] ) ) {
		if ( is_wp_error( $embedding_fatal ) && is_wp_error( $upsert_fatal ) ) {
			// A permanent failure must win, or a job would keep retrying something that cannot succeed.
			$result['fatal'] = a8csp_cws_is_retryable_error( $embedding_fatal ) ? $upsert_fatal : $embedding_fatal;
		} elseif ( is_wp_error( $embedding_fatal ) ) {
			$result['fatal'] = $embedding_fatal;
		} elseif ( is_wp_error( $upsert_fatal ) ) {
			$result['fatal'] = $upsert_fatal;
		} else {
			$result['fatal'] = new WP_Error( 'a8csp_invalid_response', 'The sync stopped before all posts were processed.', array( 'status' => 0, 'provider' => '', 'retryable' => true ) );
		}
	}

	return $result;
}

function a8csp_cws_bulk_sync_posts($post_ids) {
	// Security: Limit batch size to prevent resource exhaustion
	$max_batch_size = 50;
	if ( count( $post_ids ) > $max_batch_size ) {
		return array(
			'success_count' => 0,
			'error_count' => 1,
			'successful_posts' => array(),
			'errors' => array( "Batch size limited to {$max_batch_size} posts for security. Please sync in smaller batches." ),
		);
	}

	$results = array(
		'success_count' => 0,
		'error_count' => 0,
		'successful_posts' => array(),
		'errors' => array(),
	);

	try {
		$sync = a8csp_cws_sync_posts( $post_ids );
	} catch ( Throwable $e ) {
		$results['error_count'] += count( $post_ids );
		$results['errors'][] = 'Sync failed: ' . $e->getMessage();
		return $results;
	}

	$results['success_count'] = count( $sync['synced'] );
	$results['successful_posts'] = $sync['synced'];

	foreach ( $sync['failed'] as $message ) {
		$results['error_count']++;
		$results['errors'][] = $message;
	}

	if ( ! empty( $sync['pending'] ) ) {
		$pending_count = count( $sync['pending'] );
		$reason = is_wp_error( $sync['fatal'] ) ? $sync['fatal']->get_error_message() : 'The sync stopped early.';
		if ( a8csp_cws_is_retryable_error( $sync['fatal'] ) ) {
			$reason = rtrim( $reason, '. ' ) . '. Try again later, or use the background sync, which retries on its own.';
		}
		$results['error_count'] += $pending_count;
		$results['errors'][] = sprintf( '%d post(s) were not synced: %s', $pending_count, $reason );
	}

	return $results;
}

function a8csp_cws_sync_page() {
	$api_settings = a8csp_cws_get_api_settings();
	$missing = a8csp_cws_check_required_settings($api_settings);
	
	// Handle form submission (bulk sync)
	if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sync_posts'])) {
		// Security: Verify nonce for CSRF protection
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'a8csp_cws_bulk_sync' ) ) {
			wp_die( __( 'Security check failed. Please try again.', 'a8csp-site-chatbot' ), 403 );
		}

		// Security: Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'a8csp-site-chatbot' ), 403 );
		}

		if (!empty($missing)) {
			echo '<div class="notice notice-error"><p>Cannot sync: Required settings are missing. Please configure the plugin settings.</p></div>';
		} else {
			// Security: Validate and sanitize post IDs
			$post_ids = array();
			if ( isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ) {
				foreach ( $_POST['post_ids'] as $post_id ) {
					$post_id = intval( $post_id );
					if ( $post_id > 0 && get_post( $post_id ) ) {
						$post_ids[] = $post_id;
					}
				}
			}
			
			if (empty($post_ids)) {
				echo '<div class="notice notice-error"><p>No valid posts selected for sync.</p></div>';
			} else {
				$sync_results = a8csp_cws_bulk_sync_posts($post_ids);
				
				if ($sync_results['success_count'] > 0) {
					echo '<div class="notice notice-success"><p>';
					echo 'Successfully synced ' . $sync_results['success_count'] . ' post(s) to Pinecone.';
					if (!empty($sync_results['successful_posts'])) {
						echo '<br>Synced posts: ' . implode(', ', array_map('get_the_title', $sync_results['successful_posts']));
					}
					echo '</p></div>';
				}
				
				if ($sync_results['error_count'] > 0) {
					echo '<div class="notice notice-error"><p>';
					echo 'Failed to sync ' . $sync_results['error_count'] . ' post(s).';
					if (!empty($sync_results['errors'])) {
						echo '<br>Errors:<ul>';
						foreach ($sync_results['errors'] as $error) {
							echo '<li>' . esc_html($error) . '</li>';
						}
						echo '</ul>';
					}
					echo '</p></div>';
				}
			}
		}
	}

	// Security: Validate and sanitize filter values
	$post_type = 'post'; // Default
	if ( isset( $_GET['content_type'] ) ) {
		$requested_type = sanitize_text_field( $_GET['content_type'] );
		// Security: Only allow public post types
		$allowed_types = get_post_types( array( 'public' => true ), 'names' );
		if ( in_array( $requested_type, $allowed_types, true ) ) {
			$post_type = $requested_type;
		}
	}

	$category = 0;
	if ( isset( $_GET['category'] ) ) {
		$category = intval( $_GET['category'] );
		// Security: Validate category exists
		if ( $category > 0 && ! term_exists( $category, 'category' ) ) {
			$category = 0;
		}
	}

	$tag = 0;
	if ( isset( $_GET['tag'] ) ) {
		$tag = intval( $_GET['tag'] );
		// Security: Validate tag exists
		if ( $tag > 0 && ! term_exists( $tag, 'post_tag' ) ) {
			$tag = 0;
		}
	}

	$paged = 1;
	if ( isset( $_GET['paged'] ) ) {
		$paged = intval( $_GET['paged'] );
		// Security: Ensure positive page number
		$paged = max( 1, $paged );
	}

	// Security: Build query args with validated inputs
	$args = array(
		'post_type' => $post_type,
		'posts_per_page' => 20,
		'post_status' => 'publish', // Only public posts
		'has_password' => false,
		'paged' => $paged,
		'no_found_rows' => false, // Need for pagination
	);

	// Security: Only add filters for 'post' type to prevent taxonomy confusion
	if ( $post_type === 'post' ) {
		if ( $category > 0 ) {
			$args['cat'] = $category;
		}
		if ( $tag > 0 ) {
			$args['tag_id'] = $tag;
		}
	}

	$posts_query = new WP_Query( $args );
	$posts = $posts_query->posts;

	// Get post types, categories, tags for filters
	$post_types = get_post_types(['public' => true], 'names');
	$categories = get_categories();
	$tags = get_tags();

?>
	<div class="wrap">
		<h1>Content Library</h1>

		<div class="content-library-filters">
			<form method="get" action="<?php echo admin_url('admin.php'); ?>">
				<input type="hidden" name="page" value="chat-with-site-sync">
				<label for="content_type">Post Type:</label>
				<select name="content_type" id="content_type">
					<?php foreach ($post_types as $pt) : ?>
						<option value="<?php echo esc_attr($pt); ?>" <?php selected($post_type, $pt); ?>><?php echo esc_html($pt); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ($post_type === 'post') : ?>
					<label for="category">Category:</label>
					<select name="category" id="category">
						<option value="0">All Categories</option>
						<?php foreach ($categories as $cat) : ?>
							<option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($category, $cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="tag">Tag:</label>
					<select name="tag" id="tag">
						<option value="0">All Tags</option>
						<?php foreach ($tags as $t) : ?>
							<option value="<?php echo esc_attr($t->term_id); ?>" <?php selected($tag, $t->term_id); ?>><?php echo esc_html($t->name); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<button type="submit" class="button">Filter</button>
			</form>
		</div>
		<?php a8csp_cws_render_sync_job_panel( $post_type, $category, $tag, (int) $posts_query->found_posts ); ?>
		<form method="post">
			<?php 
			// Security: Add nonce field for CSRF protection
			wp_nonce_field( 'a8csp_cws_bulk_sync' );
			?>
			<?php if (empty($posts)) : ?>
				<p>No posts found for the selected filters.</p>
			<?php else : ?>
				<?php
				// Top tablenav (WordPress standard)
				a8csp_cws_render_pagination($posts_query, $post_type, $category, $tag, $paged, 'top');
				?>
				<div class="content-library-table-wrapper">
					<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><input type="checkbox" id="select-all" onclick="document.querySelectorAll('input[name=\'post_ids[]\']').forEach(cb => cb.checked = this.checked);"></th>
							<th>Title</th>
							<th>Post Type</th>
							<th>Pinecone Status</th>
							<th>Date</th>
						</tr>
					</thead>
					<tbody>
						<?php
						$current_fp = a8csp_cws_current_sync_fingerprint();
						foreach ($posts as $post) :
							$stale_reason = '';
							$sync_state = a8csp_cws_get_sync_state($post->ID, $current_fp, $stale_reason);
							$sync_date = get_post_meta($post->ID, '_pinecone_sync_date', true);
						?>
							<tr>
								<td><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr($post->ID); ?>"></td>
								<td><?php echo esc_html($post->post_title); ?></td>
								<td><?php echo esc_html($post->post_type); ?></td>
								<td>
									<?php if ($sync_state === 'synced') : ?>
										<span style="color: #46b450;">
											<span class="dashicons dashicons-yes-alt"></span>
											In Pinecone
										</span>
										<?php if ($sync_date) :
											$formatted_date = date('M j, Y g:i A', strtotime($sync_date));
										?>
											<br><small style="color: #666;">Synced: <?php echo esc_html($formatted_date); ?></small>
										<?php endif; ?>
									<?php elseif ($sync_state === 'stale') : ?>
										<span style="color: #d63638;">
											<span class="dashicons dashicons-warning"></span>
											Stale &mdash; re-sync needed
										</span>
										<br><small style="color: #666;"><?php echo esc_html( ucfirst( $stale_reason ) ); ?></small>
										<?php if ($sync_date) :
											$formatted_date = date('M j, Y g:i A', strtotime($sync_date));
										?>
											<br><small style="color: #666;">Last synced: <?php echo esc_html($formatted_date); ?></small>
										<?php endif; ?>
									<?php else : ?>
										<span style="color: #dc3232;">
											<span class="dashicons dashicons-dismiss"></span>
											Not in Pinecone
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html($post->post_date); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				// Bottom tablenav (WordPress standard)
				a8csp_cws_render_pagination($posts_query, $post_type, $category, $tag, $paged, 'bottom');
				?>
				</div>
			<?php endif; ?>
			<div class="content-library-actions">
				<button type="submit" name="sync_posts" class="button button-primary">Sync Selected</button>
			</div>
		</form>
	</div>
<?php
}
