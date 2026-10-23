<?php
/**
 * Attaching a video to a comment, the admin edit screen, and deletion scheduling.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

/**
 * @covers Video_Comments_Comment_Meta
 */
class Test_Video_Comments_Comment_Meta extends WP_UnitTestCase {

	use Video_Comments_Mux_Mock;

	private Video_Comments_Comment_Meta $meta;

	public function set_up(): void {
		parent::set_up();
		$this->enable_mux_mock();
		$this->meta = Video_Comments_Comment_Meta::get_instance();
	}

	public function tear_down(): void {
		// The per-request Mux status memo is static and would otherwise leak between tests.
		Video_Comments_Comment_Meta::reset_request_state();
		parent::tear_down();
	}

	private function issue_token( string $upload_id ): string {
		$m = new ReflectionMethod( Video_Comments_REST::class, 'issue_upload_token' );
		$m->setAccessible( true );
		return $m->invoke( null, $upload_id );
	}

	private function save( int $comment_id, array $post ): void {
		$_POST = array_merge( [ Video_Comments_Comment_Meta::NONCE_FIELD => wp_create_nonce( 'video_comments_comment_submit' ) ], $post );
		$data  = $this->meta->capture_upload_fields_before_sanitize( [] );
		$this->meta->save_comment_video_meta( $comment_id, 1, $data );
	}

	public function test_valid_token_attaches_mux_resolved_ids(): void {
		[ $upload_id, $asset_id, $playback_id ] = $this->mux_seed_ready_upload();
		$token                                  = $this->issue_token( $upload_id );
		Video_Comments_Cleanup::record_pending_upload( $upload_id );
		$cid = self::factory()->comment->create();

		$this->save(
			$cid,
			[
				Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD => $upload_id,
				Video_Comments_Comment_Meta::FORM_FIELD_TOKEN  => $token,
			]
		);

		$this->assertSame( $playback_id, Video_Comments_Comment_Meta::get_playback_id( $cid ) );
		$this->assertSame( $asset_id, get_comment_meta( $cid, Video_Comments_Comment_Meta::META_ASSET_ID, true ) );
		$this->assertSame( 'mux', get_comment_meta( $cid, Video_Comments_Comment_Meta::META_PROVIDER, true ) );
		$this->assertFalse( Video_Comments_REST::verify_upload_token( $upload_id, $token ), 'token retired after attach' );
		$this->assertArrayNotHasKey( $upload_id, (array) get_option( Video_Comments_Cleanup::PENDING_OPTION, [] ) );
	}

	public function test_forged_playback_and_asset_fields_are_ignored(): void {
		$cid = self::factory()->comment->create();
		$this->save(
			$cid,
			[
				'video_comments_mux_playback_id'    => 'FORGED',
				'video_comments_mux_asset_id_field' => 'victim_asset',
			]
		);
		$this->assertSame( '', Video_Comments_Comment_Meta::get_playback_id( $cid ) );
		$this->assertSame( '', get_comment_meta( $cid, Video_Comments_Comment_Meta::META_ASSET_ID, true ) );
	}

	public function test_wrong_token_saves_nothing(): void {
		[ $upload_id ] = $this->mux_seed_ready_upload();
		$this->issue_token( $upload_id );
		$cid = self::factory()->comment->create();
		$this->save(
			$cid,
			[
				Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD => $upload_id,
				Video_Comments_Comment_Meta::FORM_FIELD_TOKEN => 'wrong',
			]
		);
		$this->assertSame( '', Video_Comments_Comment_Meta::get_playback_id( $cid ) );
		$this->assertFalse( $this->mux_requested( 'GET' ), 'Mux is not consulted for an unproven upload' );
	}

