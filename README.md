# A8CSP Site Chatbot

A8CSP Site Chatbot is a WordPress plugin for creating embeddings from published site content, storing those vectors in Pinecone, and answering visitor questions through an AI-powered chat block.

The plugin is registered in WordPress as **51 Site Chatbot**. It adds a **51 Chatbot** admin menu with Content Library and Settings pages, the `a8csp/site-chatbot` block for placing the chat UI on posts or pages, and a `wp site-chatbot` WP-CLI command for syncing content.

## What is in this repository

- `a8csp-site-chatbot.php` - plugin bootstrap, plugin metadata, constants initialized from saved settings, Composer autoload and Action Scheduler loading, include loading, and admin hooks.
- `includes/ai-providers.php` - registry for supported AI providers, models, required fields, defaults, and settings help text.
- `includes/settings.php` - WordPress Settings API registration, validation, required-setting warnings, and the **51 Chatbot > Settings** screen.
- `includes/content-library.php` - the **Content Library** admin page, public content filtering, the shared batched post sync, bulk sync, Pinecone sync metadata, and stale-sync detection.
- `includes/api-helpers.php` - single and batched embedding requests, completion, batched Pinecone upsert and query, provider error classification, provider retry, Markdown conversion, and link formatting helpers.
- `includes/sync-jobs.php` - the background sync job engine: job state, Action Scheduler batches, locking, retries, cancel, and retry of failed posts.
- `includes/sync-jobs-admin.php` - the **Background sync** panel on the Content Library page, its admin AJAX handlers, and its script loading.
- `includes/cli.php` - the `wp site-chatbot` WP-CLI command, loaded only when WP-CLI is running.
- `includes/chat-core.php` - chat history validation, retrieval context assembly, prompt construction, response limiting, and frontend rate limiting.
- `includes/block.php` - block registration, server render callback, frontend script/style registration, and AJAX handlers.
- `blocks/site-chatbot/` - block metadata, editor JavaScript, frontend JavaScript, and frontend styles.
- `assets/admin.css` - admin UI styles for the plugin screens, including the background sync panel.
- `assets/sync-jobs.js` - background sync panel script: starts, polls, cancels, retries, and dismisses sync jobs.
- `.github/workflows/build-release.yml` - GitHub release packaging workflow.

