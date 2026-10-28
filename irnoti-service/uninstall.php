<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'irnoti_settings' );
delete_option( 'irnoti_sms_log' );
