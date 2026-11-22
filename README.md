# WP IndexNow Ping

Instantly notify Bing and Yandex when your content changes — no waiting for the next crawl.

IndexNow is a simple protocol: when a URL is created, updated or deleted, your site pings `api.indexnow.org` with the URL and the search engine fetches it right away, instead of discovering the change days later on its own crawl.

## Features

- **Auto-submit on publish** — new posts/pages are submitted the moment they go live
- **Auto-submit on update** — edits to published content re-submit the URL
- **Auto-submit on delete** — deleted posts are submitted too, so engines drop the URL instead of hitting a 404 on the next crawl
- **Key file handled for you** — the `<key>.txt` file IndexNow requires at your site root is created on activation, with a reachable-status check on the settings page
- **Manual bulk submit** — paste up to 100 URLs at once from **Settings → IndexNow Ping**
- **Submission log** — last 10 submissions with time, URLs and HTTP result
- **Regenerate key** — one click, old key file removed automatically

## Setup

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Go to **Settings → IndexNow Ping**.
3. Check that the key file shows **"OK — reachable"**. If your web root is not writable, create the file manually: a file named `<key>.txt` in your site root containing only the key.
4. Done — publishing a post now pings IndexNow automatically.

## Filters

- `wip_post_types` — post types submitted (default: all public post types except attachments)
- `wip_should_submit( $submit, $post, $is_update )` — decide per post whether to submit

```php
// Only submit posts, never pages.
add_filter( 'wip_post_types', function () {
    return array( 'post' );
} );

// Never submit posts in the "internal" category.
add_filter( 'wip_should_submit', function ( $submit, $post ) {
    return has_category( 'internal', $post ) ? false : $submit;
}, 10, 2 );
```

## How IndexNow verifies you

The protocol requires the API key to be reachable at `https://your-site.com/<key>.txt` with the key as the file's only content. This plugin creates that file on activation and re-checks it on the settings page. The key is also sent in every submission (`keyLocation` tells the engine where to verify it).

## Requirements

- WordPress 5.8+
- PHP 7.4+

## License

MIT — see [LICENSE](LICENSE).
