<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Learn from conversations": once a day, for a time budget (default 60 minutes), IRNoti reads the widget assistant's
 * recent conversations and distils what the assistant should know (answers it lacked, recurring questions, corrections).
 * The plugin only schedules the run, sends the budget and mode, and keeps the report for the "what I learned" box.
 */
class IRNoti_Learn {
	const CRON   = 'irnoti_ai_learn';
	const REPORT = 'irnoti_learn_report';

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function enabled(): bool {
		return (bool) IRNoti_Settings::get( 'learn_enabled' ) && IRNoti_API::configured() && '' !== (string) IRNoti_Settings::get( 'widget_key' );
	}

	/** Next run time at the chosen hour (site time zone). */
	public static function next_run( int $hour ): int {
		$now = current_datetime();
		$t   = $now->setTime( max( 0, min( 23, $hour ) ), 0, 0 );
		if ( $t <= $now ) {
			$t = $t->modify( '+1 day' );
		}
		return $t->getTimestamp();
	}

	/** Makes the daily event match the settings (called on every load, and with $force after saving). */
	public static function schedule( bool $force = false ): void {
		$ts = wp_next_scheduled( self::CRON );
		if ( $ts && ( $force || ! self::enabled() ) ) {
			wp_clear_scheduled_hook( self::CRON );
			$ts = false;
		}
		if ( self::enabled() && ! $ts ) {
			wp_schedule_event( self::next_run( (int) IRNoti_Settings::get( 'learn_hour' ) ), 'daily', self::CRON );
		}
	}

	/** Learnings from a response: list of strings. */
	public static function normalize( $res ): array {
		$items = is_array( $res ) ? ( $res['learnings'] ?? $res['lessons'] ?? $res['items'] ?? $res['data'] ?? array() ) : array();
		$out   = array();
		foreach ( (array) $items as $i ) {
			$t = is_array( $i ) ? ( $i['text'] ?? $i['summary'] ?? $i['title'] ?? $i['content'] ?? '' ) : $i;
			$t = trim( wp_strip_all_tags( (string) $t ) );
			if ( '' !== $t ) {
				$out[] = mb_substr( $t, 0, 400 );
			}
		}
		return array_slice( $out, 0, 30 );
	}

	/** Asks IRNoti to run the learning session and stores the outcome. */
	public static function run(): array {
		$key = (string) IRNoti_Settings::get( 'widget_key' );
		$rep = array( 'time' => time(), 'ok' => false, 'items' => array(), 'processed' => 0, 'summary' => '', 'error' => '', 'mode' => (string) IRNoti_Settings::get( 'learn_mode' ) );
		if ( '' === $key || ! IRNoti_API::configured() ) {
			$rep['error'] = __( 'کلید API یا کد پروژهٔ ویجت تنظیم نشده است.', 'irnoti-service' );
		} else {
			$old                 = IRNoti_API::$timeout;
			IRNoti_API::$timeout = 120;
			$res                 = IRNoti_API::post( IRNoti_AI::endpoint( 'learn', array( 'agent' => $key ) ), array(
				'sinceHours'    => 24,
				'budgetMinutes' => max( 5, min( 180, (int) IRNoti_Settings::get( 'learn_minutes' ) ) ),
				'mode'          => 'auto' === IRNoti_Settings::get( 'learn_mode' ) ? 'auto' : 'suggest',
				'language'      => 'fa',
				'source'        => 'wordpress',
			) );
			IRNoti_API::$timeout = $old;
			if ( is_wp_error( $res ) ) {
				$rep['error'] = $res->get_error_message();
			} else {
				$rep['ok']        = true;
				$rep['items']     = self::normalize( $res );
				$rep['processed'] = (int) ( $res['processed'] ?? $res['conversations'] ?? 0 );
				$rep['summary']   = mb_substr( trim( wp_strip_all_tags( (string) ( $res['summary'] ?? '' ) ) ), 0, 600 );
			}
		}
		update_option( self::REPORT, $rep, false );
		return $rep;
	}

	public static function report(): array {
		$r = get_option( self::REPORT );
		return is_array( $r ) ? $r : array();
	}
}
