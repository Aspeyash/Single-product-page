<?php
/**
 * Embedded Reviews — Icons (re-namespaced from ZymargReviews to ZymargReviewsEngine).
 * Exact copy of class-icons.php from ZYMARG Reviews v1.1.2.
 *
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Icons {

	private static function path_star_filled() {
		return '<path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.81 8.63 2 9.24l5.46 4.73L5.82 21z"/>';
	}

	private static function path_star_outline() {
		return '<path d="M22 9.24l-7.19-.62L12 2 9.19 8.62 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z"/>';
	}

	public static function star_filled( $extra_class = '' ) {
		return sprintf(
			'<span class="zymarg-star is-filled %s" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false">%s</svg></span>',
			esc_attr( $extra_class ),
			self::path_star_filled()
		);
	}

	public static function star_empty( $extra_class = '' ) {
		return sprintf(
			'<span class="zymarg-star is-empty %s" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false">%s</svg></span>',
			esc_attr( $extra_class ),
			self::path_star_outline()
		);
	}

	public static function star_partial( $pct ) {
		$pct  = max( 0, min( 100, (int) $pct ) );
		$clip = 100 - $pct;
		$out  = '<span class="zymarg-star is-partial" aria-hidden="true">';
		$out .= '<span class="zymarg-star-back"><svg viewBox="0 0 24 24" focusable="false">' . self::path_star_outline() . '</svg></span>';
		$out .= '<span class="zymarg-star-front" style="clip-path: inset(0 ' . esc_attr( $clip ) . '% 0 0);"><svg viewBox="0 0 24 24" focusable="false">' . self::path_star_filled() . '</svg></span>';
		$out .= '</span>';
		return $out;
	}

	public static function star_input( $value, $aria_label = '' ) {
		$svg = '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">'
			. '<g class="zymarg-path-fill">' . self::path_star_filled() . '</g>'
			. '<g class="zymarg-path-out">' . self::path_star_outline() . '</g>'
			. '</svg>';
		return sprintf(
			'<button type="button" class="zymarg-star is-empty zymarg-rate-star" data-value="%d" aria-label="%s">%s</button>',
			(int) $value,
			esc_attr( $aria_label ),
			$svg
		);
	}

	public static function verified() {
		return '<svg class="zymarg-icon zymarg-icon-verified" viewBox="0 0 24 24" focusable="false" aria-hidden="true">'
			. '<path d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82L8.6 22.5l3.4-1.47 3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12zm-12.91 4.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48-7.33 7.35z"/>'
			. '</svg>';
	}

	public static function photo_library() {
		return '<svg class="zymarg-icon" viewBox="0 0 24 24" focusable="false" aria-hidden="true">'
			. '<path d="M20 4v12H8V4h12m0-2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-4.5 8.21l-2.25 3.01L11.5 13l-3 4h11l-3.5-4.79zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6z"/>'
			. '</svg>';
	}

	public static function add_photo() {
		return '<svg class="zymarg-icon" viewBox="0 0 24 24" focusable="false" aria-hidden="true">'
			. '<path d="M21 5v6.59l-2.29-2.3a.996.996 0 00-1.41 0L14 12.59 9.71 8.29a.996.996 0 00-1.41 0L3 13.59V5h18M5 19l4-4 1.79 1.79c.78.78 2.05.78 2.83 0L17 13l4 4v.01c0 1.09-.9 1.99-1.99 1.99H5z"/>'
			. '<path d="M15 4h2v2h2v2h-2v2h-2V8h-2V6h2z" />'
			. '</svg>';
	}

	public static function chevron_down() {
		return '<svg class="zymarg-icon" viewBox="0 0 24 24" focusable="false" aria-hidden="true">'
			. '<path d="M16.59 8.59L12 13.17 7.41 8.59 6 10l6 6 6-6z"/>'
			. '</svg>';
	}
}
