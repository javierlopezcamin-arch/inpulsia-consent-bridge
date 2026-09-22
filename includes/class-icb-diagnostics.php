<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ICB_Diagnostics {

	public static function run() {
		$s = ICB_Plugin::get_settings();
		$results = [];

		$results[] = self::check(
			'PHP version >= 7.4',
			version_compare( PHP_VERSION, '7.4', '>=' ),
			'PHP ' . PHP_VERSION,
			'Actualiza PHP a 7.4 o superior.'
		);

		$results[] = self::check(
			'WordPress >= 5.8',
			version_compare( get_bloginfo( 'version' ), '5.8', '>=' ),
			'WP ' . get_bloginfo( 'version' ),
			'Actualiza WordPress.'
		);

		$cmplz_active = ICB_Plugin::complianz_active();
		$results[] = self::check(
			'Complianz instalado y activo',
			$cmplz_active,
			$cmplz_active ? 'Detectado' : 'No detectado',
			'Instala Complianz GDPR/CCPA Cookie Consent.'
		);

		$sk_active = ICB_Plugin::sitekit_active();
		$results[] = self::check(
			'Google Site Kit instalado y activo',
			$sk_active,
			$sk_active ? 'Detectado' : 'No detectado',
			'Instala Site Kit by Google para cargar gtag.js.',
			'warning'
		);

		$results[] = self::check(
			'Plugin activado',
			! empty( $s['enabled'] ),
			! empty( $s['enabled'] ) ? 'ON' : 'OFF',
			'Activa el toggle "Activar puente de consent" en Ajustes.'
		);

		$cmplz_conflict = ICB_Plugin::complianz_has_consent_mode();
		$results[] = self::check(
			'Sin conflicto de Consent Mode con Complianz',
			! $cmplz_conflict,
			$cmplz_conflict ? 'CONFLICTO: ambos activos' : 'OK',
			'Desactiva Google Consent Mode en Complianz o desactiva este plugin.'
		);

		$wp_rocket_delay = self::wp_rocket_delay_blocks_gtag();
		$results[] = self::check(
			'WP Rocket Delay JS no bloquea gtag',
			! $wp_rocket_delay,
			$wp_rocket_delay ? 'Delay JS activo sin excluir gtag' : 'OK',
			'En WP Rocket → File Optimization → JavaScript, añade exclusiones para googletagmanager.com y google-analytics.com.',
			'warning'
		);

		if ( ! empty( $s['wc_events'] ) ) {
			$results[] = self::check(
				'WooCommerce activo',
				class_exists( 'WooCommerce' ),
				class_exists( 'WooCommerce' ) ? 'OK' : 'WC no detectado',
				'WooCommerce no está activo pero los eventos eCommerce están habilitados. Desactívalos en la pestaña eCommerce.',
				'warning'
			);

			$conflicts = ICB_Plugin::detect_conflicting_trackers();
			$results[] = self::check(
				'Sin trackers eCommerce duplicados',
				empty( $conflicts ),
				empty( $conflicts ) ? 'OK' : 'Detectados: ' . implode( ', ', array_keys( $conflicts ) ),
				'Otros plugins de tracking activos pueden disparar eventos GA4/Meta duplicados. Desactiva uno o coordina cuál envía qué.',
				'warning'
			);
		}

		if ( ! empty( $s['enhanced_conversions'] ) ) {
			$ec_ok = class_exists( 'WooCommerce' ) && ! empty( $s['wc_events'] ) && ! empty( $s['wc_send_purchase'] );
			$results[] = self::check(
				'Enhanced Conversions configurado',
				$ec_ok,
				$ec_ok ? 'OK — se inyectará en página de gracias' : 'Requiere WooCommerce activo + "Activar eventos WooCommerce" + "purchase" habilitado',
				'Activa WooCommerce Events y el evento purchase en la pestaña eCommerce. Recuerda activar "Conversiones mejoradas" en Google Ads.',
				'warning'
			);
		}

		if ( ! empty( $s['wc_events'] ) && ! empty( $s['meta_pixel_events'] ) ) {
			$pys_active = defined( 'PYS_FREE_VERSION' ) || defined( 'PYS_VERSION' ) || defined( 'PYS_PRO_VERSION' ) || class_exists( 'PYS' ) || class_exists( 'PixelYourSitePro\\Plugin' );
			$results[] = self::check(
				'Sin duplicados Meta Pixel (PYS + ICB)',
				! $pys_active,
				$pys_active ? 'PixelYourSite activo — riesgo de Purchase duplicado en Meta' : 'OK',
				'Desactiva los eventos de Meta en PixelYourSite o desactiva "Meta Pixel events" en este plugin.',
				'warning'
			);
		}

		if ( ! empty( $s['sgtm_enabled'] ) ) {
			$cfg = ! empty( $s['sgtm_domain'] ) && ! empty( $s['sgtm_container_id'] );
			$results[] = self::check(
				'sGTM configurado',
				$cfg,
				$cfg ? $s['sgtm_domain'] . ' / ' . $s['sgtm_container_id'] : 'Falta dominio o container ID',
				'Completa Dominio sGTM y Container ID en la pestaña sGTM.'
			);
		}

		$mapping = isset( $s['category_mapping'] ) && is_array( $s['category_mapping'] ) ? $s['category_mapping'] : [];
		$has_marketing = ! empty( $mapping['marketing'] );
		$has_stats     = ! empty( $mapping['statistics'] );
		$results[] = self::check(
			'Mapeo de categorías configurado',
			$has_marketing && $has_stats,
			'marketing: ' . count( $mapping['marketing'] ?? [] ) . ' · statistics: ' . count( $mapping['statistics'] ?? [] ),
			'Configura el mapeo en la pestaña Mapeo de categorías.'
		);

		// Cache: the consent JS is inline in the HTML, so a page cache that wasn't purged
		// after an update keeps serving the previous version to visitors.
		$cache_stale   = ICB_Cache::cache_may_be_stale();
		$cache_systems = ICB_Cache::detect();
		$last_purge    = ICB_Cache::last_purge_time();
		$results[] = self::check(
			'Caché purgada tras última actualización',
			! $cache_stale,
			$cache_stale
				? __( 'JS puede estar cacheado — purga recomendada', 'inpulsia-consent-bridge' )
				: ( $last_purge > 0
					? sprintf( __( 'hace %s', 'inpulsia-consent-bridge' ), human_time_diff( $last_purge ) ) . ( $cache_systems ? ' · ' . implode( ', ', $cache_systems ) : '' )
					: __( 'Sin actualizaciones recientes', 'inpulsia-consent-bridge' ) ),
			__( 'Ve a Ajustes → Vaciar caché ahora para asegurarte de que los visitantes reciben el JS actualizado.', 'inpulsia-consent-bridge' ),
			'warning'
		);

		// Updates from GitHub.
		$repo = ICB_Updater::repo();
		if ( ! $repo ) {
			$results[] = self::check(
				'Actualizaciones desde GitHub',
				false,
				'Repo no configurado',
				'Define ICB_GITHUB_REPO ("usuario/repo") en el plugin o en wp-config.php.',
				'warning'
			);
		} else {
			$release = ICB_Updater::get_release();
			$err     = ICB_Updater::last_error();
			$ok      = (bool) $release;
			if ( $ok ) {
				$value = version_compare( $release['version'], ICB_VERSION, '>' )
					? sprintf( 'Nueva versión %s disponible (instalada %s)', $release['version'], ICB_VERSION )
					: sprintf( 'Al día (%s) · %s', ICB_VERSION, $repo );
			} else {
				$value = $err['message'] ?? 'Sin respuesta de GitHub';
			}
			$results[] = self::check(
				'Actualizaciones desde GitHub',
				$ok,
				$value . ( ICB_Updater::has_token() ? ' · token OK' : ' · sin token' ),
				'Repo privado: añade define( \'ICB_GITHUB_TOKEN\', \'github_pat_...\' ); en wp-config.php (token fine-grained, solo este repo, Contents: Read-only). Comprueba también que hay al menos una release publicada.',
				'warning'
			);
		}

		$snap = get_transient( 'icb_last_snapshot' );
		if ( $snap && is_array( $snap ) ) {
			$age = isset( $snap['saved_at'] ) ? max( 0, time() - (int) $snap['saved_at'] ) : null;
			$age_label = $age === null ? '' : ' (' . human_time_diff( time() - $age, time() ) . ')';
			$results[] = self::check(
				'gtag.js cargó en el frontend',
				! empty( $snap['icsRaw'] ),
				! empty( $snap['icsRaw'] ) ? 'OK' . $age_label : 'No detectado — gtag no cargó',
				'Comprueba adblockers, exclusiones de Site Kit (usuarios logueados) o Delay JS.',
				'warning'
			);

			$consent_events = isset( $snap['consentEvents'] ) ? $snap['consentEvents'] : [];
			$has_default = false; $has_update = false;
			foreach ( $consent_events as $e ) {
				if ( isset( $e[1] ) && $e[1] === 'default' ) $has_default = true;
				if ( isset( $e[1] ) && $e[1] === 'update' ) $has_update = true;
			}
			$results[] = self::check(
				'consent default inyectado',
				$has_default,
				$has_default ? 'OK' : 'No detectado en último snapshot',
				'El plugin debería inyectar consent default en wp_head prio 0.'
			);

			$results[] = self::check(
				'consent update tras aceptar banner',
				$has_update,
				$has_update ? 'OK' : 'Falta — usuario no aceptó o listener no escucha',
				'Acepta el banner en el frontend y refresca snapshot. Si sigue faltando, revisa Complianz.',
				'warning'
			);

			if ( isset( $snap['order'] ) && is_array( $snap['order'] ) ) {
				$ord = $snap['order'];
				$results[] = self::check(
					'Orden consent → eventos eCommerce',
					! empty( $ord['orderOk'] ),
					empty( $ord['firstEcommerceEvent'] ) ? 'Sin eventos eCommerce' : 'consent[' . $ord['consentIndex'] . '] → ' . $ord['firstEcommerceEvent'] . '[' . $ord['firstEcommerceIndex'] . ']',
					'Algún evento eCommerce se dispara antes que consent default. Sube prioridad o revisa plugins de tracking.'
				);
			}
		} else {
			$results[] = self::check(
				'Snapshot reciente del frontend',
				false,
				'Sin datos — visita la home',
				'Visita la home del sitio (logueado o no) para que el reporter envíe un snapshot.',
				'warning'
			);
		}

		return $results;
	}

	private static function check( $title, $passed, $value, $hint = '', $level = 'error' ) {
		return [
			'title'  => $title,
			'passed' => (bool) $passed,
			'value'  => $value,
			'hint'   => $hint,
			'level'  => $passed ? 'ok' : $level,
		];
	}

	private static function wp_rocket_delay_blocks_gtag() {
		if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
			return false;
		}
		$opts = get_option( 'wp_rocket_settings', [] );
		if ( empty( $opts['delay_js'] ) ) {
			return false;
		}
		$exclusions = isset( $opts['delay_js_exclusions'] ) ? (array) $opts['delay_js_exclusions'] : [];
		$joined = strtolower( implode( "\n", $exclusions ) );
		if ( strpos( $joined, 'googletagmanager' ) !== false || strpos( $joined, 'google-analytics' ) !== false || strpos( $joined, 'gtag' ) !== false ) {
			return false;
		}
		return true;
	}

	public static function summary( $results ) {
		$total = count( $results );
		$ok = 0; $warn = 0; $err = 0;
		foreach ( $results as $r ) {
			if ( $r['level'] === 'ok' ) $ok++;
			elseif ( $r['level'] === 'warning' ) $warn++;
			else $err++;
		}
		return compact( 'total', 'ok', 'warn', 'err' );
	}
}
