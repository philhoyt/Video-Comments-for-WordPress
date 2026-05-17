# Video Comments — Audit Report

**Date:** 2026-05-17
**WordPress latest stable:** 6.9.4
**Scanned:** 8 PHP files · 0 blocks · 1 JS file · 1 CSS file

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 0 |
| Warning  | 5 |
| Info     | 3 |

---

## Status

| ID | Issue | Status |
|----|-------|--------|
| SEC-01 | No ownership check on DELETE /mux/video endpoint | ✅ Fixed |
| STD-01 | `declare(strict_types=1)` missing from all PHP files | ✅ Fixed |
| STD-02 | `Tested up to` inconsistency — plugin header says 7.0 (non-existent), readme.txt says 6.7 | ✅ Fixed |
| STD-03 | No ESLint config — JS assets not checked against WordPress JS standards | ✅ Fixed |
| ADM-01 | `wp_localize_script` used for REST nonce (prefer `wp_add_inline_script`) | ✅ Fixed |
| TST-01 | No PHP test suite | open |
| TST-02 | No REST endpoint integration tests | open |
| INF-01 | `readme.txt` Tested up to: 6.7 is stale relative to stable 6.9.4 | ✅ Fixed |

---

## Warnings

### [SEC-01] No ownership check on the DELETE /mux/video endpoint

**File:** `includes/class-rest.php:88–108`
**Code:**
```php
register_rest_route( self::NAMESPACE, '/mux/video', [
    'methods'             => WP_REST_Server::DELETABLE,
    'callback'            => [ $this, 'handle_delete_video' ],
    'permission_callback' => [ $this, 'check_upload_permission' ],
    ...
] );
```

**Context:** The `check_upload_permission` gate requires only a valid `vc_upload_nonce` and the guest/enabled checks. The `vc_upload_nonce` is output on every comment-enabled page via `wp_localize_script` and is therefore visible to any visitor. Once a caller has the nonce, they can send any `asset_id` value to the DELETE endpoint and the plugin will forward a deletion request to Mux without verifying that the asset belongs to the caller's session.

**Practical risk:** Low-to-medium. Mux asset IDs are 26-character opaque tokens that are never rendered in public HTML; an attacker would need to obtain one through another channel (e.g., sniffing REST polling responses, reading comment meta with admin access). However, the absence of any ownership model is a design weakness that could become exploitable if asset IDs were ever exposed.

**Fix:** When `create_direct_upload` succeeds, store the returned `upload_id` in a short-lived transient keyed by the nonce or session identifier. In `handle_delete_video`, verify that the requested `upload_id` or `asset_id` matches a value previously issued to this caller before forwarding the deletion to Mux.

```php
// In handle_direct_upload(), after a successful Mux response:
set_transient( 'vc_upload_' . md5( $nonce . '_' . $upload_id ), 1, HOUR_IN_SECONDS );

// In handle_delete_video(), before calling delete_asset():
$upload_key = 'vc_upload_' . md5( $nonce . '_' . $upload_id );
if ( ! get_transient( $upload_key ) ) {
    return new WP_Error( 'vc_not_owner', __( 'You are not authorised to delete this video.', 'video-comments' ), [ 'status' => 403 ] );
}
```

---

### [STD-01] `declare(strict_types=1)` missing from all PHP files

**Files:** All 8 PHP files
**Context:** The project's coding-style rule (`.claude/rules/wordpress/coding-style.md`) explicitly requires `declare(strict_types=1)` immediately after the opening `<?php` tag in every PHP file. None of the plugin's files include this declaration. Without it, PHP performs implicit type coercion, which can mask bugs (e.g., a string `"0"` being coerced to `false` in a boolean context).

**Fix:** Add `declare(strict_types=1);` as the second line of each PHP file, before any namespace, use, or class declarations. For example, in `video-comments.php`:

```php
<?php
declare(strict_types=1);

/**
 * Plugin Name: Video Comments using Mux
 * ...
 */
```

All 8 files require this change: `video-comments.php`, `class-video-comments.php`, `class-settings.php`, `class-rest.php`, `class-comment-meta.php`, `class-render.php`, `class-provider-mux.php`, `interface-provider.php`.

---

### [STD-02] `Tested up to` version is inconsistent across files and contains a non-existent WordPress version

