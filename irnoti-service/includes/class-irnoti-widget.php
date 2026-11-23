<?php
defined( 'ABSPATH' ) || exit;

/**
 * IRNoti site widget: embeds the widget script on the storefront.
 * The admin pastes the embed code from the IRNoti panel (or a script URL + key); we validate it
 * (https only, data-* attributes only) and re-render the tag ourselves, so arbitrary HTML never reaches the page.
 */
class IRNoti_Widget {
	private static bool $printed = false;

	public static function init(): void {
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 99 );
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
		if ( ! preg_match( '#^https://[^\s"\'<>]+$#i', $src ) || ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
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

	/** Final attribute map: stored data-* attrs, plus the optional key and position fields. */
	public static function attrs(): array {
		$attrs = (array) IRNoti_Settings::get( 'widget_attrs' );
		$key   = trim( (string) IRNoti_Settings::get( 'widget_key' ) );
		if ( '' !== $key ) {
			$attrs['data-key'] = $key;
		}
		$pos = (string) IRNoti_Settings::get( 'widget_position' );
		if ( in_array( $pos, array( 'right', 'left' ), true ) ) {
			$attrs['data-position'] = $pos;
		}
		return $attrs;
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

	public static function tag(): string {
		$src   = (string) IRNoti_Settings::get( 'widget_src' );
		$delay = max( 0, min( 60, (int) IRNoti_Settings::get( 'widget_delay' ) ) );
		$attrs = self::attrs();
		if ( $delay > 0 ) {
			return '<script>(function(){setTimeout(function(){var s=document.createElement("script");s.async=true;s.src=' . wp_json_encode( $src ) . ';var a=' . wp_json_encode( (object) $attrs ) . ';for(var k in a){s.setAttribute(k,a[k]);}document.body.appendChild(s);},' . ( $delay * 1000 ) . ');})();</script>' . "\n";
		}
		$html = '';
		foreach ( $attrs as $k => $v ) {
			$html .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		return '<script async src="' . esc_url( $src, array( 'https' ) ) . '"' . $html . '></script>' . "\n";
	}

	public static function footer(): void {
		if ( self::$printed || ! self::visible() ) {
			return;
		}
		self::$printed = true;
		echo self::tag(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in tag().
	}

	public static function shortcode(): string {
		if ( self::$printed || ! self::enabled() ) {
			return '';
		}
		self::$printed = true;
		return self::tag();
	}
}
