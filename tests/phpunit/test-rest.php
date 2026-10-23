<?php
/**
 * REST routes: permissions, ownership tokens, validation and rate limiting.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

/**
 * @covers Video_Comments_REST
 */
class Test_Video_Comments_REST extends WP_UnitTestCase {

	use Video_Comments_Mux_Mock;

	/** @var WP_REST_Server */
	private $server;

	public function set_up(): void {
		parent::set_up();
		$this->enable_mux_mock();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 254 );

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function nonce(): string {
		return wp_create_nonce( 'video_comments_upload' );
	}

	private function direct_upload( array $params = [] ): WP_REST_Response {
		$req = new WP_REST_Request( 'POST', '/' . Video_Comments_REST::NAMESPACE . '/mux/direct-upload' );
		$req->set_body_params(
			array_merge(
				[
					'nonce'     => $this->nonce(),
					'file_name' => 'clip.mp4',
					'file_size' => 1024,
				],
				$params
			)
		);
		return $this->server->dispatch( $req );
	}

	private function status( array $params ): WP_REST_Response {
		$req = new WP_REST_Request( 'GET', '/' . Video_Comments_REST::NAMESPACE . '/mux/upload-status' );
		$req->set_query_params( array_merge( [ 'nonce' => $this->nonce() ], $params ) );
		return $this->server->dispatch( $req );
	}

	private function delete( array $params ): WP_REST_Response {
		$req = new WP_REST_Request( 'DELETE', '/' . Video_Comments_REST::NAMESPACE . '/mux/video' );
		$req->set_query_params( array_merge( [ 'nonce' => $this->nonce() ], $params ) );
		return $this->server->dispatch( $req );
	}

	// -- shared permission checks -------------------------------------------------

	public function test_routes_are_registered(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/video-comments/v1/mux/direct-upload', $routes );
		$this->assertArrayHasKey( '/video-comments/v1/mux/upload-status', $routes );
		$this->assertArrayHasKey( '/video-comments/v1/mux/video', $routes );
	}

