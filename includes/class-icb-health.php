<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ICB_Health {

	const TABLE = 'icb_events';
	const TABLE_VERSION_OPT = 'icb_events_table_version';
	const TABLE_VERSION = '1';

	public function __construct() {
		add_action( 'wp_ajax_icb_log_event', [ $this, 'ajax_log_event' ] );
		add_action( 'wp_ajax_nopriv_icb_log_event', [ $this, 'ajax_log_event' ] );
		add_action( 'wp_ajax_icb_health_stats', [ $this, 'ajax_stats' ] );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ts INT(11) UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL,
			marketing TINYINT(1) NOT NULL DEFAULT 0,
			statistics TINYINT(1) NOT NULL DEFAULT 0,
			preferences TINYINT(1) NOT NULL DEFAULT 0,
			time_to_decision INT(11) UNSIGNED DEFAULT NULL,
			url VARCHAR(255) DEFAULT '',
			user_hash VARCHAR(32) DEFAULT '',
			PRIMARY KEY (id),
			KEY ts (ts),
			KEY type (type)
		) $charset;";
		dbDelta( $sql );
		update_option( self::TABLE_VERSION_OPT, self::TABLE_VERSION );
	}

	public static function maybe_install_table() {
		if ( get_option( self::TABLE_VERSION_OPT ) !== self::TABLE_VERSION ) {
			self::install_table();
		}
	}

	public function ajax_log_event() {
		$s = ICB_Plugin::get_settings();
		if ( empty( $s['health_logging'] ) ) {
			wp_send_json_success();
		}
		// Validate payload BEFORE consuming the rate-limit window, so a malformed
		// request can't block legitimate logging for the next 5 minutes.
		$raw = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		if ( ! $raw || strlen( $raw ) > 5000 ) {
			wp_send_json_error( null, 400 );
		}
		$d = json_decode( $raw, true );
		if ( ! is_array( $d ) ) {
			wp_send_json_error( null, 400 );
		}
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$hash = substr( md5( $ip . '|' . $ua . '|' . wp_salt() ), 0, 32 );
		// Rate-limit: 1 insert per visitor per 5 minutes
		$rate_key = 'icb_rate_' . $hash;
		if ( get_transient( $rate_key ) ) {
			wp_send_json_success();
		}
		set_transient( $rate_key, 1, 5 * MINUTE_IN_SECONDS );
		global $wpdb;
		$wpdb->insert( self::table_name(), [
			'ts'               => time(),
			'type'             => substr( sanitize_key( $d['type'] ?? 'unknown' ), 0, 20 ),
			'marketing'        => ! empty( $d['marketing'] ) ? 1 : 0,
			'statistics'       => ! empty( $d['statistics'] ) ? 1 : 0,
			'preferences'      => ! empty( $d['preferences'] ) ? 1 : 0,
			'time_to_decision' => isset( $d['time_to_decision'] ) ? max( 0, min( 600000, (int) $d['time_to_decision'] ) ) : null,
			'url'              => substr( esc_url_raw( $d['url'] ?? '' ), 0, 255 ),
			'user_hash'        => $hash,
		], [ '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ] );
		wp_send_json_success();
	}

	public function ajax_stats() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'icb_status', 'nonce' );
		wp_send_json_success( self::stats() );
	}

	public static function stats() {
		global $wpdb;
		$table = self::table_name();
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
			return [ 'enabled' => false ];
		}
		$now = time();
		$d7 = $now - 7 * DAY_IN_SECONDS;
		$d30 = $now - 30 * DAY_IN_SECONDS;

		// Use the `type` column as the single source of truth (computed from the actual
		// gtag consent update signals in the frontend). This keeps "Aceptaron todo",
		// "Rechazaron todo" and "Parcial" internally consistent.
		$total_7d  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE ts >= %d", $d7 ) );
		$accept_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE ts >= %d AND type = 'accept_all'", $d7 ) );
		$deny_7d   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE ts >= %d AND type = 'deny_all'", $d7 ) );
		$partial_7d = max( 0, $total_7d - $accept_7d - $deny_7d );

		// Marketing acceptance — the metric that matters for Google Ads / Meta Ads.
		$marketing_7d   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE ts >= %d AND marketing = 1", $d7 ) );
		$marketing_rate = $total_7d > 0 ? round( ( $marketing_7d / $total_7d ) * 100, 1 ) : null;

		$avg_ttd = (int) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(time_to_decision) FROM $table WHERE ts >= %d AND time_to_decision IS NOT NULL", $d7 ) );

		$top_reject = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, COUNT(*) as n FROM $table WHERE ts >= %d AND type = 'deny_all' GROUP BY url ORDER BY n DESC LIMIT 5",
			$d7
		), ARRAY_A );

		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(FROM_UNIXTIME(ts)) as d,
				SUM(CASE WHEN type = 'accept_all' THEN 1 ELSE 0 END) as accepts,
				SUM(CASE WHEN type = 'deny_all' THEN 1 ELSE 0 END) as denies,
				COUNT(*) as total
				FROM $table WHERE ts >= %d GROUP BY d ORDER BY d ASC",
			$d7
		), ARRAY_A );

		$rate = $total_7d > 0 ? round( ( $accept_7d / $total_7d ) * 100, 1 ) : null;

		return [
			'enabled'         => true,
			'total_7d'        => $total_7d,
			'accept_7d'       => $accept_7d,
			'deny_7d'         => $deny_7d,
			'partial_7d'      => $partial_7d,
			'accept_rate'     => $rate,
			'marketing_7d'    => $marketing_7d,
			'marketing_rate'  => $marketing_rate,
			'avg_ttd_ms'      => $avg_ttd,
			'top_reject'      => $top_reject ?: [],
			'daily'           => $daily ?: [],
			'total_30d'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE ts >= %d", $d30 ) ),
		];
	}

	public static function purge_old( $days = 90 ) {
		global $wpdb;
		$cutoff = time() - $days * DAY_IN_SECONDS;
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . self::table_name() . " WHERE ts < %d", $cutoff ) );
	}
}
