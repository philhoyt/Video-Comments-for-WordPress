# Video Comments

[![CI](https://github.com/philhoyt/Video-Comments-for-WordPress/actions/workflows/ci.yml/badge.svg)](https://github.com/philhoyt/Video-Comments-for-WordPress/actions/workflows/ci.yml)

Let commenters attach a short video to their comment.
Videos upload directly to [Mux](https://www.mux.com/).

## Requirements

- WordPress 6.0+ (tested up to 7.1)
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
| Max Upload Size (MB) | 50 | Checked in the browser and against the size the browser reports when requesting an upload URL. The file goes straight to Mux, so a tampered client can bypass it; the per-IP rate limit is the real abuse guard. |

## How uploads work

The uploader requests a short-lived upload URL from WordPress, then PUTs the file directly to Mux from the browser. WordPress also hands back a one-time token for that upload; only the browser that started the upload holds it, and it is required to poll the upload's status or remove it. Once the upload finishes, the page polls until Mux has processed the video. The comment form then submits the upload ID and its token, and WordPress looks the upload up in Mux and stores the playback ID it gets back in comment meta. Playback and asset IDs are never accepted from the browser, so a comment can only carry a video that was uploaded through this site.

The submit button stays disabled until the video is fully ready, so there's no way to accidentally post a comment with a broken video attached.

## Video-only comments

Once a video is ready, the comment text is optional: the commenter can post the video on its own. The browser stops requiring the text box at that point, and the server accepts the empty comment only when the submission carries the plugin's form nonce, the upload ID, its one-time token, and Mux confirms the video is playable. Anything else gets WordPress's usual "Please type your comment text." error. This works on the standard comment form only; comments created through the REST API or XML-RPC still need text.

Things worth knowing:

- WordPress normally rejects a second comment whose text matches an earlier one by the same person. Two video-only comments both have empty text, so the plugin skips that check for them. The usual 15-second gap between comments from the same person still applies.
- Moderation keys, the disallowed-comment list, and the link limit all look at the comment text, so they cannot catch anything in a video-only comment. Returning commenters may be auto-approved under **Settings → Discussion** as usual.
- In the admin Comments list a video-only comment shows as **[Video comment]** with a link to its edit screen, where the video plays. The new-comment and moderation emails include a line pointing at the video.
- The video is the whole comment. If you deactivate the plugin, or clear the playback ID on the comment's edit screen, the comment is left blank. Trash the comment instead if you want it gone.

To turn the behaviour off, see the `video_comments_allow_video_only` filter below.

## Clean-up

When a comment is permanently deleted, or an administrator clears its video, the Mux asset is deleted in the background by WP-Cron (with retries). Uploads that never become a comment are removed from Mux by a daily sweep once they are a day old. Deleting the plugin removes its settings, comment meta and scheduled events; videos already in your Mux account are left alone.

## Privacy

The plugin suggests policy text under **Settings → Privacy → Policy Guide**, exports a commenter's videos alongside their other personal data, and removes the videos (scheduling their deletion from Mux) when a commenter's data is erased through **Tools → Erase Personal Data**.

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

**`video_comments_client_ip`** — behind a reverse proxy or CDN, return the real visitor address so the upload rate limit is not shared by everyone behind the proxy:

```php
add_filter( 'video_comments_client_ip', function ( $ip ) {
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
} );
```

**`video_comments_allow_video_only`** -- require text even when a video is attached. The filter runs twice: when the comment form renders, with an empty `$commentdata`, to decide whether the browser may relax the text box; and on submission, with WordPress's comment data, before the upload is checked with Mux.

```php
// Always require text.
add_filter( 'video_comments_allow_video_only', '__return_false' );

// Or decide per post on submission (the form will still relax the text box).
add_filter( 'video_comments_allow_video_only', function ( $allow, $commentdata ) {
    if ( empty( $commentdata ) ) {
        return $allow; // Render time: nothing to decide on yet.
    }
    return has_term( 'video-replies', 'category', (int) $commentdata['comment_post_ID'] );
}, 10, 2 );
```

## FAQ

**Do videos need separate approval?**
No — the video is tied to the comment. Approve or hold the comment and the video follows.

**Can someone post a video without writing anything?**
Yes, once the video is ready. See [Video-only comments](#video-only-comments) for how that is checked and how to turn it off.

**What formats are accepted?**
Anything Mux supports: MP4, MOV, WebM, MKV, AVI, and more. The plugin also checks the file extension server-side before issuing an upload URL.

**Will this break with a caching plugin?**
The REST endpoints sit under `/wp-json/` which most caching plugins leave alone. If you cache pages for logged-in users you may need to exclude comment pages or use a nonce refresh strategy.

**The player isn't showing up.**
Check that your theme calls `wp_footer()`, that Mux credentials are saved, and that nothing in your comment walker is stripping the `comment_text` filter output.

## License

GPL-2.0-or-later
