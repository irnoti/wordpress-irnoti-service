<?php
defined( 'ABSPATH' ) || exit;

/** Softphone (WebRTC via JsSIP) and click-to-call. All calls go through nonce+capability checked admin-ajax handlers, so the browser never holds the API key. */
class IRNoti_VoIP {
	public static function init(): void {
		foreach ( array( 'accounts', 'create_account', 'credentials', 'call' ) as $a ) {
			add_action( 'wp_ajax_irnoti_voip_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	private static function guard(): void {
		check_ajax_referer( 'irnoti_voip', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	private static function reply( $res ): void {
		is_wp_error( $res ) ? wp_send_json_error( array( 'message' => $res->get_error_message() ), 502 ) : wp_send_json_success( $res );
	}

	public static function ajax_accounts(): void {
		self::guard();
		self::reply( IRNoti_API::get( 'voip/sip-accounts' ) );
	}

	public static function ajax_create_account(): void {
		self::guard();
		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? 'WordPress' ) );
		self::reply( IRNoti_API::post( 'voip/sip-accounts', array( 'label' => $label ) ) );
	}

	/** Returns the SIP secret to the logged-in admin's browser only, to register the softphone. */
	public static function ajax_credentials(): void {
		self::guard();
		$id = preg_replace( '/\D/', '', (string) ( $_POST['id'] ?? '' ) );
		self::reply( IRNoti_API::post( 'voip/sip-accounts/' . $id . '/credentials', array() ) );
	}

	public static function ajax_call(): void {
		self::guard();
		$from = IRNoti_SMS::normalize( sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ) );
		$to   = IRNoti_SMS::normalize( sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ) );
		self::reply( IRNoti_API::post( 'voip/calls', array( 'from' => $from, 'to' => $to ) ) );
	}

	public static function jssip_url(): string {
		$custom = (string) IRNoti_Settings::get( 'jssip_url' );
		if ( '' !== $custom && 0 === strpos( $custom, 'https://' ) ) {
			return $custom;
		}
		return file_exists( IRNOTI_DIR . 'assets/js/jssip.min.js' ) ? IRNOTI_URL . 'assets/js/jssip.min.js' : '';
	}
}
