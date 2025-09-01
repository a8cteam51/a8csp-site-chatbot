<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

function chat_with_site_get_post_content_as_text($post) {
	// Get the post content
	$content = $post->post_content;
	
	// Apply WordPress content filters (shortcodes, etc.)
	$content = apply_filters('the_content', $content);
	
	// Remove images and other media
	$content = preg_replace('/<img[^>]*>/i', '', $content);
	$content = preg_replace('/<video[^>]*>.*?<\/video>/is', '', $content);
	$content = preg_replace('/<audio[^>]*>.*?<\/audio>/is', '', $content);
	$content = preg_replace('/<iframe[^>]*>.*?<\/iframe>/is', '', $content);
	
	// Strip all HTML tags
	$content = strip_tags($content);
	
	// Clean up whitespace
	$content = preg_replace('/\s+/', ' ', $content);
	$content = trim($content);
	
	// Combine title and content for better context
	$full_content = $post->post_title . "\n\n" . $content;
	
	return $full_content;
}

function chat_with_site_bulk_sync_posts($post_ids) {
	$results = array(
		'success_count' => 0,
		'error_count' => 0,
		'successful_posts' => array(),
		'errors' => array(),
	);
	
	foreach ($post_ids as $post_id) {
		$post = get_post($post_id);
		
		if (!$post) {
			$results['error_count']++;
			$results['errors'][] = "Post ID {$post_id} not found";
			continue;
		}
		
		try {
			// Step 1: Get post content as plain text
			$post_content = chat_with_site_get_post_content_as_text($post);
			
			if (empty($post_content)) {
				$results['error_count']++;
				$results['errors'][] = "Post '{$post->post_title}' has empty content";
				continue;
			}
			
			// Step 2: Generate embedding via OpenAI
			$embedding = chat_with_site_vectorize_content($post_content);
			
			if (empty($embedding)) {
				$results['error_count']++;
				$results['errors'][] = "Failed to generate embedding for '{$post->post_title}'";
				continue;
			}
			
			// Step 3: Prepare metadata
			$metadata = array(
				'post_id' => (string)$post_id,
				'post_title' => $post->post_title,
				'post_url' => get_permalink($post_id),
				'post_type' => $post->post_type,
				'model' => OPENAI_EMBEDDING_MODEL,
			);
			
			// Add categories
			$categories = get_the_category($post_id);
			if (!empty($categories)) {
				$category_names = array();
				$category_ids = array();
				foreach ($categories as $category) {
					$category_names[] = $category->name;
					$category_ids[] = (string)$category->term_id;
				}
				$metadata['categories'] = implode(', ', $category_names);
				$metadata['category_ids'] = implode(', ', $category_ids);
			}
			
			// Add tags
			$tags = get_the_tags($post_id);
			if (!empty($tags)) {
				$tag_names = array();
				$tag_ids = array();
				foreach ($tags as $tag) {
					$tag_names[] = $tag->name;
					$tag_ids[] = (string)$tag->term_id;
				}
				$metadata['tags'] = implode(', ', $tag_names);
				$metadata['tag_ids'] = implode(', ', $tag_ids);
			}
			
			// Step 4: Upsert to Pinecone
			$result = chat_with_site_upsert_to_pinecone($post_id, $embedding, $metadata);
			
			if (is_wp_error($result)) {
				$results['error_count']++;
				$results['errors'][] = "Pinecone error for '{$post->post_title}': " . $result->get_error_message();
				continue;
			}
			
			// Mark as synced
			update_post_meta($post_id, '_pinecone_synced', 'synced');
			update_post_meta($post_id, '_pinecone_sync_date', current_time('mysql'));
			
			$results['success_count']++;
			$results['successful_posts'][] = $post_id;
			
		} catch (Exception $e) {
			$results['error_count']++;
			$results['errors'][] = "Exception for '{$post->post_title}': " . $e->getMessage();
		}
	}
	
	return $results;
}

function chat_with_site_sync_page() {
	$api_settings = chat_with_site_get_api_settings();
	$missing = chat_with_site_check_required_settings($api_settings);
	
	// Handle form submission (bulk sync)
	if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sync_posts'])) {
		if (!empty($missing)) {
			echo '<div class="notice notice-error"><p>Cannot sync: Required settings are missing. Please configure the plugin settings.</p></div>';
		} else {
			$post_ids = isset($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
			
			if (empty($post_ids)) {
				echo '<div class="notice notice-error"><p>No posts selected for sync.</p></div>';
			} else {
				$sync_results = chat_with_site_bulk_sync_posts($post_ids);
				
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

	// Get filter values
	$post_type = isset($_GET['content_type']) ? sanitize_text_field($_GET['content_type']) : 'post';
	$category = isset($_GET['category']) ? intval($_GET['category']) : 0;
	$tag = isset($_GET['tag']) ? intval($_GET['tag']) : 0;
	$paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;

	// Query posts
	$args = [
		'post_type' => $post_type,
		'posts_per_page' => 20,
		'post_status' => 'publish',
		'paged' => $paged,
	];
	if ($post_type === 'post') {
		if ($category) {
			$args['cat'] = $category;
		}
		if ($tag) {
			$args['tag_id'] = $tag;
		}
	}
	$posts_query = new WP_Query($args);
	$posts = $posts_query->posts;

	// Get post types, categories, tags for filters
	$post_types = get_post_types(['public' => true], 'names');
	$categories = get_categories();
	$tags = get_tags();

?>
<div class="wrap">
	<h1>Content Library</h1>

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
		<button type="submit">Filter</button>
	</form>
	<form method="post">
		<?php if (empty($posts)) : ?>
			<p>No posts found for the selected filters.</p>
		<?php else : ?>
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
					<?php foreach ($posts as $post) : 
						$sync_status = get_post_meta($post->ID, '_pinecone_synced', true);
						$sync_date = get_post_meta($post->ID, '_pinecone_sync_date', true);
						$is_synced = ($sync_status === 'synced');
					?>
						<tr>
							<td><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr($post->ID); ?>"></td>
							<td><?php echo esc_html($post->post_title); ?></td>
							<td><?php echo esc_html($post->post_type); ?></td>
							<td>
								<?php if ($is_synced) : ?>
									<span style="color: #46b450;">
										<span class="dashicons dashicons-yes-alt"></span>
										In Pinecone
									</span>
									<?php if ($sync_date) : 
										$formatted_date = date('M j, Y g:i A', strtotime($sync_date));
									?>
										<br><small style="color: #666;">Synced: <?php echo esc_html($formatted_date); ?></small>
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
			// Pagination
			$big = 999999999; // need an unlikely integer
			echo paginate_links(array(
				'base' => str_replace($big, '%#%', get_pagenum_link($big)),
				'format' => '?paged=%#%',
				'current' => max(1, $paged),
				'total' => $posts_query->max_num_pages,
				'type' => 'plain',
			));
			?>
		<?php endif; ?>
		<button type="submit" name="sync_posts">Sync Selected</button>
	</form>
</div>
<?php
}
