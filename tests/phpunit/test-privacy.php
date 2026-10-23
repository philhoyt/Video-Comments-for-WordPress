<?php
/**
 * Privacy tools: exporter and eraser.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

/**
 * @covers Video_Comments_Privacy
 */
class Test_Video_Comments_Privacy extends WP_UnitTestCase {

	use Video_Comments_Mux_Mock;

	public function set_up(): void {
		parent::set_up();
		$this->enable_mux_mock();
	}

	private function video_comment( string $email, string $playback = 'pb_p', string $asset = 'asset_p', string $status = '1' ): int {
		$cid = self::factory()->comment->create(
			[
				'comment_author_email' => $email,
				'comment_approved'     => $status,
			]
		);
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_PLAYBACK_ID, $playback, true );
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_ASSET_ID, $asset, true );
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_PROVIDER, 'mux', true );
		return $cid;
	}

	public function test_exporter_and_eraser_are_registered(): void {
		$this->assertArrayHasKey( 'video-comments', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		$this->assertArrayHasKey( 'video-comments', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
	}

	public function test_exporter_lists_only_the_requesters_videos(): void {
		$this->video_comment( 'alice@example.com', 'pb_alice' );
		$this->video_comment( 'bob@example.com', 'pb_bob' );
		self::factory()->comment->create( [ 'comment_author_email' => 'alice@example.com' ] ); // No video.

		$out = Video_Comments_Privacy::get_instance()->export( 'alice@example.com', 1 );

		$this->assertTrue( $out['done'] );
		$this->assertCount( 1, $out['data'] );
		$values = wp_list_pluck( $out['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'pb_alice', $values['Mux playback ID'] );
		$this->assertStringContainsString( 'stream.mux.com/pb_alice', $values['Video'] );
	}

	public function test_eraser_removes_meta_in_every_status_and_schedules_deletion(): void {
		$approved = $this->video_comment( 'carol@example.com', 'pb_1', 'asset_1', '1' );
		$spam     = $this->video_comment( 'carol@example.com', 'pb_2', 'asset_2', 'spam' );
		$other    = $this->video_comment( 'dave@example.com', 'pb_3', 'asset_3' );

		$out = Video_Comments_Privacy::get_instance()->erase( 'carol@example.com', 1 );

		$this->assertTrue( $out['items_removed'] );
		$this->assertTrue( $out['done'] );
		$this->assertSame( '', Video_Comments_Comment_Meta::get_playback_id( $approved ) );
		$this->assertSame( '', Video_Comments_Comment_Meta::get_playback_id( $spam ) );
		$this->assertSame( 'pb_3', Video_Comments_Comment_Meta::get_playback_id( $other ), 'other people keep their videos' );
		$this->assertNotFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ 'asset_1', 1 ] ) );
		$this->assertNotFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ 'asset_2', 1 ] ) );
		$this->assertFalse( wp_next_scheduled( Video_Comments_Cleanup::DELETE_HOOK, [ 'asset_3', 1 ] ) );
	}

	public function test_privacy_policy_content_is_registered_on_admin_init(): void {
		// wp_add_privacy_policy_content() only accepts registrations from an admin
		// screen while admin_init is running. Firing admin_init for real sends
		// headers, so fake the running action around a direct call instead.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'options-privacy' );
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		Video_Comments_Privacy::get_instance()->add_privacy_policy_content();
		array_pop( $GLOBALS['wp_current_filter'] );

		$content = WP_Privacy_Policy_Content::get_suggested_policy_text();
		$names   = wp_list_pluck( $content, 'plugin_name' );
		set_current_screen( 'front' );

		$this->assertContains( 'Video Comments using Mux', $names );
	}
}
