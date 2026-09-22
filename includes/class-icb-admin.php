<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ICB_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'maybe_reset_mapping' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'wp_ajax_icb_get_snapshot', [ $this, 'ajax_get_snapshot' ] );
		add_action( 'wp_ajax_icb_test_sgtm', [ $this, 'ajax_test_sgtm' ] );
		add_action( 'wp_ajax_icb_purge_cache', [ $this, 'ajax_purge_cache' ] );
		// Auto-purge cache whenever plugin settings are saved.
		add_action( 'update_option_' . ICB_OPTION, [ $this, 'on_settings_saved' ], 10, 0 );
	}

	public function on_settings_saved() {
		ICB_Cache::purge_all();
	}

	/**
	 * "Restaurar por defecto" for the category mapping. Handled on admin_init so we can
	 * issue a real HTTP redirect (PRG pattern) instead of echoing a JS redirect mid-render.
	 */
	public function maybe_reset_mapping() {
		if ( ! isset( $_GET['icb_reset_mapping'], $_GET['_wpnonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'icb_reset_mapping' ) ) {
			return;
		}
		$s = ICB_Plugin::get_settings();
		$s['category_mapping'] = ICB_Plugin::default_mapping();
		update_option( ICB_OPTION, $s ); // triggers the cache purge hook as well
		wp_safe_redirect( admin_url( 'admin.php?page=inpulsia-consent-bridge#mapping' ) );
		exit;
	}

	public function ajax_purge_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'icb_status', 'nonce' );
		$result = ICB_Cache::purge_all();
		wp_send_json_success( $result );
	}

	public function ajax_test_sgtm() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'icb_status', 'nonce' );
		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		wp_send_json_success( ICB_Stape::test_connection( $domain ) );
	}

	public function ajax_get_snapshot() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'icb_status', 'nonce' );
		$snap = get_transient( 'icb_last_snapshot' );
		wp_send_json_success( $snap ?: null );
	}

	public function menu() {
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="#a7aaad" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round">';
		$svg .= '<line x1="1" y1="15" x2="19" y2="15"/>';
		$svg .= '<line x1="5" y1="15" x2="5" y2="2.6"/>';
		$svg .= '<line x1="15" y1="15" x2="15" y2="2.6"/>';
		$svg .= '<path d="M1 13 Q3 12 5 3 C8 13 12 13 15 3 Q17 12 19 13"/>';
		$svg .= '<line x1="7.6" y1="15" x2="7.6" y2="8.7" stroke-width="0.7"/>';
		$svg .= '<line x1="10" y1="15" x2="10" y2="6.4" stroke-width="0.7"/>';
		$svg .= '<line x1="12.4" y1="15" x2="12.4" y2="8.7" stroke-width="0.7"/>';
		$svg .= '</svg>';
		$icon = 'data:image/svg+xml;base64,' . base64_encode( $svg );

		add_menu_page(
			__( 'Inpulsia Consent v2', 'inpulsia-consent-bridge' ),
			__( 'Inpulsia Consent', 'inpulsia-consent-bridge' ),
			'manage_options',
			'inpulsia-consent-bridge',
			[ $this, 'render_page' ],
			$icon,
			'80.7'
		);
		add_submenu_page(
			'inpulsia-consent-bridge',
			__( 'Inpulsia Consent v2', 'inpulsia-consent-bridge' ),
			__( 'Panel', 'inpulsia-consent-bridge' ),
			'manage_options',
			'inpulsia-consent-bridge',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'icb_group', ICB_OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
			'default'           => ICB_Plugin::defaults(),
		] );
	}

	public function sanitize( $input ) {
		$current = get_option( ICB_OPTION, [] );
		if ( ! is_array( $current ) ) {
			$current = [];
		}
		$out = wp_parse_args( $current, ICB_Plugin::defaults() );
		$section = isset( $input['icb_section'] ) ? sanitize_key( $input['icb_section'] ) : 'settings';

		if ( $section === 'settings' ) {
			$out['enabled']              = empty( $input['enabled'] ) ? 0 : 1;
			$region                      = isset( $input['region'] ) ? sanitize_text_field( $input['region'] ) : 'EEA';
			$out['region']               = in_array( $region, [ 'EEA', 'Global' ], true ) ? $region : 'EEA';
			$wait                        = isset( $input['wait_for_update'] ) ? absint( $input['wait_for_update'] ) : 500;
			$out['wait_for_update']      = max( 0, min( 5000, $wait ) );
			$out['force_gtm_unblock']    = empty( $input['force_gtm_unblock'] ) ? 0 : 1;
			$out['debug']                = empty( $input['debug'] ) ? 0 : 1;
			$out['meta_pixel_consent']   = empty( $input['meta_pixel_consent'] ) ? 0 : 1;
			$out['tiktok_pixel_consent'] = empty( $input['tiktok_pixel_consent'] ) ? 0 : 1;
			$out['health_logging']       = empty( $input['health_logging'] ) ? 0 : 1;
		}

		if ( $section === 'ecommerce' ) {
			$out['wc_events']           = empty( $input['wc_events'] ) ? 0 : 1;
			$out['wc_send_purchase']    = empty( $input['wc_send_purchase'] ) ? 0 : 1;
			$out['wc_send_cart_events'] = empty( $input['wc_send_cart_events'] ) ? 0 : 1;
			$out['wc_send_view_item']   = empty( $input['wc_send_view_item'] ) ? 0 : 1;
			$out['meta_pixel_events']    = empty( $input['meta_pixel_events'] ) ? 0 : 1;
			$out['enhanced_conversions'] = empty( $input['enhanced_conversions'] ) ? 0 : 1;
		}

		if ( $section === 'sgtm' ) {
			$out['sgtm_enabled']      = empty( $input['sgtm_enabled'] ) ? 0 : 1;
			$out['sgtm_domain']       = ICB_Stape::sanitize_domain( $input['sgtm_domain'] ?? '' );
			$out['sgtm_container_id'] = ICB_Stape::sanitize_container_id( $input['sgtm_container_id'] ?? '' );
			$out['sgtm_load_gtm']     = empty( $input['sgtm_load_gtm'] ) ? 0 : 1;
		}

		if ( $section === 'mapping' ) {
			$mapping       = [];
			$valid_signals = ICB_Plugin::all_signals();
			$in_map        = isset( $input['category_mapping'] ) && is_array( $input['category_mapping'] ) ? $input['category_mapping'] : [];
			foreach ( ICB_Plugin::all_categories() as $cat ) {
				$selected = isset( $in_map[ $cat ] ) ? (array) $in_map[ $cat ] : [];
				$mapping[ $cat ] = array_values( array_intersect( $valid_signals, array_map( 'sanitize_key', $selected ) ) );
			}
			$out['category_mapping'] = $mapping;
		}

		if ( ! isset( $out['category_mapping'] ) || ! is_array( $out['category_mapping'] ) ) {
			$out['category_mapping'] = ICB_Plugin::default_mapping();
		}
		return $out;
	}

	private function render_audit_standalone() {
		echo '<style>
			#wpcontent, #wpbody-content { padding-left: 0 !important; }
			#adminmenumain, #adminmenuback, #adminmenuwrap, #wpadminbar, #wpfooter { display: none !important; }
			html.wp-toolbar { padding-top: 0 !important; }
			#wpbody-content { margin: 0 !important; }
			.wrap { max-width: 920px; margin: 24px auto; }
			@media print {
				body { background: #fff !important; }
				.wrap { margin: 0; max-width: 100%; }
			}
		</style>';
		echo '<div class="wrap">';
		ICB_Audit::render();
		echo '</div>';
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_inpulsia-consent-bridge' !== $hook ) {
			return;
		}
		wp_add_inline_style( 'common', '
			.icb-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04);max-width:980px;margin-top:16px}
			.icb-card h2{margin-top:0}
			.icb-row{margin-bottom:18px}
			.icb-row label.icb-lbl{display:block;font-weight:600;margin-bottom:6px}
			.icb-btn{background:#2271b1;border-color:#2271b1;color:#fff}
			.icb-btn:hover{background:#185a8a;border-color:#185a8a;color:#fff}
			.icb-help{color:#646970;font-size:12px;margin-top:4px}
			.icb-status-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:12px}
			.icb-sig{display:flex;justify-content:space-between;padding:8px 12px;border-radius:4px;background:#f6f7f7;font-family:Menlo,monospace;font-size:12px}
			.icb-sig .v{font-weight:700}
			.icb-sig.granted .v{color:#1e7c1e}
			.icb-sig.denied .v{color:#b32d2e}
			.icb-sig.unknown .v{color:#646970}
			.icb-integrations{display:flex;gap:8px;flex-wrap:wrap}
			.icb-pill{display:inline-block;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600}
			.icb-pill.on{background:#edf7ed;color:#1e7c1e}
			.icb-pill.off{background:#f0f0f1;color:#646970}
			.icb-order-ok{color:#1e7c1e;font-weight:600}
			.icb-order-bad{color:#b32d2e;font-weight:600}
			.icb-tabs{display:flex;gap:4px;border-bottom:1px solid #dcdcde;margin-bottom:16px;flex-wrap:wrap}
			.icb-tab{padding:10px 16px;cursor:pointer;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0}
			.icb-tab.active{background:#fff;border-color:#dcdcde;font-weight:600}
			.icb-map{width:100%;border-collapse:collapse}
			.icb-map th,.icb-map td{padding:8px;border-bottom:1px solid #dcdcde;text-align:left;font-size:13px}
			.icb-map th{background:#f6f7f7}
			.icb-map td label{display:inline-block;margin-right:12px;font-family:Menlo,monospace;font-size:12px}
			.icb-diag-list{list-style:none;padding:0;margin:0}
			.icb-diag-list li{display:flex;align-items:flex-start;gap:12px;padding:12px;border:1px solid #dcdcde;border-radius:6px;margin-bottom:8px;background:#fff}
			.icb-diag-list li.ok{border-left:4px solid #1e7c1e}
			.icb-diag-list li.warning{border-left:4px solid #dba617}
			.icb-diag-list li.error{border-left:4px solid #b32d2e}
			.icb-diag-icon{font-size:18px;font-weight:700;width:24px;text-align:center}
			.icb-diag-icon.ok{color:#1e7c1e}
			.icb-diag-icon.warning{color:#dba617}
			.icb-diag-icon.error{color:#b32d2e}
			.icb-diag-body{flex:1}
			.icb-diag-title{font-weight:600}
			.icb-diag-value{font-family:Menlo,monospace;font-size:12px;color:#646970;margin-top:2px}
			.icb-diag-hint{font-size:12px;color:#646970;margin-top:4px;font-style:italic}
			.icb-kpis{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px}
			.icb-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 24px;min-width:140px;text-align:center}
			.icb-kpi b{display:block;font-size:28px;color:#2271b1;line-height:1.1;margin-bottom:4px}
			.icb-kpi span{font-size:12px;color:#646970}
		' );

		wp_add_inline_script( 'jquery', '
		jQuery(function($){
			$("#icb-purge-btn").on("click", function(){
				var btn = $(this);
				var result = $("#icb-purge-result");
				btn.prop("disabled", true).text("' . esc_js( __( 'Vaciando...', 'inpulsia-consent-bridge' ) ) . '");
				result.hide();
				$.post(ajaxurl, {
					action: "icb_purge_cache",
					nonce: "' . esc_js( wp_create_nonce( 'icb_status' ) ) . '"
				}, function(res){
					btn.prop("disabled", false).text("' . esc_js( __( 'Vaciar caché ahora', 'inpulsia-consent-bridge' ) ) . '");
					if (res.success) {
						var purged = res.data.purged || [];
						var msg = purged.length
							? "✓ ' . esc_js( __( 'Purgado:', 'inpulsia-consent-bridge' ) ) . ' " + purged.join(", ")
							: "✓ ' . esc_js( __( 'Transients propios limpiados. No se detectaron plugins de caché.', 'inpulsia-consent-bridge' ) ) . '";
						result.css("color", "#1e7c1e").text(msg).show();
					} else {
						result.css("color", "#b32d2e").text("' . esc_js( __( 'Error al purgar.', 'inpulsia-consent-bridge' ) ) . '").show();
					}
				}).fail(function(){
					btn.prop("disabled", false).text("' . esc_js( __( 'Vaciar caché ahora', 'inpulsia-consent-bridge' ) ) . '");
					result.css("color","#b32d2e").text("' . esc_js( __( 'Error de conexión.', 'inpulsia-consent-bridge' ) ) . '").show();
				});
			});
		});
		' );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['icb_audit'] ) ) {
			$this->render_audit_standalone();
			return;
		}
		$s              = ICB_Plugin::get_settings();
		$cmplz_conflict = ICB_Plugin::complianz_has_consent_mode();
		$home           = add_query_arg( 'icb_status', '1', home_url( '/' ) );
		$stape          = ICB_Plugin::detect_stape();
		$cmplz_active   = ICB_Plugin::complianz_active();
		$sk_active      = ICB_Plugin::sitekit_active();
		$mapping        = isset( $s['category_mapping'] ) && is_array( $s['category_mapping'] ) ? $s['category_mapping'] : ICB_Plugin::default_mapping();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Inpulsia Consent v2', 'inpulsia-consent-bridge' ); ?></h1>

			<div class="icb-card">
				<div class="icb-tabs">
					<div class="icb-tab active" data-tab="settings"><?php esc_html_e( 'Ajustes', 'inpulsia-consent-bridge' ); ?></div>
					<div class="icb-tab" data-tab="mapping"><?php esc_html_e( 'Mapeo', 'inpulsia-consent-bridge' ); ?></div>
					<div class="icb-tab" data-tab="ecommerce"><?php esc_html_e( 'eCommerce', 'inpulsia-consent-bridge' ); ?></div>
					<div class="icb-tab" data-tab="sgtm">sGTM</div>
					<div class="icb-tab" data-tab="status"><?php esc_html_e( 'Estado en vivo', 'inpulsia-consent-bridge' ); ?></div>
					<div class="icb-tab" data-tab="diagnostics"><?php esc_html_e( 'Diagnóstico', 'inpulsia-consent-bridge' ); ?></div>
					<div class="icb-tab" data-tab="health"><?php esc_html_e( 'Salud', 'inpulsia-consent-bridge' ); ?></div>
					<div class="icb-tab" data-tab="audit"><?php esc_html_e( 'Auditor', 'inpulsia-consent-bridge' ); ?></div>
				</div>

				<div class="icb-pane" data-pane="settings">
					<form method="post" action="options.php">
						<?php settings_fields( 'icb_group' ); ?>
						<input type="hidden" name="<?php echo esc_attr( ICB_OPTION ); ?>[icb_section]" value="settings" />

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Activar puente de consent', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?> /> <?php esc_html_e( 'Inyectar Consent Mode v2 en el frontend', 'inpulsia-consent-bridge' ); ?></label>
						</div>

						<div class="icb-row">
							<label class="icb-lbl" for="icb_region"><?php esc_html_e( 'Región', 'inpulsia-consent-bridge' ); ?></label>
							<select id="icb_region" name="<?php echo esc_attr( ICB_OPTION ); ?>[region]">
								<option value="EEA" <?php selected( $s['region'], 'EEA' ); ?>>EEA</option>
								<option value="Global" <?php selected( $s['region'], 'Global' ); ?>>Global</option>
							</select>
						</div>

						<div class="icb-row">
							<label class="icb-lbl" for="icb_wait"><?php esc_html_e( 'wait_for_update (ms)', 'inpulsia-consent-bridge' ); ?></label>
							<input type="number" id="icb_wait" min="0" max="5000" step="50" name="<?php echo esc_attr( ICB_OPTION ); ?>[wait_for_update]" value="<?php echo esc_attr( $s['wait_for_update'] ); ?>" />
						</div>

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Forzar unblock de GTM', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[force_gtm_unblock]" value="1" <?php checked( $s['force_gtm_unblock'], 1 ); ?> /> <?php esc_html_e( 'Quitar type="text/plain" y clases cmplz-* del script de GTM/gtag.', 'inpulsia-consent-bridge' ); ?></label>
						</div>

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Sincronizar consent con otros píxeles', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[meta_pixel_consent]" value="1" <?php checked( $s['meta_pixel_consent'], 1 ); ?> /> Meta Pixel (fbq consent grant/revoke)</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[tiktok_pixel_consent]" value="1" <?php checked( $s['tiktok_pixel_consent'], 1 ); ?> /> TikTok Pixel (ttq enable/disable cookie)</label>
							<p class="icb-help"><?php esc_html_e( 'Si el píxel está cargado en la web, se actualiza junto a Google. Si no, no afecta.', 'inpulsia-consent-bridge' ); ?></p>
						</div>

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Registro de salud', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[health_logging]" value="1" <?php checked( $s['health_logging'], 1 ); ?> /> <?php esc_html_e( 'Guardar decisiones de consent anónimas para el dashboard de salud.', 'inpulsia-consent-bridge' ); ?></label>
						</div>

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Modo debug', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[debug]" value="1" <?php checked( $s['debug'], 1 ); ?> /> <?php esc_html_e( 'Logs en consola con prefijo [ICB].', 'inpulsia-consent-bridge' ); ?></label>
						</div>

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Integraciones detectadas', 'inpulsia-consent-bridge' ); ?></label>
							<div class="icb-integrations">
								<span class="icb-pill <?php echo $cmplz_active ? 'on' : 'off'; ?>">Complianz <?php echo $cmplz_active ? '✓' : '×'; ?></span>
								<span class="icb-pill <?php echo $sk_active ? 'on' : 'off'; ?>">Site Kit <?php echo $sk_active ? '✓' : '×'; ?></span>
								<span class="icb-pill <?php echo class_exists( 'WooCommerce' ) ? 'on' : 'off'; ?>">WooCommerce <?php echo class_exists( 'WooCommerce' ) ? '✓' : '×'; ?></span>
								<span class="icb-pill <?php echo ! empty( $stape ) ? 'on' : 'off'; ?>">Stape <?php echo ! empty( $stape ) ? '✓' : '×'; ?></span>
								<?php $conflicts = ICB_Plugin::detect_conflicting_trackers(); foreach ( $conflicts as $name => $type ) : ?>
									<span class="icb-pill" style="background:#fcf0e0;color:#a06000" title="<?php esc_attr_e( 'Otro tracker eCommerce', 'inpulsia-consent-bridge' ); ?>"><?php echo esc_html( $name ); ?></span>
								<?php endforeach; ?>
							</div>
						</div>

						<?php if ( $cmplz_conflict ) : ?>
							<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Conflicto detectado:', 'inpulsia-consent-bridge' ); ?></strong> <?php esc_html_e( 'Complianz tiene Google Consent Mode activado.', 'inpulsia-consent-bridge' ); ?></p></div>
						<?php endif; ?>

						<p><button type="submit" class="button icb-btn"><?php esc_html_e( 'Guardar cambios', 'inpulsia-consent-bridge' ); ?></button></p>
					</form>

					<?php
					$cache_systems  = ICB_Cache::detect();
					$last_purge     = ICB_Cache::last_purge_time();
					$cache_stale    = ICB_Cache::cache_may_be_stale();
					?>
					<hr style="margin:24px 0">
					<div class="icb-row">
						<label class="icb-lbl"><?php esc_html_e( 'Gestión de caché', 'inpulsia-consent-bridge' ); ?></label>
						<?php if ( ! empty( $cache_systems ) ) : ?>
							<p style="margin:0 0 8px;font-size:13px;color:#646970">
								<?php esc_html_e( 'Plugins de caché detectados:', 'inpulsia-consent-bridge' ); ?>
								<strong><?php echo esc_html( implode( ', ', $cache_systems ) ); ?></strong>
							</p>
						<?php else : ?>
							<p style="margin:0 0 8px;font-size:13px;color:#646970"><?php esc_html_e( 'No se detectaron plugins de caché. La caché del servidor (Cloudflare, Kinsta, Nginx) debe purgarse manualmente desde el panel del hosting.', 'inpulsia-consent-bridge' ); ?></p>
						<?php endif; ?>
						<?php if ( $cache_stale ) : ?>
							<div class="notice notice-warning inline" style="margin:0 0 10px"><p>
								<?php esc_html_e( 'El plugin se ha actualizado pero la caché puede estar sirviendo el JS anterior. Pulsa "Vaciar caché ahora" para asegurarte de que los visitantes reciben la versión nueva.', 'inpulsia-consent-bridge' ); ?>
							</p></div>
						<?php elseif ( $last_purge > 0 ) : ?>
							<p style="font-size:12px;color:#1e7c1e;margin:0 0 8px">
								✓ <?php printf( esc_html__( 'Última purga: %s', 'inpulsia-consent-bridge' ), esc_html( human_time_diff( $last_purge ) . ' ' . __( 'atrás', 'inpulsia-consent-bridge' ) ) ); ?>
							</p>
						<?php endif; ?>
						<button id="icb-purge-btn" class="button" type="button"><?php esc_html_e( 'Vaciar caché ahora', 'inpulsia-consent-bridge' ); ?></button>
						<span id="icb-purge-result" style="margin-left:12px;font-size:13px;display:none"></span>
						<p class="icb-help"><?php esc_html_e( 'Al guardar ajustes la caché se purga automáticamente. Usa este botón si actualizaste el plugin manualmente o si los cambios no llegan a los visitantes.', 'inpulsia-consent-bridge' ); ?></p>
					</div>
				</div>

				<div class="icb-pane" data-pane="mapping" style="display:none">
					<h2><?php esc_html_e( '¿Qué es el mapeo de categorías?', 'inpulsia-consent-bridge' ); ?></h2>
					<p><?php esc_html_e( 'Cuando un usuario acepta o rechaza cookies en tu banner (Complianz), elige por categorías: marketing, estadísticas o preferencias. Google, en cambio, usa su propio sistema de "señales" (como ad_storage o analytics_storage) para saber qué puede hacer y qué no.', 'inpulsia-consent-bridge' ); ?></p>
					<p><?php esc_html_e( 'Este mapeo es la traducción: le dice a Google "cuando el usuario acepta marketing, activa estas señales".', 'inpulsia-consent-bridge' ); ?></p>

					<h3><?php esc_html_e( '¿Qué significa cada señal?', 'inpulsia-consent-bridge' ); ?></h3>
					<ul style="list-style:disc;margin-left:20px;color:#646970;font-size:13px">
						<li><strong>ad_storage</strong> — <?php esc_html_e( 'Permite guardar cookies de publicidad (Google Ads, remarketing).', 'inpulsia-consent-bridge' ); ?></li>
						<li><strong>ad_user_data</strong> — <?php esc_html_e( 'Permite enviar datos del usuario a Google con fines publicitarios.', 'inpulsia-consent-bridge' ); ?></li>
						<li><strong>ad_personalization</strong> — <?php esc_html_e( 'Permite mostrar anuncios personalizados (remarketing, audiencias similares).', 'inpulsia-consent-bridge' ); ?></li>
						<li><strong>analytics_storage</strong> — <?php esc_html_e( 'Permite guardar cookies de analítica (Google Analytics 4).', 'inpulsia-consent-bridge' ); ?></li>
						<li><strong>functionality_storage</strong> — <?php esc_html_e( 'Permite guardar cookies de funcionalidad (idioma, login, preferencias).', 'inpulsia-consent-bridge' ); ?></li>
						<li><strong>personalization_storage</strong> — <?php esc_html_e( 'Permite guardar cookies de personalización de contenido.', 'inpulsia-consent-bridge' ); ?></li>
					</ul>

					<div style="background:#f0f6ff;border:1px solid #c3daf5;border-radius:6px;padding:12px 16px;margin:12px 0;font-size:13px">
						<strong>💡 <?php esc_html_e( '¿Cuándo cambiar el mapeo?', 'inpulsia-consent-bridge' ); ?></strong><br>
						<?php esc_html_e( 'La configuración por defecto funciona para el 95% de los sitios. Solo necesitas cambiarla si:', 'inpulsia-consent-bridge' ); ?>
						<ul style="margin:6px 0 0 20px;list-style:disc">
							<li><?php esc_html_e( 'Tu banner de Complianz agrupa las categorías de forma diferente a lo habitual.', 'inpulsia-consent-bridge' ); ?></li>
							<li><?php esc_html_e( 'Un auditor o consultor de privacidad te pide un mapeo específico.', 'inpulsia-consent-bridge' ); ?></li>
							<li><?php esc_html_e( 'Quieres que analytics_storage se active también con marketing (para no perder datos de GA4 si el usuario solo acepta marketing).', 'inpulsia-consent-bridge' ); ?></li>
						</ul>
					</div>

					<form method="post" action="options.php">
						<?php settings_fields( 'icb_group' ); ?>
						<input type="hidden" name="<?php echo esc_attr( ICB_OPTION ); ?>[icb_section]" value="mapping" />
						<table class="icb-map">
							<tr><th><?php esc_html_e( 'Categoría', 'inpulsia-consent-bridge' ); ?></th><th><?php esc_html_e( 'Señales Google que activa', 'inpulsia-consent-bridge' ); ?></th></tr>
							<?php foreach ( ICB_Plugin::all_categories() as $cat ) :
								$selected = isset( $mapping[ $cat ] ) ? (array) $mapping[ $cat ] : [];
							?>
								<tr>
									<th><?php echo esc_html( ucfirst( $cat ) ); ?></th>
									<td>
										<?php foreach ( ICB_Plugin::all_signals() as $sig ) :
											if ( $sig === 'security_storage' ) continue;
											$checked = in_array( $sig, $selected, true );
										?>
											<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[category_mapping][<?php echo esc_attr( $cat ); ?>][]" value="<?php echo esc_attr( $sig ); ?>" <?php checked( $checked ); ?> /> <?php echo esc_html( $sig ); ?></label>
										<?php endforeach; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
						<p>
							<button type="submit" class="button icb-btn"><?php esc_html_e( 'Guardar mapeo', 'inpulsia-consent-bridge' ); ?></button>
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'icb_reset_mapping', '1' ), 'icb_reset_mapping' ) ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( '¿Restaurar mapeo por defecto?', 'inpulsia-consent-bridge' ) ); ?>');"><?php esc_html_e( 'Restaurar por defecto', 'inpulsia-consent-bridge' ); ?></a>
						</p>
					</form>
				</div>

				<div class="icb-pane" data-pane="ecommerce" style="display:none">
					<?php
					$wc        = ICB_Woocommerce::is_woocommerce();
					$conflicts = ICB_Plugin::detect_conflicting_trackers();
					?>
					<?php if ( ! $wc ) : ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce no detectado. Esta pestaña solo es funcional con WooCommerce activo.', 'inpulsia-consent-bridge' ); ?></p></div>
					<?php endif; ?>
					<?php if ( ! empty( $conflicts ) && ! empty( $s['wc_events'] ) ) : ?>
						<div class="notice notice-warning inline"><p>
							<strong><?php esc_html_e( 'Riesgo de eventos duplicados:', 'inpulsia-consent-bridge' ); ?></strong>
							<?php echo esc_html( implode( ', ', array_keys( $conflicts ) ) ); ?>
							<?php esc_html_e( 'también disparan eventos eCommerce. Desactiva uno para evitar doble registro en GA4.', 'inpulsia-consent-bridge' ); ?>
						</p></div>
					<?php endif; ?>
					<p><?php esc_html_e( 'Inyecta eventos GA4 estándar (view_item, add_to_cart, purchase…) en el dataLayer desde WooCommerce. Sustituye a PixelYourSite Free para tracking básico.', 'inpulsia-consent-bridge' ); ?></p>
					<form method="post" action="options.php">
						<?php settings_fields( 'icb_group' ); ?>
						<input type="hidden" name="<?php echo esc_attr( ICB_OPTION ); ?>[icb_section]" value="ecommerce" />

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Activar eventos WooCommerce', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[wc_events]" value="1" <?php checked( $s['wc_events'], 1 ); ?> <?php disabled( ! $wc ); ?> /> <?php esc_html_e( 'Inyectar eventos GA4 eCommerce automáticamente', 'inpulsia-consent-bridge' ); ?></label>
						</div>

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Eventos a disparar', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[wc_send_view_item]" value="1" <?php checked( $s['wc_send_view_item'], 1 ); ?> /> view_item · view_item_list</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[wc_send_cart_events]" value="1" <?php checked( $s['wc_send_cart_events'], 1 ); ?> /> add_to_cart · remove_from_cart · view_cart · begin_checkout</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[wc_send_purchase]" value="1" <?php checked( $s['wc_send_purchase'], 1 ); ?> /> purchase (en la página de gracias)</label>
							<p class="icb-help"><?php esc_html_e( 'Cada evento incluye items con item_id, item_name, price, quantity, item_category y currency según estándar GA4.', 'inpulsia-consent-bridge' ); ?></p>
						</div>

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Meta Pixel — enviar eventos de compra', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[meta_pixel_events]" value="1" <?php checked( $s['meta_pixel_events'] ?? 0, 1 ); ?> <?php disabled( ! $wc ); ?> /> <?php esc_html_e( 'Disparar también eventos a Meta Pixel (ViewContent, AddToCart, InitiateCheckout, Purchase)', 'inpulsia-consent-bridge' ); ?></label>
							<p class="icb-help"><?php esc_html_e( 'Solo actúa si ya tienes el Meta Pixel cargado en la web (por tu tema, GTM o PixelYourSite). El plugin no carga el píxel, solo le envía los eventos. El evento Purchase incluye event_id (nº de pedido) para deduplicación futura con CAPI.', 'inpulsia-consent-bridge' ); ?></p>
							<?php
							$pys_active = defined( 'PYS_FREE_VERSION' ) || defined( 'PYS_VERSION' ) || defined( 'PYS_PRO_VERSION' ) || class_exists( 'PYS' ) || class_exists( 'PixelYourSitePro\\Plugin' );
							if ( $pys_active && ! empty( $s['meta_pixel_events'] ) ) : ?>
							<div class="notice notice-warning inline" style="margin-top:8px"><p><?php esc_html_e( 'PixelYourSite detectado. Si PYS también dispara ViewContent/Purchase a Meta, tendrás eventos duplicados. Desactiva los eventos de Meta en PYS o deja solo el plugin activo.', 'inpulsia-consent-bridge' ); ?></p></div>
							<?php endif; ?>
						</div>

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Google Enhanced Conversions', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[enhanced_conversions]" value="1" <?php checked( $s['enhanced_conversions'] ?? 0, 1 ); ?> <?php disabled( ! $wc ); ?> /> <?php esc_html_e( 'Enviar datos del comprador hasheados a Google Ads para mejorar la atribución', 'inpulsia-consent-bridge' ); ?></label>
							<div style="background:#f0f6ff;border:1px solid #c3daf5;border-radius:6px;padding:12px 16px;margin:8px 0;font-size:13px">
								<strong>💡 <?php esc_html_e( '¿Qué hace exactamente?', 'inpulsia-consent-bridge' ); ?></strong><br>
								<?php esc_html_e( 'En la página de gracias, toma el email, teléfono y nombre del comprador, los convierte en un código cifrado irreversible (SHA-256) y los envía a Google. Google cruza ese hash con los usuarios logueados en Chrome/Gmail y puede atribuir la conversión aunque la cookie no llegara. Resultado: ~10-15% más de conversiones atribuidas en Google Ads.', 'inpulsia-consent-bridge' ); ?><br><br>
								<strong>⚙️ <?php esc_html_e( 'Requisito en Google Ads:', 'inpulsia-consent-bridge' ); ?></strong>
								<?php esc_html_e( 'Ve a Google Ads → Herramientas → Conversiones → Configuración → Conversiones mejoradas → Activar. Sin esto, Google recibe los datos pero no los procesa.', 'inpulsia-consent-bridge' ); ?><br><br>
								<strong>🔒 <?php esc_html_e( 'RGPD:', 'inpulsia-consent-bridge' ); ?></strong>
								<?php esc_html_e( 'Los datos solo se envían si ad_user_data está en "granted" (el usuario aceptó cookies de marketing). Nunca se envía el email en claro.', 'inpulsia-consent-bridge' ); ?>
							</div>
							<p class="icb-help"><?php esc_html_e( 'Para GTM: el evento "purchase" en el dataLayer incluye un campo "user_data" con los hashes. Crea una variable JavaScript en GTM apuntando a "icbUserData" para usarla en tus tags.', 'inpulsia-consent-bridge' ); ?></p>
						</div>

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Compatibilidad', 'inpulsia-consent-bridge' ); ?></label>
							<p class="icb-help">
								<?php esc_html_e( 'Si tienes PixelYourSite o Stape Conversion Tracking activos, podrías estar disparando eventos GA4 duplicados. Desactiva uno de los dos para esos eventos.', 'inpulsia-consent-bridge' ); ?>
							</p>
						</div>

						<p><button type="submit" class="button icb-btn" <?php disabled( ! $wc ); ?>><?php esc_html_e( 'Guardar', 'inpulsia-consent-bridge' ); ?></button></p>
					</form>
				</div>

				<div class="icb-pane" data-pane="sgtm" style="display:none">
					<h2><?php esc_html_e( '¿Qué es Server-Side GTM?', 'inpulsia-consent-bridge' ); ?></h2>
					<p><?php esc_html_e( 'Normalmente, Google Tag Manager se carga desde los servidores de Google (googletagmanager.com). Los bloqueadores de anuncios (AdBlock, uBlock, Brave…) detectan esa dirección y bloquean el script. Resultado: pierdes entre un 25% y un 35% de los datos de analítica y conversiones.', 'inpulsia-consent-bridge' ); ?></p>
					<p><?php esc_html_e( 'Con Server-Side GTM (sGTM), el script se carga desde un subdominio tuyo (ej: gtm.tudominio.com) que tú configuras en Stape.io. Los bloqueadores no lo reconocen como tracker, así que los datos llegan.', 'inpulsia-consent-bridge' ); ?></p>

					<div style="background:#f0f6ff;border:1px solid #c3daf5;border-radius:6px;padding:12px 16px;margin:12px 0;font-size:13px">
						<strong>💡 <?php esc_html_e( '¿Necesito esto?', 'inpulsia-consent-bridge' ); ?></strong><br>
						<?php esc_html_e( 'Si inviertes en campañas de pago (Google Ads, Meta Ads) y necesitas medir conversiones con precisión, sí. Si tu web solo tiene Analytics básico y no haces campañas, puedes dejarlo desactivado.', 'inpulsia-consent-bridge' ); ?>
					</div>

					<form method="post" action="options.php">
						<?php settings_fields( 'icb_group' ); ?>
						<input type="hidden" name="<?php echo esc_attr( ICB_OPTION ); ?>[icb_section]" value="sgtm" />

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Activar sGTM', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[sgtm_enabled]" value="1" <?php checked( $s['sgtm_enabled'], 1 ); ?> /> <?php esc_html_e( 'Activar la carga de GTM desde mi servidor (requiere cuenta en Stape.io)', 'inpulsia-consent-bridge' ); ?></label>
						</div>

						<div class="icb-row">
							<label class="icb-lbl" for="icb_sgtm_domain"><?php esc_html_e( 'Dominio sGTM', 'inpulsia-consent-bridge' ); ?></label>
							<input type="text" id="icb_sgtm_domain" class="regular-text" name="<?php echo esc_attr( ICB_OPTION ); ?>[sgtm_domain]" value="<?php echo esc_attr( $s['sgtm_domain'] ); ?>" placeholder="gtm.tudominio.com" />
							<button type="button" class="button" id="icb-test-sgtm" style="margin-left:8px"><?php esc_html_e( 'Test conexión', 'inpulsia-consent-bridge' ); ?></button>
							<span id="icb-sgtm-test-result" style="margin-left:8px;font-size:12px"></span>
							<p class="icb-help"><?php esc_html_e( 'El subdominio que configuraste en Stape.io (sin https://). Ejemplo: gtm.tudominio.com. Lo encontrarás en tu panel de Stape → Container → Custom domain.', 'inpulsia-consent-bridge' ); ?></p>
						</div>

						<div class="icb-row">
							<label class="icb-lbl" for="icb_sgtm_cid"><?php esc_html_e( 'Container ID', 'inpulsia-consent-bridge' ); ?></label>
							<input type="text" id="icb_sgtm_cid" class="regular-text" name="<?php echo esc_attr( ICB_OPTION ); ?>[sgtm_container_id]" value="<?php echo esc_attr( $s['sgtm_container_id'] ); ?>" placeholder="GTM-XXXXXX" />
							<p class="icb-help"><?php esc_html_e( 'El ID de tu contenedor de Google Tag Manager. Tiene formato GTM-XXXXXXX. Lo encontrarás en tagmanager.google.com → tu contenedor → parte superior de la pantalla.', 'inpulsia-consent-bridge' ); ?></p>
						</div>

						<div class="icb-row">
							<label class="icb-lbl"><?php esc_html_e( 'Cargar GTM via sGTM', 'inpulsia-consent-bridge' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( ICB_OPTION ); ?>[sgtm_load_gtm]" value="1" <?php checked( $s['sgtm_load_gtm'], 1 ); ?> /> <?php esc_html_e( 'Inyectar snippet GTM apuntando al subdominio sGTM en lugar de googletagmanager.com', 'inpulsia-consent-bridge' ); ?></label>
							<p class="icb-help"><?php esc_html_e( 'Esto hace que el código de GTM se cargue desde tu subdominio en vez de desde Google. Es lo que evita que los bloqueadores lo bloqueen.', 'inpulsia-consent-bridge' ); ?></p>
							<p class="icb-help"><strong>⚠️ <?php esc_html_e( 'Importante:', 'inpulsia-consent-bridge' ); ?></strong> <?php esc_html_e( 'Si ya tienes Site Kit u otro plugin cargando GTM/gtag, desactiva esa carga en el otro plugin para evitar que el script se cargue dos veces. Este plugin no lo desactiva automáticamente.', 'inpulsia-consent-bridge' ); ?></p>
						</div>

						<?php if ( ICB_Plugin::stape_is_active() ) : ?>
							<div class="notice notice-info inline"><p><?php esc_html_e( 'Plugin Stape Conversion Tracking detectado. El consent ya se propagará a tus eventos eCommerce.', 'inpulsia-consent-bridge' ); ?></p></div>
						<?php endif; ?>

						<p><button type="submit" class="button icb-btn"><?php esc_html_e( 'Guardar', 'inpulsia-consent-bridge' ); ?></button></p>
					</form>
				</div>

				<div class="icb-pane" data-pane="status" style="display:none">
					<h2><?php esc_html_e( '¿Qué es el Estado en vivo?', 'inpulsia-consent-bridge' ); ?></h2>
					<p><?php esc_html_e( 'Esta pantalla muestra el estado real del consentimiento en tu web, tal como lo ve Google. Cada vez que alguien visita tu sitio, el plugin envía una foto del estado aquí.', 'inpulsia-consent-bridge' ); ?></p>

					<div style="background:#f0f6ff;border:1px solid #c3daf5;border-radius:6px;padding:12px 16px;margin:12px 0;font-size:13px">
						<strong><?php esc_html_e( '¿Cómo leer los resultados?', 'inpulsia-consent-bridge' ); ?></strong>
						<ul style="margin:6px 0 0 20px;list-style:disc">
							<li><span style="color:#1e7c1e;font-weight:600">granted</span> — <?php esc_html_e( 'El usuario aceptó esa categoría. Google puede usar esos datos.', 'inpulsia-consent-bridge' ); ?></li>
							<li><span style="color:#b32d2e;font-weight:600">denied</span> — <?php esc_html_e( 'El usuario rechazó o aún no ha decidido. Google NO usa esos datos (correcto para RGPD).', 'inpulsia-consent-bridge' ); ?></li>
							<li><span style="color:#646970;font-weight:600">—</span> — <?php esc_html_e( 'Sin datos aún. Visita tu web en otra pestaña para generar un snapshot.', 'inpulsia-consent-bridge' ); ?></li>
						</ul>
					</div>

					<p style="font-size:13px;color:#646970"><?php esc_html_e( 'Nota: si estás logueado como admin, Site Kit puede excluirte del tracking. Prueba en una ventana de incógnito o cierra sesión para un test realista.', 'inpulsia-consent-bridge' ); ?></p>

					<p>
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" class="button icb-btn"><?php esc_html_e( 'Abrir home en otra pestaña', 'inpulsia-consent-bridge' ); ?></a>
						<button type="button" class="button" id="icb-refresh"><?php esc_html_e( 'Refrescar ahora', 'inpulsia-consent-bridge' ); ?></button>
					</p>
					<div class="icb-status-grid">
						<?php foreach ( ICB_Plugin::all_signals() as $sig ) : ?>
							<div class="icb-sig unknown" data-sig="<?php echo esc_attr( $sig ); ?>"><span><?php echo esc_html( $sig ); ?></span><span class="v">—</span></div>
						<?php endforeach; ?>
					</div>
					<p class="icb-help" id="icb-status-meta"><?php esc_html_e( 'Esperando datos del frontend…', 'inpulsia-consent-bridge' ); ?></p>
					<details style="margin-top:12px">
						<summary style="cursor:pointer;color:#646970;font-size:12px"><?php esc_html_e( 'Ver JSON crudo', 'inpulsia-consent-bridge' ); ?></summary>
						<pre id="icb-raw" style="background:#1d2327;color:#a7d4ff;padding:12px;border-radius:4px;font-size:11px;max-height:300px;overflow:auto;margin-top:8px"></pre>
					</details>
				</div>

				<div class="icb-pane" data-pane="diagnostics" style="display:none">
					<?php
					$results = ICB_Diagnostics::run();
					$summary = ICB_Diagnostics::summary( $results );
					?>
					<div class="icb-kpis">
						<div class="icb-kpi"><b style="color:#1e7c1e"><?php echo (int) $summary['ok']; ?></b><span><?php esc_html_e( 'OK', 'inpulsia-consent-bridge' ); ?></span></div>
						<div class="icb-kpi"><b style="color:#dba617"><?php echo (int) $summary['warn']; ?></b><span><?php esc_html_e( 'Avisos', 'inpulsia-consent-bridge' ); ?></span></div>
						<div class="icb-kpi"><b style="color:#b32d2e"><?php echo (int) $summary['err']; ?></b><span><?php esc_html_e( 'Errores', 'inpulsia-consent-bridge' ); ?></span></div>
					</div>
					<ul class="icb-diag-list">
						<?php foreach ( $results as $r ) :
							$icon = $r['level'] === 'ok' ? '✓' : ( $r['level'] === 'warning' ? '!' : '✗' );
						?>
							<li class="<?php echo esc_attr( $r['level'] ); ?>">
								<div class="icb-diag-icon <?php echo esc_attr( $r['level'] ); ?>"><?php echo esc_html( $icon ); ?></div>
								<div class="icb-diag-body">
									<div class="icb-diag-title"><?php echo esc_html( $r['title'] ); ?></div>
									<div class="icb-diag-value"><?php echo esc_html( $r['value'] ); ?></div>
									<?php if ( $r['level'] !== 'ok' && $r['hint'] ) : ?>
										<div class="icb-diag-hint"><?php echo esc_html( $r['hint'] ); ?></div>
									<?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="icb-pane" data-pane="health" style="display:none">
					<?php $stats = ICB_Health::stats(); ?>
					<?php if ( empty( $stats['enabled'] ) ) : ?>
						<p><?php esc_html_e( 'Tabla de salud no instalada. Reactiva el plugin para crearla.', 'inpulsia-consent-bridge' ); ?></p>
					<?php elseif ( $stats['total_7d'] === 0 ) : ?>
						<p><?php esc_html_e( 'Sin datos aún. A medida que los usuarios interactúen con el banner, aparecerán métricas aquí (suele tardar 1-2 días en mostrar tendencias útiles).', 'inpulsia-consent-bridge' ); ?></p>
						<p class="icb-help"><?php printf( esc_html__( 'Total histórico (30d): %d eventos.', 'inpulsia-consent-bridge' ), (int) $stats['total_30d'] ); ?></p>
					<?php else : ?>
						<div class="icb-kpis">
							<div class="icb-kpi" title="<?php esc_attr_e( '% de visitantes que aceptaron TODAS las categorías', 'inpulsia-consent-bridge' ); ?>"><b><?php echo $stats['accept_rate'] !== null ? esc_html( $stats['accept_rate'] . '%' ) : '—'; ?></b><span><?php esc_html_e( 'Aceptación total 7d', 'inpulsia-consent-bridge' ); ?></span></div>
							<div class="icb-kpi" title="<?php esc_attr_e( '% que permitió marketing — lo relevante para Google/Meta Ads', 'inpulsia-consent-bridge' ); ?>"><b style="color:#2271b1"><?php echo isset( $stats['marketing_rate'] ) && $stats['marketing_rate'] !== null ? esc_html( $stats['marketing_rate'] . '%' ) : '—'; ?></b><span><?php esc_html_e( 'Aceptaron marketing 7d', 'inpulsia-consent-bridge' ); ?></span></div>
							<div class="icb-kpi"><b><?php echo (int) $stats['total_7d']; ?></b><span><?php esc_html_e( 'Decisiones 7d', 'inpulsia-consent-bridge' ); ?></span></div>
							<div class="icb-kpi"><b style="color:#1e7c1e"><?php echo (int) $stats['accept_7d']; ?></b><span><?php esc_html_e( 'Aceptaron todo', 'inpulsia-consent-bridge' ); ?></span></div>
							<div class="icb-kpi"><b style="color:#b32d2e"><?php echo (int) $stats['deny_7d']; ?></b><span><?php esc_html_e( 'Rechazaron todo', 'inpulsia-consent-bridge' ); ?></span></div>
							<div class="icb-kpi"><b><?php echo (int) $stats['partial_7d']; ?></b><span><?php esc_html_e( 'Parcial', 'inpulsia-consent-bridge' ); ?></span></div>
							<div class="icb-kpi"><b><?php echo $stats['avg_ttd_ms'] ? esc_html( round( $stats['avg_ttd_ms'] / 1000, 1 ) . 's' ) : '—'; ?></b><span><?php esc_html_e( 'Tiempo medio decisión', 'inpulsia-consent-bridge' ); ?></span></div>
						</div>
						<p class="icb-help"><?php esc_html_e( '"Aceptación total" = aceptaron todas las categorías. "Aceptaron marketing" = permitieron cookies de publicidad (es la métrica que determina si Google Ads y Meta pueden medir conversiones).', 'inpulsia-consent-bridge' ); ?></p>

						<h3><?php esc_html_e( 'Desglose diario (7d)', 'inpulsia-consent-bridge' ); ?></h3>
						<?php
						$max_day = 0;
						foreach ( $stats['daily'] as $d ) { $max_day = max( $max_day, (int) $d['total'] ); }
						$max_day = max( 1, $max_day );
						?>
						<table class="icb-map">
							<tr><th><?php esc_html_e( 'Día', 'inpulsia-consent-bridge' ); ?></th><th colspan="2"><?php esc_html_e( 'Distribución', 'inpulsia-consent-bridge' ); ?></th><th><?php esc_html_e( 'Total', 'inpulsia-consent-bridge' ); ?></th></tr>
							<?php foreach ( $stats['daily'] as $d ) :
								$total = (int) $d['total'];
								$acc   = (int) $d['accepts'];
								$den   = (int) $d['denies'];
								$acc_w = $total > 0 ? round( $acc / $total * 100 ) : 0;
								$den_w = $total > 0 ? round( $den / $total * 100 ) : 0;
							?>
								<tr>
									<td><?php echo esc_html( $d['d'] ); ?></td>
									<td style="width:60%">
										<div style="display:flex;height:18px;border-radius:3px;overflow:hidden;background:#f0f0f1">
											<div title="<?php echo esc_attr( $acc . ' aceptados' ); ?>" style="width:<?php echo (int) $acc_w; ?>%;background:#1e7c1e"></div>
											<div title="<?php echo esc_attr( $den . ' denegados' ); ?>" style="width:<?php echo (int) $den_w; ?>%;background:#b32d2e"></div>
										</div>
									</td>
									<td style="font-family:Menlo,monospace;font-size:11px;color:#646970;white-space:nowrap"><?php echo (int) $acc; ?>↑ / <?php echo (int) $den; ?>↓</td>
									<td><?php echo (int) $total; ?></td>
								</tr>
							<?php endforeach; ?>
						</table>

						<?php if ( ! empty( $stats['top_reject'] ) ) : ?>
							<h3><?php esc_html_e( 'Páginas con más rechazos', 'inpulsia-consent-bridge' ); ?></h3>
							<table class="icb-map">
								<tr><th>URL</th><th><?php esc_html_e( 'Rechazos', 'inpulsia-consent-bridge' ); ?></th></tr>
								<?php foreach ( $stats['top_reject'] as $row ) : ?>
									<tr><td><?php echo esc_html( $row['url'] ); ?></td><td><?php echo (int) $row['n']; ?></td></tr>
								<?php endforeach; ?>
							</table>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<div class="icb-pane" data-pane="audit" style="display:none">
					<p><?php esc_html_e( 'Genera un informe consolidado con configuración, integraciones, tests y salud. Ideal para entregar a clientes o archivar como evidencia RGPD.', 'inpulsia-consent-bridge' ); ?></p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=inpulsia-consent-bridge&icb_audit=1' ) ); ?>" target="_blank" class="button icb-btn"><?php esc_html_e( 'Abrir informe en nueva pestaña', 'inpulsia-consent-bridge' ); ?></a>
					</p>
					<p class="icb-help"><?php esc_html_e( 'Desde el informe podrás imprimir o guardar como PDF.', 'inpulsia-consent-bridge' ); ?></p>
				</div>
			</div>
		</div>

		<script>
		(function(){
			var tabs = document.querySelectorAll('.icb-tab');
			function activate(name){
				var found = false;
				tabs.forEach(function(x){
					var match = x.getAttribute('data-tab') === name;
					x.classList.toggle('active', match);
					if (match) found = true;
				});
				if (!found) { tabs[0].classList.add('active'); name = tabs[0].getAttribute('data-tab'); }
				document.querySelectorAll('.icb-pane').forEach(function(p){
					p.style.display = (p.getAttribute('data-pane') === name) ? '' : 'none';
				});
				if (history.replaceState) history.replaceState(null, '', '#' + name);
			}
			tabs.forEach(function(t){
				t.addEventListener('click', function(){ activate(t.getAttribute('data-tab')); });
			});
			var initial = (location.hash || '').replace('#','') || 'settings';
			activate(initial);
			window.addEventListener('hashchange', function(){ activate((location.hash || '').replace('#','') || 'settings'); });

			var meta  = document.getElementById('icb-status-meta');
			var AJAX  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var NONCE = <?php echo wp_json_encode( wp_create_nonce( 'icb_status' ) ); ?>;
			var lastTs = 0;

			function render(payload){
				var ics = payload.ics || {};
				document.querySelectorAll('.icb-sig').forEach(function(el){
					var k = el.getAttribute('data-sig');
					var entry = ics[k];
					var val = entry && entry.value ? entry.value : '—';
					el.classList.remove('granted','denied','unknown');
					el.classList.add(val === 'granted' ? 'granted' : (val === 'denied' ? 'denied' : 'unknown'));
					el.querySelector('.v').textContent = val;
				});
				var n = (payload.consentEvents || []).length;
				var age = payload.saved_at ? Math.max(0, Math.round(Date.now()/1000 - payload.saved_at)) : '?';
				var orderTxt = '';
				if (payload.order) {
					if (payload.order.firstEcommerceIndex === -1) {
						orderTxt = ' · sin eventos eCommerce';
					} else if (payload.order.orderOk) {
						orderTxt = ' · <span class="icb-order-ok">orden OK: consent[' + payload.order.consentIndex + '] → ' + payload.order.firstEcommerceEvent + '[' + payload.order.firstEcommerceIndex + ']</span>';
					} else {
						orderTxt = ' · <span class="icb-order-bad">CONFLICTO: ' + payload.order.firstEcommerceEvent + ' antes que consent default</span>';
					}
				}
				if (meta) meta.innerHTML = 'Eventos consent: ' + n + ' · hace ' + age + 's' + (payload.url ? ' · ' + payload.url.split('?')[0] : '') + orderTxt;
				var raw = document.getElementById('icb-raw');
				if (raw) raw.textContent = JSON.stringify({ ics: payload.ics, icsRaw: payload.icsRaw, consentEvents: payload.consentEvents, order: payload.order }, null, 2);
			}

			function poll(){
				fetch(AJAX + '?action=icb_get_snapshot&nonce=' + NONCE, { credentials:'include' })
					.then(function(r){ return r.json(); })
					.then(function(j){
						if (j && j.success && j.data && j.data.ts && j.data.ts !== lastTs) {
							lastTs = j.data.ts;
							render(j.data);
						}
					}).catch(function(){});
			}

			poll();
			setInterval(poll, 1500);
			var rb = document.getElementById('icb-refresh');
			if (rb) rb.addEventListener('click', poll);

			var testBtn = document.getElementById('icb-test-sgtm');
			if (testBtn) {
				testBtn.addEventListener('click', function(){
					var domain = (document.getElementById('icb_sgtm_domain') || {}).value || '';
					var out = document.getElementById('icb-sgtm-test-result');
					out.textContent = '⏳ Probando…'; out.style.color = '#646970';
					var fd = new FormData();
					fd.append('action', 'icb_test_sgtm');
					fd.append('nonce', NONCE);
					fd.append('domain', domain);
					fetch(AJAX, { method:'POST', body: fd, credentials:'include' })
						.then(function(r){ return r.json(); })
						.then(function(j){
							if (j && j.success && j.data && j.data.ok) {
								out.textContent = '✓ Conexión OK';
								out.style.color = '#1e7c1e';
							} else {
								var err = (j && j.data && j.data.error) || 'Error';
								out.textContent = '✗ ' + err;
								out.style.color = '#b32d2e';
							}
						})
						.catch(function(){ out.textContent = '✗ Error de red'; out.style.color = '#b32d2e'; });
				});
			}
		})();
		</script>
		<?php
	}
}
