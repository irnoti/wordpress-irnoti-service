<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'irnoti_settings' );
delete_option( 'irnoti_sms_log' );
delete_option( 'irnoti_events' );
delete_option( 'irnoti_key_status' );
delete_option( 'irnoti_low_balance_sent' );
delete_transient( 'irnoti_account' );
wp_clear_scheduled_hook( 'irnoti_low_balance' );
delete_option( 'irnoti_learn_report' );
delete_transient( 'irnoti_ai_trends' );
wp_clear_scheduled_hook( 'irnoti_ai_learn' );
foreach ( array( '_irnoti_seo_score', '_irnoti_seo_desc', '_irnoti_seo_kw' ) as $irnoti_key ) {
	delete_post_meta_by_key( $irnoti_key );
}
