<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and the plugin.
 *
 * Run through wp-env (`npm run test:php`), which provides the suite at
 * WP_TESTS_DIR, or point WP_TESTS_DIR at a local wordpress-develop checkout.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

$video_comments_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $video_comments_tests_dir ) {
	foreach ( [ '/wordpress-phpunit', rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib' ] as $video_comments_candidate ) {
		if ( file_exists( $video_comments_candidate . '/includes/functions.php' ) ) {
			$video_comments_tests_dir = $video_comments_candidate;
			break;
		}
	}
}

if ( ! $video_comments_tests_dir || ! file_exists( $video_comments_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found. Run `npm run test:php` (wp-env) or set WP_TESTS_DIR.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $video_comments_tests_dir . '/includes/functions.php';

/**
 * Load the plugin before the WordPress test suite boots.
 */
function video_comments_tests_load_plugin(): void {
	require dirname( __DIR__, 2 ) . '/video-comments.php';
}
tests_add_filter( 'muplugins_loaded', 'video_comments_tests_load_plugin' );

require $video_comments_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/trait-mux-mock.php';
