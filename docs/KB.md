# Knowledge Base — Video Comments using Mux

A per-project lookup table for API constraints, version-specific gotchas, and findings from external docs. Not prose — one line per entry.

Format: `topic — finding — source URL`

## WordPress

- Nonces — `wp_create_nonce()` for a logged-out visitor hashes user 0 plus an empty session token, so every guest on the site shares one nonce value per 12-hour tick; a guest nonce proves "came from a page of this site", never "this visitor", and must not be used as an ownership key — https://developer.wordpress.org/reference/functions/wp_create_nonce/

## Block editor

- (none yet)

## Third-party libraries

- ESLint — `@wordpress/eslint-plugin` exports flat configs from v25; v28 peers `eslint ^9 || ^10` and `@wordpress/scripts` 37 pins `eslint ^10`, so the toolchain is on ESLint 10 (flat config only; `.eslintrc.js` is ignored and flat config never climbs parent directories, which fixes the worktree crash) — https://www.npmjs.com/package/@wordpress/eslint-plugin
- ESLint — from v9 `no-unused-vars` defaults `caughtErrors` to `"all"`, so `catch ( err )` with an unused `err` is an error; use the optional catch binding `catch {` — https://eslint.org/docs/latest/rules/no-unused-vars

## Local environment

- Local site — `bin/wp.sh` targets the `climbchippewa06` Local site (id `jBqyYOXFS`); WooCommerce Payments on that site exhausts PHP's 128M default, so the wrapper passes `-d memory_limit=512M` — n/a
- Composer — global binary at `/usr/local/bin/composer` is 2.7.6 and prints deprecation notices on PHP 8.5; `composer self-update` needs `sudo` because `/usr/local/bin` is not user-writable — https://getcomposer.org/download/
- wp-env — `.wp-env.json` pins PHP 7.4 (plugin minimum); `composer config platform.php` is pinned to 7.4.99 so `vendor/` resolves for the container, which forces doctrine/instantiator 1.5 and deep-copy 1.13 — n/a