There are no tracked custom post types, taxonomies, REST routes, or shortcodes. The WP-CLI commands are described under [Background Sync](#background-sync).

## Requirements

- WordPress 6.5 or newer (the minimum for the bundled Action Scheduler 3.9).
- PHP 7.4 or newer.
- Composer, to install the locked PHP dependencies.
- A Pinecone index for vector storage.
- API access for one supported chat/embedding provider combination.

The PHP package dependencies are `erusev/parsedown` and `woocommerce/action-scheduler` (`^3.9`, used for background sync), locked in `composer.lock`. There is no `package.json`, npm build step, PHPUnit config, PHPCS config, or markdown lint command in the repository.

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
| Google Gemini | `gemini-3.5-flash` default, `gemini-3.5-flash-lite`, `gemini-2.5-flash` | `gemini-embedding-001` |

Anthropic is used for chat completions only. Voyage AI is used for embeddings when the Anthropic provider is selected.

### Batched Embeddings and Upserts

Content syncs (the Content Library bulk action, background sync, and WP-CLI) send many posts per request instead of one request per post. Embedding requests are split into chunks that stay inside each provider's limits:

| Provider | Texts per embedding request | Text per embedding request |
| --- | --- | --- |
| OpenAI | 256 | 600,000 bytes |
| Anthropic + Voyage AI | 128 | 200,000 bytes (1,500,000 for `voyage-4-lite`) |
| Google Gemini | 100, using `batchEmbedContents` | 60,000 bytes (about 15,000 tokens, under the free tier's per-minute token limit) |

Pinecone upserts are chunked to at most 100 vectors and 1.5 MB of JSON per request, below Pinecone's limits of 1,000 vectors and 2 MB. The size cap matters for 3072-dimension vectors, which are about 60 KB of JSON each.

Each text is prepared the same way as a chat query (cut to 8,000 bytes, then sanitized), and no extra model options are sent. Batching does not change the sync fingerprint, so posts synced before batching are not marked stale. Chat queries still embed one message per request.

If a provider or Pinecone rejects a chunk with HTTP 400, the plugin splits the chunk in half until the bad post is isolated. That post is reported as failed, and the rest of the chunk still syncs. Configuration errors, such as an invalid API key or an index dimension mismatch, stop the sync instead of being split. So does a 400 that both halves of a chunk return with exactly the same message as the whole chunk, because no single post can cause that. The chunk limits can be changed with [filters](#filters).

### Pinecone Index Dimensions

Pinecone index dimensions must match the embedding model output. If the dimensions do not match, Pinecone rejects upserts and queries will not use the expected vector space.

| Embedding model | Dimensions shown by the plugin |
| --- | --- |
| `text-embedding-3-small` | 1536 |
| `text-embedding-3-large` | 3072 |
| `text-embedding-ada-002` | 1536 |
| `gemini-embedding-001` | 768-3072 |
| `voyage-3-large` | 1024 |
| `voyage-4-large` | 1024 |
| `voyage-4-lite` | 1024 |

When changing provider, embedding model, Pinecone index, or namespace, plan to resync content. Vectors from different embedding models are not comparable.

## Content Library

Use **51 Chatbot > Content Library** to sync published site content to Pinecone.

The Content Library:

- Lists public post types, 20 posts per page. Password-protected posts are not listed or synced, and the chat never uses them as context.
- Filters posts by category or tag when viewing the `post` type.
- Syncs selected posts in batches of up to 50, in the page request.
- Syncs every post matching the current filters in the background (see [Background Sync](#background-sync)).
- Converts post title and filtered content to plain text for embedding.
- Sends vectors to Pinecone with metadata for post ID, title, URL, post type, provider, model, and available categories/tags.

After a successful sync, the plugin writes these post meta keys:

- `_pinecone_synced`
- `_pinecone_sync_date`
- `_pinecone_sync_fingerprint`
- `_pinecone_content_hash`

The fingerprint records the provider, embedding model, Pinecone URL, and namespace used for the sync. The content hash covers the post title, slug, and raw content, so editing any of them after a sync marks the post stale. Posts synced before the hash was added have none and are not marked stale until they are synced again. Changes made outside the post itself are not detected, such as editing a synced pattern or reusable block the post includes, or renaming a parent page's slug; resync affected posts manually. The admin table marks content as:

- **In Pinecone** - current settings match the stored sync fingerprint.
- **Stale - re-sync needed** - the post was synced with different provider, model, index, or namespace settings, or its title, slug, or content changed since the last sync.
- **Not in Pinecone** - the post has not been synced by this plugin.

## Background Sync

The **Background sync** panel on the Content Library page syncs every published post that matches the current post type, category, and tag filters, not just the current page. The same jobs can be started, checked, and cancelled from WP-CLI.

### How It Works

1. **Sync all matching posts** takes a snapshot of the matching post IDs, newest first, leaving out password-protected posts. With **Skip posts already synced with the current settings** checked (the default), posts marked **In Pinecone** are left out. Stale and unsynced posts are included.
2. The job is processed by [Action Scheduler](https://actionscheduler.org/), bundled through Composer. Each Action Scheduler action handles one batch of 25 posts, using batched embedding and Pinecone upsert requests, then queues the next batch.
3. Only one job exists at a time. Starting another while one is queued, running, or waiting is refused. A lock stops two queue runners from processing the same job at once.
4. Posts that fail on their own, such as posts with empty content, are recorded as failed, and the job continues. Posts deleted, unpublished, or given a password after the job started are counted as skipped.
5. Rate limits, network errors, and provider 5xx errors put the job in **Waiting to retry**. The next attempt uses the provider's retry delay when it gives one, kept between 30 seconds and 15 minutes. Otherwise the wait starts at 1 minute and doubles up to 15 minutes. When the same batch is rate limited twice in a row, the job halves its batch size, and grows it back after two clean batches in a row. A Gemini daily quota waits until the quota resets at midnight Pacific time; if it is still exhausted after the reset, the job fails. The job fails after 12 consecutive errors.
6. Permanent errors, such as a rejected API key, an exhausted OpenAI billing quota, or a Pinecone monthly usage limit, fail the job straight away. Changing the provider, embedding model, Pinecone URL, or namespace while a job runs also fails it, because its posts would be embedded with mixed settings.

While a job is active, the panel polls it every 3 seconds, or every 15 seconds while the job is waiting or the browser tab is hidden. It shows the status, a progress bar, synced, skipped, and failed counts, the last error with the next retry time, and the failed posts with links to edit them.

- **Cancel sync** stops the job. A batch already in progress finishes, and posts synced so far stay synced. The panel keeps checking until that batch has saved its counts, and a new sync can start once it has.
- **Retry failed posts** appears when a finished job has failures. It starts a new job for those posts only, without skipping synced posts. Every failed post is retried; the panel lists the first 50 reasons and the job keeps up to 500.
- **Dismiss** clears a finished job from the panel.

Status polls and every Action Scheduler queue run also check that an active job still has a batch scheduled, and queue one if it was lost. After a PHP fatal error or timeout, the interrupted batch is retried one post at a time, so a single problem post is recorded as failed instead of stopping the job. Deactivating the plugin unschedules its pending actions.

Job state is stored in the `a8csp_cws_sync_job` option (not autoloaded), with the `a8csp_cws_sync_job_lock` and `a8csp_cws_sync_job_cancelled` options used for locking and cancellation. The snapshot of post IDs is stored separately, 1,000 IDs per `a8csp_cws_sync_job_ids_<job>_<n>` option, and deleted when the job ends. Actions use the `a8csp_cws_sync_job_batch` hook and the `a8csp-site-chatbot` group, and can be inspected under **Tools > Scheduled Actions**.

### Low-Traffic Sites

Action Scheduler runs from WP-Cron, which WordPress only triggers when the site receives requests, and from an async runner that admin requests start. On a site with little traffic, a job can sit between batches until someone visits.

A job advances fastest while the Content Library page is open, because the panel's status polls are admin requests. It keeps going after the page is closed, as long as the site gets requests.

On Pressable and WordPress.com, add an hourly server cron job as a backstop:

```sh
wp action-scheduler run --hooks=a8csp_cws_sync_job_batch
```

Each run keeps processing batches until the job finishes or has to wait for a retry.

### WP-CLI

`wp site-chatbot sync` syncs published posts in the foreground by default, with a progress bar.

| Option | Description |
| --- | --- |
| `--post_type=<type>` | Public post type to sync. Default `post`. |
| `--category=<id-or-slug>` | Only sync posts in this category. `post` only. |
| `--tag=<id-or-slug>` | Only sync posts with this tag. `post` only. |
| `--all` | Also re-sync posts already synced with the current settings. |
| `--batch-size=<n>` | Posts per embedding and upsert batch in the foreground, 1-100. Default 50. |
| `--background` | Start a background job instead of syncing now. |
| `--dry-run` | Print how many posts would be synced and skipped, then exit. |

In the foreground, retryable errors print `Waiting Ns: <message>`, sleep, and retry the unsynced posts, giving up after 12 consecutive errors. When the same batch is rate limited twice in a row, the batch size is halved. A permanent error, or an exhausted Gemini daily quota, stops the command with the counts so far. The command ends with a summary line and a table of failed posts. It warns if a background job is running at the same time, since posts may then be synced twice.

`--background` starts the same job as the Content Library panel and ignores `--batch-size`. Use the `a8csp_cws_sync_batch_size` filter for background batches.

```sh
# Sync every post not yet synced with the current settings.
wp site-chatbot sync

# Preview a category sync, then run it.
wp site-chatbot sync --category=travel --dry-run
wp site-chatbot sync --category=travel

# Re-sync every page, including pages already synced.
wp site-chatbot sync --post_type=page --all

# Use smaller batches on tight provider rate limits.
wp site-chatbot sync --batch-size=10

# Start a background job, then process it right away.
wp site-chatbot sync --category=travel --background
wp action-scheduler run --hooks=a8csp_cws_sync_job_batch
```

`wp site-chatbot status` prints the current job: status, filters, progress, counts, the last error, the next retry, and up to 50 failed posts. Add `--format=json` for JSON output. Like the panel, it queues a new batch if an active job has stalled.

`wp site-chatbot cancel` cancels the active job.

### Filters

| Filter | Default | Description |
| --- | --- | --- |
| `a8csp_cws_sync_batch_size` | `25` | Posts per background batch, clamped to 1-100. |
| `a8csp_cws_sync_batch_delay` | `0` | Seconds to wait between background batches. `0` queues the next batch straight away. |
| `a8csp_cws_embedding_batch_limits` | See [Batched Embeddings and Upserts](#batched-embeddings-and-upserts) | Chunk limits for each embedding request, as `array( 'max_items' => int, 'max_chars' => int )`. `max_chars` is measured in bytes, and `0` means no cap. The second argument is the provider ID: `openai`, `anthropic`, or `google`. |
| `a8csp_cws_pinecone_upsert_batch_size` | `100` | Vectors per Pinecone upsert request, capped at 1,000. |
| `a8csp_cws_pinecone_upsert_max_bytes` | `1500000` | Maximum JSON body size of a Pinecone upsert request. A single vector larger than this is still sent on its own. |

The embedding and upsert filters apply to every content sync: the Content Library bulk action, background sync, and WP-CLI.

```php
// Smaller, slower background batches for tight provider rate limits.
add_filter( 'a8csp_cws_sync_batch_size', fn() => 10 );
add_filter( 'a8csp_cws_sync_batch_delay', fn() => 20 );

// Smaller Gemini requests for a key with a lower per-minute token limit.
add_filter( 'a8csp_cws_embedding_batch_limits', function ( $limits, $provider ) {
	if ( 'google' === $provider ) {
		$limits['max_chars'] = 30000;
	}
	return $limits;
}, 10, 2 );
```

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

Provider API behavior lives in `includes/api-helpers.php`. When adding a provider, add matching embedding (single and batch) and completion helpers there, and its chunk limits in `a8csp_cws_get_embedding_batch_limits()`.

`a8csp_cws_sync_posts()` in `includes/content-library.php` is the shared sync path for the Content Library bulk action, background jobs, and WP-CLI. It puts every requested post in exactly one of synced, failed, or pending. The Action Scheduler callback in `includes/sync-jobs.php` is registered when the file loads, on every request, because Action Scheduler marks actions for unregistered hooks as failed.

Block source files are edited directly in `blocks/site-chatbot/`, and `assets/sync-jobs.js` is edited directly too; there is no build pipeline in this repository. Keep `composer.lock` committed when PHP dependencies change.

`vendor/` is ignored locally and generated by Composer. The release workflow installs dependencies and includes `vendor/`, with Parsedown and Action Scheduler, in the packaged plugin zip.

## Release Packaging

GitHub releases trigger `.github/workflows/build-release.yml`. The workflow:

1. Runs `composer validate --strict`.
2. Installs Composer dependencies with `composer install --no-dev --optimize-autoloader --prefer-dist --no-progress`.
3. Copies the plugin PHP file, `LICENSE`, `README.md`, Composer files, `includes/`, `assets/`, `blocks/`, and `vendor/` into an `a8csp-site-chatbot/` release directory.
4. Uploads a zipped plugin artifact to the GitHub release.

## Troubleshooting

- **Pinecone rejects syncs with HTTP 400** - verify that the Pinecone index dimensions match the selected embedding model.
- **Content is marked stale** - resync after changing provider, embedding model, Pinecone URL, or namespace, or after editing the post.
- **The chat cannot answer a topic** - confirm the relevant published content is synced and not stale.
- **Provider requests fail with HTTP 429 or 503** - the plugin retries those responses, and background sync waits and retries on its own. On tight provider limits, lower the `a8csp_cws_sync_batch_size` filter, add an `a8csp_cws_sync_batch_delay`, or use `--batch-size` with WP-CLI.
- **A sync keeps hitting the rate limit on the same batch** - one embedding request probably needs more tokens than the key's per-minute limit allows, which is common on free tiers. Lower `max_chars` with the `a8csp_cws_embedding_batch_limits` filter, or lower `a8csp_cws_sync_batch_size`.
- **Settings warnings appear** - fill in all required Pinecone fields and the required fields for the selected provider.
- **Background sync is unavailable** - Action Scheduler is not loaded. Run `composer install` in the plugin folder.
- **A background sync stays queued or moves slowly** - the site is probably getting little traffic. Keep the Content Library page open, run `wp action-scheduler run --hooks=a8csp_cws_sync_job_batch`, or add the server cron job described under [Low-Traffic Sites](#low-traffic-sites).
- **A background sync failed with "Plugin settings changed during the sync"** - the provider, embedding model, Pinecone URL, or namespace changed after the job started. Start a new sync.
- **Why `--hooks` instead of `--group`** - `wp action-scheduler run --group=a8csp-site-chatbot` fails with `Call to undefined method ... get_group_id()` when Safety Net's **Pause renewal actions** setting is on (the default outside production), because it replaces the Action Scheduler data store with one that cannot filter by group. It also fails with "The group does not exist" until the first sync job has been queued. The `--hooks=a8csp_cws_sync_job_batch` form works in both cases.

## License

This project is licensed as `GPL-2.0-or-later`, as declared in `composer.json` and the tracked `LICENSE` file.
