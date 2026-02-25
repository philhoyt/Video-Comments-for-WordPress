<?php
/**
 * Admin settings page and option helpers.
 *
 * @package Video_Comments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Settings → Video Comments admin page.
 *
 * @package Video_Comments
 */
class Video_Comments_Settings {

	/**
	 * Singleton instance.
	 *
	 * @var Video_Comments_Settings|null
	 */
	private static ?Video_Comments_Settings $instance = null;

	/** Options key. */
	public const OPTION_KEY = 'video_comments_settings';

	/** Default option values. */
	private const DEFAULTS = [
		'enabled'          => 1,
		'allow_guests'     => 1,
		'max_size_mb'      => 50,
		'mux_token_id'     => '',
		'mux_token_secret' => '',
	];

	/** Singleton accessor. */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private constructor — use get_instance(). */
	private function __construct() {}

	/** Register admin hooks. */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	// -------------------------------------------------------------------------
	// Public option accessors
	// -------------------------------------------------------------------------

	/**
	 * Return all options merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_options(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return array_merge( self::DEFAULTS, $saved );
	}

	/**
	 * Retrieve the Mux Token ID.
	 * Constant VIDEO_COMMENTS_MUX_TOKEN_ID in wp-config.php overrides the DB value.
	 */
	public static function get_mux_token_id(): string {
		if ( defined( 'VIDEO_COMMENTS_MUX_TOKEN_ID' ) ) {
			return (string) VIDEO_COMMENTS_MUX_TOKEN_ID;
		}
		return (string) ( self::get_options()['mux_token_id'] ?? '' );
	}

	/**
	 * Retrieve the Mux Token Secret.
	 * Constant VIDEO_COMMENTS_MUX_TOKEN_SECRET in wp-config.php overrides the DB value.
	 */
	public static function get_mux_token_secret(): string {
		if ( defined( 'VIDEO_COMMENTS_MUX_TOKEN_SECRET' ) ) {
			return (string) VIDEO_COMMENTS_MUX_TOKEN_SECRET;
		}
		return (string) ( self::get_options()['mux_token_secret'] ?? '' );
	}

	/** Whether the video-comments feature is globally enabled. */
	public static function is_enabled(): bool {
		return (bool) ( self::get_options()['enabled'] ?? true );
	}

	/** Whether guests (non-logged-in users) may upload videos. */
	public static function allow_guests(): bool {
		return (bool) ( self::get_options()['allow_guests'] ?? true );
	}

	/** Maximum upload size in bytes. */
	public static function max_size_bytes(): int {
		$mb = (int) ( self::get_options()['max_size_mb'] ?? 50 );
		return $mb * 1024 * 1024;
	}

	/** Maximum upload size in MB (for display/JS). */
	public static function max_size_mb(): int {
		return (int) ( self::get_options()['max_size_mb'] ?? 50 );
	}

	/** Whether Mux credentials are configured. */
	public static function has_mux_credentials(): bool {
		return '' !== self::get_mux_token_id() && '' !== self::get_mux_token_secret();
	}

	// -------------------------------------------------------------------------
	// Admin UI
	// -------------------------------------------------------------------------

	/**
	 * Register the plugin settings page under Settings menu.
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Video Comments', 'video-comments' ),
			__( 'Video Comments', 'video-comments' ),
			'manage_options',
			'video-comments',
			[ $this, 'render_settings_page' ]
		);
	}

	/** Register all settings, sections, and fields. */
	public function register_settings(): void {
		register_setting(
			'video_comments_group',
			self::OPTION_KEY,
			[ $this, 'sanitize_options' ]
		);

		add_settings_section(
			'vc_general',
			__( 'General', 'video-comments' ),
			'__return_empty_string',
			'video-comments'
		);

		add_settings_section(
			'vc_mux',
			__( 'Mux API Credentials', 'video-comments' ),
			[ $this, 'render_mux_section_description' ],
			'video-comments'
		);

		// General fields.
		add_settings_field(
			'vc_enabled',
			__( 'Enable Video Comments', 'video-comments' ),
			[ $this, 'render_checkbox' ],
			'video-comments',
			'vc_general',
			[
				'name'  => 'enabled',
				'label' => __( 'Allow commenters to attach a video', 'video-comments' ),
			]
		);

		add_settings_field(
			'vc_allow_guests',
			__( 'Allow Guests', 'video-comments' ),
			[ $this, 'render_checkbox' ],
			'video-comments',
			'vc_general',
			[
				'name'  => 'allow_guests',
				'label' => __( 'Let non-logged-in users upload videos', 'video-comments' ),
			]
		);

		add_settings_field(
			'vc_max_size_mb',
			__( 'Max Upload Size (MB)', 'video-comments' ),
			[ $this, 'render_number' ],
			'video-comments',
			'vc_general',
			[
				'name' => 'max_size_mb',
				'min'  => 1,
				'max'  => 2048,
			]
		);

		// Mux credential fields.
		add_settings_field(
			'vc_mux_token_id',
			__( 'Token ID', 'video-comments' ),
			[ $this, 'render_text' ],
			'video-comments',
			'vc_mux',
			[
				'name'     => 'mux_token_id',
				'constant' => 'VIDEO_COMMENTS_MUX_TOKEN_ID',
				'type'     => 'text',
			]
		);

		add_settings_field(
			'vc_mux_token_secret',
			__( 'Token Secret', 'video-comments' ),
			[ $this, 'render_text' ],
			'video-comments',
			'vc_mux',
			[
				'name'     => 'mux_token_secret',
				'constant' => 'VIDEO_COMMENTS_MUX_TOKEN_SECRET',
				'type'     => 'password',
			]
		);
	}