	public function test_disabled_feature_is_forbidden(): void {
		$this->set_plugin_options( [ 'enabled' => 0 ] );
		$res = $this->direct_upload();
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'vc_disabled', $res->get_data()['code'] );
	}

	public function test_guest_blocked_when_guests_disabled(): void {
		$this->set_plugin_options( [ 'allow_guests' => 0 ] );
		wp_set_current_user( 0 );
		$res = $this->direct_upload();
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'vc_guests_disabled', $res->get_data()['code'] );
	}

	public function test_logged_in_user_allowed_when_guests_disabled(): void {
		$this->set_plugin_options( [ 'allow_guests' => 0 ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$res = $this->direct_upload();
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_bad_nonce_is_forbidden(): void {
		$res = $this->direct_upload( [ 'nonce' => 'nope' ] );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'vc_bad_nonce', $res->get_data()['code'] );
	}

	// -- direct-upload --------------------------------------------------------------

	public function test_direct_upload_requires_credentials(): void {
		$this->set_plugin_options(
			[
				'mux_token_id'     => '',
				'mux_token_secret' => '',
			]
		);
		$res = $this->direct_upload();
		$this->assertSame( 503, $res->get_status() );
	}

	public function test_direct_upload_rejects_oversized_file(): void {
		$this->set_plugin_options( [ 'max_size_mb' => 1 ] );
		$res = $this->direct_upload( [ 'file_size' => 2 * 1024 * 1024 ] );
		$this->assertSame( 413, $res->get_status() );
		$this->assertFalse( $this->mux_requested( 'POST' ) );
	}

	public function test_direct_upload_rejects_non_video_extension(): void {
		$res = $this->direct_upload( [ 'file_name' => 'evil.php' ] );
		$this->assertSame( 415, $res->get_status() );
		$this->assertFalse( $this->mux_requested( 'POST' ) );
	}

	public function test_direct_upload_returns_token_and_records_pending(): void {
		$res = $this->direct_upload();
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertNotEmpty( $data['upload_id'] );
		$this->assertStringStartsWith( 'https://', $data['upload_url'] );
		$this->assertSame( 32, strlen( $data['upload_token'] ) );
		$this->assertTrue( Video_Comments_REST::verify_upload_token( $data['upload_id'], $data['upload_token'] ) );
		$this->assertArrayHasKey( $data['upload_id'], get_option( Video_Comments_Cleanup::PENDING_OPTION ) );
	}

	public function test_direct_upload_is_rate_limited_per_ip(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 200, $this->direct_upload()->get_status(), "request $i" );
		}
		$res = $this->direct_upload();
		$this->assertSame( 429, $res->get_status() );

		// Another address is unaffected.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 200, $this->direct_upload()->get_status() );
	}

	public function test_client_ip_filter_keys_the_limiter(): void {
		add_filter( 'video_comments_client_ip', fn() => '10.0.0.1' );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->direct_upload();
		}
		$_SERVER['REMOTE_ADDR'] = '198.51.100.8'; // Ignored: the filter wins.
		$this->assertSame( 429, $this->direct_upload()->get_status() );
	}

	// -- ownership token --------------------------------------------------------------

	public function test_token_helpers(): void {
		$data = $this->direct_upload()->get_data();
		$this->assertFalse( Video_Comments_REST::verify_upload_token( $data['upload_id'], 'wrong' ) );
		$this->assertFalse( Video_Comments_REST::verify_upload_token( 'other', $data['upload_token'] ) );
		$this->assertFalse( Video_Comments_REST::verify_upload_token( '', '' ) );
		Video_Comments_REST::forget_upload( $data['upload_id'] );
		$this->assertFalse( Video_Comments_REST::verify_upload_token( $data['upload_id'], $data['upload_token'] ) );
	}

	public function test_status_requires_token(): void {
		$data = $this->direct_upload()->get_data();
		$this->assertSame( 400, $this->status( [ 'upload_id' => $data['upload_id'] ] )->get_status() );
		$res = $this->status(
			[
				'upload_id'    => $data['upload_id'],
				'upload_token' => 'bogus',
			]
		);
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'vc_not_owner', $res->get_data()['code'] );
	}

	public function test_status_hides_playback_id_until_ready(): void {
		$data = $this->direct_upload()->get_data();
		$id   = $data['upload_id'];

		$this->mux_uploads[ $id ]    = [
			'status'   => 'asset_created',
			'asset_id' => 'asset_x',
		];
		$this->mux_assets['asset_x'] = [
			'status'      => 'preparing',
			'playback_id' => 'pb_x',
		];
		$res                         = $this->status(
			[
				'upload_id'    => $id,
				'upload_token' => $data['upload_token'],
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'asset_created', $res->get_data()['status'] );
		$this->assertArrayNotHasKey( 'playback_id', $res->get_data() );

		$this->mux_assets['asset_x']['status'] = 'ready';
		$res                                   = $this->status(
			[
				'upload_id'    => $id,
				'upload_token' => $data['upload_token'],
			]
		);
		$this->assertSame( 'ready', $res->get_data()['status'] );
		$this->assertSame( 'pb_x', $res->get_data()['playback_id'] );
		$this->assertSame( 'asset_x', $res->get_data()['asset_id'] );
	}

	// -- delete ---------------------------------------------------------------------------

	public function test_delete_ignores_client_asset_id_and_resolves_from_upload(): void {
		$data                            = $this->direct_upload()->get_data();
		$id                              = $data['upload_id'];
		$this->mux_uploads[ $id ]        = [
			'status'   => 'asset_created',
			'asset_id' => 'asset_mine',
		];
		$this->mux_assets['asset_mine']  = [
			'status'      => 'ready',
			'playback_id' => 'pb_mine',
		];
		$this->mux_assets['asset_other'] = [
			'status'      => 'ready',
			'playback_id' => 'pb_other',
		];

		$res = $this->delete(
			[
				'upload_id'    => $id,
				'upload_token' => $data['upload_token'],
				'asset_id'     => 'asset_other',
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['deleted'] );
		$this->assertTrue( $this->mux_requested( 'DELETE https://api.mux.com/video/v1/assets/asset_mine' ) );
		$this->assertArrayHasKey( 'asset_other', $this->mux_assets, 'the client-named asset must survive' );
		$this->assertFalse( Video_Comments_REST::verify_upload_token( $id, $data['upload_token'] ), 'ownership record retired' );
		$this->assertArrayNotHasKey( $id, (array) get_option( Video_Comments_Cleanup::PENDING_OPTION, [] ) );
	}

	public function test_delete_with_wrong_token_is_forbidden(): void {
		$data = $this->direct_upload()->get_data();
		$res  = $this->delete(
			[
				'upload_id'    => $data['upload_id'],
				'upload_token' => 'bogus',
			]
		);
		$this->assertSame( 403, $res->get_status() );
		$this->assertFalse( $this->mux_requested( 'DELETE' ) );
	}

	public function test_delete_before_asset_exists_just_forgets_upload(): void {
		$data = $this->direct_upload()->get_data();
		$res  = $this->delete(
			[
				'upload_id'    => $data['upload_id'],
				'upload_token' => $data['upload_token'],
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['deleted'] );
		$this->assertFalse( Video_Comments_REST::verify_upload_token( $data['upload_id'], $data['upload_token'] ) );
	}

	public function test_delete_keeps_ownership_record_when_mux_fails(): void {
		$data                        = $this->direct_upload()->get_data();
		$id                          = $data['upload_id'];
		$this->mux_uploads[ $id ]    = [
			'status'   => 'asset_created',
			'asset_id' => 'asset_f',
		];
		$this->mux_assets['asset_f'] = [
			'status'      => 'ready',
			'playback_id' => 'pb_f',
		];
		$this->mux_fail['DELETE']    = 500;

		$res = $this->delete(
			[
				'upload_id'    => $id,
				'upload_token' => $data['upload_token'],
			]
		);
		$this->assertSame( 500, $res->get_status() );
		$this->assertTrue( Video_Comments_REST::verify_upload_token( $id, $data['upload_token'] ) );
	}
}
