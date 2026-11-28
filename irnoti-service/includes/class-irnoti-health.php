<?php
defined( 'ABSPATH' ) || exit;

/**
 * System health: one list of checks (environment, API key and scopes, widget, scheduled jobs, plugins known to interfere),
 * each with a state (ok / warn / bad / info), what was found, and what to do about it.
 */
class IRNoti_Health {
	/** Speed/cache plugins that rewrite or block third-party scripts: plugin path fragment => name. */
	const OPTIMIZERS = array(
		'zhaket-booster'   => 'Zhaket Booster',
		'zh-booster'       => 'Zhaket Booster',
		'wp-rocket'        => 'WP Rocket',
		'litespeed-cache'  => 'LiteSpeed Cache',
		'autoptimize'      => 'Autoptimize',
		'perfmatters'      => 'Perfmatters',
		'flying-scripts'   => 'Flying Scripts',
		'w3-total-cache'   => 'W3 Total Cache',
		'wp-optimize'      => 'WP-Optimize',
		'sg-cachepress'    => 'SiteGround Optimizer',
		'nitropack'        => 'NitroPack',
		'hummingbird'      => 'Hummingbird',
	);

	private static array $rows = array();

	private static function add( string $group, string $label, string $state, string $detail, string $hint = '' ): void {
		self::$rows[] = compact( 'group', 'label', 'state', 'detail', 'hint' );
	}

	/** Runs every check. */
	public static function run(): array {
		self::$rows = array();
		self::environment();
		$ok = self::connection();
		if ( $ok ) {
			self::scopes();
		}
		self::widget();
		self::rank();
		self::jobs();
		self::plugins();
		return self::$rows;
	}

	private static function environment(): void {
		$g = __( 'محیط سرور', 'irnoti-service' );
		self::add( $g, 'PHP', version_compare( PHP_VERSION, '8.0', '>=' ) ? 'ok' : 'bad', PHP_VERSION, __( 'حداقل PHP 8.0 لازم است.', 'irnoti-service' ) );
		global $wp_version;
		self::add( $g, __( 'وردپرس', 'irnoti-service' ), version_compare( $wp_version, '6.3', '>=' ) ? 'ok' : 'bad', (string) $wp_version, __( 'حداقل وردپرس 6.3 لازم است.', 'irnoti-service' ) );
		self::add( $g, 'OpenSSL', function_exists( 'openssl_encrypt' ) ? 'ok' : 'bad', function_exists( 'openssl_encrypt' ) ? __( 'فعال', 'irnoti-service' ) : __( 'غیرفعال', 'irnoti-service' ), __( 'برای رمزنگاری کلید API لازم است؛ از میزبان بخواهید افزونهٔ openssl را فعال کند.', 'irnoti-service' ) );
		$https = 0 === strpos( home_url(), 'https://' );
		self::add( $g, 'HTTPS', $https ? 'ok' : 'warn', home_url(), __( 'سایت روی https نیست؛ ویجت و ورود با کد روی http ممکن است در مرورگرها محدود شود.', 'irnoti-service' ) );
	}

	private static function connection(): bool {
		$g = __( 'اتصال به IRNoti', 'irnoti-service' );
		if ( ! IRNoti_API::configured() ) {
			self::add( $g, __( 'کلید API', 'irnoti-service' ), 'bad', __( 'وارد نشده', 'irnoti-service' ), __( 'در تب «اتصال» کلید API را وارد کنید.', 'irnoti-service' ) );
			return false;
		}
		$t0 = microtime( true );
		$st = IRNoti_API::verify();
		$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$base = (string) IRNoti_Settings::get( 'base_url' );
		if ( 'ok' === $st['state'] ) {
			/* translators: 1: API address, 2: response time in ms */
			self::add( $g, __( 'کلید و سرور', 'irnoti-service' ), $ms > 5000 ? 'warn' : 'ok', sprintf( __( '%1$s · پاسخ در %2$s میلی‌ثانیه', 'irnoti-service' ), $base, number_format_i18n( $ms ) ), __( 'پاسخ سرور کند است؛ اتصال اینترنت/فایروال میزبان را بررسی کنید.', 'irnoti-service' ) );
			return true;
		}
		if ( 'invalid' === $st['state'] ) {
			self::add( $g, __( 'کلید API', 'irnoti-service' ), 'bad', __( 'نامعتبر (401)', 'irnoti-service' ), __( 'کلید را از پنل IRNoti ← کلیدهای API دوباره کپی کنید؛ ممکن است حذف یا غیرفعال شده باشد.', 'irnoti-service' ) );
		} else {
			self::add( $g, __( 'دسترسی به سرور', 'irnoti-service' ), 'bad', $base . ' — ' . (string) $st['msg'], __( 'آدرس API را بررسی کنید (پیش‌فرض https://api.irnoti.com/v1) و از میزبان بپرسید درخواست‌های خروجی به api.irnoti.com را مسدود نکرده باشد.', 'irnoti-service' ) );
		}
		return false;
	}

