# Video Comments

A production-ready WordPress plugin that extends core WP comments to support uploading and displaying a video per comment, powered by [Mux](https://www.mux.com/).

## Features

- **Direct-to-Mux uploads** — video files go straight from the browser to Mux; WordPress never touches the binary data.
- **Progress tracking** — real-time upload progress bar, processing status, and playback-ready confirmation.
- **Mux Player embed** — uses the official `<mux-player>` custom element for playback.
- **Safe defaults** — submit button disabled while upload is in progress; no broken half-submitted comments.
- **Provider abstraction** — `Video_Comments_Provider` interface makes it straightforward to add Cloudflare Stream or other providers later.
- **Admin settings** — configurable max upload size, enable/disable, guest access toggle.
- **Rate limiting** — transient-based per-IP rate limiting on REST endpoints.
- **Security** — nonce verification on all REST calls and comment saves; input sanitisation throughout.
- **i18n-ready** — all strings wrapped in `__()` / `esc_html__()` with the `video-comments` text domain.
- **Graceful degradation** — if Mux credentials are missing the uploader UI shows a friendly message and comments work normally.

---

## Requirements

| Requirement | Minimum | Recommended |
|---|---|---|
| WordPress | 6.0 | 6.5+ |
| PHP | 7.4 | 8.1+ |
| Mux account | — | — |

---

## Installation

1. Copy (or symlink) the `video-comments` folder into `wp-content/plugins/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Go to **Settings → Video Comments** and enter your Mux credentials (see below).

---

## Mux Setup

### 1. Create a Mux account

Sign up at <https://dashboard.mux.com/>.

### 2. Generate an API token

1. In the Mux dashboard, open **Settings → API Access Tokens**.
2. Click **Generate new token**.
3. Grant the token **Mux Video** → **Full access** (or at minimum *write* access for uploads).
4. Copy the **Token ID** and **Token Secret** — the secret is shown only once.

### 3. Enter credentials in WordPress

Go to **Settings → Video Comments** and paste the Token ID and Token Secret into the respective fields.

### Alternatively: define credentials as PHP constants

Add the following to `wp-config.php` (recommended for production — keeps secrets out of the database):

```php
define( 'VIDEO_COMMENTS_MUX_TOKEN_ID',     'your-token-id' );
define( 'VIDEO_COMMENTS_MUX_TOKEN_SECRET', 'your-token-secret' );
```

When constants are defined the settings fields will show "Set via constant in wp-config.php" and cannot be overridden from the admin UI.

---

## Settings Reference

| Setting | Default | Description |
|---|---|---|
| Enable Video Comments | On | Master switch — disables all uploader UI when off. |
| Allow Guests | On | Whether non-logged-in users can attach videos. |
| Max Upload Size (MB) | 50 | Client-side and server-side size guard. |
| Mux Token ID | — | From the Mux dashboard (or constant). |
| Mux Token Secret | — | From the Mux dashboard (or constant). |

---

## How It Works

### Upload flow

```
Browser                  WordPress REST              Mux API
  │                           │                        │
  ├─ POST /mux/direct-upload ─▶                        │
  │   (nonce, file name/size) │                        │
  │                           ├─ POST /video/v1/uploads▶
  │                           │◀─ {upload_id, url} ────┤
  │◀─ {upload_id, upload_url} ┤                        │
  │                           │                        │
  ├─ PUT file ────────────────────────────────────────▶│
  │◀─ 200 OK ──────────────────────────────────────────┤
  │                           │                        │
  ├─ GET /mux/upload-status ──▶                        │
  │   (poll every 3 s)        ├─ GET /video/v1/uploads/{id}
  │                           │◀─ {status, asset_id} ──┤
  │                           ├─ GET /video/v1/assets/{id}
  │                           │◀─ {status, playback_ids}
  │◀─ {status:"ready",        │                        │
  │    playback_id:"…"} ──────┤                        │
  │                           │                        │
  ├─ POST comment form ───────▶ (hidden field: playback_id)
```

### Comment display

When a comment has a stored `video_comments_mux_playback_id` meta value, the plugin appends:

```html
<div class="video-comment">
  <mux-player playback-id="…" controls playsinline></mux-player>
</div>
```

…directly after the comment text, using the `comment_text` filter.

---

## Developer Hooks & Filters

### `video_comments_mux_player_src` *(filter)*

Override or disable the Mux player CDN script URL.

```php
// Use a self-hosted copy.
add_filter( 'video_comments_mux_player_src', function () {
    return get_stylesheet_directory_uri() . '/js/mux-player.js';
} );

// Disable loading entirely (e.g. your theme already loads it).
add_filter( 'video_comments_mux_player_src', '__return_false' );
```

### `video_comments_player_attrs` *(filter)*

Customise the HTML attributes on `<mux-player>`.

```php
add_filter( 'video_comments_player_attrs', function ( $attrs, $playback_id ) {
    $attrs['muted']    = '';       // Start muted.
    $attrs['loop']     = '';       // Loop.
    $attrs['max-resolution'] = '1080p';
    return $attrs;
}, 10, 2 );
```

---

## Adding a New Provider

1. Create `includes/providers/class-provider-yourprovider.php` implementing `Video_Comments_Provider`.
2. Implement `create_direct_upload()` and `get_upload_status()`.
3. Update `Video_Comments_REST::get_provider()` to return your new class when selected.
4. Add a provider selector to the settings page if needed.

---

## File Structure

```
video-comments/
├── video-comments.php                  # Plugin bootstrap & header
├── includes/
│   ├── class-video-comments.php        # Core loader / singleton
│   ├── class-settings.php              # Admin settings page & option helpers
│   ├── class-rest.php                  # REST API endpoints
│   ├── class-comment-meta.php          # Meta save/read + form nonce
│   ├── class-render.php                # Uploader UI & player output
│   └── providers/
│       ├── interface-provider.php      # Provider contract
│       └── class-provider-mux.php      # Mux implementation
├── assets/
│   ├── video-comments.js               # Vanilla JS upload client
│   └── video-comments.css              # Minimal, theme-agnostic styles
├── languages/
│   └── video-comments.pot              # Translation template
└── README.md
```

---

## Security Notes

- REST endpoints require a WordPress nonce (`vc_upload_nonce`) — guests receive one via `wp_localize_script`.
- Comment saves require a second nonce (`vc_comment_submit`) tied to the specific form submission.
- File extension validation is performed server-side (best-effort; Mux performs final validation).
- File size is checked both client-side (immediate feedback) and server-side (enforce the limit).
- Rate limiting: 10 REST requests per IP per 60-second window (transient-based).
- No video binary data is ever stored in WordPress — only Mux IDs.
- Mux credentials can be stored as PHP constants in `wp-config.php` to keep them out of the database.

---

## FAQ

**Q: Do commenters' videos need manual approval before they appear?**
A: The plugin stores the `playback_id` in comment meta and displays the player whenever the comment is visible. Use WordPress's built-in comment moderation (hold all, require prior approval, etc.) to control comment visibility — the video follows the comment.

**Q: What video formats are supported?**
A: Mux accepts MP4, MOV, WebM, MKV, AVI, M4V, OGV, and more. The file-input uses `accept="video/*"` and the server validates the extension against a whitelist.

**Q: Can I use this with a caching plugin?**
A: Yes. The REST endpoints are not cached by page caching plugins (they use the `/wp-json/` path). The nonce is generated per page load, so ensure your caching plugin does not cache pages for logged-in users, or use fragment / ESI caching for the nonce if needed.

**Q: The player doesn't appear — what should I check?**
A: 1) Confirm Mux credentials are set. 2) Check the browser console for script errors. 3) Ensure the theme calls `wp_footer()` (required for scripts enqueued in the footer). 4) Check that `comment_text` filters are not stripped by your theme's comment walker.

---

## License

GPL-2.0-or-later — see <https://www.gnu.org/licenses/gpl-2.0.html>.
