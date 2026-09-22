<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ICB_Frontend {

	public function __construct() {
		add_action( 'wp_head', [ $this, 'inject_consent_default' ], 0 );
		add_action( 'wp_head', [ $this, 'inject_listener' ], 5 );
		add_filter( 'script_loader_tag', [ $this, 'maybe_unblock_gtm' ], 999, 3 );
		add_action( 'wp_footer', [ $this, 'inject_status_reporter' ], 99 );
		add_action( 'wp_ajax_icb_save_snapshot', [ $this, 'ajax_save_snapshot' ] );
		add_action( 'wp_ajax_nopriv_icb_save_snapshot', [ $this, 'ajax_save_snapshot' ] );
	}

	private function settings() {
		return ICB_Plugin::get_settings();
	}

	private function is_enabled() {
		$s = $this->settings();
		return ! empty( $s['enabled'] );
	}

	public function inject_consent_default() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$s       = $this->settings();
		$wait    = absint( $s['wait_for_update'] );
		$debug   = ! empty( $s['debug'] );
		$region  = $s['region'] === 'Global' ? null : 'EEA';
		$default = [
			'ad_storage'              => 'denied',
			'ad_user_data'            => 'denied',
			'ad_personalization'      => 'denied',
			'analytics_storage'       => 'denied',
			'functionality_storage'   => 'denied',
			'personalization_storage' => 'denied',
			'security_storage'        => 'granted',
			'wait_for_update'         => $wait,
		];
		if ( $region ) {
			$default['region'] = [ 'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','IS','LI','NO','GB','CH' ];
		}
		?>
<script id="icb-consent-default">
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', <?php echo wp_json_encode( $default ); ?>);
gtag('set', 'ads_data_redaction', true);
gtag('set', 'url_passthrough', true);
<?php if ( $debug ) : ?>
console.log('[ICB] consent default applied', <?php echo wp_json_encode( $default ); ?>);
<?php endif; ?>
</script>
		<?php
	}

	public function inject_listener() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$s        = $this->settings();
		$debug    = ! empty( $s['debug'] ) ? 'true' : 'false';
		$mapping  = isset( $s['category_mapping'] ) && is_array( $s['category_mapping'] ) ? $s['category_mapping'] : ICB_Plugin::default_mapping();
		$meta     = ! empty( $s['meta_pixel_consent'] ) ? 'true' : 'false';
		$tiktok   = ! empty( $s['tiktok_pixel_consent'] ) ? 'true' : 'false';
		$health   = ! empty( $s['health_logging'] ) ? 'true' : 'false';
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
<script id="icb-consent-listener">
(function(){
	var DEBUG    = <?php echo $debug; ?>;
	var MAPPING  = <?php echo wp_json_encode( $mapping ); ?>;
	var META     = <?php echo $meta; ?>;
	var TIKTOK   = <?php echo $tiktok; ?>;
	var HEALTH   = <?php echo $health; ?>;
	var AJAX     = <?php echo wp_json_encode( $ajax_url ); ?>;
	var pageStart = Date.now();
	var lastSignature = '';
	var logTimer = null;
	var pendingLog = null;
	function gtag(){window.dataLayer=window.dataLayer||[];window.dataLayer.push(arguments);}
	function log(){ if(DEBUG && window.console) console.log.apply(console, ['[ICB]'].concat([].slice.call(arguments))); }
	function isAllow(v){ return v === 'allow' || v === true || v === 'granted' || v === 1 || v === '1'; }
	function buildUpdate(consent){
		var allSignals = ['ad_storage','ad_user_data','ad_personalization','analytics_storage','functionality_storage','personalization_storage'];
		var update = {};
		allSignals.forEach(function(sig){ update[sig] = 'denied'; });
		Object.keys(MAPPING).forEach(function(cat){
			if (isAllow(consent[cat])) {
				(MAPPING[cat] || []).forEach(function(sig){
					if (sig !== 'security_storage') update[sig] = 'granted';
				});
			}
		});
		return update;
	}
	function apply(consent){
		consent = consent || {};
		var update = buildUpdate(consent);
		gtag('consent','update',update);
		log('consent update', update, 'from', consent);
		if (META && typeof window.fbq === 'function') {
			try { window.fbq('consent', update.ad_storage === 'granted' ? 'grant' : 'revoke'); log('fbq consent', update.ad_storage); } catch(e){}
		}
		if (TIKTOK && window.ttq) {
			try {
				if (update.ad_storage === 'granted' && typeof window.ttq.enableCookie === 'function') window.ttq.enableCookie();
				else if (typeof window.ttq.disableCookie === 'function') window.ttq.disableCookie();
				log('ttq cookie', update.ad_storage);
			} catch(e){}
		}
		try {
			document.dispatchEvent(new CustomEvent('icb:consent_update', { detail: { update: update, consent: consent } }));
		} catch(e){}
		// gtag is updated immediately above, but health logging is debounced so that a
		// multi-event "accept all" (Complianz fires one event per category) is recorded
		// ONCE with the final cumulative state — not as an early "partial" snapshot that
		// the server-side rate-limit would then lock in for 5 minutes.
		if (HEALTH) scheduleLog(consent, update);
	}
	function scheduleLog(consent, update){
		// Capture time-to-decision now (when the user actually decided), not after the
		// debounce delay, so the metric isn't inflated by the 700ms wait.
		pendingLog = { consent: consent, update: update, ttd: Date.now() - pageStart };
		if (logTimer) clearTimeout(logTimer);
		logTimer = setTimeout(function(){
			logTimer = null;
			if (pendingLog) logHealth(pendingLog.consent, pendingLog.update, pendingLog.ttd);
		}, 700);
	}
	function logHealth(consent, update, ttd){
		// Determine type from the actual update sent to Google (reliable regardless of category names)
		var signals = ['ad_storage','ad_user_data','ad_personalization','analytics_storage','functionality_storage','personalization_storage'];
		var granted = 0;
		signals.forEach(function(sig){ if (update[sig] === 'granted') granted++; });
		var type = granted === 0 ? 'deny_all' : (granted === signals.length ? 'accept_all' : 'partial');
		var sig = String(granted);
		if (sig === lastSignature) return;
		lastSignature = sig;
		var sessionKey = 'icb_logged_' + sig;
		try {
			if (sessionStorage.getItem(sessionKey)) return;
			sessionStorage.setItem(sessionKey, '1');
		} catch(e){}
		try {
			var fd = new FormData();
			fd.append('action', 'icb_log_event');
			fd.append('data', JSON.stringify({
				type: type,
				marketing: isAllow(consent.marketing) ? 1 : 0,
				statistics: isAllow(consent.statistics) ? 1 : 0,
				preferences: isAllow(consent.preferences) ? 1 : 0,
				time_to_decision: (typeof ttd === 'number' ? ttd : Date.now() - pageStart),
				url: location.href.split('?')[0]
			}));
			fetch(AJAX, { method:'POST', body: fd, credentials:'include', keepalive:true });
			log('health logged', type, 'granted:' + granted + '/' + signals.length);
		} catch(e){}
	}
	function toMap(arr){var m={};(arr||[]).forEach(function(c){m[c]=true;});return m;}
	function readCookies(){
		// Complianz persists the user's decision in cookies (cmplz_marketing, cmplz_statistics,
		// cmplz_preferences, cmplz_functional = "allow"/"deny"). This is the ONLY source that is
		// synchronously available on a brand-new page load (checkout thank-you page, any page
		// other than the one where the banner was answered) — it doesn't depend on Complianz's
		// own script having run yet, nor on a DOM/dataLayer event firing again for a decision
		// the visitor already made earlier in the session.
		var map = {};
		var found = false;
		try {
			var pairs = document.cookie.split(';');
			for (var i = 0; i < pairs.length; i++) {
				var kv = pairs[i].split('=');
				var k = (kv[0] || '').trim();
				var v = decodeURIComponent((kv[1] || '').trim());
				var m = k.match(/^cmplz_(marketing|statistics|preferences|functional)$/);
				if (m && isAllow(v)) { map[m[1]] = true; found = true; }
			}
		} catch(_){}
		return found ? map : null;
	}
	function readState(detail){
		// Priority 1: event detail categories array — Complianz cumulative accepted list.
		// This is the most reliable source: Complianz builds this array as each category fires.
		// cmplz_get_all_consents() can return all-false in the moment the event fires (timing issue).
		if (detail && detail.consentedCategories) return toMap(detail.consentedCategories);
		if (detail && Array.isArray(detail.categories) && detail.categories.length > 0) return toMap(detail.categories);
		// Priority 2: cmplz_get_all_consents() — only trust it if at least one category is granted.
		// If it returns all-false it means it hasn't processed the consent yet, skip it.
		if (typeof cmplz_get_all_consents === 'function') {
			try {
				var result = cmplz_get_all_consents();
				if (result && typeof result === 'object' && Object.keys(result).length > 0) {
					var hasGranted = Object.keys(result).some(function(k){ return isAllow(result[k]); });
					if (hasGranted) return result;
				}
			} catch(_){}
		}
		// Priority 3: detail as category→bool map (skip Complianz metadata keys)
		if (detail && typeof detail === 'object') {
			var map = {};
			Object.keys(detail).forEach(function(k){
				if (k === 'category' || k === 'value' || k === 'region' || k === 'categories') return;
				if (isAllow(detail[k])) map[k] = true;
			});
			if (Object.keys(map).length > 0) return map;
		}
		// Priority 4: persisted cookies — catches a returning visitor / a fresh page load
		// (e.g. the WooCommerce thank-you page) where the decision was already made earlier
		// and no banner/change event will fire again on this page.
		var fromCookies = readCookies();
		if (fromCookies) return fromCookies;
		return null; // Unknown state — caller decides whether to apply
	}
	// --- Single authoritative consent state -------------------------------------
	// All event paths (DOM events + dataLayer interception) feed into ONE state map.
	// apply() always reads from here, so a late/stale per-event callback can never
	// regress the consent or log a premature "partial". Within an accept the map only
	// grows; an explicit revoke resets it to empty.
	var consentState = {};
	var reapplyTimer = null;
	function touchState(){
		apply(consentState);                 // gtag updates immediately, health log is debounced inside apply()
		if (reapplyTimer) clearTimeout(reapplyTimer);
		reapplyTimer = setTimeout(function(){ reapplyTimer = null; apply(consentState); }, 500); // safety re-apply
	}
	function setAuthoritative(map){          // full/cumulative list from Complianz → replace
		consentState = {};
		Object.keys(map || {}).forEach(function(k){ if (map[k]) consentState[k] = true; });
		touchState();
	}
	function mergeState(map){                // incremental signals (dataLayer) → OR in
		var changed = false;
		Object.keys(map || {}).forEach(function(k){ if (map[k] && !consentState[k]) { consentState[k] = true; changed = true; } });
		if (changed) touchState();
	}
	function revokeAll(){ consentState = {}; touchState(); }

	document.addEventListener('cmplz_status_change', function(e){
		var detail = e && e.detail;
		log('cmplz_status_change', detail);
		var state = readState(detail);       // returns the cumulative accepted-categories map
		if (state) setAuthoritative(state);
	});
	document.addEventListener('cmplz_run_after_all_scripts', function(){
		log('cmplz_run_after_all_scripts');
		var state = readState(null);
		if (state) setAuthoritative(state);
	});
	document.addEventListener('cmplz_accept_marketing', function(){ var s = readState(null); if (s) setAuthoritative(s); });
	document.addEventListener('cmplz_accept_statistics', function(){ var s = readState(null); if (s) setAuthoritative(s); });
	document.addEventListener('cmplz_revoke', function(){ revokeAll(); });

	// Proactive init: on EVERY page load (not just the page where the banner was answered),
	// check whether the visitor already has a stored decision and restore it immediately.
	// Without this, a returning visitor landing on a new page (e.g. the checkout thank-you
	// page) stays on the default "denied" state for that whole page — Complianz doesn't
	// re-fire the banner or a change event for a decision already made, so only an active
	// check on load can catch it. Runs a few times as the page settles because Complianz's
	// own script may not have registered the cookie/API yet at the very first check.
	(function(){
		var attempts = [0, 100, 300, 700, 1500];
		attempts.forEach(function(delay){
			setTimeout(function(){
				if (Object.keys(consentState).length > 0) return; // already resolved, no need to keep checking
				var state = readState(null);
				if (state) { log('init check found existing consent', state); setAuthoritative(state); }
			}, delay);
		});
	})();

	// Fallback: intercept Complianz's GTM dataLayer pushes (cmplz_event_* events).
	// Some Complianz versions push to dataLayer instead of (or alongside) DOM events.
	// These are incremental (one per accepted category), so we MERGE them into the state.
	(function(){
		var dl = window.dataLayer = window.dataLayer || [];
		var _push = dl.push.bind(dl);
		var CMPLZ_CATS = ['marketing','statistics','preferences','functional'];
		function ingest(item){
			if (!item || typeof item.event !== 'string') return;
			var ev = item.event;
			if (ev === 'cmplz_revoke' || ev === 'cmplz_deny') { revokeAll(); return; }
			var cat = ev.replace('cmplz_event_','');
			if (ev !== cat && CMPLZ_CATS.indexOf(cat) !== -1) {
				var m = {}; m[cat] = true;
				log('dataLayer cmplz event caught', cat);
				mergeState(m);
			}
		}
		dl.push = function(){
			for (var i=0; i<arguments.length; i++){ ingest(arguments[i]); }
			return _push.apply(dl, arguments);
		};
		// Replay any cmplz events already in the dataLayer before we hooked
		dl.forEach(ingest);
	})();
})();
</script>
		<?php
	}

	public function maybe_unblock_gtm( $tag, $handle, $src ) {
		$s = $this->settings();
		if ( empty( $s['enabled'] ) || empty( $s['force_gtm_unblock'] ) ) {
			return $tag;
		}
		if ( ! $src || ( false === strpos( $src, 'googletagmanager.com' ) && false === strpos( $src, 'google-analytics.com' ) ) ) {
			return $tag;
		}
		$tag = preg_replace( '/\stype=([\'"])text\/plain\1/i', '', $tag );
		$tag = preg_replace_callback( '/\sclass=([\'"])([^\'"]*)\1/i', function ( $m ) {
			$classes = preg_split( '/\s+/', $m[2] );
			$classes = array_filter( $classes, function ( $c ) {
				return strpos( $c, 'cmplz-' ) !== 0;
			} );
			$new = trim( implode( ' ', $classes ) );
			return $new ? ' class=' . $m[1] . $new . $m[1] : '';
		}, $tag );
		$tag = preg_replace( '/\sdata-category=([\'"])[^\'"]*\1/i', '', $tag );
		return $tag;
	}

	public function inject_status_reporter() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
