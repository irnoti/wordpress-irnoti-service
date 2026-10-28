<?php
/**
 * Plugin Name:       IRNoti Service (ایران ناتیفیکیشن)
 * Description:       پیامک، ربات (بله/تلگرام) با همگام‌سازی محصولات ووکامرس، ایمیل و VoIP/سافت‌فون IRNoti روی وردپرس.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            IRNoti
 * License:           GPL-2.0-or-later
 * Text Domain:       irnoti-service
 */

defined( 'ABSPATH' ) || exit;

define( 'IRNOTI_VERSION', '0.1.0' );
define( 'IRNOTI_FILE', __FILE__ );
define( 'IRNOTI_DIR', plugin_dir_path( __FILE__ ) );
define( 'IRNOTI_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'settings', 'api-client', 'sms', 'email', 'bot-sync', 'voip', 'admin' ) as $irnoti_part ) {
	require_once IRNOTI_DIR . 'includes/class-irnoti-' . $irnoti_part . '.php';
}

add_action( 'plugins_loaded', static function () {
	IRNoti_SMS::init();
	IRNoti_Email::init();
	IRNoti_Bot_Sync::init();
	IRNoti_VoIP::init();
	if ( is_admin() ) {
		IRNoti_Admin::init();
	}
} );
