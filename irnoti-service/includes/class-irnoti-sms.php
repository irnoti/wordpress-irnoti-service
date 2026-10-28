<?php
defined( 'ABSPATH' ) || exit;

class IRNoti_SMS {
	const LOG = 'irnoti_sms_log';

	public static function init(): void {
		IRNoti_SMS_Rules::init();
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
