<?php
defined( 'ABSPATH' ) || exit;

/**
 * IRNoti AI services: text generation, image generation, content trends. Everything goes through the site's own
 * IRNoti key (tokens are billed to the IRNoti account). The endpoint paths are collected in endpoints() — one place
 * to adjust (or filter with `irnoti_ai_endpoints`) if the server's routes differ. Response parsing is tolerant.
 */
class IRNoti_AI {
	const TRENDS_CACHE = 'irnoti_ai_trends';

	/** Route table; `{agent}` is replaced for the learning routes. */
	public static function endpoints(): array {
		return (array) apply_filters( 'irnoti_ai_endpoints', array(
			'text'   => 'ai/generate',
			'image'  => 'ai/images',
			'trends' => 'ai/trends',
			'learn'  => 'ai/agents/{agent}/learn',
		) );
	}

	public static function endpoint( string $name, array $vars = array() ): string {
		$path = (string) ( self::endpoints()[ $name ] ?? '' );
		foreach ( $vars as $k => $v ) {
			$path = str_replace( '{' . $k . '}', rawurlencode( (string) $v ), $path );
		}
		return $path;
	}

	/** Who may spend AI tokens from the post screens (filterable). */
	public static function capability(): string {
		return (string) apply_filters( 'irnoti_ai_capability', 'publish_posts' );
	}

	/** Runs one API call with a long timeout (AI answers take a while). */
	private static function call( callable $fn ) {
		$old                = IRNoti_API::$timeout;
		IRNoti_API::$timeout = 90;
		try {
			return $fn();
		} finally {
			IRNoti_API::$timeout = $old;
		}
	}

	/* ------------------------------------------------------------------ pure helpers */

	/** Finds the generated text in the many shapes a response may have. */
	public static function extract_text( $res ): string {
		if ( is_string( $res ) ) {
			return trim( $res );
		}
		if ( ! is_array( $res ) ) {
			return '';
		}
		foreach ( array( 'text', 'output', 'content', 'result', 'answer', 'message' ) as $k ) {
			if ( isset( $res[ $k ] ) ) {
				$t = self::extract_text( $res[ $k ] );
				if ( '' !== $t ) {
					return $t;
				}
			}
		}
		if ( isset( $res['choices'][0] ) ) {
			return self::extract_text( $res['choices'][0]['message']['content'] ?? $res['choices'][0]['text'] ?? '' );
		}
		return isset( $res['data'] ) ? self::extract_text( $res['data'] ) : '';
	}

