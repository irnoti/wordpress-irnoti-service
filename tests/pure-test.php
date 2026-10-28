<?php
// Dependency-free checks for the pure helpers. Run: php tests/pure-test.php
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../irnoti-service/includes/class-irnoti-sms.php';
require __DIR__ . '/../irnoti-service/includes/class-irnoti-webhooks.php';
require __DIR__ . '/../irnoti-service/includes/class-irnoti-sms-rules.php';
$fail = 0;
function check( $name, $cond ) { global $fail; echo ( $cond ? 'ok   ' : 'FAIL ' ) . $name . "\n"; $fail += $cond ? 0 : 1; }

check( 'normalize 98', IRNoti_SMS::normalize( '+98 912 345 6789' ) === '09123456789' );
check( 'normalize 0098', IRNoti_SMS::normalize( '00989123456789' ) === '09123456789' );
check( 'normalize persian digits', IRNoti_SMS::normalize( '۰۹۱۲۳۴۵۶۷۸۹' ) === '09123456789' );
check( 'normalize 9xxxxxxxxx', IRNoti_SMS::normalize( '9123456789' ) === '09123456789' );

$secret = 's3cret'; $body = '{"event":"message.sent"}'; $t = 1700000000;
$sig = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $body, $secret );
check( 'webhook valid', IRNoti_Webhooks::verify( $secret, $body, $sig, 300, $t + 10 ) );
check( 'webhook wrong secret', ! IRNoti_Webhooks::verify( 'x', $body, $sig, 300, $t + 10 ) );
check( 'webhook tampered body', ! IRNoti_Webhooks::verify( $secret, $body . ' ', $sig, 300, $t + 10 ) );
check( 'webhook stale', ! IRNoti_Webhooks::verify( $secret, $body, $sig, 300, $t + 301 ) );
check( 'webhook empty secret', ! IRNoti_Webhooks::verify( '', $body, $sig, 300, $t ) );
check( 'webhook garbage header', ! IRNoti_Webhooks::verify( $secret, $body, 'nope', 300, $t ) );
check( 'render vars', IRNoti_SMS_Rules::render( 'سلام {name}، سفارش {order_id} {missing}', array( 'name' => 'سارا', 'order_id' => '12' ) ) === 'سلام سارا، سفارش 12' );
check( 'segments empty', IRNoti_SMS_Rules::segments( '' ) === 0 );
check( 'segments persian 70', IRNoti_SMS_Rules::segments( str_repeat( 'س', 70 ) ) === 1 );
check( 'segments persian 71', IRNoti_SMS_Rules::segments( str_repeat( 'س', 71 ) ) === 2 );
check( 'segments ascii 160', IRNoti_SMS_Rules::segments( str_repeat( 'a', 160 ) ) === 1 );
check( 'segments ascii 161', IRNoti_SMS_Rules::segments( str_repeat( 'a', 161 ) ) === 2 );
exit( $fail ? 1 : 0 );
