<?php
/**
 * Comment meta saving and retrieval.
 *
 * @package Video_Comments
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the comment lifecycle to persist and expose video metadata.
 *
 * @package Video_Comments
 */
class Video_Comments_Comment_Meta {

	/**
	 * Singleton instance.
	 *
	 * @var Video_Comments_Comment_Meta|null
	 */
	private static ?Video_Comments_Comment_Meta $instance = null;

	/** Comment meta key — video provider name. */
	public const META_PROVIDER = 'video_comments_provider';

	/** Comment meta key — Mux playback ID. */
	public const META_PLAYBACK_ID = 'video_comments_mux_playback_id';

	/** Comment meta key — Mux asset ID. */
	public const META_ASSET_ID = 'video_comments_mux_asset_id';

	/** Hidden input name posted with the comment form — Mux upload ID. */
	public const FORM_FIELD_UPLOAD = 'video_comments_mux_upload_id';

	/** Hidden input name posted with the comment form — per-upload token issued by the REST API. */
	public const FORM_FIELD_TOKEN = 'video_comments_upload_token';

	/** Nonce action for playback_id validation on comment submit. */
	private const NONCE_ACTION = 'video_comments_comment_submit';

	/** Nonce field name. */
	public const NONCE_FIELD = 'video_comments_comment_nonce';

	/** Singleton accessor. */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private constructor — use get_instance(). */
	private function __construct() {}

