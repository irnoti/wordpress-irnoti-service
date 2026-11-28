<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'irnoti_settings' );
delete_option( 'irnoti_sms_log' );
delete_option( 'irnoti_events' );
delete_option( 'irnoti_key_status' );
delete_option( 'irnoti_low_balance_sent' );
delete_transient( 'irnoti_account' );
wp_clear_scheduled_hook( 'irnoti_low_balance' );
delete_transient( 'irnoti_ai_ideas' );
delete_transient( 'irnoti_ai_models' );
foreach ( array( '_irnoti_seo_score', '_irnoti_seo_desc', '_irnoti_seo_kw' ) as $irnoti_key ) {
	delete_post_meta_by_key( $irnoti_key );
}
delete_option( 'irnoti_rank_state' );
delete_option( 'irnoti_rank_log' );
delete_transient( 'irnoti_rank_lock' );
wp_clear_scheduled_hook( 'irnoti_rank_sync' );
foreach ( array( '_irnoti_seo_title', '_irnoti_jsonld' ) as $irnoti_key ) {
	delete_post_meta_by_key( $irnoti_key );
}
