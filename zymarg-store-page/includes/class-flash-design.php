<?php
/**
 * ZYMARG Store Page -- self-contained custom-design engine for the Flash Sale page.
 *
 * WHAT THIS IS
 * ------------
 * The machinery that lets a marketplace owner paste a complete HTML file into
 * an admin box and have it render as the Flash Sale hero, without that file's
 * CSS escaping and wrecking the header, the footer or the product grid below.
 *
 * It translates a standalone document into an embeddable section:
 *
 *   1. the stylesheet is pulled out before the markup is touched, so CSS text
 *      is never mistaken for a tag or a placeholder;
 *   2. the document wrapper (<html>, <head>, <body>, <title>, DOCTYPE) is
 *      dropped, while <link rel="stylesheet"> is kept -- browsers accept those
 *      in the body and dropping them would silently break a design that loads
 *      its font that way;
 *   3. {{placeholders}} are filled from live page data;
 *   4. every selector in the stylesheet is rewritten so it can only match
 *      inside this section, and the handful of properties that would still
 *      reach out and break the page are removed.
 *
 * WHY THIS PLUGIN HAS ITS OWN COPY
 * --------------------------------
 * Deliberate, and not an oversight. The Flash Sale page must keep working
 * exactly the same whether or not any other ZYMARG plugin is installed or
 * active, so this file references nothing outside itself. A guarded call into
 * another plugin would mean the paste box quietly degraded the moment that
 * plugin was deactivated -- which is precisely the sort of failure an admin
 * cannot diagnose from the storefront.
 *
 * SECURITY POSTURE
 * ----------------
 * The content handled here is authored by a user who holds manage_options, and
 * is stored verbatim (see self::RAW_KEYS). Running it through a tag stripper on
 * the way in would destroy the design, so it is instead *confined* on the way
 * out. Scripts are permitted by default because handed-over designs are
 * routinely interactive; a site that wants them gone can filter
 * zymarg_sp_flash_allow_scripts to false.
 *
 * @package ZYMARG_Store_Page
 * @since   1.20.0
 */

defined( 'ABSPATH' ) || exit;

class ZYMARG_SP_Flash_Design {

	/**
	 * Setting keys whose value is stored exactly as typed.
	 *
	 * Anything holding markup or CSS MUST be listed here. A key that is missing
	 * gets sanitize_text_field() applied by the settings sanitiser, which strips
	 * every tag -- the design is destroyed on save and the box appears to do
	 * nothing at all, with no error to explain why.
	 *
	 * @since 1.20.0
	 * @var string[]
	 */
	const RAW_KEYS = array( 'custom_html', 'custom_css', 'header_html' );

	/**
	 * Properties dropped when they appear on html, body or :root.
	 *
	 * These are the ones that reach outside the section. `body { overflow:hidden;
	 * height:100dvh }` is the single most damaging line in a standalone file:
	 * retargeted onto the section wrapper it pins the hero to the viewport and
	 * clips everything inside it. Colour, background and font on those same
	 * selectors are harmless once retargeted, and are kept.
	 *
	 * @since 1.20.0
	 * @var string[]
	 */
	private const UNSAFE_ROOT_PROPS = array(
		'overflow', 'overflow-x', 'overflow-y',
		'height', 'min-height', 'max-height',
		'width', 'min-width', 'max-width',
		'position', 'display', 'margin', 'padding',
		'justify-content', 'align-items', 'inset',
		'top', 'right', 'bottom', 'left',
	);

	/**
	 * Should this section render the author's design instead of the built-in one?
	 *
	 * An empty code box counts as inactive, so flipping the dropdown to "custom"
	 * before pasting anything cannot blank out the hero.
	 *
	 * @since 1.20.0
	 * @param array $settings Saved hero settings.
	 * @return bool
	 */
	public static function is_active( array $settings ) {
		if ( 'custom' !== ( $settings['design_source'] ?? 'plugin' ) ) {
			return false;
		}

		return '' !== trim( (string) ( $settings['custom_html'] ?? '' ) );
	}

