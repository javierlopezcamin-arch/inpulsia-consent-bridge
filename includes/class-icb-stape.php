<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ICB_Stape {

	public function __construct() {
		add_action( 'wp_head', [ $this, 'inject_sgtm' ], 1 );
		add_action( 'wp_footer', [ $this, 'inject_sgtm_noscript' ], 1 );
	}

	private function settings() {
		return ICB_Plugin::get_settings();
	}

	public static function sanitize_domain( $domain ) {
		$domain = trim( (string) $domain );
		$domain = preg_replace( '#^https?://#i', '', $domain );
		$domain = rtrim( $domain, '/' );
		if ( ! preg_match( '/^[a-z0-9.\-]+\.[a-z]{2,}$/i', $domain ) ) {
			return '';
		}
		return $domain;
	}

	public static function sanitize_container_id( $id ) {
		$id = strtoupper( trim( (string) $id ) );
		if ( ! preg_match( '/^GTM-[A-Z0-9]+$/', $id ) ) {
			return '';
		}
		return $id;
	}

	public function is_configured() {
		$s = $this->settings();
		return ! empty( $s['sgtm_enabled'] ) && ! empty( $s['sgtm_domain'] ) && ! empty( $s['sgtm_container_id'] );
	}

	public function url() {
		$s = $this->settings();
		if ( ! $this->is_configured() ) {
			return '';
		}
		return 'https://' . $s['sgtm_domain'] . '/gtm.js?id=' . $s['sgtm_container_id'];
	}

	public function inject_sgtm() {
		$s = $this->settings();
		if ( empty( $s['enabled'] ) || empty( $s['sgtm_load_gtm'] ) || ! $this->is_configured() ) {
			return;
		}
		$domain = $s['sgtm_domain'];
		$cid    = $s['sgtm_container_id'];
		?>
<!-- Inpulsia Consent v2 — sGTM via Stape -->
<script id="icb-sgtm">
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;
j.src='https://<?php echo esc_js( $domain ); ?>/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo esc_js( $cid ); ?>');
</script>
		<?php
	}

	public function inject_sgtm_noscript() {
		$s = $this->settings();
		if ( empty( $s['enabled'] ) || empty( $s['sgtm_load_gtm'] ) || ! $this->is_configured() ) {
			return;
		}
		?>
<noscript><iframe src="https://<?php echo esc_attr( $s['sgtm_domain'] ); ?>/ns.html?id=<?php echo esc_attr( $s['sgtm_container_id'] ); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
		<?php
	}

	public static function test_connection( $domain ) {
		$domain = self::sanitize_domain( $domain );
		if ( ! $domain ) {
			return [ 'ok' => false, 'error' => 'Dominio inválido' ];
		}
		$url      = 'https://' . $domain . '/healthy';
		$response = wp_remote_get( $url, [ 'timeout' => 5, 'sslverify' => true ] );
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'error' => $response->get_error_message() ];
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = trim( wp_remote_retrieve_body( $response ) );
		if ( $code === 200 ) {
			return [ 'ok' => true, 'code' => $code, 'body' => substr( $body, 0, 100 ) ];
		}
		return [ 'ok' => false, 'code' => $code, 'error' => 'Respuesta inesperada' ];
	}
}
