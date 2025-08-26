<?php
// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

function chat_with_site_admin_page() {
	?>
	<div class="wrap">
		<h1>Sandbox Chat</h1>
		<div id="chat-history" style="border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; max-height: 400px; overflow-y: scroll;">
			<?php
			if (isset($_SESSION['chat_history']) && !empty($_SESSION['chat_history'])) {
				foreach ($_SESSION['chat_history'] as $msg) {
					$class = $msg['role'] == 'user' ? 'user-message' : 'bot-message';
					echo '<div class="' . $class . '" style="margin-bottom: 10px;"><strong>' . ucfirst($msg['role']) . ':</strong> ' . esc_html($msg['content']) . '</div>';
				}
			} else {
				echo '<p>No chat history yet.</p>';
			}
			?>
		</div>
		<form method="post">
			<textarea name="message" rows="3" style="width: 100%;" placeholder="Type your message here..."></textarea>
			<button type="submit" name="clear">🚫 Clear Chat</button> | <button type="submit">💬 Send</button>
		</form>
	</div>
	<?php
}

function chat_with_site_start_session() {
	if (!session_id()) {
		session_start();
	}
}

function chat_with_site_handle_post() {
	if (isset($_GET['page']) && $_GET['page'] === 'chat-with-site' && $_SERVER['REQUEST_METHOD'] === 'POST') {
		if (isset($_POST['clear'])) {
			unset($_SESSION['chat_history']);
		} elseif (isset($_POST['message'])) {
			$message = sanitize_text_field($_POST['message']);
			if (!empty($message)) {
				if (!isset($_SESSION['chat_history'])) {
					$_SESSION['chat_history'] = [];
				}
				$_SESSION['chat_history'][] = ['role' => 'user', 'content' => $message];
				$response = get_bot_response($_SESSION['chat_history']);
				$_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $response];
			}
		}
		wp_redirect(admin_url('admin.php?page=chat-with-site'));
		exit;
	}
}

function get_bot_response($history) {
	// Rhe last message from the user.
	$query     = $history[count($history) - 1]['content'];
	$embedding = get_openai_embedding($query);

	if (empty($embedding)) {
		return 'Error getting embedding.';
	}
	$matches = query_pinecone($embedding);

	if (empty($matches)) {
		return 'No relevant context found.';
	}
	$context = '';
	//$debug = "\n\nDebug Metadata:\n";
	foreach ($matches as $match) {
		if (isset($match['metadata']['post_id'])) {
			$post_id = intval($match['metadata']['post_id']);
			$post = get_post($post_id);
			
			if ($post) {
				$post_title = isset($match['metadata']['post_title']) ? $match['metadata']['post_title'] : $post->post_title;
				$post_url = isset($match['metadata']['post_url']) ? $match['metadata']['post_url'] : get_permalink($post_id);
				
				$content = apply_filters('the_content', $post->post_content);
				$clean_content = strip_tags($content);
				
				$context .= "Post Title: " . $post_title . "\n";
				$context .= "Post URL: " . $post_url . "\n";
				$context .= "Content: " . $clean_content . "\n\n";
				
				// $debug .= "Title: " . $post_title . "\nURL: " . $post_url . "\n\n";
			}
		}
	}
	$system_message = wpcomsp_get_prompt() . "\n\nContext:\n" . $context;
	$messages = [
		['role' => 'system', 'content' => $system_message],
	];
	$messages = array_merge($messages, $history);
	$response = get_openai_completion($messages);
	// $response .= $debug;

	return $response ? $response : 'Error getting response.';
}

function wpcomsp_get_prompt() {
	//return "You are a helpful assistant that can answer questions about the website. You will use the context provided to answer questions accurately.";
	return "You are the COOL HUNTING Travel Advisor. You will provide travel recommendations in a smart, intellectual, and clear yet friendly tone. You specialize in unique experiences, authentic culture, and well-designed places, with a focus on lesser-known options. Responses will be concise but can be elaborated upon request. You will prioritize articles on this website, and when applicable, answers will include relevant links to articles on this website to provide users with additional depth and context. This feature enhances the advisor's recommendations by connecting users directly to articles that align with their interests and queries.
	Your suggestions will reflect the themes and preferences found in the website's travel section, focusing on originality, authenticity, and design-centric experiences. You will steer clear of generic advice, instead offering tailored suggestions that demonstrate a passion for exploring unique, culturally rich, and aesthetically pleasing destinations.
	In interactions, you will maintain an engaging and insightful tone, appealing to travelers seeking extraordinary experiences at the intersection of culture and design. The inclusion of website links adds an extra layer of credibility and depth, making the travel advice more valuable and informative for design-oriented travelers.";
}

function chat_with_site_admin_menu() {
	add_menu_page('Chat with Site', 'Chat with Site', 'manage_options', 'chat-with-site', 'chat_with_site_admin_page');
	add_submenu_page('chat-with-site', 'Content Library', 'Content Library', 'manage_options', 'chat-with-site-sync', 'chat_with_site_sync_page');
	add_submenu_page('chat-with-site', 'Settings', 'Settings', 'manage_options', 'chat-with-site-settings', 'chat_with_site_settings_page');
	
	
}
