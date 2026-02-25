<?php
/**
 * Mux provider implementation.
 *
 * @package Video_Comments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Mux Video API using Basic Auth (token_id:token_secret).
 *
 * API reference: https://docs.mux.com/api-reference
 */
class Video_Comments_Provider_Mux implements Video_Comments_Provider {

	/** Mux API base URL. */
	private const API_BASE = 'https://api.mux.com/video/v1';

	/** @var string */
	private string $token_id;

	/** @var string */
	private string $token_secret;

	/**
	 * Constructor.
	 *
	 * @param string $token_id     Mux Token ID.
	 * @param string $token_secret Mux Token Secret.
	 */
	public function __construct( string $token_id, string $token_secret ) {
		$this->token_id     = $token_id;
		$this->token_secret = $token_secret;
	}

	// -------------------------------------------------------------------------
	// Provider interface
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 *
	 * Creates a Mux direct upload:
	 * POST https://api.mux.com/video/v1/uploads
	 */
	public function create_direct_upload( array $args = [] ) {
		$cors_origin = $args['cors_origin'] ?? get_site_url();

		$body = wp_json_encode(
			[
				'cors_origin'    => $cors_origin,
				'new_asset_settings' => [
					'playback_policy' => [ 'public' ],
				],
				'timeout'        => 3600,
			]
		);

		$response = $this->request(
			'POST',
			self::API_BASE . '/uploads',
			$body
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response['data'] ?? null;

		if ( empty( $data['id'] ) || empty( $data['url'] ) ) {
			return new WP_Error(
				'vc_mux_bad_response',
				__( 'Unexpected response from Mux when creating upload.', 'video-comments' )
			);
		}

		return [
			'upload_id'  => sanitize_text_field( $data['id'] ),
			'upload_url' => esc_url_raw( $data['url'] ),
			'expires_at' => sanitize_text_field( $data['timeout'] ?? '' ),
		];
	}

	/**
	 * {@inheritdoc}
	 *
	 * Fetches upload status:
	 * GET https://api.mux.com/video/v1/uploads/{UPLOAD_ID}
	 */
	public function get_upload_status( string $upload_id ) {
		$upload_id = sanitize_text_field( $upload_id );

		if ( empty( $upload_id ) ) {
			return new WP_Error( 'vc_invalid_upload_id', __( 'Invalid upload ID.', 'video-comments' ) );
		}

		$response = $this->request(
			'GET',
			self::API_BASE . '/uploads/' . rawurlencode( $upload_id )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response['data'] ?? null;

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'vc_mux_bad_response',
				__( 'Unexpected response from Mux when fetching upload status.', 'video-comments' )
			);
		}

		$mux_status = $data['status'] ?? 'waiting'; // waiting | asset_created | errored | cancelled
		$asset_id   = $data['asset_id'] ?? null;

		// Map Mux status → our internal status.
		$status      = $this->map_upload_status( $mux_status );
		$playback_id = null;

		// Fetch asset details to get playback_id when asset exists.
		if ( $asset_id && in_array( $status, [ 'asset_created', 'ready' ], true ) ) {
			$asset_result = $this->get_asset( (string) $asset_id );
			if ( ! is_wp_error( $asset_result ) ) {
				$playback_id = $asset_result['playback_id'] ?? null;
				// Refine status based on asset readiness.
				if ( 'ready' === ( $asset_result['status'] ?? '' ) && $playback_id ) {
					$status = 'ready';
				}
			}
		}

		$result = [
			'status'     => $status,
			'asset_id'   => $asset_id ? sanitize_text_field( (string) $asset_id ) : null,
			'playback_id' => $playback_id ? sanitize_text_field( (string) $playback_id ) : null,
		];

		return $result;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Deletes a Mux asset:
	 * DELETE https://api.mux.com/video/v1/assets/{ASSET_ID}
	 */
	public function delete_asset( string $asset_id ) {
		$asset_id = sanitize_text_field( $asset_id );

		if ( empty( $asset_id ) ) {
			return new WP_Error( 'vc_invalid_asset_id', __( 'Invalid asset ID.', 'video-comments' ) );
		}

		$result = $this->request(
			'DELETE',
			self::API_BASE . '/assets/' . rawurlencode( $asset_id )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch asset details.
	 *
	 * @param string $asset_id Mux asset ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_asset( string $asset_id ) {
		$response = $this->request(
			'GET',
			self::API_BASE . '/assets/' . rawurlencode( $asset_id )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response['data'] ?? [];

		// Extract first public playback ID.
		$playback_id = null;
		if ( ! empty( $data['playback_ids'] ) ) {
			foreach ( $data['playback_ids'] as $pb ) {
				if ( 'public' === ( $pb['policy'] ?? '' ) ) {
					$playback_id = $pb['id'] ?? null;
					break;
				}
			}
		}

		return [
			'status'      => sanitize_text_field( $data['status'] ?? '' ),
			'playback_id' => $playback_id ? sanitize_text_field( (string) $playback_id ) : null,
		];
	}

	/**
	 * Map a Mux upload status string to our internal vocabulary.
	 *
	 * @param string $mux_status Raw Mux status.
	 * @return string
	 */
	private function map_upload_status( string $mux_status ): string {
		switch ( $mux_status ) {
			case 'asset_created':
				return 'asset_created';
			case 'errored':
			case 'cancelled':
				return 'errored';
			default:
				return 'waiting';
		}
	}

	/**
	 * Make an authenticated HTTP request to the Mux API.
	 *
	 * @param string      $method HTTP method (GET|POST).
	 * @param string      $url    Full URL.
	 * @param string|null $body   JSON body (for POST).
	 * @return array<string,mixed>|WP_Error Decoded JSON data array on success.
	 */
	private function request( string $method, string $url, ?string $body = null ) {
		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $this->token_id . ':' . $this->token_secret ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'timeout' => 15,
		];

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		if ( 'GET' === $method ) {
			$response = wp_remote_get( $url, $args );
		} elseif ( 'POST' === $method ) {
			$response = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_request( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		// Mux DELETE endpoints return 204 No Content on success.
		if ( 204 === $code ) {
			return [];
		}

		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $json['error']['messages'][0] )
				? $json['error']['messages'][0]
				: sprintf(
					/* translators: 1: HTTP status code */
					__( 'Mux API returned HTTP %1$s.', 'video-comments' ),
					$code
				);
			return new WP_Error( 'vc_mux_api_error', $msg, [ 'status' => $code ] );
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'vc_mux_json_error', __( 'Could not parse Mux API response.', 'video-comments' ) );
		}

		return $json;
	}
}
