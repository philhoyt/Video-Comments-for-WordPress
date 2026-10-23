<?php
/**
 * PHPStan bootstrap: constants that video-comments.php defines at runtime.
 *
 * PHPStan analyses includes/ without executing the main plugin file, so the
 * plugin's own constants would otherwise be reported as undefined.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

define( 'VIDEO_COMMENTS_VERSION', '0.0.0' );
define( 'VIDEO_COMMENTS_PLUGIN_FILE', __FILE__ );
define( 'VIDEO_COMMENTS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'VIDEO_COMMENTS_PLUGIN_URL', 'https://example.com/' );
