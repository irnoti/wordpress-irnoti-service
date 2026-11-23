<?php
defined( 'ABSPATH' ) || exit;

/**
 * Account balances from IRNoti: wallet (Rial, with gift part), SMS credit, AI tokens, email quota and recent transactions.
 * Cached for a few minutes so admin pages stay fast; a low-balance check e-mails the site admin at most once a day.
 */
class IRNoti_Account {
	const CACHE   = 'irnoti_account';
	const TTL     = 5 * MINUTE_IN_SECONDS;
	const CRON    = 'irnoti_low_balance';
	const NOTIFIED = 'irnoti_low_balance_sent';

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'check_low_balance' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		if ( is_admin() ) {
			add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
			add_action( 'admin_post_irnoti_account_refresh', array( __CLASS__, 'refresh' ) );
		}
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
	}

	public static function schedule(): void {
		$on = (int) IRNoti_Settings::get( 'low_balance' ) > 0 && IRNoti_API::configured();
		$ts = wp_next_scheduled( self::CRON );
		if ( $on && ! $ts ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		} elseif ( ! $on && $ts ) {
			wp_unschedule_event( $ts, self::CRON );
		}
	}

	/** One part of the snapshot: the decoded data, or why it is missing ("scope" = the key lacks that permission). */
	private static function part( string $path, array $query = array() ): array {
		$res = IRNoti_API::get( $path, $query );
		if ( ! is_wp_error( $res ) ) {
			return array( 'ok' => true, 'data' => (array) $res );
		}
		$code = (int) ( $res->get_error_data()['status'] ?? 0 );
		return array( 'ok' => false, 'scope' => 403 === $code, 'error' => $res->get_error_message() );
	}

	/** Cached balances; null when no key is set. */
	public static function snapshot( bool $fresh = false ): ?array {
		if ( ! IRNoti_API::configured() ) {
			return null;
		}
		$snap = $fresh ? false : get_transient( self::CACHE );
		if ( is_array( $snap ) ) {
			return $snap;
		}
		$snap = array(
			'wallet' => self::part( 'wallet' ),
			'tx'     => self::part( 'wallet/transactions', array( 'page' => 1, 'pageSize' => 8 ) ),
			'ai'     => self::part( 'ai/balance' ),
			'email'  => self::part( 'email/me' ),
			'time'   => time(),
		);
		set_transient( self::CACHE, $snap, self::TTL );
		return $snap;
	}

	/** Cached snapshot only (never calls the API): for places that render on every admin page. */
	public static function cached(): ?array {
		$snap = get_transient( self::CACHE );
		return is_array( $snap ) ? $snap : null;
	}

	/** Integer amount from the API (BigInt arrives as a string). */
	public static function int( $v ): int {
		return (int) preg_replace( '/[^\d-]/', '', (string) $v );
	}

	public static function rial( $v ): string {
		/* translators: %s: amount in Rial, digit-grouped */
		return sprintf( __( '%s ریال', 'irnoti-service' ), number_format_i18n( self::int( $v ) ) );
	}

	public static function toman( $v ): string {
		/* translators: %s: amount in Toman, digit-grouped */
		return sprintf( __( '≈ %s تومان', 'irnoti-service' ), number_format_i18n( intdiv( self::int( $v ), 10 ) ) );
	}

	/** Where the customer tops up: the IRNoti panel wallet page. */
	public static function topup_url(): string {
		return (string) apply_filters( 'irnoti_topup_url', 'https://irnoti.com/panel/wallet' );
	}

	/** Wallet + SMS credit in Rial (what pays for SMS), or null when unknown. */
	public static function spendable( ?array $snap ): ?int {
		if ( ! $snap || empty( $snap['wallet']['ok'] ) ) {
			return null;
		}
		$w = $snap['wallet']['data'];
		return self::int( $w['balance'] ?? 0 ) + self::int( $w['smsCredit']['balance'] ?? 0 );
	}

	/** Earliest expiry among lots, as a timestamp. */
	private static function soonest( array $lots ): int {
		$min = 0;
		foreach ( $lots as $l ) {
			$t = isset( $l['expiresAt'] ) ? strtotime( (string) $l['expiresAt'] ) : 0;
			if ( $t && ( ! $min || $t < $min ) ) {
				$min = $t;
			}
		}
		return $min;
	}

	/** Balance cards (overview tab). */
	public static function render_cards(): void {
		$snap = self::snapshot();
		if ( null === $snap ) {
			return;
		}
		echo '<div class="irn-acc-head"><h2>' . esc_html__( 'حساب و اعتبار', 'irnoti-service' ) . '</h2><span class="irn-acc-meta">';
		/* translators: %s: time the balances were fetched */
		echo esc_html( sprintf( __( 'به‌روزرسانی: %s', 'irnoti-service' ), wp_date( 'H:i', (int) $snap['time'] ) ) );
		echo ' <a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=irnoti_account_refresh' ), 'irnoti_account' ) ) . '">' . esc_html__( 'بروزرسانی', 'irnoti-service' ) . '</a>';
		echo ' <a class="button button-small button-primary" href="' . esc_url( self::topup_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'افزایش اعتبار', 'irnoti-service' ) . '</a></span></div>';

		$low = (int) IRNoti_Settings::get( 'low_balance' );
		$sp  = self::spendable( $snap );
		if ( $low > 0 && null !== $sp && $sp < $low ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'موجودی برای ارسال پیامک از حد هشدار کمتر است؛ برای جلوگیری از توقف پیامک‌ها حساب را شارژ کنید.', 'irnoti-service' ) . '</p></div>';
		}

		echo '<div class="irn-acc">';
		// Wallet
		self::card_open( 'money-alt', __( 'کیف پول', 'irnoti-service' ), $snap['wallet'], 'wallet.read' );
		if ( $snap['wallet']['ok'] ) {
			$w = $snap['wallet']['data'];
			echo '<b class="irn-acc-num">' . esc_html( self::rial( $w['balance'] ?? 0 ) ) . '</b><small>' . esc_html( self::toman( $w['balance'] ?? 0 ) ) . '</small>';
			if ( self::int( $w['gift'] ?? 0 ) > 0 ) {
				$exp = self::soonest( (array) ( $w['giftExpiring'] ?? array() ) );
				echo '<span class="irn-acc-sub">' . esc_html(
					/* translators: 1: gift amount, 2: expiry date */
					sprintf( __( 'هدیه: %1$s · انقضا %2$s', 'irnoti-service' ), self::rial( $w['gift'] ), $exp ? wp_date( 'Y/m/d', $exp ) : '—' )
				) . '</span>';
			}
		}
		echo '</div>';
		// SMS credit
		self::card_open( 'smartphone', __( 'اعتبار پیامکی', 'irnoti-service' ), $snap['wallet'], 'wallet.read' );
		if ( $snap['wallet']['ok'] ) {
			$s = (array) ( $snap['wallet']['data']['smsCredit'] ?? array() );
			echo '<b class="irn-acc-num">' . esc_html( self::rial( $s['balance'] ?? 0 ) ) . '</b><small>' . esc_html__( 'فقط برای ارسال پیامک؛ اول از این کسر می‌شود', 'irnoti-service' ) . '</small>';
			$exp = self::soonest( (array) ( $s['lots'] ?? array() ) );
			if ( $exp ) {
				/* translators: %s: expiry date */
				echo '<span class="irn-acc-sub">' . esc_html( sprintf( __( 'نزدیک‌ترین انقضا: %s', 'irnoti-service' ), wp_date( 'Y/m/d', $exp ) ) ) . '</span>';
			}
		}
		echo '</div>';
		// AI tokens
		self::card_open( 'lightbulb', __( 'توکن هوش مصنوعی', 'irnoti-service' ), $snap['ai'], 'ai.read' );
		if ( $snap['ai']['ok'] ) {
			$a = $snap['ai']['data'];
			echo '<b class="irn-acc-num">' . esc_html( number_format_i18n( self::int( $a['balance'] ?? 0 ) ) ) . '</b><small>' . esc_html__( 'توکن (ویجت، اپراتور هوشمند)', 'irnoti-service' ) . '</small>';
			if ( self::int( $a['held'] ?? 0 ) > 0 ) {
				/* translators: %s: reserved tokens */
				echo '<span class="irn-acc-sub">' . esc_html( sprintf( __( 'در حال رزرو: %s', 'irnoti-service' ), number_format_i18n( self::int( $a['held'] ) ) ) ) . '</span>';
			}
		}
		echo '</div>';
		// Email
		self::card_open( 'email-alt', __( 'سهمیهٔ ایمیل', 'irnoti-service' ), $snap['email'], 'email.read' );
		if ( $snap['email']['ok'] ) {
			$e    = $snap['email']['data'];
			$s    = (array) ( $e['sends'] ?? array() );
			$left = (int) ( $s['planLeft'] ?? 0 ) + (int) ( $s['packLeft'] ?? 0 );
			echo '<b class="irn-acc-num">' . esc_html( number_format_i18n( $left ) ) . '</b><small>' . esc_html__( 'ایمیل باقی‌مانده (اشتراک + بسته)', 'irnoti-service' ) . '</small>';
			$plan = $e['subscription']['planName'] ?? '';
			if ( $plan ) {
				$exp = strtotime( (string) ( $e['subscription']['expiresAt'] ?? '' ) );
				/* translators: 1: plan name, 2: expiry date */
				echo '<span class="irn-acc-sub">' . esc_html( sprintf( __( '%1$s · تا %2$s', 'irnoti-service' ), $plan, $exp ? wp_date( 'Y/m/d', $exp ) : '—' ) ) . '</span>';
			} else {
				echo '<span class="irn-acc-sub">' . esc_html__( 'بدون اشتراک؛ پرداخت به‌ازای ارسال از کیف پول', 'irnoti-service' ) . '</span>';
			}
		}
		echo '</div></div>';
		self::render_transactions( $snap );
	}

	private static function card_open( string $icon, string $title, array $part, string $scope ): void {
		echo '<div class="irn-acc-card ' . ( $part['ok'] ? '' : 'is-missing' ) . '"><span class="irn-acc-ico dashicons dashicons-' . esc_attr( $icon ) . '"></span><span class="irn-acc-title">' . esc_html( $title ) . '</span>';
		if ( ! $part['ok'] ) {
			/* translators: %s: API key scope name */
			$msg = ! empty( $part['scope'] ) ? sprintf( __( 'کلید API دسترسی %s ندارد.', 'irnoti-service' ), $scope ) : (string) $part['error'];
			echo '<span class="irn-acc-err">' . esc_html( $msg ) . '</span>';
		}
	}

	private static function render_transactions( array $snap ): void {
		if ( empty( $snap['tx']['ok'] ) ) {
			return;
		}
		$d    = $snap['tx']['data'];
		$rows = isset( $d['items'] ) ? (array) $d['items'] : $d;
		if ( ! $rows ) {
			return;
		}
		echo '<h2>' . esc_html__( 'آخرین تراکنش‌های کیف پول', 'irnoti-service' ) . '</h2><table class="widefat striped irn-tx"><thead><tr><th>' . esc_html__( 'تاریخ', 'irnoti-service' ) . '</th><th>' . esc_html__( 'شرح', 'irnoti-service' ) . '</th><th>' . esc_html__( 'مبلغ', 'irnoti-service' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 8 ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$amt  = self::int( $r['amount'] ?? 0 );
			$t    = strtotime( (string) ( $r['createdAt'] ?? '' ) );
			$desc = (string) ( $r['transaction']['description'] ?? '' );
			if ( '' === $desc ) {
				$type = (string) ( $r['transaction']['type'] ?? '' );
				$desc = array(
					'topup'  => __( 'شارژ کیف پول', 'irnoti-service' ),
					'gift'   => __( 'هدیه', 'irnoti-service' ),
					'sms'    => __( 'ارسال پیامک', 'irnoti-service' ),
					'refund' => __( 'بازگشت وجه', 'irnoti-service' ),
					'email'  => __( 'ارسال ایمیل', 'irnoti-service' ),
				)[ $type ] ?? $type;
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td class="%s" dir="ltr">%s</td></tr>',
				esc_html( $t ? wp_date( 'Y/m/d H:i', $t ) : '' ),
				esc_html( $desc ),
				$amt < 0 ? 'irn-neg' : 'irn-pos',
				esc_html( ( $amt > 0 ? '+' : '' ) . number_format_i18n( $amt ) )
			);
		}
		echo '</tbody></table>';
	}

	public static function refresh(): void {
		check_admin_referer( 'irnoti_account' );
		current_user_can( 'manage_options' ) || wp_die( 'forbidden', 403 );
		self::snapshot( true );
		wp_safe_redirect( add_query_arg( array( 'page' => 'irnoti', 'tab' => 'overview' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Compact balances on the WordPress dashboard. */
	public static function dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) || ! IRNoti_API::configured() ) {
			return;
		}
		wp_add_dashboard_widget(
			'irnoti_account',
			__( 'IRNoti · اعتبار حساب', 'irnoti-service' ),
			static function () {
				$snap = self::snapshot();
				echo '<div dir="rtl" class="irn-dash">';
				if ( $snap && $snap['wallet']['ok'] ) {
					$w = $snap['wallet']['data'];
					echo '<p>' . esc_html__( 'کیف پول:', 'irnoti-service' ) . ' <strong>' . esc_html( self::rial( $w['balance'] ?? 0 ) ) . '</strong></p>';
					echo '<p>' . esc_html__( 'اعتبار پیامکی:', 'irnoti-service' ) . ' <strong>' . esc_html( self::rial( $w['smsCredit']['balance'] ?? 0 ) ) . '</strong></p>';
				} elseif ( $snap ) {
					echo '<p>' . esc_html( ! empty( $snap['wallet']['scope'] ) ? __( 'کلید API دسترسی wallet.read ندارد.', 'irnoti-service' ) : (string) $snap['wallet']['error'] ) . '</p>';
				}
				if ( $snap && $snap['ai']['ok'] ) {
					echo '<p>' . esc_html__( 'توکن هوش مصنوعی:', 'irnoti-service' ) . ' <strong>' . esc_html( number_format_i18n( self::int( $snap['ai']['data']['balance'] ?? 0 ) ) ) . '</strong></p>';
				}
				echo '<p><a class="button button-primary" href="' . esc_url( self::topup_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'افزایش اعتبار', 'irnoti-service' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=irnoti' ) ) . '">' . esc_html__( 'مدیریت IRNoti', 'irnoti-service' ) . '</a></p></div>';
			}
		);
	}

	/** Wallet balance in the admin bar, from the cache only (no API call on every page). */
	public static function admin_bar( $bar ): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$snap = self::cached();
		if ( ! $snap || empty( $snap['wallet']['ok'] ) ) {
			return;
		}
		$sp  = self::spendable( $snap );
		$low = (int) IRNoti_Settings::get( 'low_balance' );
		$bar->add_node(
			array(
				'id'    => 'irnoti-balance',
				/* translators: %s: wallet + SMS credit, digit-grouped Rial */
				'title' => esc_html( sprintf( __( 'IRNoti: %s', 'irnoti-service' ), self::rial( (int) $sp ) ) ) . ( $low > 0 && $sp < $low ? ' ⚠' : '' ),
				'href'  => admin_url( 'admin.php?page=irnoti' ),
			)
		);
	}

	/** Hourly: e-mail the site admin once a day while wallet + SMS credit is under the threshold. */
	public static function check_low_balance(): void {
		$low = (int) IRNoti_Settings::get( 'low_balance' );
		if ( $low <= 0 ) {
			return;
		}
		$sp = self::spendable( self::snapshot( true ) );
		if ( null === $sp ) {
			return;
		}
		if ( $sp >= $low ) {
			delete_option( self::NOTIFIED );
			return;
		}
		if ( time() - (int) get_option( self::NOTIFIED, 0 ) < DAY_IN_SECONDS ) {
			return;
		}
		update_option( self::NOTIFIED, time(), false );
		$to = (string) ( IRNoti_Settings::get( 'low_balance_email' ) ?: get_option( 'admin_email' ) );
		wp_mail(
			$to,
			/* translators: %s: site name */
			sprintf( __( '[%s] موجودی IRNoti رو به اتمام است', 'irnoti-service' ), get_bloginfo( 'name' ) ),
			/* translators: 1: current balance, 2: threshold, 3: top-up link */
			sprintf( __( "موجودی قابل خرج برای پیامک (کیف پول + اعتبار پیامکی): %1\$s\nحد هشدار: %2\$s\n\nبرای جلوگیری از توقف پیامک‌های سفارش و ورود، حساب را شارژ کنید:\n%3\$s", 'irnoti-service' ), self::rial( $sp ), self::rial( $low ), self::topup_url() )
		);
	}
}
