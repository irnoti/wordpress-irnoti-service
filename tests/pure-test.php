<?php
// Dependency-free checks for the pure helpers. Run: php tests/pure-test.php
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../irnoti-service/includes/class-irnoti-sms.php';
require __DIR__ . '/../irnoti-service/includes/class-irnoti-webhooks.php';
require __DIR__ . '/../irnoti-service/includes/class-irnoti-sms-rules.php';
require __DIR__ . '/../irnoti-service/includes/class-irnoti-widget.php';
require __DIR__ . '/../irnoti-service/includes/class-irnoti-seo.php';
require __DIR__ . '/../irnoti-service/includes/class-irnoti-ai.php';
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
$w = IRNoti_Widget::parse_embed( '<script async src="https://cdn.irnoti.com/w.js" data-key="abc" onload="evil()" data-position="left"></script>' );
check( 'widget src', 'https://cdn.irnoti.com/w.js' === $w['src'] );
check( 'widget data attrs only', array( 'data-key' => 'abc', 'data-position' => 'left' ) === $w['attrs'] );
check( 'widget bare url', 'https://api.irnoti.com/a.js' === IRNoti_Widget::parse_embed( 'https://api.irnoti.com/a.js' )['src'] );
check( 'widget rejects http', '' === IRNoti_Widget::parse_embed( '<script src="http://x.io/a.js"></script>' )['src'] );
check( 'widget rejects js url', '' === IRNoti_Widget::parse_embed( '<script src="javascript:alert(1)"></script>' )['src'] );
check( 'widget empty', '' === IRNoti_Widget::parse_embed( '' )['src'] );
check( 'widget default src is https', 0 === strpos( IRNoti_Widget::DEFAULT_SRC, 'https://' ) );
check( 'widget rejects foreign host', '' === IRNoti_Widget::parse_embed( 'https://evil.example/a.js' )['src'] );
check( 'widget api base', 'https://api.irnoti.com/v1' === IRNoti_Widget::api_base( IRNoti_Widget::DEFAULT_SRC ) );

// SEO analyzer
$ids = static function ( $r, $state ) { return array_column( array_filter( $r['checks'], static function ( $c ) use ( $state ) { return $c['state'] === $state; } ), 'id' ); };
$empty = IRNoti_SEO::analyze( array() );
check( 'seo empty is low', 'low' === $empty['grade'] && $empty['score'] < 50 );
$body  = '<p>' . str_repeat( 'خرید کفش ورزشی مردانه با کیفیت و قیمت مناسب از فروشگاه ما. ', 3 ) . '</p><h2>راهنمای انتخاب کفش ورزشی</h2><p>' . str_repeat( 'کفش ورزشی باید سبک باشد و پا را خوب نگه دارد. ', 25 ) . '</p><img src="a.jpg" alt="کفش ورزشی"><a href="/shop">فروشگاه</a> <a href="https://example.org/x">منبع</a>';
$good  = IRNoti_SEO::analyze( array( 'title' => 'راهنمای خرید کفش ورزشی مردانه برای دویدن', 'content' => $body, 'desc' => 'در این راهنما کفش ورزشی مناسب را با نکات کلیدی انتخاب کنید و بهترین گزینه‌ها را با قیمت مناسب از فروشگاه ما بخرید.', 'kw' => 'کفش ورزشی', 'slug' => 'sport-shoes-guide', 'has_thumb' => true, 'host' => 'shop.test' ) );
check( 'seo title ok', in_array( 'title_len', $ids( $good, 'ok' ), true ) );
check( 'seo kw in title', in_array( 'kw_title', $ids( $good, 'ok' ), true ) );
check( 'seo heading + links', in_array( 'headings', $ids( $good, 'ok' ), true ) && in_array( 'links_internal', $ids( $good, 'ok' ), true ) && in_array( 'links_external', $ids( $good, 'ok' ), true ) );
check( 'seo alt present', in_array( 'img_alt', $ids( $good, 'ok' ), true ) );
check( 'seo slug lacks kw (latin slug, persian kw)', in_array( 'kw_slug', $ids( $good, 'warn' ), true ) );
check( 'seo good beats empty', $good['score'] > 70 && $good['score'] > $empty['score'] );
$noalt = IRNoti_SEO::analyze( array( 'content' => '<img src="a.jpg"><img src="b.jpg" alt="">' ) );
check( 'seo missing alt is bad', in_array( 'img_alt', $ids( $noalt, 'bad' ), true ) );
$arabic = IRNoti_SEO::analyze( array( 'title' => 'كتاب خوب', 'kw' => 'کتاب', 'content' => '<p>x</p>' ) );
check( 'seo arabic/persian letters match', in_array( 'kw_title', $ids( $arabic, 'ok' ), true ) );
check( 'seo no kw skips kw checks', ! in_array( 'kw_title', array_column( $empty['checks'], 'id' ), true ) && in_array( 'kw_missing', array_column( $empty['checks'], 'id' ), true ) );
check( 'seo product needs fewer words', in_array( 'words', $ids( IRNoti_SEO::analyze( array( 'type' => 'product', 'content' => '<p>' . str_repeat( 'کفش ', 130 ) . '</p>' ) ), 'ok' ), true ) );
check( 'seo text strips script and shortcode', 'سلام' === IRNoti_SEO::text( '<script>x()</script><p>سلام</p>[gallery ids="1"]' ) );

// AI helpers
check( 'ai extract text', 'hi' === IRNoti_AI::extract_text( array( 'text' => 'hi' ) ) );
check( 'ai extract openai-like', 'yo' === IRNoti_AI::extract_text( array( 'choices' => array( array( 'message' => array( 'content' => 'yo' ) ) ) ) ) );
check( 'ai extract nested data', 'n' === IRNoti_AI::extract_text( array( 'data' => array( 'output' => 'n' ) ) ) );
check( 'ai extract empty', '' === IRNoti_AI::extract_text( array( 'x' => 1 ) ) );
check( 'ai image url', array( 'url' => 'https://a/b.png' ) === IRNoti_AI::extract_image( array( 'data' => array( array( 'url' => 'https://a/b.png' ) ) ) ) );
check( 'ai image b64 strips prefix', array( 'b64' => 'AAA' ) === IRNoti_AI::extract_image( array( 'image' => 'data:image/png;base64,AAA' ) ) );
check( 'ai split lines', array( 'a', 'b' ) === IRNoti_AI::split_lines( "1. a\n- b\n\n۲) b" ) );
check( 'ai split title', array( 'سلام دنیا', '<p>x</p>' ) === IRNoti_AI::split_title_body( "عنوان: «سلام دنیا»\n<p>x</p>" ) );
$t = IRNoti_AI::normalize_trends( array( 'items' => array( array( 'title' => 'الف', 'keywords' => 'a, b', 'score' => '3' ), 'ب', array( 'x' => 1 ) ) ) );
check( 'ai trends normalize', 2 === count( $t ) && array( 'a', 'b' ) === $t[0]['keywords'] && 'ب' === $t[1]['title'] );
$p = IRNoti_AI::build_prompt( 'title', array( 'title' => 'T', 'kw' => 'K' ) );
check( 'ai prompt has keyword and type', false !== strpos( $p[1], 'K' ) && 'lines' === $p[2] );
check( 'ai prompt truncates text', mb_strlen( IRNoti_AI::build_prompt( 'optimize', array( 'text' => str_repeat( 'x', 20000 ) ) )[1] ) < 6600 );
exit( $fail ? 1 : 0 );
