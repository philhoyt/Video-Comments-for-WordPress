<?php
/**
 * Core plugin loader.
 *
 * @package Video_Comments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class — wires up all subsystems.
 */
final class Video_Comments {

	/** @var Video_Comments|null */
	private static ?Video_Comments $instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private constructor — use get_instance(). */
	private function __construct() {}

	/**
	 * Require all dependencies and register hooks.
	 */
	public function init(): void {
		$this->load_dependencies();

		// Initialise subsystems.
		Video_Comments_Settings::get_instance()->init();
		Video_Comments_REST::get_instance()->init();
		Video_Comments_Comment_Meta::get_instance()->init();
		Video_Comments_Render::get_instance()->init();
	}

	/**
	 * Load all required files.
	 */
	private function load_dependencies(): void {
		$dir = VIDEO_COMMENTS_PLUGIN_DIR . 'includes/';

		require_once $dir . 'providers/interface-provider.php';
		require_once $dir . 'providers/class-provider-mux.php';
		require_once $dir . 'class-settings.php';
		require_once $dir . 'class-rest.php';
		require_once $dir . 'class-comment-meta.php';
		require_once $dir . 'class-render.php';
	}
}
