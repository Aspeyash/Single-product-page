<?php
/**
 * ZYMARG Store Page -- Flash Sale hero: settings registry, storage and render.
 *
 * WHAT CHANGED IN 1.20.0
 * ----------------------
 * The Flash Sale hero used to be three hard-coded strings and one hard-coded
 * gradient living in templates/flash-sale.php and assets/css/flash-sale.css.
 * An administrator could not change the eyebrow text, let alone the colours,
 * without editing PHP. This file replaces that with a control surface: content
 * fields, a declarative registry of design controls, a multi-slide repeater and
 * a paste-your-own-HTML escape hatch.
 *
 * THE ONE INVARIANT THAT MATTERS MOST
 * -----------------------------------
 * A control sitting at its default emits NO CSS AT ALL, and an install with
 * nothing configured renders the same markup it rendered in 1.19.3. The
 * stylesheet stays the single source of truth for the shipped design; these
 * settings only override it, and only where they were actually touched. That is
 * what makes this safe to ship to a live storefront: upgrading changes nothing
 * until somebody deliberately changes something.
 *
 * HOW A SETTING BECOMES A PIXEL
 * -----------------------------
 *   self::CONTROLS   declares key, CSS variable, mode and default
 *        |
 *   render_fields()  builds the admin input from that declaration
 *        |
 *   sanitize()       clamps and type-checks it on the way into the option
 *        |
 *   declarations()   emits "--zfs-head-scale:0.5;" but only if it differs
 *        |           from the default
 *   inline_css()     wraps that in :root{...} via wp_add_inline_style
 *        |
 *   flash-sale.css   does the arithmetic: calc( token * var(--zfs-head-scale) )
 *
 * Adding a control means adding one row to CONTROLS, one label to labels(), and
 * one use of the variable in the stylesheet. Nothing else has to change: the
 * admin field, the sanitiser and the CSS emitter all read the registry. New
 * kinds of control are added by extending the mode switch rather than by
 * special-casing an individual key.
 *
 * STORAGE
 * -------
 * Its own option, 'zymarg_sp_flash_hero', registered into the EXISTING
 * 'zymarg_sp_options' settings group. Two reasons it is not nested inside the
 * zymarg_sp_options array: ZYMARG_SP_Admin::sanitize_options() rebuilds that
 * array from scratch and returns only its own four keys, so nesting here would
 * have every hero setting silently deleted on any classic save; and a separate
 * option keeps this module self-contained enough to be reasoned about on its
 * own. Sharing the settings GROUP is what keeps the no-JavaScript options.php
 * path working, since one form can carry several registered options.
 *
 * @package ZYMARG_Store_Page
 * @since   1.20.0
 */

defined( 'ABSPATH' ) || exit;

class ZYMARG_SP_Flash_Hero {

	/** Where hero settings live. */
	const OPTION = 'zymarg_sp_flash_hero';

	/** The settings group the admin form already posts to. */
	const GROUP = 'zymarg_sp_options';

	/** Scope suffix handed to the custom-design engine. */
	const SLUG = 'flash-hero';

	/**
	 * Emits a unitless multiplier: 50 becomes 0.5.
	 *
	 * For controls that scale a length the design already has. The stylesheet
	 * owns the length; the setting only says how much of it to use, so the
	 * responsive clamp() survives untouched.
	 */
	const MODE_SCALE = 'scale';

	/** Emits a 0-1 ratio, for opacity. */
	const MODE_RATIO = 'ratio';

	/** Emits a pixel length. A value of 0 means "automatic" and emits nothing. */
	const MODE_PX = 'px';

	/** Emits an angle in degrees. */
	const MODE_DEG = 'deg';

	/** Emits a colour literal. */
	const MODE_COLOR = 'color';

	/**
	 * Every design control, in the order it appears on the settings screen.
	 *
	 * Holds no translated strings on purpose. This constant is read on the front
	 * end to build the inline style, and pulling label text in here would load
	 * the translation files on every page view to render CSS that contains no
	 * text at all. Labels live in self::labels(), which only the admin calls.
	 *
	 * 'default' is always the value that reproduces the design as shipped, and
	 * is the one value that emits nothing. Note it is 100 for the scales and 0
	 * for the overlay: a scale of 100 means "all of the length the stylesheet
	 * already has", while an overlay of 0 means "the tint that does not exist
	 * yet". Both leave an untouched install exactly as authored.
	 *
	 * @since 1.20.0
	 * @var array<int, array<string, mixed>>
	 */
	const CONTROLS = array(
		// -- Shape -------------------------------------------------------
		/*
		 * A minimum height in pixels, not a scale.
		 *
		 * The homepage hero scales a min-height the stylesheet already has. This
		 * band has none: its height is whatever the padding plus the text comes
		 * to. A "height scale" here would therefore be a control that visibly
		 * does nothing, and giving the band a min-height just so a scale had
		 * something to bite on would change how the page looks on upgrade.
		 *
		 * So this is a floor instead, and 0 means "no floor, height follows the
		 * content" -- which is the shipped behaviour and emits no CSS.
		 */
		array(
			'key'     => 'min_height',
			'var'     => '--zfs-head-min',
			'mode'    => self::MODE_PX,
			'default' => 0,
			'min'     => 0,
			'max'     => 600,
			'step'    => 10,
			'group'   => 'shape',
		),
		array(
			'key'     => 'padding_scale',
			'var'     => '--zfs-head-pad-scale',
			'mode'    => self::MODE_SCALE,
			'default' => 100,
			'group'   => 'shape',
		),
		array(
			'key'     => 'max_width',
			'var'     => '--zfs-head-max',
			'mode'    => self::MODE_PX,
			'default' => 1280,
			'min'     => 640,
			'max'     => 1920,
			'step'    => 20,
			'group'   => 'shape',
		),
		array(
			'key'     => 'radius',
			'var'     => '--zfs-head-radius',
			'mode'    => self::MODE_PX,
			'default' => 0,
			'min'     => 0,
			'max'     => 48,
			'step'    => 2,
			'group'   => 'shape',
		),

		// -- Colour ------------------------------------------------------
		array(
			'key'     => 'grad_angle',
			'var'     => '--zfs-grad-angle',
			'mode'    => self::MODE_DEG,
			'default' => 135,
			'min'     => 0,
			'max'     => 360,
			'step'    => 5,
			'group'   => 'colour',
		),
		array(
			'key'     => 'grad_from',
			'var'     => '--zfs-grad-from',
			'mode'    => self::MODE_COLOR,
			'default' => '#9500a5',
			'group'   => 'colour',
		),
		array(
			'key'     => 'grad_via',
			'var'     => '--zfs-grad-via',
			'mode'    => self::MODE_COLOR,
			'default' => '#bd00d1',
			'group'   => 'colour',
		),
		array(
			'key'     => 'grad_to',
			'var'     => '--zfs-grad-to',
			'mode'    => self::MODE_COLOR,
			'default' => '#fea9ff',
			'group'   => 'colour',
		),
		array(
			'key'     => 'text_color',
			'var'     => '--zfs-head-text',
			'mode'    => self::MODE_COLOR,
			'default' => '#ffffff',
			'group'   => 'colour',
		),
		array(
			'key'     => 'overlay',
			'var'     => '--zfs-overlay-opacity',
			'mode'    => self::MODE_RATIO,
			'default' => 0,
			'group'   => 'colour',
		),

		// -- Type --------------------------------------------------------
		array(
			'key'     => 'title_size',
			'var'     => '--zfs-title-size',
			'mode'    => self::MODE_PX,
			'default' => 0,
			'min'     => 0,
			'max'     => 72,
			'step'    => 1,
			'group'   => 'type',
		),
		array(
			'key'     => 'sub_size',
			'var'     => '--zfs-sub-size',
			'mode'    => self::MODE_PX,
			'default' => 0,
			'min'     => 0,
			'max'     => 28,
			'step'    => 1,
			'group'   => 'type',
		),
	);

