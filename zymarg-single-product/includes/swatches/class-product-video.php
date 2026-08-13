<?php
/**
 * Product-level video support (native port of WSE_Product_Video).
 *
 * Lets a merchant attach ONE video per product (YouTube, Vimeo, or a
 * self-hosted MP4/WebM/OGG). Surfaced in the gallery as a "Watch video"
 * trigger that opens a lazy overlay player (see zymarg-sp.js).
 *
 * @version 1.0.2
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Product_Video {

	const META_KEY = '_zymarg_sp_product_video';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_admin_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_admin_field' ) );
	}

	public function render_admin_field(): void {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_KEY,
				'label'       => esc_html__( 'Product Video URL', 'zymarg-single-product' ),
				'placeholder' => 'https://www.youtube.com/watch?v=…',
				'desc_tip'    => true,
				'description' => esc_html__( 'Optional. YouTube, Vimeo, or a direct .mp4 / .webm / .ogg URL. The gallery shows a "Watch video" button that opens this in an overlay player.', 'zymarg-single-product' ),
				'type'        => 'url',
			)
		);
	}

	public function save_admin_field( $post_id ): void {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST[ self::META_KEY ] ) ? esc_url_raw( wp_unslash( (string) $_POST[ self::META_KEY ] ) ) : '';
		if ( '' === $raw ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}
		update_post_meta( $post_id, self::META_KEY, $raw );
	}

	/**
	 * Returns parsed video data for a product, or null.
	 *
	 * @return array{type:string,embed:string,raw:string}|null
	 */
	public static function get_product_video_data( int $product_id ): ?array {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return null;
		}
		$raw = (string) get_post_meta( $product_id, self::META_KEY, true );
		if ( '' === trim( $raw ) ) {
			return null;
		}
		return self::parse_video_url( $raw );
	}

	/**
	 * Classifies a video URL and returns the canonical embed source.
	 *
	 * @return array{type:string,embed:string,raw:string}|null
	 */
	public static function parse_video_url( string $url ): ?array {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}

		if ( preg_match( '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~i', $url, $m ) ) {
			return array(
				'type'  => 'youtube',
				'embed' => 'https://www.youtube-nocookie.com/embed/' . $m[1],
				'raw'   => $url,
			);
		}

		if ( preg_match( '~vimeo\.com/(?:video/)?(\d+)~i', $url, $m ) ) {
			return array(
				'type'  => 'vimeo',
				'embed' => 'https://player.vimeo.com/video/' . $m[1],
				'raw'   => $url,
			);
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '~\.(mp4|webm|ogg|ogv)$~i', $path ) ) {
			return array(
				'type'  => 'mp4',
				'embed' => $url,
				'raw'   => $url,
			);
		}

		return null;
	}
}
