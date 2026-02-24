<?php
/**
 * Plugin Name:       Video Comments
 * Plugin URI:        https://github.com/your-org/video-comments
 * Description:       Extends WordPress comments to support uploading and displaying a video per comment via Mux.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       video-comments
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'VIDEO_COMMENTS_VERSION', '1.0.0' );
define( 'VIDEO_COMMENTS_PLUGIN_FILE', __FILE__ );
define( 'VIDEO_COMMENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIDEO_COMMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
function video_comments_init(): void {
	// Load text domain.
	load_plugin_textdomain(
		'video-comments',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);

	// Load core class and initialise.
	require_once VIDEO_COMMENTS_PLUGIN_DIR . 'includes/class-video-comments.php';
	Video_Comments::get_instance()->init();
}
add_action( 'plugins_loaded', 'video_comments_init' );

/**
 * Activation hook — no DB changes needed in v1.
 */
function video_comments_activate(): void {
	// Flush rewrite rules in case REST routes need it (usually not, but safe).
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'video_comments_activate' );

/**
 * Deactivation hook.
 */
function video_comments_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'video_comments_deactivate' );
