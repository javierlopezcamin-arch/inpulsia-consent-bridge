<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ICB_Woocommerce {

	const SESSION_KEY = 'icb_pending_events';

	public function __construct() {
		$s = ICB_Plugin::get_settings();
		// Enhanced Conversions head injection hooks regardless of wc_events toggle
		if ( ! empty( $s['enabled'] ) && ! empty( $s['enhanced_conversions'] ) && self::is_woocommerce() ) {
			add_action( 'wp_head', [ $this, 'inject_enhanced_conversions_head' ], 2 );
		}
		if ( ! $this->is_active() ) {
			return;
		}
		add_action( 'woocommerce_after_single_product', [ $this, 'view_item' ] );
		add_action( 'woocommerce_after_shop_loop', [ $this, 'view_item_list' ] );
		add_action( 'woocommerce_add_to_cart', [ $this, 'add_to_cart' ], 10, 6 );
		add_action( 'woocommerce_before_cart', [ $this, 'view_cart' ] );
		add_action( 'woocommerce_before_checkout_form', [ $this, 'begin_checkout' ] );
		add_action( 'woocommerce_thankyou', [ $this, 'purchase' ], 10, 1 );
		add_action( 'wp_footer', [ $this, 'flush_pending' ], 5 );
		add_action( 'wp_footer', [ $this, 'inject_js_helpers' ], 6 );
	}

	public static function is_woocommerce() {
		return class_exists( 'WooCommerce' );
	}

	private function is_active() {
		$s = ICB_Plugin::get_settings();
		return self::is_woocommerce() && ! empty( $s['enabled'] ) && ! empty( $s['wc_events'] );
	}

	private function settings() {
		return ICB_Plugin::get_settings();
	}

	private function currency() {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
	}

	private function primary_category( $product ) {
		if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
			return '';
		}
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$first = reset( $terms );
			return $first->name;
		}
		return '';
	}

	private function product_to_item( $product, $quantity = 1 ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$parent  = $product;
		$variant = '';
		if ( $product->is_type( 'variation' ) ) {
			$parent_obj = wc_get_product( $product->get_parent_id() );
			if ( $parent_obj ) {
				$parent = $parent_obj;
			}
			$atts = $product->get_variation_attributes( false );
			$atts = array_filter( array_map( 'trim', (array) $atts ) );
			if ( ! empty( $atts ) ) {
				$variant = implode( ' / ', $atts );
			}
		}
		$item = [
			'item_id'       => $product->get_sku() ?: (string) $product->get_id(),
			'item_name'     => $parent->get_name(),
			'price'         => (float) wc_get_price_to_display( $product ),
			'quantity'      => (int) $quantity,
			'item_category' => $this->primary_category( $parent ),
		];
		if ( $variant !== '' ) {
			$item['item_variant'] = $variant;
		}
		return $item;
	}

	private function build_user_data( WC_Order $order ) {
		$email = strtolower( trim( $order->get_billing_email() ) );
		if ( ! $email ) {
			return [];
		}
		$data = [
			'sha256_email_address' => hash( 'sha256', $email ),
		];
		$phone = preg_replace( '/[^\d+]/', '', $order->get_billing_phone() );
		if ( $phone ) {
			$data['sha256_phone_number'] = hash( 'sha256', $phone );
		}
		$first  = strtolower( trim( $order->get_billing_first_name() ) );
		$last   = strtolower( trim( $order->get_billing_last_name() ) );
		$street = strtolower( trim( $order->get_billing_address_1() ) );
		$city   = strtolower( trim( $order->get_billing_city() ) );
		$state  = strtolower( trim( $order->get_billing_state() ) );
		$postal  = trim( $order->get_billing_postcode() );
		$country = strtolower( trim( $order->get_billing_country() ) );
		$address = array_filter( [
			'city'        => $city,
			'region'      => $state,
			'postal_code' => $postal,
			'country'     => $country,
		] );
		if ( $first )  $address['sha256_first_name'] = hash( 'sha256', $first );
		if ( $last )   $address['sha256_last_name']  = hash( 'sha256', $last );
		if ( $street ) $address['sha256_street']     = hash( 'sha256', $street );
		if ( ! empty( $address ) ) {
			$data['address'] = $address;
		}
		return $data;
	}

	public function inject_enhanced_conversions_head() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		$order_id = absint( get_query_var( 'order-received', 0 ) );
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Validate order key to prevent data leakage on URL guessing
		$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( $order_key && method_exists( $order, 'key_is_valid' ) && ! $order->key_is_valid( $order_key ) ) {
			return;
		}
		$user_data = $this->build_user_data( $order );
		if ( empty( $user_data ) ) {
			return;
		}
		?>
