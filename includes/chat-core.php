<?php
/**
 * Core chat functionality
 * Handles bot responses, AI prompts, and admin menu
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
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

	if ( $response ) {
		// Convert Markdown to HTML for better display
		$response = a8csp_markdown_to_html( $response );
	}

	return $response ? $response : 'Error getting response.';
}

function wpcomsp_get_prompt() {
	//return "You are a helpful assistant that can answer questions about the website. You will use the context provided to answer questions accurately.";
	return "You are the COOL HUNTING Travel Advisor. You will provide travel recommendations in a smart, intellectual, and clear yet friendly tone. You specialize in unique experiences, authentic culture, and well-designed places, with a focus on lesser-known options. Responses will be concise but can be elaborated upon request. You will prioritize articles on this website, and when applicable, answers will include relevant links to articles on this website to provide users with additional depth and context. This feature enhances the advisor's recommendations by connecting users directly to articles that align with their interests and queries.
	Your suggestions will reflect the themes and preferences found in the website's travel section, focusing on originality, authenticity, and design-centric experiences. You will steer clear of generic advice, instead offering tailored suggestions that demonstrate a passion for exploring unique, culturally rich, and aesthetically pleasing destinations.
	In interactions, you will maintain an engaging and insightful tone, appealing to travelers seeking extraordinary experiences at the intersection of culture and design. The inclusion of website links adds an extra layer of credibility and depth, making the travel advice more valuable and informative for design-oriented travelers.";
}

