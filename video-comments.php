<?php
/**
 * Plugin Name:       Video Comments using Mux
 * Plugin URI:        https://github.com/philhoyt/Video-Comments-for-WordPress
 * Description:       Extends WordPress comments to support uploading and displaying a video per comment via Mux.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Tested up to:      6.9
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

declare(strict_types=1);

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'VIDEO_COMMENTS_VERSION', '1.0.0' );
define( 'VIDEO_COMMENTS_PLUGIN_FILE', __FILE__ );
define( 'VIDEO_COMMENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIDEO_COMMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Plugin Update Checker: serves updates from GitHub releases (video-comments.zip asset).
require_once VIDEO_COMMENTS_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

$video_comments_update_checker     = PucFactory::buildUpdateChecker(
	'https://github.com/philhoyt/Video-Comments-for-WordPress/',
	__FILE__,
	'video-comments'
);
$video_comments_update_checker_api = $video_comments_update_checker->getVcsApi();
if ( method_exists( $video_comments_update_checker_api, 'enableReleaseAssets' ) ) {
	$video_comments_update_checker_api->enableReleaseAssets();
}

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
 * Activation hook: schedule the daily sweep of abandoned uploads.
 *
 * The plugins_loaded hook has already fired in the activation request, so the plugin
 * classes are not loaded here; use the hook name directly.
 */
function video_comments_activate(): void {
	if ( ! wp_next_scheduled( 'video_comments_sweep_uploads' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'video_comments_sweep_uploads' );
	}
}
register_activation_hook( __FILE__, 'video_comments_activate' );

/**
 * Deactivation hook: remove the plugin's scheduled events.
 */
function video_comments_deactivate(): void {
	wp_unschedule_hook( 'video_comments_sweep_uploads' );
	wp_unschedule_hook( 'video_comments_delete_asset' );
}
register_deactivation_hook( __FILE__, 'video_comments_deactivate' );
