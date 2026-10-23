=== Video Comments ===
Contributors: philhoyt
Tags: comments, video, mux, upload, media
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a video upload field to the comment form. Videos go straight to Mux — WordPress just stores the playback ID.

== Description ==

Adds a video field to the standard WordPress comment form. The file uploads directly from the browser to [Mux](https://www.mux.com/), so it never passes through your server. Once Mux is done processing, the playback ID gets saved with the comment and a `<mux-player>` shows up below the comment text.

The submit button is disabled until the video is ready, so comments can't be posted with a half-processed video attached. Once it is ready, the comment text is optional: a comment can be just a video.

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
* **Max Upload Size (MB)** — defaults to 50. Checked in the browser before uploading and against the size the browser reports when it asks for an upload URL. The file itself goes straight from the browser to Mux, so a tampered client can bypass this check; the per-IP rate limit on upload requests is the real guard against abuse.

== Frequently Asked Questions ==

= Do videos need to be moderated separately? =

No. Approve or trash the comment and the video goes with it.

= What formats are supported? =

Whatever Mux accepts — MP4, MOV, WebM, MKV, AVI, etc. The plugin also checks the file extension before handing out an upload URL.

= What happens to videos when a comment is deleted? =

When a comment is permanently deleted, or an administrator clears its video, the Mux asset is deleted in the background by WP-Cron. Uploads that never become a comment are removed from Mux by a daily sweep once they are a day old.

= Does the plugin work with the WordPress privacy tools? =

Yes. It suggests privacy-policy text under Settings → Privacy, exports a commenter's videos with their personal data, and removes the videos (and schedules their deletion from Mux) when a commenter's data is erased.

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

**`video_comments_client_ip`** — behind a reverse proxy or CDN, return the real visitor address so the upload rate limit is not shared by everyone behind the proxy:

`add_filter( 'video_comments_client_ip', function ( $ip ) {
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
} );`

**`video_comments_allow_video_only`** -- require comment text even when a video is attached. Runs when the comment form renders (with empty comment data) and again on submission:

`add_filter( 'video_comments_allow_video_only', '__return_false' );`

== Screenshots ==

1. Video uploader on the comment form.
2. Rendered `<mux-player>` below a comment.
3. Settings page under Settings → Video Comments.

== Changelog ==

= 1.2.0 =
* Add: A comment can be just a video. Once the upload is ready the text box becomes optional, and WordPress accepts the empty comment when the video checks out. Text is still required for comments made through the REST API or XML-RPC.
* Add: `video_comments_allow_video_only` filter to keep requiring text.
* Add: Video-only comments show as "[Video comment]" in the admin Comments list, linking to the edit screen, and the new-comment and moderation emails say when a comment includes a video.
* Fix: Video-only comments render in block themes, whose Comment Content block skips empty comments.
* Change: The video is attached to the comment before WordPress sends its notification emails.

= 1.1.1 =
* Fix: Automatic updates from GitHub releases always download the plugin zip. Version 1.1.0 could pick readme.txt as the update package, so sites on 1.1.0 need to install this version by hand once.

= 1.1.0 =
* Security: Each upload now carries a one-time token. Only the browser that started an upload can check its status or remove it, and the playback and asset IDs are looked up from Mux when the comment is saved instead of being accepted from the form.
* Security: The Mux Token Secret is no longer written back into the settings form. Leave the field blank to keep it, or tick the new checkbox to clear it.
* Fix: Videos keep playing on posts after comments have been closed.
* Add: Videos are removed from Mux in the background when a comment is permanently deleted or an administrator clears it, with automatic retries. Uploads that never became a comment are cleaned up by a daily task.
* Add: Privacy tools support. Suggested privacy-policy text appears under Settings → Privacy, and videos are included in personal-data exports and removed by erasure requests.
* Add: Deleting the plugin removes its settings, comment video data, counters and scheduled tasks.
* Add: `video_comments_client_ip` filter so sites behind a reverse proxy or CDN can rate-limit per visitor.
* Change: The file picker is reachable by keyboard, the uploader meets contrast guidelines, and screen readers hear a single processing announcement.
* Change: The Mux player is pinned to version 3 and loads only on pages that show a video or the uploader.
* Change: Status polling is rate limited. The upload size limit is now documented as a browser-side check, since files go straight from the browser to Mux.
* Change: Tested up to WordPress 7.1.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.1 =
Fixes the GitHub release updater choosing the wrong asset. Sites on 1.1.0 must install this version by hand once; updates work automatically from then on.

= 1.1.0 =
Security hardening of the upload flow, background clean-up of Mux assets, privacy-tools support and accessibility fixes. Pages cached before the update need a refresh before their first upload.

= 1.0.0 =
Initial release.
