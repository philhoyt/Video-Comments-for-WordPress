<?php
/**
 * Provider interface for video hosting backends.
 *
 * @package Video_Comments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contract that every video-hosting provider must satisfy.
 *
 * Implement this interface to add support for additional providers
 * (e.g. Cloudflare Stream) without modifying existing code.
 */
interface Video_Comments_Provider {

	/**
	 * Request a direct-upload URL from the provider.
	 *
	 * @param array<string,mixed> $args Optional arguments (e.g. cors_origin).
	 * @return array<string,mixed>|WP_Error {
	 *     On success:
	 *     @type string $upload_id  Provider-specific upload identifier.
	 *     @type string $upload_url URL the client PUTs/POSTs the video file to.
	 *     @type string $expires_at ISO-8601 expiry (if available).
	 * }
	 */
	public function create_direct_upload( array $args = [] );

	/**
	 * Poll the status of a previously requested upload.
	 *
	 * @param string $upload_id The upload_id returned by create_direct_upload().
	 * @return array<string,mixed>|WP_Error {
	 *     @type string      $status       'waiting' | 'asset_created' | 'ready' | 'errored'
	 *     @type string|null $asset_id     Provider asset ID once the asset exists.
	 *     @type string|null $playback_id  Public playback ID once the asset is ready.
	 * }
	 */
	public function get_upload_status( string $upload_id );

	/**
	 * Permanently delete an asset from the provider.
	 *
	 * @param string $asset_id The provider-specific asset identifier.
	 * @return true|WP_Error True on success.
	 */
	public function delete_asset( string $asset_id );
}
