<?php
defined( 'ABSPATH' ) || exit;

/** Mirrors WooCommerce products/categories into the IRNoti shop bot (`/botbuilder/bots/:id/shop/*`). */
class IRNoti_Bot_Sync {
	const META_PRODUCT  = '_irnoti_remote_id';
	const META_CATEGORY = '_irnoti_remote_cat_id';
	const CRON          = 'irnoti_sync_product';

	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) && ! did_action( 'woocommerce_loaded' ) ) {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'hooks' ) );
			return;
		}
		self::hooks();
	}

	public static function hooks(): void {
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'queue_stock' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'queue_stock' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_trash' ) );
		add_action( self::CRON, array( __CLASS__, 'sync_product' ) );
	}

	public static function enabled(): bool {
		return IRNoti_Settings::get( 'bot_auto_sync' ) && '' !== (string) IRNoti_Settings::get( 'bot_id' ) && IRNoti_API::configured();
	}

	/** Debounced via WP-Cron so a save in the editor is never blocked by the remote call. */
	public static function queue( $product_id ): void {
		if ( ! self::enabled() || wp_next_scheduled( self::CRON, array( (int) $product_id ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + 5, self::CRON, array( (int) $product_id ) );
	}

	/** Any stock movement (web order, refund, manual edit, bot order) is pushed so the bot never sells what Woo no longer has. */
	public static function queue_stock( $product ): void {
		if ( $product instanceof WC_Product ) {
			self::queue( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
		}
	}

	public static function on_trash( $post_id ): void {
		if ( 'product' !== get_post_type( $post_id ) || ! self::enabled() ) {
			return;
		}
		$remote = get_post_meta( $post_id, self::META_PRODUCT, true );
		if ( $remote ) {
			IRNoti_API::delete( self::bot_path( 'shop/products/' . rawurlencode( (string) $remote ) ) );
			delete_post_meta( $post_id, self::META_PRODUCT );
		}
	}

	/** Prices are integer Rials server-side; Woo may be in Toman (IRT) or Rial (IRR). Anything else cannot be mapped safely. */
	public static function to_rial( $amount ): ?string {
		$cur = get_woocommerce_currency();
		if ( 'IRR' !== $cur && 'IRT' !== $cur && 'IRHT' !== $cur ) {
			return null;
		}
		$mult = 'IRR' === $cur ? 1 : ( 'IRHT' === $cur ? 10000 : 10 );
		return (string) ( (int) round( (float) $amount * $mult ) );
	}

	public static function sync_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! self::enabled() ) {
			return new WP_Error( 'irnoti_skip', 'skip' );
		}
		if ( 'trash' === $product->get_status() ) {
			self::on_trash( $product_id );
			return true;
		}
		if ( $product->is_type( 'variable' ) ) {
			$price = $product->get_variation_price( 'min' );
		} else {
			$price = $product->get_price();
		}
		$rial = self::to_rial( $price );
		if ( null === $rial || (int) $rial <= 0 ) {
			return new WP_Error( 'irnoti_price', __( 'قیمت یا واحد پول محصول قابل تبدیل به ریال نیست.', 'irnoti-service' ) );
		}
		$body = array(
			'title'  => mb_substr( $product->get_name(), 0, 120 ),
			'price'  => $rial,
			'active' => 'publish' === $product->get_status() && $product->is_in_stock(),
			'stock'  => $product->managing_stock() ? max( 0, (int) $product->get_stock_quantity() ) : null,
		);
		$desc = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
		if ( '' !== $desc ) {
			$body['description'] = mb_substr( $desc, 0, 700 );
		}
		if ( $product->is_on_sale() && $product->get_regular_price() ) {
			$old = self::to_rial( $product->get_regular_price() );
			if ( $old && (int) $old > (int) $rial ) {
				$body['oldPrice'] = $old;
			}
		}
		$img = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
		if ( $img && 0 === strpos( $img, 'https://' ) ) {
			$body['imageUrl'] = $img;
		}
		$body['featured'] = $product->is_featured();
		$cat_id           = self::ensure_category( $product );
		if ( $cat_id ) {
			$body['categoryId'] = $cat_id;
		}
		$remote = get_post_meta( $product_id, self::META_PRODUCT, true );
		if ( $remote ) {
			$body['id'] = (string) $remote;
		}
		$res = IRNoti_API::put( self::bot_path( 'shop/products' ), $body );
		if ( is_wp_error( $res ) ) {
			update_post_meta( $product_id, '_irnoti_sync_error', $res->get_error_message() );
			return $res;
		}
		delete_post_meta( $product_id, '_irnoti_sync_error' );
		if ( isset( $res['id'] ) ) {
			update_post_meta( $product_id, self::META_PRODUCT, (string) $res['id'] );
		}
		return true;
	}

	private static function ensure_category( WC_Product $product ): ?string {
		$ids = $product->get_category_ids();
		if ( ! $ids ) {
			return null;
		}
		$term = get_term( (int) $ids[0], 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		$remote = get_term_meta( $term->term_id, self::META_CATEGORY, true );
		$body   = array( 'title' => mb_substr( $term->name, 0, 80 ) );
		if ( $remote ) {
			return (string) $remote; // Renames are pushed by sync_all only; avoids a write per product save.
		}
		$res = IRNoti_API::put( self::bot_path( 'shop/categories' ), $body );
		if ( ! is_wp_error( $res ) && isset( $res['id'] ) ) {
			update_term_meta( $term->term_id, self::META_CATEGORY, (string) $res['id'] );
			return (string) $res['id'];
		}
		return null;
	}

	/** One page of products; the admin screen loops until `done`. */
	public static function sync_batch( int $page, int $per_page = 10 ): array {
		$ids  = wc_get_products( array( 'limit' => $per_page, 'page' => $page, 'status' => array( 'publish', 'draft', 'private' ), 'return' => 'ids' ) );
		$errs = array();
		foreach ( $ids as $id ) {
			$r = self::sync_product( $id );
			if ( is_wp_error( $r ) ) {
				$errs[] = array( 'id' => $id, 'title' => get_the_title( $id ), 'error' => $r->get_error_message() );
			}
		}
		return array( 'count' => count( $ids ), 'done' => count( $ids ) < $per_page, 'errors' => $errs );
	}

	private static function bot_path( string $suffix ): string {
		return 'botbuilder/bots/' . rawurlencode( (string) IRNoti_Settings::get( 'bot_id' ) ) . '/' . $suffix;
	}

	public static function bots() {
		return IRNoti_API::get( 'botbuilder/bots' );
	}
}
