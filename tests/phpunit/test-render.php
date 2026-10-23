<?php
/**
 * Front-end rendering and asset loading.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

/**
 * @covers Video_Comments_Render
 */
class Test_Video_Comments_Render extends WP_UnitTestCase {

	use Video_Comments_Mux_Mock;

	public function set_up(): void {
		parent::set_up();
		$this->enable_mux_mock();
		wp_dequeue_script( 'mux-player' );
		wp_dequeue_script( 'video-comments' );
		wp_dequeue_style( 'video-comments' );
	}

	public function test_video_comment_renders_player_and_enqueues_assets_even_when_comments_closed(): void {
		$post_id = self::factory()->post->create( [ 'comment_status' => 'closed' ] );
		$cid     = self::factory()->comment->create( [ 'comment_post_ID' => $post_id ] );
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_PLAYBACK_ID, 'pbRENDER1', true );
		$this->go_to( get_permalink( $post_id ) );

		Video_Comments_Render::get_instance()->enqueue_assets();
		$this->assertFalse( wp_script_is( 'video-comments', 'enqueued' ), 'uploader script stays off when comments are closed' );

		$html = apply_filters( 'comment_text', 'hello', get_comment( $cid ) );

		$this->assertStringContainsString( '<mux-player', $html );
		$this->assertStringContainsString( 'playback-id="pbRENDER1"', $html );
		$this->assertStringContainsString( 'hello', $html );
		$this->assertTrue( wp_script_is( 'mux-player', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'video-comments', 'enqueued' ) );
	}

	public function test_player_src_is_pinned_and_filterable(): void {
		$cid = self::factory()->comment->create();
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_PLAYBACK_ID, 'pbPIN', true );
		apply_filters( 'comment_text', 'x', get_comment( $cid ) );
		$this->assertStringContainsString( '@mux/mux-player@3', wp_scripts()->registered['mux-player']->src );

		wp_dequeue_script( 'mux-player' );
		wp_deregister_script( 'mux-player' );
		add_filter( 'video_comments_mux_player_src', '__return_false' );
		apply_filters( 'comment_text', 'x', get_comment( $cid ) );
		$this->assertFalse( wp_script_is( 'mux-player', 'enqueued' ) );
	}

	public function test_comment_without_video_is_untouched(): void {
		$cid  = self::factory()->comment->create();
		$html = Video_Comments_Render::get_instance()->append_video_player( 'plain', get_comment( $cid ) );
		$this->assertSame( 'plain', $html );
		$this->assertFalse( wp_script_is( 'mux-player', 'enqueued' ) );
	}

	public function test_player_attrs_are_escaped(): void {
		$cid = self::factory()->comment->create();
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_PLAYBACK_ID, 'pbESC', true );
		add_filter( 'video_comments_player_attrs', fn( $attrs ) => $attrs + [ 'data-x' => '"><script>' ] );
		$html = apply_filters( 'comment_text', 'x', get_comment( $cid ) );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'data-x="&quot;&gt;&lt;script&gt;"', $html );
	}

	public function test_uploader_markup_carries_upload_fields_and_labelled_progress(): void {
		ob_start();
		Video_Comments_Render::get_instance()->render_uploader();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="' . Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD . '"', $html );
		$this->assertStringContainsString( 'name="' . Video_Comments_Comment_Meta::FORM_FIELD_TOKEN . '"', $html );
		$this->assertStringContainsString( 'name="' . Video_Comments_Comment_Meta::NONCE_FIELD . '"', $html );
		$this->assertStringNotContainsString( 'vc-playback-id', $html );
		$this->assertMatchesRegularExpression( '/<progress[^>]+aria-label="/', $html );
		$this->assertTrue( wp_script_is( 'mux-player', 'enqueued' ), 'preview player loads with the uploader' );
	}

	public function test_uploader_without_credentials_does_not_load_player(): void {
		$this->set_plugin_options(
			[
				'mux_token_id'     => '',
				'mux_token_secret' => '',
			]
		);
		ob_start();
		Video_Comments_Render::get_instance()->render_uploader();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'vc-status--notice', $html );
		$this->assertFalse( wp_script_is( 'mux-player', 'enqueued' ) );
	}
}
