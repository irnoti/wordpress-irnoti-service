<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'irnoti_settings' );
delete_option( 'irnoti_sms_log' );
delete_option( 'irnoti_events' );
delete_option( 'irnoti_key_status' );
delete_option( 'irnoti_low_balance_sent' );
delete_transient( 'irnoti_account' );
wp_clear_scheduled_hook( 'irnoti_low_balance' );
