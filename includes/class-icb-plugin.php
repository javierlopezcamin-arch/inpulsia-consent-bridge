<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ICB_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function defaults() {
		return [
			'enabled'             => 1,
			'region'              => 'EEA',
			'wait_for_update'     => 500,
			'force_gtm_unblock'   => 0,
			'debug'               => 0,
			'category_mapping'    => self::default_mapping(),
			'meta_pixel_consent'  => 1,
			'tiktok_pixel_consent'=> 1,
			'health_logging'      => 1,
			'wc_events'           => 0,
			'wc_send_purchase'    => 1,
			'wc_send_cart_events' => 1,
			'wc_send_view_item'   => 1,
			'meta_pixel_events'       => 0,
			'enhanced_conversions'    => 0,
			'sgtm_enabled'        => 0,
			'sgtm_domain'         => '',
			'sgtm_container_id'   => '',
			'sgtm_load_gtm'       => 0,
		];
	}

	public static function default_mapping() {
		return [
			'marketing'   => [ 'ad_storage', 'ad_user_data', 'ad_personalization' ],
			'statistics'  => [ 'analytics_storage' ],
			'preferences' => [ 'functionality_storage', 'personalization_storage' ],
			'functional'  => [ 'functionality_storage' ],
		];
	}

	public static function all_signals() {
		return [ 'ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage', 'functionality_storage', 'personalization_storage', 'security_storage' ];
	}

	public static function all_categories() {
		return [ 'marketing', 'statistics', 'preferences', 'functional' ];
	}

	public static function get_settings() {
		$opts = get_option( ICB_OPTION, [] );
		$out  = wp_parse_args( is_array( $opts ) ? $opts : [], self::defaults() );
		if ( ! is_array( $out['category_mapping'] ?? null ) ) {
			$out['category_mapping'] = self::default_mapping();
		} else {
			$defaults_map = self::default_mapping();
			foreach ( self::all_categories() as $cat ) {
				if ( ! isset( $out['category_mapping'][ $cat ] ) || ! is_array( $out['category_mapping'][ $cat ] ) ) {
					$out['category_mapping'][ $cat ] = $defaults_map[ $cat ] ?? [];
				}
			}
		}
		return $out;
	}

	public static function activate() {
		if ( false === get_option( ICB_OPTION ) ) {
			add_option( ICB_OPTION, self::defaults() );
		}
		ICB_Health::install_table();
		// Purge cache on activation so a fresh install / manual update serves the new JS
		// immediately and the diagnostics "stale cache" check starts clean.
		if ( class_exists( 'ICB_Cache' ) ) {
			ICB_Cache::purge_all();
		}
	}

	public static function detect_stape() {
		$found = [];
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', [] );
		foreach ( $active as $p ) {
			$slug = strtolower( $p );
			if ( strpos( $slug, 'stape' ) !== false || strpos( $slug, 'gtm-server-side' ) !== false || ( strpos( $slug, 'conversion-tracking' ) !== false && strpos( $slug, 'stape' ) !== false ) ) {
				$found[] = $p;
			}
		}
		if ( defined( 'STAPE_VERSION' ) || defined( 'STAPE_PLUGIN_VERSION' ) ) {
			$found[] = 'stape-runtime';
		}
		if ( class_exists( 'Stape_Plugin' ) || class_exists( 'Stape\\Plugin' ) ) {
			$found[] = 'stape-class';
		}
		return array_values( array_unique( $found ) );
	}

	public static function stape_is_active() {
		return ! empty( self::detect_stape() );
	}

	/**
	 * Single source of truth for "is Complianz active?". Complianz has changed its
	 * bootstrap symbols across versions, so we check every known marker here and
	 * nowhere else.
	 */
	public static function complianz_active() {
		return defined( 'cmplz_version' )
			|| defined( 'cmplz_plugin' )
			|| function_exists( 'cmplz_init' )
			|| class_exists( 'cmplz_admin' )
			|| class_exists( 'CMPLZ_GDPR' )
			|| class_exists( 'COMPLIANZ' );
	}

	public static function sitekit_active() {
		return class_exists( 'Google\\Site_Kit\\Plugin' ) || defined( 'GOOGLESITEKIT_VERSION' );
	}

	public static function detect_conflicting_trackers() {
		$found = [];
		if ( defined( 'PYS_FREE_VERSION' ) || defined( 'PYS_VERSION' ) || class_exists( 'PYS' ) || function_exists( 'pys_function_for_pixelyoursite' ) ) {
			$found['PixelYourSite'] = 'free';
		}
		if ( defined( 'PYS_PRO_VERSION' ) || class_exists( 'PixelYourSitePro\\Plugin' ) ) {
			$found['PixelYourSite Pro'] = 'pro';
		}
		if ( defined( 'GTM4WP_VERSION' ) ) {
			$found['GTM4WP'] = 'plugin';
		}
		if ( function_exists( 'woocommerce_google_analytics_integration_init' ) || class_exists( 'WC_Google_Analytics_Integration' ) ) {
			$found['WC Google Analytics Integration'] = 'plugin';
		}
		if ( defined( 'MONSTERINSIGHTS_VERSION' ) || class_exists( 'MonsterInsights' ) ) {
			$found['MonsterInsights'] = 'plugin';
		}
		$stape = self::detect_stape();
		if ( ! empty( $stape ) ) {
			$found['Stape Conversion Tracking'] = 'plugin';
		}
		return $found;
	}

	public static function complianz_has_consent_mode() {
		$opts = get_option( 'complianz_options_settings', [] );
		if ( is_array( $opts ) ) {
			foreach ( [ 'consent_mode', 'enable_consent_mode', 'gcm', 'google_consent_mode' ] as $key ) {
				if ( ! empty( $opts[ $key ] ) ) {
					return true;
				}
			}
		}
		$integrations = get_option( 'complianz_options_integrations', [] );
		if ( is_array( $integrations ) && ! empty( $integrations['consent_mode'] ) ) {
			return true;
		}
		return false;
	}

	public function init() {
		new ICB_Admin();
		new ICB_Frontend();
		new ICB_Health();
		new ICB_Woocommerce();
		new ICB_Stape();
		new ICB_Updater();
		add_action( 'admin_notices', [ $this, 'dependency_notices' ] );
		add_action( 'admin_notices', [ $this, 'consent_mode_conflict_notice' ] );
		add_action( 'admin_notices', [ $this, 'stape_info_notice' ] );
		add_action( 'icb_weekly_purge', [ 'ICB_Health', 'purge_old' ] );
		if ( ! wp_next_scheduled( 'icb_weekly_purge' ) ) {
			wp_schedule_event( time(), 'weekly', 'icb_weekly_purge' );
		}
		// Auto-purge cache after plugin update so the new JS reaches visitors immediately.
		add_action( 'upgrader_process_complete', [ $this, 'on_plugin_upgrade' ], 10, 2 );
		ICB_Health::maybe_install_table();
	}

	public function on_plugin_upgrade( $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) {
			return;
		}
		$plugins = $hook_extra['plugins'] ?? ( isset( $hook_extra['plugin'] ) ? [ $hook_extra['plugin'] ] : [] );
		$our_basename = plugin_basename( ICB_PATH . 'inpulsia-consent-bridge.php' );
		if ( in_array( $our_basename, (array) $plugins, true ) ) {
			ICB_Cache::purge_all();
		}
	}

	public function dependency_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = [];
		if ( ! self::complianz_active() ) {
			$missing[] = 'Complianz';
		}
		if ( ! self::sitekit_active() ) {
			$missing[] = 'Google Site Kit';
		}
		if ( ! empty( $missing ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Inpulsia Consent v2:</strong> %s <em>%s</em>.</p></div>',
				esc_html__( 'Faltan dependencias requeridas:', 'inpulsia-consent-bridge' ),
				esc_html( implode( ', ', $missing ) )
			);
		}
	}

	public function stape_info_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'toplevel_page_inpulsia-consent-bridge' ) {
			return;
		}
		if ( ! self::stape_is_active() ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>Inpulsia Consent v2:</strong> %s</p></div>',
			esc_html__( 'Stape detectado. El Bridge inyecta consent default antes que Stape (wp_head prio 0); revisa la pestaña Estado en vivo para confirmar el orden de eventos.', 'inpulsia-consent-bridge' )
		);
	}

	public function consent_mode_conflict_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::get_settings();
		if ( empty( $s['enabled'] ) ) {
			return;
		}
		if ( self::complianz_has_consent_mode() ) {
			printf(
				'<div class="notice notice-error"><p><strong>Inpulsia Consent v2:</strong> %s</p></div>',
				esc_html__( 'Complianz tiene Google Consent Mode v2 activado. Hay conflicto: desactiva uno de los dos para evitar doble disparo de gtag(\'consent\',\'default\').', 'inpulsia-consent-bridge' )
			);
		}
	}
}