	/** One request per scope: 403 means the key lacks it, anything else that is not 401 means it has it. */
	private static function scopes(): void {
		$g     = __( 'دسترسی‌های کلید (scope)', 'irnoti-service' );
		$need  = array(
			'sms.read'    => array( 'sms/lines', __( 'خطوط و گزارش پیامک', 'irnoti-service' ), true ),
			'wallet.read' => array( 'wallet', __( 'نمایش موجودی و هشدار موجودی کم', 'irnoti-service' ), false ),
			'ai.read'     => array( 'ai/balance', __( 'نمایش توکن هوش مصنوعی', 'irnoti-service' ), false ),
			'email.read'  => array( 'email/me', __( 'سهمیهٔ ایمیل', 'irnoti-service' ), (bool) IRNoti_Settings::get( 'email_enabled' ) ),
			'bot.read'    => array( 'botbuilder/bots', __( 'ربات فروشگاهی', 'irnoti-service' ), '' !== (string) IRNoti_Settings::get( 'bot_id' ) ),
		);
		foreach ( $need as $scope => $n ) {
			$res = IRNoti_API::get( $n[0] );
			$code = is_wp_error( $res ) ? (int) ( $res->get_error_data()['status'] ?? 0 ) : 200;
			if ( 403 === $code ) {
				self::add( $g, $scope, $n[2] ? 'bad' : 'warn', __( 'ندارد', 'irnoti-service' ) . ' — ' . $n[1], __( 'در پنل IRNoti ← کلیدهای API این دسترسی را به کلید بدهید (یا کلید تازه بسازید).', 'irnoti-service' ) );
			} elseif ( is_wp_error( $res ) && 404 !== $code ) {
				self::add( $g, $scope, 'warn', $res->get_error_message(), '' );
			} else {
				self::add( $g, $scope, 'ok', __( 'دارد', 'irnoti-service' ) . ' — ' . $n[1] );
			}
		}
	}

