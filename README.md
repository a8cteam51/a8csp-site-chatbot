# A8CSP Site Chatbot

A8CSP Site Chatbot is a WordPress plugin for creating embeddings from published site content, storing those vectors in Pinecone, and answering visitor questions through an AI-powered chat block.

The plugin is registered in WordPress as **51 Site Chatbot**. It adds a **51 Chatbot** admin menu with Content Library and Settings pages, plus the `a8csp/site-chatbot` block for placing the chat UI on posts or pages.

## What is in this repository

- `a8csp-site-chatbot.php` - plugin bootstrap, plugin metadata, constants initialized from saved settings, Composer autoload loading, include loading, and admin hooks.
- `includes/ai-providers.php` - registry for supported AI providers, models, required fields, defaults, and settings help text.
- `includes/settings.php` - WordPress Settings API registration, validation, required-setting warnings, and the **51 Chatbot > Settings** screen.
- `includes/content-library.php` - the **Content Library** admin page, public content filtering, bulk sync, Pinecone sync metadata, and stale-sync detection.
- `includes/api-helpers.php` - embedding, completion, Pinecone upsert/query, provider retry, Markdown conversion, and link formatting helpers.
- `includes/chat-core.php` - chat history validation, retrieval context assembly, prompt construction, response limiting, and frontend rate limiting.
- `includes/block.php` - block registration, server render callback, frontend script/style registration, and AJAX handlers.
- `blocks/site-chatbot/` - block metadata, editor JavaScript, frontend JavaScript, and frontend styles.
- `assets/admin.css` - admin UI styles for the plugin screens.
- `.github/workflows/build-release.yml` - GitHub release packaging workflow.

There are no tracked custom post types, taxonomies, REST routes, shortcodes, or WP-CLI commands.

## Requirements

- WordPress 5.0 or newer.
- PHP 7.4 or newer.
- Composer, to install the locked PHP dependency.
- A Pinecone index for vector storage.
- API access for one supported chat/embedding provider combination.

The only PHP package dependency is `erusev/parsedown`, locked in `composer.lock`. There is no `package.json`, npm build step, PHPUnit config, PHPCS config, or markdown lint command in the repository.

## Local Setup

Install PHP dependencies before activating or packaging the plugin:

```sh
composer install
```

Then place the repository directory at:

```text
wp-content/plugins/a8csp-site-chatbot/
```

Activate **51 Site Chatbot** from the WordPress plugins screen and configure it under **51 Chatbot > Settings**.

## Configuration

The plugin stores settings in the `a8csp_chat_with_site_options` option. The settings screen has three groups:

- **Chatbot Configuration** - optional custom prompt text used with the default system instructions.
- **AI Service** - provider selection and provider-specific API/model fields.
- **Vector Database** - Pinecone API key, Pinecone server URL, and optional Pinecone namespace.

Supported providers are defined in `includes/ai-providers.php`:

| Provider | Chat models | Embedding models |
| --- | --- | --- |
| OpenAI | `gpt-4o-mini` default, `gpt-5-mini`, `gpt-5-nano` | `text-embedding-3-small` default, `text-embedding-3-large`, `text-embedding-ada-002` |
| Anthropic + Voyage AI | `claude-sonnet-5` default, `claude-haiku-4-5`, `claude-opus-5` | `voyage-3-large` default, `voyage-4-large`, `voyage-4-lite` |
| Google Gemini | `gemini-2.5-flash` default, `gemini-2.5-pro`, `gemini-2.5-flash-lite` | `gemini-embedding-001` default, `text-embedding-004` |

Anthropic is used for chat completions only. Voyage AI is used for embeddings when the Anthropic provider is selected.

### Pinecone Index Dimensions

Pinecone index dimensions must match the embedding model output. If the dimensions do not match, Pinecone rejects upserts and queries will not use the expected vector space.

| Embedding model | Dimensions shown by the plugin |
| --- | --- |
| `text-embedding-3-small` | 1536 |
| `text-embedding-3-large` | 3072 |
| `text-embedding-ada-002` | 1536 |
| `gemini-embedding-001` | 768-3072 |
| `text-embedding-004` | 768 |
| `voyage-3-large` | 1024 |
| `voyage-4-large` | 1024 |
| `voyage-4-lite` | 1024 |

