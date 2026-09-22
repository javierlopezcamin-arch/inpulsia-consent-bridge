<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ICB_Audit {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = ICB_Plugin::get_settings();
		$results  = ICB_Diagnostics::run();
		$summary  = ICB_Diagnostics::summary( $results );
		$stats    = ICB_Health::stats();
		$snap     = get_transient( 'icb_last_snapshot' );
		$stape    = ICB_Plugin::detect_stape();
		$cmplz    = ICB_Plugin::complianz_active();
		$sk       = ICB_Plugin::sitekit_active();
		$site     = get_bloginfo( 'name' );
		$home     = home_url( '/' );
		$date     = wp_date( 'd/m/Y H:i' );
		?>
		<div class="icb-audit">
			<style>
				.icb-audit{font-family:-apple-system,sans-serif;color:#1d2327;max-width:900px}
				.icb-audit h2{border-bottom:2px solid #2271b1;padding-bottom:8px;margin-top:32px}
				.icb-audit h3{color:#2271b1;margin-top:24px}
				.icb-audit table{width:100%;border-collapse:collapse;margin:12px 0}
				.icb-audit th,.icb-audit td{padding:8px 12px;border-bottom:1px solid #dcdcde;text-align:left;font-size:13px}
				.icb-audit th{background:#f6f7f7;font-weight:600}
				.icb-audit .ok{color:#1e7c1e;font-weight:600}
				.icb-audit .warn{color:#dba617;font-weight:600}
				.icb-audit .err{color:#b32d2e;font-weight:600}
				.icb-audit .kpi{display:inline-block;padding:16px 24px;background:#f6f7f7;border-radius:8px;margin:4px;min-width:120px;text-align:center}
				.icb-audit .kpi b{display:block;font-size:24px;color:#2271b1}
				.icb-audit .meta{color:#646970;font-size:12px}
				@media print {.icb-noprint{display:none!important}}
			</style>

			<div class="icb-noprint" style="margin-bottom:20px">
				<button class="button icb-btn" onclick="window.print()"><?php esc_html_e( 'Imprimir / Guardar PDF', 'inpulsia-consent-bridge' ); ?></button>
			</div>

			<h1 style="margin:0"><?php esc_html_e( 'Informe de auditoría — Consent Mode v2', 'inpulsia-consent-bridge' ); ?></h1>
			<p class="meta"><?php echo esc_html( $site ); ?> · <?php echo esc_html( $home ); ?> · <?php echo esc_html( $date ); ?></p>

			<h2><?php esc_html_e( 'Resumen', 'inpulsia-consent-bridge' ); ?></h2>
			<div>
				<div class="kpi"><b><?php echo (int) $summary['ok']; ?></b><?php esc_html_e( 'Tests OK', 'inpulsia-consent-bridge' ); ?></div>
				<div class="kpi"><b style="color:#dba617"><?php echo (int) $summary['warn']; ?></b><?php esc_html_e( 'Avisos', 'inpulsia-consent-bridge' ); ?></div>
				<div class="kpi"><b style="color:#b32d2e"><?php echo (int) $summary['err']; ?></b><?php esc_html_e( 'Errores', 'inpulsia-consent-bridge' ); ?></div>
				<?php if ( ! empty( $stats['enabled'] ) ) : ?>
					<div class="kpi"><b><?php echo $stats['accept_rate'] !== null ? esc_html( $stats['accept_rate'] . '%' ) : '—'; ?></b><?php esc_html_e( 'Aceptación 7d', 'inpulsia-consent-bridge' ); ?></div>
					<div class="kpi"><b><?php echo (int) $stats['total_7d']; ?></b><?php esc_html_e( 'Eventos 7d', 'inpulsia-consent-bridge' ); ?></div>
				<?php endif; ?>
			</div>

			<h2><?php esc_html_e( 'Configuración', 'inpulsia-consent-bridge' ); ?></h2>
			<table>
				<tr><th><?php esc_html_e( 'Versión', 'inpulsia-consent-bridge' ); ?></th><td><?php echo esc_html( ICB_VERSION ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Activado', 'inpulsia-consent-bridge' ); ?></th><td><?php echo ! empty( $s['enabled'] ) ? '<span class="ok">ON</span>' : '<span class="err">OFF</span>'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Región', 'inpulsia-consent-bridge' ); ?></th><td><?php echo esc_html( $s['region'] ); ?></td></tr>
				<tr><th>wait_for_update</th><td><?php echo (int) $s['wait_for_update']; ?> ms</td></tr>
				<tr><th><?php esc_html_e( 'Force GTM unblock', 'inpulsia-consent-bridge' ); ?></th><td><?php echo ! empty( $s['force_gtm_unblock'] ) ? 'ON' : 'OFF'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Meta Pixel consent sync', 'inpulsia-consent-bridge' ); ?></th><td><?php echo ! empty( $s['meta_pixel_consent'] ) ? 'ON' : 'OFF'; ?></td></tr>
				<tr><th><?php esc_html_e( 'TikTok Pixel consent sync', 'inpulsia-consent-bridge' ); ?></th><td><?php echo ! empty( $s['tiktok_pixel_consent'] ) ? 'ON' : 'OFF'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Meta Pixel events (WC)', 'inpulsia-consent-bridge' ); ?></th><td><?php echo ! empty( $s['meta_pixel_events'] ) ? '<span class="ok">ON</span>' : 'OFF'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Enhanced Conversions (Google)', 'inpulsia-consent-bridge' ); ?></th><td><?php echo ! empty( $s['enhanced_conversions'] ) ? '<span class="ok">ON</span>' : 'OFF'; ?></td></tr>
			</table>

			<h2><?php esc_html_e( 'Integraciones detectadas', 'inpulsia-consent-bridge' ); ?></h2>
			<?php $wc = class_exists( 'WooCommerce' ); ?>
			<table>
				<tr><th>Complianz</th><td><?php echo $cmplz ? '<span class="ok">✓ Activo</span>' : '<span class="err">✗ No detectado</span>'; ?></td></tr>
				<tr><th>Google Site Kit</th><td><?php echo $sk ? '<span class="ok">✓ Activo</span>' : '<span class="warn">✗ No detectado</span>'; ?></td></tr>
				<tr><th>WooCommerce</th><td><?php echo $wc ? '<span class="ok">✓ Activo</span>' : '— No detectado'; ?></td></tr>
				<tr><th>Stape (plugin)</th><td><?php echo ! empty( $stape ) ? '<span class="ok">✓ Activo</span> (' . esc_html( implode( ', ', $stape ) ) . ')' : '— No detectado'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Eventos WC (GA4)', 'inpulsia-consent-bridge' ); ?></th><td><?php echo ! empty( $s['wc_events'] ) ? '<span class="ok">ON</span>' : 'OFF'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Eventos WC → Meta Pixel', 'inpulsia-consent-bridge' ); ?></th><td><?php echo ! empty( $s['meta_pixel_events'] ) ? '<span class="ok">ON</span>' : 'OFF'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Enhanced Conversions', 'inpulsia-consent-bridge' ); ?></th><td><?php echo ! empty( $s['enhanced_conversions'] ) ? '<span class="ok">ON</span>' : 'OFF'; ?></td></tr>
				<tr><th>sGTM</th><td><?php echo ! empty( $s['sgtm_enabled'] ) && ! empty( $s['sgtm_domain'] ) ? '<span class="ok">' . esc_html( $s['sgtm_domain'] . ' / ' . $s['sgtm_container_id'] ) . '</span>' : '— No configurado'; ?></td></tr>
			</table>

			<h2><?php esc_html_e( 'Mapeo de categorías', 'inpulsia-consent-bridge' ); ?></h2>
			<table>
				<tr><th><?php esc_html_e( 'Categoría Complianz', 'inpulsia-consent-bridge' ); ?></th><th><?php esc_html_e( 'Señales Google', 'inpulsia-consent-bridge' ); ?></th></tr>
				<?php foreach ( ICB_Plugin::all_categories() as $cat ) :
					$sigs = isset( $s['category_mapping'][ $cat ] ) ? (array) $s['category_mapping'][ $cat ] : [];
				?>
					<tr><th><?php echo esc_html( $cat ); ?></th><td><?php echo $sigs ? esc_html( implode( ', ', $sigs ) ) : '<em>—</em>'; ?></td></tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Tests de diagnóstico', 'inpulsia-consent-bridge' ); ?></h2>
			<table>
				<tr><th><?php esc_html_e( 'Estado', 'inpulsia-consent-bridge' ); ?></th><th><?php esc_html_e( 'Test', 'inpulsia-consent-bridge' ); ?></th><th><?php esc_html_e( 'Valor', 'inpulsia-consent-bridge' ); ?></th></tr>
				<?php foreach ( $results as $r ) :
					$icon = $r['level'] === 'ok' ? '<span class="ok">✓</span>' : ( $r['level'] === 'warning' ? '<span class="warn">!</span>' : '<span class="err">✗</span>' );
				?>
					<tr>
						<td><?php echo $icon; ?></td>
						<td><?php echo esc_html( $r['title'] ); ?><?php if ( $r['level'] !== 'ok' && $r['hint'] ) echo '<br><span class="meta">' . esc_html( $r['hint'] ) . '</span>'; ?></td>
						<td><?php echo esc_html( $r['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php if ( ! empty( $stats['enabled'] ) && $stats['total_7d'] > 0 ) : ?>
				<h2><?php esc_html_e( 'Salud del consent (últimos 7 días)', 'inpulsia-consent-bridge' ); ?></h2>
				<table>
					<tr><th><?php esc_html_e( 'Total decisiones', 'inpulsia-consent-bridge' ); ?></th><td><?php echo (int) $stats['total_7d']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Aceptaron todo', 'inpulsia-consent-bridge' ); ?></th><td><?php echo (int) $stats['accept_7d']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Rechazaron todo', 'inpulsia-consent-bridge' ); ?></th><td><?php echo (int) $stats['deny_7d']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Parcial', 'inpulsia-consent-bridge' ); ?></th><td><?php echo (int) $stats['partial_7d']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Tasa aceptación total', 'inpulsia-consent-bridge' ); ?></th><td><?php echo $stats['accept_rate'] !== null ? esc_html( $stats['accept_rate'] . '%' ) : '—'; ?></td></tr>
					<tr><th><?php esc_html_e( 'Aceptaron marketing', 'inpulsia-consent-bridge' ); ?></th><td><?php echo isset( $stats['marketing_rate'] ) && $stats['marketing_rate'] !== null ? esc_html( $stats['marketing_rate'] . '%' ) : '—'; ?></td></tr>
					<tr><th><?php esc_html_e( 'Tiempo medio decisión', 'inpulsia-consent-bridge' ); ?></th><td><?php echo $stats['avg_ttd_ms'] ? esc_html( round( $stats['avg_ttd_ms'] / 1000, 1 ) . 's' ) : '—'; ?></td></tr>
				</table>
			<?php endif; ?>

			<?php if ( $snap && ! empty( $snap['ics'] ) ) : ?>
				<h2><?php esc_html_e( 'Último estado capturado', 'inpulsia-consent-bridge' ); ?></h2>
				<p class="meta"><?php echo esc_html( $snap['url'] ?? '' ); ?></p>
				<table>
					<tr><th><?php esc_html_e( 'Señal', 'inpulsia-consent-bridge' ); ?></th><th><?php esc_html_e( 'Valor', 'inpulsia-consent-bridge' ); ?></th></tr>
					<?php foreach ( ICB_Plugin::all_signals() as $sig ) :
						$entry = $snap['ics'][ $sig ] ?? null;
						$val   = $entry['value'] ?? '—';
						$cls   = $val === 'granted' ? 'ok' : ( $val === 'denied' ? 'err' : '' );
					?>
						<tr><th><?php echo esc_html( $sig ); ?></th><td><span class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $val ); ?></span></td></tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recomendaciones', 'inpulsia-consent-bridge' ); ?></h2>
			<ul>
				<?php
				$recs = [];
				foreach ( $results as $r ) {
					if ( $r['level'] !== 'ok' && $r['hint'] ) {
						$recs[] = $r['hint'];
					}
				}
				if ( empty( $recs ) ) {
					echo '<li><span class="ok">' . esc_html__( 'Sin recomendaciones críticas. Implementación saludable.', 'inpulsia-consent-bridge' ) . '</span></li>';
				} else {
					foreach ( array_unique( $recs ) as $r ) {
						echo '<li>' . esc_html( $r ) . '</li>';
					}
				}
				?>
			</ul>

			<p class="meta" style="margin-top:40px;border-top:1px solid #dcdcde;padding-top:12px">
				<?php printf( esc_html__( 'Generado por Inpulsia Consent v%s · %s', 'inpulsia-consent-bridge' ), esc_html( ICB_VERSION ), esc_html( $date ) ); ?>
			</p>
		</div>
		<?php
	}
}