	public function test_missing_nonce_saves_nothing(): void {
		[ $upload_id ] = $this->mux_seed_ready_upload();
		$token         = $this->issue_token( $upload_id );
		$cid           = self::factory()->comment->create();
		$_POST         = [
			Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD => $upload_id,
			Video_Comments_Comment_Meta::FORM_FIELD_TOKEN  => $token,
		];
		$this->meta->save_comment_video_meta( $cid, 1, $this->meta->capture_upload_fields_before_sanitize( [] ) );
		$this->assertSame( '', Video_Comments_Comment_Meta::get_playback_id( $cid ) );
	}

	public function test_unfinished_upload_is_not_attached(): void {
		$upload_id                       = 'upl_waiting';
		$this->mux_uploads[ $upload_id ] = [
			'status'   => 'waiting',
			'asset_id' => null,
		];
		$token                           = $this->issue_token( $upload_id );
		$cid                             = self::factory()->comment->create();
		$this->save(
			$cid,
			[
				Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD => $upload_id,
				Video_Comments_Comment_Meta::FORM_FIELD_TOKEN => $token,
			]
		);
		$this->assertSame( '', Video_Comments_Comment_Meta::get_playback_id( $cid ) );
		$this->assertTrue( Video_Comments_REST::verify_upload_token( $upload_id, $token ), 'token kept so the client can retry' );
	}

	public function test_token_cannot_be_reused_for_a_second_comment(): void {
		[ $upload_id ] = $this->mux_seed_ready_upload();
		$token         = $this->issue_token( $upload_id );
		$first         = self::factory()->comment->create();
		$this->save(
			$first,
			[
				Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD => $upload_id,
				Video_Comments_Comment_Meta::FORM_FIELD_TOKEN => $token,
			]
		);
		$second = self::factory()->comment->create();
		$this->save(
			$second,
			[
				Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD => $upload_id,
				Video_Comments_Comment_Meta::FORM_FIELD_TOKEN => $token,
			]
		);
		$this->assertNotSame( '', Video_Comments_Comment_Meta::get_playback_id( $first ) );
		$this->assertSame( '', Video_Comments_Comment_Meta::get_playback_id( $second ) );
	}

	public function test_deleting_comment_schedules_background_asset_deletion(): void {
		$cid = self::factory()->comment->create();
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_ASSET_ID, 'asset_del', true );

		wp_delete_comment( $cid, true );

		$this->assertNotFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ 'asset_del', 1 ] ) );
		$this->assertFalse( $this->mux_requested( 'DELETE' ), 'no synchronous HTTP during the admin request' );
	}

	public function test_admin_save_requires_nonce_and_capability(): void {
		$cid = self::factory()->comment->create();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST = [
			'video_comments_edit_nonce'  => wp_create_nonce( 'video_comments_edit_comment_' . $cid ),
			'video_comments_playback_id' => 'abc123',
		];
		$this->meta->save_admin_comment_meta( $cid );
		$this->assertSame( '', Video_Comments_Comment_Meta::get_playback_id( $cid ), 'subscriber cannot edit' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST['video_comments_edit_nonce'] = wp_create_nonce( 'video_comments_edit_comment_' . $cid );
		$this->meta->save_admin_comment_meta( $cid );
		$this->assertSame( 'abc123', Video_Comments_Comment_Meta::get_playback_id( $cid ) );

		$_POST['video_comments_playback_id'] = 'bad id!';
		$this->meta->save_admin_comment_meta( $cid );
		$this->assertSame( 'abc123', Video_Comments_Comment_Meta::get_playback_id( $cid ), 'malformed id ignored' );
	}

	public function test_admin_clearing_video_schedules_deletion_and_removes_meta(): void {
		$cid = self::factory()->comment->create();
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_PLAYBACK_ID, 'pb', true );
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_ASSET_ID, 'asset_clear', true );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST = [
			'video_comments_edit_nonce'  => wp_create_nonce( 'video_comments_edit_comment_' . $cid ),
			'video_comments_playback_id' => '',
		];

		$this->meta->save_admin_comment_meta( $cid );

		$this->assertSame( '', Video_Comments_Comment_Meta::get_playback_id( $cid ) );
		$this->assertNotFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ 'asset_clear', 1 ] ) );
	}
}