	/**
	 * Has the admin hidden the built-in heading block?
	 *
	 * Hides the heading only. The rest of the hero still renders, which is what
	 * you want when a custom header already carries its own title.
	 *
	 * @since 1.20.0
	 * @param array $settings Saved hero settings.
	 * @return bool
	 */
	public static function header_hidden( array $settings ) {
		return ! empty( $settings['hide_header'] );
	}

	/**
	 * Render the author's own design for the hero.
	 *
	 * @since 1.20.0
	 * @param array  $settings Saved hero settings.
	 * @param array  $vars     Scalar placeholders, e.g. [ 'title' => '...' ].
	 * @param array  $loops    Repeating placeholders, e.g. [ 'slides' => [ ... ] ].
	 * @param string $slug     Scope suffix, so two custom blocks cannot collide.
	 * @return string Section HTML, or '' when there is nothing to render.
	 */
	public static function render( array $settings, array $vars = array(), array $loops = array(), $slug = 'flash-hero' ) {
		$code = (string) ( $settings['custom_html'] ?? '' );

		if ( '' === trim( $code ) ) {
			return '';
		}

		$scope = 'zfs-custom--' . sanitize_html_class( $slug );

		// CSS out first, before the markup is touched at all.
		$css  = self::extract_css( $code );
		$html = self::extract_markup( $code );

		// The separate "Extra CSS" box is appended last so it wins any conflict
		// with the stylesheet that arrived inside the pasted document.
		$extra = trim( (string) ( $settings['custom_css'] ?? '' ) );
		if ( '' !== $extra ) {
			$css .= "\n" . $extra;
		}

		/**
		 * Allow or forbid <script> inside a pasted Flash Sale design.
		 *
		 * True by default: handed-over hero designs are commonly interactive,
		 * and stripping their behaviour would look like a broken design rather
		 * than a policy decision.
		 *
		 * @since 1.20.0
		 * @param bool   $allow Whether scripts survive.
		 * @param string $slug  Scope suffix being rendered.
		 */
		if ( ! apply_filters( 'zymarg_sp_flash_allow_scripts', true, $slug ) ) {
			$html = self::strip_scripts( $html );
		}

		$html = self::fill_loops( $html, $loops );
		$html = self::fill_vars( $html, $vars );
		$html = self::strip_unused_placeholders( $html );

		$css = self::scope_css( $css, $scope );

		$out = '<section class="zfs-custom ' . esc_attr( $scope ) . '"'
			. ' data-zfs-custom="' . esc_attr( $slug ) . '">';

		if ( '' !== trim( $css ) ) {
			/*
			 * Printed inline rather than enqueued. This CSS is per section, it
			 * changes whenever the author edits the box, and it has no stable
			 * file on disk to point wp_enqueue_style() at.
			 */
			$out .= '<style>' . $css . '</style>';
		}

		$out .= $html . '</section>';

		/**
		 * Filter the finished custom Flash Sale section markup.
		 *
		 * @since 1.20.0
		 * @param string $out      Rendered HTML.
		 * @param string $slug     Scope suffix.
		 * @param array  $vars     Placeholders that were available.
		 * @param array  $settings Saved hero settings.
		 */
		return (string) apply_filters( 'zymarg_sp_flash_custom_html', $out, $slug, $vars, $settings );
	}

	/**
	 * Make a small fragment of admin-authored markup safe to place inside the page.
	 *
	 * Used for the "Custom Header HTML" box, which replaces only the heading
	 * block. Runs the same pipeline as a full custom design -- wrapper dropped,
	 * scripts removed, CSS confined -- because a pasted preview file complete
	 * with a `body { display:flex }` rule would otherwise take the page apart.
	 *
	 * Scripts are always stripped here, unlike render(). A heading is not an
	 * interactive component, so there is nothing to lose and a smaller surface
	 * to defend.
	 *
	 * @since 1.20.0
	 * @param string $html Raw markup as the administrator typed it.
	 * @param string $slug Scope suffix.
	 * @return string Safe markup, ready to echo.
	 */
	public static function prepare_header( $html, $slug = 'flash-hero' ) {
		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return '';
		}

		$scope = 'zfs-header--' . sanitize_html_class( $slug );

		$css    = self::scope_css( self::extract_css( $html ), $scope );
		$markup = self::strip_scripts( self::extract_markup( $html ) );

