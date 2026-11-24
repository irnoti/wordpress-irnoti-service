<?php
defined( 'ABSPATH' ) || exit;

/**
 * On-page SEO: a local analyzer (score 0-100 + a list of problems, no API call needed), the focus keyword and
 * meta description fields (stored where Yoast / Rank Math keep them when one is active), and the front-end meta tag.
 * analyze() is pure (no WordPress calls) so it is unit-tested.
 */
class IRNoti_SEO {
	const SCORE = '_irnoti_seo_score';
	const DESC  = '_irnoti_seo_desc';
	const KW    = '_irnoti_seo_kw';

	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'print_meta' ), 1 );
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
	}

	/** Post types the SEO tools work on. */
	public static function post_types(): array {
		$types = (array) IRNoti_Settings::get( 'seo_post_types' );
		$types = array_values( array_filter( $types, 'post_type_exists' ) );
		return (array) apply_filters( 'irnoti_seo_post_types', $types );
	}

	/** Which SEO plugin owns the meta fields: yoast | rankmath | own. */
	public static function store(): string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		return 'own';
	}

	public static function meta_keys(): array {
		switch ( self::store() ) {
			case 'yoast':
				return array( 'desc' => '_yoast_wpseo_metadesc', 'kw' => '_yoast_wpseo_focuskw' );
			case 'rankmath':
				return array( 'desc' => 'rank_math_description', 'kw' => 'rank_math_focus_keyword' );
		}
		return array( 'desc' => self::DESC, 'kw' => self::KW );
	}

	public static function get_desc( int $post_id ): string {
		return trim( (string) get_post_meta( $post_id, self::meta_keys()['desc'], true ) );
	}

	public static function get_kw( int $post_id ): string {
		$kw = trim( (string) get_post_meta( $post_id, self::meta_keys()['kw'], true ) );
		return trim( explode( ',', $kw )[0] ); // Rank Math keeps a comma list; the first one is the primary keyword.
	}

	public static function set_meta( int $post_id, string $which, string $value ): void {
		$key = self::meta_keys()[ 'kw' === $which ? 'kw' : 'desc' ];
		update_post_meta( $post_id, $key, sanitize_text_field( $value ) );
	}

	/** Prints the description tag ourselves only when no SEO plugin does and the admin left it on. */
	public static function print_meta(): void {
		if ( 'own' !== self::store() || ! IRNoti_Settings::get( 'seo_meta_output' ) || ! is_singular() ) {
			return;
		}
		$desc = self::get_desc( (int) get_queried_object_id() );
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
	}

	public static function on_save( int $post_id, $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! $post instanceof WP_Post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}
		self::analyze_post( $post );
	}

	/** Analyzes a stored post and caches its score (used for sorting in the list). */
	public static function analyze_post( WP_Post $post ): array {
		$res = self::analyze( self::data_for( $post ) );
		update_post_meta( $post->ID, self::SCORE, $res['score'] );
		return $res;
	}

	public static function data_for( WP_Post $post, array $override = array() ): array {
		$data = array(
			'title'     => (string) $post->post_title,
			'content'   => (string) apply_filters( 'irnoti_seo_content', $post->post_content, $post ),
			'desc'      => self::get_desc( $post->ID ),
			'kw'        => self::get_kw( $post->ID ),
			'slug'      => (string) $post->post_name,
			'has_thumb' => has_post_thumbnail( $post->ID ),
			'type'      => $post->post_type,
			'host'      => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'excerpt'   => (string) $post->post_excerpt,
		);
		if ( 'product' === $post->post_type && '' === trim( wp_strip_all_tags( $data['content'] ) ) ) {
			$data['content'] = (string) $post->post_excerpt;
		}
		return array_merge( $data, $override );
	}

	/* ------------------------------------------------------------------ pure analyzer */

	/** Visible text of an HTML fragment (scripts, styles and shortcodes dropped). */
	public static function text( string $html ): string {
		$html = preg_replace( '#<(script|style)\b.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#</(p|div|li|h[1-6]|br|tr)>|<br\s*/?>#i', "\n", (string) $html );
		$html = preg_replace( '/\[\/?[a-z_][^\]]*\]/i', ' ', strip_tags( (string) $html ) );
		return trim( html_entity_decode( (string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	public static function words( string $text ): array {
		$parts = preg_split( '/[\s\x{200c}\x{200f}\x{200e}]+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_filter( (array) $parts, static function ( $w ) {
			return (bool) preg_match( '/[\p{L}\p{N}]/u', $w );
		} ) );
	}

	/** Normalizes Arabic/Persian letter variants and digits so "كتاب" and "کتاب" match. */
	public static function norm( string $s ): string {
		$s = strtr( $s, array( 'ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'ؤ' => 'و', "\xE2\x80\x8C" => ' ', '‌' => ' ' ) );
		$s = strtr( $s, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
		return mb_strtolower( preg_replace( '/[\x{064B}-\x{065F}]/u', '', $s ), 'UTF-8' );
	}

	private static function has( string $haystack, string $needle ): bool {
		return '' !== $needle && false !== mb_strpos( self::norm( $haystack ), self::norm( $needle ), 0, 'UTF-8' );
	}

	/** Minimum word counts (ok, warn) per content type. */
	private static function min_words( string $type ): array {
		return array( 'product' => array( 120, 50 ), 'page' => array( 200, 100 ) )[ $type ] ?? array( 300, 150 );
	}

	/**
	 * @param array{title?:string,content?:string,desc?:string,kw?:string,slug?:string,has_thumb?:bool,type?:string,host?:string} $d
	 * @return array{score:int,grade:string,checks:array<int,array{id:string,state:string,w:int,args:array}>,counts:array,words:int}
	 */
	public static function analyze( array $d ): array {
		$title   = trim( (string) ( $d['title'] ?? '' ) );
		$html    = (string) ( $d['content'] ?? '' );
		$desc    = trim( (string) ( $d['desc'] ?? '' ) );
		$kw      = trim( (string) ( $d['kw'] ?? '' ) );
		$slug    = rawurldecode( (string) ( $d['slug'] ?? '' ) );
		$type    = (string) ( $d['type'] ?? 'post' );
		$host    = strtolower( (string) ( $d['host'] ?? '' ) );
		$text    = self::text( $html );
		$words   = self::words( $text );
		$wc      = count( $words );
		$checks  = array();
		$add     = static function ( string $id, string $state, int $w, array $args = array() ) use ( &$checks ) {
			$checks[] = array( 'id' => $id, 'state' => $state, 'w' => $w, 'args' => $args );
		};

		// Title.
		$tl = mb_strlen( $title, 'UTF-8' );
		$add( 'title_len', ( $tl >= 30 && $tl <= 60 ) ? 'ok' : ( ( $tl >= 20 && $tl <= 70 ) ? 'warn' : 'bad' ), 10, array( 'len' => $tl ) );

		// Meta description.
		$dl = mb_strlen( $desc, 'UTF-8' );
		$add( 'desc_len', 0 === $dl ? 'bad' : ( ( $dl >= 70 && $dl <= 160 ) ? 'ok' : 'warn' ), 10, array( 'len' => $dl ) );

		// Length.
		list( $okw, $warnw ) = self::min_words( $type );
		$add( 'words', $wc >= $okw ? 'ok' : ( $wc >= $warnw ? 'warn' : 'bad' ), 10, array( 'count' => $wc, 'need' => $okw ) );

		// Focus keyword.
		if ( '' === $kw ) {
			$add( 'kw_missing', 'warn', 6 );
		} else {
			$add( 'kw_title', self::has( $title, $kw ) ? 'ok' : 'bad', 8 );
			$add( 'kw_desc', '' === $desc ? 'bad' : ( self::has( $desc, $kw ) ? 'ok' : 'warn' ), 6 );
			$intro = implode( ' ', array_slice( $words, 0, 100 ) );
			$add( 'kw_intro', self::has( $intro, $kw ) ? 'ok' : 'warn', 6 );
			preg_match_all( '#<h[2-6]\b[^>]*>(.*?)</h[2-6]>#is', $html, $hm );
			$add( 'kw_heading', self::has( implode( ' ', $hm[1] ), $kw ) ? 'ok' : 'warn', 5 );
			$slug_norm = str_replace( array( '-', '_' ), ' ', $slug );
			$add( 'kw_slug', self::has( $slug_norm, $kw ) ? 'ok' : 'warn', 4 );
			$kwn   = self::norm( $kw );
			$kwlen = max( 1, count( self::words( $kw ) ) );
			$hits  = '' === $kwn ? 0 : substr_count( self::norm( $text ), $kwn );
			$dens  = $wc > 0 ? round( $hits * $kwlen / $wc * 100, 1 ) : 0;
			$add( 'kw_density', ( $dens >= 0.5 && $dens <= 2.5 ) ? 'ok' : ( ( $dens > 0 && $dens < 4 ) ? 'warn' : 'bad' ), 5, array( 'pct' => $dens, 'hits' => $hits ) );
		}

		// Structure.
		preg_match_all( '#<h1\b#i', $html, $h1 );
		preg_match_all( '#<h[2-3]\b#i', $html, $h23 );
		if ( count( $h1[0] ) > 0 ) {
			$add( 'h1_in_content', 'warn', 3 ); // The theme already prints the title as H1.
		}
		$add( 'headings', ( $wc < 250 || count( $h23[0] ) > 0 ) ? 'ok' : 'warn', 6, array( 'count' => count( $h23[0] ) ) );

		// Images.
		preg_match_all( '#<img\b[^>]*>#i', $html, $im );
		$img_total = count( $im[0] );
		$no_alt    = 0;
		foreach ( $im[0] as $tag ) {
			if ( ! preg_match( '#\balt\s*=\s*(["\'])\s*\S.*?\1#is', $tag ) ) {
				++$no_alt;
			}
		}
		$thumb = ! empty( $d['has_thumb'] );
		$add( 'thumb', $thumb ? 'ok' : 'warn', 5 );
		$add( 'images', ( $img_total > 0 || $thumb ) ? 'ok' : 'warn', 4 );
		if ( $img_total > 0 ) {
			$add( 'img_alt', 0 === $no_alt ? 'ok' : ( $no_alt < $img_total ? 'warn' : 'bad' ), 6, array( 'missing' => $no_alt, 'total' => $img_total ) );
		}

		// Links.
		preg_match_all( '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#is', $html, $lm );
		$internal = 0;
		$external = 0;
		foreach ( $lm[2] as $href ) {
			$h = html_entity_decode( trim( $href ) );
			if ( '' === $h || '#' === $h[0] || preg_match( '#^(mailto|tel|javascript):#i', $h ) ) {
				continue;
			}
			if ( preg_match( '#^(https?:)?//([^/:?\#]+)#i', $h, $hm2 ) ) {
				( '' !== $host && ( strtolower( $hm2[2] ) === $host || substr( strtolower( $hm2[2] ), -strlen( $host ) - 1 ) === '.' . $host ) ) ? ++$internal : ++$external;
			} else {
				++$internal;
			}
		}
		$add( 'links_internal', $internal > 0 ? 'ok' : 'warn', 5, array( 'count' => $internal ) );
		$add( 'links_external', $external > 0 ? 'ok' : 'warn', 3, array( 'count' => $external ) );

		// Slug.
		$add( 'slug_len', '' === $slug ? 'warn' : ( mb_strlen( $slug, 'UTF-8' ) <= 75 ? 'ok' : 'warn' ), 3, array( 'len' => mb_strlen( $slug, 'UTF-8' ) ) );

		// Readability: sentence and paragraph length.
		if ( $wc >= 80 ) {
			$sentences = preg_split( '/[.!?؟!۔\n]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
			$sentences = array_filter( (array) $sentences, static function ( $s ) {
				return count( IRNoti_SEO::words( $s ) ) > 0;
			} );
			$avg       = $sentences ? $wc / count( $sentences ) : $wc;
			$add( 'readability', $avg <= 22 ? 'ok' : ( $avg <= 32 ? 'warn' : 'bad' ), 5, array( 'avg' => (int) round( $avg ) ) );
		}

		$sum   = 0;
		$got   = 0.0;
		$count = array( 'ok' => 0, 'warn' => 0, 'bad' => 0 );
		foreach ( $checks as $c ) {
			$sum += $c['w'];
			$got += $c['w'] * ( 'ok' === $c['state'] ? 1 : ( 'warn' === $c['state'] ? 0.5 : 0 ) );
			++$count[ $c['state'] ];
		}
		$score = $sum ? (int) round( $got / $sum * 100 ) : 0;
		usort( $checks, static function ( $a, $b ) {
			$o = array( 'bad' => 0, 'warn' => 1, 'ok' => 2 );
			return array( $o[ $a['state'] ], -$a['w'] ) <=> array( $o[ $b['state'] ], -$b['w'] );
		} );
		return array( 'score' => $score, 'grade' => $score >= 80 ? 'good' : ( $score >= 50 ? 'mid' : 'low' ), 'checks' => $checks, 'counts' => $count, 'words' => $wc );
	}

	/** Persian message for one check (label, detail) — kept apart from analyze() so that stays pure. */
	public static function message( array $c ): array {
		$a = $c['args'];
		$n = static function ( $v ) {
			return number_format_i18n( (float) $v );
		};
		switch ( $c['id'] ) {
			case 'title_len':
				/* translators: %s: title length */
				return array( __( 'طول عنوان', 'irnoti-service' ), sprintf( __( 'عنوان %s نویسه است؛ مقدار مناسب ۳۰ تا ۶۰.', 'irnoti-service' ), $n( $a['len'] ) ) );
			case 'desc_len':
				return array( __( 'توضیحات متا', 'irnoti-service' ), 0 === $a['len'] ? __( 'توضیحات متا خالی است؛ گوگل خودش از متن می‌چیند و جذابیت نتیجه کم می‌شود.', 'irnoti-service' ) : sprintf( /* translators: %s: length */ __( '%s نویسه؛ مقدار مناسب ۷۰ تا ۱۶۰.', 'irnoti-service' ), $n( $a['len'] ) ) );
			case 'words':
				return array( __( 'حجم محتوا', 'irnoti-service' ), sprintf( /* translators: 1: words, 2: wanted */ __( '%1$s کلمه؛ حداقل پیشنهادی %2$s.', 'irnoti-service' ), $n( $a['count'] ), $n( $a['need'] ) ) );
			case 'kw_missing':
				return array( __( 'کلمهٔ کلیدی', 'irnoti-service' ), __( 'کلمهٔ کلیدی اصلی تعیین نشده؛ بدون آن بقیهٔ بررسی‌های کلمهٔ کلیدی انجام نمی‌شود.', 'irnoti-service' ) );
			case 'kw_title':
				return array( __( 'کلمهٔ کلیدی در عنوان', 'irnoti-service' ), __( 'کلمهٔ کلیدی باید در عنوان بیاید (ترجیحاً ابتدای آن).', 'irnoti-service' ) );
			case 'kw_desc':
				return array( __( 'کلمهٔ کلیدی در توضیحات متا', 'irnoti-service' ), __( 'کلمهٔ کلیدی را در توضیحات متا بیاورید.', 'irnoti-service' ) );
			case 'kw_intro':
				return array( __( 'کلمهٔ کلیدی در ابتدای متن', 'irnoti-service' ), __( 'کلمهٔ کلیدی در ۱۰۰ کلمهٔ اول دیده نمی‌شود.', 'irnoti-service' ) );
			case 'kw_heading':
				return array( __( 'کلمهٔ کلیدی در زیرعنوان', 'irnoti-service' ), __( 'حداقل در یکی از زیرعنوان‌ها (H2/H3) از کلمهٔ کلیدی استفاده کنید.', 'irnoti-service' ) );
			case 'kw_slug':
				return array( __( 'کلمهٔ کلیدی در نشانی (نامک)', 'irnoti-service' ), __( 'نامک باید کلمهٔ کلیدی را داشته باشد.', 'irnoti-service' ) );
			case 'kw_density':
				return array( __( 'تراکم کلمهٔ کلیدی', 'irnoti-service' ), sprintf( /* translators: 1: percent, 2: times */ __( '٪%1$s (%2$s بار)؛ مقدار مناسب ۰٫۵ تا ۲٫۵ درصد.', 'irnoti-service' ), $n( $a['pct'] ), $n( $a['hits'] ) ) );
			case 'h1_in_content':
				return array( __( 'H1 داخل متن', 'irnoti-service' ), __( 'داخل متن تگ H1 هست؛ قالب عنوان را خودش H1 می‌کند، در متن از H2 و H3 استفاده کنید.', 'irnoti-service' ) );
			case 'headings':
				return array( __( 'زیرعنوان‌ها', 'irnoti-service' ), __( 'متن بلند بدون زیرعنوان (H2/H3) است؛ ساختار بدهید تا خواندن و ایندکس بهتر شود.', 'irnoti-service' ) );
			case 'thumb':
				return array( __( 'تصویر شاخص', 'irnoti-service' ), __( 'تصویر شاخص ندارد؛ برای اشتراک‌گذاری و نتایج تصویری لازم است (می‌توانید با هوش مصنوعی بسازید).', 'irnoti-service' ) );
			case 'images':
				return array( __( 'تصویر در محتوا', 'irnoti-service' ), __( 'هیچ تصویری ندارد.', 'irnoti-service' ) );
			case 'img_alt':
				return array( __( 'متن جایگزین تصاویر (alt)', 'irnoti-service' ), sprintf( /* translators: 1: missing, 2: total */ __( '%1$s از %2$s تصویر alt ندارد.', 'irnoti-service' ), $n( $a['missing'] ), $n( $a['total'] ) ) );
			case 'links_internal':
				return array( __( 'لینک داخلی', 'irnoti-service' ), __( 'لینکی به صفحات دیگر سایت ندارد.', 'irnoti-service' ) );
			case 'links_external':
				return array( __( 'لینک خارجی', 'irnoti-service' ), __( 'به منبع معتبر بیرونی لینک نداده است.', 'irnoti-service' ) );
			case 'slug_len':
				return array( __( 'نشانی (نامک)', 'irnoti-service' ), 0 === $a['len'] ? __( 'نامک خالی است.', 'irnoti-service' ) : sprintf( /* translators: %s: length */ __( 'نامک %s نویسه است؛ کوتاه‌ترش کنید (حداکثر ۷۵).', 'irnoti-service' ), $n( $a['len'] ) ) );
			case 'readability':
				return array( __( 'خوانایی', 'irnoti-service' ), sprintf( /* translators: %s: words per sentence */ __( 'میانگین %s کلمه در هر جمله؛ جمله‌ها را کوتاه‌تر کنید (زیر ۲۲).', 'irnoti-service' ), $n( $a['avg'] ) ) );
		}
		return array( $c['id'], '' );
	}

	/** Same text for passing OK checks (shown collapsed). */
	public static function ok_text( array $c ): string {
		return __( 'مشکلی نیست.', 'irnoti-service' ) . ( 'words' === $c['id'] ? ' (' . number_format_i18n( (int) $c['args']['count'] ) . ')' : '' );
	}
}
