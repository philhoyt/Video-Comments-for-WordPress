<?php
/**
 * Comment meta saving and retrieval.
 *
 * @package Video_Comments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the comment lifecycle to persist and expose video metadata.
 */
class Video_Comments_Comment_Meta {

	/** @var Video_Comments_Comment_Meta|null */
	private static ?Video_Comments_Comment_Meta $instance = null;

	/** Comment meta key — video provider name. */
	public const META_PROVIDER = 'video_comments_provider';

	/** Comment meta key — Mux playback ID. */
	public const META_PLAYBACK_ID = 'video_comments_mux_playback_id';

	/** Comment meta key — Mux asset ID. */
	public const META_ASSET_ID = 'video_comments_mux_asset_id';

	/** Hidden input name posted with the comment form. */
	public const FORM_FIELD = 'video_comments_mux_playback_id';

	/** Nonce action for playback_id validation on comment submit. */
	private const NONCE_ACTION = 'vc_comment_submit';

	/** Nonce field name. */
	public const NONCE_FIELD = 'vc_comment_nonce';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		// Save meta after comment is inserted.
		add_action( 'comment_post', [ $this, 'save_comment_video_meta' ], 10, 3 );

		// Allow the playback_id field through WP comment data sanitization.
		add_filter( 'preprocess_comment', [ $this, 'capture_playback_id_before_sanitize' ] );
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Stash the raw playback_id from $_POST before WP strips unknown keys.
	 * We use a static variable to carry it into save_comment_video_meta().
	 *
	 * @param array<string,mixed> $comment_data Data array passed to wp_insert_comment.
	 * @return array<string,mixed>
	 */
	public function capture_playback_id_before_sanitize( array $comment_data ): array {
		// Only capture when the feature is active.
		if ( ! Video_Comments_Settings::is_enabled() ) {
			return $comment_data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in save_comment_video_meta.
		$playback_id = isset( $_POST[ self::FORM_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::FORM_FIELD ] ) )
			: '';

		// Store on the data array under a private key so it survives into the post hook.
		$comment_data['_vc_playback_id'] = $playback_id;

		// When a video is attached and the commenter left the text field blank,
		// JS injects a non-breaking space (U+00A0) so WordPress's empty-content
		// check is bypassed. Strip it here for clean DB storage.
		if ( '' !== $playback_id && isset( $comment_data['comment_content'] ) ) {
			$cleaned = trim( str_replace( "\xc2\xa0", '', (string) $comment_data['comment_content'] ) );
			if ( '' === $cleaned ) {
				$comment_data['comment_content'] = '';
			}
		}

		return $comment_data;
	}

	/**
	 * After comment is inserted, persist video meta if a valid playback_id was submitted.
	 *
	 * @param int             $comment_id  New comment ID.
	 * @param int|string      $approved    Approval status (0|1|'spam').
	 * @param array<string,mixed> $commentdata Full comment data array.
	 */
	public function save_comment_video_meta( int $comment_id, $approved, array $commentdata ): void {
		if ( ! Video_Comments_Settings::is_enabled() ) {
			return;
		}

		// Guest guard.
		if ( ! is_user_logged_in() && ! Video_Comments_Settings::allow_guests() ) {
			return;
		}

		// Verify the nonce posted with the comment form.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			// No nonce or invalid — silently skip; the comment itself is still saved.
			return;
		}

		// Retrieve playback_id (set in capture_playback_id_before_sanitize).
		$playback_id = sanitize_text_field( $commentdata['_vc_playback_id'] ?? '' );

		if ( empty( $playback_id ) ) {
			return;
		}

		// Basic format sanity: Mux playback IDs are alphanumeric + hyphens.
		if ( ! preg_match( '/^[A-Za-z0-9\-]+$/', $playback_id ) ) {
			return;
		}

		add_comment_meta( $comment_id, self::META_PROVIDER, 'mux', true );
		add_comment_meta( $comment_id, self::META_PLAYBACK_ID, $playback_id, true );
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the Mux playback ID for a comment, if any.
	 *
	 * @param int $comment_id Comment ID.
	 * @return string Empty string if none.
	 */
	public static function get_playback_id( int $comment_id ): string {
		$val = get_comment_meta( $comment_id, self::META_PLAYBACK_ID, true );
		return is_string( $val ) ? sanitize_text_field( $val ) : '';
	}

	/**
	 * Build the nonce field HTML for the comment form.
	 */
	public static function nonce_field_html(): string {
		return wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );
	}
}
