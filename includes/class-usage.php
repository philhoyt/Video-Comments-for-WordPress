<?php
/**
 * Player-render tracking and admin usage alerts.
 *
 * Counts how many times a <mux-player> is output on the front end
 * (a reliable proxy for streaming load) and surfaces admin notices +
 * a one-time email when configurable daily / monthly thresholds are crossed.
 *
 * Mux does not expose billing data via API, so the Mux billing dashboard
 * is the authoritative source for actual costs. These counters are a
 * heads-up, not an invoice.
 *
 * @package Video_Comments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracks video player renders and alerts admins when thresholds are reached.
 */
class Video_Comments_Usage {

	/** @var Video_Comments_Usage|null */
	private static ?Video_Comments_Usage $instance = null;

	/** Prefix for wp_options counter keys. */
	private const KEY_PREFIX = 'vc_plays_';

	/** Cron hook for cleaning up old counter rows. */
	private const CLEANUP_HOOK = 'vc_cleanup_play_counters';

	/** Meta key used to record per-user notice dismissals. */
	private const DISMISS_META = 'vc_dismissed_usage_notice';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		// Record a render each time the player is output on the front end.
		add_action( 'video_comments_player_rendered', [ $this, 'record_play' ] );

		// Admin notices.
		add_action( 'admin_notices', [ $this, 'show_usage_notices' ] );

		// AJAX dismiss handler.
		add_action( 'wp_ajax_vc_dismiss_usage_notice', [ $this, 'ajax_dismiss_notice' ] );

		// Inline JS to persist notice dismissal via AJAX when WP hides the element.
		add_action( 'admin_footer', [ $this, 'output_dismiss_script' ] );