**Files:** `video-comments.php:8`, `readme.txt:5`
**Code:**
- Plugin header: `Tested up to: 7.0`
- readme.txt: `Tested up to: 6.7`

**Context:** WordPress 7.0 does not exist (current stable is 6.9.4; no 7.x prerelease is active). The plugin header value is almost certainly a typo. The two files must agree — WordPress.org and Plugin Update Checker read `readme.txt`, while IDEs and some tooling read the plugin header.

**Fix:** Decide on the correct version (recommend `6.9` to reflect current testing) and update both files to match:

```
# video-comments.php header
Tested up to: 6.9

# readme.txt
Tested up to: 6.9
```

---

### [STD-03] No ESLint configuration file — JS not validated against WordPress standards

**Context:** `package.json` defines `"lint:js": "eslint assets/"` but there is no `.eslintrc.js` or `eslint.config.js`. When ESLint runs without a config it applies no rules, so the command always exits successfully regardless of code quality. The codebase uses `@wordpress/eslint-plugin` as a dev dependency (via `@wordpress/scripts`) but never wires it up.

`@wordpress/scripts` v31.5.0 bundles ESLint v8, so the legacy `.eslintrc.js` format applies (not the v9 flat config).

**Fix:** Create `.eslintrc.js` in the project root. Since the script is vanilla JS (no JSX/React), disable the React rules:

```js
module.exports = {
    extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
    env: {
        browser: true,
    },
    rules: {
        'react/react-in-jsx-scope': 'off',
        'react/prop-types': 'off',
    },
};
```

After adding the config, run `npm run lint:js` to surface any new violations (expect spacing and `var`-vs-`const` findings).

---

### [ADM-01] `wp_localize_script` used to pass the REST nonce

**File:** `includes/class-render.php:88–118`
**Code:**
```php
wp_localize_script(
    'video-comments',
    'vcSettings',
    [
        ...
        'restNonce' => wp_create_nonce( 'wp_rest' ),
        ...
    ]
);
```

**Context:** The WordPress coding-standards guidance is to pass REST nonces via `wp_add_inline_script` rather than `wp_localize_script`. `wp_localize_script` serialises the value at the time the script is registered, before filters that might affect nonce generation have run, and it uses a JSON-unsafe variable assignment that can conflict with minifiers. `wp_add_inline_script` with `'before'` position is the preferred approach and avoids these issues. Additionally, `wp_localize_script` is flagged as deprecated for data-passing in the JS standards docs.

**Fix:** Replace `wp_localize_script` with `wp_add_inline_script`:

```php
wp_add_inline_script(
    'video-comments',
    sprintf(
        'window.vcSettings = %s;',
        wp_json_encode( [
            'restUrl'        => esc_url_raw( rest_url( Video_Comments_REST::NAMESPACE ) ),
            'nonce'          => wp_create_nonce( 'vc_upload_nonce' ),
            'restNonce'      => wp_create_nonce( 'wp_rest' ),
            'maxSizeMb'      => Video_Comments_Settings::max_size_mb(),
            'hasCredentials' => Video_Comments_Settings::has_mux_credentials(),
            'allowGuests'    => Video_Comments_Settings::allow_guests(),
            'isLoggedIn'     => is_user_logged_in(),
            'i18n'           => [ /* ... same i18n array ... */ ],
        ] )
    ),
    'before'
);
```

Update all references in `assets/video-comments.js` from `window.vcSettings` — they already use `window.vcSettings`, so no JS changes are needed.

---

## Info

### [TST-01] No PHP test suite

**Recommendation:** The plugin has no `phpunit.xml`, no `tests/` directory, and no test bootstrap. Security-critical paths — nonce verification (`save_comment_video_meta`, `save_admin_comment_meta`), permission callbacks in the REST class, and settings sanitization — are entirely untested. A regression in any of these would not be caught before shipping.

Minimum recommended coverage:
- `Video_Comments_Settings::sanitize_options()` — verify each field is correctly sanitized and clamped
- `Video_Comments_REST::check_upload_permission()` — verify nonce failure, guest-disabled, and feature-disabled paths
- `Video_Comments_Comment_Meta::save_comment_video_meta()` — verify nonce check, playback_id format validation, and empty-playback skip

Set up PHPUnit with `wp-env` and target at least 80% line coverage on `includes/`.

---

### [TST-02] No REST endpoint integration tests

