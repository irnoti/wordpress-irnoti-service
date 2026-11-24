<?php
/**
 * Plugin Name:       IRNoti Service
 * Description:       پیامک، ربات (بله/تلگرام) با همگام‌سازی محصولات ووکامرس، ایمیل، ویجت سایت، سئو و محتوای هوش مصنوعی و VoIP/سافت‌فون IRNoti روی وردپرس.
 * Version:           1.1.8
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            IRNoti
 * License:           GPL-2.0-or-later
 * Text Domain:       irnoti-service
 */

defined( 'ABSPATH' ) || exit;

define( 'IRNOTI_VERSION', '1.1.8' );
define( 'IRNOTI_FILE', __FILE__ );
define( 'IRNOTI_DIR', plugin_dir_path( __FILE__ ) );
define( 'IRNOTI_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'settings', 'api-client', 'sms', 'sms-rules', 'email', 'bot-sync', 'bot-orders', 'voip', 'webhooks', 'otp-login', 'widget', 'account', 'health', 'seo', 'ai', 'admin-sms', 'admin-seo', 'admin' ) as $irnoti_part ) {
	require_once IRNOTI_DIR . 'includes/class-irnoti-' . $irnoti_part . '.php';
}

add_action( 'plugins_loaded', static function () {
	IRNoti_SMS::init();
	IRNoti_Email::init();
	IRNoti_Bot_Sync::init();
	IRNoti_VoIP::init();
	IRNoti_Webhooks::init();
	IRNoti_Bot_Orders::init();
	IRNoti_OTP_Login::init();
	IRNoti_Widget::init();
	IRNoti_Account::init();
	IRNoti_SEO::init();
	if ( is_admin() ) {
		IRNoti_Admin_SMS::init();
		IRNoti_Admin_SEO::init();
		IRNoti_Admin::init();
	}
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), static function ( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=irnoti' ) ) . '">' . esc_html__( 'تنظیمات', 'irnoti-service' ) . '</a>' );
	$links[] = '<a href="' . esc_url( 'https://irnoti.com/?utm_source=wp-plugin&utm_medium=plugins-list' ) . '" target="_blank" rel="noopener">' . esc_html__( 'سایت IRNoti', 'irnoti-service' ) . '</a>';
	return $links;
} );

register_deactivation_hook( __FILE__, static function () {
	wp_clear_scheduled_hook( 'irnoti_pull_orders' );
	wp_clear_scheduled_hook( 'irnoti_low_balance' );
} );