		// Weekly cron to prune old counter rows.
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', self::CLEANUP_HOOK );
		}
		add_action( self::CLEANUP_HOOK, [ $this, 'cleanup_old_counters' ] );
	}

	// -------------------------------------------------------------------------
	// Recording
	// -------------------------------------------------------------------------

	/**
	 * Increment today's and this month's render counters.
	 * Called via the 'video_comments_player_rendered' action.
	 *
	 * @param string $playback_id The Mux playback ID that was rendered.
	 */
	public function record_play( string $playback_id ): void {
		$daily_key   = $this->daily_key();
		$monthly_key = $this->monthly_key();

		$daily   = (int) get_option( $daily_key, 0 ) + 1;
		$monthly = (int) get_option( $monthly_key, 0 ) + 1;

		update_option( $daily_key, $daily, false );
		update_option( $monthly_key, $monthly, false );

		// Send a one-time email the moment a threshold is first crossed.
		$this->maybe_send_email_alert( $daily, $monthly );
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	/**
	 * Show warning notices in wp-admin when render counts exceed thresholds.
	 */
	public function show_usage_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts              = Video_Comments_Settings::get_options();
		$daily_threshold   = (int) ( $opts['play_alert_daily'] ?? 0 );
		$monthly_threshold = (int) ( $opts['play_alert_monthly'] ?? 0 );

		$daily   = $this->get_daily_count();
		$monthly = $this->get_monthly_count();

		if ( $daily_threshold > 0 && $daily >= $daily_threshold && ! $this->is_dismissed( 'daily' ) ) {
			$this->render_notice( 'daily', $daily, $daily_threshold );
		}

		if ( $monthly_threshold > 0 && $monthly >= $monthly_threshold && ! $this->is_dismissed( 'monthly' ) ) {
			$this->render_notice( 'monthly', $monthly, $monthly_threshold );
		}
	}

	/**
	 * Output a single dismissible notice.
	 *
	 * @param string $period    'daily' or 'monthly'.
	 * @param int    $count     Current render count.
	 * @param int    $threshold Configured threshold.
	 */
	private function render_notice( string $period, int $count, int $threshold ): void {
		$period_label = 'daily' === $period
			? __( 'today', 'video-comments' )
			: __( 'this month', 'video-comments' );

		$nonce       = wp_create_nonce( 'vc_dismiss_' . $period );
		$settings_url = admin_url( 'options-general.php?page=video-comments' );
		$mux_url      = 'https://dashboard.mux.com/';
		?>
		<div class="notice notice-warning is-dismissible vc-usage-notice"
			data-vc-period="<?php echo esc_attr( $period ); ?>"
			data-vc-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<strong><?php esc_html_e( 'Video Comments', 'video-comments' ); ?></strong> &mdash;
				<?php
				printf(
					/* translators: 1: render count, 2: period label (e.g. "today"), 3: threshold count */
					esc_html__( 'Video players have been loaded %1$s times %2$s, passing your alert threshold of %3$s.', 'video-comments' ),
					'<strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>',
					esc_html( $period_label ),
					'<strong>' . esc_html( number_format_i18n( $threshold ) ) . '</strong>'
				);
				?>
				<?php
				printf(
					/* translators: 1: link to Mux dashboard, 2: link to plugin settings */
					esc_html__( 'Check the %1$s for actual streaming costs, or adjust your thresholds in %2$s.', 'video-comments' ),
					'<a href="' . esc_url( $mux_url ) . '" target="_blank" rel="noopener noreferrer">'
						. esc_html__( 'Mux billing dashboard', 'video-comments' ) . '</a>',
					'<a href="' . esc_url( $settings_url ) . '">'
						. esc_html__( 'Video Comments settings', 'video-comments' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Output a small script that fires an AJAX call when an admin clicks the
	 * dismiss button on one of our usage notices. Only outputs if our notice
	 * markup is actually present on the page (checked via the CSS class).
	 */
	public function output_dismiss_script(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<script>
		( function () {
			document.querySelectorAll( '.vc-usage-notice' ).forEach( function ( el ) {
				el.addEventListener( 'click', function ( e ) {
					if ( ! e.target.classList.contains( 'notice-dismiss' ) ) return;
					var fd = new FormData();
					fd.append( 'action', 'vc_dismiss_usage_notice' );
					fd.append( 'period', el.dataset.vcPeriod );
					fd.append( 'nonce',  el.dataset.vcNonce );
					fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						method: 'POST',
						credentials: 'same-origin',
						body: fd,
					} );
				} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Handle AJAX dismissal — records the dismissal in user meta so the
	 * notice stays hidden for the rest of the current day / month.
	 */
	public function ajax_dismiss_notice(): void {
		$period = sanitize_key( wp_unslash( $_POST['period'] ?? '' ) );
		$nonce  = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );

		if ( ! in_array( $period, [ 'daily', 'monthly' ], true )
			|| ! wp_verify_nonce( $nonce, 'vc_dismiss_' . $period )
			|| ! current_user_can( 'manage_options' )
		) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		$this->set_dismissed( $period );
		wp_die();
	}

	// -------------------------------------------------------------------------
	// Email alerts
	// -------------------------------------------------------------------------

	/**
	 * Send a one-time email to the site admin when a threshold is first crossed.
	 * Uses a wp_options flag to ensure only one email per period is sent.
	 *
	 * @param int $daily   Current daily count.
	 * @param int $monthly Current monthly count.
	 */
	private function maybe_send_email_alert( int $daily, int $monthly ): void {
		$opts = Video_Comments_Settings::get_options();

		$daily_threshold = (int) ( $opts['play_alert_daily'] ?? 0 );
		if ( $daily_threshold > 0 && $daily >= $daily_threshold ) {
			$sent_key = 'vc_alert_emailed_daily_' . gmdate( 'Y-m-d' );
			if ( ! get_option( $sent_key ) ) {
				$this->send_alert_email( 'daily', $daily, $daily_threshold );
				update_option( $sent_key, 1, false );
			}
		}

		$monthly_threshold = (int) ( $opts['play_alert_monthly'] ?? 0 );
		if ( $monthly_threshold > 0 && $monthly >= $monthly_threshold ) {
			$sent_key = 'vc_alert_emailed_monthly_' . gmdate( 'Y-m' );
			if ( ! get_option( $sent_key ) ) {
				$this->send_alert_email( 'monthly', $monthly, $monthly_threshold );
				update_option( $sent_key, 1, false );
			}
		}
	}

	/**
	 * Compose and send the alert email.
	 *
	 * @param string $period    'daily' or 'monthly'.
	 * @param int    $count     Current render count.
	 * @param int    $threshold Configured threshold.
	 */
	private function send_alert_email( string $period, int $count, int $threshold ): void {
		$admin_email  = (string) get_option( 'admin_email' );
		$site_name    = (string) get_bloginfo( 'name' );
		$period_label = 'daily' === $period ? 'today' : 'this month';
		$settings_url = admin_url( 'options-general.php?page=video-comments' );

		$subject = sprintf(
			'[%s] Video Comments usage alert',
			$site_name
		);

		$message  = sprintf(
			"Video players on %s have been loaded %s times %s, which has passed your alert threshold of %s.\n\n",
			home_url(),
			number_format_i18n( $count ),
			$period_label,
			number_format_i18n( $threshold )
		);
		$message .= "Note: this count reflects how many times a video player appeared on a page, not actual video plays or exact streaming minutes.\n\n";
		$message .= "Check your Mux billing dashboard for actual costs:\nhttps://dashboard.mux.com/\n\n";
		$message .= "Adjust your alert thresholds here:\n" . $settings_url;

		wp_mail( $admin_email, $subject, $message );
	}

	// -------------------------------------------------------------------------
	// Counter accessors (public for use in the settings page)
	// -------------------------------------------------------------------------

	/** Current day's render count. */
	public function get_daily_count(): int {
		return (int) get_option( $this->daily_key(), 0 );
	}

	/** Current month's render count. */
	public function get_monthly_count(): int {
		return (int) get_option( $this->monthly_key(), 0 );
	}

	// -------------------------------------------------------------------------
	// Dismissal helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether the current admin user has dismissed a notice for this period.
	 *
	 * @param string $period 'daily' or 'monthly'.
	 */
	private function is_dismissed( string $period ): bool {
		$period_id = 'daily' === $period ? gmdate( 'Y-m-d' ) : gmdate( 'Y-m' );
		$meta      = (array) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
		return ! empty( $meta[ $period . '_' . $period_id ] );
	}

	/**
	 * Record that the current admin user has dismissed a notice for this period.
	 *
	 * @param string $period 'daily' or 'monthly'.
	 */
	private function set_dismissed( string $period ): void {
		$period_id = 'daily' === $period ? gmdate( 'Y-m-d' ) : gmdate( 'Y-m' );
		$user_id   = get_current_user_id();
		$meta      = (array) get_user_meta( $user_id, self::DISMISS_META, true );

		$meta[ $period . '_' . $period_id ] = 1;

		// Prune stale periods so meta doesn't grow unbounded.
		foreach ( array_keys( $meta ) as $key ) {
			[ $p, $date ] = explode( '_', $key, 2 ) + [ '', '' ];
			if ( 'daily' === $p && $date < gmdate( 'Y-m-d' ) ) {
				unset( $meta[ $key ] );
			} elseif ( 'monthly' === $p && $date < gmdate( 'Y-m' ) ) {
				unset( $meta[ $key ] );
			}
		}

		update_user_meta( $user_id, self::DISMISS_META, $meta );
	}

	// -------------------------------------------------------------------------
	// Maintenance
	// -------------------------------------------------------------------------

	/**
	 * Delete counter rows older than two months. Runs weekly via WP-Cron.
	 */
	public function cleanup_old_counters(): void {
		global $wpdb;

		$cutoff_daily   = self::KEY_PREFIX . 'daily_' . gmdate( 'Y-m-d', strtotime( '-2 months' ) );
		$cutoff_monthly = self::KEY_PREFIX . 'monthly_' . gmdate( 'Y-m', strtotime( '-2 months' ) );
		$cutoff_emailed = 'vc_alert_emailed_daily_' . gmdate( 'Y-m-d', strtotime( '-2 months' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE ( option_name LIKE %s AND option_name <= %s )
				   OR ( option_name LIKE %s AND option_name <= %s )
				   OR ( option_name LIKE %s AND option_name <= %s )",
				$wpdb->esc_like( self::KEY_PREFIX . 'daily_' ) . '%',
				$cutoff_daily,
				$wpdb->esc_like( self::KEY_PREFIX . 'monthly_' ) . '%',
				$cutoff_monthly,
				$wpdb->esc_like( 'vc_alert_emailed_' ) . '%',
				$cutoff_emailed
			)
		);
		// phpcs:enable
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function daily_key(): string {
		return self::KEY_PREFIX . 'daily_' . gmdate( 'Y-m-d' );
	}

	private function monthly_key(): string {
		return self::KEY_PREFIX . 'monthly_' . gmdate( 'Y-m' );
	}
}