<script id="icb-status-reporter">
(function(){
	var AJAX = <?php echo wp_json_encode( $ajax_url ); ?>;
	function bool2val(b){ return b === true ? 'granted' : (b === false ? 'denied' : null); }
	function readEntries(){
		var ics = window.google_tag_data && google_tag_data.ics;
		if (!ics) return null;
		var raw = ics.entries || ics;
		var out = {};
		try {
			Object.keys(raw).forEach(function(k){
				var e = raw[k];
				if (e == null) return;
				if (typeof e === 'string') { out[k] = { value: e, raw: e }; return; }
				if (typeof e === 'boolean') { out[k] = { value: bool2val(e), raw: e }; return; }
				var val = null;
				if (Object.prototype.hasOwnProperty.call(e, 'update')) val = bool2val(e.update);
				if (val == null && Object.prototype.hasOwnProperty.call(e, 'default')) val = bool2val(e['default']);
				if (val == null && typeof e.value === 'string') val = e.value;
				out[k] = { value: val, raw: e };
			});
		} catch(_){}
		return out;
	}
	var ECOMMERCE_EVENTS = ['view_item','view_item_list','select_item','add_to_cart','remove_from_cart','view_cart','begin_checkout','add_payment_info','add_shipping_info','purchase','refund','add_to_wishlist'];
	function analyzeOrder(){
		var dl = window.dataLayer || [];
		var firstConsentIdx = -1, firstEcomIdx = -1, firstEcomName = null;
		for (var i = 0; i < dl.length; i++){
			var e = dl[i];
			if (!e) continue;
			if (firstConsentIdx === -1 && e[0] === 'consent' && e[1] === 'default') firstConsentIdx = i;
			var name = (e && e.event) || (Array.isArray(e) ? e[0] : null);
			if (firstEcomIdx === -1 && name && ECOMMERCE_EVENTS.indexOf(name) !== -1) { firstEcomIdx = i; firstEcomName = name; }
		}
		var ok = firstConsentIdx === -1 || firstEcomIdx === -1 || firstConsentIdx < firstEcomIdx;
		return {
			consentIndex: firstConsentIdx,
			firstEcommerceIndex: firstEcomIdx,
			firstEcommerceEvent: firstEcomName,
			orderOk: ok
		};
	}
	function snapshot(){
		var entries = readEntries();
		var consentEvents = (window.dataLayer || []).filter(function(x){ return x && x[0] === 'consent'; }).map(function(e){ return [].slice.call(e); });
		var rawIcs = null;
		try { rawIcs = JSON.parse(JSON.stringify(window.google_tag_data && google_tag_data.ics || null)); } catch(_){}
		return { ics: entries, icsRaw: rawIcs, consentEvents: consentEvents, order: analyzeOrder(), ts: Date.now(), url: location.href };
	}
	var SNAP_KEY = 'icb_snap_ts';
	var SNAP_TTL = 5 * 60 * 1000; // 5 minutes rate-limit for page-load sends
	function canSend(){
		try {
			var last = parseInt(sessionStorage.getItem(SNAP_KEY) || '0', 10);
			return (Date.now() - last) > SNAP_TTL;
		} catch(e){ return true; }
	}
	function markSent(){
		try { sessionStorage.setItem(SNAP_KEY, String(Date.now())); } catch(e){}
	}
	function send(force){
		if (!force && !canSend()) return;
		markSent();
		try {
			var fd = new FormData();
			fd.append('action', 'icb_save_snapshot');
			fd.append('data', JSON.stringify(snapshot()));
			fetch(AJAX, { method:'POST', body: fd, credentials:'include', keepalive:true });
		} catch(e){}
	}
	if (document.readyState === 'complete') send();
	else window.addEventListener('load', send);
	setTimeout(send, 1500);
	// After consent change always send (force=true) so admin sees updated state immediately
	document.addEventListener('cmplz_status_change', function(){ setTimeout(function(){ send(true); }, 300); });
})();
</script>
		<?php
	}

	public function ajax_save_snapshot() {
		$raw = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		if ( ! $raw || strlen( $raw ) > 30000 ) {
			wp_send_json_error( null, 400 );
		}
		$decoded = json_decode( $raw, true );
		// The endpoint is public (nopriv) by design: it reports the visitor-side consent
		// state. Only accept the exact shape our reporter sends and nothing else.
		if ( ! is_array( $decoded ) || ! isset( $decoded['ts'], $decoded['consentEvents'] ) || ! is_array( $decoded['consentEvents'] ) ) {
			wp_send_json_error( null, 400 );
		}
		$decoded = array_intersect_key( $decoded, array_flip( [ 'ics', 'icsRaw', 'consentEvents', 'order', 'ts', 'url' ] ) );
		$decoded['url']      = isset( $decoded['url'] ) ? esc_url_raw( (string) $decoded['url'] ) : '';
		$decoded['saved_at'] = time();
		set_transient( 'icb_last_snapshot', $decoded, HOUR_IN_SECONDS );
		wp_send_json_success();
	}
}
