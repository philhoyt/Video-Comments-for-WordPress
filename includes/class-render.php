<?php
/**
 * Front-end rendering: uploader UI, comment form fields, and video player.
 *
 * @package Video_Comments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles all front-end output for the plugin.
 */
class Video_Comments_Render {

	/** @var Video_Comments_Render|null */
	private static ?Video_Comments_Render $instance = null;

	/**
	 * CDN URL for the official Mux player element.
	 * Filterable via 'video_comments_mux_player_src'.
	 */
	private const MUX_PLAYER_CDN = 'https://cdn.jsdelivr.net/npm/@mux/mux-player';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		if ( ! Video_Comments_Settings::is_enabled() ) {
			return;
		}

		// Enqueue assets on singular posts/pages that have comments open.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Inject uploader UI into the comment form.
		add_action( 'comment_form_after_fields', [ $this, 'render_uploader' ] ); // guests.
		add_action( 'comment_form_logged_in_after', [ $this, 'render_uploader' ] ); // logged-in.

		// Render video player below comment text.
		add_filter( 'comment_text', [ $this, 'append_video_player' ], 20, 2 );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets(): void {
		if ( ! is_singular() || ! comments_open() ) {
			return;
		}

		// Plugin stylesheet.
		wp_enqueue_style(
			'video-comments',
			VIDEO_COMMENTS_PLUGIN_URL . 'assets/video-comments.css',
			[],
			VIDEO_COMMENTS_VERSION
		);

		// Plugin JS.
		wp_enqueue_script(
			'video-comments',
			VIDEO_COMMENTS_PLUGIN_URL . 'assets/video-comments.js',
			[],
			VIDEO_COMMENTS_VERSION,
			true // Load in footer.
		);

		// Localise settings for JS.
		wp_localize_script(
			'video-comments',
			'vcSettings',
			[
				'restUrl'         => esc_url_raw( rest_url( Video_Comments_REST::NAMESPACE ) ),
				'nonce'           => wp_create_nonce( 'vc_upload_nonce' ),
				'restNonce'       => wp_create_nonce( 'wp_rest' ),
				'maxSizeMb'       => Video_Comments_Settings::max_size_mb(),
				'hasCredentials'  => Video_Comments_Settings::has_mux_credentials(),
				'allowGuests'     => Video_Comments_Settings::allow_guests(),
				'isLoggedIn'      => is_user_logged_in(),
				/* translators: %s: max size in MB */
				'i18n'            => [
					'selectVideo'     => __( 'Select video', 'video-comments' ),
					'uploading'       => __( 'Uploading…', 'video-comments' ),
					'uploadComplete'  => __( 'Upload complete', 'video-comments' ),
					'uploadError'     => __( 'Upload failed. Please try again.', 'video-comments' ),
					'processing'      => __( 'Processing video…', 'video-comments' ),
					'ready'           => __( 'Video ready', 'video-comments' ),
					'clearVideo'      => __( 'Remove video', 'video-comments' ),
					'noCredentials'   => __( 'Video uploads are not available at this time.', 'video-comments' ),
					'guestsDisabled'  => __( 'Please log in to attach a video.', 'video-comments' ),
					'fileTooLarge'    => sprintf(
						/* translators: %s: max size in MB */
						__( 'File is too large. Maximum size is %s MB.', 'video-comments' ),
						Video_Comments_Settings::max_size_mb()
					),
					'invalidType'     => __( 'Please select a video file.', 'video-comments' ),
				],
			]
		);

		// Mux player (only needed for the display side, but enqueue here for efficiency).
		$this->enqueue_mux_player();
	}

	/**
	 * Enqueue the Mux player custom element.
	 * Filterable and can be disabled entirely by returning false from the filter.
	 */
	private function enqueue_mux_player(): void {
		/**
		 * Filter the Mux player script URL.
		 * Return false to skip loading (e.g. if the theme already loads it).
		 *
		 * @param string $url CDN URL.
		 */
		$src = apply_filters( 'video_comments_mux_player_src', self::MUX_PLAYER_CDN );

		if ( false === $src || '' === $src ) {
			return;
		}

		wp_enqueue_script(
			'mux-player',
			esc_url( $src ),
			[],
			null, // CDN controls version.
			true
		);
	}

	// -------------------------------------------------------------------------
	// Comment form injection
	// -------------------------------------------------------------------------

