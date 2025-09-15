# WP Video/Category Posts — Live Search + Chips

Live search + category chips + subcategory filter (via WP REST API) for multiple categories using a shortcode.

# Screenshot



**Shortcode**
```text
[video_posts_live category_slugs="videos, news, investment" per_page="12" category_logic="or" include_children="true"]

Attributes

category_slugs — comma-separated root category slugs (fallback: category_slug)

category_logic — or (default) or and (requires included REST hook)

include_children — true (default) or false

per_page — posts per page (default 9)

Features

Live search (titles/content)

Root chips (All + each root); optional subcategory dropdown

Infinite list with Load more (with Loading… state)

Always restricted to the categories you pass (and optionally their children)

Works entirely with WP REST API (no admin-ajax needed)

Install

Copy wp-video-posts-live.php to /wp-content/plugins/wp-video-posts-live/.

Activate WP Video/Category Posts — Live Search + Chips.

Add the shortcode anywhere.

License

MIT

## Main plugin file: `wp-video-posts-live.php`
> Put this in `/wp-content/plugins/wp-video-posts-live/wp-video-posts-live.php`
