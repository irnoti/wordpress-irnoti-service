<?php
defined( 'ABSPATH' ) || exit;

/** Admin side of the AI content tools: content list with SEO scores, trends box, editor box, AJAX endpoints, learning box. */
class IRNoti_Admin_SEO {
	const NONCE    = 'irnoti_seo';
	const PER_PAGE = 20;
	const SCAN_BATCH = 25;

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'analyze', 'action', 'image', 'meta', 'scan', 'trends', 'draft' ) as $a ) {
			add_action( 'wp_ajax_irnoti_seo_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/* ------------------------------------------------------------------ assets */

	public static function assets( string $hook ): void {
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$on_box = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && self::box_screen();
		$on_tab = 'toplevel_page_' . IRNoti_Admin::SLUG === $hook && 'content' === $tab;
		if ( ! $on_box && ! $on_tab ) {
			return;
		}
		wp_enqueue_style( 'irnoti-seo', IRNOTI_URL . 'assets/css/seo.css', array(), IRNOTI_VERSION );
		wp_enqueue_script( 'irnoti-seo', IRNOTI_URL . 'assets/js/seo.js', array(), IRNOTI_VERSION, true );
		wp_localize_script( 'irnoti-seo', 'IRNOTI_SEO', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
			'store' => IRNoti_SEO::store(),
		) );
	}

	private static function box_screen(): bool {
		$s = get_current_screen();
		return $s && in_array( $s->post_type, IRNoti_SEO::post_types(), true );
	}

	/* ------------------------------------------------------------------ settings */

	public static function save_settings( string $tab, array $p ): void {
		if ( 'seo' === $tab ) {
			$types = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $p['seo_post_types'] ?? array() ) ), array_keys( self::available_types() ) ) );
			IRNoti_Settings::update( array(
				'seo_post_types'  => $types ?: array( 'post' ),
				'seo_tone'        => in_array( $p['seo_tone'] ?? '', array( 'professional', 'friendly', 'sales', 'educational' ), true ) ? $p['seo_tone'] : 'professional',
				'seo_meta_output' => empty( $p['seo_meta_output'] ) ? 0 : 1,
				'ai_model'        => preg_replace( '/[^A-Za-z0-9._:\/\-]/', '', (string) ( $p['ai_model'] ?? '' ) ),
			) );
		}
	}

	/** Public post types the tools can work on: slug => label. */
	private static function available_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $o ) {
			if ( 'attachment' !== $slug ) {
				$out[ $slug ] = $o->labels->name;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ content tab */

	private static function url( array $extra = array() ): string {
		return add_query_arg( array_merge( array( 'page' => IRNoti_Admin::SLUG, 'tab' => 'content' ), $extra ), admin_url( 'admin.php' ) );
	}

	public static function render_tab(): void {
		$connected = IRNoti_API::connected();
		if ( ! $connected ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'امتیاز سئو بدون اتصال هم کار می‌کند، ولی تولید و بهینه‌سازی با هوش مصنوعی، تصویر و ایده‌های محتوا به کلید API فعال نیاز دارد (تب «اتصال»؛ دسترسی‌های ai.read، ai.chat و ai.image).', 'irnoti-service' ) . '</p></div>';
		}
		self::render_trends( $connected );
		self::render_list();
		self::render_settings();
	}

	private static function render_trends( bool $connected ): void {
		echo '<h2>' . esc_html__( 'ایده‌ها و موضوع‌های پیشنهادی', 'irnoti-service' ) . '</h2><p class="description">' . esc_html__( 'هوش مصنوعی IRNoti بر اساس دسته‌ها و نوشته‌های فعلی سایت شما موضوع‌های تازه پیشنهاد می‌دهد (پیشنهاد مدل است، نه آمار جستجو). با یک کلیک پیش‌نویس سئوشده بسازید.', 'irnoti-service' ) . '</p>';
		echo '<div id="irnoti-trends" class="irnseo-trends" data-enabled="' . ( $connected ? '1' : '0' ) . '"><p class="irn-empty">' . esc_html( $connected ? __( 'در حال دریافت…', 'irnoti-service' ) : '—' ) . '</p></div>';
		if ( $connected ) {
			echo '<p><button type="button" class="button" id="irnoti-trends-refresh">' . esc_html__( 'پیشنهاد دوباره', 'irnoti-service' ) . '</button></p>';
		}
	}

	private static function render_list(): void {
		$types   = IRNoti_SEO::post_types();
		$all     = self::available_types();
		$type    = isset( $_GET['ptype'] ) && in_array( $_GET['ptype'], $types, true ) ? sanitize_key( $_GET['ptype'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$search  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$worst   = isset( $_GET['sort'] ) && 'worst' === $_GET['sort']; // phpcs:ignore WordPress.Security.NonceVerification
		$paged   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$args    = array(
			'post_type'      => $type ? array( $type ) : $types,
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			's'              => $search,
		);
		if ( $worst ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation'  => 'OR',
				'irn_score' => array( 'key' => IRNoti_SEO::SCORE, 'compare' => 'EXISTS', 'type' => 'NUMERIC' ),
				array( 'key' => IRNoti_SEO::SCORE, 'compare' => 'NOT EXISTS' ),
			);
			$args['orderby']    = array( 'irn_score' => 'ASC', 'date' => 'DESC' );
		}
		$q = new WP_Query( $args );

		echo '<h2>' . esc_html__( 'نوشته‌ها، محصولات و برگه‌ها', 'irnoti-service' ) . '</h2>';
		echo '<form method="get" class="irnseo-filter"><input type="hidden" name="page" value="' . esc_attr( IRNoti_Admin::SLUG ) . '"><input type="hidden" name="tab" value="content">';
		echo '<select name="ptype"><option value="">' . esc_html__( 'همهٔ انواع', 'irnoti-service' ) . '</option>';
		foreach ( $types as $t ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $t ), selected( $type, $t, false ), esc_html( $all[ $t ] ?? $t ) );
		}
		echo '</select> <select name="sort"><option value="">' . esc_html__( 'جدیدترین', 'irnoti-service' ) . '</option><option value="worst" ' . selected( $worst, true, false ) . '>' . esc_html__( 'کمترین امتیاز سئو', 'irnoti-service' ) . '</option></select> ';
		echo '<input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'جستجوی عنوان', 'irnoti-service' ) . '"> <button class="button">' . esc_html__( 'نمایش', 'irnoti-service' ) . '</button> ';
		echo '<button type="button" class="button button-primary" id="irnoti-scan">' . esc_html__( 'تحلیل همهٔ محتوا', 'irnoti-service' ) . '</button> <span id="irnoti-scan-status"></span></form>';
		echo '<p class="description">' . esc_html__( 'برای مرتب‌سازی «کمترین امتیاز» یک بار «تحلیل همهٔ محتوا» را بزنید؛ امتیاز با هر ذخیرهٔ نوشته به‌روز می‌شود.', 'irnoti-service' ) . '</p>';

		echo '<table class="widefat striped irnseo-list"><thead><tr><th>' . esc_html__( 'عنوان', 'irnoti-service' ) . '</th><th>' . esc_html__( 'نوع', 'irnoti-service' ) . '</th><th>' . esc_html__( 'امتیاز سئو', 'irnoti-service' ) . '</th><th>' . esc_html__( 'مشکلات', 'irnoti-service' ) . '</th><th></th></tr></thead><tbody>';
		$cls = array( 'good' => 'ok', 'mid' => 'warn', 'low' => 'bad' );
		foreach ( $q->posts as $post ) {
			$res    = IRNoti_SEO::analyze_post( $post );
			$issues = array_filter( $res['checks'], static function ( $c ) {
				return 'ok' !== $c['state'];
			} );
			echo '<tr><td><strong>' . esc_html( get_the_title( $post ) ?: __( '(بدون عنوان)', 'irnoti-service' ) ) . '</strong></td>';
			echo '<td>' . esc_html( $all[ $post->post_type ] ?? $post->post_type ) . '</td>';
			printf( '<td><span class="irn-badge %s">%s</span></td>', esc_attr( $cls[ $res['grade'] ] ), esc_html( number_format_i18n( $res['score'] ) ) );
			echo '<td>';
			if ( $issues ) {
				echo '<details class="irnseo-issues"><summary>' . esc_html( sprintf( /* translators: %s: count */ __( '%s مورد', 'irnoti-service' ), number_format_i18n( count( $issues ) ) ) ) . '</summary><ul>';
				foreach ( $issues as $c ) {
					$m = IRNoti_SEO::message( $c );
					printf( '<li class="is-%s"><b>%s</b> — %s</li>', esc_attr( $c['state'] ), esc_html( $m[0] ), esc_html( $m[1] ) );
				}
				echo '</ul></details>';
			} else {
				echo '<span class="irn-badge ok">' . esc_html__( 'بی‌مشکل', 'irnoti-service' ) . '</span>';
			}
			echo '</td><td><a class="button button-small" href="' . esc_url( (string) get_edit_post_link( $post->ID, 'raw' ) . '#irnoti_seo_box' ) . '">' . esc_html__( 'بهینه‌سازی با AI', 'irnoti-service' ) . '</a></td></tr>';
		}
		if ( ! $q->posts ) {
			echo '<tr><td colspan="5" class="irn-empty">—</td></tr>';
		}
		echo '</tbody></table>';
		$links = paginate_links( array(
			'base'      => add_query_arg( 'paged', '%#%', self::url( array_filter( array( 'ptype' => $type, 'q' => $search, 'sort' => $worst ? 'worst' : '' ) ) ) ),
			'format'    => '',
			'current'   => $paged,
			'total'     => max( 1, (int) $q->max_num_pages ),
			'prev_text' => '›',
			'next_text' => '‹',
		) );
		if ( $links ) {
			echo '<p class="irnseo-pages">' . wp_kses_post( $links ) . '</p>';
		}
	}

	private static function render_settings(): void {
		$sel   = IRNoti_SEO::post_types();
		$tone  = (string) IRNoti_Settings::get( 'seo_tone' );
		$tones = array( 'professional' => __( 'رسمی و حرفه‌ای', 'irnoti-service' ), 'friendly' => __( 'دوستانه', 'irnoti-service' ), 'sales' => __( 'فروش‌محور', 'irnoti-service' ), 'educational' => __( 'آموزشی', 'irnoti-service' ) );
		echo '<h2>' . esc_html__( 'تنظیمات محتوا و سئو', 'irnoti-service' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'irnoti_save' );
		echo '<input type="hidden" name="action" value="irnoti_save"><input type="hidden" name="tab" value="seo"><table class="form-table">';
		$boxes = '';
		foreach ( self::available_types() as $slug => $label ) {
			$boxes .= '<label style="margin-inline-end:16px"><input type="checkbox" name="seo_post_types[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, $sel, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<tr><th>' . esc_html__( 'انواع محتوا', 'irnoti-service' ) . '</th><td>' . $boxes . '<p class="description">' . esc_html__( 'جعبهٔ «دستیار سئو IRNoti» در صفحهٔ ویرایش این انواع نمایش داده می‌شود.', 'irnoti-service' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$opts = '';
		foreach ( $tones as $k => $l ) {
			$opts .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $tone, $k, false ), esc_html( $l ) );
		}
		echo '<tr><th>' . esc_html__( 'لحن نوشتار', 'irnoti-service' ) . '</th><td><select name="seo_tone">' . $opts . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$models = IRNoti_API::connected() ? IRNoti_AI::models() : array();
		$mopt   = '<option value="">' . esc_html__( 'خودکار (اولین مدل فعال)', 'irnoti-service' ) . '</option>';
		foreach ( is_array( $models ) ? $models : array() as $m ) {
			$mopt .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $m ), selected( (string) IRNoti_Settings::get( 'ai_model' ), $m, false ), esc_html( $m ) );
		}
		echo '<tr><th>' . esc_html__( 'مدل هوش مصنوعی', 'irnoti-service' ) . '</th><td><select name="ai_model">' . $mopt . '</select><p class="description">' . esc_html__( 'فهرست مدل‌ها و ضریب هزینه در پنل IRNoti ← هوش مصنوعی است.', 'irnoti-service' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$own = 'own' === IRNoti_SEO::store();
		echo '<tr><th>' . esc_html__( 'تگ توضیحات متا', 'irnoti-service' ) . '</th><td><label class="irn-switch"><input type="checkbox" name="seo_meta_output" value="1" ' . checked( 1, (int) IRNoti_Settings::get( 'seo_meta_output' ), false ) . ( $own ? '' : ' disabled' ) . '><span class="irn-track"></span><span class="irn-switch-label">' . esc_html__( 'چاپ meta description در سایت', 'irnoti-service' ) . '</span></label><p class="description">' . esc_html( $own ? __( 'افزونهٔ سئوی دیگری فعال نیست؛ IRNoti توضیحات متا را در head چاپ می‌کند.', 'irnoti-service' ) : sprintf( /* translators: %s: plugin name */ __( 'افزونهٔ %s فعال است؛ توضیحات و کلمهٔ کلیدی در همان ذخیره می‌شود و چاپ تگ با خود آن است.', 'irnoti-service' ), 'yoast' === IRNoti_SEO::store() ? 'Yoast SEO' : 'Rank Math' ) ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</table>';
		submit_button( __( 'ذخیرهٔ تنظیمات', 'irnoti-service' ) );
		echo '</form>';
	}

	/* ------------------------------------------------------------------ editor box */

	public static function add_box(): void {
		foreach ( IRNoti_SEO::post_types() as $t ) {
			add_meta_box( 'irnoti_seo_box', __( 'دستیار سئو و هوش مصنوعی IRNoti', 'irnoti-service' ), array( __CLASS__, 'render_box' ), $t, 'normal', 'high' );
		}
	}

	public static function render_box( WP_Post $post ): void {
		$res  = IRNoti_SEO::analyze( IRNoti_SEO::data_for( $post ) );
		$ai   = IRNoti_API::connected() && current_user_can( IRNoti_AI::capability() );
		$desc = IRNoti_SEO::get_desc( $post->ID );
		$kw   = IRNoti_SEO::get_kw( $post->ID );
		wp_nonce_field( 'irnoti_seo_box', 'irnoti_seo_box_nonce' );
		printf( '<div id="irnoti-seo-box" class="irnseo" dir="rtl" data-post="%d" data-ai="%d" data-type="%s">', (int) $post->ID, $ai ? 1 : 0, esc_attr( $post->post_type ) );
		echo '<div class="irnseo-top"><div class="irnseo-score" id="irnoti-seo-score"></div><div class="irnseo-fields">';
		echo '<label>' . esc_html__( 'کلمهٔ کلیدی اصلی', 'irnoti-service' ) . '<input type="text" id="irnoti-seo-kw" name="irnoti_seo_kw" value="' . esc_attr( $kw ) . '"></label>';
		echo '<label>' . esc_html__( 'توضیحات متا', 'irnoti-service' ) . ' <small id="irnoti-seo-desc-count"></small><textarea id="irnoti-seo-desc" name="irnoti_seo_desc" rows="2">' . esc_textarea( $desc ) . '</textarea></label>';
		echo '</div></div>';
		echo '<script type="application/json" id="irnoti-seo-init">' . wp_json_encode( self::pack( $res ) ) . '</script>';
		echo '<p><button type="button" class="button" id="irnoti-seo-recheck">' . esc_html__( 'تحلیل دوباره', 'irnoti-service' ) . '</button></p><ul class="irnseo-checks" id="irnoti-seo-checks"></ul>';
		if ( $ai ) {
			echo '<h4>' . esc_html__( 'ابزارهای هوش مصنوعی', 'irnoti-service' ) . '</h4><div class="irnseo-tools">';
			$tools = array(
				'title'    => __( 'پیشنهاد عنوان', 'irnoti-service' ),
				'meta'     => __( 'ساخت توضیحات متا', 'irnoti-service' ),
				'keyword'  => __( 'پیشنهاد کلمهٔ کلیدی', 'irnoti-service' ),
				'optimize' => __( 'بهینه‌سازی متن', 'irnoti-service' ),
				'expand'   => __( 'گسترش متن کوتاه', 'irnoti-service' ),
				'faq'      => __( 'تولید پرسش‌های متداول', 'irnoti-service' ),
				'excerpt'  => __( 'ساخت خلاصه', 'irnoti-service' ),
				'image'    => __( 'تولید تصویر شاخص', 'irnoti-service' ),
			);
			foreach ( $tools as $k => $l ) {
				printf( '<button type="button" class="button" data-ai="%s">%s</button> ', esc_attr( $k ), esc_html( $l ) );
			}
			echo '</div><div id="irnoti-ai-out" class="irnseo-out" hidden></div>';
			echo '<h4>' . esc_html__( 'ایده‌های موضوعی', 'irnoti-service' ) . '</h4><div id="irnoti-trends" class="irnseo-trends irnseo-mini" data-enabled="1" data-mini="1"></div>';
		} else {
			$why = IRNoti_API::connected() ? __( 'شما دسترسی استفاده از هوش مصنوعی را ندارید.', 'irnoti-service' ) : __( 'برای ابزارهای هوش مصنوعی ابتدا کلید API را در IRNoti ← اتصال وارد کنید.', 'irnoti-service' );
			echo '<p class="description">' . esc_html( $why ) . '</p>';
		}
		echo '</div>';
	}

	public static function save_box( int $post_id, $post ): void {
		if ( ! isset( $_POST['irnoti_seo_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['irnoti_seo_box_nonce'] ) ), 'irnoti_seo_box' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'own' !== IRNoti_SEO::store() ) {
			return; // Yoast / Rank Math save their own fields; ours are display + AI helpers only.
		}
		IRNoti_SEO::set_meta( $post_id, 'desc', wp_unslash( (string) ( $_POST['irnoti_seo_desc'] ?? '' ) ) );
		IRNoti_SEO::set_meta( $post_id, 'kw', wp_unslash( (string) ( $_POST['irnoti_seo_kw'] ?? '' ) ) );
	}

	/** JSON-ready analysis for the browser. */
	private static function pack( array $res ): array {
		$items = array();
		foreach ( $res['checks'] as $c ) {
			$m       = IRNoti_SEO::message( $c );
			$items[] = array( 'state' => $c['state'], 'label' => $m[0], 'detail' => 'ok' === $c['state'] ? IRNoti_SEO::ok_text( $c ) : $m[1] );
		}
		return array( 'score' => $res['score'], 'grade' => $res['grade'], 'words' => $res['words'], 'counts' => $res['counts'], 'items' => $items );
	}

	/* ------------------------------------------------------------------ AJAX */

	/** Common checks; returns the post (or null when none was given) and dies with a JSON error otherwise. */
	private static function guard( bool $ai = true, bool $admin = false ): ?WP_Post {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( $admin && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی ندارید.', 'irnoti-service' ) ), 403 );
		}
		if ( $ai ) {
			if ( ! current_user_can( IRNoti_AI::capability() ) ) {
				wp_send_json_error( array( 'message' => __( 'دسترسی ندارید.', 'irnoti-service' ) ), 403 );
			}
			if ( ! IRNoti_AI::throttle() ) {
				wp_send_json_error( array( 'message' => __( 'درخواست‌ها زیاد است؛ یک دقیقه صبر کنید.', 'irnoti-service' ) ), 429 );
			}
		}
		$id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $id ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی ندارید.', 'irnoti-service' ) ), 403 );
		}
		return $post;
	}

	private static function fail( WP_Error $e ): void {
		wp_send_json_error( array( 'message' => $e->get_error_message() ) );
	}

	/** Editor state posted by the browser (may be unsaved). */
	private static function ctx( ?WP_Post $post ): array {
		$g = static function ( string $k ) {
			return isset( $_POST[ $k ] ) ? wp_unslash( (string) $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- guard() verified it.
		};
		return array(
			'title'     => sanitize_text_field( $g( 'title' ) ),
			'content'   => mb_substr( wp_kses_post( $g( 'content' ) ), 0, 200000 ),
			'desc'      => sanitize_text_field( $g( 'desc' ) ),
			'kw'        => sanitize_text_field( $g( 'kw' ) ),
			'slug'      => sanitize_title( $g( 'slug' ) ?: ( $post->post_name ?? '' ) ),
			'has_thumb' => '1' === $g( 'has_thumb' ) || ( $post && has_post_thumbnail( $post->ID ) ),
			'type'      => $post ? $post->post_type : sanitize_key( $g( 'type' ) ),
			'host'      => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		);
	}

	public static function ajax_analyze(): void {
		$post = self::guard( false );
		wp_send_json_success( self::pack( IRNoti_SEO::analyze( self::ctx( $post ) ) ) );
	}

	public static function ajax_action(): void {
		$post   = self::guard();
		$action = sanitize_key( $_POST['task'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $action, array( 'title', 'meta', 'keyword', 'optimize', 'expand', 'faq', 'excerpt', 'alt' ), true ) ) {
			wp_send_json_error( array( 'message' => 'bad action' ), 400 );
		}
		$c = self::ctx( $post );
		if ( '' === trim( $c['title'] . $c['content'] ) ) {
			wp_send_json_error( array( 'message' => __( 'ابتدا عنوان یا متنی بنویسید.', 'irnoti-service' ) ) );
		}
		$out = IRNoti_AI::run( $action, array( 'title' => $c['title'], 'text' => IRNoti_SEO::text( $c['content'] ), 'kw' => $c['kw'], 'desc' => $c['desc'], 'type' => $c['type'] ) );
		is_wp_error( $out ) ? self::fail( $out ) : wp_send_json_success( $out );
	}

	public static function ajax_image(): void {
		$post = self::guard();
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'ابتدا نوشته را یک بار ذخیره کنید.', 'irnoti-service' ) ) );
		}
		$c      = self::ctx( $post );
		$prompt = sanitize_textarea_field( wp_unslash( (string) ( $_POST['prompt'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $prompt ) {
			$p = IRNoti_AI::run( 'image_prompt', array( 'title' => $c['title'], 'text' => IRNoti_SEO::text( $c['content'] ), 'kw' => $c['kw'], 'type' => $c['type'] ) );
			if ( is_wp_error( $p ) ) {
				self::fail( $p );
			}
			$prompt = mb_substr( $p['text'], 0, 900 );
		}
		$img = IRNoti_AI::image( $prompt );
		if ( is_wp_error( $img ) ) {
			self::fail( $img );
		}
		$alt = $c['kw'] ?: $c['title'];
		$id  = IRNoti_AI::sideload( $img, $post->ID, $c['title'] ?: 'irnoti-ai', $alt );
		if ( is_wp_error( $id ) ) {
			self::fail( $id );
		}
		wp_send_json_success( array( 'id' => $id, 'url' => wp_get_attachment_image_url( $id, 'medium' ), 'prompt' => $prompt ) );
	}

	public static function ajax_meta(): void {
		$post  = self::guard( false );
		$which = 'kw' === sanitize_key( $_POST['which'] ?? '' ) ? 'kw' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'no post' ), 400 );
		}
		IRNoti_SEO::set_meta( $post->ID, $which, wp_unslash( (string) ( $_POST['value'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		wp_send_json_success();
	}

	public static function ajax_scan(): void {
		self::guard( false, true );
		$page = max( 1, absint( $_POST['page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$q    = new WP_Query( array(
			'post_type'      => IRNoti_SEO::post_types(),
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => self::SCAN_BATCH,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		foreach ( $q->posts as $p ) {
			IRNoti_SEO::analyze_post( $p );
		}
		wp_send_json_success( array( 'count' => count( $q->posts ), 'total' => (int) $q->found_posts, 'done' => $page >= (int) $q->max_num_pages ) );
	}

	public static function ajax_trends(): void {
		self::guard();
		$res = IRNoti_AI::ideas( ! empty( $_POST['fresh'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		is_wp_error( $res ) ? self::fail( $res ) : wp_send_json_success( array( 'items' => $res ) );
	}

	/** Writes a draft article for a trend. */
	public static function ajax_draft(): void {
		self::guard();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی ندارید.', 'irnoti-service' ) ), 403 );
		}
		$topic = sanitize_text_field( wp_unslash( (string) ( $_POST['topic'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$kw    = sanitize_text_field( wp_unslash( (string) ( $_POST['kw'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $topic ) {
			wp_send_json_error( array( 'message' => 'no topic' ), 400 );
		}
		$out = IRNoti_AI::run( 'article', array( 'title' => $topic, 'kw' => $kw ?: $topic ) );
		if ( is_wp_error( $out ) ) {
			self::fail( $out );
		}
		list( $title, $body ) = IRNoti_AI::split_title_body( $out['text'] );
		$id                   = wp_insert_post( array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $title ?: $topic,
			'post_content' => wp_kses_post( $body ),
		), true );
		if ( is_wp_error( $id ) ) {
			self::fail( $id );
		}
		IRNoti_SEO::set_meta( (int) $id, 'kw', $kw ?: $topic );
		wp_send_json_success( array( 'id' => $id, 'edit' => get_edit_post_link( (int) $id, 'raw' ) ) );
	}
}
