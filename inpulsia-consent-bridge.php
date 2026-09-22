<?php
/**
 * Plugin Name:       Inpulsia Consent v2
 * Plugin URI:        https://inpulsia.es
 * Description:       Puente de Google Consent Mode v2 entre Complianz y Google Site Kit / GTM. Propaga el consentimiento a Google, Meta y TikTok, añade eventos GA4 y Meta para WooCommerce, Enhanced Conversions, sGTM (Stape), diagnóstico, salud del consent y auditoría RGPD.
 * Version:           2.7.0
 * Author:            Inpulsia
 * Author URI:        https://inpulsia.es
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       inpulsia-consent-bridge
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * Update URI:        https://github.com/javierlopezcamin-arch/inpulsia-consent-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ICB_VERSION', '2.7.0' );

// Public GitHub repo that publishes the releases ("usuario/repo"). Sites update from it with
// no configuration. Optional: define ICB_GITHUB_TOKEN in wp-config.php only if the repo is
// ever made private again.
if ( ! defined( 'ICB_GITHUB_REPO' ) ) {
	define( 'ICB_GITHUB_REPO', 'javierlopezcamin-arch/inpulsia-consent-bridge' );
}
define( 'ICB_PATH', plugin_dir_path( __FILE__ ) );
define( 'ICB_URL', plugin_dir_url( __FILE__ ) );
define( 'ICB_OPTION', 'inpulsia_consent_bridge_settings' );

require_once ICB_PATH . 'includes/class-icb-plugin.php';
require_once ICB_PATH . 'includes/class-icb-cache.php';
require_once ICB_PATH . 'includes/class-icb-admin.php';
require_once ICB_PATH . 'includes/class-icb-frontend.php';
require_once ICB_PATH . 'includes/class-icb-diagnostics.php';
require_once ICB_PATH . 'includes/class-icb-health.php';
require_once ICB_PATH . 'includes/class-icb-audit.php';
require_once ICB_PATH . 'includes/class-icb-woocommerce.php';
require_once ICB_PATH . 'includes/class-icb-stape.php';
require_once ICB_PATH . 'includes/class-icb-updater.php';

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'inpulsia-consent-bridge', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	ICB_Plugin::instance()->init();
} );

register_activation_hook( __FILE__, [ 'ICB_Plugin', 'activate' ] );