	/** Register hooks for saving and displaying comment video meta. */
	public function init(): void {
		// Save meta after comment is inserted.
		add_action( 'comment_post', [ $this, 'save_comment_video_meta' ], 10, 3 );

		// Carry the upload fields through WP comment data sanitization.
		add_filter( 'preprocess_comment', [ $this, 'capture_upload_fields_before_sanitize' ] );

		// Admin comment edit page.
		add_action( 'add_meta_boxes_comment', [ $this, 'add_comment_meta_box' ] );
		add_action( 'edit_comment', [ $this, 'save_admin_comment_meta' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Delete the Mux asset when a comment is permanently deleted.
		add_action( 'delete_comment', [ $this, 'delete_mux_asset_for_comment' ] );
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Stash the raw upload fields from $_POST before WP strips unknown keys,
	 * so they are available to save_comment_video_meta() via $commentdata.
	 *
	 * @param array<string,mixed> $comment_data Data array passed to wp_insert_comment.
	 * @return array<string,mixed>
	 */
	public function capture_upload_fields_before_sanitize( array $comment_data ): array {
		// Only capture when the feature is active.
		if ( ! Video_Comments_Settings::is_enabled() ) {
			return $comment_data;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in save_comment_video_meta.
		$upload_id = isset( $_POST[ self::FORM_FIELD_UPLOAD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::FORM_FIELD_UPLOAD ] ) )
			: '';

		$token = isset( $_POST[ self::FORM_FIELD_TOKEN ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::FORM_FIELD_TOKEN ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Store on the data array under private keys so they survive into the post hook.
		$comment_data['_vc_upload_id']    = $upload_id;
		$comment_data['_vc_upload_token'] = $token;

		return $comment_data;
	}

	/**
	 * After the comment is inserted, attach the video if the submitter owns a
	 * finished upload.
	 *
	 * The playback and asset IDs are never taken from the form. The form only
	 * carries the upload ID and the per-upload token issued when the upload was
	 * created; both IDs are then resolved from Mux for that upload, so a commenter
	 * cannot attach a video they did not upload through this site.
	 *
	 * @param int                 $comment_id  New comment ID.
	 * @param int|string          $approved    Approval status (0|1|'spam').
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

		$upload_id = sanitize_text_field( (string) ( $commentdata['_vc_upload_id'] ?? '' ) );
		$token     = sanitize_text_field( (string) ( $commentdata['_vc_upload_token'] ?? '' ) );

		if ( '' === $upload_id || '' === $token ) {
			return;
		}

		// Only the caller who created the upload holds its token.
		if ( ! Video_Comments_REST::verify_upload_token( $upload_id, $token ) ) {
			return;
		}

		if ( ! Video_Comments_Settings::has_mux_credentials() ) {
			return;
		}

		$provider = new Video_Comments_Provider_Mux(
			Video_Comments_Settings::get_mux_token_id(),
			Video_Comments_Settings::get_mux_token_secret()
		);

		$status = $provider->get_upload_status( $upload_id );
		if ( is_wp_error( $status ) ) {
			return;
		}

		$playback_id = (string) ( $status['playback_id'] ?? '' );
		$asset_id    = (string) ( $status['asset_id'] ?? '' );

		// Nothing to attach until Mux has produced a playable asset.
		if ( '' === $playback_id || '' === $asset_id ) {
			return;
		}

		// Format sanity on what Mux returned: IDs are alphanumeric + hyphens.
		if ( ! preg_match( '/^[A-Za-z0-9\-]+$/', $playback_id ) || ! preg_match( '/^[A-Za-z0-9\-]+$/', $asset_id ) ) {
			return;
		}

		add_comment_meta( $comment_id, self::META_PROVIDER, 'mux', true );
		add_comment_meta( $comment_id, self::META_PLAYBACK_ID, $playback_id, true );
		add_comment_meta( $comment_id, self::META_ASSET_ID, $asset_id, true );

		// The upload now belongs to this comment; retire the token so it cannot be
		// used to delete the asset through the REST API afterwards, and take it
		// off the orphan sweep's list.
		Video_Comments_REST::forget_upload( $upload_id );
		Video_Comments_Cleanup::forget_pending_upload( $upload_id );
	}

	// -------------------------------------------------------------------------
	// Admin comment edit page
	// -------------------------------------------------------------------------

	/**
	 * Enqueue the Mux player on the comment edit screen so the preview works.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'comment.php' !== $hook ) {
			return;
		}

		/** This filter is documented in class-render.php */
		$src = apply_filters( 'video_comments_mux_player_src', Video_Comments_Render::MUX_PLAYER_CDN );
		if ( $src ) {
			wp_enqueue_script( 'mux-player', esc_url( $src ), [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
	}

	/**
	 * Register the meta box on the comment edit screen.
	 *
	 * @param WP_Comment $comment The comment being edited.
	 */
	public function add_comment_meta_box( WP_Comment $comment ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by add_meta_boxes_comment hook.
		add_meta_box(
			'video-comments-meta',
			__( 'Video Comment', 'video-comments' ),
			[ $this, 'render_comment_meta_box' ],
			'comment',
			'normal'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param WP_Comment $comment The comment being edited.
	 */
	public function render_comment_meta_box( WP_Comment $comment ): void {
		$playback_id = self::get_playback_id( (int) $comment->comment_ID );

		wp_nonce_field( 'video_comments_edit_comment_' . $comment->comment_ID, 'video_comments_edit_nonce' );
		?>
		<table class="form-table editcomment" style="margin-top:0;">
			<tbody>
				<tr>
					<td class="first"><label for="vc-admin-playback-id"><?php esc_html_e( 'Mux Playback ID', 'video-comments' ); ?></label></td>
					<td>
						<input
							type="text"
							id="vc-admin-playback-id"
							name="video_comments_playback_id"
							value="<?php echo esc_attr( $playback_id ); ?>"
							class="large-text"
							placeholder="<?php esc_attr_e( 'Leave blank to remove the video', 'video-comments' ); ?>"
						/>
					</td>
				</tr>

				<?php if ( $playback_id ) : ?>
				<tr>
					<td class="first"></td>
					<td>
						<div style="margin-top:0.5em;max-width:480px;">
							<mux-player
								playback-id="<?php echo esc_attr( $playback_id ); ?>"
								controls
								playsinline
								style="display:block;width:100%;"
							></mux-player>
						</div>
					</td>
				</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save the playback ID when an admin edits a comment.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function save_admin_comment_meta( int $comment_id ): void {
		if ( ! isset( $_POST['video_comments_edit_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['video_comments_edit_nonce'] ) ), 'video_comments_edit_comment_' . $comment_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_comment', $comment_id ) ) {
			return;
		}

		$playback_id = isset( $_POST['video_comments_playback_id'] )
			? sanitize_text_field( wp_unslash( $_POST['video_comments_playback_id'] ) )
			: '';

		if ( '' === $playback_id ) {
			$this->delete_mux_asset_for_comment( $comment_id );
			delete_comment_meta( $comment_id, self::META_PLAYBACK_ID );
			delete_comment_meta( $comment_id, self::META_PROVIDER );
			delete_comment_meta( $comment_id, self::META_ASSET_ID );
			return;
		}

		if ( ! preg_match( '/^[A-Za-z0-9\-]+$/', $playback_id ) ) {
			return;
		}

		update_comment_meta( $comment_id, self::META_PLAYBACK_ID, $playback_id );
		update_comment_meta( $comment_id, self::META_PROVIDER, 'mux' );
	}

	// -------------------------------------------------------------------------
	// Mux asset deletion
	// -------------------------------------------------------------------------

	/**
	 * Queue deletion of the Mux asset associated with a comment, if any.
	 * Called when a comment is permanently deleted from WordPress, or when an
	 * admin clears the video from the comment edit screen.
	 *
	 * The HTTP call runs in a WP-Cron event (with retries), so admin requests
	 * such as emptying the trash never block on the Mux API.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function delete_mux_asset_for_comment( int $comment_id ): void {
		$asset_id = get_comment_meta( $comment_id, self::META_ASSET_ID, true );
		if ( empty( $asset_id ) || ! is_string( $asset_id ) ) {
			return;
		}

		Video_Comments_Cleanup::schedule_asset_deletion( $asset_id );
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
