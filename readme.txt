=== Video Comments ===
Contributors: philhoyt
Tags: comments, video, mux, upload, media
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a video upload field to the comment form; the file goes straight to Mux and WordPress stores only the playback ID

== Description ==

Adds a video field to the standard WordPress comment form. The file uploads from the browser directly to [Mux](https://www.mux.com/) and never passes through your server. When Mux has finished processing, the playback ID is saved as comment meta and a `<mux-player>` is rendered below the comment text.

The submit button stays disabled until the video is ready, so a comment cannot be posted with a half-processed video. Once the video is ready the text box becomes optional and a comment can be the video alone.

Videos follow their comment. A comment held for moderation does not show its video, and there is no separate moderation queue.

You need a Mux account and an API access token with Mux Video full access.

= Settings =

Settings > Video Comments has three options:

* Enable Video Comments. Turns the uploader on or off sitewide. On by default.
* Allow Guests. Lets visitors who are not logged in upload. On by default.
* Max Upload Size (MB). Default 50. Checked in the browser before the upload starts, and again against the size the browser reports when it asks for an upload URL. The file itself goes from the browser to Mux, so a modified client can get around this check. The limit of 10 upload URL requests per IP per minute is the guard against abuse.

The Mux Token Secret is never written back into the settings form. Leave the field blank to keep the saved value, or tick the checkbox to clear it.

= Clean-up =

When a comment is permanently deleted, or an administrator clears its video, the Mux asset is deleted in the background by WP-Cron, with up to five attempts. Uploads that never became a comment are removed from Mux by a daily sweep once they are a day old.

Deleting the plugin removes its settings, comment video meta, rate-limit counters, and scheduled events. Videos already in your Mux account are left alone.

= Privacy =

The plugin adds suggested text to the Privacy Policy Guide, includes a commenter's videos in personal data exports, and removes the videos (and schedules their deletion from Mux) when a commenter's data is erased.

= Filters =

`video_comments_mux_player_src` replaces or disables the Mux player script URL:

`add_filter( 'video_comments_mux_player_src', '__return_false' );`

`video_comments_player_attrs` changes the attributes on the `<mux-player>` element:

`add_filter( 'video_comments_player_attrs', function ( $attrs, $playback_id ) {
    $attrs['muted'] = '';
    return $attrs;
}, 10, 2 );`

`video_comments_client_ip` returns the real visitor address behind a reverse proxy or CDN, so the upload rate limit is not shared by everyone behind the proxy:

`add_filter( 'video_comments_client_ip', function ( $ip ) {
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
} );`

`video_comments_allow_video_only` requires comment text even when a video is attached. It runs when the comment form renders (with empty comment data) and again on submission:

`add_filter( 'video_comments_allow_video_only', '__return_false' );`

== Installation ==

1. Upload the `video-comments` folder to `wp-content/plugins/` and activate the plugin.
2. In the Mux dashboard, go to Settings > API Access Tokens and generate a token with Mux Video full access.
3. In WordPress, go to Settings > Video Comments and enter the Token ID and Token Secret.

To keep the credentials out of the database, define them in `wp-config.php` instead. The settings fields are hidden when the constants exist:

`define( 'VIDEO_COMMENTS_MUX_TOKEN_ID',     'your-token-id' );`
`define( 'VIDEO_COMMENTS_MUX_TOKEN_SECRET', 'your-token-secret' );`

== Frequently Asked Questions ==

= Do videos need to be moderated separately? =

No. Approve or trash the comment and the video goes with it.

= Can a comment be a video with no text? =

Yes, once the upload is ready. The server accepts the empty comment only when the submission carries the plugin's form nonce, the upload ID, its one-time token, and Mux confirms the video is playable. Comments created through the REST API or XML-RPC still need text. Add the `video_comments_allow_video_only` filter to keep requiring text.

= Which file types are accepted? =

mp4, mov, webm, mkv, avi, m4v, ogv, ts, and mts. The extension is checked before an upload URL is issued; Mux does the real format check when it processes the file.

= What happens to a video when its comment is deleted? =

Trashing a comment hides the video with it. Permanently deleting the comment schedules the Mux asset for deletion.

= Does it work with the WordPress privacy tools? =

Yes. See the Privacy section above.

= Will it conflict with a caching plugin? =

The REST endpoints are under `/wp-json/`, which most page caches skip. If you cache pages for logged-in users you may need to exclude pages with comments open.

= The player is not showing. =

Check that your theme calls `wp_footer()`, that the Mux credentials are saved, and that your comment walker does not drop the `comment_text` filter output.

== Screenshots ==

1. The video uploader on the comment form.
2. A rendered `<mux-player>` below a comment.
3. The settings page under Settings > Video Comments.

== Changelog ==

= 1.2.1 =
* Fixed a comment with its own video being refused as a duplicate when its text repeated an earlier comment. WordPress compared only the author and text, so the video had already uploaded before the comment was rejected.

= 1.2.0 =
* Added video-only comments. Once the upload is ready the text box becomes optional, and WordPress accepts the empty comment when the video checks out. Comments made through the REST API or XML-RPC still need text.
* Added the `video_comments_allow_video_only` filter to keep requiring text.
* Added a "[Video comment]" label for video-only comments in the admin Comments list, linking to the edit screen.
* Added a line to the new-comment and moderation emails when a comment includes a video.
* Fixed video-only comments not rendering in block themes, whose Comment Content block skips empty comments.
* Changed the order of operations so the video is attached to the comment before WordPress sends its notification emails.

= 1.1.1 =
* Fixed automatic updates from GitHub releases so they always download the plugin zip. Version 1.1.0 could pick readme.txt as the update package, so sites on 1.1.0 need to install this version by hand once.

= 1.1.0 =
* Security: each upload now carries a one-time token. Only the browser that started an upload can check its status or remove it.
* Security: the playback and asset IDs are looked up from Mux when the comment is saved instead of being accepted from the form.
* Security: the Mux Token Secret is no longer written back into the settings form. Leave the field blank to keep it, or tick the new checkbox to clear it.
* Fixed videos not playing on posts after comments were closed.
* Added background removal of Mux assets, with retries, when a comment is permanently deleted or an administrator clears its video.
* Added a daily task that removes uploads that never became a comment.
* Added privacy tools support: suggested policy text, personal data export, and erasure.
* Added an uninstall routine that removes settings, comment video data, counters, and scheduled tasks.
* Added the `video_comments_client_ip` filter for sites behind a reverse proxy or CDN.
* Changed the file picker to be reachable by keyboard, fixed the uploader's colour contrast, and reduced screen reader output to a single processing announcement.
* Changed the Mux player to load pinned at version 3, and only on pages that show a video or the uploader.
* Changed status polling to be rate limited.
* Changed the documentation of the upload size limit to describe it as a browser-side check, since files go straight from the browser to Mux.
* Tested up to WordPress 7.1.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Comments can now be a video on their own, with no text. Nothing to configure; add the video_comments_allow_video_only filter if your site should keep requiring text.

= 1.1.1 =
Fixes the GitHub release updater choosing the wrong asset. Sites on 1.1.0 must install this version by hand once; updates work automatically from then on.

= 1.1.0 =
Security hardening of the upload flow, background clean-up of Mux assets, privacy tools support, and accessibility fixes. Pages cached before the update need a refresh before their first upload.

= 1.0.0 =
Initial release.