	/** Image result → array('url' => ..) or array('b64' => ..); empty array when none found. */
	public static function extract_image( $res ): array {
		if ( ! is_array( $res ) ) {
			return array();
		}
		foreach ( array( $res, $res['data'] ?? null, $res['images'][0] ?? null, $res['data'][0] ?? null, $res['result'] ?? null ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			foreach ( array( 'url', 'imageUrl', 'image_url' ) as $k ) {
				if ( ! empty( $c[ $k ] ) && is_string( $c[ $k ] ) ) {
					return array( 'url' => $c[ $k ] );
				}
			}
			foreach ( array( 'b64', 'b64_json', 'base64', 'image' ) as $k ) {
				if ( ! empty( $c[ $k ] ) && is_string( $c[ $k ] ) ) {
					return array( 'b64' => preg_replace( '#^data:image/[a-z+]+;base64,#i', '', $c[ $k ] ) );
				}
			}
		}
		return array();
	}

	/** Trend items → list of array(title, keywords[], summary, score). */
	public static function normalize_trends( $res ): array {
		$items = is_array( $res ) ? ( $res['items'] ?? $res['trends'] ?? $res['data'] ?? $res ) : array();
		$out   = array();
		foreach ( (array) $items as $i ) {
			if ( is_string( $i ) ) {
				$i = array( 'title' => $i );
			}
			if ( ! is_array( $i ) ) {
				continue;
			}
			$title = trim( (string) ( $i['title'] ?? $i['topic'] ?? $i['keyword'] ?? $i['name'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$kw = $i['keywords'] ?? $i['tags'] ?? array();
			$kw = is_string( $kw ) ? preg_split( '/[,،]+/u', $kw ) : (array) $kw;
			$out[] = array(
				'title'    => mb_substr( $title, 0, 160 ),
				'keywords' => array_slice( array_values( array_filter( array_map( static function ( $k ) {
					return mb_substr( trim( (string) $k ), 0, 60 );
				}, $kw ) ) ), 0, 6 ),
				'summary'  => mb_substr( trim( (string) ( $i['summary'] ?? $i['description'] ?? '' ) ), 0, 300 ),
				'score'    => isset( $i['score'] ) ? (float) $i['score'] : ( isset( $i['volume'] ) ? (float) $i['volume'] : null ),
			);
		}
		return array_slice( $out, 0, 20 );
	}

	/** First non-empty line = title, the rest = body (an optional "عنوان:" / "Title:" prefix is stripped). */
	public static function split_title_body( string $text ): array {
		$text  = trim( preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', trim( $text ) ) );
		$lines = preg_split( '/\r?\n/', $text, 2 );
		$title = trim( preg_replace( '/^(?:#+\s*|(?:عنوان|title)\s*[:：]\s*)/iu', '', strip_tags( (string) ( $lines[0] ?? '' ) ) ), " \t*\"«»" );
		return array( $title, trim( (string) ( $lines[1] ?? '' ) ) );
	}

	/** Option lines ("1. foo", "- bar") → clean list. */
	public static function split_lines( string $text, int $max = 8 ): array {
		$out = array();
		foreach ( preg_split( '/\r?\n/', $text ) as $l ) {
			$l = trim( preg_replace( '/^\s*(?:[-*•]+|\d+[.)\-]|[۰-۹]+[.)\-])\s*/u', '', $l ), " \t\"«»*" );
			if ( '' !== $l ) {
				$out[] = $l;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, $max );
	}

	/**
	 * Prompt for one editor action. Returns array(system, prompt, type, max_tokens); type tells the UI how to show it:
	 * lines (pick one), html (editor content), text.
	 *
	 * @param array{title?:string,text?:string,kw?:string,desc?:string,type?:string,tone?:string} $c
	 */
	public static function build_prompt( string $action, array $c ): array {
		$tones = array(
			'professional' => 'رسمی و حرفه‌ای',
			'friendly'     => 'دوستانه و صمیمی',
			'sales'        => 'فروش‌محور و ترغیب‌کننده',
			'educational'  => 'آموزشی و ساده',
		);
		$tone  = $tones[ $c['tone'] ?? '' ] ?? $tones['professional'];
		$kind  = array( 'product' => 'محصول', 'page' => 'صفحه' )[ $c['type'] ?? '' ] ?? 'مقاله';
		$title = trim( (string) ( $c['title'] ?? '' ) );
		$kw    = trim( (string) ( $c['kw'] ?? '' ) );
		$text  = mb_substr( trim( (string) ( $c['text'] ?? '' ) ), 0, 6000 );
		$ctx   = "نوع محتوا: $kind\nعنوان فعلی: $title\n" . ( '' !== $kw ? "کلمهٔ کلیدی اصلی: $kw\n" : '' ) . ( '' !== $text ? "متن:\n$text\n" : '' );
		$sys   = "تو یک متخصص ارشد سئو و تولید محتوای فارسی هستی. لحن: $tone. فقط خروجی خواسته‌شده را بده، بدون مقدمه و توضیح اضافه، بدون علامت‌گذاری مارک‌داون مگر خواسته شده. هیچ اطلاعات جعلی (قیمت، آمار، مشخصات) از خودت اضافه نکن.";
		switch ( $action ) {
			case 'title':
				return array( $sys, "$ctx\nپنج عنوان سئوشده و جذاب بنویس؛ هرکدام حداکثر ۶۰ نویسه، با کلمهٔ کلیدی نزدیک ابتدای عنوان (اگر داده شده). هر عنوان در یک خط، بدون شماره.", 'lines', 400 );
			case 'meta':
				return array( $sys, "$ctx\nسه توضیح متا (meta description) بنویس؛ هرکدام بین ۱۲۰ تا ۱۵۵ نویسه، شامل کلمهٔ کلیدی، با دعوت به اقدام. هر کدام در یک خط، بدون شماره.", 'lines', 500 );
			case 'keyword':
				return array( $sys, "$ctx\nپنج کلمهٔ کلیدی اصلی مناسب برای این محتوا پیشنهاد بده (۲ تا ۴ کلمه‌ای، با نیت جستجوی واقعی). هر کدام در یک خط، بدون شماره و توضیح.", 'lines', 200 );
			case 'excerpt':
				return array( $sys, "$ctx\nیک خلاصهٔ کوتاه (حداکثر ۳۰۰ نویسه) برای این محتوا بنویس.", 'text', 300 );
			case 'optimize':
				return array( $sys, "$ctx\nاین متن را برای سئو بازنویسی و بهینه کن: ساختار با H2 و H3، پاراگراف‌های کوتاه، جمله‌های ساده، کلمهٔ کلیدی طبیعی در مقدمه و حداقل یک زیرعنوان (بدون تکرار افراطی)، فهرست‌های لازم، و نتیجه‌گیری. محتوای واقعی را حفظ کن و چیزی از خودت نساز. خروجی فقط HTML ساده شامل p, h2, h3, ul, ol, li, strong, a باشد (بدون h1، بدون html/body).", 'html', 4000 );
			case 'expand':
				return array( $sys, "$ctx\nاین متن کوتاه است. آن را با اطلاعات مفید و بی‌ادعا گسترش بده و ساختار سئویی (H2/H3، لیست) بده، ولی ادعای عددی یا مشخصاتی که در متن نیست اضافه نکن. خروجی فقط HTML ساده (p, h2, h3, ul, ol, li, strong).", 'html', 4000 );
			case 'faq':
				return array( $sys, "$ctx\nپنج پرسش پرتکرار با پاسخ کوتاه دربارهٔ این موضوع بنویس. خروجی HTML: یک <h2>پرسش‌های متداول</h2> و برای هر مورد <h3>پرسش</h3><p>پاسخ</p>.", 'html', 2000 );
			case 'image_prompt':
				return array( 'You write prompts for an AI image generator. Output only the prompt, in English, one paragraph, no quotes.', "Write a prompt for a professional, photorealistic, clean featured image (no text, no logos, no watermark, 16:9) for this web content.\n$ctx", 'text', 300 );
			case 'alt':
				return array( $sys, "$ctx\nیک متن جایگزین (alt) توصیفی و کوتاه (حداکثر ۱۲۵ نویسه) برای تصویر شاخص این محتوا بنویس.", 'text', 120 );
			case 'article':
				return array( $sys, "موضوع: $title\n" . ( '' !== $kw ? "کلمات کلیدی: $kw\n" : '' ) . "یک مقالهٔ کامل سئوشده (حداقل ۸۰۰ کلمه) دربارهٔ این موضوع بنویس. خط اول فقط عنوان مقاله (حداکثر ۶۰ نویسه) باشد؛ از خط دوم بدنه به صورت HTML ساده (p, h2, h3, ul, ol, li, strong) با مقدمه، زیرعنوان‌ها، بخش پرسش‌های متداول و نتیجه‌گیری. چیزی که مطمئن نیستی را به‌عنوان آمار یا واقعیت قطعی ننویس.", 'html', 6000 );
		}
		return array( $sys, $ctx, 'text', 800 );
	}

	/* ------------------------------------------------------------------ API calls */

	/** Generates text for an editor action. Returns array(type, text, lines?) or WP_Error. */
	public static function run( string $action, array $ctx ) {
		list( $sys, $prompt, $type, $max ) = self::build_prompt( $action, $ctx + array( 'tone' => (string) IRNoti_Settings::get( 'seo_tone' ) ) );
		$res = self::call( static function () use ( $action, $sys, $prompt, $max ) {
			return IRNoti_API::post( self::endpoint( 'text' ), array(
				'task'      => $action,
				'system'    => $sys,
				'prompt'    => $prompt,
				'language'  => 'fa',
				'maxTokens' => $max,
				'source'    => 'wordpress',
			) );
		} );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$text = self::extract_text( $res );
		if ( '' === $text ) {
			return new WP_Error( 'irnoti_ai_empty', __( 'پاسخ خالی از سرویس هوش مصنوعی دریافت شد.', 'irnoti-service' ) );
		}
		$out = array( 'type' => $type, 'text' => $text );
		if ( 'lines' === $type ) {
			$out['lines'] = self::split_lines( $text );
		} elseif ( 'html' === $type ) {
			$out['text'] = wp_kses_post( preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', trim( $text ) ) );
		} else {
			$out['text'] = trim( wp_strip_all_tags( $text ) );
		}
		return $out;
	}

	/** Generates an image from a prompt. Returns array('url'|'b64' => …) or WP_Error. */
	public static function image( string $prompt ) {
		$res = self::call( static function () use ( $prompt ) {
			return IRNoti_API::post( self::endpoint( 'image' ), array(
				'prompt' => $prompt,
				'size'   => '1344x768',
				'aspect' => '16:9',
				'source' => 'wordpress',
			) );
		} );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$img = self::extract_image( $res );
		return $img ?: new WP_Error( 'irnoti_ai_no_image', __( 'سرویس تصویری نساخت یا پاسخ ناشناخته بود.', 'irnoti-service' ) );
	}

	/** Puts a generated image (https url or base64) into the media library. Returns attachment ID or WP_Error. */
	public static function sideload( array $img, int $post_id, string $title, string $alt = '' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$name = sanitize_file_name( ( sanitize_title( $title ) ?: 'irnoti-ai' ) . '-' . wp_generate_password( 5, false ) );
		if ( ! empty( $img['url'] ) ) {
			if ( 0 !== strpos( (string) $img['url'], 'https://' ) ) {
				return new WP_Error( 'irnoti_ai_url', __( 'آدرس تصویر باید https باشد.', 'irnoti-service' ) );
			}
			$tmp = download_url( (string) $img['url'], 60 );
		} else {
			$bin = base64_decode( (string) ( $img['b64'] ?? '' ), true );
			$tmp = false === $bin ? new WP_Error( 'irnoti_ai_b64', __( 'تصویر دریافتی خراب است.', 'irnoti-service' ) ) : wp_tempnam( $name );
			if ( ! is_wp_error( $tmp ) ) {
				file_put_contents( $tmp, $bin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$ext  = array( IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif' )[ $info[2] ?? 0 ] ?? '';
		if ( '' === $ext ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'irnoti_ai_type', __( 'فایل دریافتی تصویر معتبر نیست.', 'irnoti-service' ) );
		}
		$id = media_handle_sideload( array( 'name' => $name . '.' . $ext, 'tmp_name' => $tmp ), $post_id, $title );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return (int) $id;
	}

	/** Trending topics for content ideas; cached for an hour. */
	public static function trends( bool $fresh = false ) {
		$cached = $fresh ? false : get_transient( self::TRENDS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = self::call( static function () {
			return IRNoti_API::get( self::endpoint( 'trends' ), array( 'lang' => 'fa', 'limit' => 12 ) );
		} );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$items = self::normalize_trends( $res );
		set_transient( self::TRENDS_CACHE, $items, HOUR_IN_SECONDS );
		return $items;
	}

	/** Per-user brake so a stuck button or a script cannot burn tokens: 20 calls per minute. */
	public static function throttle(): bool {
		$k = 'irnoti_ai_rate_' . get_current_user_id();
		$n = (int) get_transient( $k );
		if ( $n >= 20 ) {
			return false;
		}
		set_transient( $k, $n + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
