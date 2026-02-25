=== Video Comments ===
Contributors: philhoyt
Tags: comments, video, mux, upload, media
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a video upload field to the comment form. Videos go straight to Mux — WordPress just stores the playback ID.

== Description ==

Adds a video field to the standard WordPress comment form. The file uploads directly from the browser to [Mux](https://www.mux.com/), so it never passes through your server. Once Mux is done processing, the playback ID gets saved with the comment and a `<mux-player>` shows up below the comment text.

The submit button is disabled until the video is ready, so comments can't be posted with a half-processed video attached.

Videos are tied to their comment — if a comment is held for moderation the video won't show either. No separate moderation queue.

You'll need a Mux account with an API Access Token (Video → Full access).

== Installation ==

1. Upload the `video-comments` folder to `wp-content/plugins/` and activate.
2. Go to **Settings → Video Comments** and enter your Mux Token ID and Token Secret.

= Keeping credentials out of the database =

Add these to `wp-config.php` instead:

`define( 'VIDEO_COMMENTS_MUX_TOKEN_ID',     'your-token-id' );`
`define( 'VIDEO_COMMENTS_MUX_TOKEN_SECRET', 'your-token-secret' );`

When the constants are present, the settings fields are hidden.

== Settings ==

* **Enable Video Comments** — turns the uploader on or off sitewide.
* **Allow Guests** — lets non-logged-in users upload. On by default.
* **Max Upload Size (MB)** — defaults to 50, checked on both client and server.

== Frequently Asked Questions ==

= Do videos need to be moderated separately? =

No. Approve or trash the comment and the video goes with it.

= What formats are supported? =

Whatever Mux accepts — MP4, MOV, WebM, MKV, AVI, etc. The plugin also checks the file extension before handing out an upload URL.

= Will this conflict with a caching plugin? =

The REST endpoints are under `/wp-json/` which most caching plugins skip. If you're caching pages for logged-in users you may need to exclude comment pages.

= The player isn't showing up. =

Make sure your theme calls `wp_footer()`, your Mux credentials are saved, and nothing in your comment walker is stripping the `comment_text` filter output.

== Filters ==

**`video_comments_mux_player_src`** — override or disable the Mux player script URL:

`add_filter( 'video_comments_mux_player_src', '__return_false' );`

**`video_comments_player_attrs`** — modify attributes on the `<mux-player>` element:

`add_filter( 'video_comments_player_attrs', function ( $attrs, $playback_id ) {
    $attrs['muted'] = '';
    return $attrs;
}, 10, 2 );`

== Screenshots ==

1. Video uploader on the comment form.
2. Rendered `<mux-player>` below a comment.
3. Settings page under Settings → Video Comments.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
