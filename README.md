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
- `includes/answer-links.php` - the link policy for chat answers: article link extraction, external link verification, flight search links, and the Skimlinks tracking label.
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

- **Chatbot Configuration** - optional custom prompt text used with the default system instructions, daily message limits, and the answer link settings described under [Links in Answers](#links-in-answers).
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

Chat history is stored in the visitor PHP session as `frontend_chat_history` and is bounded to the last 20 messages.

### Message Limits

The chat endpoint is public, so every message spends the site's AI credits. Each message is checked against three limits before any provider call:

- **7 per minute per IP** - `a8csp_cws_bot_response_<hash>` transients.
- **Daily per IP** - **Daily Message Limit per Visitor** under **51 Chatbot > Settings**, default 50. Stored in `a8csp_cws_chat_ip_<hash>` transients.
- **Daily for the whole site** - **Daily Message Limit (Whole Site)**, default 500. This is the hard cap on AI spend, since it also holds against many IPs. The count is kept in the `a8csp_cws_chat_count_<Ymd>` option, incremented with a conditional SQL update so concurrent requests can't push it past the cap, and older days are deleted on the first message of a new day. The settings screen shows today's count.

Days follow the site timezone, and `0` turns a daily limit off. A visitor over a limit gets a short "come back tomorrow" reply. Nonce refreshes are limited to one per minute per IP with `a8csp_nonce_refresh_<hash>` transients.

The IP is `REMOTE_ADDR`. Forwarded-IP headers are not trusted by default, because anyone can send them to dodge the per-IP limits. If the site is behind a proxy or CDN, every visitor shares one IP; the settings screen shows **Your IP as the chatbot sees it** so an admin can check. In that case, name the header that carries the visitor IP in `wp-config.php`:

```php
define( 'A8CSP_CWS_CLIENT_IP_HEADER', 'HTTP_X_FORWARDED_FOR' );
```

The plugin uses the rightmost address in the header, which is the one your proxy added. Addresses to its left are sent by the client and can be faked. If more than one proxy sits in front of the site, set `A8CSP_CWS_CLIENT_IP_TRUSTED_HOPS` to the number of proxies (default `1`). Behind a CDN and a load balancer, use `2`; a value that is too low puts every visitor in the CDN's bucket. An invalid address falls back to `REMOTE_ADDR`. The `a8csp_cws_client_ip` filter can override the result.

## Retrieval and Responses

For each visitor message, the plugin:

1. Sanitizes and bounds the message and chat history.
2. Creates an embedding with the configured provider.
3. Queries Pinecone for up to five matches.
4. Loads up to three matching published posts from WordPress, with up to 8 links to other websites from each. A post's links only use what is left of its third of the context, so they never push the next post out.
5. Builds a context-limited system message: a prompt of up to 4,500 characters and up to 6,000 characters of context, 10,000 characters in total.
6. Sends the chat completion request to the configured provider.
7. Turns flight markers into flight search links, then converts the Markdown response to safe HTML with Parsedown.
8. Checks every link in the answer, as described under [Links in Answers](#links-in-answers).

Provider requests retry HTTP 429 and 503 responses with bounded backoff. Completion responses are capped at 500 output tokens where the provider API supports that option, and rendered responses are truncated if they exceed the plugin's response limit.

## Links in Answers

Every link in a chat answer is checked on the server before the answer is shown. A link that fails the checks is removed, and its text stays in the answer.

- **Links to this site** are kept when they point to an article used as context, a published post of a public post type, or the home page.
- **Links to other websites** are kept when they appear in a context article, and point to the address as the article spells it. For each article, the plugin gives the model up to 8 external links from its raw content, with their link text, leaving out social profile and share links (Instagram, Facebook, X and Twitter, Pinterest, TikTok, LinkedIn, Threads). Links the visitor can't see are left out too: links in HTML comments, inside membership shortcodes such as `[mepr-show]` or `[pmpro_member]` (the list is filterable with `a8csp_cws_gated_shortcodes`), and, with Jetpack Memberships, past a paywall the visitor has no access to. Links inside other shortcodes, such as `[caption]` or page builder blocks, are kept.
- **Email and phone links** (`mailto:` and `tel:`) are kept.
- Root-relative links (`/path`) are treated as links to this site and follow the rule above. A link that starts with a domain name but has no scheme (`example.com/page`) is read as `https://example.com/page`. Any other link is removed, such as another relative or protocol-relative URL (`page/`, `?p=1`, `//example.com`) or a `javascript:` or `data:` URL.

Kept links open in a new tab, and links to other websites get `rel="noopener nofollow"`. The prompt tells the model to use only URLs from the context and never to guess one; these checks catch the links it guesses anyway.

### Recommendations Beyond Your Content

With **Recommendations Beyond Your Content** checked under **51 Chatbot > Settings**, the prompt tells the model to use the context articles as its main source and as a guide to the site's taste and point of view. It may also recommend specific hotels, restaurants, cruises, and other places from its own knowledge. The prompt asks it to link the name of every place it mentions: to the place's link in a context article when there is one, otherwise to its official website. The setting is off by default.

Those links are not in the context, so each one is verified before the answer is shown:

1. A `HEAD` request with a 4 second timeout, following up to 3 redirects. A 2xx response verifies the link.
2. If `HEAD` returns 400, 403, 405, 429, 501, or 503, which many sites send to bots or to `HEAD` requests, or fails with an error other than a DNS or connection failure, the first 4 KB are fetched with `GET` instead.
3. If the page still does not load or times out, and the URL has a path, the site's home page is checked the same way. When it loads, the link points to the home page instead: the place's own website still works even if the deep link was wrong. This step is skipped for social networks and for sites shared by many places, such as Google Maps, Apple Maps, Tripadvisor, Yelp, Booking.com, Expedia, Airbnb, OpenTable, TheFork, the Michelin Guide, and large hotel chains, because their home page says nothing about the place. The `a8csp_cws_link_root_fallback` filter can change that.
4. Otherwise the link is removed.

A verified link keeps the URL the model wrote, not the redirect target, which may be localized for the server's location. Only `http` and `https` URLs on ports 80 and 443 are checked, through `wp_safe_remote_head()` and `wp_safe_remote_get()`. The plugin follows redirects itself: before each request it resolves the host, refuses the URL if any of its IPv4 addresses is local, private, or reserved, and makes cURL connect to the address it checked, so a DNS answer that changes between the check and the request can't reach an internal address. When WordPress sends requests through a proxy (`WP_PROXY_HOST`), the proxy resolves the host itself and this pinning doesn't apply. Checks need the PHP cURL extension, and sites reachable only over IPv6 can't be verified; those links are removed.

Results are cached in `a8csp_cws_link_<hash>` transients, for 1 day when the link loads and 6 hours when it does not. A cached result is shared by spellings of the same address with and without `www.` or a trailing slash: a link to a spelling that was verified then points to the spelling that was checked, and a failure only counts for the exact spelling that failed. One answer checks at most 8 uncached links with at most 24 requests, within about 10 seconds: each request's timeout is cut to the time left. Links beyond that are removed for this answer and not cached. With the setting off, links to other websites that are not in the context are removed without any request.

### Flight Search Links

With **Flight Search Links** checked, the prompt gives the model today's date and a flight marker it can add only when the visitor has given the route and travel dates, without reusing the example's values:

```text
[Search flights from New York to Lisbon](flight:JFK-LIS/2027-05-12/2027-05-19/2)
```

The marker is `ORIGIN-DESTINATION/DEPART[/RETURN][/ADULTS]`: two different 3-letter IATA airport or city codes, dates as `YYYY-MM-DD`, and 1-6 adults (default 1). A one-way trip leaves out the return date. Before the Markdown is converted, the plugin replaces the marker with an Expedia flight search:

```text
https://www.expedia.com/go/flight/search/roundtrip/2027-05-12/2027-05-19?FromAirport=JFK&ToAirport=LIS&NumAdult=2
```

One-way searches use `oneway` and repeat the departure date, because Expedia needs both date segments. Dates must be between today, in the site timezone, and 330 days ahead; Expedia shows no results for later dates. A marker that is invalid, or any marker while the setting is off, is replaced with its link text.

### Link Tracking Label

**Link Tracking Label** adds a `data-skimlinks-tracking` attribute with the label to every link to another website in an answer, flight searches included. Skimlinks sends it as the Custom ID (`xcust`), so clicks from the chatbot can be reported on their own. The Skimlinks script must already be on the site; the plugin does not load it. The label may contain letters, numbers, hyphens, and underscores, up to 50 characters. It is empty by default, which adds no attribute. `rel="nofollow"` and `target="_blank"` do not stop Skimlinks from affiliating a link.

### Skimlinks Site ID

The Skimlinks script looks up the merchants of the links on a page when the page loads. Links the chat adds later are never looked up, so the script leaves them alone and they earn nothing. With **Skimlinks Site ID** set (Publisher Hub, Settings, Sites; it looks like `12345X678901`), every link to another website in an answer, flight searches included, is written as a Skimlinks link wrapper URL instead:

```text
https://go.skimresources.com/?id=12345X678901&url=<encoded link>&xcust=<link tracking label>
```

Skimlinks then affiliates the click when the merchant is in the site's program and forwards it unchanged otherwise. The link checks still run against the original URL. When a site ID is set, the label goes into `xcust` and no `data-skimlinks-tracking` attribute is added. The field is empty by default, which links directly.

### Why Not Google Search Grounding

Gemini's Google Search grounding is deliberately not used. Its terms forbid modifying grounded answers and adding link tracking or redirects to their links, and this plugin does both: it removes links that fail the checks and adds the tracking label. The model recommends from its own knowledge instead, and the plugin verifies every link.

### Link Filters

| Filter | Arguments | Description |
| --- | --- | --- |
| `a8csp_cws_flight_search_url` | `$url`, `$args` | The Expedia URL built from a flight marker. `$args` has `origin`, `destination`, `depart`, `return` (`''` for one-way), and `adults`. Return another `http` or `https` URL to use a different booking site, or `''` to show the link text only. |
| `a8csp_cws_link_check_user_agent` | `$user_agent`, `$url` | The `User-Agent` header sent when verifying a link. Default `Mozilla/5.0 (compatible; A8CSP-Site-Chatbot/<version>; +<home URL>)`. |
| `a8csp_cws_link_root_fallback` | `$allowed`, `$url` | Whether a recommended link that fails can fall back to its site's home page. Default `false` for social networks and shared platforms such as maps, review, booking, and hotel chain sites, `true` otherwise. |
| `a8csp_cws_gated_shortcodes` | `$tags` | Shortcode tags whose content is hidden from some visitors, so links inside them are not given to the model. |
| `a8csp_cws_answer_html` | `$html`, `$settings` | The answer HTML after the link checks, with the link settings from `a8csp_cws_get_link_settings()`. |

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
- **A link is missing from a chat answer, but its text is there** - the link failed the checks described under [Links in Answers](#links-in-answers). Links to other websites that are not in a context article are only kept, after a check, when **Recommendations Beyond Your Content** is on.
- **Settings warnings appear** - fill in all required Pinecone fields and the required fields for the selected provider.
- **Background sync is unavailable** - Action Scheduler is not loaded. Run `composer install` in the plugin folder.
- **A background sync stays queued or moves slowly** - the site is probably getting little traffic. Keep the Content Library page open, run `wp action-scheduler run --hooks=a8csp_cws_sync_job_batch`, or add the server cron job described under [Low-Traffic Sites](#low-traffic-sites).
- **A background sync failed with "Plugin settings changed during the sync"** - the provider, embedding model, Pinecone URL, or namespace changed after the job started. Start a new sync.
- **Why `--hooks` instead of `--group`** - `wp action-scheduler run --group=a8csp-site-chatbot` fails with `Call to undefined method ... get_group_id()` when Safety Net's **Pause renewal actions** setting is on (the default outside production), because it replaces the Action Scheduler data store with one that cannot filter by group. It also fails with "The group does not exist" until the first sync job has been queued. The `--hooks=a8csp_cws_sync_job_batch` form works in both cases.

## License

This project is licensed as `GPL-2.0-or-later`, as declared in `composer.json` and the tracked `LICENSE` file.
