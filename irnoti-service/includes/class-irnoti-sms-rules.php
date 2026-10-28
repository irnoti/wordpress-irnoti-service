<?php
defined( 'ABSPATH' ) || exit;

/**
 * SMS rules: "when EVENT happens (and CONDITIONS hold) send TEXT or PATTERN to RECIPIENT".
 * Events cover WooCommerce (order statuses, new order, customer note, refund, stock, new customer, bot order) and WordPress (registration).
 */
class IRNoti_SMS_Rules {
	const OPTION = 'irnoti_sms_rules';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'migrate' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'on_new_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_new_order' ) );
		add_action( 'woocommerce_new_customer_note', array( __CLASS__, 'on_customer_note' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ), 10, 2 );
		add_action( 'woocommerce_low_stock', array( __CLASS__, 'on_low_stock' ) );
		add_action( 'woocommerce_no_stock', array( __CLASS__, 'on_no_stock' ) );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'on_new_customer' ) );
		add_action( 'user_register', array( __CLASS__, 'on_register' ) );
		add_action( 'irnoti_bot_order_created', array( __CLASS__, 'on_bot_order' ) );
	}

	/* ---------- registry ---------- */

	public static function events(): array {
		$e = array(
			'wc_new_order'     => array( __( 'ثبت سفارش جدید', 'irnoti-service' ), 'order' ),
			'wc_bot_order'     => array( __( 'سفارش جدید از ربات', 'irnoti-service' ), 'order' ),
			'wc_customer_note' => array( __( 'یادداشت برای مشتری', 'irnoti-service' ), 'order' ),
			'wc_refund'        => array( __( 'بازپرداخت سفارش', 'irnoti-service' ), 'order' ),
			'wc_low_stock'     => array( __( 'کمبود موجودی محصول', 'irnoti-service' ), 'product' ),
			'wc_no_stock'      => array( __( 'ناموجود شدن محصول', 'irnoti-service' ), 'product' ),
			'wc_new_customer'  => array( __( 'ثبت‌نام مشتری جدید', 'irnoti-service' ), 'user' ),
			'wp_register'      => array( __( 'ثبت‌نام کاربر در وردپرس', 'irnoti-service' ), 'user' ),
		);
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $slug => $name ) {
				$e[ 'wc_status:' . preg_replace( '/^wc-/', '', $slug ) ] = array( sprintf( __( 'تغییر وضعیت سفارش به «%s»', 'irnoti-service' ), $name ), 'order' );
			}
		}
		return $e;
	}

	/** Variables offered per context type: token => label. */
	public static function variables( string $type ): array {
		$site = array( 'site' => __( 'نام سایت', 'irnoti-service' ) );
		switch ( $type ) {
			case 'order':
				return $site + array(
					'order_id'       => __( 'شمارهٔ سفارش', 'irnoti-service' ),
					'first_name'     => __( 'نام مشتری', 'irnoti-service' ),
					'last_name'      => __( 'نام خانوادگی', 'irnoti-service' ),
					'full_name'      => __( 'نام کامل', 'irnoti-service' ),
					'phone'          => __( 'موبایل', 'irnoti-service' ),
					'total'          => __( 'مبلغ کل', 'irnoti-service' ),
					'shipping_total' => __( 'هزینهٔ ارسال', 'irnoti-service' ),
					'payment_method' => __( 'روش پرداخت', 'irnoti-service' ),
					'status'         => __( 'وضعیت', 'irnoti-service' ),
					'date'           => __( 'تاریخ سفارش', 'irnoti-service' ),
					'items'          => __( 'اقلام', 'irnoti-service' ),
					'items_count'    => __( 'تعداد اقلام', 'irnoti-service' ),
					'city'           => __( 'شهر', 'irnoti-service' ),
					'address'        => __( 'آدرس', 'irnoti-service' ),
					'note'           => __( 'یادداشت', 'irnoti-service' ),
					'order_url'      => __( 'لینک سفارش', 'irnoti-service' ),
				);
			case 'product':
				return $site + array(
					'product_name' => __( 'نام محصول', 'irnoti-service' ),
					'sku'          => __( 'کد محصول', 'irnoti-service' ),
					'stock'        => __( 'موجودی', 'irnoti-service' ),
					'product_url'  => __( 'لینک محصول', 'irnoti-service' ),
					'edit_url'     => __( 'لینک ویرایش', 'irnoti-service' ),
				);
			default:
				return $site + array(
					'user_name'    => __( 'نام کاربری', 'irnoti-service' ),
					'display_name' => __( 'نام نمایشی', 'irnoti-service' ),
					'email'        => __( 'ایمیل', 'irnoti-service' ),
					'phone'        => __( 'موبایل', 'irnoti-service' ),
				);
		}
	}

	public static function sample( string $type ): array {
		$s = array( 'site' => get_bloginfo( 'name' ) );
		if ( 'order' === $type ) {
			return $s + array( 'order_id' => '1042', 'first_name' => 'سارا', 'last_name' => 'احمدی', 'full_name' => 'سارا احمدی', 'phone' => '09121234567', 'total' => '۲٬۵۰۰٬۰۰۰ تومان', 'shipping_total' => '۵۰٬۰۰۰ تومان', 'payment_method' => 'درگاه پرداخت', 'status' => 'در حال انجام', 'date' => '1405/08/06', 'items' => 'کفش ورزشی × ۱', 'items_count' => '1', 'city' => 'تهران', 'address' => 'خیابان ولیعصر', 'note' => '', 'order_url' => home_url( '/my-account/orders/' ) );
		}
		if ( 'product' === $type ) {
			return $s + array( 'product_name' => 'کفش ورزشی', 'sku' => 'SH-100', 'stock' => '2', 'product_url' => home_url( '/product/x' ), 'edit_url' => admin_url( 'post.php' ) );
		}
		return $s + array( 'user_name' => 'sara', 'display_name' => 'سارا', 'email' => 'sara@example.com', 'phone' => '09121234567' );
	}

	/* ---------- storage ---------- */

	public static function all(): array {
		return array_values( (array) get_option( self::OPTION, array() ) );
	}

	public static function find( string $id ): ?array {
		foreach ( self::all() as $r ) {
			if ( $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	public static function save_all( array $rules ): void {
		update_option( self::OPTION, array_values( $rules ), false );
	}

	public static function blank(): array {
		return array( 'id' => '', 'name' => '', 'enabled' => 1, 'event' => 'wc_new_order', 'recipient' => 'customer', 'custom_to' => '', 'mode' => 'text', 'text' => '', 'pattern' => '', 'params' => array(), 'min_total' => '', 'payment_method' => '', 'source' => 'any' );
	}

	public static function sanitize( array $in ): array {
		$r             = self::blank();
		$r['id']       = preg_match( '/^[a-f0-9]{8}$/', (string) ( $in['id'] ?? '' ) ) ? $in['id'] : substr( md5( uniqid( '', true ) ), 0, 8 );
		$r['name']     = sanitize_text_field( $in['name'] ?? '' );
		$r['enabled']  = empty( $in['enabled'] ) ? 0 : 1;
		$r['event']    = isset( self::events()[ $in['event'] ?? '' ] ) ? $in['event'] : 'wc_new_order';
		$r['recipient'] = in_array( $in['recipient'] ?? '', array( 'customer', 'admin', 'custom' ), true ) ? $in['recipient'] : 'customer';
		$r['custom_to'] = sanitize_text_field( $in['custom_to'] ?? '' );
		$r['mode']     = 'pattern' === ( $in['mode'] ?? '' ) ? 'pattern' : 'text';
		$r['text']     = sanitize_textarea_field( $in['text'] ?? '' );
		$r['pattern']  = sanitize_text_field( $in['pattern'] ?? '' );
		foreach ( (array) ( $in['params'] ?? array() ) as $k => $v ) {
			if ( preg_match( '/^\w{1,40}$/', (string) $k ) ) {
				$r['params'][ $k ] = sanitize_text_field( $v );
			}
		}
		$r['min_total'] = '' === trim( (string) ( $in['min_total'] ?? '' ) ) ? '' : (string) max( 0, (float) $in['min_total'] );
		$r['payment_method'] = sanitize_key( $in['payment_method'] ?? '' );
		$r['source']   = in_array( $in['source'] ?? '', array( 'web', 'bot' ), true ) ? $in['source'] : 'any';
		if ( '' === $r['name'] ) {
			$r['name'] = self::events()[ $r['event'] ][0];
		}
		return $r;
	}

	/** One-time import of the v0.1 per-status templates. */
	public static function migrate(): void {
		$legacy = (array) IRNoti_Settings::get( 'sms_events' );
		$admin  = (string) IRNoti_Settings::get( 'sms_admin_to' );
		if ( ( ! $legacy && '' === $admin ) || get_option( 'irnoti_sms_migrated' ) ) {
			return;
		}
		$rules = self::all();
		if ( '' !== $admin ) {
			$rules[] = self::sanitize( array( 'name' => __( 'اعلان سفارش جدید به مدیر', 'irnoti-service' ), 'enabled' => 1, 'event' => 'wc_new_order', 'recipient' => 'admin', 'mode' => 'text', 'text' => 'سفارش جدید #{order_id} به مبلغ {total} ثبت شد.', 'source' => 'any' ) );
		}
		foreach ( $legacy as $status => $tpl ) {
			$rules[] = self::sanitize( array( 'name' => 'status ' . $status, 'enabled' => 1, 'event' => 'wc_status:' . $status, 'recipient' => 'customer', 'mode' => 'text', 'text' => $tpl, 'source' => 'any' ) );
		}
		self::save_all( $rules );
		update_option( 'irnoti_sms_migrated', 1, false );
	}

	/* ---------- rendering ---------- */

	public static function render( string $tpl, array $vars ): string {
		return trim( (string) preg_replace_callback( '/\{(\w+)\}/', static function ( $m ) use ( $vars ) {
			return (string) ( $vars[ $m[1] ] ?? '' );
		}, $tpl ) );
	}

	/** Persian/UCS-2 SMS: 70 chars for one part, 67 per part afterwards. */
	public static function segments( string $text ): int {
		$n = mb_strlen( $text );
		if ( 0 === $n ) {
			return 0;
		}
		$ascii = ! preg_match( '/[^\x00-\x7F]/', $text );
		$one   = $ascii ? 160 : 70;
		$multi = $ascii ? 153 : 67;
		return $n <= $one ? 1 : (int) ceil( $n / $multi );
	}

	private static function money( $v ): string {
		$cur = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '';
		return number_format_i18n( (float) $v, 0 ) . ' ' . $cur;
	}

	public static function order_vars( WC_Order $o ): array {
		$items = array();
		$count = 0;
		foreach ( $o->get_items() as $it ) {
			$items[] = $it->get_name() . ' × ' . $it->get_quantity();
			$count  += (int) $it->get_quantity();
		}
		$first = $o->get_billing_first_name();
		$last  = $o->get_billing_last_name();
		return array(
			'site'           => get_bloginfo( 'name' ),
			'order_id'       => $o->get_order_number(),
			'first_name'     => $first,
			'last_name'      => $last,
			'full_name'      => trim( $first . ' ' . $last ),
			'phone'          => $o->get_billing_phone(),
			'total'          => self::money( $o->get_total() ),
			'shipping_total' => self::money( $o->get_shipping_total() ),
			'payment_method' => $o->get_payment_method_title(),
			'status'         => wc_get_order_status_name( $o->get_status() ),
			'date'           => $o->get_date_created() ? wp_date( 'Y/m/d', $o->get_date_created()->getTimestamp() ) : '',
			'items'          => implode( '، ', $items ),
			'items_count'    => (string) $count,
			'city'           => $o->get_billing_city(),
			'address'        => trim( $o->get_billing_address_1() . ' ' . $o->get_billing_address_2() ),
			'note'           => $o->get_customer_note(),
			'order_url'      => $o->get_view_order_url(),
		);
	}

	private static function product_vars( WC_Product $p ): array {
		return array( 'site' => get_bloginfo( 'name' ), 'product_name' => $p->get_name(), 'sku' => $p->get_sku(), 'stock' => (string) $p->get_stock_quantity(), 'product_url' => get_permalink( $p->get_id() ), 'edit_url' => get_edit_post_link( $p->get_id(), 'raw' ) );
	}

	private static function user_vars( WP_User $u ): array {
		$m = (string) ( get_user_meta( $u->ID, 'irnoti_mobile', true ) ?: get_user_meta( $u->ID, 'billing_phone', true ) );
		return array( 'site' => get_bloginfo( 'name' ), 'user_name' => $u->user_login, 'display_name' => $u->display_name, 'email' => $u->user_email, 'phone' => $m );
	}

	/* ---------- firing ---------- */

	/**
	 * @param array{order?:WC_Order,customer_phone?:string,vars:array,key:string} $ctx
	 */
	private static function fire( string $event, array $ctx ): void {
		if ( ! IRNoti_API::configured() ) {
			return;
		}
		foreach ( self::all() as $rule ) {
			if ( ! $rule['enabled'] || $rule['event'] !== $event || ! self::passes( $rule, $ctx ) ) {
				continue;
			}
			$to = self::recipients( $rule, $ctx );
			if ( ! $to ) {
				continue;
			}
			$key = 'wp-' . $rule['id'] . '-' . $ctx['key'];
			if ( 'pattern' === $rule['mode'] && '' !== $rule['pattern'] ) {
				$params = array();
				foreach ( $rule['params'] as $name => $tpl ) {
					$params[ $name ] = mb_substr( self::render( $tpl, $ctx['vars'] ), 0, 200 );
				}
				foreach ( $to as $n ) {
					IRNoti_SMS::pattern( $rule['pattern'], $n, $params, $key . '-' . $n );
				}
			} elseif ( '' !== $rule['text'] ) {
				IRNoti_SMS::send( $to, self::render( $rule['text'], $ctx['vars'] ), $key );
			}
		}
	}

	private static function passes( array $rule, array $ctx ): bool {
		$order = $ctx['order'] ?? null;
		if ( ! $order instanceof WC_Order ) {
			return true;
		}
		if ( '' !== $rule['min_total'] && (float) $order->get_total() < (float) $rule['min_total'] ) {
			return false;
		}
		if ( '' !== $rule['payment_method'] && $order->get_payment_method() !== $rule['payment_method'] ) {
			return false;
		}
		$bot = 'irnoti-bot' === $order->get_created_via();
		return 'any' === $rule['source'] || ( 'bot' === $rule['source'] ? $bot : ! $bot );
	}

	private static function recipients( array $rule, array $ctx ): array {
		switch ( $rule['recipient'] ) {
			case 'admin':
				$raw = (string) IRNoti_Settings::get( 'sms_admin_to' );
				break;
			case 'custom':
				$raw = $rule['custom_to'];
				break;
			default:
				$raw = (string) ( $ctx['customer_phone'] ?? '' );
		}
		return array_values( array_unique( array_filter( array_map( array( 'IRNoti_SMS', 'normalize' ), preg_split( '/[\s,،;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ) ) ) );
	}

	private static function order_ctx( WC_Order $o, string $key ): array {
		return array( 'order' => $o, 'customer_phone' => $o->get_billing_phone(), 'vars' => self::order_vars( $o ), 'key' => $key );
	}

	public static function on_status( $order_id, $from, $to, $order ): void {
		if ( $order instanceof WC_Order ) {
			self::fire( 'wc_status:' . $to, self::order_ctx( $order, $order_id . '-' . $to ) );
		}
	}

	public static function on_new_order( $order ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || $order->get_meta( '_irnoti_new_sms' ) ) {
			return;
		}
		$order->update_meta_data( '_irnoti_new_sms', 1 );
		$order->save_meta_data();
		self::fire( 'wc_new_order', self::order_ctx( $order, $order->get_id() . '-new' ) );
	}

	public static function on_bot_order( $order ): void {
		if ( $order instanceof WC_Order ) {
			self::fire( 'wc_bot_order', self::order_ctx( $order, $order->get_id() . '-bot' ) );
		}
	}

	public static function on_customer_note( $data ): void {
		$order = wc_get_order( (int) ( $data['order_id'] ?? 0 ) );
		if ( $order ) {
			$ctx                 = self::order_ctx( $order, $order->get_id() . '-note-' . md5( (string) ( $data['customer_note'] ?? '' ) ) );
			$ctx['vars']['note'] = wp_strip_all_tags( (string) ( $data['customer_note'] ?? '' ) );
			self::fire( 'wc_customer_note', $ctx );
		}
	}

	public static function on_refund( $order_id, $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::fire( 'wc_refund', self::order_ctx( $order, $order_id . '-refund-' . $refund_id ) );
		}
	}

	public static function on_low_stock( $product ): void {
		self::product_event( 'wc_low_stock', $product );
	}

	public static function on_no_stock( $product ): void {
		self::product_event( 'wc_no_stock', $product );
	}

	private static function product_event( string $event, $product ): void {
		if ( $product instanceof WC_Product ) {
			// Recipient is the admin mobile for product events; a "customer" recipient has no meaning here.
			self::fire( $event, array( 'customer_phone' => '', 'vars' => self::product_vars( $product ), 'key' => $product->get_id() . '-' . $event . '-' . wp_date( 'YmdH' ) ) );
		}
	}

	public static function on_new_customer( $user_id ): void {
		self::user_event( 'wc_new_customer', (int) $user_id );
	}

	public static function on_register( $user_id ): void {
		self::user_event( 'wp_register', (int) $user_id );
	}

	private static function user_event( string $event, int $user_id ): void {
		$u = get_userdata( $user_id );
		if ( $u ) {
			$vars = self::user_vars( $u );
			self::fire( $event, array( 'customer_phone' => $vars['phone'], 'vars' => $vars, 'key' => $user_id . '-' . $event ) );
		}
	}
}
