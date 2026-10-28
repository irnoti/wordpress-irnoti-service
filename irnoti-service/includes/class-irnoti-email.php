<?php
defined( 'ABSPATH' ) || exit;

/** Routes wp_mail() through IRNoti when enabled; any API failure falls back to WordPress' normal transport. */
class IRNoti_Email {
	public static function init(): void {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'intercept' ), 10, 2 );
	}

	public static function intercept( $short, $atts ) {
		if ( null !== $short || ! IRNoti_Settings::get( 'email_enabled' ) || ! IRNoti_API::configured() ) {
			return $short;
		}
		$from = (string) IRNoti_Settings::get( 'email_from' );
		if ( '' === $from ) {
			return null;
		}
		$headers = is_array( $atts['headers'] ?? null ) ? $atts['headers'] : array_filter( explode( "\n", str_replace( "\r\n", "\n", (string) ( $atts['headers'] ?? '' ) ) ) );
		$html    = false;
		$reply   = '';
		$cc      = array();
		$bcc     = array();
		foreach ( $headers as $h ) {
			if ( false === strpos( $h, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $h, 2 ) );
			switch ( strtolower( $name ) ) {
				case 'content-type':
					$html = false !== stripos( $value, 'text/html' );
					break;
				case 'reply-to':
					$reply = $value;
					break;
				case 'cc':
					$cc = array_merge( $cc, array_map( 'trim', explode( ',', $value ) ) );
					break;
				case 'bcc':
					$bcc = array_merge( $bcc, array_map( 'trim', explode( ',', $value ) ) );
					break;
			}
		}
		if ( ! $html && has_filter( 'wp_mail_content_type' ) ) {
			$html = false !== stripos( (string) apply_filters( 'wp_mail_content_type', 'text/plain' ), 'text/html' );
		}
		$body = array(
			'from'     => $from,
			'fromName' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'to'       => array_values( array_filter( (array) $atts['to'] ) ),
			'subject'  => (string) $atts['subject'],
		);
		$body[ $html ? 'html' : 'text' ] = (string) $atts['message'];
		if ( $reply ) {
			$body['replyTo'] = $reply;
		}
		if ( $cc ) {
			$body['cc'] = $cc;
		}
		if ( $bcc ) {
			$body['bcc'] = $bcc;
		}
		$res = IRNoti_API::post( 'email/send', $body );
		return is_wp_error( $res ) ? null : true;
	}

	public static function send_test( string $to ) {
		return IRNoti_API::post( 'email/send', array(
			'from'    => (string) IRNoti_Settings::get( 'email_from' ),
			'to'      => array( $to ),
			'subject' => __( 'ایمیل آزمایشی IRNoti', 'irnoti-service' ),
			'text'    => __( 'اتصال وردپرس شما به IRNoti برقرار است.', 'irnoti-service' ),
		) );
	}
}
