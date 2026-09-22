<?php
/**
 * Uninstall routine — removes every option, transient, cron hook and table the plugin
 * created, so a site is left exactly as it was before installation.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Options.
delete_option( 'inpulsia_consent_bridge_settings' );
delete_option( 'icb_events_table_version' );
delete_option( 'icb_last_cache_purge' );

// Transients (snapshot + per-visitor rate-limit keys + GitHub updater cache).
delete_transient( 'icb_last_snapshot' );
delete_site_transient( 'icb_gh_release' );
delete_site_transient( 'icb_gh_last_error' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_icb_rate_%' OR option_name LIKE '_transient_timeout_icb_rate_%'" );

// Cron.
wp_clear_scheduled_hook( 'icb_weekly_purge' );

// Health table.
$table = $wpdb->prefix . 'icb_events';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
