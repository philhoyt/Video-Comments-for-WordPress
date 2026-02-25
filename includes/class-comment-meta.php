<?php
/**
 * Comment meta saving and retrieval.
 *
 * @package Video_Comments
 */

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

	/** Hidden input name posted with the comment form — playback ID. */
	public const FORM_FIELD = 'video_comments_mux_playback_id';

	/** Hidden input name posted with the comment form — asset ID. */
	public const FORM_FIELD_ASSET = 'video_comments_mux_asset_id_field';

	/** Nonce action for playback_id validation on comment submit. */
	private const NONCE_ACTION = 'vc_comment_submit';

	/** Nonce field name. */
	public const NONCE_FIELD = 'vc_comment_nonce';

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

		// Allow the playback_id field through WP comment data sanitization.
		add_filter( 'preprocess_comment', [ $this, 'capture_playback_id_before_sanitize' ] );

		// Admin comment edit page.
		add_action( 'add_meta_boxes_comment', [ $this, 'add_comment_meta_box' ] );
		add_action( 'edit_comment', [ $this, 'save_admin_comment_meta' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Delete the Mux asset when a comment is permanently deleted.
		add_action( 'delete_comment', [ $this, 'delete_mux_asset_for_comment' ], 10, 2 );
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

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in save_comment_video_meta.
		$playback_id = isset( $_POST[ self::FORM_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::FORM_FIELD ] ) )
			: '';

		$asset_id = isset( $_POST[ self::FORM_FIELD_ASSET ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::FORM_FIELD_ASSET ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Store on the data array under private keys so they survive into the post hook.
		$comment_data['_vc_playback_id'] = $playback_id;
		$comment_data['_vc_asset_id']    = $asset_id;

		return $comment_data;
	}

	/**
	 * After comment is inserted, persist video meta if a valid playback_id was submitted.
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

		// Retrieve playback_id (set in capture_playback_id_before_sanitize).
		$playback_id = sanitize_text_field( $commentdata['_vc_playback_id'] ?? '' );

		if ( empty( $playback_id ) ) {
			return;
		}

		// Basic format sanity: Mux playback IDs are alphanumeric + hyphens.
		if ( ! preg_match( '/^[A-Za-z0-9\-]+$/', $playback_id ) ) {
			return;
		}

		$asset_id = sanitize_text_field( $commentdata['_vc_asset_id'] ?? '' );

		add_comment_meta( $comment_id, self::META_PROVIDER, 'mux', true );
		add_comment_meta( $comment_id, self::META_PLAYBACK_ID, $playback_id, true );
		if ( '' !== $asset_id && preg_match( '/^[A-Za-z0-9\-]+$/', $asset_id ) ) {
			add_comment_meta( $comment_id, self::META_ASSET_ID, $asset_id, true );
		}
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
		$src = apply_filters( 'video_comments_mux_player_src', 'https://cdn.jsdelivr.net/npm/@mux/mux-player' );
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

		wp_nonce_field( 'vc_edit_comment_' . $comment->comment_ID, 'vc_edit_nonce' );
		?>
		<table class="form-table editcomment" style="margin-top:0;">
			<tbody>
				<tr>
					<td class="first"><label for="vc-admin-playback-id"><?php esc_html_e( 'Mux Playback ID', 'video-comments' ); ?></label></td>
					<td>
						<input
							type="text"
							id="vc-admin-playback-id"
							name="vc_playback_id"
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
		if ( ! isset( $_POST['vc_edit_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vc_edit_nonce'] ) ), 'vc_edit_comment_' . $comment_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_comment', $comment_id ) ) {
			return;
		}

		$playback_id = isset( $_POST['vc_playback_id'] )
			? sanitize_text_field( wp_unslash( $_POST['vc_playback_id'] ) )
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
	 * Delete the Mux asset associated with a comment, if any.
	 * Called when a comment is permanently deleted from WordPress, or when an
	 * admin clears the video from the comment edit screen.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function delete_mux_asset_for_comment( int $comment_id ): void {
		if ( ! Video_Comments_Settings::has_mux_credentials() ) {
			return;
		}

		$asset_id = get_comment_meta( $comment_id, self::META_ASSET_ID, true );
		if ( empty( $asset_id ) || ! is_string( $asset_id ) ) {
			return;
		}

		$provider = new Video_Comments_Provider_Mux(
			Video_Comments_Settings::get_mux_token_id(),
			Video_Comments_Settings::get_mux_token_secret()
		);

		$provider->delete_asset( $asset_id );
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
