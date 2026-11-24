<?php
defined( 'ABSPATH' ) || exit;

class IRNoti_Admin {
	const SLUG = 'irnoti';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_head', array( __CLASS__, 'menu_icon_css' ) );
		add_action( 'admin_post_irnoti_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_irnoti_sms_test', array( __CLASS__, 'sms_test' ) );
		add_action( 'admin_post_irnoti_email_test', array( __CLASS__, 'email_test' ) );
		add_action( 'admin_post_irnoti_verify', array( __CLASS__, 'verify_key' ) );
		add_action( 'wp_ajax_irnoti_sync_batch', array( __CLASS__, 'ajax_sync_batch' ) );
		add_action( 'wp_ajax_irnoti_pull_orders', array( __CLASS__, 'ajax_pull_orders' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_menu_page( 'IRNoti', 'IRNoti', 'manage_options', self::SLUG, array( __CLASS__, 'render' ), self::menu_icon(), 58 );
	}

	/**
	 * The official IRNoti logo (same file as the platform's logo.svg, only drawn at 20px). A file URL, not a data URI:
	 * WordPress repaints base64 SVG menu icons in one colour, which would lose the brand colours.
	 */
	private static function menu_icon(): string {
		return IRNOTI_URL . 'assets/img/menu-icon.svg';
	}

	/** Fits the logo into the menu row on every admin page (WordPress shows image icons at their own size and dimmed). */
	public static function menu_icon_css(): void {
		echo '<style>#adminmenu .toplevel_page_irnoti .wp-menu-image img{width:20px;height:20px;padding:7px 0 0;opacity:1;box-sizing:content-box}</style>';
	}

	private static function tabs(): array {
		return array(
			'overview'   => __( 'نمای کلی', 'irnoti-service' ),
			'connection' => __( 'اتصال', 'irnoti-service' ),
			'sms'        => __( 'پیامک', 'irnoti-service' ),
			'bot'        => __( 'ربات و محصولات', 'irnoti-service' ),
			'email'      => __( 'ایمیل', 'irnoti-service' ),
			'widget'     => __( 'ویجت سایت', 'irnoti-service' ),
			'content'    => __( 'محتوا و سئو (AI)', 'irnoti-service' ),
			'voip'       => __( 'VoIP و سافت‌فون', 'irnoti-service' ),
			'health'     => __( 'سلامت سیستم', 'irnoti-service' ),
		);
	}

	private static function icons(): array {
		return array( 'overview' => 'dashboard', 'connection' => 'admin-network', 'sms' => 'smartphone', 'bot' => 'cart', 'email' => 'email-alt', 'widget' => 'format-chat', 'content' => 'chart-area', 'voip' => 'phone', 'health' => 'heart' );
	}

	private static function url( string $tab, array $extra = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $extra ), admin_url( 'admin.php' ) );
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		wp_enqueue_style( 'irnoti-admin', IRNOTI_URL . 'assets/css/admin.css', array(), IRNOTI_VERSION );
		wp_enqueue_script( 'irnoti-admin', IRNOTI_URL . 'assets/js/admin.js', array(), IRNOTI_VERSION, true );
		wp_localize_script( 'irnoti-admin', 'IRNOTI', array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'syncNonce' => wp_create_nonce( 'irnoti_sync' ),
			'voipNonce' => wp_create_nonce( 'irnoti_voip' ),
			'voipFrom'  => (string) IRNoti_Settings::get( 'voip_from' ),
			'widget'    => array(
				'key' => (string) IRNoti_Settings::get( 'widget_key' ),
				'api' => IRNoti_Widget::api_base( IRNoti_Widget::DEFAULT_SRC ),
				'src' => IRNoti_Widget::DEFAULT_SRC,
			),
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
			'overview'   => array( __( 'نمای کلی', 'irnoti-service' ), __( 'وضعیت همهٔ خدمات و مراحل راه‌اندازی در یک نگاه.', 'irnoti-service' ) ),
			'widget'     => array( __( 'ویجت سایت', 'irnoti-service' ), __( 'ویجت IRNoti را روی فروشگاه و سایت خود نمایش دهید.', 'irnoti-service' ) ),
			'connection' => array( __( 'اتصال به IRNoti', 'irnoti-service' ), __( 'کلید API و دریافت رویدادها (وب‌هوک).', 'irnoti-service' ) ),
			'sms'        => array( __( 'پیامک', 'irnoti-service' ), __( 'پیامک سفارش‌ها، ورود با کد یکبارمصرف و ارسال آزمایشی.', 'irnoti-service' ) ),
			'bot'        => array( __( 'ربات و محصولات', 'irnoti-service' ), __( 'همگام‌سازی محصولات و سفارش‌های ووکامرس با ربات فروشگاهی.', 'irnoti-service' ) ),
			'email'      => array( __( 'ایمیل', 'irnoti-service' ), __( 'ارسال ایمیل‌های وردپرس از زیرساخت IRNoti.', 'irnoti-service' ) ),
			'health'     => array( __( 'سلامت سیستم', 'irnoti-service' ), __( 'بررسی خودکار اتصال، دسترسی‌های کلید، ویجت، کارهای زمان‌بندی‌شده و افزونه‌های تداخلی.', 'irnoti-service' ) ),
			'content'    => array( __( 'محتوا و سئو با هوش مصنوعی', 'irnoti-service' ), __( 'امتیاز و مشکلات سئوی نوشته‌ها، محصولات و برگه‌ها، بهینه‌سازی و تولید متن و تصویر با IRNoti، و ترندهای روز.', 'irnoti-service' ) ),
			'voip'       => array( __( 'VoIP و سافت‌فون', 'irnoti-service' ), __( 'تماس، گزارش‌ها، ضبط‌ها و حساب‌های SIP از داخل وردپرس.', 'irnoti-service' ) ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		$meta = self::meta()[ $tab ];
		$st   = IRNoti_API::status();
		$on   = 'ok' === $st['state'];
		$pill = array(
			'ok'      => array( 'on', __( 'متصل', 'irnoti-service' ) ),
			'invalid' => array( 'bad', __( 'کلید API نامعتبر', 'irnoti-service' ) ),
			'error'   => array( 'off', __( 'سرور IRNoti در دسترس نیست', 'irnoti-service' ) ),
			'none'    => array( 'off', __( 'کلید API وارد نشده', 'irnoti-service' ) ),
		)[ $st['state'] ] ?? array( 'off', '' );
		echo '<div class="wrap irn" dir="rtl"><h2 class="screen-reader-text"></h2>';
		echo '<header class="irn-head"><img class="irn-logo" src="' . esc_url( IRNOTI_URL . 'assets/img/icon.png' ) . '" alt="">';
		echo '<h1>IRNoti<small>' . esc_html__( 'ایران ناتیفیکیشن · پیامک، ربات، ایمیل و VoIP', 'irnoti-service' ) . '</small></h1><span class="irn-spacer"></span>';
		printf( '<a class="irn-pill %s" href="%s"><i></i>%s</a>', esc_attr( $pill[0] ), esc_url( self::url( 'connection' ) ), esc_html( $pill[1] ) );
		echo '<span class="irn-pill">v' . esc_html( IRNOTI_VERSION ) . '</span></header>';
		if ( isset( $_GET['irnoti_msg'] ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', ! empty( $_GET['ok'] ) ? 'success' : 'error', esc_html( sanitize_text_field( wp_unslash( $_GET['irnoti_msg'] ) ) ) );
		}
		echo '<nav class="nav-tab-wrapper">';
		$icons = self::icons();
		foreach ( $tabs as $k => $label ) {
			printf( '<a class="nav-tab %s" href="%s"><span class="dashicons dashicons-%s"></span>%s</a>', $k === $tab ? 'nav-tab-active' : '', esc_url( self::url( $k ) ), esc_attr( $icons[ $k ] ), esc_html( $label ) );
		}
		echo '</nav>';
		if ( in_array( $st['state'], array( 'invalid', 'error' ), true ) ) {
			printf( '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>', esc_html( 'invalid' === $st['state'] ? __( 'اتصال به IRNoti برقرار نیست: کلید API نامعتبر است.', 'irnoti-service' ) : __( 'اتصال به IRNoti برقرار نیست.', 'irnoti-service' ) ), esc_html( (string) $st['msg'] ), esc_url( self::url( 'connection' ) ), esc_html__( 'بررسی تنظیمات اتصال', 'irnoti-service' ) );
		} elseif ( ! in_array( $tab, array( 'connection', 'overview', 'widget', 'health', 'content' ), true ) && ! $on ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'ابتدا در تب «اتصال» کلید API را وارد کنید.', 'irnoti-service' ) . '</p></div>';
		}
		echo '<section class="irn-card irn-tab-' . esc_attr( $tab ) . '"><div class="irn-card-head"><span class="irn-card-ico dashicons dashicons-' . esc_attr( $icons[ $tab ] ) . '"></span><div><h2>' . esc_html( $meta[0] ) . '</h2><p>' . esc_html( $meta[1] ) . '</p></div></div>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</section><footer class="irn-foot">' . esc_html__( 'IRNoti Service', 'irnoti-service' ) . ' v' . esc_html( IRNOTI_VERSION ) . ' · <a href="https://irnoti.com" target="_blank" rel="noopener">irnoti.com</a></footer></div>';
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


	/** Tags and attributes the plugin's own admin markup (inputs, buttons, panels) may contain. */
	private static function allowed_html(): array {
		$common = array( 'id' => true, 'class' => true, 'dir' => true, 'style' => true, 'hidden' => true );
		return array(
			'input'   => $common + array( 'type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'autocomplete' => true, 'min' => true, 'max' => true ),
			'button'  => $common + array( 'type' => true, 'disabled' => true ),
			'a'       => $common + array( 'href' => true, 'data-base' => true ),
			'section' => $common + array( 'data-panel' => true ),
			'p'       => $common,
			'h2'      => $common,
			'span'    => $common,
			'div'     => $common,
			'audio'   => $common + array( 'autoplay' => true, 'controls' => true ),
		);
	}

	/** Pretty toggle: a checkbox styled as a switch. */
	private static function switch( string $name, bool $on, string $label ): string {
		return '<label class="irn-switch"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( true, $on, false ) . '><span class="irn-track"></span><span class="irn-switch-label">' . esc_html( $label ) . '</span></label>';
	}

	public static function tab_overview(): void {
		$st    = IRNoti_API::status();
		$on    = 'ok' === $st['state'];
		$rules = count( IRNoti_SMS_Rules::all() );
		$woo   = class_exists( 'WooCommerce' );
		$cards = array(
			array( 'connection', 'admin-network', __( 'اتصال API', 'irnoti-service' ), $on, array( 'ok' => __( 'متصل و تأیید شده', 'irnoti-service' ), 'invalid' => __( 'کلید نامعتبر', 'irnoti-service' ), 'error' => __( 'سرور در دسترس نیست', 'irnoti-service' ) )[ $st['state'] ] ?? __( 'کلید وارد نشده', 'irnoti-service' ), __( 'کلید API و وب‌هوک', 'irnoti-service' ) ),
			array( 'sms', 'smartphone', __( 'پیامک', 'irnoti-service' ), $rules > 0, sprintf( /* translators: %s: rule count */ __( '%s قانون', 'irnoti-service' ), number_format_i18n( $rules ) ), __( 'قوانین رویداد، پترن، ورود با کد یکبارمصرف', 'irnoti-service' ) ),
			array( 'bot', 'cart', __( 'ربات و محصولات', 'irnoti-service' ), '' !== (string) IRNoti_Settings::get( 'bot_id' ), $woo ? __( 'ووکامرس فعال', 'irnoti-service' ) : __( 'ووکامرس غیرفعال', 'irnoti-service' ), __( 'همگام‌سازی محصولات و سفارش‌ها', 'irnoti-service' ) ),
			array( 'email', 'email-alt', __( 'ایمیل', 'irnoti-service' ), (bool) IRNoti_Settings::get( 'email_enabled' ), IRNoti_Settings::get( 'email_enabled' ) ? __( 'فعال', 'irnoti-service' ) : __( 'غیرفعال', 'irnoti-service' ), __( 'ارسال ایمیل‌های وردپرس', 'irnoti-service' ) ),
			array( 'widget', 'format-chat', __( 'ویجت سایت', 'irnoti-service' ), IRNoti_Widget::enabled(), IRNoti_Widget::enabled() ? __( 'نمایش داده می‌شود', 'irnoti-service' ) : __( 'غیرفعال', 'irnoti-service' ), __( 'ویجت IRNoti روی سایت شما', 'irnoti-service' ) ),
			array( 'content', 'chart-area', __( 'محتوا و سئو', 'irnoti-service' ), count( IRNoti_SEO::post_types() ) > 0 && $on, $on ? __( 'هوش مصنوعی فعال', 'irnoti-service' ) : __( 'نیازمند کلید API', 'irnoti-service' ), __( 'امتیاز سئو، بهینه‌سازی، تصویر و ترندها', 'irnoti-service' ) ),
			array( 'voip', 'phone', __( 'VoIP و سافت‌فون', 'irnoti-service' ), '' !== (string) IRNoti_Settings::get( 'voip_from' ), '' !== (string) IRNoti_Settings::get( 'voip_from' ) ? __( 'خط تنظیم شده', 'irnoti-service' ) : __( 'تنظیم نشده', 'irnoti-service' ), __( 'تماس، گزارش و ضبط‌ها', 'irnoti-service' ) ),
		);
		$steps = array(
			array( $on, __( 'کلید API را وارد کنید', 'irnoti-service' ), 'connection' ),
			array( '' !== (string) IRNoti_Settings::webhook_secret(), __( 'وب‌هوک را برای دریافت رویدادها فعال کنید', 'irnoti-service' ), 'connection' ),
			array( '' !== (string) IRNoti_Settings::get( 'sms_line_id' ), __( 'خط پیامک پیش‌فرض را انتخاب کنید', 'irnoti-service' ), 'sms' ),
			array( $rules > 0, __( 'اولین قانون پیامک را بسازید', 'irnoti-service' ), 'sms' ),
			array( IRNoti_Widget::enabled(), __( 'ویجت را روی سایت فعال کنید', 'irnoti-service' ), 'widget' ),
		);
		$done = count( array_filter( array_column( $steps, 0 ) ) );
		$pct  = (int) round( $done / count( $steps ) * 100 );

		if ( $on ) {
			IRNoti_Account::render_cards();
		}
		echo '<div class="irn-hero"><div class="irn-ring" style="--p:' . (int) $pct . '"><b>' . esc_html( number_format_i18n( $pct ) ) . '٪</b></div><div><h3>' . esc_html( $pct >= 100 ? __( 'همه‌چیز آماده است 🎉', 'irnoti-service' ) : __( 'راه‌اندازی را کامل کنید', 'irnoti-service' ) ) . '</h3><p>' . esc_html( sprintf( /* translators: 1: done, 2: total */ __( '%1$s از %2$s مرحله انجام شده است.', 'irnoti-service' ), number_format_i18n( $done ), number_format_i18n( count( $steps ) ) ) ) . '</p></div></div>';

		echo '<div class="irn-grid">';
		foreach ( $cards as $c ) {
			printf(
				'<a class="irn-mod %1$s" href="%2$s"><span class="irn-mod-ico dashicons dashicons-%3$s"></span><span class="irn-mod-body"><strong>%4$s</strong><small>%5$s</small></span><span class="irn-mod-state"><i></i>%6$s</span></a>',
				$c[3] ? 'is-on' : 'is-off',
				esc_url( self::url( $c[0] ) ),
				esc_attr( $c[1] ),
				esc_html( $c[2] ),
				esc_html( $c[5] ),
				esc_html( $c[4] )
			);
		}
		echo '</div><h2>' . esc_html__( 'مراحل راه‌اندازی', 'irnoti-service' ) . '</h2><ol class="irn-steps">';
		foreach ( $steps as $st ) {
			printf( '<li class="%s"><span class="irn-check">%s</span><a href="%s">%s</a></li>', $st[0] ? 'done' : '', $st[0] ? '✓' : '', esc_url( self::url( $st[2] ) ), esc_html( $st[1] ) );
		}
		echo '</ol>';
		if ( $on ) {
			echo '<h2>' . esc_html__( 'هشدار موجودی کم', 'irnoti-service' ) . '</h2>';
			self::form_open( 'account' );
			echo '<table class="form-table">';
			self::row( __( 'حد هشدار (ریال)', 'irnoti-service' ), self::input( 'low_balance', (string) ( (int) IRNoti_Settings::get( 'low_balance' ) ?: '' ), 'text', 'inputmode="numeric" placeholder="500000"' ), __( 'وقتی کیف پول + اعتبار پیامکی از این کمتر شود، روزی یک بار ایمیل هشدار فرستاده می‌شود و کنار مبلغ در نوار ابزار ⚠ می‌آید. خالی = خاموش.', 'irnoti-service' ) );
			self::row( __( 'ایمیل هشدار', 'irnoti-service' ), self::input( 'low_balance_email', (string) IRNoti_Settings::get( 'low_balance_email' ), 'email', 'placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '"' ), __( 'خالی = ایمیل مدیر سایت.', 'irnoti-service' ) );
			echo '</table>';
			submit_button( __( 'ذخیرهٔ هشدار', 'irnoti-service' ) );
			echo '</form>';
		}
	}

	public static function tab_widget(): void {
		$on  = IRNoti_Widget::enabled();
		$src = (string) IRNoti_Settings::get( 'widget_src' );
		self::form_open( 'widget' );
		echo '<div class="irn-split"><div>';
		echo '<table class="form-table">';
		self::row( __( 'وضعیت', 'irnoti-service' ), self::switch( 'widget_enabled', (bool) IRNoti_Settings::get( 'widget_enabled' ), __( 'نمایش ویجت روی سایت', 'irnoti-service' ) ), '' !== (string) IRNoti_Settings::get( 'widget_key' ) || $src ? '' : __( 'ابتدا کد پروژه را وارد کنید.', 'irnoti-service' ) );
		self::row( __( 'کد پروژهٔ ویجت', 'irnoti-service' ), self::input( 'widget_key', (string) IRNoti_Settings::get( 'widget_key' ), 'text', 'placeholder="pj_…"' ), __( 'در پنل IRNoti ← استودیوی عامل ← «ویجت و نصب» کد پروژه (pj_…) را کپی کنید؛ چسباندن کد کامل نصب هم پذیرفته می‌شود. دامنهٔ سایت باید در پروژه مجاز و تأیید شده باشد.', 'irnoti-service' ) );
		echo '</table><h2>' . esc_html__( 'ظاهر و رفتار', 'irnoti-service' ) . '</h2><table class="form-table">';
		$pos = (string) IRNoti_Settings::get( 'widget_position' );
		self::row( __( 'جایگاه پیش‌نمایش', 'irnoti-service' ), '<div class="irn-seg"><label><input type="radio" name="widget_position" value="right" ' . checked( 'right', $pos, false ) . '><span>' . esc_html__( 'راست', 'irnoti-service' ) . '</span></label><label><input type="radio" name="widget_position" value="left" ' . checked( 'left', $pos, false ) . '><span>' . esc_html__( 'چپ', 'irnoti-service' ) . '</span></label></div>', __( 'فقط برای پیش‌نمایش؛ جایگاه، رنگ، متن و ساعت کاری واقعی ویجت را در استودیوی IRNoti تنظیم می‌کنید.', 'irnoti-service' ) );
		$sc  = (string) IRNoti_Settings::get( 'widget_scope' );
		$opt = '';
		foreach ( array( 'all' => __( 'همهٔ صفحات سایت (خودکار، بدون نیاز به شورت‌کد)', 'irnoti-service' ), 'home' => __( 'فقط صفحهٔ نخست', 'irnoti-service' ), 'woo' => __( 'فقط فروشگاه (محصول، سبد، پرداخت)', 'irnoti-service' ) ) as $k => $l ) {
			$opt .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $sc, $k, false ), esc_html( $l ) );
		}
		self::row( __( 'نمایش در', 'irnoti-service' ), '<select name="widget_scope">' . $opt . '</select>' );
		self::row( __( 'شناسهٔ صفحه‌های مستثنا', 'irnoti-service' ), self::input( 'widget_hide_ids', (string) IRNoti_Settings::get( 'widget_hide_ids' ), 'text', 'placeholder="12, 48"' ), __( 'ویجت در این برگه‌ها/نوشته‌ها نمایش داده نمی‌شود (شناسه‌ها با کاما جدا شوند).', 'irnoti-service' ) );
		self::row( __( 'تأخیر بارگذاری', 'irnoti-service' ), '<input type="number" min="0" max="60" name="widget_delay" value="' . (int) IRNoti_Settings::get( 'widget_delay' ) . '" style="width:90px"> ' . esc_html__( 'ثانیه', 'irnoti-service' ), __( '۰ یعنی همزمان با صفحه. تأخیر کوتاه سرعت اولیهٔ سایت را بهتر می‌کند.', 'irnoti-service' ) );
		self::row( __( 'مدیران', 'irnoti-service' ), self::switch( 'widget_hide_admin', (bool) IRNoti_Settings::get( 'widget_hide_admin' ), __( 'برای مدیران سایت نمایش نده', 'irnoti-service' ) ), __( 'اگر روشن باشد، وقتی با حساب مدیر وارد شده‌اید ویجت را نمی‌بینید. برای آزمایش خاموشش کنید یا در پنجرهٔ ناشناس سایت را باز کنید.', 'irnoti-service' ) );
		echo '</table>';
		submit_button( __( 'ذخیرهٔ تنظیمات ویجت', 'irnoti-service' ) );
		echo '</div><aside class="irn-preview"><div class="irn-browser"><div class="irn-browser-bar"><i></i><i></i><i></i></div><div class="irn-browser-body"><span class="l1"></span><span class="l2"></span><span class="l3"></span><div class="irn-bubble ' . ( 'left' === $pos ? 'left' : 'right' ) . ' ' . ( $on ? '' : 'off' ) . '"><img src="' . esc_url( IRNOTI_URL . 'assets/img/icon.png' ) . '" alt=""></div></div></div>';
		echo '<p class="irn-preview-cap">' . esc_html( $on ? __( 'ویجت فعال است و در سایت نمایش داده می‌شود.', 'irnoti-service' ) : __( 'ویجت غیرفعال است.', 'irnoti-service' ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'برای قراردادن ویجت در محل دلخواه از شورت‌کد زیر استفاده کنید:', 'irnoti-service' ) . '</p><p><code dir="ltr">[irnoti_widget]</code></p></aside></div></form>';
		echo '<section class="irn-live"><h2>' . esc_html__( 'تست زنده', 'irnoti-service' ) . '</h2><p class="description">' . esc_html__( 'همان درخواستی که مرورگر بازدیدکننده می‌زند از همین صفحه فرستاده می‌شود و پاسخ یا خطای سرور (مثل validation_error یا دامنهٔ غیرمجاز) همین‌جا نشان داده می‌شود. «نمایش ویجت» خود ویجت را روی همین صفحهٔ مدیریت باز می‌کند.', 'irnoti-service' ) . '</p>';
		echo '<p><button type="button" class="button button-primary" id="irnoti-w-check">' . esc_html__( 'بررسی ویجت', 'irnoti-service' ) . '</button> <button type="button" class="button" id="irnoti-w-show">' . esc_html__( 'نمایش ویجت در همین صفحه', 'irnoti-service' ) . '</button> <a class="button" href="' . esc_url( self::url( 'health' ) ) . '">' . esc_html__( 'بررسی سلامت کامل', 'irnoti-service' ) . '</a></p><div id="irnoti-w-out"></div></section>';
	}

	public static function tab_health(): void {
		$rows  = IRNoti_Health::run();
		$count = array( 'ok' => 0, 'warn' => 0, 'bad' => 0, 'info' => 0 );
		foreach ( $rows as $r ) {
			++$count[ $r['state'] ];
		}
		$cls = $count['bad'] ? 'bad' : ( $count['warn'] ? 'warn' : 'ok' );
		$ttl = $count['bad'] ? __( 'مشکل‌هایی پیدا شد که باید رفع شوند', 'irnoti-service' ) : ( $count['warn'] ? __( 'کار می‌کند، با چند هشدار', 'irnoti-service' ) : __( 'همه‌چیز سالم است', 'irnoti-service' ) );
		printf(
			'<div class="irn-health-sum %1$s"><span class="irn-health-dot"></span><div><h3>%2$s</h3><p>%3$s</p></div><span class="irn-spacer"></span><a class="button" href="%4$s">%5$s</a> <button type="button" class="button" id="irnoti-copy-report">%6$s</button></div>',
			esc_attr( $cls ),
			esc_html( $ttl ),
			/* translators: 1: passed, 2: warnings, 3: failures */
			esc_html( sprintf( __( '%1$s سالم · %2$s هشدار · %3$s خطا', 'irnoti-service' ), number_format_i18n( $count['ok'] ), number_format_i18n( $count['warn'] ), number_format_i18n( $count['bad'] ) ) ),
			esc_url( self::url( 'health' ) ),
			esc_html__( 'اجرای دوباره', 'irnoti-service' ),
			esc_html__( 'کپی گزارش برای پشتیبانی', 'irnoti-service' )
		);
		$label = array( 'ok' => __( 'سالم', 'irnoti-service' ), 'warn' => __( 'هشدار', 'irnoti-service' ), 'bad' => __( 'خطا', 'irnoti-service' ), 'info' => __( 'اطلاع', 'irnoti-service' ) );
		$badge = array( 'ok' => 'ok', 'warn' => 'warn', 'bad' => 'bad', 'info' => '' );
		$grp   = '';
		foreach ( $rows as $r ) {
			if ( $r['group'] !== $grp ) {
				echo $grp ? '</tbody></table>' : '';
				$grp = $r['group'];
				echo '<h2>' . esc_html( $grp ) . '</h2><table class="widefat irn-health"><tbody>';
			}
			printf(
				'<tr class="is-%1$s"><td class="irn-h-state"><span class="irn-badge %2$s">%3$s</span></td><th>%4$s</th><td><span dir="auto">%5$s</span>%6$s</td></tr>',
				esc_attr( $r['state'] ),
				esc_attr( $badge[ $r['state'] ] ),
				esc_html( $label[ $r['state'] ] ),
				esc_html( $r['label'] ),
				esc_html( $r['detail'] ),
				( 'ok' !== $r['state'] && 'info' !== $r['state'] && $r['hint'] ) ? '<p class="irn-h-hint">' . esc_html( $r['hint'] ) . '</p>' : ''
			);
		}
		echo $grp ? '</tbody></table>' : '';
		echo '<textarea id="irnoti-report" class="screen-reader-text" readonly>' . esc_textarea( IRNoti_Health::report( $rows ) ) . '</textarea>';
	}

	public static function tab_content(): void {
		IRNoti_Admin_SEO::render_tab();
	}

	public static function tab_connection(): void {
		self::form_open( 'connection' );
		echo '<table class="form-table">';
		self::row( __( 'آدرس API', 'irnoti-service' ), self::input( 'base_url', (string) IRNoti_Settings::get( 'base_url' ), 'url' ), __( 'پیش‌فرض: https://api.irnoti.com/v1 (فقط https).', 'irnoti-service' ) );
		$has = IRNoti_API::configured();
		self::row( __( 'کلید API', 'irnoti-service' ), self::input( 'api_key', '', 'password', 'autocomplete="new-password" placeholder="' . ( $has ? '•••••• (ذخیره‌شده؛ برای تغییر مقدار جدید وارد کنید)' : 'irn_…' ) . '"' ), __( 'از پنل IRNoti ← کلیدهای API بسازید. scopeهای لازم: sms.send، sms.read، email.send، email.read، bot.read، bot.send؛ برای نمایش موجودی: wallet.read و ai.read؛ برای محتوا و سئو با هوش مصنوعی: ai.read، ai.chat و ai.image.', 'irnoti-service' ) );
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
			$st  = IRNoti_API::status();
			$cls = array( 'ok' => 'ok', 'invalid' => 'bad', 'error' => 'warn' )[ $st['state'] ] ?? '';
			$tx  = array( 'ok' => __( 'کلید معتبر است و اتصال برقرار است.', 'irnoti-service' ), 'invalid' => __( 'کلید API نامعتبر است.', 'irnoti-service' ), 'error' => __( 'به سرور IRNoti وصل نشد.', 'irnoti-service' ) )[ $st['state'] ] ?? '';
			printf(
				'<p class="irn-keystate"><span class="irn-badge %s">%s</span> %s <small>%s</small> <a class="button button-small" href="%s">%s</a></p>',
				esc_attr( $cls ),
				esc_html( $tx ),
				esc_html( (string) $st['msg'] ),
				esc_html( isset( $st['time'] ) ? sprintf( /* translators: %s: time of the last check */ __( 'آخرین بررسی: %s', 'irnoti-service' ), wp_date( 'Y/m/d H:i', (int) $st['time'] ) ) : '' ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=irnoti_verify' ), 'irnoti_verify' ) ),
				esc_html__( 'بررسی دوباره', 'irnoti-service' )
			);
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
		self::row( __( 'همگام‌سازی خودکار', 'irnoti-service' ), '<label><input type="checkbox" name="bot_auto_sync" value="1" ' . checked( 1, (int) IRNoti_Settings::get( 'bot_auto_sync' ), false ) . '> ' . esc_html__( 'با هر ذخیره/حذف محصول و هر تغییر موجودی در ووکامرس', 'irnoti-service' ) . '</label>', sprintf( /* translators: %s: store currency code */ __( 'واحد پول فروشگاه: %s (فقط IRT و IRR پشتیبانی می‌شود).', 'irnoti-service' ), function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '—' ) );
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
		echo wp_kses( '<input type="hidden" name="action" value="irnoti_email_test">' . self::input( 'to', (string) wp_get_current_user()->user_email, 'email' ) . ' ', self::allowed_html() );
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

		echo wp_kses( '<section data-panel="phone"><h2>' . esc_html__( 'کلیک‌تو‌کال (تماس دوطرفه از سرور)', 'irnoti-service' ) . '</h2><p>' . self::input( 'irnoti-ctc-to', '', 'text', 'id="irnoti-ctc-to" placeholder="09…"' ) . ' <button class="button" id="irnoti-ctc">' . esc_html__( 'تماس', 'irnoti-service' ) . '</button> <span id="irnoti-ctc-status"></span></p>', self::allowed_html() );
		echo '<h2>' . esc_html__( 'سافت‌فون', 'irnoti-service' ) . '</h2>';
		if ( ! IRNoti_VoIP::jssip_url() ) {
			echo '<p style="color:#b32d2e">' . esc_html__( 'کتابخانهٔ JsSIP یافت نشد (bin/vendor-jssip.sh یا آدرس در «تنظیمات»).', 'irnoti-service' ) . '</p>';
		} else {
			echo '<p><select id="irnoti-sip-acc"></select> <button class="button button-primary" id="irnoti-sip-connect">' . esc_html__( 'اتصال', 'irnoti-service' ) . '</button> <span id="irnoti-sip-status"></span></p>';
			echo wp_kses( '<p>' . self::input( 'irnoti-sip-dial', '', 'text', 'id="irnoti-sip-dial" placeholder="09…"' ) . ' <button class="button" id="irnoti-sip-call" disabled>' . esc_html__( 'تماس', 'irnoti-service' ) . '</button> <button class="button" id="irnoti-sip-hangup" disabled>' . esc_html__( 'قطع', 'irnoti-service' ) . '</button></p><audio id="irnoti-sip-audio" autoplay></audio>', self::allowed_html() );
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
		echo wp_kses( self::input( 'irnoti-rep-q', '', 'text', 'id="irnoti-rep-q" placeholder="شماره" style="width:120px"' ) . ' <button class="button button-primary" id="irnoti-rep-go">' . esc_html__( 'نمایش', 'irnoti-service' ) . '</button> <a class="button" id="irnoti-rep-csv" href="#" data-base="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=irnoti_voip_csv' ), 'irnoti_voip' ) ) . '">CSV</a></p><div id="irnoti-rep-summary"></div><div id="irnoti-rep-table"></div><p><button class="button" id="irnoti-rep-more" hidden>' . esc_html__( 'بیشتر', 'irnoti-service' ) . '</button></p></section>', self::allowed_html() );

		echo '<section data-panel="recordings" hidden><p><select id="irnoti-rec-kind"><option value="">' . esc_html__( 'همه', 'irnoti-service' ) . '</option><option value="call">' . esc_html__( 'ضبط تماس', 'irnoti-service' ) . '</option><option value="voicemail">' . esc_html__( 'پیام‌گیر', 'irnoti-service' ) . '</option></select> <button class="button" id="irnoti-rec-go">' . esc_html__( 'نمایش', 'irnoti-service' ) . '</button></p><div id="irnoti-rec"></div><audio id="irnoti-rec-audio" controls hidden></audio></section>';

		echo wp_kses( '<section data-panel="blocklist" hidden><p>' . self::input( 'irnoti-block-number', '', 'text', 'id="irnoti-block-number" placeholder="09…"' ) . ' ' . self::input( 'irnoti-block-reason', '', 'text', 'id="irnoti-block-reason" placeholder="دلیل (اختیاری)"' ) . ' <button class="button" id="irnoti-block-add">' . esc_html__( 'مسدود کن', 'irnoti-service' ) . '</button></p><div id="irnoti-block"></div></section>', self::allowed_html() );

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
				if ( '' !== $key || isset( $p['base_url'] ) ) {
					self::back_status( IRNoti_API::verify() );
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
			case 'account':
				$digits = strtr( (string) ( $p['low_balance'] ?? '' ), array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
				IRNoti_Settings::update( array(
					'low_balance'       => (int) preg_replace( '/\D/', '', $digits ),
					'low_balance_email' => sanitize_email( $p['low_balance_email'] ?? '' ),
				) );
				IRNoti_Account::schedule();
				self::back( 'overview', true, __( 'ذخیره شد.', 'irnoti-service' ) );
				break;
			case 'widget':
				$raw    = trim( (string) ( $p['widget_key'] ?? '' ) );
				$parsed = IRNoti_Widget::parse_embed( $raw );
				$code   = $parsed['src'] ? (string) ( $parsed['attrs']['data-agent'] ?? '' ) : $raw;
				$code   = preg_replace( '/[^A-Za-z0-9_-]/', '', $code );
				if ( '' !== $code && ! IRNoti_Widget::valid_key( $code ) ) {
					self::back( $tab, false, __( 'کد پروژهٔ ویجت درست نیست: باید با pj_ (یا ag_) شروع شود و ۲۰ تا ۴۰ نویسهٔ انگلیسی، عدد یا خط تیره بعدش بیاید. کد را از استودیوی IRNoti کامل کپی کنید.', 'irnoti-service' ) );
				}
				unset( $parsed['attrs']['data-agent'] );
				if ( '' === $parsed['src'] && '' !== $code ) {
					$parsed['src'] = IRNoti_Widget::DEFAULT_SRC;
				}
				$scopes = array( 'all', 'home', 'woo' );
				IRNoti_Settings::update( array(
					'widget_enabled'    => empty( $p['widget_enabled'] ) ? 0 : 1,
					'widget_src'        => $parsed['src'],
					'widget_attrs'      => array_map( 'sanitize_text_field', $parsed['attrs'] ),
					'widget_key'        => $code,
					'widget_position'   => 'left' === ( $p['widget_position'] ?? '' ) ? 'left' : 'right',
					'widget_scope'      => in_array( $p['widget_scope'] ?? '', $scopes, true ) ? $p['widget_scope'] : 'all',
					'widget_hide_admin' => empty( $p['widget_hide_admin'] ) ? 0 : 1,
					'widget_hide_ids'   => preg_replace( '/[^0-9,\s]/', '', (string) ( $p['widget_hide_ids'] ?? '' ) ),
					'widget_delay'      => max( 0, min( 60, (int) ( $p['widget_delay'] ?? 0 ) ) ),
				) );
				if ( '' === $parsed['src'] && '' !== $raw ) {
					self::back( $tab, false, __( 'کد جاسازی معتبر نیست؛ باید آدرس https داشته باشد.', 'irnoti-service' ) );
				}
				break;
			case 'seo':
				IRNoti_Admin_SEO::save_settings( $tab, $p );
				break;
			case 'voip':
				$url = esc_url_raw( (string) ( $p['jssip_url'] ?? '' ), array( 'https' ) );
				IRNoti_Settings::update( array( 'voip_from' => sanitize_text_field( $p['voip_from'] ?? '' ), 'jssip_url' => $url ) );
				break;
		}
		self::back( 'seo' === $tab ? 'content' : $tab, true, __( 'ذخیره شد.', 'irnoti-service' ) );
	}

	public static function verify_key(): void {
		check_admin_referer( 'irnoti_verify' );
		current_user_can( 'manage_options' ) || wp_die( 'forbidden', 403 );
		self::back_status( IRNoti_API::verify() );
	}

	/** Redirects to the connection tab with the outcome of a key check. */
	private static function back_status( array $st ): void {
		$msg = array(
			'ok'      => __( 'ذخیره شد؛ کلید API معتبر است و اتصال برقرار است.', 'irnoti-service' ),
			'invalid' => __( 'کلید API نامعتبر است. کلید را از پنل IRNoti ← کلیدهای API دوباره کپی کنید.', 'irnoti-service' ),
			'error'   => __( 'ذخیره شد، ولی اتصال به سرور IRNoti برقرار نشد:', 'irnoti-service' ) . ' ' . ( $st['msg'] ?? '' ),
		)[ $st['state'] ] ?? __( 'ذخیره شد.', 'irnoti-service' );
		self::back( 'connection', 'ok' === $st['state'], $msg );
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
		wp_send_json_success( IRNoti_Bot_Sync::sync_batch( max( 1, absint( wp_unslash( $_POST['page'] ?? 1 ) ) ) ) );
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