		$out = '<div class="zfs-custom-header ' . esc_attr( $scope ) . '">';

		if ( '' !== trim( $css ) ) {
			$out .= '<style>' . $css . '</style>';
		}

		return $out . $markup . '</div>';
	}

	/**
	 * Scoped <style> for the Extra CSS box when the built-in design is in use.
	 *
	 * Without this the Extra CSS box would be dead unless the admin had also
	 * pasted a whole document, because the only code that reads it lives in
	 * render(), which bails on an empty custom_html. An admin who just wants to
	 * nudge one colour on an otherwise stock hero would be typing into a box
	 * that silently threw their CSS away.
	 *
	 * Returns nothing when the custom design IS active, since render() already
	 * appends the same CSS there and emitting it twice would double every rule.
	 *
	 * @since 1.20.0
	 * @param array  $settings Saved hero settings.
	 * @param string $slug     Scope suffix.
	 * @return string A <style> block, or '' when there is nothing to add.
	 */
	public static function standalone_css( array $settings, $slug = 'flash-hero' ) {
		if ( self::is_active( $settings ) ) {
			return '';
		}

		$extra = trim( (string) ( $settings['custom_css'] ?? '' ) );

		if ( '' === $extra ) {
			return '';
		}

		/*
		 * Scoped to the section wrapper the built-in hero actually renders,
		 * not to the custom wrapper, which does not exist on this path.
		 */
		$scoped = self::scope_css( $extra, 'zfs__head' );

		return '' === trim( $scoped ) ? '' : '<style>' . $scoped . '</style>';
	}

	/* ---------------------------------------------------------------------
	 * Document unwrapping
	 * ------------------------------------------------------------------ */

	/**
	 * Collect every stylesheet in the document, in source order.
	 *
	 * @since 1.20.0
	 * @param string $code Raw pasted document.
	 * @return string Concatenated CSS.
	 */
	private static function extract_css( $code ) {
		if ( ! preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', (string) $code, $matches ) ) {
			return '';
		}

		return implode( "\n", $matches[1] );
	}

	/**
	 * Reduce a standalone document to the markup that belongs inside a page.
	 *
	 * @since 1.20.0
	 * @param string $code Raw pasted document.
	 * @return string Markup safe to place inside a page.
	 */
	private static function extract_markup( $code ) {
		$code  = (string) $code;
		$links = '';

		/*
		 * Keep stylesheet and preconnect links from the head. A design that
		 * pulls its typeface in this way would otherwise render in the wrong
		 * font with nothing to indicate why.
		 */
		if ( preg_match( '#<head\b[^>]*>(.*?)</head>#is', $code, $head )
			&& preg_match_all( '#<link\b[^>]*>#i', $head[1], $found )
		) {
			foreach ( $found[0] as $tag ) {
				if ( false !== stripos( $tag, 'stylesheet' ) || false !== stripos( $tag, 'preconnect' ) ) {
					$links .= $tag;
				}
			}
		}

		// Prefer the body contents when the document has one.
		if ( preg_match( '#<body\b[^>]*>(.*)</body>#is', $code, $body ) ) {
			$code = $body[1];
		}

		// Elements whose content must go, not merely their tags.
		$code = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $code );
		$code = preg_replace( '#<title\b[^>]*>.*?</title>#is', '', (string) $code );
		$code = preg_replace( '#<!DOCTYPE[^>]*>#i', '', (string) $code );
		$code = preg_replace( '#<!--\[if.*?<!\[endif\]-->#is', '', (string) $code );

		// Remaining document-level tags carry no meaning inside a page.
		$code = preg_replace( '#</?(?:html|head|body|meta|base|title)\b[^>]*>#i', '', (string) $code );

		return $links . trim( (string) $code );
	}

	/**
	 * Remove script elements and inline event handlers.
	 *
	 * @since 1.20.0
	 * @param string $html Markup.
	 * @return string
	 */
	private static function strip_scripts( $html ) {
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
		$html = preg_replace( '#<script\b[^>]*/?>#i', '', (string) $html );
		$html = preg_replace( '#\son[a-z]+\s*=\s*"[^"]*"#i', '', (string) $html );
		$html = preg_replace( "#\\son[a-z]+\\s*=\\s*'[^']*'#i", '', (string) $html );

		return (string) $html;
	}

	/* ---------------------------------------------------------------------
	 * Placeholders
	 * ------------------------------------------------------------------ */

	/**
	 * Expand repeating blocks: {{#slides}} ... {{/slides}}.
	 *
	 * The block body is emitted once per row, with that row's keys filled in.
	 *
	 * @since 1.20.0
	 * @param string $html  Markup.
	 * @param array  $loops Map of loop name => list of associative rows.
	 * @return string
	 */
	private static function fill_loops( $html, array $loops ) {
		foreach ( $loops as $name => $rows ) {
			$quoted  = preg_quote( (string) $name, '#' );
			$pattern = '#\{\{\#' . $quoted . '\}\}(.*?)\{\{/' . $quoted . '\}\}#is';

			$html = (string) preg_replace_callback(
				$pattern,
				static function ( array $m ) use ( $rows ) {
					if ( ! is_array( $rows ) || empty( $rows ) ) {
						return '';
					}

					$out = '';
					foreach ( $rows as $row ) {
						if ( is_array( $row ) ) {
							$out .= self::fill_vars( $m[1], $row );
						}
					}

					return $out;
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * Replace {{key}} placeholders with escaped values.
	 *
	 * Escaping is chosen by key name: anything that reads as a link or an image
	 * path goes through esc_url(), everything else through esc_attr().
	 * esc_attr() rather than esc_html() because a placeholder is as likely to
	 * sit inside an attribute as in text, and it is correct in both positions.
	 *
	 * @since 1.20.0
	 * @param string $html Markup.
	 * @param array  $vars Map of placeholder name => value.
	 * @return string
	 */
	private static function fill_vars( $html, array $vars ) {
		foreach ( $vars as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$key = (string) $key;

			$safe = ( 'url' === $key || preg_match( '#(?:^|_)(?:url|link|permalink|src)$#', $key ) )
				? esc_url( (string) $value )
				: esc_attr( (string) $value );

			$html = str_replace( '{{' . $key . '}}', $safe, $html );
		}

		return $html;
	}

	/**
	 * Remove placeholders that had no matching value.
	 *
	 * Without this a typo such as {{titel}} would be printed literally on the
	 * storefront. Loop tags are cleared too, in case a loop name was misspelled
	 * and therefore never expanded.
	 *
	 * @since 1.20.0
	 * @param string $html Markup.
	 * @return string
	 */
	private static function strip_unused_placeholders( $html ) {
		/*
		 * The `#` inside the character class MUST stay escaped: `#` is also the
		 * pattern delimiter, and PHP ends the pattern at the first unescaped one
		 * even inside a [class]. Unescaped, this becomes an "Unknown modifier"
		 * warning, preg_replace() returns null, and casting null to string
		 * blanks the whole section.
		 */
		$stripped = preg_replace( '#\{\{[\#/]?[a-z0-9_\-]*\}\}#i', '', (string) $html );

		/*
		 * Never let a regex failure destroy the author's design. Returning the
		 * unstripped markup shows a stray {{placeholder}}, which is a far better
		 * outcome than an empty hero that gives no clue what went wrong.
		 */
		return null === $stripped ? (string) $html : (string) $stripped;
	}

	/* ---------------------------------------------------------------------
	 * CSS scoping
	 * ------------------------------------------------------------------ */

	/**
	 * Rewrite a stylesheet so nothing in it can affect the rest of the page.
	 *
	 * @since 1.20.0
	 * @param string $css   Author CSS.
	 * @param string $scope Wrapper class name, without the leading dot.
	 * @return string
	 */
	private static function scope_css( $css, $scope ) {
		$css = (string) $css;

		if ( '' === trim( $css ) ) {
			return '';
		}

		// Comments go first: a selector inside one would otherwise be scoped.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		return self::scope_block( $css, $scope );
	}

	/**
	 * Walk a stylesheet body, prefixing selectors and recursing into
	 * conditional at-rules.
	 *
	 * Written as a brace-counting walk rather than a regular expression because
	 * nested at-rules -- a @media holding rules that hold braces -- cannot be
	 * matched reliably with one.
	 *
	 * @since 1.20.0
	 * @param string $css   Stylesheet body.
	 * @param string $scope Wrapper class name.
	 * @return string
	 */
	private static function scope_block( $css, $scope ) {
		$out    = '';
		$buffer = '';
		$len    = strlen( $css );
		$i      = 0;

		while ( $i < $len ) {
			if ( '{' !== $css[ $i ] ) {
				$buffer .= $css[ $i ];
				$i++;
				continue;
			}

			// Capture this block's body by counting braces.
			$depth = 1;
			$body  = '';
			$j     = $i + 1;

			while ( $j < $len ) {
				if ( '{' === $css[ $j ] ) {
					$depth++;
				} elseif ( '}' === $css[ $j ] ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
				}
				$body .= $css[ $j ];
				$j++;
			}

			$selector = trim( $buffer );

			if ( '' !== $selector && '@' === $selector[0] ) {
				$lower = strtolower( $selector );

				if ( 0 === strpos( $lower, '@media' )
					|| 0 === strpos( $lower, '@supports' )
					|| 0 === strpos( $lower, '@container' )
					|| 0 === strpos( $lower, '@layer' )
				) {
					// Conditional wrapper: keep the condition, scope the inside.
					$out .= $selector . '{' . self::scope_block( $body, $scope ) . '}';
				} else {
					/*
					 * @keyframes, @font-face, @property. The body is not a list
					 * of selectors and prefixing it would break the rule.
					 */
					$out .= $selector . '{' . $body . '}';
				}
			} elseif ( '' !== $selector ) {
				$out .= self::scope_selector( $selector, $scope )
					. '{' . self::filter_declarations( $selector, $body ) . '}';
			}

			$buffer = '';
			$i      = $j + 1;
		}

		// Anything left is a statement without a block, such as @import.
		return $out . trim( $buffer );
	}

	/**
	 * Prefix a comma-separated selector list with the section wrapper.
	 *
	 * Document-level selectors are retargeted at the wrapper itself, which is
	 * what the author actually meant: inside their standalone file, `body` WAS
	 * the outermost box of the design.
	 *
	 * @since 1.20.0
	 * @param string $selector Selector list.
	 * @param string $scope    Wrapper class name.
	 * @return string
	 */
	private static function scope_selector( $selector, $scope ) {
		$root  = '.' . $scope;
		$parts = explode( ',', $selector );
		$done  = array();

		foreach ( $parts as $part ) {
			$sel = trim( (string) preg_replace( '#\s+#', ' ', $part ) );

			if ( '' === $sel ) {
				continue;
			}

			$lower = strtolower( $sel );

			if ( in_array( $lower, array( 'html', 'body', ':root', 'html body' ), true ) ) {
				// Custom properties and page-level paint belong on the wrapper.
				$done[] = $root;
				continue;
			}

			if ( '*' === $sel ) {
				// A universal reset is honoured, but only within the section.
				$done[] = $root;
				$done[] = $root . ' *';
				continue;
			}

			if ( preg_match( '#^(?:html|body)\b(.*)$#i', $sel, $m ) ) {
				$done[] = $root . $m[1];
				continue;
			}

			$done[] = $root . ' ' . $sel;
		}

		return implode( ',', $done );
	}

	/**
	 * Drop declarations that would escape the section via html, body or :root.
	 *
	 * @since 1.20.0
	 * @param string $selector Original selector list.
	 * @param string $body     Declaration block.
	 * @return string
	 */
	private static function filter_declarations( $selector, $body ) {
		if ( ! preg_match( '#(^|,)\s*(html|body|:root)\b#i', $selector ) ) {
			return $body;
		}

		$kept = array();

		foreach ( explode( ';', $body ) as $declaration ) {
			if ( '' === trim( $declaration ) ) {
				continue;
			}

			$property = strtolower( trim( (string) strtok( $declaration, ':' ) ) );

			// Custom properties are always safe: they do nothing until used.
			if ( 0 === strpos( $property, '--' ) ) {
				$kept[] = $declaration;
				continue;
			}

			if ( in_array( $property, self::UNSAFE_ROOT_PROPS, true ) ) {
				continue;
			}

			$kept[] = $declaration;
		}

		return implode( ';', $kept ) . ';';
	}
}
