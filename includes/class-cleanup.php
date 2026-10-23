<?php
/**
 * Background clean-up of Mux assets.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Deletes Mux assets in the background and sweeps uploads that never became
 * a comment.
 *
 * Two WP-Cron hooks:
 * - video_comments_delete_asset (single events): deletes one asset, retrying
 *   with back-off when the Mux API fails, so admin requests never block on
 *   an outbound HTTP call.
 * - video_comments_sweep_uploads (daily): looks at uploads created more than a
 *   day ago that were never attached to a comment and deletes their assets.
 */
final class Video_Comments_Cleanup {

	/** Single-event hook: delete one asset. Args: asset_id, attempt. */
	public const DELETE_HOOK = 'video_comments_delete_asset';

	/** Recurring hook: sweep abandoned uploads. */
	public const SWEEP_HOOK = 'video_comments_sweep_uploads';

	/** Option holding pending uploads as upload_id => created timestamp. */
	public const PENDING_OPTION = 'video_comments_pending_uploads';

	/** Give up deleting an asset after this many attempts. */
	private const MAX_ATTEMPTS = 5;

	/** An upload is considered abandoned once it is this old (seconds). */
	private const ORPHAN_AGE = 86400; // 24 hours.

	/** Drop pending records older than this even if Mux could not be reached (seconds). */
	private const PENDING_MAX_AGE = 604800; // 7 days.

	/** Keep the pending option bounded. */
	private const PENDING_MAX_ENTRIES = 500;

	/**
	 * Singleton instance.
	 *
	 * @var Video_Comments_Cleanup|null
	 */
	private static ?Video_Comments_Cleanup $instance = null;

	/** Singleton accessor. */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private constructor — use get_instance(). */
	private function __construct() {}

