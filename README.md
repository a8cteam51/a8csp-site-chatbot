# A8CSP Site Chatbot

## Description

A8CSP Site Chatbot is a WordPress plugin that enables a chatbot interface for interacting with your site's content using AI-powered search and responses. It integrates with Pinecone for vector storage and supports multiple AI providers — **OpenAI**, **Google (Gemini)**, and **Anthropic (Claude)** — for embeddings and chat completions, selectable per-site from the settings page.

### Features
- **Settings Page**: Pick an AI provider and configure its API keys, plus Pinecone credentials.
- **Content Library**: Sync site content (posts, pages) to Pinecone for vector search. Synced posts are tracked against the provider, model, and index they were synced to, so the UI can flag stale syncs after a configuration change.
- **Frontend Block**: A WordPress block for the chat component.

## Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- A Pinecone account for vector storage
- API access with **one** of the supported providers:
  - **OpenAI** — one API key (chat + embeddings)
  - **Google (Gemini)** — one API key (chat + embeddings)
  - **Anthropic (Claude)** — Anthropic API key for chat *plus* a Voyage AI API key for embeddings (Anthropic does not ship an embedding model; Voyage AI is their recommended partner and requires a credit card on file even on free credits)

## Installation
1. Upload the plugin files to the `/wp-content/plugins/a8csp-site-chatbot/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to the plugin's Settings page to configure API keys.

## Configuration

1. Go to **51 Chatbot > Settings** in the WordPress admin.
2. Under **AI Service**, pick your provider from the **AI Chat Provider** dropdown. The settings page hides fields that don't apply to your choice.
3. Fill in the provider-specific fields:
   - **OpenAI**: API key, (optional) Organization ID, Chat Model, Embedding Model.
   - **Google (Gemini)**: API key, Chat Model, Embedding Model.
   - **Anthropic (Claude)**: Anthropic API key + Chat Model, *and* Voyage AI API key + Voyage Embedding Model.
4. Under **Vector Database**, enter your Pinecone API Key and Server URL. Namespace is optional.
5. Save changes.

**Important — Pinecone index dimensions must match the embedding model.** The plugin sends embeddings to Pinecone at their native dimensionality; a mismatch causes Pinecone to reject the upsert with HTTP 400. Common dimensions:

| Embedding model | Dimensions |
|---|---|
| OpenAI `text-embedding-3-small` (default) | 1536 |
| OpenAI `text-embedding-3-large` | 3072 |
| OpenAI `text-embedding-ada-002` (legacy) | 1536 |
| Google `gemini-embedding-001` | 3072 |
| Google `text-embedding-004` (legacy) | 768 |
| Voyage `voyage-3-large` | 1024 |

If you change provider or embedding model, you will generally need a fresh Pinecone index sized to match. Existing vectors from a different model are not comparable to new ones.

### Content Library
To vectorize your data, you can use the Content Library option from the left-panel navigation:
1. Go to **51 Chatbot > Content Library**.
2. Filter by post type, category, or tag.
3. Select posts to sync and click "Sync Selected".

Each row shows a status badge:

- **In Pinecone** (green) — the post is synced and the current settings still match where it was synced to.
- **Stale — re-sync needed** (yellow) — the post is synced, but provider, embedding model, Pinecone index, or namespace has changed since. The reason for the drift is listed inline so you know what changed.
- **Not in Pinecone** (red) — the post has never been synced from this site.

Stale posts should be re-selected and re-synced after any provider/index/model change.

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
- **Check the Content Library status column**: posts marked "Stale" or "Not in Pinecone" will not be used in answers. Re-sync them.
- **Verify API configuration**: ensure your selected provider's keys *and* your Pinecone settings are filled in. Missing keys are flagged at the top of the Settings page.
- **Check the PHP error log** for `A8CSP:` prefixed messages — provider errors (rate limits, auth failures, dimension mismatches) are surfaced there with the HTTP status code.

### Which provider should I pick?
- **Gemini** has the lowest setup friction (one API key, generous free tier).
- **OpenAI** is the original default and the most broadly documented.
- **Claude** requires two accounts (Anthropic + Voyage) and a credit card on file at Voyage; pick it only if you specifically need Claude for chat.

Embedding quality is broadly comparable across providers for most site Q&A use cases. The differentiator is usually setup friction and pricing rather than retrieval quality.

### Can I switch providers later?
Yes, but switching providers (or changing the embedding model within a provider) means your existing vectors in Pinecone are no longer comparable to new ones. The plugin will mark all previously-synced posts as **Stale** after the change. The recommended workflow is:

1. Create a new Pinecone index sized for your new embedding model's dimensions.
2. Update Pinecone Server URL in plugin settings.
3. Change the AI provider / embedding model.
4. Bulk-resync all content in the Content Library.

### Can I use the chatbot on multiple pages?
Yes, you can add the chatbot block to as many pages or posts as you like. Each instance will have access to the same synced content.

### Does the chatbot work with custom post types?
Yes, the Content Library allows you to filter and sync content from different post types available on your site.

### How can I customize the chatbot's tone, personality, or behavior?
Use the **Custom Prompt** field in **51 Chatbot > Settings** under the "Chatbot Configuration" section. This powerful feature lets you personalize many aspects of the chatbot:

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
- **Rate Limiting**: Built-in rate limiting prevents abuse (7 requests per minute per visitor). The plugin also retries provider-side 429/503 responses with exponential backoff so transient overloads don't surface to visitors.
- **Prompt Injection Protection**: The system includes safety instructions that prevent users from manipulating the bot into ignoring its guidelines or executing harmful instructions.
- **Content Length Limits**: Response and context lengths are capped to prevent resource exhaustion.

The chatbot is a read-only interface to your synced content—it cannot modify your site, access wp-config, read sensitive files, or perform any administrative actions.

## Troubleshooting

### Pinecone returns HTTP 400 on sync
Dimension mismatch between the embedding model and the Pinecone index. Verify the index dimensions match the table in the Configuration section. You will need a fresh index sized for your model — Pinecone indexes cannot be resized in place.

### Voyage / provider returns HTTP 429 during bulk sync
Rate limit. The plugin will automatically wait and retry (honoring `Retry-After` when the provider sends it), but on tight free-tier limits you may want to sync in smaller batches. Voyage in particular has a low default RPM.

### Logs show "Missing OpenAI API configuration" but I'm not using OpenAI
You are on an old version of the plugin. Update to a release that includes the multi-provider dispatcher.

### Content Library shows posts as "Stale" after a configuration change
Expected behavior. The plugin tracks the provider, embedding model, Pinecone index, and namespace each post was synced against; when any of those change, previously-synced posts are flagged stale because their stored vectors aren't comparable to new ones. Re-sync to clear the warning.

## Development
The plugin is organized as:

- `a8csp-site-chatbot.php` — bootstrap, constants, hook registration.
- `includes/ai-providers.php` — provider registry; add new providers here.
- `includes/api-helpers.php` — provider dispatchers, per-provider call helpers, retry-with-backoff wrapper, Pinecone helpers.
- `includes/settings.php` — settings page, registry-driven field rendering, validation.
- `includes/content-library.php` — bulk sync, sync-fingerprint tracking, Content Library admin page.
- `includes/chat-core.php` — chat request handling, context retrieval, response building.
- `includes/block.php` — frontend chat block.

Adding a new AI provider only requires a new entry in `a8csp_cws_get_ai_providers()` plus per-provider call helpers in `api-helpers.php`. The settings UI, PHP constants, and validation are derived from the registry automatically.

For support, contact the developer team or open an issue.
