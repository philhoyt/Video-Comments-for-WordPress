# Video Comments

Let commenters attach a short video to their comment. Videos upload directly to [Mux](https://www.mux.com/) — WordPress never touches the file, just stores an ID and renders a player.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A [Mux](https://dashboard.mux.com/) account

## Setup

1. Drop the `video-comments` folder into `wp-content/plugins/` and activate it.
2. Go to **Settings → Video Comments**.
3. Paste in your Mux Token ID and Token Secret (see below).

### Getting Mux credentials

1. In the Mux dashboard go to **Settings → API Access Tokens**.
2. Click **Generate new token** and give it **Mux Video → Full access**.
3. Copy the Token ID and Token Secret — the secret won't be shown again.

### Keeping credentials out of the database

Add these to `wp-config.php` instead of using the settings fields:

```php
define( 'VIDEO_COMMENTS_MUX_TOKEN_ID',     'your-token-id' );
define( 'VIDEO_COMMENTS_MUX_TOKEN_SECRET', 'your-token-secret' );
```

## Settings

| Setting | Default | Notes |
|---|---|---|
| Enable Video Comments | On | Turns the uploader on or off sitewide. |
| Allow Guests | On | Let non-logged-in users upload videos. |
| Max Upload Size (MB) | 50 | Enforced client-side and server-side. |

## How uploads work

The uploader requests a short-lived upload URL from WordPress, then PUTs the file directly to Mux from the browser. Once the upload finishes, the page polls until Mux has processed the video and hands back a playback ID. That ID gets submitted with the comment form and stored in comment meta.

The submit button stays disabled until the video is fully ready, so there's no way to accidentally post a comment with a broken video attached.

## Moderation

Videos follow the comment — if a comment is held for moderation, the video won't show either. The plugin doesn't add its own moderation queue on top of WordPress's built-in comment settings.

## Filters

**`video_comments_mux_player_src`** — swap out or disable the Mux player script URL:

```php
// Already loading mux-player in your theme? Skip the plugin's copy.
add_filter( 'video_comments_mux_player_src', '__return_false' );
```

**`video_comments_player_attrs`** — add or change attributes on `<mux-player>`:

```php
add_filter( 'video_comments_player_attrs', function ( $attrs, $playback_id ) {
    $attrs['muted'] = '';
    return $attrs;
}, 10, 2 );
```

## Adding another video provider

The plugin uses a `Video_Comments_Provider` interface so a second backend (e.g. Cloudflare Stream) can be dropped in without rewriting anything. Implement `create_direct_upload()` and `get_upload_status()` in a new class under `includes/providers/`.

## FAQ

**Do videos need separate approval?**
No — the video is tied to the comment. Approve or hold the comment and the video follows.

**What formats are accepted?**
Anything Mux supports: MP4, MOV, WebM, MKV, AVI, and more. The plugin also checks the file extension server-side before issuing an upload URL.

**Will this break with a caching plugin?**
The REST endpoints sit under `/wp-json/` which most caching plugins leave alone. If you cache pages for logged-in users you may need to exclude comment pages or use a nonce refresh strategy.

**The player isn't showing up.**
Check that your theme calls `wp_footer()`, that Mux credentials are saved, and that nothing in your comment walker is stripping the `comment_text` filter output.

## License

GPL-2.0-or-later