	/**
	 * Sanitize raw option array submitted from the form.
	 *
	 * @param mixed $input Raw POST data.
	 * @return array<string,mixed>
	 */
	public function sanitize_options( $input ): array {
		if ( ! is_array( $input ) ) {
			return self::DEFAULTS;
		}

		return [
			'enabled'          => ! empty( $input['enabled'] ) ? 1 : 0,
			'allow_guests'     => ! empty( $input['allow_guests'] ) ? 1 : 0,
			'max_size_mb'      => max( 1, min( 2048, (int) ( $input['max_size_mb'] ?? 50 ) ) ),
			'mux_token_id'     => sanitize_text_field( $input['mux_token_id'] ?? '' ),
			'mux_token_secret' => sanitize_text_field( $input['mux_token_secret'] ?? '' ),
		];
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the description shown above the Mux credentials section.
	 */
	public function render_mux_section_description(): void {
		echo '<p>' . esc_html__( 'Enter your Mux API credentials below, or define VIDEO_COMMENTS_MUX_TOKEN_ID and VIDEO_COMMENTS_MUX_TOKEN_SECRET in wp-config.php to keep secrets out of the database.', 'video-comments' ) . '</p>';
	}

	/**
	 * Render a checkbox settings field.
	 *
	 * @param array<string,mixed> $args Field arguments (name, label).
	 */
	public function render_checkbox( array $args ): void {
		$opts  = self::get_options();
		$name  = esc_attr( $args['name'] );
		$label = esc_html( $args['label'] ?? '' );
		$value = ! empty( $opts[ $args['name'] ] ) ? 1 : 0;
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( self::OPTION_KEY ),
			$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_attr() above.
			checked( 1, $value, false ),
			$label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_html() above.
		);
	}

	/**
	 * Render a number settings field.
	 *
	 * @param array<string,mixed> $args Field arguments (name, min, max).
	 */
	public function render_number( array $args ): void {
		$opts = self::get_options();
		$name = esc_attr( $args['name'] );
		printf(
			'<input type="number" name="%1$s[%2$s]" value="%3$s" min="%4$s" max="%5$s" class="small-text" />',
			esc_attr( self::OPTION_KEY ),
			$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_attr() above.
			esc_attr( (string) ( $opts[ $args['name'] ] ?? '' ) ),
			esc_attr( (string) ( $args['min'] ?? 1 ) ),
			esc_attr( (string) ( $args['max'] ?? 9999 ) )
		);
	}

	/**
	 * Render a text or password settings field.
	 *
	 * @param array<string,mixed> $args Field arguments (name, type, constant).
	 */
	public function render_text( array $args ): void {
		$opts     = self::get_options();
		$name     = esc_attr( $args['name'] );
		$type     = esc_attr( $args['type'] ?? 'text' );
		$constant = $args['constant'] ?? '';
		$locked   = $constant && defined( $constant );

		if ( $locked ) {
			echo '<code>' . esc_html__( 'Set via constant in wp-config.php', 'video-comments' ) . '</code>';
			return;
		}

		printf(
			'<input type="%1$s" name="%2$s[%3$s]" value="%4$s" class="regular-text" autocomplete="off" />',
			$type, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_attr() above.
			esc_attr( self::OPTION_KEY ),
			$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_attr() above.
			esc_attr( $opts[ $args['name'] ] ?? '' )
		);
	}

	/**
	 * Render the plugin settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Video Comments Settings', 'video-comments' ); ?></h1>

			<?php if ( ! self::has_mux_credentials() ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Mux credentials are not configured. The video upload UI will be hidden from commenters until credentials are set.', 'video-comments' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'video_comments_group' );
				do_settings_sections( 'video-comments' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
