<?php
/**
 * In-memory stand-in for the Mux Video API, wired through pre_http_request.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

/**
 * Mixed into test cases that talk to Mux. No HTTP request ever leaves the test.
 */
trait Video_Comments_Mux_Mock {

	/** @var array<int,string> "METHOD url" of every request the plugin made. */
	protected array $mux_requests = [];

	/** @var array<string,array<string,mixed>> upload_id => [status, asset_id]. */
	protected array $mux_uploads = [];

	/** @var array<string,array<string,mixed>> asset_id => [status, playback_id]. */
	protected array $mux_assets = [];

	/** @var array<string,int> METHOD => HTTP status to fail with (e.g. DELETE => 500). */
	protected array $mux_fail = [];

	/** Store credentials and intercept HTTP. */
	protected function enable_mux_mock(): void {
		$this->mux_requests = [];
		$this->mux_uploads  = [];
		$this->mux_assets   = [];
		$this->mux_fail     = [];
		$this->set_plugin_options(
			[
				'mux_token_id'     => 'test-id',
				'mux_token_secret' => 'test-secret',
			]
		);
		add_filter( 'pre_http_request', [ $this, 'mock_mux_request' ], 10, 3 );
	}

	/** Merge values into the plugin's option. */
	protected function set_plugin_options( array $values ): void {
		update_option( Video_Comments_Settings::OPTION_KEY, array_merge( Video_Comments_Settings::get_options(), $values ) );
	}

	/**
	 * Make an upload exist on the mock with a ready asset. Returns [upload_id, asset_id, playback_id].
	 * Real Mux IDs are alphanumeric, and the plugin's sanity regex rejects anything else, so defaults are too.
	 */
	protected function mux_seed_ready_upload( string $upload_id = 'uplReady01', string $asset_id = 'assetReady01', string $playback_id = 'pbReady01' ): array {
		$this->mux_uploads[ $upload_id ] = [
			'status'   => 'asset_created',
			'asset_id' => $asset_id,
		];
		$this->mux_assets[ $asset_id ]   = [
			'status'      => 'ready',
			'playback_id' => $playback_id,
		];
		return [ $upload_id, $asset_id, $playback_id ];
	}

	/**
	 * pre_http_request callback.
	 *
	 * @param false|array<string,mixed> $pre  Short-circuit value.
	 * @param array<string,mixed>       $args Request arguments.
	 * @param string                    $url  Request URL.
	 * @return array<string,mixed>|false
	 */
	public function mock_mux_request( $pre, array $args, string $url ) {
		if ( 0 !== strpos( $url, 'https://api.mux.com/' ) ) {
			return $pre;
		}

		$method               = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$this->mux_requests[] = $method . ' ' . $url;
		$path                 = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( isset( $this->mux_fail[ $method ] ) ) {
			return $this->mux_response( $this->mux_fail[ $method ], [ 'error' => [ 'messages' => [ 'mock failure' ] ] ] );
		}

		if ( 'POST' === $method && '/video/v1/uploads' === $path ) {
			$id                       = 'upl_' . wp_generate_password( 8, false );
			$this->mux_uploads[ $id ] = [
				'status'   => 'waiting',
				'asset_id' => null,
			];
			return $this->mux_response(
				201,
				[
					'data' => [
						'id'      => $id,
						'url'     => 'https://storage.example.invalid/' . $id,
						'timeout' => 3600,
					],
				]
			);
		}

		if ( 'GET' === $method && preg_match( '#^/video/v1/uploads/([^/]+)$#', $path, $m ) ) {
			$id = rawurldecode( $m[1] );
			if ( ! isset( $this->mux_uploads[ $id ] ) ) {
				return $this->mux_response( 404, [ 'error' => [ 'messages' => [ 'not found' ] ] ] );
			}
			return $this->mux_response( 200, [ 'data' => [ 'id' => $id ] + $this->mux_uploads[ $id ] ] );
		}

		if ( preg_match( '#^/video/v1/assets/([^/]+)$#', $path, $m ) ) {
			$id = rawurldecode( $m[1] );
			if ( ! isset( $this->mux_assets[ $id ] ) ) {
				return $this->mux_response( 404, [ 'error' => [ 'messages' => [ 'not found' ] ] ] );
			}
			if ( 'DELETE' === $method ) {
				unset( $this->mux_assets[ $id ] );
				return $this->mux_response( 204, null );
			}
			$asset = $this->mux_assets[ $id ];
			return $this->mux_response(
				200,
				[
					'data' => [
						'id'           => $id,
						'status'       => $asset['status'],
						'playback_ids' => $asset['playback_id'] ? [
							[
								'policy' => 'public',
								'id'     => $asset['playback_id'],
							],
						] : [],
					],
				]
			);
		}

		return $this->mux_response( 404, [ 'error' => [ 'messages' => [ 'unmocked ' . $method . ' ' . $path ] ] ] );
	}

	/** Build a WP HTTP API response array. */
	private function mux_response( int $code, ?array $json ): array {
		return [
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'body'     => null === $json ? '' : (string) wp_json_encode( $json ),
			'headers'  => [],
			'cookies'  => [],
		];
	}

	/** Whether any recorded request matches the needle. */
	protected function mux_requested( string $needle ): bool {
		foreach ( $this->mux_requests as $r ) {
			if ( false !== strpos( $r, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
