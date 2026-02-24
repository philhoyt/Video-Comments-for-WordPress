<?php
/**
 * REST API endpoints.
 *
 * @package Video_Comments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles REST routes under the "video-comments/v1" namespace.
 */
class Video_Comments_REST {

	/** @var Video_Comments_REST|null */
	private static ?Video_Comments_REST $instance = null;

	/** REST namespace. */
	public const NAMESPACE = 'video-comments/v1';

	/** Transient prefix for rate limiting. */
	private const RATE_LIMIT_PREFIX = 'vc_rl_';

	/** Max requests per window per IP. */
	private const RATE_LIMIT_MAX = 10;

	/** Rate-limit window in seconds. */
	private const RATE_LIMIT_WINDOW = 60;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

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
					'nonce' => [
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
				'permission_callback' => [ $this, 'check_upload_permission' ],
				'args'                => [
					'upload_id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'nonce' => [
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
		if ( ! $nonce || false === wp_verify_nonce( $nonce, 'vc_upload_nonce' ) ) {
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
	 * Rate limiting is intentionally NOT applied to the polling endpoint.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function check_upload_permission_with_rate_limit( WP_REST_Request $request ) {
		$check = $this->check_upload_permission( $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$rate_check = $this->check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
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

		return rest_ensure_response( $result );
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

	/**
	 * Simple transient-based rate limiter keyed by client IP.
	 *
	 * @return true|WP_Error
	 */
	private function check_rate_limit() {
		$ip  = $this->get_client_ip();
		$key = self::RATE_LIMIT_PREFIX . md5( $ip );

		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
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