**Recommendation:** The three REST routes (`POST /mux/direct-upload`, `GET /mux/upload-status`, `DELETE /mux/video`) have no automated tests. At minimum, test:

- All three routes return `403` when the nonce is missing or invalid
- All three routes return `403` when `Video_Comments_Settings::is_enabled()` is false
- `POST /mux/direct-upload` returns `415` for a disallowed file extension
- `POST /mux/direct-upload` returns `413` when `file_size` exceeds the configured limit
- `GET /mux/upload-status` returns `400` when `upload_id` is empty

Use `WP_Test_REST_TestCase` as the base class and mock the Mux provider via a test double to avoid live API calls.

---

### [INF-01] readme.txt `Tested up to: 6.7` is stale

**File:** `readme.txt:5`
**Context:** The current WordPress stable is 6.9.4. The readme.txt value of 6.7 is two minor versions behind. While this does not cause a plugin malfunction, it signals to prospective users that the plugin has not been verified against recent WordPress releases, which can reduce install confidence. This should be updated whenever testing occurs against a new WP release. (See also STD-02 — the plugin header value and readme.txt must agree.)

---

## Quick Wins

1. **Fix `Tested up to` in both files (5 minutes)** — Update `video-comments.php` header from `7.0` to `6.9` and `readme.txt` from `6.7` to `6.9`. Eliminates the invalid-version confusion and syncs the two files.
2. **Add `.eslintrc.js` (10 minutes)** — Enables actual WordPress JS standards enforcement on `assets/video-comments.js`. Currently `npm run lint:js` runs with no rules and always passes.
3. **Add `declare(strict_types=1)` to all PHP files (15 minutes)** — Can be applied with a one-liner sed or phpcbf once the sniff is added to `phpcs.xml`. Closes the project-standard gap across all 8 files at once.

---

## Already Fixed / Clean

- **Output escaping** — All `echo`/`print` output is escaped. Pre-escaped variables with `phpcs:ignore` suppression comments are documented with reasons. No false positives detected.
- **Unsanitized input** — All `$_POST` and `$_SERVER` reads use `wp_unslash()` + `sanitize_*()`. REST parameters use `sanitize_callback` on every arg definition.
- **Nonce verification** — Comment form uses `wp_verify_nonce()` before persisting meta. Admin comment edit uses `wp_verify_nonce()`. REST endpoints verify a custom plugin nonce in their `permission_callback`.
- **Capability checks** — Settings page re-checks `current_user_can( 'manage_options' )` in the render callback. Admin comment meta save checks `current_user_can( 'edit_comment', $comment_id )`.
- **SQL injection** — No raw SQL. All data is stored/read via WP meta and options APIs.
- **HTTP requests** — `wp_remote_get()`, `wp_remote_post()`, `wp_remote_request()` used throughout the Mux provider. No `curl_*` or `file_get_contents()`.
- **Hardcoded secrets** — Mux credentials are stored in the DB or in `wp-config.php` constants; none are committed to code.
- **Debug code** — No `var_dump`, `print_r`, `error_log`, or `dd` in any production file.
- **Insecure REST endpoints** — No `__return_true` on any write endpoint. All three routes have meaningful `permission_callback` implementations.
- **ABSPATH guards** — All 8 PHP files open with `defined( 'ABSPATH' ) || exit;`.
- **Prefix consistency** — All functions, classes, hooks, option keys, and comment meta keys use the `video_comments_` / `Video_Comments` prefix.
- **i18n** — All user-facing strings wrapped in `__()`, `_e()`, `esc_html__()`, or `esc_html_e()` with the `video-comments` text domain.
- **Asset loading** — `wp_enqueue_script()` / `wp_enqueue_style()` used throughout; no inline `<script>` or `<link>` tags.
- **Closing PHP tags** — None present.
- **Settings API** — `register_setting()` uses a `sanitize_callback`. Settings page callback re-checks capability.
- **Direct HTTP** — All external API calls go through WP HTTP API.
- **`query_posts()`** — Not used anywhere.
- **Direct DB queries** — No raw SQL against `wp_*` tables.
- **Block development** — No blocks in this plugin (block.json files exist only in node_modules).
- **phpcs** — Passes cleanly against `WordPress` ruleset (8 files, 0 violations).
- **Stylelint** — `.stylelintrc.json` extends `@wordpress/stylelint-config` with a BEM class-pattern rule. CSS file is clean.
