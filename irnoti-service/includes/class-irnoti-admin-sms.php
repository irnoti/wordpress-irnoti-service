<?php
defined( 'ABSPATH' ) || exit;

/** SMS tab: rules (event → text/pattern), pattern manager, settings and log. */
class IRNoti_Admin_SMS {
	public static function init(): void {
		add_action( 'admin_post_irnoti_rule_save', array( __CLASS__, 'rule_save' ) );
		add_action( 'admin_post_irnoti_rule_toggle', array( __CLASS__, 'rule_toggle' ) );
		add_action( 'admin_post_irnoti_rule_delete', array( __CLASS__, 'rule_delete' ) );
		add_action( 'admin_post_irnoti_pattern_create', array( __CLASS__, 'pattern_create' ) );
		add_action( 'wp_ajax_irnoti_sms_quote', array( __CLASS__, 'ajax_quote' ) );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'irnoti', 'tab' => 'sms' ), $args ), admin_url( 'admin.php' ) );
	}

	private static function back( array $args, bool $ok, string $msg ): void {
		wp_safe_redirect( self::url( array_merge( $args, array( 'ok' => $ok ? 1 : 0, 'irnoti_msg' => rawurlencode( $msg ) ) ) ) );
		exit;
	}

	private static function guard( string $nonce ): void {
		check_admin_referer( $nonce );
		current_user_can( 'manage_options' ) || wp_die( 'forbidden', 403 );
	}

	private static function lines(): array {
		$res = IRNoti_API::configured() ? IRNoti_API::get( 'sms/lines' ) : array();
		return is_wp_error( $res ) ? array() : array_filter( (array) ( $res['items'] ?? $res ), 'is_array' );
	}

	private static function patterns(): array {
		$res = IRNoti_API::configured() ? IRNoti_API::get( 'patterns' ) : array();
		return is_wp_error( $res ) ? array() : array_filter( (array) ( $res['items'] ?? $res ), 'is_array' );
	}

	/** Script data for the rule editor. */
	public static function script_data(): array {
		$events = array();
		foreach ( IRNoti_SMS_Rules::events() as $k => $v ) {
			$events[ $k ] = array( 'label' => $v[0], 'type' => $v[1] );
		}
		$vars = $samples = array();
		foreach ( array( 'order', 'product', 'user' ) as $t ) {
			$vars[ $t ]    = IRNoti_SMS_Rules::variables( $t );
			$samples[ $t ] = IRNoti_SMS_Rules::sample( $t );
		}
		$pat = array();
		foreach ( self::patterns() as $p ) {
			if ( 'approved' === ( $p['status'] ?? '' ) ) {
				$pat[] = array( 'code' => (string) $p['code'], 'name' => (string) ( $p['name'] ?? '' ), 'body' => (string) ( $p['body'] ?? '' ) );
			}
		}
		return array( 'events' => $events, 'vars' => $vars, 'samples' => $samples, 'patterns' => $pat, 'nonce' => wp_create_nonce( 'irnoti_sms_quote' ) );
	}

	public static function render(): void {
		$sub  = isset( $_GET['sub'] ) ? sanitize_key( $_GET['sub'] ) : 'rules';
		$subs = array(
			'rules'    => __( 'قوانین پیامک', 'irnoti-service' ),
			'patterns' => __( 'الگوها (پترن)', 'irnoti-service' ),
			'settings' => __( 'تنظیمات', 'irnoti-service' ),
			'log'      => __( 'ارسال و گزارش', 'irnoti-service' ),
		);
		$sub = isset( $subs[ $sub ] ) ? $sub : 'rules';
		echo '<p class="irnoti-subnav">';
		foreach ( $subs as $k => $label ) {
			printf( '<a class="button %s" href="%s">%s</a> ', $k === $sub ? 'is-active' : '', esc_url( self::url( array( 'sub' => $k ) ) ), esc_html( $label ) );
		}
		echo '</p>';
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'ووکامرس فعال نیست؛ رویدادهای فروشگاه در دسترس نیستند ولی رویدادهای وردپرس (ثبت‌نام کاربر) کار می‌کنند.', 'irnoti-service' ) . '</p></div>';
		}
		call_user_func( array( __CLASS__, 'sub_' . $sub ) );
	}

	/* ---------- rules ---------- */

	public static function sub_rules(): void {
		if ( isset( $_GET['rule'] ) ) {
			self::editor( sanitize_text_field( wp_unslash( $_GET['rule'] ) ) );
			return;
		}
		$rules  = IRNoti_SMS_Rules::all();
		$events = IRNoti_SMS_Rules::events();
		echo '<p><a class="button button-primary" href="' . esc_url( self::url( array( 'rule' => 'new' ) ) ) . '">+ ' . esc_html__( 'قانون جدید', 'irnoti-service' ) . '</a></p>';
		if ( ! $rules ) {
			echo '<p class="irn-empty">' . esc_html__( 'هنوز قانونی نساخته‌اید. مثلاً «وقتی سفارش به در حال انجام تغییر کرد، به مشتری پیامک برود».', 'irnoti-service' ) . '</p>';
			return;
		}
		$who = array( 'customer' => __( 'مشتری', 'irnoti-service' ), 'admin' => __( 'مدیر', 'irnoti-service' ), 'custom' => __( 'شمارهٔ دلخواه', 'irnoti-service' ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'نام', 'irnoti-service' ) . '</th><th>' . esc_html__( 'رویداد', 'irnoti-service' ) . '</th><th>' . esc_html__( 'گیرنده', 'irnoti-service' ) . '</th><th>' . esc_html__( 'نوع', 'irnoti-service' ) . '</th><th>' . esc_html__( 'وضعیت', 'irnoti-service' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rules as $r ) {
			$tog = wp_nonce_url( admin_url( 'admin-post.php?action=irnoti_rule_toggle&id=' . $r['id'] ), 'irnoti_rule' );
			$del = wp_nonce_url( admin_url( 'admin-post.php?action=irnoti_rule_delete&id=' . $r['id'] ), 'irnoti_rule' );
			printf(
				'<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td><span class="irn-badge %s">%s</span></td><td><a href="%s"><span class="irn-badge %s">%s</span></a></td><td><a class="button button-small" href="%s">%s</a> <a class="button button-small" href="%s" onclick="return confirm(\'%s\')">%s</a></td></tr>',
				esc_html( $r['name'] ),
				esc_html( $events[ $r['event'] ][0] ?? $r['event'] ),
				esc_html( $who[ $r['recipient'] ] ?? '' ),
				'pattern' === $r['mode'] ? 'brand' : '',
				esc_html( 'pattern' === $r['mode'] ? __( 'پترن', 'irnoti-service' ) : __( 'متن', 'irnoti-service' ) ),
				esc_url( $tog ),
				$r['enabled'] ? 'ok' : 'warn',
				esc_html( $r['enabled'] ? __( 'فعال', 'irnoti-service' ) : __( 'غیرفعال', 'irnoti-service' ) ),
				esc_url( self::url( array( 'rule' => $r['id'] ) ) ),
				esc_html__( 'ویرایش', 'irnoti-service' ),
				esc_url( $del ),
				esc_js( __( 'حذف شود؟', 'irnoti-service' ) ),
				esc_html__( 'حذف', 'irnoti-service' )
			);
		}
		echo '</tbody></table>';
	}

	private static function editor( string $id ): void {
		$rule = 'new' === $id ? IRNoti_SMS_Rules::blank() : IRNoti_SMS_Rules::find( $id );
		if ( ! $rule ) {
			echo '<p class="irn-empty">' . esc_html__( 'قانون پیدا نشد.', 'irnoti-service' ) . '</p>';
			return;
		}
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		echo '<form method="post" id="irnoti-rule-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'irnoti_rule' );
		echo '<input type="hidden" name="action" value="irnoti_rule_save"><input type="hidden" name="id" value="' . esc_attr( 'new' === $id ? '' : $rule['id'] ) . '"><table class="form-table">';

		self::row( __( 'نام قانون', 'irnoti-service' ), '<input class="regular-text" type="text" name="name" value="' . esc_attr( $rule['name'] ) . '">' );

		$sel = '<select name="event" id="irnoti-r-event">';
		foreach ( IRNoti_SMS_Rules::events() as $k => $v ) {
			$sel .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $rule['event'], $k, false ), esc_html( $v[0] ) );
		}
		self::row( __( 'وقتی…', 'irnoti-service' ), $sel . '</select>' );

		$rc = '<select name="recipient" id="irnoti-r-recipient">';
		foreach ( array( 'customer' => __( 'مشتری / کاربر', 'irnoti-service' ), 'admin' => __( 'مدیر (از تنظیمات)', 'irnoti-service' ), 'custom' => __( 'شمارهٔ دلخواه', 'irnoti-service' ) ) as $k => $l ) {
			$rc .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $rule['recipient'], $k, false ), esc_html( $l ) );
		}
		self::row( __( 'پیامک برود به', 'irnoti-service' ), $rc . '</select> <input type="text" dir="ltr" name="custom_to" id="irnoti-r-custom" placeholder="09…, 09…" value="' . esc_attr( $rule['custom_to'] ) . '">' );

		self::row( __( 'نوع ارسال', 'irnoti-service' ), '<label><input type="radio" name="mode" value="text" ' . checked( 'text', $rule['mode'], false ) . '> ' . esc_html__( 'متن آزاد', 'irnoti-service' ) . '</label> &nbsp; <label><input type="radio" name="mode" value="pattern" ' . checked( 'pattern', $rule['mode'], false ) . '> ' . esc_html__( 'الگو (پترن) تأییدشده', 'irnoti-service' ) . '</label>' );

		echo '<tr data-for="text"><th>' . esc_html__( 'متن پیامک', 'irnoti-service' ) . '</th><td><textarea class="large-text" rows="4" name="text" id="irnoti-r-text">' . esc_textarea( $rule['text'] ) . '</textarea><div class="irnoti-chips" data-target="irnoti-r-text"></div><p class="irnoti-count" id="irnoti-r-count"></p></td></tr>';
		echo '<tr data-for="pattern"><th>' . esc_html__( 'الگو', 'irnoti-service' ) . '</th><td><select name="pattern" id="irnoti-r-pattern"><option value="">—</option></select><p class="description">' . esc_html__( 'فقط الگوهای تأییدشده. الگوی جدید را در تب «الگوها» بسازید.', 'irnoti-service' ) . '</p><div id="irnoti-r-params"></div></td></tr>';

		echo '<tr><th>' . esc_html__( 'پیش‌نمایش', 'irnoti-service' ) . '</th><td><div class="irnoti-preview" id="irnoti-r-preview"></div></td></tr>';

		echo '<tr data-only="order"><th>' . esc_html__( 'شرط‌ها', 'irnoti-service' ) . '</th><td>';
		echo '<p><label>' . esc_html__( 'حداقل مبلغ سفارش', 'irnoti-service' ) . ' <input type="number" min="0" step="any" name="min_total" value="' . esc_attr( $rule['min_total'] ) . '"></label></p>';
		$pm = '<select name="payment_method"><option value="">' . esc_html__( 'همهٔ روش‌های پرداخت', 'irnoti-service' ) . '</option>';
		foreach ( $gateways as $gid => $g ) {
			$pm .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $gid ), selected( $rule['payment_method'], $gid, false ), esc_html( $g->get_title() ) );
		}
		echo '<p><label>' . esc_html__( 'روش پرداخت', 'irnoti-service' ) . ' ' . $pm . '</select></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '<p><label>' . esc_html__( 'منبع سفارش', 'irnoti-service' ) . ' <select name="source"><option value="any" ' . selected( 'any', $rule['source'], false ) . '>' . esc_html__( 'همه', 'irnoti-service' ) . '</option><option value="web" ' . selected( 'web', $rule['source'], false ) . '>' . esc_html__( 'فقط سایت', 'irnoti-service' ) . '</option><option value="bot" ' . selected( 'bot', $rule['source'], false ) . '>' . esc_html__( 'فقط ربات', 'irnoti-service' ) . '</option></select></label></p>';
		echo '</td></tr>';

		self::row( __( 'فعال', 'irnoti-service' ), '<label><input type="checkbox" name="enabled" value="1" ' . checked( 1, (int) $rule['enabled'], false ) . '> ' . esc_html__( 'این قانون اجرا شود', 'irnoti-service' ) . '</label>' );
		echo '</table>';
		echo '<script>window.IRNOTI_RULE=' . wp_json_encode( array( 'params' => (object) $rule['params'], 'pattern' => $rule['pattern'] ) ) . ';</script>';
		submit_button( __( 'ذخیرهٔ قانون', 'irnoti-service' ) );
		echo '</form>';
	}

	private static function row( string $label, string $html ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- callers escape.
	}

	public static function rule_save(): void {
		self::guard( 'irnoti_rule' );
		$rule  = IRNoti_SMS_Rules::sanitize( wp_unslash( $_POST ) );
		$rules = IRNoti_SMS_Rules::all();
		$found = false;
		foreach ( $rules as $i => $r ) {
			if ( $r['id'] === $rule['id'] ) {
				$rules[ $i ] = $rule;
				$found       = true;
			}
		}
		$found || $rules[] = $rule;
		if ( 'pattern' === $rule['mode'] && '' === $rule['pattern'] ) {
			self::back( array( 'rule' => $rule['id'] ), false, __( 'برای نوع «الگو» یک الگو انتخاب کنید.', 'irnoti-service' ) );
		}
		IRNoti_SMS_Rules::save_all( $rules );
		self::back( array(), true, __( 'قانون ذخیره شد.', 'irnoti-service' ) );
	}

	public static function rule_toggle(): void {
		self::guard( 'irnoti_rule' );
		$id    = sanitize_text_field( $_GET['id'] ?? '' );
		$rules = IRNoti_SMS_Rules::all();
		foreach ( $rules as $i => $r ) {
			if ( $r['id'] === $id ) {
				$rules[ $i ]['enabled'] = $r['enabled'] ? 0 : 1;
			}
		}
		IRNoti_SMS_Rules::save_all( $rules );
		self::back( array(), true, __( 'وضعیت قانون تغییر کرد.', 'irnoti-service' ) );
	}

	public static function rule_delete(): void {
		self::guard( 'irnoti_rule' );
		$id = sanitize_text_field( $_GET['id'] ?? '' );
		IRNoti_SMS_Rules::save_all( array_filter( IRNoti_SMS_Rules::all(), static function ( $r ) use ( $id ) {
			return $r['id'] !== $id;
		} ) );
		self::back( array(), true, __( 'قانون حذف شد.', 'irnoti-service' ) );
	}

	/* ---------- patterns ---------- */

	public static function sub_patterns(): void {
		$list  = self::patterns();
		$badge = array( 'approved' => array( 'ok', __( 'تأیید شده', 'irnoti-service' ) ), 'pending' => array( 'warn', __( 'در انتظار بررسی', 'irnoti-service' ) ), 'rejected' => array( 'bad', __( 'رد شده', 'irnoti-service' ) ) );
		echo '<h2 style="margin-top:0">' . esc_html__( 'الگوهای شما', 'irnoti-service' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'نام', 'irnoti-service' ) . '</th><th>' . esc_html__( 'کد', 'irnoti-service' ) . '</th><th>' . esc_html__( 'متن', 'irnoti-service' ) . '</th><th>' . esc_html__( 'وضعیت', 'irnoti-service' ) . '</th></tr></thead><tbody>';
		foreach ( $list as $p ) {
			$b = $badge[ $p['status'] ?? '' ] ?? array( '', (string) ( $p['status'] ?? '' ) );
			printf( '<tr><td>%s</td><td dir="ltr"><code>%s</code></td><td>%s</td><td><span class="irn-badge %s">%s</span>%s</td></tr>', esc_html( $p['name'] ?? '' ), esc_html( $p['code'] ?? '' ), esc_html( $p['body'] ?? '' ), esc_attr( $b[0] ), esc_html( $b[1] ), ! empty( $p['rejectReason'] ) ? '<br><small>' . esc_html( $p['rejectReason'] ) . '</small>' : '' );
		}
		if ( ! $list ) {
			echo '<tr><td colspan="4" class="irn-empty">—</td></tr>';
		}
		echo '</tbody></table><h2>' . esc_html__( 'ساخت الگوی جدید', 'irnoti-service' ) . '</h2><p class="description">' . esc_html__( 'متغیرها را به شکل {name} بنویسید؛ مثلاً: «{name} عزیز، سفارش {order} ثبت شد». الگو پس از بررسی تأیید می‌شود و بعد در قوانین قابل استفاده است.', 'irnoti-service' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'irnoti_pattern' );
		echo '<input type="hidden" name="action" value="irnoti_pattern_create"><table class="form-table">';
		self::row( __( 'نام', 'irnoti-service' ), '<input class="regular-text" type="text" name="name" required maxlength="120">' );
		$ln = '<select name="line_id" required>';
		foreach ( self::lines() as $l ) {
			$ln .= sprintf( '<option value="%s">%s</option>', esc_attr( $l['id'] ), esc_html( ( $l['number'] ?? '' ) . ( ! empty( $l['senderName'] ) ? ' — ' . $l['senderName'] : '' ) ) );
		}
		self::row( __( 'خط ارسال', 'irnoti-service' ), $ln . '</select>' );
		self::row( __( 'کاربرد', 'irnoti-service' ), '<select name="kind"><option value="transactional">' . esc_html__( 'اطلاع‌رسانی (سفارش، وضعیت…)', 'irnoti-service' ) . '</option><option value="otp">' . esc_html__( 'کد یکبارمصرف (OTP)', 'irnoti-service' ) . '</option></select>' );
		self::row( __( 'متن الگو', 'irnoti-service' ), '<textarea class="large-text" rows="3" name="body" required minlength="3" maxlength="500"></textarea>' );
		echo '</table>';
		submit_button( __( 'ارسال برای تأیید', 'irnoti-service' ) );
		echo '</form>';
	}

	public static function pattern_create(): void {
		self::guard( 'irnoti_pattern' );
		$res = IRNoti_API::post( 'patterns', array(
			'lineId' => preg_replace( '/\D/', '', (string) ( $_POST['line_id'] ?? '' ) ),
			'name'   => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'body'   => sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ),
			'kind'   => 'otp' === ( $_POST['kind'] ?? '' ) ? 'otp' : 'transactional',
			'mode'   => 'internal',
		) );
		self::back( array( 'sub' => 'patterns' ), ! is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : __( 'الگو برای بررسی ارسال شد.', 'irnoti-service' ) );
	}

	/* ---------- settings / log ---------- */

	public static function sub_settings(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'irnoti_save' );
		echo '<input type="hidden" name="action" value="irnoti_save"><input type="hidden" name="tab" value="sms"><table class="form-table">';
		$cur = (string) IRNoti_Settings::get( 'sms_line_id' );
		$ln  = '<select name="sms_line_id"><option value="">' . esc_html__( 'خط پیش‌فرض حساب', 'irnoti-service' ) . '</option><option value="sandbox" ' . selected( 'sandbox', $cur, false ) . '>sandbox (' . esc_html__( 'تست', 'irnoti-service' ) . ')</option>';
		foreach ( self::lines() as $l ) {
			$ln .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $l['id'] ), selected( (string) $l['id'], $cur, false ), esc_html( $l['number'] ?? $l['id'] ) );
		}
		self::row( __( 'خط ارسال', 'irnoti-service' ), $ln . '</select>' );
		self::row( __( 'موبایل مدیر', 'irnoti-service' ), '<input class="regular-text" dir="ltr" type="text" name="sms_admin_to" value="' . esc_attr( (string) IRNoti_Settings::get( 'sms_admin_to' ) ) . '"><p class="description">' . esc_html__( 'گیرندهٔ قوانینی که «مدیر» را انتخاب کرده‌اند. چند شماره با ویرگول.', 'irnoti-service' ) . '</p>' );
		self::row( __( 'ورود با کد پیامکی', 'irnoti-service' ), '<label><input type="checkbox" name="otp_login" value="1" ' . checked( 1, (int) IRNoti_Settings::get( 'otp_login' ), false ) . '> ' . esc_html__( 'فعال (شورت‌کد [irnoti_login])', 'irnoti-service' ) . '</label><p class="description">' . esc_html__( 'موبایل هر کاربر در پروفایلش ثبت می‌شود. ورود مدیران پیش‌فرض بسته است.', 'irnoti-service' ) . '</p>' );
		self::row( __( 'الگوی OTP (اختیاری)', 'irnoti-service' ), '<input class="regular-text" dir="ltr" type="text" name="otp_pattern" value="' . esc_attr( (string) IRNoti_Settings::get( 'otp_pattern' ) ) . '"><p class="description">' . esc_html__( 'کد الگو؛ پارامتر code به آن داده می‌شود. خالی = پیامک متنی.', 'irnoti-service' ) . '</p>' );
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	public static function sub_log(): void {
		echo '<h2 style="margin-top:0">' . esc_html__( 'ارسال آزمایشی', 'irnoti-service' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'irnoti_sms_test' );
		echo '<input type="hidden" name="action" value="irnoti_sms_test"><input type="text" dir="ltr" name="to" placeholder="09…"> <input type="text" name="text" value="تست IRNoti" style="min-width:260px"> ';
		submit_button( __( 'ارسال', 'irnoti-service' ), 'secondary', 'submit', false );
		echo '</form><h2>' . esc_html__( 'ارسال‌های اخیر', 'irnoti-service' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( (array) get_option( IRNoti_SMS::LOG, array() ) as $row ) {
			printf( '<tr><td>%s</td><td dir="ltr">%s</td><td>%s</td><td>%s</td></tr>', esc_html( wp_date( 'Y/m/d H:i', $row['time'] ) ), esc_html( $row['to'] ), esc_html( $row['text'] ), $row['ok'] ? '<span class="irn-badge ok">' . esc_html__( 'ارسال شد', 'irnoti-service' ) . '</span>' : '<span class="irn-badge bad">' . esc_html( $row['error'] ?: __( 'ناموفق', 'irnoti-service' ) ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
	}

	/** Price/parts quote for the rendered preview text. */
	public static function ajax_quote(): void {
		check_ajax_referer( 'irnoti_sms_quote', 'nonce' );
		current_user_can( 'manage_options' ) || wp_send_json_error( null, 403 );
		$text = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
		$res  = IRNoti_SMS::quote( IRNoti_SMS::normalize( (string) IRNoti_Settings::get( 'sms_admin_to' ) ) ?: '09120000000', $text );
		is_wp_error( $res ) ? wp_send_json_error( array( 'message' => $res->get_error_message() ) ) : wp_send_json_success( $res );
	}
}
