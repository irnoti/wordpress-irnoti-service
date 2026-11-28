<?php
defined( 'ABSPATH' ) || exit;

/**
 * Link to the site's IRNoti Rank report (professional plan).
 *
 * Rules that make this safe, and that the tests below pin down:
 *  - PULL ONLY: nothing here opens a REST route, AJAX endpoint or file for the outside. The site asks IRNoti for work; IRNoti cannot call in.
 *  - ALLOW-LIST: only `post_meta` (title/description) and `jsonld` exist. Anything else is refused, and every value is re-checked here
 *    whatever the server said (clean_command() is pure, so it is unit-tested).
 *  - APPROVED ONLY: IRNoti hands out only the changes the owner approved in the studio.
 *  - The data sent is a health summary (versions and counts), never content, users, orders or secrets.
 *  - HTTPS and certificate verification always on, redirects never followed.
 * The token is its own credential (`irnwp_…`), separate from the API key, and works for this one site only.
 */
class IRNoti_Rank {
	const CRON   = 'irnoti_rank_sync';
	const STATE  = 'irnoti_rank_state';
	const LOG    = 'irnoti_rank_log';
	const TITLE  = '_irnoti_seo_title';
	const LD     = '_irnoti_jsonld';
	const LOCK   = 'irnoti_rank_lock';
	const MAX_TITLE = 70;
	const MAX_DESC  = 200;
	const MAX_LD    = 6000;

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'sync' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_jsonld' ), 1 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_title' ), 20 );
	}

	/* ───────────── pure helpers (no WordPress calls; tested in tests/pure-test.php) ───────────── */

	/** Text without tags, control characters or surplus whitespace. */
	public static function plain( string $s ): string {
		$s = strip_tags( $s );
		$s = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** A site path from what the server named: a path only (no scheme, host, query or traversal), or '' when it is not one. */
	public static function clean_path( string $p ): string {
		$p = trim( $p );
		if ( '' === $p || strlen( $p ) > 300 || preg_match( '#^[a-z][a-z0-9+.-]*:|//|\.\.|[\x00-\x1F\x7F\\\\?\#]#i', $p ) ) {
			return '';
		}
		$p = ltrim( $p, '/' );
		return '' === $p ? '' : '/' . $p;
	}

	/** True for a JSON object that is schema.org markup and small enough to print. */
	public static function schema_ok( string $json ): bool {
		if ( '' === $json || strlen( $json ) > self::MAX_LD ) {
			return false;
		}
		$j = json_decode( $json, true );
		return is_array( $j ) && ( array() === $j || array_keys( $j ) !== range( 0, count( $j ) - 1 ) ) && isset( $j['@context'] ) && is_string( $j['@context'] ) && false !== strpos( $j['@context'], 'schema.org' );
	}

	/** JSON for a <script type="application/ld+json">: tags and ampersands are escaped so the value can never close the script element. */
	public static function encode_ld( array $j ): string {
		return (string) json_encode( $j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	}

	/**
	 * Checks one command from the server against the allow-list. Returns array( ok, reason, data ); `data` is the cleaned command when ok.
	 * Unknown kinds, bad paths and over-long values are refused with a short machine-readable reason (reported back to IRNoti).
	 */
	public static function clean_command( array $c ): array {
		$path = self::clean_path( (string) ( $c['post'] ?? '' ) );
		if ( '' === $path ) {
			return array( false, 'bad_path', array() );
		}
		switch ( (string) ( $c['kind'] ?? '' ) ) {
			case 'post_meta':
				$title = self::plain( (string) ( $c['title'] ?? '' ) );
				$desc  = self::plain( (string) ( $c['description'] ?? '' ) );
				if ( '' === $title && '' === $desc ) {
					return array( false, 'empty', array() );
				}
				if ( mb_strlen( $title ) > self::MAX_TITLE || mb_strlen( $desc ) > self::MAX_DESC ) {
					return array( false, 'too_long', array() );
				}
				return array( true, 'ok', array( 'kind' => 'post_meta', 'post' => $path, 'title' => $title, 'description' => $desc ) );
			case 'jsonld':
				$json = (string) ( $c['json'] ?? '' );
				if ( ! self::schema_ok( $json ) ) {
					return array( false, strlen( $json ) > self::MAX_LD ? 'too_long' : 'not_schema', array() );
				}
				return array( true, 'ok', array( 'kind' => 'jsonld', 'post' => $path, 'json' => $json ) );
		}
		return array( false, 'unknown_kind', array() );
	}

	/** The value the server shows for a seo plugin: none | yoast | rankmath | aioseo. */
	public static function seo_name(): string {
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		$s = IRNoti_SEO::store();
		return 'own' === $s ? 'none' : $s;
	}

	/* ───────────── settings and schedule ───────────── */

	public static function token(): string {
		return IRNoti_Settings::rank_token();
	}

	public static function configured(): bool {
		return '' !== self::token();
	}

	public static function schedule(): void {
		if ( self::configured() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		} elseif ( ! self::configured() && wp_next_scheduled( self::CRON ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	public static function state(): array {
		return (array) get_option( self::STATE, array() );
	}

	/* ───────────── what is sent ───────────── */

	/** A read-only picture of the site: versions and counts only. */
	public static function snapshot(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$upd     = get_site_transient( 'update_plugins' );
		$core    = get_site_transient( 'update_core' );
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = array();
		foreach ( get_plugins() as $file => $p ) {
			$plugins[] = array(
				'name'    => mb_substr( (string) $p['Name'], 0, 80 ),
				'version' => mb_substr( (string) $p['Version'], 0, 20 ),
				'active'  => in_array( $file, $active, true ),
				'update'  => is_object( $upd ) && isset( $upd->response[ $file ] ),
			);
			if ( count( $plugins ) >= 120 ) {
				break;
			}
		}
		$theme = wp_get_theme();
		$posts = wp_count_posts( 'post' );
		$pages = wp_count_posts( 'page' );
		return array(
			'siteUrl'       => home_url( '/' ),
			'pluginVersion' => mb_substr( IRNOTI_VERSION, 0, 20 ),
			'wp'            => mb_substr( get_bloginfo( 'version' ), 0, 20 ),
			'php'           => mb_substr( PHP_VERSION, 0, 20 ),
			'locale'        => mb_substr( get_locale(), 0, 20 ),
			'multisite'     => is_multisite(),
			'https'         => 0 === strpos( home_url(), 'https://' ),
			'indexable'     => (bool) get_option( 'blog_public' ),
			'permalinks'    => '' !== (string) get_option( 'permalink_structure' ),
			'debugDisplay'  => defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ),
			'coreUpdate'    => is_object( $core ) && ! empty( $core->updates[0]->response ) && 'upgrade' === $core->updates[0]->response,
			'theme'         => array( 'name' => mb_substr( (string) $theme->get( 'Name' ), 0, 80 ), 'version' => mb_substr( (string) $theme->get( 'Version' ), 0, 20 ) ),
			'seoPlugin'     => self::seo_name(),
			'plugins'       => $plugins,
			'posts'         => isset( $posts->publish ) ? (int) $posts->publish : 0,
			'pages'         => isset( $pages->publish ) ? (int) $pages->publish : 0,
		);
	}

	/* ───────────── talking to IRNoti ───────────── */

	private static function call( string $path, array $body ) {
		$url = IRNoti_Settings::clean_base_url( (string) IRNoti_Settings::get( 'base_url' ) ) . '/' . ltrim( $path, '/' );
		return wp_safe_remote_post( $url, array(
			'timeout'             => 15,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 262144,
			'headers'             => array(
				'Authorization' => 'Bearer ' . self::token(),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'irnoti-wordpress/' . IRNOTI_VERSION,
			),
			'body'                => wp_json_encode( $body ),
		) );
	}

	private static function save_state( array $st ): array {
		$st['at'] = time();
		update_option( self::STATE, $st, false );
		return $st;
	}

	/**
	 * One round: send the snapshot, take the approved commands, apply them, tell IRNoti what happened. Never throws; the outcome is kept
	 * (without secrets) for the settings tab. A lock stops two rounds (cron and the button) from running at once.
	 */
	public static function sync(): array {
		if ( ! self::configured() ) {
			return self::save_state( array( 'ok' => false, 'msg' => 'no_token' ) );
		}
		if ( get_transient( self::LOCK ) ) {
			return self::state();
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );
		try {
			$res = self::call( 'rank/wp/hello', self::snapshot() );
			if ( is_wp_error( $res ) ) {
				return self::save_state( array( 'ok' => false, 'msg' => 'network', 'detail' => mb_substr( $res->get_error_message(), 0, 120 ) ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( 200 !== $code || ! is_array( $data ) ) {
				$err = is_array( $data ) && is_array( $data['error'] ?? null ) ? (string) ( $data['error']['code'] ?? '' ) : '';
				return self::save_state( array( 'ok' => false, 'msg' => 401 === $code ? 'bad_token' : ( 'wrong_site' === $err ? 'wrong_site' : 'http_' . $code ) ) );
			}
			$st = array( 'ok' => true, 'pro' => ! empty( $data['pro'] ), 'applied' => 0, 'failed' => 0 );
			if ( $st['pro'] && ! empty( $data['commands'] ) && is_array( $data['commands'] ) ) {
				foreach ( array_slice( $data['commands'], 0, 10 ) as $c ) {
					if ( ! is_array( $c ) || empty( $c['id'] ) || ! preg_match( '/^\d{1,18}$/', (string) $c['id'] ) ) {
						continue;
					}
					list( $ok, $reason ) = self::apply( $c );
					self::call( 'rank/wp/result', array( 'id' => (string) $c['id'], 'ok' => $ok, 'message' => $reason ) );
					$ok ? $st['applied']++ : $st['failed']++;
				}
			}
			return self::save_state( $st );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/* ───────────── doing the work ───────────── */

	private static function log( array $c, bool $ok, string $reason, int $post_id ): void {
		$log = (array) get_option( self::LOG, array() );
		array_unshift( $log, array( 'time' => time(), 'kind' => (string) ( $c['kind'] ?? '' ), 'post' => $post_id, 'ok' => $ok, 'reason' => $reason ) );
		update_option( self::LOG, array_slice( $log, 0, 50 ), false );
	}

	/** Applies one approved command. Returns array( ok, reason ). Only a published, publicly viewable post of this site can be touched. */
	public static function apply( array $raw ): array {
		list( $ok, $reason, $c ) = self::clean_command( $raw );
		if ( ! $ok ) {
			self::log( $raw, false, $reason, 0 );
			return array( false, $reason );
		}
		$id   = (int) url_to_postid( home_url( $c['post'] ) );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			self::log( $c, false, 'post_not_found', $id );
			return array( false, 'post_not_found' );
		}
		if ( 'post_meta' === $c['kind'] ) {
			if ( 'aioseo' === self::seo_name() ) {
				self::log( $c, false, 'seo_plugin_unsupported', $id );
				return array( false, 'seo_plugin_unsupported' );
			}
			if ( '' !== $c['title'] ) {
				update_post_meta( $id, self::title_key(), $c['title'] );
			}
			if ( '' !== $c['description'] ) {
				IRNoti_SEO::set_meta( $id, 'desc', $c['description'] );
			}
		} else {
			update_post_meta( $id, self::LD, wp_slash( self::encode_ld( (array) json_decode( $c['json'], true ) ) ) );
		}
		self::log( $c, true, 'ok', $id );
		return array( true, 'ok' );
	}

	/** Where the title is kept: the SEO plugin's own field when one is active, else ours. */
	public static function title_key(): string {
		switch ( IRNoti_SEO::store() ) {
			case 'yoast':
				return '_yoast_wpseo_title';
			case 'rankmath':
				return 'rank_math_title';
		}
		return self::TITLE;
	}

	/** The document title, only when no SEO plugin owns it. */
	public static function filter_title( $title ) {
		if ( 'own' !== IRNoti_SEO::store() || ! is_singular() ) {
			return $title;
		}
		$t = trim( (string) get_post_meta( (int) get_queried_object_id(), self::TITLE, true ) );
		return '' !== $t ? $t : $title;
	}

	/** Prints the approved JSON-LD (stored already escaped by encode_ld(), so it cannot close the script tag). */
	public static function print_jsonld(): void {
		if ( ! is_singular() ) {
			return;
		}
		$j = (string) get_post_meta( (int) get_queried_object_id(), self::LD, true );
		if ( '' !== $j && false === stripos( $j, '</' ) ) {
			echo '<script type="application/ld+json">' . $j . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- built by encode_ld() with JSON_HEX_TAG.
		}
	}
}
