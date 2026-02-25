<?php
/**
 * Plugin Name:       Video Comments
 * Plugin URI:        https://github.com/philhoyt/Video-Comments-for-WordPress
 * Description:       Extends WordPress comments to support uploading and displaying a video per comment via Mux.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            philhoyt
 * Author URI:        https://philhoyt.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       video-comments
 * Domain Path:       /languages
 *
 * @package           Video_Comments
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
 * Add a Settings link on the Plugins list page.
 *
 * @param array<string,string> $links Existing action links.
 * @return array<string,string>
 */
function video_comments_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=video-comments' ) ),
		esc_html__( 'Settings', 'video-comments' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'video_comments_plugin_action_links' );

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
