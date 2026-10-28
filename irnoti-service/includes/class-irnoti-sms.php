<?php
defined( 'ABSPATH' ) || exit;

class IRNoti_SMS {
	const LOG = 'irnoti_sms_log';

	public static function init(): void {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status' ), 10, 4 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 10, 1 );
	}

	public static function normalize( string $mobile ): string {
		$m = strtr( $mobile, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
		$m = preg_replace( '/\D+/', '', $m );
		if ( 0 === strpos( $m, '0098' ) ) {
			$m = substr( $m, 4 );
		} elseif ( 0 === strpos( $m, '98' ) ) {
			$m = substr( $m, 2 );
		}
		return ( 10 === strlen( $m ) && '9' === $m[0] ) ? '0' . $m : $m;
	}

	/** @param string|string[] $to */
	public static function send( $to, string $text, string $idempotency_key = '' ) {
		$list = array_values( array_filter( array_map( array( __CLASS__, 'normalize' ), (array) $to ) ) );
		if ( ! $list ) {
			return new WP_Error( 'irnoti_no_recipient', __( 'گیرنده‌ای مشخص نشده است.', 'irnoti-service' ) );
		}
		$body = array( 'to' => $list, 'text' => $text );
		$line = IRNoti_Settings::get( 'sms_line_id' );
		if ( '' !== (string) $line ) {
			$body['lineId'] = $line;
		}
		$res = IRNoti_API::post( 'sms/send', $body, $idempotency_key );
		self::log( $list, $text, $res );
		return $res;
	}

	public static function pattern( string $code, string $to, array $params = array(), string $idempotency_key = '' ) {
		$res = IRNoti_API::post( 'sms/pattern', array( 'code' => $code, 'to' => self::normalize( $to ), 'params' => (object) $params ), $idempotency_key );
		self::log( array( $to ), 'pattern:' . $code, $res );
		return $res;
	}

	public static function quote( $to, string $text ) {
		return IRNoti_API::post( 'sms/quote', array( 'to' => array_map( array( __CLASS__, 'normalize' ), (array) $to ), 'text' => $text ) );
	}

	public static function on_new_order( $order_id ): void {
		$admin_to = (string) IRNoti_Settings::get( 'sms_admin_to' );
		$order    = wc_get_order( $order_id );
		if ( '' === $admin_to || ! $order ) {
			return;
		}
		/* translators: 1: order number, 2: total */
		$text = sprintf( __( 'سفارش جدید #%1$s به مبلغ %2$s ثبت شد.', 'irnoti-service' ), $order->get_order_number(), wp_strip_all_tags( wc_price( $order->get_total() ) ) );
		self::send( preg_split( '/[\s,،]+/', $admin_to, -1, PREG_SPLIT_NO_EMPTY ), $text, 'wc-new-' . $order_id );
	}

	public static function on_order_status( $order_id, $from, $to, $order ): void {
		$templates = (array) IRNoti_Settings::get( 'sms_events' );
		$tpl       = $templates[ $to ] ?? '';
		if ( '' === $tpl || ! $order instanceof WC_Order || '' === $order->get_billing_phone() ) {
			return;
		}
		$text = strtr( $tpl, array(
			'{order_id}' => $order->get_order_number(),
			'{name}'     => $order->get_billing_first_name(),
			'{total}'    => wp_strip_all_tags( wc_price( $order->get_total() ) ),
			'{status}'   => wc_get_order_status_name( $to ),
			'{site}'     => get_bloginfo( 'name' ),
		) );
		// Idempotent per order+status so a re-fired hook never double-bills.
		self::send( $order->get_billing_phone(), $text, 'wc-' . $order_id . '-' . $to );
	}

	private static function log( array $to, string $text, $res ): void {
		$log = (array) get_option( self::LOG, array() );
		array_unshift( $log, array(
			'time'  => time(),
			'to'    => implode( ',', $to ),
			'text'  => mb_substr( $text, 0, 200 ),
			'ok'    => ! is_wp_error( $res ),
			'error' => is_wp_error( $res ) ? $res->get_error_message() : '',
		) );
		update_option( self::LOG, array_slice( $log, 0, 50 ), false );
	}
}
