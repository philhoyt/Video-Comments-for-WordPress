<?php
/**
 * Uninstall handler: remove everything the plugin stored in this site's database.
 *
 * Runs when the plugin is deleted from the Plugins screen. Videos already
 * uploaded remain in the site owner's Mux account; this only removes the
 * WordPress side (settings, comment meta, transients and scheduled events).
 *
 * @package Video_Comments
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site.
 */
function video_comments_uninstall_site(): void {
	global $wpdb;

	delete_option( 'video_comments_settings' );
	delete_option( 'video_comments_pending_uploads' );

	foreach ( [ 'video_comments_provider', 'video_comments_mux_playback_id', 'video_comments_mux_asset_id' ] as $meta_key ) {
		delete_metadata( 'comment', 0, $meta_key, '', true );
	}

	// Rate-limit counters and upload ownership records (all prefixed video_comments_).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup at uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_video_comments_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_video_comments_' ) . '%'
		)
	);

	wp_unschedule_hook( 'video_comments_sweep_uploads' );
	wp_unschedule_hook( 'video_comments_delete_asset' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $video_comments_site_id ) {
		switch_to_blog( (int) $video_comments_site_id );
		video_comments_uninstall_site();
		restore_current_blog();
	}
} else {
	video_comments_uninstall_site();
}
