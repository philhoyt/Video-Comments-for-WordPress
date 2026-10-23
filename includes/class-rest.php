<?php
/**
 * REST API endpoints.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles REST routes under the "video-comments/v1" namespace.
 */
class Video_Comments_REST {

	/**
	 * Singleton instance.
	 *
	 * @var Video_Comments_REST|null
	 */
	private static ?Video_Comments_REST $instance = null;

	/** REST namespace. */
	public const NAMESPACE = 'video-comments/v1';

	/** Transient prefix for rate limiting. */
	private const RATE_LIMIT_PREFIX = 'video_comments_rl_';

	/** Max requests per window per IP. */
	private const RATE_LIMIT_MAX = 10;

	/** Rate-limit window in seconds. */
	private const RATE_LIMIT_WINDOW = 60;

	/** Transient prefix for the status-polling rate limiter. */
	private const POLL_RATE_LIMIT_PREFIX = 'video_comments_rlp_';

	/** Max status/delete requests per window per IP (the client polls every 3 s). */
	private const POLL_RATE_LIMIT_MAX = 60;

	/** Transient prefix for per-upload ownership records. */
	private const UPLOAD_RECORD_PREFIX = 'video_comments_upload_';

	/** Lifetime of an ownership record: covers uploading, processing and writing the comment. */
	private const UPLOAD_RECORD_TTL = 43200; // 12 hours.

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
	 * Register WordPress hooks.
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register all REST routes for this plugin.
	 */
	public function register_routes(): void {
		// POST /video-comments/v1/mux/direct-upload.
		register_rest_route(
			self::NAMESPACE,
			'/mux/direct-upload',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_direct_upload' ],
				'permission_callback' => [ $this, 'check_upload_permission_with_rate_limit' ],
				'args'                => [
					'file_name' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_file_name',
					],
					'file_size' => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'nonce'     => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// DELETE /video-comments/v1/mux/video — delete the asset behind an upload from Mux.
		register_rest_route(
			self::NAMESPACE,
			'/mux/video',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'handle_delete_video' ],
				'permission_callback' => [ $this, 'check_owner_permission' ],
				'args'                => [
					'upload_id'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'upload_token' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'nonce'        => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// GET /video-comments/v1/mux/upload-status.
		register_rest_route(
			self::NAMESPACE,
			'/mux/upload-status',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_upload_status' ],
				'permission_callback' => [ $this, 'check_owner_permission' ],
				'args'                => [
					'upload_id'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'upload_token' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'nonce'        => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Shared permission check: enabled, guest guard, nonce.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_upload_permission( WP_REST_Request $request ) {
		if ( ! Video_Comments_Settings::is_enabled() ) {
			return new WP_Error(
				'vc_disabled',
				__( 'Video Comments is currently disabled.', 'video-comments' ),
				[ 'status' => 403 ]
			);
		}

		// Guests check.
		if ( ! is_user_logged_in() && ! Video_Comments_Settings::allow_guests() ) {
			return new WP_Error(
				'vc_guests_disabled',
				__( 'Video uploads are not available for guests.', 'video-comments' ),
				[ 'status' => 403 ]
			);
		}

		// Nonce verification.
		$nonce = $request->get_param( 'nonce' );
		if ( ! $nonce || false === wp_verify_nonce( $nonce, 'video_comments_upload' ) ) {
			return new WP_Error(
				'vc_bad_nonce',
				__( 'Security check failed. Please refresh the page.', 'video-comments' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Permission check for direct-upload: same as above plus rate limiting.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_upload_permission_with_rate_limit( WP_REST_Request $request ) {
		$check = $this->check_upload_permission( $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$rate_check = $this->check_rate_limit( self::RATE_LIMIT_PREFIX, self::RATE_LIMIT_MAX );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		return true;
	}

	/**
	 * Permission check for routes that act on an existing upload (status, delete):
	 * the shared checks, a looser polling rate limit, and the per-upload token
	 * issued by handle_direct_upload(). Only the caller who created the upload
	 * holds that token, so guests sharing one nonce cannot touch each other's uploads.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_owner_permission( WP_REST_Request $request ) {
		$check = $this->check_upload_permission( $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$rate_check = $this->check_rate_limit( self::POLL_RATE_LIMIT_PREFIX, self::POLL_RATE_LIMIT_MAX );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$upload_id = (string) $request->get_param( 'upload_id' );
		$token     = (string) $request->get_param( 'upload_token' );

		if ( ! self::verify_upload_token( $upload_id, $token ) ) {
			return new WP_Error(
				'vc_not_owner',
				__( 'You are not authorised to access this upload.', 'video-comments' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * POST /mux/direct-upload — request a Mux direct upload URL.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_direct_upload( WP_REST_Request $request ) {
		if ( ! Video_Comments_Settings::has_mux_credentials() ) {
			return new WP_Error(
				'vc_no_credentials',
				__( 'Mux API credentials are not configured. Please contact the site administrator.', 'video-comments' ),
				[ 'status' => 503 ]
			);
		}

		// Validate reported file size server-side.
		$file_size = (int) $request->get_param( 'file_size' );
		if ( $file_size > 0 && $file_size > Video_Comments_Settings::max_size_bytes() ) {
			return new WP_Error(
				'vc_file_too_large',
				sprintf(
					/* translators: %s: max size in MB */
					__( 'File exceeds the maximum allowed size of %s MB.', 'video-comments' ),
					Video_Comments_Settings::max_size_mb()
				),
				[ 'status' => 413 ]
			);
		}

		// Validate reported MIME type / extension (best-effort).
		$file_name = (string) $request->get_param( 'file_name' );
		if ( $file_name ) {
			$allowed_extensions = [ 'mp4', 'mov', 'webm', 'mkv', 'avi', 'm4v', 'ogv', 'ts', 'mts' ];
			$ext                = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
			if ( $ext && ! in_array( $ext, $allowed_extensions, true ) ) {
				return new WP_Error(
					'vc_invalid_file_type',
					__( 'That file type is not allowed. Please upload a video file.', 'video-comments' ),
					[ 'status' => 415 ]
				);
			}
		}

		$provider = $this->get_provider();
		$result   = $provider->create_direct_upload( [ 'cors_origin' => get_site_url() ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Issue a per-upload secret. It is required to poll or delete this upload and
		// to attach it to a comment, and it is only ever returned to the caller who created it.
		$result['upload_token'] = self::issue_upload_token( (string) $result['upload_id'] );

		return rest_ensure_response( $result );
	}

	/**
	 * GET /mux/upload-status — poll a Mux upload for its current status.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_upload_status( WP_REST_Request $request ) {
		if ( ! Video_Comments_Settings::has_mux_credentials() ) {
			return new WP_Error(
				'vc_no_credentials',
				__( 'Mux API credentials are not configured.', 'video-comments' ),
				[ 'status' => 503 ]
			);
		}

		$upload_id = (string) $request->get_param( 'upload_id' );

		if ( empty( $upload_id ) ) {
			return new WP_Error(
				'vc_missing_param',
				__( 'upload_id is required.', 'video-comments' ),
				[ 'status' => 400 ]
			);
		}

		$provider = $this->get_provider();
		$result   = $provider->get_upload_status( $upload_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Only surface playback_id when the asset is truly ready.
		if ( 'ready' !== $result['status'] ) {
			unset( $result['playback_id'] );
		}

		// Always include asset_id so the client can use it for deletion.
		return rest_ensure_response( $result );
	}

	/**
	 * DELETE /mux/video — delete the Mux asset behind an upload.
	 *
	 * Ownership is verified in check_owner_permission(). The asset ID is always
	 * resolved from Mux via the upload, never taken from the client, so a caller
	 * can only ever delete the asset created by their own upload.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_delete_video( WP_REST_Request $request ) {
		if ( ! Video_Comments_Settings::has_mux_credentials() ) {
			return new WP_Error(
				'vc_no_credentials',
				__( 'Mux API credentials are not configured.', 'video-comments' ),
				[ 'status' => 503 ]
			);
		}

		$upload_id = (string) $request->get_param( 'upload_id' );
		$provider  = $this->get_provider();

		$status = $provider->get_upload_status( $upload_id );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$asset_id = (string) ( $status['asset_id'] ?? '' );

		if ( '' === $asset_id ) {
			// Upload exists but no asset was created yet — just forget the ownership record.
			self::forget_upload( $upload_id );
			return rest_ensure_response( [ 'deleted' => false ] );
		}

		$result = $provider->delete_asset( $asset_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::forget_upload( $upload_id );
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a provider instance using current credentials.
	 */
	private function get_provider(): Video_Comments_Provider {
		return new Video_Comments_Provider_Mux(
			Video_Comments_Settings::get_mux_token_id(),
			Video_Comments_Settings::get_mux_token_secret()
		);
	}

	// -------------------------------------------------------------------------
	// Upload ownership
	// -------------------------------------------------------------------------

	/**
	 * Verify the per-upload token issued by handle_direct_upload().
	 *
	 * Used by the REST permission callback and by the comment-save path, which
	 * only attaches an upload to a comment when the submitter holds its token.
	 *
	 * @param string $upload_id Mux upload ID.
	 * @param string $token     Token presented by the client.
	 */
	public static function verify_upload_token( string $upload_id, string $token ): bool {
		if ( '' === $upload_id || '' === $token ) {
			return false;
		}

		$stored = get_transient( self::upload_record_key( $upload_id ) );

		return is_string( $stored ) && hash_equals( $stored, wp_hash( $token ) );
	}

	/**
	 * Remove the ownership record for an upload (after deletion, or once it is
	 * attached to a comment so the token cannot be reused to delete it).
	 *
	 * @param string $upload_id Mux upload ID.
	 */
	public static function forget_upload( string $upload_id ): void {
		delete_transient( self::upload_record_key( $upload_id ) );
	}

	/**
	 * Generate a random secret for a new upload and store its hash.
	 *
	 * @param string $upload_id Mux upload ID.
	 * @return string The plain token to hand back to the client (stored only as a hash).
	 */
	private static function issue_upload_token( string $upload_id ): string {
		$token = wp_generate_password( 32, false );
		set_transient( self::upload_record_key( $upload_id ), wp_hash( $token ), self::UPLOAD_RECORD_TTL );
		return $token;
	}

	/**
	 * Transient key for an upload's ownership record.
	 *
	 * @param string $upload_id Mux upload ID.
	 */
	private static function upload_record_key( string $upload_id ): string {
		return self::UPLOAD_RECORD_PREFIX . md5( $upload_id );
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	/**
	 * Simple transient-based rate limiter keyed by client IP.
	 *
	 * @param string $prefix Transient prefix identifying the limiter bucket.
	 * @param int    $max    Max requests per window.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( string $prefix, int $max ) {
		$ip  = $this->get_client_ip();
		$key = $prefix . md5( $ip );

		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return new WP_Error(
				'vc_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'video-comments' ),
				[ 'status' => 429 ]
			);
		}

		if ( 0 === $count ) {
			set_transient( $key, 1, self::RATE_LIMIT_WINDOW );
		} else {
			set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		}

		return true;
	}

	/**
	 * Retrieve the client's real IP address.
	 * Trusts standard headers only; does not blindly trust X-Forwarded-For.
	 */
	private function get_client_ip(): string {
		// Use REMOTE_ADDR as the authoritative source.
		// Reverse-proxy setups may need to adjust this.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}
}