	private static function widget(): void {
		$g   = __( 'ویجت سایت', 'irnoti-service' );
		$key = (string) IRNoti_Settings::get( 'widget_key' );
		if ( ! IRNoti_Settings::get( 'widget_enabled' ) ) {
			self::add( $g, __( 'وضعیت', 'irnoti-service' ), 'info', __( 'خاموش', 'irnoti-service' ) );
			if ( '' === $key ) {
				return;
			}
		}
		if ( '' === $key ) {
			self::add( $g, __( 'کد پروژه', 'irnoti-service' ), 'bad', __( 'وارد نشده', 'irnoti-service' ), __( 'کد pj_… را از استودیوی IRNoti ← ویجت و نصب کپی کنید.', 'irnoti-service' ) );
			return;
		}
		if ( ! IRNoti_Widget::valid_key( $key ) ) {
			self::add( $g, __( 'کد پروژه', 'irnoti-service' ), 'bad', $key, __( 'قالب کد درست نیست (سرور خطای validation_error می‌دهد). کد باید با pj_ یا ag_ شروع شود و ۲۰ تا ۴۰ نویسهٔ انگلیسی/عدد/خط تیره بعدش باشد؛ کد را دوباره و کامل کپی کنید.', 'irnoti-service' ) );
			return;
		}
		self::add( $g, __( 'کد پروژه', 'irnoti-service' ), 'ok', $key );

		// The same request the visitor's browser makes, with this site's Origin.
		$origin = untrailingslashit( (string) preg_replace( '#^(https?://[^/]+).*$#', '$1', home_url() ) );
		$url    = IRNoti_Widget::api_base( IRNoti_Widget::DEFAULT_SRC ) . '/embed/agents/' . rawurlencode( $key );
		$res    = wp_safe_remote_get( $url, array( 'timeout' => 15, 'headers' => array( 'Origin' => $origin, 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $res ) ) {
			self::add( $g, __( 'پاسخ سرور ویجت', 'irnoti-service' ), 'bad', $res->get_error_message(), __( 'سرور وردپرس به api.irnoti.com وصل نشد.', 'irnoti-service' ) );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( 200 === $code ) {
				$name = is_array( $body ) ? (string) ( $body['name'] ?? '' ) : '';
				/* translators: 1: operator name, 2: site origin */
				self::add( $g, __( 'پاسخ سرور ویجت', 'irnoti-service' ), 'ok', sprintf( __( 'ویجت «%1$s» برای %2$s فعال است.', 'irnoti-service' ), $name, $origin ) );
			} elseif ( 404 === $code ) {
				/* translators: %s: site origin */
				self::add( $g, __( 'پاسخ سرور ویجت', 'irnoti-service' ), 'bad', sprintf( __( 'پیدا نشد برای %s (404)', 'irnoti-service' ), $origin ), __( 'یکی از این‌ها: کد پروژه اشتباه است، پروژه یا اپراتور خاموش است، پروژه اپراتور فعالی ندارد، یا دامنهٔ این سایت در «دامنه‌های مجاز» پروژه نیست (با و بدون www هر دو را اضافه کنید).', 'irnoti-service' ) );
			} else {
				$msg = is_array( $body ) ? (string) ( $body['message'] ?? $body['code'] ?? '' ) : '';
				self::add( $g, __( 'پاسخ سرور ویجت', 'irnoti-service' ), 'bad', sprintf( 'HTTP %d %s', $code, $msg ), '' );
			}
		}

		self::add( $g, __( 'درج خودکار در سایت', 'irnoti-service' ), has_action( 'wp_enqueue_scripts', array( 'IRNoti_Widget', 'maybe_enqueue' ) ) ? 'ok' : 'bad', has_action( 'wp_enqueue_scripts', array( 'IRNoti_Widget', 'maybe_enqueue' ) ) ? __( 'فعال', 'irnoti-service' ) : __( 'غیرفعال', 'irnoti-service' ), __( 'افزونه را به آخرین نسخه به‌روز کنید.', 'irnoti-service' ) );
		$scope = array( 'all' => __( 'همهٔ صفحات', 'irnoti-service' ), 'home' => __( 'فقط صفحهٔ نخست', 'irnoti-service' ), 'woo' => __( 'فقط فروشگاه', 'irnoti-service' ) )[ (string) IRNoti_Settings::get( 'widget_scope' ) ] ?? '';
		self::add( $g, __( 'محدودهٔ نمایش', 'irnoti-service' ), 'info', $scope );
		if ( IRNoti_Settings::get( 'widget_hide_admin' ) ) {
			self::add( $g, __( 'مدیران', 'irnoti-service' ), 'warn', __( 'برای مدیران مخفی است', 'irnoti-service' ), __( 'وقتی با حساب مدیر وارد شده‌اید ویجت را نمی‌بینید؛ برای آزمایش در پنجرهٔ ناشناس باز کنید.', 'irnoti-service' ) );
		}
	}

	/** The link to the site's IRNoti Rank report: token, last round, and its schedule. Nothing is called here; it reads what the last round stored. */
	private static function rank(): void {
		$g = __( 'رتبهٔ سایت', 'irnoti-service' );
		if ( ! IRNoti_Rank::configured() ) {
			self::add( $g, __( 'اتصال', 'irnoti-service' ), 'info', __( 'وصل نیست', 'irnoti-service' ), __( 'اختیاری: در تب «رتبهٔ سایت» توکن را وارد کنید تا سلامت سایت خوانده شود و تغییرهای سئوی تأییدشده اعمال شود.', 'irnoti-service' ) );
			return;
		}
		$st = IRNoti_Rank::state();
		if ( empty( $st ) ) {
			self::add( $g, __( 'اتصال', 'irnoti-service' ), 'warn', __( 'هنوز همگام‌سازی نشده', 'irnoti-service' ), __( 'در تب «رتبهٔ سایت» دکمهٔ «همگام‌سازی همین حالا» را بزنید.', 'irnoti-service' ) );
		} elseif ( empty( $st['ok'] ) ) {
			self::add( $g, __( 'اتصال', 'irnoti-service' ), 'bad', (string) ( $st['msg'] ?? '' ), __( 'توکن را در استودیوی IRNoti دوباره بسازید و در تب «رتبهٔ سایت» وارد کنید.', 'irnoti-service' ) );
		} else {
			self::add( $g, __( 'اتصال', 'irnoti-service' ), empty( $st['pro'] ) ? 'warn' : 'ok', empty( $st['pro'] ) ? __( 'متصل، ولی پلن حرفه‌ای فعال نیست', 'irnoti-service' ) : __( 'متصل', 'irnoti-service' ), empty( $st['pro'] ) ? __( 'پلن حرفه‌ای رتبهٔ این دامنه را در استودیو فعال کنید.', 'irnoti-service' ) : '' );
		}
		if ( ! wp_next_scheduled( IRNoti_Rank::CRON ) ) {
			self::add( $g, __( 'همگام‌سازی ساعتی', 'irnoti-service' ), 'warn', __( 'زمان‌بندی نشده', 'irnoti-service' ), __( 'افزونه را یک بار غیرفعال و فعال کنید.', 'irnoti-service' ) );
		}
	}

	private static function jobs(): void {
		$g = __( 'کارهای زمان‌بندی‌شده', 'irnoti-service' );
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			self::add( $g, 'WP-Cron', 'warn', 'DISABLE_WP_CRON', __( 'کرون داخلی وردپرس خاموش است؛ مطمئن شوید یک کرون واقعی روی سرور wp-cron.php را صدا می‌زند، وگرنه دریافت سفارش‌های ربات و هشدار موجودی اجرا نمی‌شوند.', 'irnoti-service' ) );
		} else {
			self::add( $g, 'WP-Cron', 'ok', __( 'فعال', 'irnoti-service' ) );
		}
		foreach ( array( 'irnoti_pull_orders' => __( 'دریافت سفارش‌های ربات', 'irnoti-service' ), IRNoti_Account::CRON => __( 'بررسی موجودی کم', 'irnoti-service' ) ) as $hook => $label ) {
			$ts = wp_next_scheduled( $hook );
			if ( $ts ) {
				$late = $ts < time() - 30 * MINUTE_IN_SECONDS;
				/* translators: %s: date and time of the next run */
				self::add( $g, $label, $late ? 'warn' : 'ok', sprintf( __( 'اجرای بعدی: %s', 'irnoti-service' ), wp_date( 'Y/m/d H:i', $ts ) ), __( 'اجرا عقب افتاده؛ کرون سایت درست کار نمی‌کند.', 'irnoti-service' ) );
			}
		}
	}