	/** Register cron handlers and make sure the sweep is scheduled. */
	public function init(): void {
		add_action( self::DELETE_HOOK, [ $this, 'run_delete_asset' ], 10, 2 );
		add_action( self::SWEEP_HOOK, [ $this, 'sweep_uploads' ] );
		add_action( 'init', [ self::class, 'schedule_sweep' ] );
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	/**
	 * Schedule the daily sweep if it is not already scheduled.
	 * Called on activation and on init (covers sites where the plugin was
	 * already active when this hook was introduced). wp_next_scheduled() reads
	 * the autoloaded cron option, so this is cheap.
	 */
	public static function schedule_sweep(): void {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK );
		}
	}

	/** Remove every scheduled event of this plugin (deactivation). */
	public static function unschedule_all(): void {
		wp_unschedule_hook( self::SWEEP_HOOK );
		wp_unschedule_hook( self::DELETE_HOOK );
	}

	/**
	 * Queue an asset for deletion in the background.
	 *
	 * @param string $asset_id Mux asset ID.
	 * @param int    $attempt  Attempt number (1-based); later attempts back off.
	 */
	public static function schedule_asset_deletion( string $asset_id, int $attempt = 1 ): void {
		if ( '' === $asset_id ) {
			return;
		}

		// 0 s, then 2, 4, 8, 16 minutes.
		$delay = $attempt > 1 ? min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** ( $attempt - 1 ) ) ) : 0;

		wp_schedule_single_event( time() + $delay, self::DELETE_HOOK, [ $asset_id, $attempt ] );
	}

	// -------------------------------------------------------------------------
	// Pending uploads
	// -------------------------------------------------------------------------

	/**
	 * Remember that an upload was created so the sweep can find it later if it
	 * never becomes a comment.
	 *
	 * @param string $upload_id Mux upload ID.
	 */
	public static function record_pending_upload( string $upload_id ): void {
		if ( '' === $upload_id ) {
			return;
		}

		$pending               = self::get_pending();
		$pending[ $upload_id ] = time();

		if ( count( $pending ) > self::PENDING_MAX_ENTRIES ) {
			asort( $pending );
			$pending = array_slice( $pending, -self::PENDING_MAX_ENTRIES, null, true );
		}

		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * Forget a pending upload (attached to a comment, or deleted by its owner).
	 *
	 * @param string $upload_id Mux upload ID.
	 */
	public static function forget_pending_upload( string $upload_id ): void {
		$pending = self::get_pending();
		if ( ! isset( $pending[ $upload_id ] ) ) {
			return;
		}
		unset( $pending[ $upload_id ] );
		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * Read the pending-uploads option.
	 *
	 * @return array<string,int> upload_id => created timestamp.
	 */
	private static function get_pending(): array {
		$pending = get_option( self::PENDING_OPTION, [] );
		return is_array( $pending ) ? $pending : [];
	}

	// -------------------------------------------------------------------------
	// Cron handlers
	// -------------------------------------------------------------------------

	/**
	 * Delete one asset; reschedule with back-off on failure.
	 *
	 * @param string $asset_id Mux asset ID.
	 * @param int    $attempt  Attempt number.
	 */
	public function run_delete_asset( string $asset_id, int $attempt = 1 ): void {
		if ( '' === $asset_id || ! Video_Comments_Settings::has_mux_credentials() ) {
			return;
		}

		$result = $this->get_provider()->delete_asset( $asset_id );

		if ( ! is_wp_error( $result ) ) {
			return;
		}

		// Already gone on Mux's side counts as done.
		$data = $result->get_error_data();
		if ( is_array( $data ) && 404 === ( $data['status'] ?? 0 ) ) {
			return;
		}

		if ( $attempt < self::MAX_ATTEMPTS ) {
			self::schedule_asset_deletion( $asset_id, $attempt + 1 );
		}
	}

	/**
	 * Delete assets behind uploads that are older than a day and were never
	 * attached to a comment.
	 */
	public function sweep_uploads(): void {
		if ( ! Video_Comments_Settings::has_mux_credentials() ) {
			return;
		}

		$pending = self::get_pending();
		if ( [] === $pending ) {
			return;
		}

		$provider = $this->get_provider();
		$now      = time();
		$changed  = false;

		foreach ( $pending as $upload_id => $created ) {
			$age = $now - (int) $created;
			if ( $age < self::ORPHAN_AGE ) {
				continue;
			}

			$status = $provider->get_upload_status( (string) $upload_id );

			if ( is_wp_error( $status ) ) {
				$data      = $status->get_error_data();
				$not_found = is_array( $data ) && 404 === ( $data['status'] ?? 0 );
				// Keep retrying transient API errors on later sweeps, but not forever.
				if ( $not_found || $age > self::PENDING_MAX_AGE ) {
					unset( $pending[ $upload_id ] );
					$changed = true;
				}
				continue;
			}

			$asset_id = (string) ( $status['asset_id'] ?? '' );
			if ( '' !== $asset_id && ! self::asset_in_use( $asset_id ) ) {
				self::schedule_asset_deletion( $asset_id );
			}

			// Waiting/errored/cancelled uploads have no asset and their upload URL
			// expired long ago; nothing more to do either way.
			unset( $pending[ $upload_id ] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( self::PENDING_OPTION, $pending, false );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether any comment (in any status, including trash) references the asset.
	 *
	 * @param string $asset_id Mux asset ID.
	 */
	public static function asset_in_use( string $asset_id ): bool {
		$count = get_comments(
			[
				'meta_key'   => Video_Comments_Comment_Meta::META_ASSET_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- exact-match lookup on a sparse key.
				'meta_value' => $asset_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'status'     => [ 'approve', 'hold', 'spam', 'trash', 'post-trashed' ],
				'count'      => true,
			]
		);

		return (int) $count > 0;
	}

	/** Build a provider using the current credentials. */
	private function get_provider(): Video_Comments_Provider {
		return new Video_Comments_Provider_Mux(
			Video_Comments_Settings::get_mux_token_id(),
			Video_Comments_Settings::get_mux_token_secret()
		);
	}
}
