<?php
/**
 * Privacy tools integration: policy text, personal data exporter and eraser.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the plugin into WordPress's privacy tools.
 *
 * A commenter's video is personal data held by a third party (Mux). Core's
 * comment eraser anonymises the comment row but knows nothing about the video,
 * so without this class an erased commenter's video would stay online.
 */
final class Video_Comments_Privacy {

	/** Exporter/eraser slug. */
	private const KEY = 'video-comments';

	/** Comments per exporter/eraser page. */
	private const PER_PAGE = 50;

	/**
	 * Singleton instance.
	 *
	 * @var Video_Comments_Privacy|null
	 */
	private static ?Video_Comments_Privacy $instance = null;

	/** Singleton accessor. */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private constructor — use get_instance(). */
	private function __construct() {}

	/** Register privacy hooks. */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'add_privacy_policy_content' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	// -------------------------------------------------------------------------
	// Privacy policy suggestion
	// -------------------------------------------------------------------------

	/** Suggest privacy-policy text under Settings → Privacy → Policy Guide. */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for sites that let commenters attach a video. Adjust the retention and contact details to match your site.', 'video-comments' ) . '</p>';
		$content .= '<p>' . esc_html__( 'When you attach a video to a comment, the video file is uploaded from your browser directly to Mux (Mux, Inc.), a third-party video hosting service, and is stored and streamed from their servers. Mux\'s handling of that file is governed by their privacy policy.', 'video-comments' ) . '</p>';
		$content .= '<p>' . esc_html__( 'This site stores only the identifiers Mux assigns to your video, linked to your comment. Anyone who can view the comment can watch the video. Pages that show a video load the Mux video player from a third-party content delivery network (jsDelivr), which receives your IP address as part of that request.', 'video-comments' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Your IP address is used in hashed form for up to one minute to limit how often video uploads can be requested. Videos are deleted from Mux when the comment they belong to is permanently deleted, when a site administrator removes the video, when an upload is abandoned without posting a comment, or when you ask us to erase your personal data.', 'video-comments' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Video Comments using Mux', 'video-comments' ), wp_kses_post( $content ) );
	}

	// -------------------------------------------------------------------------
	// Exporter
	// -------------------------------------------------------------------------

	/**
	 * Register the exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Registered exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::KEY ] = [
			'exporter_friendly_name' => __( 'Comment videos', 'video-comments' ),
			'callback'               => [ $this, 'export' ],
		];
		return $exporters;
	}

	/**
	 * Export the videos attached to comments by this email address.
	 *
	 * @param string $email_address Requester's email.
	 * @param int    $page          Page number (1-based).
	 * @return array{data: array<int,array<string,mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$comments = $this->get_video_comments( $email_address, $page );
		$data     = [];

		foreach ( $comments as $comment ) {
			$playback_id = Video_Comments_Comment_Meta::get_playback_id( (int) $comment->comment_ID );
			if ( '' === $playback_id ) {
				continue;
			}

			$data[] = [
				'group_id'          => self::KEY,
				'group_label'       => __( 'Comment videos', 'video-comments' ),
				'group_description' => __( 'Videos attached to your comments. The video files are hosted by Mux.', 'video-comments' ),
				'item_id'           => 'video-comment-' . $comment->comment_ID,
				'data'              => [
					[
						'name'  => __( 'Comment date', 'video-comments' ),
						'value' => $comment->comment_date,
					],
					[
						'name'  => __( 'Comment URL', 'video-comments' ),
						'value' => get_comment_link( $comment ),
					],
					[
						'name'  => __( 'Video', 'video-comments' ),
						'value' => 'https://stream.mux.com/' . rawurlencode( $playback_id ) . '.m3u8',
					],
					[
						'name'  => __( 'Mux playback ID', 'video-comments' ),
						'value' => $playback_id,
					],
				],
			];
		}

		return [
			'data' => $data,
			'done' => count( $comments ) < self::PER_PAGE,
		];
	}

	// -------------------------------------------------------------------------
	// Eraser
	// -------------------------------------------------------------------------

	/**
	 * Register the eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Registered erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::KEY ] = [
			'eraser_friendly_name' => __( 'Comment videos', 'video-comments' ),
			'callback'             => [ $this, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * Remove the videos attached to comments by this email address: the Mux
	 * asset is queued for deletion and the comment meta is removed.
	 *
	 * @param string $email_address Requester's email.
	 * @param int    $page          Page number (1-based).
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int,string>, done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		// Always erase from page 1: each pass removes the meta the query matches on.
		$comments = $this->get_video_comments( $email_address, 1 );
		$removed  = false;

		foreach ( $comments as $comment ) {
			$comment_id = (int) $comment->comment_ID;
			$asset_id   = get_comment_meta( $comment_id, Video_Comments_Comment_Meta::META_ASSET_ID, true );

			if ( is_string( $asset_id ) && '' !== $asset_id ) {
				Video_Comments_Cleanup::schedule_asset_deletion( $asset_id );
			}

			delete_comment_meta( $comment_id, Video_Comments_Comment_Meta::META_PLAYBACK_ID );
			delete_comment_meta( $comment_id, Video_Comments_Comment_Meta::META_ASSET_ID );
			delete_comment_meta( $comment_id, Video_Comments_Comment_Meta::META_PROVIDER );
			$removed = true;
		}

		unset( $page );

		return [
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $removed ? [ __( 'Videos attached to comments were removed and their deletion from Mux has been scheduled.', 'video-comments' ) ] : [],
			'done'           => count( $comments ) < self::PER_PAGE,
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Comments by this email address that carry a video, in any status.
	 *
	 * @param string $email_address Commenter email.
	 * @param int    $page          Page number (1-based).
	 * @return WP_Comment[]
	 */
	private function get_video_comments( string $email_address, int $page ): array {
		return get_comments(
			[
				'author_email'              => $email_address,
				'meta_key'                  => Video_Comments_Comment_Meta::META_PLAYBACK_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the only way to find video comments.
				'status'                    => [ 'approve', 'hold', 'spam', 'trash', 'post-trashed' ],
				'number'                    => self::PER_PAGE,
				'paged'                     => max( 1, $page ),
				'orderby'                   => 'comment_ID',
				'order'                     => 'ASC',
				'update_comment_meta_cache' => true,
			]
		);
	}
}
