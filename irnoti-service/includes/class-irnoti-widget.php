<?php
defined( 'ABSPATH' ) || exit;

/**
 * IRNoti site widget: embeds the widget script on the storefront.
 * The admin pastes the embed code from the IRNoti panel (or a script URL + key); we validate it
 * (https only, data-* attributes only) and re-render the tag ourselves, so arbitrary HTML never reaches the page.
 */
class IRNoti_Widget {
	/** Official embed script of IRNoti's support/AI-agent widget; the project code goes in data-agent. */
	const DEFAULT_SRC = 'https://api.irnoti.com/v1/embed/widget.js';

	const HANDLE = 'irnoti-widget';

	private static bool $printed = false;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_shortcode( 'irnoti_widget', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Pure parser (no WordPress calls, unit-tested).
	 * Accepts a full <script …></script> snippet, or a bare https URL.
	 *
	 * @return array{src:string,attrs:array<string,string>}
	 */
	public static function parse_embed( string $raw ): array {
		$raw = trim( $raw );
		$out = array( 'src' => '', 'attrs' => array() );
		if ( '' === $raw ) {
			return $out;
		}
		$src = '';
		if ( preg_match( '/<script\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/is', $raw, $m ) ) {
			$src = $m[2];
		} elseif ( preg_match( '#^https://\S+$#i', $raw ) ) {
			$src = $raw;
		}
		$src = trim( html_entity_decode( $src ) );
		if ( ! preg_match( '#^https://[^\s"\'<>]+$#i', $src ) || ! filter_var( $src, FILTER_VALIDATE_URL ) || ! self::allowed_src( $src ) ) {
			return $out;
		}
		$out['src'] = $src;
		if ( preg_match( '/<script\b([^>]*)>/is', $raw, $tag ) && preg_match_all( '/\b(data-[a-z0-9-]{1,40})\s*=\s*(["\'])(.*?)\2/is', $tag[1], $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $a ) {
				$out['attrs'][ strtolower( $a[1] ) ] = substr( html_entity_decode( $a[3] ), 0, 200 );
			}
		}
		return $out;
	}

	public static function enabled(): bool {
		return (bool) IRNoti_Settings::get( 'widget_enabled' ) && '' !== (string) IRNoti_Settings::get( 'widget_src' );
	}

	/** Only IRNoti's own hosts may serve the widget script. */
	public static function allowed_src( string $url ): bool {
		$host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, also unit-tested outside WordPress.
		return is_string( $host ) && (bool) preg_match( '/(^|\\.)irnoti\\.com$/i', $host );
	}

	/** Final attribute map: stored data-* attrs plus the project code as data-agent (look and position are set in the IRNoti agent studio). */
	public static function attrs(): array {
		$attrs = (array) IRNoti_Settings::get( 'widget_attrs' );
		$key   = trim( (string) IRNoti_Settings::get( 'widget_key' ) );
		if ( '' !== $key ) {
			$attrs['data-agent'] = $key;
		}
		$src               = (string) IRNoti_Settings::get( 'widget_src' );
		$attrs['data-api'] = self::api_base( self::allowed_src( $src ) ? $src : self::DEFAULT_SRC );
		return $attrs;
	}

	/**
	 * API base the widget calls, from the script URL. Sent as data-api so the widget still works when a
	 * speed/CDN plugin (Zhaket Booster, WP Rocket…) serves a copy of widget.js from the site's own host.
	 */
	public static function api_base( string $src ): string {
		return (string) preg_replace( '#/embed/widget\.js.*$#', '', $src );
	}

	/** Should the widget show on the current request? */
	public static function visible(): bool {
		if ( ! self::enabled() || is_admin() || is_feed() ) {
			return false;
		}
		if ( IRNoti_Settings::get( 'widget_hide_admin' ) && current_user_can( 'manage_options' ) ) {
			return false;
		}
		$ids = array_filter( array_map( 'absint', preg_split( '/[\s,،]+/', (string) IRNoti_Settings::get( 'widget_hide_ids' ) ) ?: array() ) );
		if ( $ids && is_singular() && in_array( (int) get_queried_object_id(), $ids, true ) ) {
			return false;
		}
		switch ( (string) IRNoti_Settings::get( 'widget_scope' ) ) {
			case 'home':
				return is_front_page() || is_home();
			case 'woo':
				return function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() );
		}
		return true;
	}

	/** Enqueues the widget script (footer, async) with its data-* attributes; with a delay it is injected by a tiny inline loader. */
	public static function enqueue(): void {
		if ( self::$printed ) {
			return;
		}
		self::$printed = true;
		$src   = self::allowed_src( (string) IRNoti_Settings::get( 'widget_src' ) ) ? (string) IRNoti_Settings::get( 'widget_src' ) : self::DEFAULT_SRC;
		$attrs = self::attrs();
		$delay = max( 0, min( 60, (int) IRNoti_Settings::get( 'widget_delay' ) ) );
		if ( $delay > 0 ) {
			wp_register_script( self::HANDLE, false, array(), IRNOTI_VERSION, true );
			wp_enqueue_script( self::HANDLE );
			wp_add_inline_script( self::HANDLE, '(function(){setTimeout(function(){var s=document.createElement("script");s.async=true;s.src=' . wp_json_encode( $src ) . ';var a=' . wp_json_encode( (object) $attrs ) . ';for(var k in a){s.setAttribute(k,a[k]);}document.body.appendChild(s);},' . ( $delay * 1000 ) . ');})();' );
			return;
		}
		wp_enqueue_script( self::HANDLE, $src, array(), null, array( 'in_footer' => true, 'strategy' => 'async' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- remote script, versioned by IRNoti.
		add_filter( 'script_loader_tag', array( __CLASS__, 'tag_attrs' ), 10, 2 );
	}

	/** Adds the data-* attributes to the enqueued widget tag. */
	public static function tag_attrs( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		$html = '';
		foreach ( self::attrs() as $k => $v ) {
			$html .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		return str_replace( ' src=', $html . ' src=', $tag );
	}

	public static function maybe_enqueue(): void {
		if ( self::visible() ) {
			self::enqueue();
		}
	}

	public static function shortcode(): string {
		if ( self::enabled() ) {
			self::enqueue();
		}
		return '';
	}
}
