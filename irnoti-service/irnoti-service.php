<?php
/**
 * Plugin Name:       IRNoti Service (ایران ناتیفیکیشن)
 * Description:       پیامک، ربات (بله/تلگرام) با همگام‌سازی محصولات ووکامرس، ایمیل و VoIP/سافت‌فون IRNoti روی وردپرس.
 * Version:           1.1.3
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            IRNoti
 * License:           GPL-2.0-or-later
 * Text Domain:       irnoti-service
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'IRNOTI_VERSION', '1.1.3' );
define( 'IRNOTI_FILE', __FILE__ );
define( 'IRNOTI_DIR', plugin_dir_path( __FILE__ ) );
define( 'IRNOTI_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'settings', 'api-client', 'sms', 'sms-rules', 'email', 'bot-sync', 'bot-orders', 'voip', 'webhooks', 'otp-login', 'admin-sms', 'admin' ) as $irnoti_part ) {
	require_once IRNOTI_DIR . 'includes/class-irnoti-' . $irnoti_part . '.php';
}

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'irnoti-service', false, dirname( plugin_basename( IRNOTI_FILE ) ) . '/languages' );
	IRNoti_SMS::init();
	IRNoti_Email::init();
	IRNoti_Bot_Sync::init();
	IRNoti_VoIP::init();
	IRNoti_Webhooks::init();
	IRNoti_Bot_Orders::init();
	IRNoti_OTP_Login::init();
	if ( is_admin() ) {
		IRNoti_Admin_SMS::init();
		IRNoti_Admin::init();
	}
} );

register_deactivation_hook( __FILE__, static function () {
	wp_clear_scheduled_hook( 'irnoti_pull_orders' );
} );