	/**
	 * Render the video uploader section inside the comment form.
	 */
	public function render_uploader(): void {
		$has_creds   = Video_Comments_Settings::has_mux_credentials();
		$allow_guest = Video_Comments_Settings::allow_guests();
		$is_guest    = ! is_user_logged_in();

		// Render nonce + hidden field always (they're inert without a value).
		// Actual uploader state is managed by JS.
		?>
		<div id="vc-uploader" class="vc-uploader" style="margin-top:1em;">
			<p class="vc-label">
				<strong><?php esc_html_e( 'Video (optional)', 'video-comments' ); ?></strong>
			</p>

			<?php if ( ! $has_creds ) : ?>
				<p class="vc-status vc-status--notice">
					<?php esc_html_e( 'Video uploads are not available at this time.', 'video-comments' ); ?>
				</p>
			<?php elseif ( $is_guest && ! $allow_guest ) : ?>
				<p class="vc-status vc-status--notice">
					<?php esc_html_e( 'Please log in to attach a video to your comment.', 'video-comments' ); ?>
				</p>
			<?php else : ?>
				<div class="vc-uploader__controls">
					<input
						type="file"
						id="vc-file-input"
						class="vc-file-input"
						accept="video/*"
						aria-label="<?php esc_attr_e( 'Select a video file', 'video-comments' ); ?>"
					/>
					<button
						type="button"
						id="vc-upload-btn"
						class="vc-btn vc-btn--upload wp-element-button"
						disabled
					>
						<?php esc_html_e( 'Upload Video', 'video-comments' ); ?>
					</button>
					<button
						type="button"
						id="vc-clear-btn"
						class="vc-btn vc-btn--clear"
						style="display:none"
						aria-label="<?php esc_attr_e( 'Remove video', 'video-comments' ); ?>"
					>
						<?php esc_html_e( 'Remove', 'video-comments' ); ?>
					</button>
				</div>

				<div id="vc-progress-wrap" class="vc-progress-wrap" style="display:none" aria-hidden="true">
					<progress id="vc-progress" class="vc-progress" max="100" value="0"></progress>
					<span id="vc-progress-pct" class="vc-progress-pct">0%</span>
				</div>

				<p id="vc-status" class="vc-status" aria-live="polite"></p>
			<?php endif; ?>

			<?php
			// Hidden field that carries the playback_id with the comment form POST.
			echo Video_Comments_Comment_Meta::nonce_field_html();
			?>
			<input
				type="hidden"
				id="vc-playback-id"
				name="<?php echo esc_attr( Video_Comments_Comment_Meta::FORM_FIELD ); ?>"
				value=""
			/>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Comment display
	// -------------------------------------------------------------------------

	/**
	 * Prepend a Mux player to the comment text if the comment has a video.
	 *
	 * @param string          $text    Comment text.
	 * @param WP_Comment|null $comment Comment object (null in some edge-case callers).
	 * @return string
	 */
	public function append_video_player( string $text, ?WP_Comment $comment = null ): string {
		if ( ! $comment instanceof WP_Comment ) {
			return $text;
		}

		$playback_id = Video_Comments_Comment_Meta::get_playback_id( (int) $comment->comment_ID );

		if ( empty( $playback_id ) ) {
			return $text;
		}

		$player_html = $this->build_player_html( $playback_id );

		return $player_html . $text;
	}

	/**
	 * Build the <mux-player> HTML.
	 *
	 * @param string $playback_id Mux playback ID.
	 * @return string HTML markup.
	 */
	private function build_player_html( string $playback_id ): string {
		$playback_id = esc_attr( sanitize_text_field( $playback_id ) );

		/**
		 * Filter extra attributes added to <mux-player>.
		 *
		 * @param array<string,string> $attrs Key => value attribute pairs.
		 * @param string               $playback_id Mux playback ID.
		 */
		$extra_attrs = apply_filters(
			'video_comments_player_attrs',
			[
				'controls'    => '',
				'playsinline' => '',
			],
			$playback_id
		);

		$attr_string = '';
		foreach ( $extra_attrs as $key => $val ) {
			$key          = esc_attr( sanitize_key( $key ) );
			$attr_string .= '' === $val ? ' ' . $key : ' ' . $key . '="' . esc_attr( $val ) . '"';
		}

		ob_start();
		?>
		<div class="video-comment">
			<mux-player
				playback-id="<?php echo $playback_id; // Already escaped above. ?>"
				<?php echo $attr_string; // Pre-escaped. ?>
			></mux-player>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
