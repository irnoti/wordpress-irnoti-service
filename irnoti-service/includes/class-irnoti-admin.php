<?php
defined( 'ABSPATH' ) || exit;

class IRNoti_Admin {
	const SLUG = 'irnoti';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_irnoti_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_irnoti_sms_test', array( __CLASS__, 'sms_test' ) );
		add_action( 'admin_post_irnoti_email_test', array( __CLASS__, 'email_test' ) );
		add_action( 'wp_ajax_irnoti_sync_batch', array( __CLASS__, 'ajax_sync_batch' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_menu_page( 'IRNoti', 'IRNoti', 'manage_options', self::SLUG, array( __CLASS__, 'render' ), 'dashicons-megaphone', 58 );
	}

	private static function tabs(): array {
		return array(
			'connection' => __( 'اتصال', 'irnoti-service' ),
			'sms'        => __( 'پیامک', 'irnoti-service' ),
			'bot'        => __( 'ربات و محصولات', 'irnoti-service' ),
			'email'      => __( 'ایمیل', 'irnoti-service' ),
			'voip'       => __( 'VoIP و سافت‌فون', 'irnoti-service' ),
		);
	}

	private static function url( string $tab, array $extra = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $extra ), admin_url( 'admin.php' ) );
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection';
		wp_enqueue_script( 'irnoti-admin', IRNOTI_URL . 'assets/js/admin.js', array(), IRNOTI_VERSION, true );
		wp_localize_script( 'irnoti-admin', 'IRNOTI', array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'syncNonce' => wp_create_nonce( 'irnoti_sync' ),
			'voipNonce' => wp_create_nonce( 'irnoti_voip' ),
			'voipFrom'  => (string) IRNoti_Settings::get( 'voip_from' ),
		) );
		if ( 'voip' === $tab && IRNoti_VoIP::jssip_url() ) {
			wp_enqueue_script( 'irnoti-jssip', IRNoti_VoIP::jssip_url(), array(), null, true );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'connection';
		echo '<div class="wrap" dir="rtl"><h1>IRNoti</h1>';
		if ( isset( $_GET['irnoti_msg'] ) ) {
			$ok = ! empty( $_GET['ok'] );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $ok ? 'success' : 'error', esc_html( wp_unslash( $_GET['irnoti_msg'] ) ) );
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $k === $tab ? 'nav-tab-active' : '', esc_url( self::url( $k ) ), esc_html( $label ) );
		}
		echo '</nav>';
		if ( 'connection' !== $tab && ! IRNoti_API::configured() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'ابتدا در تب «اتصال» کلید API را وارد کنید.', 'irnoti-service' ) . '</p></div>';
		}
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	private static function form_open( string $tab ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'irnoti_save' );
		echo '<input type="hidden" name="action" value="irnoti_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
	}

	private static function row( string $label, string $field_html, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field_html; // phpcs:ignore WordPress.Security.EscapeOutput -- callers escape.
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function input( string $name, string $value, string $type = 'text', string $extra = '' ): string {
		return sprintf( '<input class="regular-text" dir="ltr" type="%s" name="%s" value="%s" %s>', esc_attr( $type ), esc_attr( $name ), esc_attr( $value ), $extra );
	}

	public static function tab_connection(): void {
		self::form_open( 'connection' );
		echo '<table class="form-table">';
		self::row( __( 'آدرس API', 'irnoti-service' ), self::input( 'base_url', (string) IRNoti_Settings::get( 'base_url' ), 'url' ), __( 'پیش‌فرض: https://api.irnoti.com/v1 (فقط https).', 'irnoti-service' ) );
		$has = IRNoti_API::configured();
		self::row( __( 'کلید API', 'irnoti-service' ), self::input( 'api_key', '', 'password', 'autocomplete="new-password" placeholder="' . ( $has ? '•••••• (ذخیره‌شده؛ برای تغییر مقدار جدید وارد کنید)' : 'irn_…' ) . '"' ), __( 'از پنل IRNoti ← کلیدهای API بسازید. scopeهای لازم: sms.send، sms.read، email.send، email.read، bot.read، bot.send.', 'irnoti-service' ) );
		echo '</table>';
		submit_button();
		echo '</form>';
		if ( $has ) {
			$me = IRNoti_API::get( 'email/me' );
			$tx = is_wp_error( $me ) && 401 === ( $me->get_error_data()['status'] ?? 0 ) ? __( 'کلید نامعتبر است.', 'irnoti-service' ) : __( 'کلید ذخیره شده است.', 'irnoti-service' );
			echo '<p>' . esc_html( $tx ) . '</p>';
		}
	}

	public static function tab_sms(): void {
		self::form_open( 'sms' );
		echo '<table class="form-table">';
		self::row( __( 'شناسه خط ارسال', 'irnoti-service' ), self::input( 'sms_line_id', (string) IRNoti_Settings::get( 'sms_line_id' ) ), __( 'خالی = خط پیش‌فرض حساب. «sandbox» برای تست.', 'irnoti-service' ) );
		self::row( __( 'موبایل مدیر (اعلان سفارش جدید)', 'irnoti-service' ), self::input( 'sms_admin_to', (string) IRNoti_Settings::get( 'sms_admin_to' ) ), __( 'چند شماره با ویرگول.', 'irnoti-service' ) );
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$tpl = (array) IRNoti_Settings::get( 'sms_events' );
			foreach ( wc_get_order_statuses() as $slug => $name ) {
				$s = preg_replace( '/^wc-/', '', $slug );
				self::row( sprintf( __( 'پیامک مشتری: %s', 'irnoti-service' ), $name ), '<textarea class="large-text" rows="2" name="sms_events[' . esc_attr( $s ) . ']">' . esc_textarea( $tpl[ $s ] ?? '' ) . '</textarea>', $slug === 'wc-processing' ? __( 'متغیرها: {order_id} {name} {total} {status} {site}. خالی = ارسال نشود.', 'irnoti-service' ) : '' );
			}
		} else {
			echo '<tr><td colspan="2"><em>' . esc_html__( 'برای پیامک وضعیت سفارش، ووکامرس را فعال کنید.', 'irnoti-service' ) . '</em></td></tr>';
		}
		echo '</table>';
		submit_button();
		echo '</form><h2>' . esc_html__( 'ارسال آزمایشی', 'irnoti-service' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'irnoti_sms_test' );
		echo '<input type="hidden" name="action" value="irnoti_sms_test">' . self::input( 'to', '', 'text', 'placeholder="09…"' ) . ' ' . self::input( 'text', 'تست IRNoti' );
		submit_button( __( 'ارسال', 'irnoti-service' ), 'secondary', 'submit', false );
		echo '</form><h2>' . esc_html__( 'ارسال‌های اخیر', 'irnoti-service' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( (array) get_option( IRNoti_SMS::LOG, array() ) as $row ) {
			printf( '<tr><td>%s</td><td dir="ltr">%s</td><td>%s</td><td>%s</td></tr>', esc_html( wp_date( 'Y/m/d H:i', $row['time'] ) ), esc_html( $row['to'] ), esc_html( $row['text'] ), $row['ok'] ? '✔' : esc_html( '✖ ' . $row['error'] ) );
		}
		echo '</tbody></table>';
	}

	public static function tab_bot(): void {
		$bots = IRNoti_API::configured() ? IRNoti_Bot_Sync::bots() : array();
		$list = is_wp_error( $bots ) ? array() : (array) ( $bots['items'] ?? $bots['data'] ?? $bots );
		self::form_open( 'bot' );
		echo '<table class="form-table">';
		if ( is_wp_error( $bots ) ) {
			echo '<tr><td colspan="2"><span style="color:#b32d2e">' . esc_html( $bots->get_error_message() ) . '</span></td></tr>';
		}
		$sel = '<select name="bot_id"><option value="">—</option>';
		foreach ( $list as $b ) {
			if ( ! is_array( $b ) || ! isset( $b['id'] ) ) {
				continue;
			}
			$sel .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $b['id'] ), selected( (string) IRNoti_Settings::get( 'bot_id' ), (string) $b['id'], false ), esc_html( ( $b['name'] ?? $b['username'] ?? '' ) . ' #' . $b['id'] ) );
		}
		self::row( __( 'ربات فروشگاهی', 'irnoti-service' ), $sel . '</select>', __( 'ربات باید از نوع فروشگاهی باشد. ابتدا در پنل IRNoti ربات را بسازید و به بله/تلگرام وصل کنید.', 'irnoti-service' ) );
		self::row( __( 'همگام‌سازی خودکار', 'irnoti-service' ), '<label><input type="checkbox" name="bot_auto_sync" value="1" ' . checked( 1, (int) IRNoti_Settings::get( 'bot_auto_sync' ), false ) . '> ' . esc_html__( 'با هر ذخیره/حذف محصول ووکامرس', 'irnoti-service' ) . '</label>', sprintf( __( 'واحد پول فروشگاه: %s (فقط IRT و IRR پشتیبانی می‌شود).', 'irnoti-service' ), function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '—' ) );
		echo '</table>';
		submit_button();
		echo '</form>';
		if ( class_exists( 'WooCommerce' ) && IRNoti_Bot_Sync::enabled() ) {
			echo '<p><button class="button button-primary" id="irnoti-sync-all">' . esc_html__( 'همگام‌سازی همهٔ محصولات', 'irnoti-service' ) . '</button> <span id="irnoti-sync-status"></span></p><ul id="irnoti-sync-errors"></ul>';
		}
	}

	public static function tab_email(): void {
		self::form_open( 'email' );
		echo '<table class="form-table">';
		self::row( __( 'ارسال ایمیل‌های وردپرس از IRNoti', 'irnoti-service' ), '<label><input type="checkbox" name="email_enabled" value="1" ' . checked( 1, (int) IRNoti_Settings::get( 'email_enabled' ), false ) . '> ' . esc_html__( 'فعال', 'irnoti-service' ) . '</label>', __( 'در صورت خطای API، ارسال به‌صورت خودکار با روش پیش‌فرض وردپرس انجام می‌شود.', 'irnoti-service' ) );
		self::row( __( 'آدرس فرستنده', 'irnoti-service' ), self::input( 'email_from', (string) IRNoti_Settings::get( 'email_from' ), 'email' ), __( 'باید از دامنهٔ تأییدشده در IRNoti باشد.', 'irnoti-service' ) );
		echo '</table>';
		submit_button();
		echo '</form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'irnoti_email_test' );
		echo '<input type="hidden" name="action" value="irnoti_email_test">' . self::input( 'to', (string) wp_get_current_user()->user_email, 'email' ) . ' ';
		submit_button( __( 'ارسال ایمیل آزمایشی', 'irnoti-service' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	public static function tab_voip(): void {
		self::form_open( 'voip' );
		echo '<table class="form-table">';
		self::row( __( 'شمارهٔ خط شما (from)', 'irnoti-service' ), self::input( 'voip_from', (string) IRNoti_Settings::get( 'voip_from' ) ), __( 'شمارهٔ تماس‌گیرندهٔ دوطرفه (کلیک‌تو‌کال).', 'irnoti-service' ) );
		self::row( __( 'آدرس JsSIP (اختیاری)', 'irnoti-service' ), self::input( 'jssip_url', (string) IRNoti_Settings::get( 'jssip_url' ), 'url' ), __( 'اگر فایل assets/js/jssip.min.js را با bin/vendor-jssip.sh نساخته‌اید، آدرس https یک نسخهٔ JsSIP ۳٫x را بدهید.', 'irnoti-service' ) );
		echo '</table>';
		submit_button();
		echo '</form><h2>' . esc_html__( 'کلیک‌تو‌کال', 'irnoti-service' ) . '</h2><p>' . self::input( 'irnoti-ctc-to', '', 'text', 'id="irnoti-ctc-to" placeholder="09…"' ) . ' <button class="button" id="irnoti-ctc">' . esc_html__( 'تماس', 'irnoti-service' ) . '</button> <span id="irnoti-ctc-status"></span></p>';
		echo '<h2>' . esc_html__( 'سافت‌فون', 'irnoti-service' ) . '</h2>';
		if ( ! IRNoti_VoIP::jssip_url() ) {
			echo '<p style="color:#b32d2e">' . esc_html__( 'کتابخانهٔ JsSIP یافت نشد (bin/vendor-jssip.sh یا آدرس بالا).', 'irnoti-service' ) . '</p>';
			return;
		}
		echo '<p><select id="irnoti-sip-acc"></select> <button class="button" id="irnoti-sip-new">' . esc_html__( 'ساخت حساب SIP', 'irnoti-service' ) . '</button> <button class="button button-primary" id="irnoti-sip-connect">' . esc_html__( 'اتصال', 'irnoti-service' ) . '</button> <span id="irnoti-sip-status"></span></p>';
		echo '<p>' . self::input( 'irnoti-sip-dial', '', 'text', 'id="irnoti-sip-dial" placeholder="09…"' ) . ' <button class="button" id="irnoti-sip-call" disabled>' . esc_html__( 'تماس', 'irnoti-service' ) . '</button> <button class="button" id="irnoti-sip-hangup" disabled>' . esc_html__( 'قطع', 'irnoti-service' ) . '</button></p><audio id="irnoti-sip-audio" autoplay></audio>';
	}

	public static function save(): void {
		check_admin_referer( 'irnoti_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		$tab = sanitize_key( $_POST['tab'] ?? 'connection' );
		$p   = wp_unslash( $_POST );
		switch ( $tab ) {
			case 'connection':
				IRNoti_Settings::update( array( 'base_url' => IRNoti_Settings::clean_base_url( (string) ( $p['base_url'] ?? '' ) ) ) );
				$key = trim( (string) ( $p['api_key'] ?? '' ) );
				if ( '' !== $key ) {
					IRNoti_Settings::set_api_key( sanitize_text_field( $key ) );
				}
				break;
			case 'sms':
				$events = array();
				foreach ( (array) ( $p['sms_events'] ?? array() ) as $s => $t ) {
					if ( '' !== trim( (string) $t ) ) {
						$events[ sanitize_key( $s ) ] = sanitize_textarea_field( $t );
					}
				}
				IRNoti_Settings::update( array(
					'sms_line_id'  => sanitize_text_field( $p['sms_line_id'] ?? '' ),
					'sms_admin_to' => sanitize_text_field( $p['sms_admin_to'] ?? '' ),
					'sms_events'   => $events,
				) );
				break;
			case 'bot':
				IRNoti_Settings::update( array( 'bot_id' => preg_replace( '/\D/', '', (string) ( $p['bot_id'] ?? '' ) ), 'bot_auto_sync' => empty( $p['bot_auto_sync'] ) ? 0 : 1 ) );
				break;
			case 'email':
				IRNoti_Settings::update( array( 'email_enabled' => empty( $p['email_enabled'] ) ? 0 : 1, 'email_from' => sanitize_email( $p['email_from'] ?? '' ) ) );
				break;
			case 'voip':
				$url = esc_url_raw( (string) ( $p['jssip_url'] ?? '' ), array( 'https' ) );
				IRNoti_Settings::update( array( 'voip_from' => sanitize_text_field( $p['voip_from'] ?? '' ), 'jssip_url' => $url ) );
				break;
		}
		self::back( $tab, true, __( 'ذخیره شد.', 'irnoti-service' ) );
	}

	public static function sms_test(): void {
		check_admin_referer( 'irnoti_sms_test' );
		current_user_can( 'manage_options' ) || wp_die( 'forbidden', 403 );
		$res = IRNoti_SMS::send( sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['text'] ?? '' ) ) );
		self::back( 'sms', ! is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : __( 'ارسال شد.', 'irnoti-service' ) );
	}

	public static function email_test(): void {
		check_admin_referer( 'irnoti_email_test' );
		current_user_can( 'manage_options' ) || wp_die( 'forbidden', 403 );
		$res = IRNoti_Email::send_test( sanitize_email( wp_unslash( $_POST['to'] ?? '' ) ) );
		self::back( 'email', ! is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : __( 'ایمیل در صف ارسال قرار گرفت.', 'irnoti-service' ) );
	}

	public static function ajax_sync_batch(): void {
		check_ajax_referer( 'irnoti_sync', 'nonce' );
		current_user_can( 'manage_options' ) || wp_send_json_error( null, 403 );
		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( array( 'message' => 'WooCommerce inactive' ) );
		}
		wp_send_json_success( IRNoti_Bot_Sync::sync_batch( max( 1, (int) ( $_POST['page'] ?? 1 ) ) ) );
	}

	private static function back( string $tab, bool $ok, string $msg ): void {
		wp_safe_redirect( self::url( $tab, array( 'ok' => $ok ? 1 : 0, 'irnoti_msg' => rawurlencode( $msg ) ) ) );
		exit;
	}
}