<script id="icb-enhanced-conversions">
(function(){
	function gtag(){window.dataLayer=window.dataLayer||[];window.dataLayer.push(arguments);}
	var ud = <?php echo wp_json_encode( $user_data ); ?>;
	gtag('set','user_data', ud);
	window.icbUserData = ud; // Available as GTM variable: {{JavaScript Variable}} → icbUserData
})();
</script>
		<?php
	}

	private function push( array $data, array $fbq = [] ) {
		$s        = $this->settings();
		$do_fbq   = ! empty( $s['meta_pixel_events'] ) && ! empty( $fbq );
		$fbq_js   = '';
		if ( $do_fbq ) {
			$event_name = esc_js( $fbq['event'] );
			$params_js  = wp_json_encode( $fbq['params'] );
			$event_id   = isset( $fbq['event_id'] ) ? ', ' . wp_json_encode( [ 'eventID' => (string) $fbq['event_id'] ] ) : '';
			$fbq_js     = "if(typeof window.fbq==='function'){try{fbq('track','{$event_name}',{$params_js}{$event_id});}catch(e){}}";
		}
		?>
<script class="icb-wc-event">
window.dataLayer = window.dataLayer || [];
dataLayer.push({ ecommerce: null });
dataLayer.push(<?php echo wp_json_encode( $data ); ?>);
<?php if ( $fbq_js ) echo $fbq_js . "\n"; ?>
</script>
		<?php
	}

	private function items_to_content_ids( array $items ) {
		return array_values( array_filter( array_map( function ( $it ) {
			return $it['item_id'] ?? null;
		}, $items ) ) );
	}

	public function view_item() {
		$s = $this->settings();
		if ( empty( $s['wc_send_view_item'] ) ) {
			return;
		}
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$item = $this->product_to_item( $product );
		if ( ! $item ) {
			return;
		}
		$this->push(
			[
				'event'     => 'view_item',
				'ecommerce' => [
					'currency' => $this->currency(),
					'value'    => $item['price'],
					'items'    => [ $item ],
				],
			],
			[
				'event'  => 'ViewContent',
				'params' => [
					'content_ids'  => [ $item['item_id'] ],
					'content_name' => $item['item_name'],
					'content_type' => 'product',
					'value'        => $item['price'],
					'currency'     => $this->currency(),
				],
			]
		);
	}

	public function view_item_list() {
		if ( ! function_exists( 'wc_get_loop_prop' ) ) {
			return;
		}
		$args = [
			'status' => 'publish',
			'limit'  => (int) wc_get_loop_prop( 'per_page' ) ?: 12,
			'paged'  => max( 1, (int) wc_get_loop_prop( 'current_page' ) ),
		];
		$query = wc_get_products( $args );
		if ( empty( $query ) || ! is_array( $query ) ) {
			return;
		}
		$items = [];
		$i = 0;
		foreach ( $query as $product ) {
			$it = $this->product_to_item( $product );
			if ( ! $it ) continue;
			$it['index'] = ++$i;
			$items[] = $it;
		}
		if ( empty( $items ) ) {
			return;
		}
		$this->push( [
			'event'     => 'view_item_list',
			'ecommerce' => [
				'currency'      => $this->currency(),
				'item_list_id'  => is_shop() ? 'shop' : ( is_product_category() ? get_queried_object()->slug : 'archive' ),
				'item_list_name'=> is_shop() ? 'Shop' : ( is_product_category() ? get_queried_object()->name : 'Archive' ),
				'items'         => array_slice( $items, 0, 50 ),
			],
		] );
	}

	public function add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( wp_doing_ajax() ) {
			return;
		}
		$s = $this->settings();
		if ( empty( $s['wc_send_cart_events'] ) ) {
			return;
		}
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return;
		}
		$item = $this->product_to_item( $product, $quantity );
		if ( ! $item ) {
			return;
		}
		$event = [
			'event'     => 'add_to_cart',
			'ecommerce' => [
				'currency' => $this->currency(),
				'value'    => $item['price'] * $item['quantity'],
				'items'    => [ $item ],
			],
		];
		if ( WC()->session ) {
			$pending = (array) WC()->session->get( self::SESSION_KEY, [] );
			$pending[] = $event;
			WC()->session->set( self::SESSION_KEY, array_slice( $pending, -20 ) );
		}
	}

	public function flush_pending() {
		if ( ! WC()->session ) {
			return;
		}
		$pending = (array) WC()->session->get( self::SESSION_KEY, [] );
		if ( empty( $pending ) ) {
			return;
		}
		foreach ( $pending as $event ) {
			$fbq = [];
			if ( ( $event['event'] ?? '' ) === 'add_to_cart' ) {
				$ec_items = $event['ecommerce']['items'] ?? [];
				$fbq = [
					'event'  => 'AddToCart',
					'params' => [
						'content_ids'  => $this->items_to_content_ids( $ec_items ),
						'content_type' => 'product',
						'value'        => $event['ecommerce']['value'] ?? 0,
						'currency'     => $event['ecommerce']['currency'] ?? $this->currency(),
					],
				];
			}
			$this->push( $event, $fbq );
		}
		WC()->session->set( self::SESSION_KEY, [] );
	}

	public function view_cart() {
		$s = $this->settings();
		if ( empty( $s['wc_send_cart_events'] ) ) {
			return;
		}
		if ( ! WC()->cart ) {
			return;
		}
		$items = [];
		foreach ( WC()->cart->get_cart() as $ci ) {
			$product = $ci['data'] ?? null;
			$it = $this->product_to_item( $product, $ci['quantity'] ?? 1 );
			if ( $it ) $items[] = $it;
		}
		if ( empty( $items ) ) {
			return;
		}
		$this->push( [
			'event'     => 'view_cart',
			'ecommerce' => [
				'currency' => $this->currency(),
				'value'    => (float) WC()->cart->get_cart_contents_total(),
				'items'    => $items,
			],
		] );
	}

	public function begin_checkout() {
		$s = $this->settings();
		if ( empty( $s['wc_send_cart_events'] ) ) {
			return;
		}
		if ( ! WC()->cart ) {
			return;
		}
		$items = [];
		foreach ( WC()->cart->get_cart() as $ci ) {
			$product = $ci['data'] ?? null;
			$it = $this->product_to_item( $product, $ci['quantity'] ?? 1 );
			if ( $it ) $items[] = $it;
		}
		if ( empty( $items ) ) {
			return;
		}
		$value = (float) WC()->cart->get_cart_contents_total();
		$this->push(
			[
				'event'     => 'begin_checkout',
				'ecommerce' => [
					'currency' => $this->currency(),
					'value'    => $value,
					'items'    => $items,
				],
			],
			[
				'event'  => 'InitiateCheckout',
				'params' => [
					'content_ids'  => $this->items_to_content_ids( $items ),
					'content_type' => 'product',
					'num_items'    => count( $items ),
					'value'        => $value,
					'currency'     => $this->currency(),
				],
			]
		);
	}

	public function purchase( $order_id ) {
		$s = $this->settings();
		if ( empty( $s['wc_send_purchase'] ) || ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_icb_tracked' ) ) {
			return;
		}
		$order->update_meta_data( '_icb_tracked', 1 );
		$order->save();

		$items = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) continue;
			$items[] = [
				'item_id'       => $product->get_sku() ?: (string) $product->get_id(),
				'item_name'     => $item->get_name(),
				'price'         => (float) $order->get_item_subtotal( $item, false, false ),
				'quantity'      => (int) $item->get_quantity(),
				'item_category' => $this->primary_category( $product ),
			];
		}

		$order_number = (string) $order->get_order_number();
		$total        = (float) $order->get_total();
		$currency     = $order->get_currency();
		$ga4_event    = [
			'event'     => 'purchase',
			'ecommerce' => [
				'transaction_id' => $order_number,
				'value'          => $total,
				'tax'            => (float) $order->get_total_tax(),
				'shipping'       => (float) $order->get_shipping_total(),
				'currency'       => $currency,
				'coupon'         => implode( ',', $order->get_coupon_codes() ),
				'items'          => $items,
			],
		];
		if ( ! empty( $s['enhanced_conversions'] ) ) {
			$user_data = $this->build_user_data( $order );
			if ( ! empty( $user_data ) ) {
				$ga4_event['user_data'] = $user_data;
			}
		}
		$this->push(
			$ga4_event,
			[
				'event'    => 'Purchase',
				'params'   => [
					'value'        => $total,
					'currency'     => $currency,
					'content_ids'  => $this->items_to_content_ids( $items ),
					'content_type' => 'product',
					'num_items'    => count( $items ),
				],
				'event_id' => $order_number,
			]
		);
	}

	private function build_products_map() {
		$map = [];
		if ( is_singular( 'product' ) ) {
			global $product;
			$p = $product;
			if ( ! ( $p instanceof WC_Product ) ) {
				$p = wc_get_product( get_the_ID() );
			}
			if ( $p instanceof WC_Product ) {
				$item = $this->product_to_item( $p );
				if ( $item ) {
					$map[ (string) $p->get_id() ] = $item;
				}
			}
		}
		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) ) {
			global $wp_query;
			if ( $wp_query && ! empty( $wp_query->posts ) ) {
				foreach ( $wp_query->posts as $post ) {
					$p = wc_get_product( $post->ID );
					if ( ! $p ) continue;
					$item = $this->product_to_item( $p );
					if ( $item ) {
						$map[ (string) $p->get_id() ] = $item;
					}
				}
			}
		}
		return $map;
	}

	public function inject_js_helpers() {
		$s = $this->settings();
		if ( empty( $s['wc_send_cart_events'] ) && empty( $s['wc_send_view_item'] ) ) {
			return;
		}
		$products_map = $this->build_products_map();
		$currency     = $this->currency();
		?>
<script id="icb-wc-helpers">
window.icbProducts = <?php echo wp_json_encode( (object) $products_map ); ?>;
(function(){
	var CURRENCY = <?php echo wp_json_encode( $currency ); ?>;
	var DEBUG = <?php echo ! empty( $s['debug'] ) ? 'true' : 'false'; ?>;
	var META_PIXEL = <?php echo ! empty( $s['meta_pixel_events'] ) ? 'true' : 'false'; ?>;
	function log(){ if (DEBUG && window.console) console.log.apply(console, ['[ICB-WC]'].concat([].slice.call(arguments))); }
	function push(ev){ window.dataLayer = window.dataLayer || []; dataLayer.push({ ecommerce: null }); dataLayer.push(ev); log('push', ev); }
	function fbqPush(event, params, eventId){
		if (!META_PIXEL || typeof window.fbq !== 'function') return;
		try { fbq('track', event, params, eventId ? { eventID: eventId } : undefined); log('fbq', event, params); } catch(e){}
	}
	function findPid(el){
		while (el && el !== document) {
			if (el.classList) {
				for (var i = 0; i < el.classList.length; i++) {
					var m = el.classList[i].match(/^post-(\d+)$/);
					if (m) return m[1];
				}
			}
			if (el.dataset && el.dataset.productId) return el.dataset.productId;
			el = el.parentNode;
		}
		return null;
	}

	<?php if ( ! empty( $s['wc_send_view_item'] ) ) : ?>
	// select_item: clicks en cards de producto en listados
	document.addEventListener('click', function(e){
		var link = e.target.closest && e.target.closest('a');
		if (!link) return;
		if (!link.matches('.woocommerce-LoopProduct-link, .woocommerce-loop-product__link, li.product a, .products .product > a')) return;
		var pid = findPid(link);
		if (!pid || !window.icbProducts || !icbProducts[pid]) return;
		push({ event: 'select_item', ecommerce: { currency: CURRENCY, items: [icbProducts[pid]] } });
	}, true);
	<?php endif; ?>

	<?php if ( ! empty( $s['wc_send_cart_events'] ) ) : ?>
	if (typeof jQuery !== 'undefined') {
		// add_to_cart AJAX
		jQuery(document.body).on('added_to_cart', function(event, fragments, cart_hash, $button){
			if (!$button || !$button.hasClass || !$button.hasClass('ajax_add_to_cart')) return;
			var pid = $button.data('product_id');
			var qty = parseInt($button.data('quantity') || 1, 10);
			if (!pid || !window.icbProducts || !icbProducts[pid]) return;
			var p = icbProducts[pid];
			var item = Object.assign({}, p, { quantity: qty });
			var value = (p.price || 0) * qty;
			push({ event: 'add_to_cart', ecommerce: { currency: CURRENCY, value: value, items: [item] } });
			fbqPush('AddToCart', { content_ids: [String(pid)], content_type: 'product', value: value, currency: CURRENCY });
		});
		// remove_from_cart
		jQuery(document.body).on('removed_from_cart', function(event, fragments, cart_hash, $button){
			var pid = $button && $button.data ? $button.data('product_id') : null;
			if (!pid) return;
			push({ event: 'remove_from_cart', ecommerce: { currency: CURRENCY, items: [{ item_id: String(pid) }] } });
		});
	}
	<?php endif; ?>
})();
</script>
		<?php
	}
}