	/**
	 * Content fields, and the per-slide field list for the repeater.
	 *
	 * Shared by the admin panel and the renderer so the two cannot disagree
	 * about what a slide consists of. 'type' picks the input; nothing here
	 * emits CSS.
	 *
	 * @since 1.20.0
	 * @var array<string, string>
	 */
	const SLIDE_FIELDS = array(
		'eyebrow'   => 'text',
		'title'     => 'text',
		'subtitle'  => 'textarea',
		'cta_label' => 'text',
		'cta_url'   => 'url',
		'bg_image'  => 'url',
	);

	// ─────────────────────────────────────────────────────────────────────────
	// Boot
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the option inside the group the settings form already posts to.
	 *
	 * @since 1.20.0
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Registry helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Every control's default, keyed by setting name.
	 *
	 * @since 1.20.0
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		$defaults = array();

		foreach ( self::CONTROLS as $control ) {
			$defaults[ $control['key'] ] = $control['default'];
		}

		return $defaults;
	}

	/**
	 * Saved settings, merged over the registry defaults.
	 *
	 * @since 1.20.0
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( self::defaults(), $saved );
	}

	/**
	 * One control's declaration, or null.
	 *
	 * @since 1.20.0
	 * @param string $key Setting name.
	 * @return array<string, mixed>|null
	 */
	private static function control( $key ) {
		foreach ( self::CONTROLS as $control ) {
			if ( $control['key'] === $key ) {
				return $control;
			}
		}

		return null;
	}

	/**
	 * The numeric bounds for a control, with registry-wide fallbacks.
	 *
	 * Scales and ratios are always 0-100 and never declare their own, so the
	 * common case stays a three-key row in CONTROLS.
	 *
	 * @since 1.20.0
	 * @param array $control Control declaration.
	 * @return array{0:int,1:int,2:int} min, max, step.
	 */
	private static function bounds( array $control ) {
		return array(
			isset( $control['min'] ) ? (int) $control['min'] : 0,
			isset( $control['max'] ) ? (int) $control['max'] : 100,
			isset( $control['step'] ) ? (int) $control['step'] : 5,
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Sanitising
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Clamp and type-check submitted hero settings.
	 *
	 * Values here are interpolated straight into a CSS declaration, so the
	 * bounds are what guarantee the emitted stylesheet is always well-formed.
	 * A key absent from the input falls back to that control's own default
	 * rather than to zero, so a partial save cannot flatten a design nobody
	 * touched.
	 *
	 * @since 1.20.0
	 * @param mixed $input Raw input, from options.php or the Ajax handler.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		// -- Registry-driven design controls -----------------------------
		foreach ( self::CONTROLS as $control ) {
			$key = $control['key'];

			if ( self::MODE_COLOR === $control['mode'] ) {
				$colour = isset( $input[ $key ] ) ? sanitize_hex_color( (string) $input[ $key ] ) : null;
				// sanitize_hex_color() returns null for anything malformed, in
				// which case the shipped colour is the only safe answer.
				$clean[ $key ] = $colour ? $colour : $control['default'];
				continue;
			}

			list( $min, $max ) = self::bounds( $control );

			if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
				$clean[ $key ] = (int) $control['default'];
				continue;
			}

			$clean[ $key ] = max( $min, min( $max, (int) $input[ $key ] ) );
		}

		// -- Content -----------------------------------------------------
		$clean['eyebrow']   = sanitize_text_field( (string) ( $input['eyebrow'] ?? '' ) );
		$clean['title']     = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$clean['subtitle']  = sanitize_textarea_field( (string) ( $input['subtitle'] ?? '' ) );
		$clean['cta_label'] = sanitize_text_field( (string) ( $input['cta_label'] ?? '' ) );
		$clean['cta_url']   = esc_url_raw( (string) ( $input['cta_url'] ?? '' ) );
		$clean['bg_image']  = esc_url_raw( (string) ( $input['bg_image'] ?? '' ) );

		$align          = (string) ( $input['align'] ?? 'left' );
		$clean['align'] = in_array( $align, array( 'left', 'center' ), true ) ? $align : 'left';

		$clean['show_countdown'] = empty( $input['show_countdown'] ) ? 0 : 1;
		$clean['show_count']     = empty( $input['show_count'] ) ? 0 : 1;
		$clean['hide_header']    = empty( $input['hide_header'] ) ? 0 : 1;

		// -- Slides ------------------------------------------------------
		$clean['items'] = self::sanitize_items( $input['items'] ?? array() );

		// -- Custom design ----------------------------------------------
		$source                  = (string) ( $input['design_source'] ?? 'plugin' );
		$clean['design_source']  = ( 'custom' === $source ) ? 'custom' : 'plugin';

		/*
		 * Stored verbatim. Running these through sanitize_text_field() would
		 * strip every tag and destroy the pasted design on save, with nothing to
		 * indicate why. Only a manage_options user reaches this point, and the
		 * markup is confined to its section by ZYMARG_SP_Flash_Design at render
		 * time rather than being mangled on the way in.
		 */
		foreach ( ZYMARG_SP_Flash_Design::RAW_KEYS as $raw_key ) {
			$clean[ $raw_key ] = (string) ( $input[ $raw_key ] ?? '' );
		}

		return $clean;
	}

	/**
	 * Sanitise the slide repeater.
	 *
	 * Rows where every field is empty are dropped rather than stored, so the
	 * blank row left behind by an accidental "Add slide" cannot render as an
	 * empty banner. array_values() re-indexes, because removing a middle row
	 * client-side otherwise arrives here as a sparse list.
	 *
	 * @since 1.20.0
	 * @param mixed $items Raw rows.
	 * @return array<int, array<string, string>>
	 */
	private static function sanitize_items( $items ) {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$clean = array();

		foreach ( $items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$slide = array();
			$empty = true;

			foreach ( self::SLIDE_FIELDS as $field => $type ) {
				$value = (string) ( $row[ $field ] ?? '' );

				if ( 'url' === $type ) {
					$value = esc_url_raw( $value );
				} elseif ( 'textarea' === $type ) {
					$value = sanitize_textarea_field( $value );
				} else {
					$value = sanitize_text_field( $value );
				}

				$slide[ $field ] = $value;

				if ( '' !== trim( $value ) ) {
					$empty = false;
				}
			}

			if ( ! $empty ) {
				$clean[] = $slide;
			}
		}

		return array_values( $clean );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// CSS emission
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Build the CSS custom property declarations for the current settings.
	 *
	 * A control at its default emits nothing, which is what keeps an untouched
	 * install rendering from the stylesheet exactly as authored with no inline
	 * override to reason about. On a default install this returns ''.
	 *
	 * @since 1.20.0
	 * @param array|null $settings Settings, or null to read them.
	 * @return string Declarations such as "--zfs-head-scale:0.5;", or ''.
	 */
	public static function declarations( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$out      = '';

		foreach ( self::CONTROLS as $control ) {
			$key = $control['key'];

			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}

			$value = $settings[ $key ];

			if ( self::MODE_COLOR === $control['mode'] ) {
				$colour = sanitize_hex_color( (string) $value );

				if ( ! $colour || 0 === strcasecmp( $colour, (string) $control['default'] ) ) {
					continue;
				}

				$out .= $control['var'] . ':' . $colour . ';';
				continue;
			}

			if ( ! is_numeric( $value ) ) {
				continue;
			}

			list( $min, $max ) = self::bounds( $control );
			$value             = max( $min, min( $max, (int) $value ) );

			if ( (int) $control['default'] === $value ) {
				continue;
			}

			switch ( $control['mode'] ) {
				case self::MODE_SCALE:
				case self::MODE_RATIO:
					$out .= $control['var'] . ':' . self::ratio( $value ) . ';';
					break;

				case self::MODE_PX:
					/*
					 * 0 is the "automatic" sentinel for the two type sizes: it
					 * means "keep the stylesheet's fluid clamp()". It is also
					 * their default, so it has already been skipped above --
					 * this guard is for a control whose default is non-zero but
					 * which has been deliberately set to 0.
					 */
					if ( 0 === $value ) {
						break;
					}
					$out .= $control['var'] . ':' . $value . 'px;';
					break;

				case self::MODE_DEG:
					$out .= $control['var'] . ':' . $value . 'deg;';
					break;
			}
		}

		return $out;
	}

	/**
	 * Turn a 0-100 integer into a tidy CSS ratio.
	 *
	 * Trailing zeroes would be harmless but noisy in the page source.
	 *
	 * @since 1.20.0
	 * @param int $value 0-100.
	 * @return string
	 */
	private static function ratio( $value ) {
		$ratio = rtrim( rtrim( number_format( $value / 100, 2, '.', '' ), '0' ), '.' );

		return '' === $ratio ? '0' : $ratio;
	}

	/**
	 * Attach the hero's custom properties to the page-chrome stylesheet.
	 *
	 * wp_add_inline_style() rather than a wp_head echo so the values land in the
	 * same place as the CSS they override, and never flash the shipped design
	 * before correcting themselves.
	 *
	 * @since 1.20.0
	 * @param string $handle Enqueued style handle to attach to.
	 * @return void
	 */
	public static function inline_css( $handle ) {
		$declarations = self::declarations();

		if ( '' === $declarations ) {
			return;
		}

		wp_add_inline_style( $handle, ':root{' . $declarations . '}' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Live data
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * How many flash sales are live right now, marketplace-wide.
	 *
	 * Reads the list the page already builds and caches, so switching the count
	 * on costs no extra query.
	 *
	 * @since 1.20.0
	 * @return int
	 */
	public static function deal_count() {
		if ( ! class_exists( 'ZYMARG_SP_Flash_Sale' ) ) {
			return 0;
		}

		return count( (array) ZYMARG_SP_Flash_Sale::premium_flash_ids() );
	}

	/**
	 * When the soonest-ending live flash sale ends, as a Unix timestamp.
	 *
	 * Returns 0 when there is nothing to count down to -- no live sales, or
	 * every live sale open-ended. The renderer treats 0 as "draw no countdown"
	 * rather than showing a timer at zero, which would read as "this sale has
	 * already finished" on a page full of running sales.
	 *
	 * @since 1.20.0
	 * @return int
	 */
	public static function soonest_end() {
		if ( ! class_exists( 'ZYMARG_SP_Flash_Sale' )
			|| ! function_exists( 'zymarg_vd_premium_get_flash_data' )
			|| ! function_exists( 'zymarg_sp_premium_window_ts' ) ) {
			return 0;
		}

		$ids = (array) ZYMARG_SP_Flash_Sale::premium_flash_ids();

		/*
		 * The list is already sorted soonest-ending first, with open-ended sales
		 * pushed to the back, so the first row that yields a real timestamp is
		 * the answer. The loop exists only to skip a leading open-ended sale on
		 * a marketplace that has nothing else running.
		 */
		foreach ( $ids as $pid ) {
			$data = (array) zymarg_vd_premium_get_flash_data( (int) $pid );
			$end  = isset( $data['end'] ) ? (string) $data['end'] : '';

			if ( '' === $end ) {
				continue;
			}

			$ts = (int) zymarg_sp_premium_window_ts( $end );

			if ( $ts > time() ) {
				return $ts;
			}
		}

		return 0;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Rendering
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Resolved slides, newest-format first and legacy flat keys as the fallback.
	 *
	 * A single-slide hero returns exactly one row built from the top-level
	 * fields, so a site that never touches the repeater renders through the same
	 * code path with the same markup it always had.
	 *
	 * @since 1.20.0
	 * @param array $settings Hero settings.
	 * @return array<int, array<string, string>>
	 */
	private static function slides( array $settings ) {
		$items = isset( $settings['items'] ) && is_array( $settings['items'] )
			? $settings['items']
			: array();

		$rows = array();

		foreach ( $items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$slide = array();
			$empty = true;

			foreach ( self::SLIDE_FIELDS as $field => $unused ) {
				$slide[ $field ] = trim( (string) ( $row[ $field ] ?? '' ) );

				if ( '' !== $slide[ $field ] ) {
					$empty = false;
				}
			}

			if ( ! $empty ) {
				$rows[] = $slide;
			}
		}

		if ( ! empty( $rows ) ) {
			return $rows;
		}

		// No repeater rows: fall back to the flat top-level fields.
		$single = array();

		foreach ( self::SLIDE_FIELDS as $field => $unused ) {
			$single[ $field ] = trim( (string) ( $settings[ $field ] ?? '' ) );
		}

		return array( $single );
	}

	/**
	 * Fill a slide's blanks with the shipped copy.
	 *
	 * The defaults are resolved here rather than at save time so that clearing a
	 * field restores the shipped text instead of rendering an empty element, and
	 * so a site that never opens this screen keeps the 1.19.3 wording.
	 *
	 * @since 1.20.0
	 * @param array $slide Raw slide.
	 * @return array<string, string>
	 */
	private static function resolve( array $slide ) {
		$title = trim( (string) ( $slide['title'] ?? '' ) );

		if ( '' === $title ) {
			// The page title, exactly as 1.19.3 did it.
			$title = (string) get_the_title();
		}

		$eyebrow = trim( (string) ( $slide['eyebrow'] ?? '' ) );

		if ( '' === $eyebrow ) {
			$eyebrow = __( 'Limited time', 'zymarg-store-page' );
		}

		$subtitle = trim( (string) ( $slide['subtitle'] ?? '' ) );

		if ( '' === $subtitle ) {
			$subtitle = __( 'Every flash sale running across the marketplace right now. Ending soonest first.', 'zymarg-store-page' );
		}

		return array(
			'eyebrow'   => $eyebrow,
			'title'     => $title,
			'subtitle'  => $subtitle,
			'cta_label' => trim( (string) ( $slide['cta_label'] ?? '' ) ),
			'cta_url'   => trim( (string) ( $slide['cta_url'] ?? '' ) ),
			'bg_image'  => trim( (string) ( $slide['bg_image'] ?? '' ) ),
		);
	}

	/**
	 * The Flash Sale hero, as markup.
	 *
	 * @since 1.20.0
	 * @return string
	 */
	public static function render() {
		$settings = self::get_settings();
		$slides   = self::slides( $settings );

		// The custom design gets the first slide's resolved values, so an author
		// pasting their own markup still receives live copy and a live count.
		$first = self::resolve( $slides[0] );

		if ( ZYMARG_SP_Flash_Design::is_active( $settings ) ) {
			return ZYMARG_SP_Flash_Design::render(
				$settings,
				array_merge(
					$first,
					array(
						'deal_count' => (string) self::deal_count(),
						'ends_at'    => (string) self::soonest_end(),
					)
				),
				array( 'slides' => array_map( array( __CLASS__, 'resolve' ), $slides ) ),
				self::SLUG
			);
		}

		$multi = count( $slides ) > 1;

		$classes = array( 'zfs__head' );

		if ( 'center' === ( $settings['align'] ?? 'left' ) ) {
			$classes[] = 'zfs__head--center';
		}

		if ( $multi ) {
			$classes[] = 'zfs__head--multi';
		}

		ob_start();

		// Extra CSS typed against the stock design, scoped to this section.
		echo ZYMARG_SP_Flash_Design::standalone_css( $settings, self::SLUG ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored CSS, confined by the engine.

		if ( $multi ) {
			/*
			 * A CSS scroll-snap track, not a scripted carousel. It swipes on
			 * touch, scrolls with the keyboard, degrades to a plain stack with
			 * no JavaScript at all, and adds no payload to a page whose weight
			 * budget is already spent on product cards.
			 */
			echo '<div class="zfs__slides" data-zfs-slides="' . esc_attr( (string) count( $slides ) ) . '">';
		}

		foreach ( $slides as $slide ) {
			self::render_slide( self::resolve( $slide ), $settings, $classes );
		}

		if ( $multi ) {
			echo '</div>';
		}

		return (string) ob_get_clean();
	}

	/**
	 * One hero slide.
	 *
	 * Prints rather than returns because it is only ever called from inside
	 * render()'s output buffer.
	 *
	 * @since 1.20.0
	 * @param array $slide    Resolved slide values.
	 * @param array $settings Hero settings.
	 * @param array $classes  Wrapper classes.
	 * @return void
	 */
	private static function render_slide( array $slide, array $settings, array $classes ) {
		$has_image = '' !== $slide['bg_image'];

		if ( $has_image ) {
			$classes[] = 'zfs__head--has-image';
		}
		?>
		<header class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<?php if ( $has_image ) : ?>
				<?php
				/*
				 * A real <img> rather than an inline background-image. This is
				 * the page's Largest Contentful Paint element, and a background
				 * cannot be found by the preload scanner, cannot carry
				 * fetchpriority and cannot have a srcset.
				 */
				?>
				<img class="zfs__bg" src="<?php echo esc_url( $slide['bg_image'] ); ?>"
					alt="" aria-hidden="true"
					loading="eager" decoding="async" fetchpriority="high">
				<span class="zfs__overlay" aria-hidden="true"></span>
			<?php endif; ?>

			<div class="zfs__head-inner">
				<?php
				$custom_header = trim( (string) ( $settings['header_html'] ?? '' ) );

				if ( ZYMARG_SP_Flash_Design::header_hidden( $settings ) ) {
					// Heading suppressed by the setting. The rest still renders.
					$header_done = true;
				} elseif ( '' !== $custom_header ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored markup stored verbatim under RAW_KEYS, confined by the engine.
					echo ZYMARG_SP_Flash_Design::prepare_header( $custom_header, self::SLUG );
					$header_done = true;
				} else {
					$header_done = false;
				}

				if ( ! $header_done ) :
					?>
					<p class="zfs__eyebrow"><?php echo esc_html( $slide['eyebrow'] ); ?></p>
					<h1 class="zfs__title"><?php echo esc_html( $slide['title'] ); ?></h1>
					<p class="zfs__sub"><?php echo esc_html( $slide['subtitle'] ); ?></p>
					<?php
				endif;

				self::render_meta( $settings );

				if ( '' !== $slide['cta_url'] && '' !== $slide['cta_label'] ) :
					?>
					<a class="zfs__cta" href="<?php echo esc_url( $slide['cta_url'] ); ?>">
						<?php echo esc_html( $slide['cta_label'] ); ?>
					</a>
					<?php
				endif;
				?>
			</div>
		</header>
		<?php
	}

	/**
	 * The countdown and the live deal count.
	 *
	 * Both are opt-in and both stay silent when they have nothing true to say:
	 * no countdown without a real end date, no count when the count is zero.
	 * A hero that claims "0 deals" on a page listing deals would be worse than
	 * one that claims nothing.
	 *
	 * @since 1.20.0
	 * @param array $settings Hero settings.
	 * @return void
	 */
	private static function render_meta( array $settings ) {
		$count = ! empty( $settings['show_count'] ) ? self::deal_count() : 0;
		$ends  = ! empty( $settings['show_countdown'] ) ? self::soonest_end() : 0;

		if ( $count < 1 && $ends < 1 ) {
			return;
		}

		echo '<div class="zfs__meta">';

		if ( $count > 0 ) {
			printf(
				'<span class="zfs__chip zfs__chip--count">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: number of live flash sales. */
						_n( '%s deal live now', '%s deals live now', $count, 'zymarg-store-page' ),
						number_format_i18n( $count )
					)
				)
			);
		}

		if ( $ends > 0 ) {
			/*
			 * The server prints the deadline and a formatted fallback; the script
			 * only counts down. So the deadline is still readable with JavaScript
			 * off, and a cached page cannot serve a stale timer -- the target is
			 * absolute, not a duration.
			 */
			printf(
				'<span class="zfs__chip zfs__chip--timer" data-zfs-countdown="%1$s"><span class="zfs__chip-label">%2$s</span> <time datetime="%3$s">%4$s</time></span>',
				esc_attr( (string) $ends ),
				esc_html__( 'Ends in', 'zymarg-store-page' ),
				esc_attr( gmdate( 'c', $ends ) ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ends ) )
			);
		}

		echo '</div>';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Admin
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Translated labels and descriptions for the design controls.
	 *
	 * Separate from CONTROLS on purpose: a class constant cannot call __(), and
	 * the front end reads CONTROLS on every Flash Sale view.
	 *
	 * @since 1.20.0
	 * @return array<string, array<string, string>>
	 */
	public static function labels() {
		return array(
			'min_height'    => array(
				'label' => __( 'Minimum Height', 'zymarg-store-page' ),
				'desc'  => __( 'Forces the hero band to be at least this tall, in pixels. Leave at 0 and the band is exactly as tall as its own text needs, which is the design as shipped. Raise it for a taller, more poster-like banner — especially with a background image.', 'zymarg-store-page' ),
			),
			'padding_scale' => array(
				'label' => __( 'Hero Padding', 'zymarg-store-page' ),
				'desc'  => __( 'Breathing room above and below the hero text, as a percentage of the built-in spacing. 100 is the design as shipped, 50 halves it. This is the setting that actually changes the height of a hero with no Minimum Height set.', 'zymarg-store-page' ),
			),
			'max_width'     => array(
				'label' => __( 'Content Width', 'zymarg-store-page' ),
				'desc'  => __( 'How wide the hero text may run, in pixels. Matches the product grid below at the default 1280.', 'zymarg-store-page' ),
			),
			'radius'        => array(
				'label' => __( 'Corner Radius', 'zymarg-store-page' ),
				'desc'  => __( 'Rounds the bottom corners of the hero band, in pixels. 0 is the design as shipped, where the band meets the page edge to edge.', 'zymarg-store-page' ),
			),
			'grad_angle'    => array(
				'label' => __( 'Gradient Angle', 'zymarg-store-page' ),
				'desc'  => __( 'Direction the three gradient colours run, in degrees. 135 is the ZYMARG brand default: top-left to bottom-right.', 'zymarg-store-page' ),
			),
			'grad_from'     => array(
				'label' => __( 'Gradient Start', 'zymarg-store-page' ),
				'desc'  => __( 'First gradient colour. Defaults to ZYMARG brand purple.', 'zymarg-store-page' ),
			),
			'grad_via'      => array(
				'label' => __( 'Gradient Middle', 'zymarg-store-page' ),
				'desc'  => __( 'Middle gradient colour, placed at 60%. Defaults to ZYMARG brand magenta.', 'zymarg-store-page' ),
			),
			'grad_to'       => array(
				'label' => __( 'Gradient End', 'zymarg-store-page' ),
				'desc'  => __( 'Final gradient colour. Defaults to ZYMARG light pink.', 'zymarg-store-page' ),
			),
			'text_color'    => array(
				'label' => __( 'Text Colour', 'zymarg-store-page' ),
				'desc'  => __( 'Colour of the eyebrow, title and subtitle. Check the contrast against your gradient after changing either.', 'zymarg-store-page' ),
			),
			'overlay'       => array(
				'label' => __( 'Image Darkening', 'zymarg-store-page' ),
				'desc'  => __( 'How strongly a background image is darkened, so light text stays readable over it. 0 leaves the image untouched. Only applies when a background image is set.', 'zymarg-store-page' ),
			),
			'title_size'    => array(
				'label' => __( 'Title Size', 'zymarg-store-page' ),
				'desc'  => __( 'Title size in pixels. Leave at 0 to keep the built-in fluid size, which scales itself between 28 and 44 pixels with the viewport — usually the better choice.', 'zymarg-store-page' ),
			),
			'sub_size'      => array(
				'label' => __( 'Subtitle Size', 'zymarg-store-page' ),
				'desc'  => __( 'Subtitle size in pixels. Leave at 0 to keep the built-in 15 pixels.', 'zymarg-store-page' ),
			),
		);
	}

	/**
	 * Group headings for the design controls.
	 *
	 * @since 1.20.0
	 * @return array<string, string>
	 */
	private static function groups() {
		return array(
			'shape'  => __( 'Hero Shape', 'zymarg-store-page' ),
			'colour' => __( 'Hero Colour', 'zymarg-store-page' ),
			'type'   => __( 'Hero Typography', 'zymarg-store-page' ),
		);
	}

	/**
	 * The field name for a hero setting.
	 *
	 * One place, so the admin markup and the Ajax collector cannot drift.
	 *
	 * @since 1.20.0
	 * @param string $key  Setting name.
	 * @param string $path Optional extra bracket path, e.g. 'items][0'.
	 * @return string
	 */
	private static function name( $key, $path = '' ) {
		return '' === $path
			? self::OPTION . '[' . $key . ']'
			: self::OPTION . '[' . $path . '][' . $key . ']';
	}

	/**
	 * Render the whole Flash Sale Hero panel.
	 *
	 * @since 1.20.0
	 * @return void
	 */
	public static function render_fields() {
		$settings = self::get_settings();

		self::render_content_fields( $settings );
		self::render_design_fields( $settings );
		self::render_repeater( $settings );
		self::render_custom_fields( $settings );
	}

	/**
	 * Content fields.
	 *
	 * @since 1.20.0
	 * @param array $settings Hero settings.
	 * @return void
	 */
	private static function render_content_fields( array $settings ) {
		echo '<p class="zsp-section-label">' . esc_html__( 'Hero Content', 'zymarg-store-page' ) . '</p>';

		self::text_field(
			'eyebrow',
			$settings['eyebrow'] ?? '',
			__( 'Eyebrow', 'zymarg-store-page' ),
			__( 'The small uppercase line above the title. Leave empty for "Limited time".', 'zymarg-store-page' ),
			'text',
			__( 'Limited time', 'zymarg-store-page' )
		);

		self::text_field(
			'title',
			$settings['title'] ?? '',
			__( 'Title', 'zymarg-store-page' ),
			__( 'Leave empty to use the page title, which is what this page has always shown.', 'zymarg-store-page' ),
			'text',
			__( 'Flash Sale', 'zymarg-store-page' )
		);

		self::text_field(
			'subtitle',
			$settings['subtitle'] ?? '',
			__( 'Subtitle', 'zymarg-store-page' ),
			__( 'One or two lines under the title. Leave empty to keep the shipped sentence.', 'zymarg-store-page' ),
			'textarea'
		);

		self::text_field(
			'cta_label',
			$settings['cta_label'] ?? '',
			__( 'Button Label', 'zymarg-store-page' ),
			__( 'Leave the label or the URL empty and no button is drawn.', 'zymarg-store-page' )
		);

		self::text_field(
			'cta_url',
			$settings['cta_url'] ?? '',
			__( 'Button URL', 'zymarg-store-page' ),
			'',
			'url'
		);

		self::text_field(
			'bg_image',
			$settings['bg_image'] ?? '',
			__( 'Background Image URL', 'zymarg-store-page' ),
			__( 'Sits behind the gradient. Use Image Darkening below to keep the text readable over it.', 'zymarg-store-page' ),
			'url'
		);

		// Alignment.
		$align = ( 'center' === ( $settings['align'] ?? 'left' ) ) ? 'center' : 'left';
		echo '<div class="zsp-field">';
		echo '<label class="zsp-label" for="zfs-align">' . esc_html__( 'Text Alignment', 'zymarg-store-page' ) . '</label>';
		echo '<select id="zfs-align" name="' . esc_attr( self::name( 'align' ) ) . '">';
		echo '<option value="left"' . selected( $align, 'left', false ) . '>' . esc_html__( 'Left', 'zymarg-store-page' ) . '</option>';
		echo '<option value="center"' . selected( $align, 'center', false ) . '>' . esc_html__( 'Centred', 'zymarg-store-page' ) . '</option>';
		echo '</select>';
		echo '</div>';

		self::toggle_field(
			'show_countdown',
			! empty( $settings['show_countdown'] ),
			__( 'Show a countdown', 'zymarg-store-page' ),
			__( 'Counts down to the soonest-ending live flash sale. Hidden automatically when nothing has an end date, rather than showing a timer at zero.', 'zymarg-store-page' )
		);

		self::toggle_field(
			'show_count',
			! empty( $settings['show_count'] ),
			__( 'Show the live deal count', 'zymarg-store-page' ),
			__( 'A chip reading "12 deals live now". Hidden automatically when the count is zero.', 'zymarg-store-page' )
		);
	}

	/**
	 * Registry-driven design controls.
	 *
	 * Built from CONTROLS rather than hand-written, so a new control appears
	 * here the moment it is declared.
	 *
	 * @since 1.20.0
	 * @param array $settings Hero settings.
	 * @return void
	 */
	private static function render_design_fields( array $settings ) {
		$labels = self::labels();

		foreach ( self::groups() as $group => $heading ) {
			echo '<div class="zsp-divider"></div>';
			echo '<p class="zsp-section-label">' . esc_html( $heading ) . '</p>';

			foreach ( self::CONTROLS as $control ) {
				if ( ( $control['group'] ?? '' ) !== $group ) {
					continue;
				}

				$key   = $control['key'];
				$label = $labels[ $key ]['label'] ?? $key;
				$desc  = $labels[ $key ]['desc'] ?? '';
				$id    = 'zfs-' . str_replace( '_', '-', $key );
				$value = $settings[ $key ] ?? $control['default'];

				echo '<div class="zsp-field">';
				echo '<label class="zsp-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';

				if ( self::MODE_COLOR === $control['mode'] ) {
					$colour = sanitize_hex_color( (string) $value );
					$colour = $colour ? $colour : (string) $control['default'];

					echo '<span class="zfs-colour">';
					printf(
						'<input type="color" id="%1$s" name="%2$s" value="%3$s" data-zfs-default="%4$s">',
						esc_attr( $id ),
						esc_attr( self::name( $key ) ),
						esc_attr( $colour ),
						esc_attr( (string) $control['default'] )
					);
					// The hex is shown as text too: a colour swatch alone gives
					// an admin no way to copy a value or to see what it is.
					printf(
						'<code class="zfs-colour__value">%s</code>',
						esc_html( $colour )
					);
					printf(
						'<button type="button" class="zfs-reset" data-zfs-reset="%s">%s</button>',
						esc_attr( $id ),
						esc_html__( 'Reset', 'zymarg-store-page' )
					);
					echo '</span>';
				} else {
					list( $min, $max, $step ) = self::bounds( $control );

					printf(
						'<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="%6$s" data-zfs-default="%7$s">',
						esc_attr( $id ),
						esc_attr( self::name( $key ) ),
						esc_attr( (string) (int) $value ),
						esc_attr( (string) $min ),
						esc_attr( (string) $max ),
						esc_attr( (string) $step ),
						esc_attr( (string) $control['default'] )
					);
				}

				if ( '' !== $desc ) {
					echo '<p class="zsp-field-desc">' . esc_html( $desc ) . '</p>';
				}

				echo '</div>';
			}
		}
	}

	/**
	 * The multi-slide repeater.
	 *
	 * The clone source lives in a <script type="text/html"> so the browser never
	 * renders it and its inputs are never submitted. __INDEX__ is substituted
	 * client-side.
	 *
	 * @since 1.20.0
	 * @param array $settings Hero settings.
	 * @return void
	 */
	private static function render_repeater( array $settings ) {
		$items = isset( $settings['items'] ) && is_array( $settings['items'] ) ? $settings['items'] : array();

		echo '<div class="zsp-divider"></div>';
		echo '<p class="zsp-section-label">' . esc_html__( 'Hero Slides', 'zymarg-store-page' ) . '</p>';
		echo '<p class="zsp-field-desc zfs-repeater__intro">'
			. esc_html__( 'Add two or more slides to turn the hero into a swipeable banner. With no slides here the single hero above is used. Anything left empty on a slide falls back to the matching field above.', 'zymarg-store-page' )
			. '</p>';

		echo '<div class="zfs-repeater" data-zfs-repeater>';
		echo '<div class="zfs-repeater__rows">';

		foreach ( $items as $index => $row ) {
			self::render_repeater_row( (int) $index, is_array( $row ) ? $row : array() );
		}

		echo '</div>';

		echo '<script type="text/html" class="zfs-repeater__template">';
		self::render_repeater_row( '__INDEX__', array() );
		echo '</script>';

		printf(
			'<button type="button" class="zsp-ghost-btn zfs-repeater__add">%s</button>',
			esc_html__( '+ Add slide', 'zymarg-store-page' )
		);

		echo '</div>';
	}

	/**
	 * One repeater row.
	 *
	 * @since 1.20.0
	 * @param int|string $index Row index, or the __INDEX__ token.
	 * @param array      $row   Saved row values.
	 * @return void
	 */
	private static function render_repeater_row( $index, array $row ) {
		$labels = array(
			'eyebrow'   => __( 'Eyebrow', 'zymarg-store-page' ),
			'title'     => __( 'Title', 'zymarg-store-page' ),
			'subtitle'  => __( 'Subtitle', 'zymarg-store-page' ),
			'cta_label' => __( 'Button Label', 'zymarg-store-page' ),
			'cta_url'   => __( 'Button URL', 'zymarg-store-page' ),
			'bg_image'  => __( 'Background Image URL', 'zymarg-store-page' ),
		);

		echo '<div class="zfs-repeater__row" data-index="' . esc_attr( (string) $index ) . '">';

		printf(
			'<p class="zfs-repeater__title">%s</p>',
			esc_html__( 'Slide', 'zymarg-store-page' )
		);

		foreach ( self::SLIDE_FIELDS as $field => $type ) {
			$value = (string) ( $row[ $field ] ?? '' );
			$name  = self::name( $field, 'items][' . $index );

			echo '<div class="zsp-field zsp-field--tight">';
			echo '<label class="zsp-label">' . esc_html( $labels[ $field ] ?? $field ) . '</label>';

			if ( 'textarea' === $type ) {
				printf(
					'<textarea name="%1$s" rows="2" class="zsp-input--wide">%2$s</textarea>',
					esc_attr( $name ),
					esc_textarea( $value )
				);
			} else {
				printf(
					'<input type="%1$s" name="%2$s" value="%3$s" class="zsp-input--wide">',
					esc_attr( 'url' === $type ? 'url' : 'text' ),
					esc_attr( $name ),
					esc_attr( $value )
				);
			}

			echo '</div>';
		}

		printf(
			'<button type="button" class="zfs-repeater__remove">%s</button>',
			esc_html__( 'Remove slide', 'zymarg-store-page' )
		);

		echo '</div>';
	}

	/**
	 * The master control: design source, pasted HTML, extra CSS, custom header.
	 *
	 * @since 1.20.0
	 * @param array $settings Hero settings.
	 * @return void
	 */
	private static function render_custom_fields( array $settings ) {
		$source = ( 'custom' === ( $settings['design_source'] ?? 'plugin' ) ) ? 'custom' : 'plugin';

		echo '<div class="zsp-divider"></div>';
		echo '<p class="zsp-section-label">' . esc_html__( 'Your Own Design', 'zymarg-store-page' ) . '</p>';

		// Hide header + custom header first: the smaller, safer edit. Most
		// admins want to restyle a title, not replace the whole hero.
		self::toggle_field(
			'hide_header',
			! empty( $settings['hide_header'] ),
			__( 'Hide the heading block', 'zymarg-store-page' ),
			__( 'Removes the eyebrow, title and subtitle only. The countdown, button and background still render.', 'zymarg-store-page' )
		);

		self::code_field(
			'header_html',
			$settings['header_html'] ?? '',
			__( 'Custom Heading HTML', 'zymarg-store-page' ),
			__( 'Replaces the eyebrow, title and subtitle only — the rest of the hero keeps the plugin design. Your CSS is confined to this block automatically. Leave empty to use the default heading.', 'zymarg-store-page' ),
			4
		);

		// Design source.
		echo '<div class="zsp-field">';
		echo '<label class="zsp-label" for="zfs-design-source">' . esc_html__( 'Design Source', 'zymarg-store-page' ) . '</label>';
		echo '<select id="zfs-design-source" name="' . esc_attr( self::name( 'design_source' ) ) . '">';
		echo '<option value="plugin"' . selected( $source, 'plugin', false ) . '>' . esc_html__( 'Plugin default design', 'zymarg-store-page' ) . '</option>';
		echo '<option value="custom"' . selected( $source, 'custom', false ) . '>' . esc_html__( 'My own HTML design', 'zymarg-store-page' ) . '</option>';
		echo '</select>';
		echo '<p class="zsp-field-desc">'
			. esc_html__( 'Switching to your own design replaces the entire hero. Switch back at any time — your code stays saved either way.', 'zymarg-store-page' )
			. '</p>';
		echo '</div>';

		self::code_field(
			'custom_html',
			$settings['custom_html'] ?? '',
			__( 'My HTML Design', 'zymarg-store-page' ),
			__( 'Paste a complete HTML file, exactly as your developer wrote it. The document wrapper is removed and your CSS is confined to this section automatically, so it cannot affect your header, footer or the product grid below.', 'zymarg-store-page' ),
			12,
			'{{eyebrow}} {{title}} {{subtitle}} {{cta_label}} {{cta_url}} {{bg_image}} {{deal_count}} {{ends_at}} — repeat per slide: {{#slides}} {{title}} {{subtitle}} {{cta_url}} {{bg_image}} {{/slides}}'
		);

		self::code_field(
			'custom_css',
			$settings['custom_css'] ?? '',
			__( 'Extra CSS (optional)', 'zymarg-store-page' ),
			__( 'Applied after the CSS inside your HTML file, so it wins any conflict. Works with the plugin design too, and is confined to this section either way.', 'zymarg-store-page' ),
			6
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Field primitives
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * A text, url or textarea field.
	 *
	 * @since 1.20.0
	 * @param string $key         Setting name.
	 * @param string $value       Current value.
	 * @param string $label       Field label.
	 * @param string $desc        Optional description.
	 * @param string $type        text|url|textarea.
	 * @param string $placeholder Optional placeholder.
	 * @return void
	 */
	private static function text_field( $key, $value, $label, $desc = '', $type = 'text', $placeholder = '' ) {
		$id = 'zfs-' . str_replace( '_', '-', $key );

		echo '<div class="zsp-field">';
		echo '<label class="zsp-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';

		if ( 'textarea' === $type ) {
			printf(
				'<textarea id="%1$s" name="%2$s" rows="3" class="zsp-input--wide" placeholder="%3$s">%4$s</textarea>',
				esc_attr( $id ),
				esc_attr( self::name( $key ) ),
				esc_attr( $placeholder ),
				esc_textarea( (string) $value )
			);
		} else {
			printf(
				'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="zsp-input--wide" placeholder="%5$s">',
				esc_attr( 'url' === $type ? 'url' : 'text' ),
				esc_attr( $id ),
				esc_attr( self::name( $key ) ),
				esc_attr( (string) $value ),
				esc_attr( $placeholder )
			);
		}

		if ( '' !== $desc ) {
			echo '<p class="zsp-field-desc">' . esc_html( $desc ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * A monospace code box.
	 *
	 * @since 1.20.0
	 * @param string $key   Setting name.
	 * @param string $value Current value.
	 * @param string $label Field label.
	 * @param string $desc  Description.
	 * @param int    $rows  Textarea rows.
	 * @param string $hint  Optional placeholder-token hint.
	 * @return void
	 */
	private static function code_field( $key, $value, $label, $desc, $rows = 8, $hint = '' ) {
		$id = 'zfs-' . str_replace( '_', '-', $key );

		echo '<div class="zsp-field">';
		echo '<label class="zsp-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';

		printf(
			'<textarea id="%1$s" name="%2$s" rows="%3$s" spellcheck="false" class="zfs-code-box">%4$s</textarea>',
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			esc_attr( (string) (int) $rows ),
			esc_textarea( (string) $value )
		);

		echo '<p class="zsp-field-desc">' . esc_html( $desc );

		if ( '' !== $hint ) {
			echo '<br><strong>' . esc_html__( 'Live data placeholders:', 'zymarg-store-page' ) . '</strong> '
				. '<code>' . esc_html( $hint ) . '</code>';
		}

		echo '</p>';
		echo '</div>';
	}

	/**
	 * A toggle switch, matching the ones already on this screen.
	 *
	 * Duplicated shape rather than reusing ZYMARG_SP_Admin::toggle_field()
	 * because that helper is private and hard-codes the zymarg_sp_options field
	 * name. The markup and classes are identical, so the two look the same.
	 *
	 * @since 1.20.0
	 * @param string $key     Setting name.
	 * @param bool   $checked Current state.
	 * @param string $label   Field label.
	 * @param string $desc    Description.
	 * @return void
	 */
	private static function toggle_field( $key, $checked, $label, $desc ) {
		$id = 'zfs-' . str_replace( '_', '-', $key );

		echo '<div class="zsp-field">';
		echo '<label class="zsp-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<div class="zsp-toggle-wrap">';
		echo '<span class="zsp-toggle">';
		printf(
			'<input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s>',
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			checked( (bool) $checked, true, false )
		);
		echo '<span class="zsp-toggle__slider"></span>';
		echo '</span>';
		// The word, not just the pill colour, carries the state.
		echo '<span class="zsp-toggle-state">'
			. esc_html( $checked ? __( 'Enabled', 'zymarg-store-page' ) : __( 'Disabled', 'zymarg-store-page' ) )
			. '</span>';
		echo '</div>';
		echo '<p class="zsp-field-desc">' . esc_html( $desc ) . '</p>';
		echo '</div>';
	}
}
