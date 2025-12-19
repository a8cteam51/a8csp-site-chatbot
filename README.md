# A8CSP Site Chatbot

## Description

A8CSP Site Chatbot is a WordPress plugin that enables a chatbot interface for interacting with your site's content using AI-powered search and responses. It integrates with Pinecone for vector storage and OpenAI for embeddings and chat completions.

### Features
- **Settings Page**: Configure API keys for Pinecone and OpenAI.
- **Content Library**: Sync site content (posts, pages) to Pinecone for vector search.
- **Frontend Block**: A WordPress block for the chat component.

## Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- Accounts with Pinecone and OpenAI for API keys

## Installation
1. Upload the plugin files to the `/wp-content/plugins/a8csp-site-chatbot/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to the plugin's Settings page to configure API keys.

## Configuration
1. Go to **Chat with Site > Settings** in the WordPress admin.
2. Enter your Pinecone API Key, Server URL, and optional Namespace.
3. Enter your OpenAI API Key, optional Organization ID, and select models for chat and embeddings.
4. Save changes.

**Note**: Ensure your Pinecone index has 1536 dimensions to match the default embedding model.

### Content Library
To vectorize your data, you can use the Content Library option from the right-panel navigation:
1. Go to **Chat with Site > Content Library**.
2. Filter by post type, category, or tag.
3. Select posts to sync and click "Sync Selected".
4. Synced posts will be marked as "In Pinecone" with a sync date.

## Frequently Asked Questions

### How do I add the chatbot to my site?
1. Open the Page or Post where you want the chatbot to appear in the WordPress Block Editor.
2. Click the **+** button to add a new block.
3. Search for "**A8CSP Site Chatbot**" or find it under the **Widgets** category.
4. Insert the block where you want the chat interface to appear.
5. Publish or update your page.

### Can I customize the chatbot's appearance?
Yes! When you select the chatbot block in the editor, you'll see a **Chatbot Colors** panel in the right sidebar. Use the color picker to set a **Primary Color** that matches your site's design. This color will be applied to buttons and accent elements in the chat interface.

### What content does the chatbot know about?
The chatbot only knows about content you've explicitly synced to Pinecone via the **Content Library**. If a post or page hasn't been synced, the chatbot won't be able to answer questions about it.

### How do I make sure the chatbot can answer questions about my content?
1. Sync your important posts and pages using the Content Library (see above).
2. Make sure the content is well-written and contains the information you want the chatbot to reference.
3. Re-sync content whenever you make significant updates to keep the chatbot's knowledge current.

### Why isn't the chatbot responding or giving good answers?
- **Check your synced content**: Make sure relevant posts/pages are synced in the Content Library.
- **Verify API configuration**: Ensure your API keys are correctly configured in Settings.
- **Review content quality**: The chatbot can only work with the information in your synced content.

### Can I use the chatbot on multiple pages?
Yes, you can add the chatbot block to as many pages or posts as you like. Each instance will have access to the same synced content.

### Does the chatbot work with custom post types?
Yes, the Content Library allows you to filter and sync content from different post types available on your site.

### How can I customize the chatbot's tone, personality, or behavior?
Use the **Custom Prompt** field in **Chat with Site > Settings** under the "Chatbot Configuration" section. This powerful feature lets you personalize many aspects of the chatbot:

- **Tone & Personality**: Make the bot formal, casual, friendly, or match your brand voice (e.g., "Respond in a warm, conversational tone like a helpful friend").
- **Language**: Instruct the bot to respond in a specific language (e.g., "Always respond in Spanish" or "Reply in the same language the user writes in").
- **Link Formatting**: Control how content is hyperlinked (e.g., "Include relevant links at the end of each response" or "Embed links naturally within the text").
- **Response Style**: Set preferences for length, structure, or format (e.g., "Keep responses under 3 paragraphs" or "Use bullet points when listing multiple items").
- **Expertise Focus**: Direct the bot to emphasize certain topics or adopt a specific persona (e.g., "You are a product specialist for our e-commerce store").

The custom prompt is combined with the default system instructions, so you only need to specify what you want to change or add.

### Is the chatbot secure? Can users hack it or access sensitive data?
The chatbot is designed with multiple layers of security:

- **No Database Access**: The chatbot cannot access your WordPress database, files, or server resources. It only works with content you've explicitly synced to the vector database.
- **Published Content Only**: Only publicly published posts and pages can be synced and referenced. Draft, private, or password-protected content is never accessible.
- **Input Validation**: All user inputs are sanitized and validated before processing to prevent injection attacks.
- **Rate Limiting**: Built-in rate limiting prevents abuse (7 requests per minute per visitor).
- **Prompt Injection Protection**: The system includes safety instructions that prevent users from manipulating the bot into ignoring its guidelines or executing harmful instructions.
- **Content Length Limits**: Response and context lengths are capped to prevent resource exhaustion.

The chatbot is a read-only interface to your synced content—it cannot modify your site, access wp-config, read sensitive files, or perform any administrative actions.

## Troubleshooting
TBD

## Development
The plugin is structured with includes for settings, content library, and API helpers.

For support, contact the developer team or open an issue.
