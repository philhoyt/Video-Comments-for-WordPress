# Knowledge Base — Video Comments using Mux

A per-project lookup table for API constraints, version-specific gotchas, and findings from external docs. Not prose — one line per entry.

Format: `topic — finding — source URL`

## WordPress

- (none yet)

## Block editor

- (none yet)

## Third-party libraries

- (none yet)

## Local environment

- Local site — `bin/wp.sh` targets the `climbchippewa06` Local site (id `jBqyYOXFS`); WooCommerce Payments on that site exhausts PHP's 128M default, so the wrapper passes `-d memory_limit=512M` — n/a
- Composer — global binary at `/usr/local/bin/composer` is 2.7.6 and prints deprecation notices on PHP 8.5; `composer self-update` needs `sudo` because `/usr/local/bin` is not user-writable — https://getcomposer.org/download/
- wp-env — `.wp-env.json` pins PHP 7.4 (plugin minimum); `composer config platform.php` is pinned to 7.4.99 so `vendor/` resolves for the container, which forces doctrine/instantiator 1.5 and deep-copy 1.13 — n/a
