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

	/** Transient prefix for cached "ready" upload statuses. A ready status is final. */
	private const READY_CACHE_PREFIX = 'video_comments_ready_';

	/** Lifetime of a cached ready status. */
	private const READY_CACHE_TTL = 600; // 10 minutes.

	/** Mux IDs are alphanumeric plus hyphens. */
	private const ID_PATTERN = '/^[A-Za-z0-9\-]+$/';

	/**
	 * Per-request memo of Mux status lookups, upload_id => status array or WP_Error.
	 * A key here only means a lookup happened, whatever its result.
	 *
	 * @var array<string,array<string,mixed>|WP_Error>
	 */
	private static array $resolved_uploads = [];

	/**
	 * Uploads that passed every video-only check in this request, upload_id => status array.
	 * Only this array authorises the empty-body and duplicate-check bypasses.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $authorized_uploads = [];

	/**
	 * Forget per-request state. PHPUnit runs every test in one process, so tests
	 * call this between runs; production requests never need to.
	 *
	 * @internal
	 */
	public static function reset_request_state(): void {
		self::$resolved_uploads   = [];
		self::$authorized_uploads = [];
	}

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
		// Save meta after comment is inserted. Priority 5 so the video is attached
		// before core's wp_new_comment_notify_moderator() and
		// wp_new_comment_notify_postauthor() (both comment_post at priority 10)
		// build their emails and can mention it.
		add_action( 'comment_post', [ $this, 'save_comment_video_meta' ], 5, 3 );

		// Carry the upload fields through WP comment data sanitization.
		add_filter( 'preprocess_comment', [ $this, 'capture_upload_fields_before_sanitize' ] );

		// Video-only comments: the empty-body and duplicate-check overrides are
		// registered from pre_comment_on_post, which only the classic form handler
		// wp_handle_comment_submission() fires. REST and XML-RPC comment creation
		// apply allow_empty_comment too but never fire comment_post, so a video
		// could not be attached there; scoping the hooks keeps them rejected.
		add_action( 'pre_comment_on_post', [ $this, 'register_form_submission_hooks' ] );

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

		// Store on the data array under private keys so they survive into the post hook.
		// Nonce verified in save_comment_video_meta.
		$comment_data['_vc_upload_id']    = self::posted_field( self::FORM_FIELD_UPLOAD );
		$comment_data['_vc_upload_token'] = self::posted_field( self::FORM_FIELD_TOKEN );

		return $comment_data;
	}

	/**
	 * Read one of the plugin's hidden form fields from the request body.
	 * Anything that is not a string (an array-valued field) reads as empty.
	 *
	 * @param string $name Field name.
	 */
	private static function posted_field( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the form nonce before acting on the value.
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
	}

	/** Whether the request carries a valid comment-form nonce for this plugin. */
	private static function form_nonce_is_valid(): bool {
		$nonce = self::posted_field( self::NONCE_FIELD );
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, self::NONCE_ACTION );
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
		if ( ! self::form_nonce_is_valid() ) {
			// No nonce or invalid — silently skip; the comment itself is still saved.
			return;
		}

		$upload_id = sanitize_text_field( (string) ( $commentdata['_vc_upload_id'] ?? '' ) );
		$token     = sanitize_text_field( (string) ( $commentdata['_vc_upload_token'] ?? '' ) );

		if ( '' === $upload_id || '' === $token ) {
			return;
		}

		// A video-only submission already proved ownership and readiness in
		// allow_video_only_comment() during this request; reuse that result so
		// the attach cannot diverge from the decision that let the comment in.
		$authorized = isset( self::$authorized_uploads[ $upload_id ] );

		// Only the caller who created the upload holds its token.
		if ( ! $authorized && ! Video_Comments_REST::verify_upload_token( $upload_id, $token ) ) {
			return;
		}

		$status = $authorized ? self::$authorized_uploads[ $upload_id ] : $this->resolve_upload_status( $upload_id );

		// Nothing to attach until Mux has produced a playable asset.
		if ( ! self::is_ready_status( $status ) ) {
			return;
		}

		$playback_id = (string) $status['playback_id'];
		$asset_id    = (string) $status['asset_id'];

		// One asset belongs to one comment. Two requests racing on the same token
		// could both pass verification before either retires it; the second one
		// must not attach the same video (deleting either comment would then
		// delete the shared asset).
		if ( Video_Comments_Cleanup::asset_in_use( $asset_id ) ) {
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
	// Video-only comments (empty body, video attached)
	// -------------------------------------------------------------------------

	/**
	 * Hook the video-only overrides for the classic comment-form submission only.
	 *
	 * Runs on pre_comment_on_post, which wp_handle_comment_submission() fires after
	 * trimming the comment text and before applying allow_empty_comment.
	 *
	 * @param int $comment_post_id Post being commented on (unused).
	 */
	public function register_form_submission_hooks( int $comment_post_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by the pre_comment_on_post hook.
		add_filter( 'allow_empty_comment', [ $this, 'allow_video_only_comment' ], 10, 2 );
		add_filter( 'duplicate_comment_id', [ $this, 'skip_duplicate_check_for_video_only' ], 10, 2 );
	}

	/**
	 * Let a comment with no text through when it carries a finished video upload
	 * that the submitter owns.
	 *
	 * Order matters: every cheap check (settings, nonce, token) runs before the
	 * developer filter, and the filter runs before the Mux lookup, so a vetoing
	 * site and a replayed or forged submission never cost a network request.
	 *
	 * @param bool                $allow       Whether core already allows the empty comment.
	 * @param array<string,mixed> $commentdata Core's comment data (eight keys at this point).
	 * @return bool
	 */
	public function allow_video_only_comment( $allow, array $commentdata ): bool {
		if ( $allow ) {
			return true;
		}

		// Core applies this filter on every submission, not only empty ones.
		$content = $commentdata['comment_content'] ?? '';
		if ( ! is_string( $content ) || '' !== trim( $content ) ) {
			return false;
		}

		if ( ! Video_Comments_Settings::is_enabled() ) {
			return false;
		}

		if ( ! is_user_logged_in() && ! Video_Comments_Settings::allow_guests() ) {
			return false;
		}

		if ( ! self::form_nonce_is_valid() ) {
			return false;
		}

		$upload_id = self::posted_field( self::FORM_FIELD_UPLOAD );
		$token     = self::posted_field( self::FORM_FIELD_TOKEN );

		if ( '' === $upload_id || '' === $token ) {
			return false;
		}

		if ( ! Video_Comments_REST::verify_upload_token( $upload_id, $token ) ) {
			return false;
		}

		if ( ! Video_Comments_Settings::has_mux_credentials() ) {
			return false;
		}

		/**
		 * Filter whether a comment may consist of a video alone, with no text.
		 *
		 * Applied on submission before the upload is checked with Mux, and again
		 * when the comment form renders (with an empty $commentdata) to decide
		 * whether the browser may relax the comment field's required attribute.
		 *
		 * @since 1.2.0
		 *
		 * @param bool                $allow       Default true.
		 * @param array<string,mixed> $commentdata Core's comment data on submission; empty at render time.
		 */
		if ( ! apply_filters( 'video_comments_allow_video_only', true, $commentdata ) ) {
			return false;
		}

		$status = $this->resolve_upload_status( $upload_id );
		if ( ! self::is_ready_status( $status ) ) {
			return false;
		}

		self::$authorized_uploads[ $upload_id ] = $status;

		return true;
	}

	/**
	 * Skip core's duplicate check for an empty-body comment whose upload was
	 * authorised in this request. Two empty bodies by the same author on the same
	 * post always match, so without this a second video comment fails with 409.
	 * Comments with text keep the normal protection.
	 *
	 * @param int                 $dupe_id     Comment ID core identified as a duplicate.
	 * @param array<string,mixed> $commentdata Comment data (includes the keys added by preprocess_comment).
	 * @return int 0 to proceed, or the duplicate ID to reject.
	 */
	public function skip_duplicate_check_for_video_only( $dupe_id, array $commentdata ) {
		$content   = $commentdata['comment_content'] ?? '';
		$upload_id = (string) ( $commentdata['_vc_upload_id'] ?? '' );

		if ( is_string( $content ) && '' === trim( $content ) && '' !== $upload_id && isset( self::$authorized_uploads[ $upload_id ] ) ) {
			return 0;
		}

		return $dupe_id;
	}

	/**
	 * Resolve an upload's status with Mux once per request, serving a ready
	 * status from a short-lived transient when it was resolved recently.
	 *
	 * @param string $upload_id Mux upload ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_upload_status( string $upload_id ) {
		if ( isset( self::$resolved_uploads[ $upload_id ] ) ) {
			return self::$resolved_uploads[ $upload_id ];
		}

		$cache_key = self::READY_CACHE_PREFIX . md5( $upload_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && self::is_ready_status( $cached ) ) {
			self::$resolved_uploads[ $upload_id ] = $cached;
			return $cached;
		}

		if ( ! Video_Comments_Settings::has_mux_credentials() ) {
			$status = new WP_Error( 'vc_no_credentials', __( 'Video uploads are not available at this time.', 'video-comments' ) );
		} else {
			$provider = new Video_Comments_Provider_Mux(
				Video_Comments_Settings::get_mux_token_id(),
				Video_Comments_Settings::get_mux_token_secret()
			);
			$status   = $provider->get_upload_status( $upload_id );
		}

		self::$resolved_uploads[ $upload_id ] = $status;

		if ( self::is_ready_status( $status ) ) {
			set_transient( $cache_key, $status, self::READY_CACHE_TTL );
		}

		return $status;
	}

	/**
	 * Whether a resolved status describes a playable asset with well-formed IDs.
	 *
	 * @param array<string,mixed>|WP_Error|mixed $status Result of resolve_upload_status().
	 */
	private static function is_ready_status( $status ): bool {
		if ( ! is_array( $status ) || 'ready' !== ( $status['status'] ?? '' ) ) {
			return false;
		}

		$playback_id = $status['playback_id'] ?? '';
		$asset_id    = $status['asset_id'] ?? '';

		return is_string( $playback_id ) && is_string( $asset_id )
			&& 1 === preg_match( self::ID_PATTERN, $playback_id )
			&& 1 === preg_match( self::ID_PATTERN, $asset_id );
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
