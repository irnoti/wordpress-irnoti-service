<?php
defined( 'ABSPATH' ) || exit;

/** Two-way order sync between the shop bot and WooCommerce. Bot orders are pulled every 5 minutes; Woo status changes are pushed back. */
class IRNoti_Bot_Orders {
	const META_ID     = '_irnoti_bot_order_id';
	const META_STATUS = '_irnoti_bot_status';
	const CRON        = 'irnoti_pull_orders';

	private static bool $pulling = false;

	/** bot status => Woo status */
	const TO_WOO = array(
		'new'       => 'on-hold',
		'confirmed' => 'processing',
		'shipped'   => 'processing',
		'done'      => 'completed',
		'canceled'  => 'cancelled',
	);
	/** Woo status => bot status (statuses not listed are not pushed). */
	const TO_BOT = array(
		'processing' => 'confirmed',
		'completed'  => 'done',
		'cancelled'  => 'canceled',
	);

	public static function init(): void {
		add_filter( 'cron_schedules', static function ( $s ) {
			$s['irnoti_5min'] = array( 'interval' => 300, 'display' => 'IRNoti 5 min' );
			return $s;
		} );
		add_action( self::CRON, array( __CLASS__, 'pull' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'push_status' ), 20, 3 );
	}

	public static function schedule(): void {
		$on = IRNoti_Settings::get( 'bot_pull_orders' ) && IRNoti_Bot_Sync::enabled() && class_exists( 'WooCommerce' );
		$ts = wp_next_scheduled( self::CRON );
		if ( $on && ! $ts ) {
			wp_schedule_event( time() + 60, 'irnoti_5min', self::CRON );
		} elseif ( ! $on && $ts ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	private static function from_rial( $rial ): ?float {
		$cur = get_woocommerce_currency();
		if ( 'IRR' === $cur ) {
			return (float) $rial;
		}
		return 'IRT' === $cur ? (float) $rial / 10 : null;
	}

	private static function find_order( string $remote_id ): ?WC_Order {
		$found = wc_get_orders( array( 'limit' => 1, 'meta_key' => self::META_ID, 'meta_value' => $remote_id, 'return' => 'objects' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $found ? $found[0] : null;
	}

	private static function find_product( string $remote_id ): int {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => IRNoti_Bot_Sync::META_PRODUCT, 'meta_value' => $remote_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $ids ? (int) $ids[0] : 0;
	}

	/** @return int|WP_Error number of new orders */
	public static function pull() {
		if ( ! class_exists( 'WooCommerce' ) || ! IRNoti_Bot_Sync::enabled() ) {
			return 0;
		}
		$rows = IRNoti_API::get( 'botbuilder/bots/' . rawurlencode( (string) IRNoti_Settings::get( 'bot_id' ) ) . '/shop/orders' );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		self::$pulling = true;
		$new           = 0;
		foreach ( array_reverse( (array) $rows ) as $o ) {
			if ( ! is_array( $o ) || empty( $o['id'] ) ) {
				continue;
			}
			$order = self::find_order( (string) $o['id'] );
			if ( $order ) {
				self::apply_remote_status( $order, (string) ( $o['status'] ?? '' ) );
				continue;
			}
			if ( self::create( $o ) ) {
				++$new;
			}
		}
		self::$pulling = false;
		return $new;
	}

	private static function apply_remote_status( WC_Order $order, string $status ): void {
		if ( '' === $status || $status === $order->get_meta( self::META_STATUS ) || ! isset( self::TO_WOO[ $status ] ) ) {
			return;
		}
		$order->update_meta_data( self::META_STATUS, $status );
		$order->set_status( self::TO_WOO[ $status ], __( 'تغییر وضعیت از ربات.', 'irnoti-service' ) );
		$order->save();
	}

	private static function create( array $o ): bool {
		if ( null === self::from_rial( 0 ) ) {
			return false; // unsupported currency: never guess prices.
		}
		$order = wc_create_order();
		foreach ( (array) ( $o['items'] ?? array() ) as $line ) {
			$qty   = max( 1, (int) ( $line['qty'] ?? 1 ) );
			$total = self::from_rial( (string) ( $line['price'] ?? 0 ) ) * $qty;
			$pid   = self::find_product( (string) ( $line['productId'] ?? '' ) );
			$item  = new WC_Order_Item_Product();
			if ( $pid ) {
				$item->set_product( wc_get_product( $pid ) );
			}
			$item->set_name( sanitize_text_field( (string) ( $line['title'] ?? 'item' ) ) );
			$item->set_quantity( $qty );
			$item->set_subtotal( $total );
			$item->set_total( $total );
			$order->add_item( $item );
		}
		$name = sanitize_text_field( (string) ( $o['customerName'] ?? '' ) );
		$order->set_billing_first_name( $name );
		$order->set_billing_phone( sanitize_text_field( (string) ( $o['phone'] ?? '' ) ) );
		$order->set_billing_address_1( sanitize_textarea_field( (string) ( $o['address'] ?? '' ) ) );
		$order->set_payment_method_title( sanitize_text_field( sprintf( 'ربات (%s) %s', (string) ( $o['channel'] ?? '' ), (string) ( $o['paymentMethod'] ?? '' ) ) ) );
		$order->set_created_via( 'irnoti-bot' );
		$order->calculate_totals( false );
		$order->update_meta_data( self::META_ID, (string) $o['id'] );
		$status = (string) ( $o['status'] ?? 'new' );
		$order->update_meta_data( self::META_STATUS, $status );
		if ( ! empty( $o['note'] ) ) {
			$order->add_order_note( sanitize_textarea_field( (string) $o['note'] ) );
		}
		$order->set_status( self::TO_WOO[ $status ] ?? 'on-hold', sprintf( __( 'سفارش ربات #%s', 'irnoti-service' ), (string) ( $o['number'] ?? $o['id'] ) ) );
		$order->save();
		return true;
	}

	public static function push_status( $order_id, $from, $to ): void {
		if ( self::$pulling || ! IRNoti_Bot_Sync::enabled() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		$bot   = $order ? $order->get_meta( self::META_ID ) : '';
		if ( ! $bot || ! isset( self::TO_BOT[ $to ] ) || self::TO_BOT[ $to ] === $order->get_meta( self::META_STATUS ) ) {
			return;
		}
		$res = IRNoti_API::post( 'botbuilder/bots/' . rawurlencode( (string) IRNoti_Settings::get( 'bot_id' ) ) . '/shop/orders/' . rawurlencode( (string) $bot ) . '/status', array( 'status' => self::TO_BOT[ $to ] ) );
		if ( ! is_wp_error( $res ) ) {
			$order->update_meta_data( self::META_STATUS, self::TO_BOT[ $to ] );
			$order->save_meta_data();
		}
	}
}
