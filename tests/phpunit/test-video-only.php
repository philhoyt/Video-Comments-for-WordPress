<?php
/**
 * Video-only comments: an empty body is accepted on the classic comment form
 * when a finished, owned upload comes with it, and nowhere else.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

/**
 * @covers Video_Comments_Comment_Meta
 * @covers Video_Comments_Render
 */
class Test_Video_Comments_Video_Only extends WP_UnitTestCase {

	use Video_Comments_Mux_Mock;

	private int $post_id;
	private int $author_id;
	private int $commenter_id;
	private int $upload_counter = 0;

	public function set_up(): void {
		parent::set_up();
		$this->enable_mux_mock();
		reset_phpmailer_instance();

		// The post author must be a real user with an email, distinct from the
		// commenter, or wp_notify_postauthor() returns before its filter runs.
		$this->author_id    = self::factory()->user->create( [ 'role' => 'author' ] );
		$this->post_id      = self::factory()->post->create( [ 'post_author' => $this->author_id ] );
		$this->commenter_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->commenter_id );

		// Core blocks a second comment from the same person within 15 seconds.
		add_filter( 'wp_is_comment_flood', '__return_false', 20 );

		// wp-env serves the tests site from localhost, and PHPMailer rejects the
		// dotless From address core derives from it, so no mail would be "sent".
		add_filter( 'wp_mail_from', static fn(): string => 'wordpress@example.org' );
	}

	public function tear_down(): void {
		Video_Comments_Comment_Meta::reset_request_state();
		reset_phpmailer_instance();
		$_POST = [];
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function issue_token( string $upload_id ): string {
		$m = new ReflectionMethod( Video_Comments_REST::class, 'issue_upload_token' );
		$m->setAccessible( true );
		return $m->invoke( null, $upload_id );
	}

	/** Seed a ready upload with ids unique to this test and hand back its form fields. */
	private function ready_upload_fields( ?string $asset_id = null ): array {
		++$this->upload_counter;
		$upload_id = 'uplVO' . $this->upload_counter;
		$asset_id  = $asset_id ?? 'assetVO' . $this->upload_counter;
		$this->mux_seed_ready_upload( $upload_id, $asset_id, 'pbVO' . $this->upload_counter );
		return [
			Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD => $upload_id,
			Video_Comments_Comment_Meta::FORM_FIELD_TOKEN  => $this->issue_token( $upload_id ),
		];
	}

	/**
	 * Submit the classic comment form the way wp-comments-post.php does.
	 *
	 * @param string $content Comment text.
	 * @param array  $fields  Extra $_POST fields (upload id, token).
	 * @param array  $post    Overrides for the submission array.
	 * @return WP_Comment|WP_Error
	 */
	private function submit( string $content, array $fields = [], array $post = [] ) {
		$_POST = array_merge(
			[ Video_Comments_Comment_Meta::NONCE_FIELD => wp_create_nonce( 'video_comments_comment_submit' ) ],
			$fields
		);
		return wp_handle_comment_submission(
			array_merge(
				[
					'comment_post_ID' => $this->post_id,
					'comment'         => $content,
					'author'          => '',
					'email'           => '',
					'url'             => '',
					'comment_parent'  => 0,
				],
				$post
			)
		);
	}

	private function mux_request_count( string $needle ): int {
		return count(
			array_filter(
				$this->mux_requests,
				static fn( string $r ): bool => false !== strpos( $r, $needle )
			)
		);
	}

	private function assert_rejected_empty( $result, string $message = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $message );
		$this->assertSame( 'require_valid_comment', $result->get_error_code(), $message );
		$this->assertSame(
			0,
			(int) get_comments(
				[
					'post_id' => $this->post_id,
					'count'   => true,
					'status'  => 'all',
				]
			),
			'no comment inserted'
		);
	}

	/** Let the commenter through moderation: one earlier approved comment under the same name and email. */
	private function pre_approve_commenter(): void {
		$user = get_userdata( $this->commenter_id );
		self::factory()->comment->create(
			[
				'comment_post_ID'      => self::factory()->post->create(),
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'user_id'              => $this->commenter_id,
				'comment_approved'     => 1,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Acceptance
	// -------------------------------------------------------------------------

	public function test_empty_comment_with_ready_upload_is_created_with_the_video(): void {
		$fields = $this->ready_upload_fields();
		$result = $this->submit( '', $fields );

		$this->assertInstanceOf( WP_Comment::class, $result );
		$this->assertSame( '', $result->comment_content );
		$this->assertSame( 'pbVO1', Video_Comments_Comment_Meta::get_playback_id( (int) $result->comment_ID ) );
		$this->assertSame( 'assetVO1', get_comment_meta( (int) $result->comment_ID, Video_Comments_Comment_Meta::META_ASSET_ID, true ) );
		$this->assertFalse(
			Video_Comments_REST::verify_upload_token( $fields[ Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD ], $fields[ Video_Comments_Comment_Meta::FORM_FIELD_TOKEN ] ),
			'token retired once attached'
		);
		$this->assertSame( 1, $this->mux_request_count( '/uploads/uplVO1' ), 'one upload lookup for the whole request' );
		$this->assertSame( 1, $this->mux_request_count( '/assets/assetVO1' ), 'one asset lookup for the whole request' );
	}

	public function test_whitespace_only_body_counts_as_empty(): void {
		$result = $this->submit( "  \n\t ", $this->ready_upload_fields() );
		$this->assertInstanceOf( WP_Comment::class, $result );
		$this->assertNotSame( '', Video_Comments_Comment_Meta::get_playback_id( (int) $result->comment_ID ) );
	}

	public function test_replaying_a_used_upload_is_rejected_without_calling_mux(): void {
		$fields = $this->ready_upload_fields();
		$this->assertInstanceOf( WP_Comment::class, $this->submit( '', $fields ) );
		$requests_after_first = count( $this->mux_requests );
		Video_Comments_Comment_Meta::reset_request_state(); // A new request.

		$result = $this->submit( '', $fields );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'require_valid_comment', $result->get_error_code() );
		$this->assertCount( $requests_after_first, $this->mux_requests, 'retired token fails before any Mux lookup' );
	}

	public function test_ready_status_is_served_from_cache_across_requests(): void {
		$fields = $this->ready_upload_fields();
		$_POST  = array_merge( [ Video_Comments_Comment_Meta::NONCE_FIELD => wp_create_nonce( 'video_comments_comment_submit' ) ], $fields );
		$data   = [ 'comment_content' => '' ];
		$meta   = Video_Comments_Comment_Meta::get_instance();

		$this->assertTrue( $meta->allow_video_only_comment( false, $data ) );
		$this->assertCount( 2, $this->mux_requests );

		Video_Comments_Comment_Meta::reset_request_state(); // A new request with a still-valid token.
		$this->assertTrue( $meta->allow_video_only_comment( false, $data ) );
		$this->assertCount( 2, $this->mux_requests, 'second request hits the transient, not Mux' );
	}

	public function test_same_author_can_post_two_video_only_comments_on_one_post(): void {
		$first = $this->submit( '', $this->ready_upload_fields() );
		Video_Comments_Comment_Meta::reset_request_state();
		$second = $this->submit( '', $this->ready_upload_fields() );

		$this->assertInstanceOf( WP_Comment::class, $first );
		$this->assertInstanceOf( WP_Comment::class, $second, 'second empty body is not a duplicate when it carries its own video' );
		$this->assertNotSame( $first->comment_ID, $second->comment_ID );
	}

	public function test_text_comments_keep_the_duplicate_check(): void {
		$this->assertInstanceOf( WP_Comment::class, $this->submit( 'same words' ) );
		$second = $this->submit( 'same words' );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'comment_duplicate', $second->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Rejections that never reach Mux
	// -------------------------------------------------------------------------

	public function test_empty_comment_without_upload_is_rejected(): void {
		$this->assert_rejected_empty( $this->submit( '' ) );
		$this->assertCount( 0, $this->mux_requests );
	}

	public function test_two_empty_comments_without_video_both_fail_on_content_not_duplicate(): void {
		$this->assert_rejected_empty( $this->submit( '' ) );
		$this->assert_rejected_empty( $this->submit( '' ) );
	}

	public function test_missing_nonce_is_rejected(): void {
		$fields = $this->ready_upload_fields();
		$_POST  = $fields;
		$result = wp_handle_comment_submission(
			[
				'comment_post_ID' => $this->post_id,
				'comment'         => '',
			]
		);
		$this->assert_rejected_empty( $result );
		$this->assertCount( 0, $this->mux_requests );
	}

	public function test_wrong_token_is_rejected(): void {
		$fields = $this->ready_upload_fields();
		$fields[ Video_Comments_Comment_Meta::FORM_FIELD_TOKEN ] = 'nope';
		$this->assert_rejected_empty( $this->submit( '', $fields ) );
		$this->assertCount( 0, $this->mux_requests );
	}

	public function test_array_valued_upload_field_is_rejected(): void {
		$fields = $this->ready_upload_fields();
		$fields[ Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD ] = [ $fields[ Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD ] ];
		$this->assert_rejected_empty( $this->submit( '', $fields ) );
		$this->assertCount( 0, $this->mux_requests );
	}

	public function test_disabled_plugin_rejects_empty_comment(): void {
		$this->set_plugin_options( [ 'enabled' => 0 ] );
		$this->assert_rejected_empty( $this->submit( '', $this->ready_upload_fields() ) );
		$this->assertCount( 0, $this->mux_requests );
	}

	public function test_guest_is_rejected_when_guest_uploads_are_off(): void {
		$this->set_plugin_options( [ 'allow_guests' => 0 ] );
		$fields = $this->ready_upload_fields();
		wp_set_current_user( 0 );
		$_POST  = array_merge( [ Video_Comments_Comment_Meta::NONCE_FIELD => wp_create_nonce( 'video_comments_comment_submit' ) ], $fields );
		$result = wp_handle_comment_submission(
			[
				'comment_post_ID' => $this->post_id,
				'comment'         => '',
				'author'          => 'Guest',
				'email'           => 'guest@example.org',
				'url'             => '',
			]
		);
		$this->assert_rejected_empty( $result );
		$this->assertCount( 0, $this->mux_requests );
	}

	public function test_missing_credentials_rejects_empty_comment(): void {
		$fields = $this->ready_upload_fields();
		$this->set_plugin_options(
			[
				'mux_token_id'     => '',
				'mux_token_secret' => '',
			]
		);
		$this->assert_rejected_empty( $this->submit( '', $fields ) );
		$this->assertCount( 0, $this->mux_requests );
	}

	public function test_developer_filter_vetoes_before_any_mux_request(): void {
		add_filter( 'video_comments_allow_video_only', '__return_false' );
		$this->assert_rejected_empty( $this->submit( '', $this->ready_upload_fields() ) );
		$this->assertCount( 0, $this->mux_requests );
	}

	public function test_text_comment_without_upload_saves_and_never_calls_mux(): void {
		$result = $this->submit( 'Just words.' );
		$this->assertInstanceOf( WP_Comment::class, $result );
		$this->assertCount( 0, $this->mux_requests );
		$this->assertSame( '', Video_Comments_Comment_Meta::get_playback_id( (int) $result->comment_ID ) );
	}

	// -------------------------------------------------------------------------
	// Rejections decided by Mux
	// -------------------------------------------------------------------------

	public function test_upload_still_processing_is_rejected(): void {
		$upload_id                       = 'uplWaiting';
		$this->mux_uploads[ $upload_id ] = [
			'status'   => 'waiting',
			'asset_id' => null,
		];
		$fields                          = [
			Video_Comments_Comment_Meta::FORM_FIELD_UPLOAD => $upload_id,
			Video_Comments_Comment_Meta::FORM_FIELD_TOKEN  => $this->issue_token( $upload_id ),
		];
		$this->assert_rejected_empty( $this->submit( '', $fields ) );
		$this->assertSame( 1, $this->mux_request_count( '/uploads/' . $upload_id ) );
		$this->assertTrue( Video_Comments_REST::verify_upload_token( $upload_id, $fields[ Video_Comments_Comment_Meta::FORM_FIELD_TOKEN ] ), 'token kept so the client can retry' );
	}

	public function test_mux_error_is_rejected(): void {
		$fields                = $this->ready_upload_fields();
		$this->mux_fail['GET'] = 500;
		$this->assert_rejected_empty( $this->submit( '', $fields ) );
	}

	public function test_ready_asset_without_playback_id_is_rejected(): void {
		$fields                                      = $this->ready_upload_fields();
		$this->mux_assets['assetVO1']['playback_id'] = null;
		$this->assert_rejected_empty( $this->submit( '', $fields ) );
	}

	public function test_asset_already_attached_elsewhere_is_rejected(): void {
		$first = $this->submit( '', $this->ready_upload_fields( 'assetShared' ) );
		$this->assertInstanceOf( WP_Comment::class, $first );
		Video_Comments_Comment_Meta::reset_request_state();

		$this->assertInstanceOf( WP_Error::class, $this->submit( '', $this->ready_upload_fields( 'assetShared' ) ) );
		$this->assertSame(
			1,
			(int) get_comments(
				[
					'meta_key'   => Video_Comments_Comment_Meta::META_ASSET_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => 'assetShared', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'count'      => true,
				]
			),
			'one asset belongs to one comment'
		);
	}

	// -------------------------------------------------------------------------
	// Other transports
	// -------------------------------------------------------------------------

	public function test_rest_comment_creation_still_requires_content(): void {
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$fields = $this->ready_upload_fields();
		$_POST  = array_merge( [ Video_Comments_Comment_Meta::NONCE_FIELD => wp_create_nonce( 'video_comments_comment_submit' ) ], $fields );

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_body_params(
			[
				'post'    => $this->post_id,
				'content' => '',
			]
		);
		$response       = $wp_rest_server->dispatch( $request );
		$wp_rest_server = null;

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_comment_content_invalid', $response->as_error()->get_error_code() );
		$this->assertSame(
			0,
			(int) get_comments(
				[
					'post_id' => $this->post_id,
					'count'   => true,
					'status'  => 'all',
				]
			)
		);
		$this->assertFalse( has_filter( 'allow_empty_comment', [ Video_Comments_Comment_Meta::get_instance(), 'allow_video_only_comment' ] ), 'override is never registered outside the classic form handler' );
		$this->assertCount( 0, $this->mux_requests );
	}

	// -------------------------------------------------------------------------
	// Emails
	// -------------------------------------------------------------------------

	public function test_moderation_email_links_to_the_video(): void {
		update_option( 'comment_moderation', 1 );
		$result = $this->submit( '', $this->ready_upload_fields() );
		$this->assertInstanceOf( WP_Comment::class, $result );
		$this->assertSame( '0', $result->comment_approved );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotFalse( $mail, 'moderation email was sent' );
		$this->assertStringContainsString( 'This comment includes a video:', $mail->body );
		$this->assertStringContainsString( 'comment.php?action=editcomment&c=' . $result->comment_ID, $mail->body );
	}

	public function test_post_author_email_links_to_the_video(): void {
		$this->pre_approve_commenter();
		$result = $this->submit( '', $this->ready_upload_fields() );
		$this->assertInstanceOf( WP_Comment::class, $result );
		$this->assertSame( '1', $result->comment_approved );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotFalse( $mail, 'post author email was sent' );
		$this->assertStringContainsString( 'This comment includes a video:', $mail->body );
		$this->assertStringContainsString( get_comment_link( $result ), $mail->body );
	}

	public function test_emails_for_a_text_comment_carry_no_video_line(): void {
		$this->pre_approve_commenter();
		$result = $this->submit( 'Words only.' );
		$this->assertInstanceOf( WP_Comment::class, $result );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotFalse( $mail );
		$this->assertStringNotContainsString( 'This comment includes a video', $mail->body );
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	private function video_only_comment(): WP_Comment {
		$cid = self::factory()->comment->create(
			[
				'comment_post_ID' => $this->post_id,
				'comment_content' => '',
			]
		);
		add_comment_meta( $cid, Video_Comments_Comment_Meta::META_PLAYBACK_ID, 'pbEMPTY', true );
		return get_comment( $cid );
	}

	public function test_comment_content_block_renders_the_player_for_an_empty_body(): void {
		$comment = $this->video_only_comment();
		$block   = new WP_Block(
			[
				'blockName'    => 'core/comment-content',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[ 'commentId' => (int) $comment->comment_ID ]
		);

		$html = $block->render();

		$this->assertStringContainsString( '<mux-player', $html );
		$this->assertStringContainsString( 'playback-id="pbEMPTY"', $html );
		$this->assertStringContainsString( 'vc-sr-only', $html );
	}

	public function test_empty_body_label_only_applies_to_video_comments(): void {
		$comment = $this->video_only_comment();
		$this->assertStringContainsString( 'vc-sr-only', get_comment_text( $comment ) );

		$plain = get_comment( self::factory()->comment->create( [ 'comment_content' => '' ] ) );
		$this->assertSame( '', get_comment_text( $plain ) );

		$this->assertSame( 'kept', apply_filters( 'get_comment_text', 'kept', $comment ), 'a comment with text is untouched' );
	}

	public function test_admin_list_shows_placeholder_linking_to_the_edit_screen(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-comments' );
		$this->assertTrue( is_admin() );

		$comment = $this->video_only_comment();
		$html    = apply_filters( 'comment_text', '', $comment );

		$this->assertStringContainsString( 'video-comment-placeholder', $html );
		$this->assertStringContainsString( '[Video comment]', $html );
		$this->assertStringContainsString( 'action=editcomment', $html );
		$this->assertStringNotContainsString( '<mux-player', $html );

		$with_text = self::factory()->comment->create( [ 'comment_content' => 'has words' ] );
		add_comment_meta( $with_text, Video_Comments_Comment_Meta::META_PLAYBACK_ID, 'pbTEXT', true );
		$this->assertStringContainsString( '<mux-player', apply_filters( 'comment_text', 'has words', get_comment( $with_text ) ), 'admin output for a comment with text is unchanged' );

		$plain = self::factory()->comment->create( [ 'comment_content' => '' ] );
		$this->assertSame( '', Video_Comments_Render::get_instance()->append_video_player( '', get_comment( $plain ) ) );
		$this->assertSame( 'moderation input', Video_Comments_Render::get_instance()->append_video_player( 'moderation input', null ), 'check_comment() passes a null comment' );
	}
}