	private static function plugins(): void {
		$g      = __( 'افزونه‌های دیگر', 'irnoti-service' );
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$found = array();
		foreach ( $active as $p ) {
			foreach ( self::OPTIMIZERS as $frag => $name ) {
				if ( false !== stripos( (string) $p, $frag ) ) {
					$found[ $name ] = true;
				}
			}
		}
		foreach ( array_keys( $found ) as $name ) {
			self::add( $g, $name, IRNoti_Settings::get( 'widget_enabled' ) ? 'warn' : 'info', __( 'فعال', 'irnoti-service' ), __( 'این افزونه ممکن است اسکریپت‌های بیرونی را تأخیری، کپی یا مسدود کند. دامنهٔ api.irnoti.com را در «استثناها/عدم بهینه‌سازی» آن بگذارید و اگر در فهرست «مسدود/کند» آن آمده، حذفش کنید؛ بعد کشش را پاک کنید.', 'irnoti-service' ) );
		}
		if ( IRNoti_Settings::get( 'email_enabled' ) ) {
			foreach ( array( 'wp-mail-smtp', 'post-smtp', 'easy-wp-smtp', 'fluent-smtp' ) as $smtp ) {
				foreach ( $active as $p ) {
					if ( false !== stripos( (string) $p, $smtp ) ) {
						self::add( $g, $smtp, 'warn', __( 'فعال', 'irnoti-service' ), __( 'یک افزونهٔ SMTP دیگر هم فعال است؛ ممکن است ارسال ایمیل از IRNoti را دور بزند. یکی را نگه دارید.', 'irnoti-service' ) );
					}
				}
			}
		}
		if ( ! $found ) {
			self::add( $g, __( 'افزونه‌های بهینه‌ساز', 'irnoti-service' ), 'ok', __( 'افزونهٔ تداخلی شناخته‌شده‌ای پیدا نشد', 'irnoti-service' ) );
		}
	}

	/** Plain-text report for support. */
	public static function report( array $rows ): string {
		$mark = array( 'ok' => '[OK]  ', 'warn' => '[WARN]', 'bad' => '[FAIL]', 'info' => '[INFO]' );
		$out  = 'IRNoti Service ' . IRNOTI_VERSION . ' · ' . home_url() . ' · ' . gmdate( 'Y-m-d H:i' ) . " UTC\n";
		$grp  = '';
		foreach ( $rows as $r ) {
			if ( $r['group'] !== $grp ) {
				$grp  = $r['group'];
				$out .= "\n== " . $grp . "\n";
			}
			$out .= ( $mark[ $r['state'] ] ?? '' ) . ' ' . $r['label'] . ': ' . $r['detail'] . "\n";
		}
		return $out;
	}
}