When changing provider, embedding model, Pinecone index, or namespace, plan to resync content. Vectors from different embedding models are not comparable.

## Content Library

Use **51 Chatbot > Content Library** to sync published site content to Pinecone.

The Content Library:

- Lists public post types, 20 posts per page.
- Filters posts by category or tag when viewing the `post` type.
- Syncs selected posts in batches of up to 50.
- Converts post title and filtered content to plain text for embedding.
- Sends vectors to Pinecone with metadata for post ID, title, URL, post type, provider, model, and available categories/tags.

After a successful sync, the plugin writes these post meta keys:

- `_pinecone_synced`
- `_pinecone_sync_date`
- `_pinecone_sync_fingerprint`

The fingerprint records the provider, embedding model, Pinecone URL, and namespace used for the sync. The admin table marks content as:

- **In Pinecone** - current settings match the stored sync fingerprint.
- **Stale - re-sync needed** - the post was synced with different provider, model, index, or namespace settings.
- **Not in Pinecone** - the post has not been synced by this plugin.

## Chat Block

The block is registered as `a8csp/site-chatbot` in the `widgets` category with the `format-chat` icon. Its attributes are:

- `primaryColor`
- `botName`
- `initialMessage`

The server render callback enqueues `blocks/site-chatbot/frontend.js` and `blocks/site-chatbot/style.css`, localizes `admin-ajax.php` data, and renders the current PHP session chat history. The editor script provides sidebar controls for bot name, initial message, and primary color.

The frontend uses two AJAX actions for both authenticated and anonymous visitors:

- `a8csp_chat_message`
- `a8csp_refresh_nonce`

Chat history is stored in the visitor PHP session as `frontend_chat_history` and is bounded to the last 20 messages. Bot responses are rate-limited per IP with `a8csp_cws_bot_response_<hash>` transients. Nonce refreshes are also rate-limited with `a8csp_nonce_refresh_<hash>` transients.

## Retrieval and Responses

For each visitor message, the plugin:

1. Sanitizes and bounds the message and chat history.
2. Creates an embedding with the configured provider.
3. Queries Pinecone for up to five matches.
4. Loads up to three matching published posts from WordPress.
5. Builds a context-limited system message.
6. Sends the chat completion request to the configured provider.
7. Converts the Markdown response to safe HTML with Parsedown.

Provider requests retry HTTP 429 and 503 responses with bounded backoff. Completion responses are capped at 500 output tokens where the provider API supports that option, and rendered responses are truncated if they exceed the plugin's response limit.

## Development Notes

Add or change provider fields in `a8csp_cws_get_ai_providers()` in `includes/ai-providers.php`. The settings UI, validation, defaults, and constants are derived from that registry.

Provider API behavior lives in `includes/api-helpers.php`. Add matching embedding and completion helpers there when adding a provider.

Block source files are edited directly in `blocks/site-chatbot/`; there is no build pipeline in this repository. Keep `composer.lock` committed when PHP dependencies change.

`vendor/` is ignored locally and generated by Composer. The release workflow installs dependencies and includes `vendor/` in the packaged plugin zip.

## Release Packaging

GitHub releases trigger `.github/workflows/build-release.yml`. The workflow:

1. Runs `composer validate --strict`.
2. Installs Composer dependencies with `composer install --no-dev --optimize-autoloader --prefer-dist --no-progress`.
3. Copies the plugin PHP file, `LICENSE`, `README.md`, Composer files, `includes/`, `assets/`, `blocks/`, and `vendor/` into an `a8csp-site-chatbot/` release directory.
4. Uploads a zipped plugin artifact to the GitHub release.

## Troubleshooting

- **Pinecone rejects syncs with HTTP 400** - verify that the Pinecone index dimensions match the selected embedding model.
- **Content is marked stale** - resync after changing provider, embedding model, Pinecone URL, or namespace.
- **The chat cannot answer a topic** - confirm the relevant published content is synced and not stale.
- **Provider requests fail with HTTP 429 or 503** - the plugin retries those responses, but smaller sync batches may still be needed on tight provider limits.
- **Settings warnings appear** - fill in all required Pinecone fields and the required fields for the selected provider.

## License

This project is licensed as `GPL-2.0-or-later`, as declared in `composer.json` and the tracked `LICENSE` file.
