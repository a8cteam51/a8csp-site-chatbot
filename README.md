# A8CSP Site Chatbot

## Description

A8CSP Site Chatbot is a WordPress plugin that enables a chatbot interface for interacting with your site's content using AI-powered search and responses. It integrates with Pinecone for vector storage and OpenAI for embeddings and chat completions.

### Features
- **Settings Page**: Configure API keys for Pinecone and OpenAI.
- **Content Library**: Sync site content (posts, pages) to Pinecone for vector search.
- **Sandbox Chat**: Test the chatbot in an admin sandbox environment.
- **Frontend Block**: Soon...

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

## Usage

### Content Library
1. Go to **Chat with Site > Content Library**.
2. Filter by post type, category, or tag.
3. Select posts to sync and click "Sync Selected".
4. Synced posts will be marked as "In Pinecone" with a sync date.

### Sandbox Chat
1. Go to **Chat with Site** (main menu item).
2. Type a message and send to chat with the bot.
3. The bot uses site content from Pinecone to generate responses.
4. Clear chat history if needed.

## Troubleshooting
TBD

## Development
The plugin is structured with includes for settings, content library, sandbox, and API helpers.

For support, contact the developer team or open an issue.
