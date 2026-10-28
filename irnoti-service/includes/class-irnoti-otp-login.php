<?php
defined( 'ABSPATH' ) || exit;

/** Passwordless login by SMS code. Shortcode: [irnoti_login]. Codes are never stored in clear text; responses never reveal whether a mobile is registered. */
class IRNoti_OTP_Login {
	const TTL = 300;

	public static function init(): void {
		add_shortcode( 'irnoti_login', array( __CLASS__, 'shortcode' ) );
		foreach ( array( 'send', 'verify' ) as $a ) {
			add_action( 'wp_ajax_nopriv_irnoti_otp_' . $a, array( __CLASS__, 'ajax_' . $a ) );
			add_action( 'wp_ajax_irnoti_otp_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		add_action( 'show_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
	}

	public static function enabled(): bool {
		return (bool) IRNoti_Settings::get( 'otp_login' ) && IRNoti_API::configured();
	}

	public static function shortcode(): string {
		if ( ! self::enabled() || is_user_logged_in() ) {
			return '';
		}
		wp_enqueue_script( 'irnoti-otp', IRNOTI_URL . 'assets/js/otp.js', array(), IRNOTI_VERSION, true );
		wp_localize_script( 'irnoti-otp', 'IRNOTI_OTP', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'irnoti_otp' ), 'redirect' => home_url( '/' ) ) );
		ob_start();
		?>
		<div class="irnoti-otp" dir="rtl">
			<form id="irnoti-otp-send"><p><label><?php esc_html_e( 'شمارهٔ موبایل', 'irnoti-service' ); ?><br><input type="tel" name="mobile" dir="ltr" required autocomplete="tel"></label></p><p><button type="submit"><?php esc_html_e( 'ارسال کد', 'irnoti-service' ); ?></button></p></form>
			<form id="irnoti-otp-verify" hidden><p><label><?php esc_html_e( 'کد دریافتی', 'irnoti-service' ); ?><br><input type="text" name="code" dir="ltr" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code"></label></p><p><button type="submit"><?php esc_html_e( 'ورود', 'irnoti-service' ); ?></button></p></form>
			<p id="irnoti-otp-msg" role="status"></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function mobile_key( string $mobile ): string {
		return hash_hmac( 'sha256', $mobile, wp_salt( 'auth' ) );
	}

	/** Returns false when over the limit; counts the attempt otherwise. */
	private static function throttle( string $bucket, int $max, int $window ): bool {
		$key = 'irnoti_rl_' . md5( $bucket );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, $window );
		return true;
	}

	private static function client_ip(): string {
		return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ); // Not X-Forwarded-For: spoofable.
	}

	private static function find_user( string $mobile ): ?WP_User {
		$users = get_users( array( 'meta_key' => 'irnoti_mobile', 'meta_value' => $mobile, 'number' => 2 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( 1 !== count( $users ) ) {
			return null; // none or ambiguous.
		}
		$u = $users[0];
		return ( user_can( $u, 'manage_options' ) && ! apply_filters( 'irnoti_otp_allow_admins', false ) ) ? null : $u;
	}

	public static function ajax_send(): void {
		check_ajax_referer( 'irnoti_otp', 'nonce' );
		self::enabled() || wp_send_json_error( array( 'message' => 'disabled' ), 403 );
		$mobile = IRNoti_SMS::normalize( sanitize_text_field( wp_unslash( $_POST['mobile'] ?? '' ) ) );
		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
			wp_send_json_error( array( 'message' => __( 'شمارهٔ موبایل نامعتبر است.', 'irnoti-service' ) ), 400 );
		}
		if ( ! self::throttle( 'ip:' . self::client_ip(), 20, HOUR_IN_SECONDS ) || ! self::throttle( 'm:' . $mobile, 5, HOUR_IN_SECONDS ) || ! self::throttle( 'm60:' . $mobile, 1, 60 ) ) {
			wp_send_json_error( array( 'message' => __( 'تعداد درخواست‌ها زیاد است؛ کمی بعد تلاش کنید.', 'irnoti-service' ) ), 429 );
		}
		$user = self::find_user( $mobile );
		if ( $user ) {
			$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			set_transient( 'irnoti_otp_' . self::mobile_key( $mobile ), array( 'h' => hash_hmac( 'sha256', $code, wp_salt( 'auth' ) ), 'u' => $user->ID, 'tries' => 0 ), self::TTL );
			$pattern = (string) IRNoti_Settings::get( 'otp_pattern' );
			$pattern ? IRNoti_SMS::pattern( $pattern, $mobile, array( 'code' => $code ) ) : IRNoti_SMS::send( $mobile, sprintf( __( 'کد ورود به %1$s: %2$s', 'irnoti-service' ), get_bloginfo( 'name' ), $code ) );
		}
		wp_send_json_success( array( 'message' => __( 'اگر این شماره ثبت شده باشد، کد ارسال شد.', 'irnoti-service' ) ) );
	}

	public static function ajax_verify(): void {
		check_ajax_referer( 'irnoti_otp', 'nonce' );
		self::enabled() || wp_send_json_error( array( 'message' => 'disabled' ), 403 );
		$mobile = IRNoti_SMS::normalize( sanitize_text_field( wp_unslash( $_POST['mobile'] ?? '' ) ) );
		$code   = preg_replace( '/\D/', '', (string) ( $_POST['code'] ?? '' ) );
		$key    = 'irnoti_otp_' . self::mobile_key( $mobile );
		$rec    = get_transient( $key );
		$fail   = static function () {
			wp_send_json_error( array( 'message' => __( 'کد نادرست یا منقضی است.', 'irnoti-service' ) ), 400 );
		};
		if ( ! is_array( $rec ) || $rec['tries'] >= 5 ) {
			delete_transient( $key );
			$fail();
		}
		if ( ! hash_equals( $rec['h'], hash_hmac( 'sha256', $code, wp_salt( 'auth' ) ) ) ) {
			$rec['tries']++;
			set_transient( $key, $rec, self::TTL );
			$fail();
		}
		delete_transient( $key );
		wp_set_auth_cookie( (int) $rec['u'], true );
		wp_send_json_success( array( 'redirect' => wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect'] ?? '' ) ), home_url( '/' ) ) ) );
	}

	public static function profile_field( WP_User $user ): void {
		echo '<h2>IRNoti</h2><table class="form-table"><tr><th><label for="irnoti_mobile">' . esc_html__( 'موبایل (ورود با پیامک)', 'irnoti-service' ) . '</label></th><td><input type="tel" dir="ltr" class="regular-text" name="irnoti_mobile" id="irnoti_mobile" value="' . esc_attr( (string) get_user_meta( $user->ID, 'irnoti_mobile', true ) ) . '"></td></tr></table>';
	}

	public static function save_profile( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['irnoti_mobile'] ) ) {
			return;
		}
		$m = IRNoti_SMS::normalize( sanitize_text_field( wp_unslash( $_POST['irnoti_mobile'] ) ) );
		if ( '' === $m ) {
			delete_user_meta( $user_id, 'irnoti_mobile' );
		} elseif ( preg_match( '/^09\d{9}$/', $m ) ) {
			update_user_meta( $user_id, 'irnoti_mobile', $m );
		}
	}
}
