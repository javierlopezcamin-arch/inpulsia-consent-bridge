<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ICB_Cache {

	const LAST_PURGE_OPTION = 'icb_last_cache_purge';

	/**
	 * Returns an array of detected active cache systems: ['key' => 'Name'].
	 */
	public static function detect() {
		$found = [];

		if ( defined( 'WP_ROCKET_VERSION' ) || function_exists( 'rocket_clean_domain' ) ) {
			$found['wp_rocket'] = 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed_Cache' ) || class_exists( 'LiteSpeed\\Core' ) ) {
			$found['litespeed'] = 'LiteSpeed Cache';
		}
		if ( defined( 'W3TC_VERSION' ) || function_exists( 'w3tc_pgcache_flush' ) ) {
			$found['w3tc'] = 'W3 Total Cache';
		}
		if ( defined( 'WPCACHEHOME' ) && function_exists( 'wp_cache_clear_cache' ) ) {
			$found['wp_super_cache'] = 'WP Super Cache';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) || class_exists( 'SG_CachePress' ) || class_exists( 'SiteGround_Optimizer\\Supercacher\\Supercacher' ) ) {
			$found['sg_optimizer'] = 'SG Optimizer';
		}
		if ( defined( 'KINSTA_CACHE_ZONE' ) || class_exists( 'Kinsta\\Cache' ) ) {
			$found['kinsta'] = 'Kinsta Cache';
		}
		if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) || class_exists( 'autoptimizeCache' ) ) {
			$found['autoptimize'] = 'Autoptimize';
		}
		if ( defined( 'BREEZE_VERSION' ) || class_exists( 'Breeze_Admin' ) ) {
			$found['breeze'] = 'Breeze (Cloudways)';
		}
		if ( defined( 'COMET_CACHE_VERSION' ) || class_exists( 'comet_cache' ) ) {
			$found['comet_cache'] = 'Comet Cache';
		}
		if ( defined( 'CACHE_ENABLER_VERSION' ) || class_exists( 'Cache_Enabler' ) ) {
			$found['cache_enabler'] = 'Cache Enabler';
		}

		return $found;
	}

	/**
	 * Purge all detected caches + our own transients.
	 * Returns ['purged' => [...], 'errors' => [...], 'systems' => [...]].
	 */
	public static function purge_all() {
		$purged  = [];
		$errors  = [];
		$systems = self::detect();

		// WP Rocket
		if ( isset( $systems['wp_rocket'] ) ) {
			if ( function_exists( 'rocket_clean_domain' ) ) {
				try { rocket_clean_domain(); $purged[] = 'WP Rocket'; } catch ( \Throwable $e ) { $errors[] = 'WP Rocket'; }
			}
		}

		// LiteSpeed Cache
		if ( isset( $systems['litespeed'] ) ) {
			try {
				do_action( 'litespeed_purge_all' );
				$purged[] = 'LiteSpeed Cache';
			} catch ( \Throwable $e ) {
				if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
					try { LiteSpeed_Cache_API::purge_all(); $purged[] = 'LiteSpeed Cache'; } catch ( \Throwable $e2 ) { $errors[] = 'LiteSpeed'; }
				} else {
					$errors[] = 'LiteSpeed';
				}
			}
		}

		// W3 Total Cache
		if ( isset( $systems['w3tc'] ) ) {
			if ( function_exists( 'w3tc_pgcache_flush' ) ) {
				try { w3tc_pgcache_flush(); $purged[] = 'W3 Total Cache'; } catch ( \Throwable $e ) { $errors[] = 'W3 Total Cache'; }
			}
		}

		// WP Super Cache
		if ( isset( $systems['wp_super_cache'] ) ) {
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				try { wp_cache_clear_cache(); $purged[] = 'WP Super Cache'; } catch ( \Throwable $e ) { $errors[] = 'WP Super Cache'; }
			}
		}

		// SG Optimizer (SiteGround)
		if ( isset( $systems['sg_optimizer'] ) ) {
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				try { sg_cachepress_purge_cache(); $purged[] = 'SG Optimizer'; } catch ( \Throwable $e ) { $errors[] = 'SG Optimizer'; }
			} elseif ( class_exists( 'SiteGround_Optimizer\\Supercacher\\Supercacher' ) ) {
				try {
					do_action( 'sgo_css_combine_flush' );
					do_action( 'sgo_js_combine_flush' );
					\SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
					$purged[] = 'SG Optimizer';
				} catch ( \Throwable $e ) { $errors[] = 'SG Optimizer'; }
			}
		}

		// Kinsta
		if ( isset( $systems['kinsta'] ) ) {
			try {
				if ( class_exists( 'Kinsta\\Cache' ) && method_exists( 'Kinsta\\Cache', 'purge_complete_caches' ) ) {
					\Kinsta\Cache::purge_complete_caches();
				} else {
					do_action( 'kinsta_cache_purge_complete' );
				}
				$purged[] = 'Kinsta Cache';
			} catch ( \Throwable $e ) { $errors[] = 'Kinsta Cache'; }
		}

		// Autoptimize
		if ( isset( $systems['autoptimize'] ) ) {
			if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
				try { autoptimizeCache::clearall(); $purged[] = 'Autoptimize'; } catch ( \Throwable $e ) { $errors[] = 'Autoptimize'; }
			}
		}

		// Breeze (Cloudways)
		if ( isset( $systems['breeze'] ) ) {
			try { do_action( 'breeze_clear_all_cache' ); $purged[] = 'Breeze'; } catch ( \Throwable $e ) { $errors[] = 'Breeze'; }
		}

		// Comet Cache
		if ( isset( $systems['comet_cache'] ) ) {
			if ( class_exists( 'comet_cache' ) && method_exists( 'comet_cache', 'clear' ) ) {
				try { comet_cache::clear(); $purged[] = 'Comet Cache'; } catch ( \Throwable $e ) { $errors[] = 'Comet Cache'; }
			}
		}

		// Cache Enabler
		if ( isset( $systems['cache_enabler'] ) ) {
			try { do_action( 'cache_enabler_clear_complete_cache' ); $purged[] = 'Cache Enabler'; } catch ( \Throwable $e ) { $errors[] = 'Cache Enabler'; }
		}

		// WordPress object cache (always)
		try { wp_cache_flush(); } catch ( \Throwable $e ) {}

		// Our own transients
		self::purge_transients();

		update_option( self::LAST_PURGE_OPTION, time(), false );

		return [
			'purged'  => $purged,
			'errors'  => $errors,
			'systems' => $systems,
		];
	}

	/**
	 * Clear our own transients and rate-limit keys.
	 */
	public static function purge_transients() {
		delete_transient( 'icb_last_snapshot' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_icb_rate_%' OR option_name LIKE '_transient_timeout_icb_rate_%'" );
	}

	/**
	 * Timestamp of last purge, or 0 if never.
	 */
	public static function last_purge_time() {
		return (int) get_option( self::LAST_PURGE_OPTION, 0 );
	}

	/**
	 * True if the plugin file is newer than the last cache purge (cache may be stale).
	 */
	public static function cache_may_be_stale() {
		$plugin_mtime = filemtime( ICB_PATH . 'includes/class-icb-frontend.php' );
		$last_purge   = self::last_purge_time();
		return $plugin_mtime > $last_purge;
	}
}
