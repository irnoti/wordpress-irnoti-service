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
		add_action( 'wp_ajax_irnoti_pull_orders', array( __CLASS__, 'ajax_pull_orders' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_menu_page( 'IRNoti', 'IRNoti', 'manage_options', self::SLUG, array( __CLASS__, 'render' ), IRNOTI_URL . 'assets/img/icon.png', 58 );
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
		wp_enqueue_style( 'irnoti-admin', IRNOTI_URL . 'assets/css/admin.css', array(), IRNOTI_VERSION );
		wp_enqueue_script( 'irnoti-admin', IRNOTI_URL . 'assets/js/admin.js', array(), IRNOTI_VERSION, true );
		wp_localize_script( 'irnoti-admin', 'IRNOTI', array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'syncNonce' => wp_create_nonce( 'irnoti_sync' ),
			'voipNonce' => wp_create_nonce( 'irnoti_voip' ),
			'voipFrom'  => (string) IRNoti_Settings::get( 'voip_from' ),
		) );
		if ( 'sms' === $tab && isset( $_GET['rule'] ) ) {
			wp_enqueue_script( 'irnoti-sms-rules', IRNOTI_URL . 'assets/js/sms-rules.js', array(), IRNOTI_VERSION, true );
			wp_localize_script( 'irnoti-sms-rules', 'IRNOTI_SMS', IRNoti_Admin_SMS::script_data() );
		}
		if ( 'voip' === $tab && IRNoti_VoIP::jssip_url() ) {
			wp_enqueue_script( 'irnoti-jssip', IRNoti_VoIP::jssip_url(), array(), null, true );
		}
	}

	private static function meta(): array {
		return array(
			'connection' => array( __( 'اتصال به IRNoti', 'irnoti-service' ), __( 'کلید API و دریافت رویدادها (وب‌هوک).', 'irnoti-service' ) ),
			'sms'        => array( __( 'پیامک', 'irnoti-service' ), __( 'پیامک سفارش‌ها، ورود با کد یکبارمصرف و ارسال آزمایشی.', 'irnoti-service' ) ),
			'bot'        => array( __( 'ربات و محصولات', 'irnoti-service' ), __( 'همگام‌سازی محصولات و سفارش‌های ووکامرس با ربات فروشگاهی.', 'irnoti-service' ) ),
			'email'      => array( __( 'ایمیل', 'irnoti-service' ), __( 'ارسال ایمیل‌های وردپرس از زیرساخت IRNoti.', 'irnoti-service' ) ),
			'voip'       => array( __( 'VoIP و سافت‌فون', 'irnoti-service' ), __( 'تماس، گزارش‌ها، ضبط‌ها و حساب‌های SIP از داخل وردپرس.', 'irnoti-service' ) ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'connection';
		$meta = self::meta()[ $tab ];
		$on   = IRNoti_API::configured();
		echo '<div class="wrap irn" dir="rtl"><h2 class="screen-reader-text"></h2>';
		echo '<header class="irn-head"><img class="irn-logo" src="' . esc_url( IRNOTI_URL . 'assets/img/icon.png' ) . '" alt="">';
		echo '<h1>IRNoti<small>' . esc_html__( 'ایران ناتیفیکیشن · پیامک، ربات، ایمیل و VoIP', 'irnoti-service' ) . '</small></h1><span class="irn-spacer"></span>';
		printf( '<span class="irn-pill %s"><i></i>%s</span>', $on ? 'on' : 'off', esc_html( $on ? __( 'متصل', 'irnoti-service' ) : __( 'اتصال برقرار نیست', 'irnoti-service' ) ) );
		echo '<span class="irn-pill">v' . esc_html( IRNOTI_VERSION ) . '</span></header>';
		if ( isset( $_GET['irnoti_msg'] ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', ! empty( $_GET['ok'] ) ? 'success' : 'error', esc_html( wp_unslash( $_GET['irnoti_msg'] ) ) );
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $k === $tab ? 'nav-tab-active' : '', esc_url( self::url( $k ) ), esc_html( $label ) );
		}
		echo '</nav>';
		if ( 'connection' !== $tab && ! $on ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'ابتدا در تب «اتصال» کلید API را وارد کنید.', 'irnoti-service' ) . '</p></div>';
		}
		echo '<section class="irn-card"><div class="irn-card-head"><h2>' . esc_html( $meta[0] ) . '</h2><p>' . esc_html( $meta[1] ) . '</p></div>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</section></div>';
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
		echo '<h2>' . esc_html__( 'وب‌هوک (رویدادهای تحویل پیامک، ایمیل، تماس)', 'irnoti-service' ) . '</h2>';
		echo '<p>' . esc_html__( 'در پنل IRNoti ← وب‌هوک‌ها یک وب‌هوک با این آدرس بسازید و راز امضا را اینجا وارد کنید:', 'irnoti-service' ) . '</p><p><code dir="ltr">' . esc_html( IRNoti_Webhooks::endpoint() ) . '</code></p>';
		self::form_open( 'connection' );
		echo '<table class="form-table">';
		self::row( __( 'راز امضای وب‌هوک', 'irnoti-service' ), self::input( 'webhook_secret', '', 'password', 'autocomplete="new-password" placeholder="' . ( IRNoti_Settings::webhook_secret() ? '••••••' : '' ) . '"' ) );
		echo '</table>';
		submit_button();
		echo '</form>';
		$events = (array) get_option( IRNoti_Webhooks::LOG, array() );
		if ( $events ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $events as $e ) {
				printf( '<tr><td>%s</td><td dir="ltr">%s</td><td dir="ltr">%s</td></tr>', esc_html( wp_date( 'Y/m/d H:i:s', $e['time'] ) ), esc_html( $e['event'] ), esc_html( $e['brief'] ) );
			}
			echo '</tbody></table>';
		}
		if ( $has ) {
			$me = IRNoti_API::get( 'email/me' );
			$tx = is_wp_error( $me ) && 401 === ( $me->get_error_data()['status'] ?? 0 ) ? __( 'کلید نامعتبر است.', 'irnoti-service' ) : __( 'کلید ذخیره شده است.', 'irnoti-service' );
			echo '<p>' . esc_html( $tx ) . '</p>';
		}
	}

	public static function tab_sms(): void {
		IRNoti_Admin_SMS::render();
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
		self::row( __( 'همگام‌سازی خودکار', 'irnoti-service' ), '<label><input type="checkbox" name="bot_auto_sync" value="1" ' . checked( 1, (int) IRNoti_Settings::get( 'bot_auto_sync' ), false ) . '> ' . esc_html__( 'با هر ذخیره/حذف محصول و هر تغییر موجودی در ووکامرس', 'irnoti-service' ) . '</label>', sprintf( __( 'واحد پول فروشگاه: %s (فقط IRT و IRR پشتیبانی می‌شود).', 'irnoti-service' ), function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '—' ) );
		self::row( __( 'دریافت سفارش‌های ربات', 'irnoti-service' ), '<label><input type="checkbox" name="bot_pull_orders" value="1" ' . checked( 1, (int) IRNoti_Settings::get( 'bot_pull_orders' ), false ) . '> ' . esc_html__( 'هر ۵ دقیقه سفارش‌های ربات در ووکامرس ساخته شود و وضعیت‌ها دوطرفه همگام شود', 'irnoti-service' ) . '</label>' );
		echo '</table>';
		submit_button();
		echo '</form>';
		if ( class_exists( 'WooCommerce' ) && IRNoti_Bot_Sync::enabled() ) {
			self::bot_orders_table();
			echo '<p><button class="button" id="irnoti-pull-orders">' . esc_html__( 'دریافت سفارش‌ها همین حالا', 'irnoti-service' ) . '</button> <span id="irnoti-pull-status"></span></p>';
			echo '<p><button class="button button-primary" id="irnoti-sync-all">' . esc_html__( 'همگام‌سازی همهٔ محصولات', 'irnoti-service' ) . '</button> <span id="irnoti-sync-status"></span></p><ul id="irnoti-sync-errors"></ul>';
		}
	}

	private static function bot_orders_table(): void {
		$rows = IRNoti_Bot_Orders::recent( 15 );
		echo '<h2>' . esc_html__( 'آخرین سفارش‌های ربات', 'irnoti-service' ) . '</h2>';
		if ( is_wp_error( $rows ) ) {
			echo '<p style="color:#C0260B">' . esc_html( $rows->get_error_message() ) . '</p>';
			return;
		}
		$b = array( 'new' => array( 'brand', 'جدید' ), 'confirmed' => array( 'warn', 'تأیید شده' ), 'shipped' => array( 'warn', 'ارسال شده' ), 'done' => array( 'ok', 'تحویل شده' ), 'canceled' => array( 'bad', 'لغو شده' ) );
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'مشتری', 'irnoti-service' ) . '</th><th>' . esc_html__( 'مبلغ (ریال)', 'irnoti-service' ) . '</th><th>' . esc_html__( 'وضعیت ربات', 'irnoti-service' ) . '</th><th>' . esc_html__( 'سفارش ووکامرس', 'irnoti-service' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $o ) {
			$st = $b[ $o['status'] ?? '' ] ?? array( '', (string) ( $o['status'] ?? '' ) );
			$wc = ! empty( $o['wc'] ) ? '<a href="' . esc_url( get_edit_post_link( (int) $o['wc'], 'raw' ) ?: admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $o['wc'] ) ) . '">#' . (int) $o['wc'] . '</a>' : '<span class="irn-badge warn">' . esc_html__( 'هنوز وارد نشده', 'irnoti-service' ) . '</span>';
			printf( '<tr><td>%s</td><td>%s<br><small dir="ltr">%s</small></td><td>%s</td><td><span class="irn-badge %s">%s</span></td><td>%s</td></tr>', esc_html( (string) ( $o['number'] ?? $o['id'] ?? '' ) ), esc_html( (string) ( $o['customerName'] ?? '' ) ), esc_html( (string) ( $o['phone'] ?? '' ) ), esc_html( number_format_i18n( (float) ( $o['total'] ?? 0 ) ) ), esc_attr( $st[0] ), esc_html( $st[1] ), $wc ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5" class="irn-empty">—</td></tr>';
		}
		echo '</tbody></table>';
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
		$sections = array(
			'phone'      => __( 'تلفن', 'irnoti-service' ),
			'live'       => __( 'تماس‌های زنده', 'irnoti-service' ),
			'reports'    => __( 'گزارش تماس', 'irnoti-service' ),
			'recordings' => __( 'ضبط‌ها و پیام‌گیر', 'irnoti-service' ),
			'blocklist'  => __( 'لیست مسدود', 'irnoti-service' ),
			'accounts'   => __( 'حساب‌های SIP', 'irnoti-service' ),
			'settings'   => __( 'تنظیمات', 'irnoti-service' ),
		);
		echo '<div id="irnoti-voip"><p class="irnoti-subnav">';
		foreach ( $sections as $k => $label ) {
			printf( '<button type="button" class="button" data-sec="%s">%s</button> ', esc_attr( $k ), esc_html( $label ) );
		}
		echo '</p>';

		echo '<section data-panel="phone"><h2>' . esc_html__( 'کلیک‌تو‌کال (تماس دوطرفه از سرور)', 'irnoti-service' ) . '</h2><p>' . self::input( 'irnoti-ctc-to', '', 'text', 'id="irnoti-ctc-to" placeholder="09…"' ) . ' <button class="button" id="irnoti-ctc">' . esc_html__( 'تماس', 'irnoti-service' ) . '</button> <span id="irnoti-ctc-status"></span></p>';
		echo '<h2>' . esc_html__( 'سافت‌فون', 'irnoti-service' ) . '</h2>';
		if ( ! IRNoti_VoIP::jssip_url() ) {
			echo '<p style="color:#b32d2e">' . esc_html__( 'کتابخانهٔ JsSIP یافت نشد (bin/vendor-jssip.sh یا آدرس در «تنظیمات»).', 'irnoti-service' ) . '</p>';
		} else {
			echo '<p><select id="irnoti-sip-acc"></select> <button class="button button-primary" id="irnoti-sip-connect">' . esc_html__( 'اتصال', 'irnoti-service' ) . '</button> <span id="irnoti-sip-status"></span></p>';
			echo '<p>' . self::input( 'irnoti-sip-dial', '', 'text', 'id="irnoti-sip-dial" placeholder="09…"' ) . ' <button class="button" id="irnoti-sip-call" disabled>' . esc_html__( 'تماس', 'irnoti-service' ) . '</button> <button class="button" id="irnoti-sip-hangup" disabled>' . esc_html__( 'قطع', 'irnoti-service' ) . '</button></p><audio id="irnoti-sip-audio" autoplay></audio>';
		}
		$inbound = array_filter( (array) get_transient( 'irnoti_inbound' ) );
		if ( $inbound ) {
			echo '<h3>' . esc_html__( 'آخرین تماس‌های ورودی (وب‌هوک)', 'irnoti-service' ) . '</h3><ul>';
			foreach ( $inbound as $c ) {
				printf( '<li dir="ltr">%s — %s → %s</li>', esc_html( wp_date( 'Y/m/d H:i', $c['time'] ) ), esc_html( $c['from'] ), esc_html( $c['to'] ) );
			}
			echo '</ul>';
		}
		echo '</section>';

		echo '<section data-panel="live" hidden><p><button class="button" id="irnoti-live-refresh">' . esc_html__( 'بروزرسانی', 'irnoti-service' ) . '</button> <label><input type="checkbox" id="irnoti-live-auto" checked> ' . esc_html__( 'خودکار هر ۵ ثانیه', 'irnoti-service' ) . '</label></p><div id="irnoti-live"></div></section>';

		echo '<section data-panel="reports" hidden><p><label>' . esc_html__( 'از', 'irnoti-service' ) . ' <input type="date" id="irnoti-rep-from"></label> <label>' . esc_html__( 'تا', 'irnoti-service' ) . ' <input type="date" id="irnoti-rep-to"></label> ';
		echo '<select id="irnoti-rep-dir"><option value="">' . esc_html__( 'همه جهت‌ها', 'irnoti-service' ) . '</option><option value="in">' . esc_html__( 'ورودی', 'irnoti-service' ) . '</option><option value="out">' . esc_html__( 'خروجی', 'irnoti-service' ) . '</option></select> ';
		echo '<select id="irnoti-rep-status"><option value="">' . esc_html__( 'همه وضعیت‌ها', 'irnoti-service' ) . '</option><option value="answered">answered</option><option value="missed">missed</option><option value="failed">failed</option></select> ';
		echo self::input( 'irnoti-rep-q', '', 'text', 'id="irnoti-rep-q" placeholder="شماره" style="width:120px"' ) . ' <button class="button button-primary" id="irnoti-rep-go">' . esc_html__( 'نمایش', 'irnoti-service' ) . '</button> <a class="button" id="irnoti-rep-csv" href="#" data-base="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=irnoti_voip_csv' ), 'irnoti_voip' ) ) . '">CSV</a></p><div id="irnoti-rep-summary"></div><div id="irnoti-rep-table"></div><p><button class="button" id="irnoti-rep-more" hidden>' . esc_html__( 'بیشتر', 'irnoti-service' ) . '</button></p></section>';

		echo '<section data-panel="recordings" hidden><p><select id="irnoti-rec-kind"><option value="">' . esc_html__( 'همه', 'irnoti-service' ) . '</option><option value="call">' . esc_html__( 'ضبط تماس', 'irnoti-service' ) . '</option><option value="voicemail">' . esc_html__( 'پیام‌گیر', 'irnoti-service' ) . '</option></select> <button class="button" id="irnoti-rec-go">' . esc_html__( 'نمایش', 'irnoti-service' ) . '</button></p><div id="irnoti-rec"></div><audio id="irnoti-rec-audio" controls hidden></audio></section>';

		echo '<section data-panel="blocklist" hidden><p>' . self::input( 'irnoti-block-number', '', 'text', 'id="irnoti-block-number" placeholder="09…"' ) . ' ' . self::input( 'irnoti-block-reason', '', 'text', 'id="irnoti-block-reason" placeholder="دلیل (اختیاری)"' ) . ' <button class="button" id="irnoti-block-add">' . esc_html__( 'مسدود کن', 'irnoti-service' ) . '</button></p><div id="irnoti-block"></div></section>';

		echo '<section data-panel="accounts" hidden><p><button class="button" id="irnoti-sip-new">' . esc_html__( 'ساخت حساب SIP', 'irnoti-service' ) . '</button></p><div id="irnoti-accounts"></div></section>';

		echo '<section data-panel="settings" hidden>';
		self::form_open( 'voip' );
		echo '<table class="form-table">';
		self::row( __( 'شمارهٔ خط شما (from)', 'irnoti-service' ), self::input( 'voip_from', (string) IRNoti_Settings::get( 'voip_from' ) ), __( 'شمارهٔ تماس‌گیرندهٔ دوطرفه (کلیک‌تو‌کال).', 'irnoti-service' ) );
		self::row( __( 'آدرس JsSIP (اختیاری)', 'irnoti-service' ), self::input( 'jssip_url', (string) IRNoti_Settings::get( 'jssip_url' ), 'url' ), __( 'اگر assets/js/jssip.min.js را با bin/vendor-jssip.sh نساخته‌اید، آدرس https یک JsSIP ۳٫x را بدهید.', 'irnoti-service' ) );
		echo '</table>';
		submit_button();
		echo '</form></section></div>';
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
				if ( isset( $p['base_url'] ) ) {
					IRNoti_Settings::update( array( 'base_url' => IRNoti_Settings::clean_base_url( (string) $p['base_url'] ) ) );
				}
				$key = trim( (string) ( $p['api_key'] ?? '' ) );
				if ( '' !== $key ) {
					IRNoti_Settings::set_api_key( sanitize_text_field( $key ) );
				}
				$wh = trim( (string) ( $p['webhook_secret'] ?? '' ) );
				if ( '' !== $wh ) {
					IRNoti_Settings::set_webhook_secret( sanitize_text_field( $wh ) );
				}
				break;
			case 'sms':
				IRNoti_Settings::update( array(
					'sms_line_id'  => sanitize_text_field( $p['sms_line_id'] ?? '' ),
					'sms_admin_to' => sanitize_text_field( $p['sms_admin_to'] ?? '' ),
					'otp_login'    => empty( $p['otp_login'] ) ? 0 : 1,
					'otp_pattern'  => sanitize_text_field( $p['otp_pattern'] ?? '' ),
				) );
				break;
			case 'bot':
				IRNoti_Settings::update( array( 'bot_id' => preg_replace( '/\D/', '', (string) ( $p['bot_id'] ?? '' ) ), 'bot_auto_sync' => empty( $p['bot_auto_sync'] ) ? 0 : 1, 'bot_pull_orders' => empty( $p['bot_pull_orders'] ) ? 0 : 1 ) );
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

	public static function ajax_pull_orders(): void {
		check_ajax_referer( 'irnoti_sync', 'nonce' );
		current_user_can( 'manage_options' ) || wp_send_json_error( null, 403 );
		$n = IRNoti_Bot_Orders::pull();
		is_wp_error( $n ) ? wp_send_json_error( array( 'message' => $n->get_error_message() ) ) : wp_send_json_success( array( 'new' => $n ) );
	}

	private static function back( string $tab, bool $ok, string $msg ): void {
		wp_safe_redirect( self::url( $tab, array( 'ok' => $ok ? 1 : 0, 'irnoti_msg' => rawurlencode( $msg ) ) ) );
		exit;
	}
}
