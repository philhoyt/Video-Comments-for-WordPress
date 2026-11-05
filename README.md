# Video Comments using Mux

[![CI](https://github.com/philhoyt/Video-Comments-for-WordPress/actions/workflows/ci.yml/badge.svg)](https://github.com/philhoyt/Video-Comments-for-WordPress/actions/workflows/ci.yml)

Adds a video upload field to the WordPress comment form; the file goes straight to [Mux](https://www.mux.com/) and WordPress stores only the playback ID.

## Requirements

- WordPress 6.0 or later (tested up to 7.1)
- PHP 7.4 or later
- A [Mux](https://dashboard.mux.com/) account and an API access token with Mux Video full access

## Installation

1. Copy the `video-comments` folder into `wp-content/plugins/` and activate it.
2. In the Mux dashboard, go to Settings > API Access Tokens, click Generate new token, and give it Mux Video full access. Copy the Token ID and Token Secret; the secret is shown once.
3. In WordPress, go to Settings > Video Comments and paste both values.

To keep the credentials out of the database, define them in `wp-config.php`. The settings fields are hidden when the constants exist:

```php
define( 'VIDEO_COMMENTS_MUX_TOKEN_ID',     'your-token-id' );
define( 'VIDEO_COMMENTS_MUX_TOKEN_SECRET', 'your-token-secret' );
```

## Usage

With credentials saved, the comment form gets a video field. The commenter picks a file, the upload runs, and the submit button is enabled once Mux reports the video playable. The video is rendered below the comment text with `<mux-player>`.

Videos follow the comment. A comment held for moderation does not show its video, and there is no separate moderation queue. Trashing a comment hides the video; permanently deleting it schedules the Mux asset for deletion.

### How an upload works

1. The browser asks the plugin's REST endpoint for an upload URL. The request is rate limited to 10 per IP per minute, the reported file size is checked against the Max Upload Size setting, and the extension must be one of mp4, mov, webm, mkv, avi, m4v, ogv, ts, or mts.
2. WordPress returns a short-lived Mux direct upload URL and a one-time token for that upload. Only the browser holding the token can poll the upload's status or remove it. Polling is rate limited to 60 requests per IP per minute.
3. The browser PUTs the file to Mux and polls until Mux has processed it.
4. The comment form submits the upload ID and token. WordPress looks the upload up in Mux and stores the playback and asset IDs it gets back as comment meta. IDs are never accepted from the browser, so a comment can only carry a video uploaded through this site.

### Video-only comments

Once a video is ready, the comment text is optional. The browser stops requiring the text box, and the server accepts an empty comment only when the submission carries the plugin's form nonce, the upload ID, its one-time token, and Mux confirms the video is playable. Anything else gets the usual "Please type your comment text." error. This applies to the standard comment form only; comments created through the REST API or XML-RPC still need text.

Related behaviour:

- WordPress normally rejects a second comment whose text matches an earlier one by the same person. A comment that carries its own finished video skips that check, since two video-only comments both have empty text. The 15-second gap between comments from the same person still applies.
- Moderation keys, the disallowed-comment list, and the link limit only look at the comment text, so they cannot catch anything in a video-only comment.
- In the admin Comments list a video-only comment shows as "[Video comment]" with a link to its edit screen, where the video plays. The new-comment and moderation emails include a line pointing at the video.
- The video is the whole comment. Deactivating the plugin, or clearing the playback ID on the comment's edit screen, leaves the comment blank. Trash the comment if you want it gone.

Use the `video_comments_allow_video_only` filter to keep requiring text.

## Configuration

Settings > Video Comments:

| Setting | Default | Notes |
|---|---|---|
| Enable Video Comments | On | Turns the uploader on or off sitewide. |
| Allow Guests | On | Lets visitors who are not logged in upload. |
| Max Upload Size (MB) | 50 | Checked in the browser and against the size the browser reports when requesting an upload URL. The file goes straight to Mux, so a modified client can bypass it; the per-IP rate limit is the guard against abuse. |

The Mux Token Secret is never written back into the form. Leave the field blank to keep the saved value, or tick the checkbox to clear it.

### Filters

`video_comments_mux_player_src` replaces or disables the Mux player script URL. The plugin loads `@mux/mux-player@3` from jsDelivr only on pages that show a video or the uploader.

```php
// Already loading mux-player in your theme? Skip the plugin's copy.
add_filter( 'video_comments_mux_player_src', '__return_false' );
```

`video_comments_player_attrs` adds or changes attributes on `<mux-player>`:

```php
add_filter( 'video_comments_player_attrs', function ( $attrs, $playback_id ) {
    $attrs['muted'] = '';
    return $attrs;
}, 10, 2 );
```

`video_comments_client_ip` returns the real visitor address behind a reverse proxy or CDN, so the rate limit is not shared by everyone behind the proxy:

```php
add_filter( 'video_comments_client_ip', function ( $ip ) {
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
} );
```

`video_comments_allow_video_only` requires text even when a video is attached. It runs twice: when the comment form renders, with an empty `$commentdata`, to decide whether the browser may relax the text box; and on submission, with WordPress's comment data, before the upload is checked with Mux.

```php
// Always require text.
add_filter( 'video_comments_allow_video_only', '__return_false' );

// Or decide per post on submission (the form still relaxes the text box).
add_filter( 'video_comments_allow_video_only', function ( $allow, $commentdata ) {
    if ( empty( $commentdata ) ) {
        return $allow; // Render time: nothing to decide on yet.
    }
    return has_term( 'video-replies', 'category', (int) $commentdata['comment_post_ID'] );
}, 10, 2 );
```

## Clean-up and privacy

When a comment is permanently deleted, or an administrator clears its video, the Mux asset is deleted in the background by the `video_comments_delete_asset` cron event, with up to five attempts. The daily `video_comments_sweep_uploads` event removes uploads that never became a comment once they are a day old.

Deleting the plugin removes its settings, comment meta, transients, and scheduled events on every site in a multisite network. Videos already in your Mux account are left alone.

The plugin adds suggested text to Settings > Privacy > Policy Guide, includes a commenter's videos in exports from Tools > Export Personal Data, and removes the videos (scheduling their deletion from Mux) on an erasure request.

## Development

The front-end script and stylesheet in `assets/` are plain files with no build step.

```bash
npm install
composer install        # also copies Plugin Update Checker into lib/

npm run lint            # eslint on assets/ and stylelint on assets/*.css
composer lint           # phpcs
composer analyse        # phpstan

npm run env:start       # wp-env
npm run test:php        # PHPUnit inside wp-env (tests/phpunit/)
npm run make-pot        # regenerate languages/video-comments.pot
npm run plugin-zip      # build video-comments.zip
```

CI runs `composer audit`, phpcs, and phpstan on a PHP matrix, PHPUnit against wp-env, and the JS and CSS linters.

## Releases

Bump the version in `video-comments.php` (header and `VIDEO_COMMENTS_VERSION`), `readme.txt` (Stable tag and a `= X.Y.Z =` changelog entry), and `package.json`, then push a `v` tag:

```bash
git tag v1.2.1 && git push origin v1.2.1
```

The release workflow lints, runs phpstan, builds `video-comments.zip` with `wp-scripts plugin-zip`, pulls the matching changelog section out of `readme.txt` for the release notes (and fails if there is none), and attaches the zip and `readme.txt` to the GitHub release. Installed sites pick the release up through Plugin Update Checker.

## Limitations

- Mux only. The provider interface in `includes/providers/` has a single implementation.
- The upload size limit is enforced in the browser and on the reported size, not on the bytes Mux receives.
- Comment text filters (moderation keys, disallowed list, link limit) do not inspect the video.
- Video-only comments work on the standard comment form only, not through the REST API or XML-RPC.

## FAQ

**Do videos need separate approval?**
No. Approve or hold the comment and the video follows.

**Will this break with a caching plugin?**
The REST endpoints sit under `/wp-json/`, which most page caches skip. If you cache pages for logged-in users you may need to exclude pages with comments open.

**The player is not showing.**
Check that your theme calls `wp_footer()`, that the Mux credentials are saved, and that your comment walker does not drop the `comment_text` filter output.

## License

GPL-2.0-or-later
