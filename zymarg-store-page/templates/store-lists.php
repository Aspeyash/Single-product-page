<?php
/**
 * ZYMARG Store Listing Template
 *
 * Replaces Dokan's default store-lists.php -- the marketplace store
 * directory, normally output by the [dokan-stores] shortcode.
 *
 * Card design 06 ("Split").
 *
 * Search and sorting are plain GET form submissions rather than Ajax, on
 * purpose: the result stays linkable, shareable, bookmarkable and indexable,
 * and it keeps working when JavaScript fails. The only JavaScript on this
 * page is the Follow button and the sort auto-submit, and both degrade.
 *
 * @package ZYMARG_Store_Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZYMARG_SP_Store_Listing' ) ) {
	return;
}

$zsl_request = ZYMARG_SP_Store_Listing::current_request();
$zsl_results = ZYMARG_SP_Store_Listing::query( $zsl_request );
$zsl_sorts   = ZYMARG_SP_Store_Listing::sort_options();

$zsl_total    = (int) $zsl_results['total'];
$zsl_vendors  = $zsl_results['vendors'];
$zsl_pages    = (int) $zsl_results['pages'];
$zsl_paged    = (int) $zsl_results['paged'];
$zsl_search   = $zsl_request['search'];
$zsl_sort     = $zsl_request['sort'];
$zsl_base_url = remove_query_arg( array( 'store_page' ) );

get_header();
?>

<style type="text/tailwindcss">
  @theme {
    --font-sans: "Inter Variable", ui-sans-serif, system-ui, sans-serif;
    /* Pointed at the shared tokens rather than holding a second copy of
       the palette. Every bg-zy-* / text-zy-* utility in this template now
       follows dark mode automatically. The gradient utilities below stay
       literal on purpose: the brand mark reads the same in both modes. */
    --color-zy-primary:   var(--zym-color-primary);
    --color-zy-secondary: var(--zym-color-secondary);
    --color-zy-accent:    var(--zym-color-accent);
    --color-zy-dark:      var(--zym-color-dark);
    --color-zy-body:      var(--zym-color-text);
    --color-zy-border:    var(--zym-color-border);
    --color-zy-bg:        var(--zym-color-bg);
    --color-zy-surface:   var(--zym-color-surface);
    --color-zy-alt:       var(--zym-color-surface-alt);
    --color-zy-container: var(--zym-color-container);
  }
  @utility bg-zy-gradient {
    background: linear-gradient(135deg, #9500A5 0%, #BD00D1 60%, #FEA9FF 130%);
  }
  @utility text-zy-gradient {
    background: linear-gradient(135deg, #9500A5 0%, #BD00D1 60%, #FEA9FF 130%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
  }
</style>

<div class="zsl">

	<!-- ── Header ──────────────────────────────────────────────────────── -->
	<header class="zsl-head">
		<div class="zsl__inner">
			<h1 class="zsl-sr"><?php esc_html_e( 'Browse stores', 'zymarg-store-page' ); ?></h1>
			<p class="zsl-head__sub">
				<span class="zsl-head__line"><?php esc_html_e( 'Every seller on the marketplace, in one place.', 'zymarg-store-page' ); ?></span>
				<span class="zsl-head__line"><?php esc_html_e( 'Find a store by name, or sort to see who has the most to offer.', 'zymarg-store-page' ); ?></span>
			</p>
		</div>
	</header>

	<div class="zsl__inner">

		<!-- ── Controls ────────────────────────────────────────────────── -->
		<form class="zsl-controls" method="get" action="<?php echo esc_url( $zsl_base_url ); ?>" data-zsl-form>

			<div class="zsl-controls__group">
				<div class="zsl-controls__field zsl-controls__field--search">
					<label class="zsl-label" for="zsl-search"><?php esc_html_e( 'Search stores', 'zymarg-store-page' ); ?></label>
					<div class="zsl-search">
						<svg class="zsl-search__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
						<input
							class="zsl-search__input"
							id="zsl-search"
							type="search"
							name="store_search"
							value="<?php echo esc_attr( $zsl_search ); ?>"
							placeholder="<?php esc_attr_e( 'Search by store name', 'zymarg-store-page' ); ?>"
						>
					</div>
				</div>

				<div class="zsl-controls__field zsl-controls__field--sort">
					<label class="zsl-label" for="zsl-sort"><?php esc_html_e( 'Sort by', 'zymarg-store-page' ); ?></label>
					<select class="zsl-select" id="zsl-sort" name="store_sort" data-zsl-sort>
						<?php foreach ( $zsl_sorts as $zsl_key => $zsl_label ) : ?>
							<option value="<?php echo esc_attr( $zsl_key ); ?>" <?php selected( $zsl_sort, $zsl_key ); ?>><?php echo esc_html( $zsl_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="zsl-controls__field zsl-controls__field--submit">
					<span class="zsl-label" aria-hidden="true">&nbsp;</span>
					<button class="zsl-btn zsl-btn--primary zsl-btn--submit" type="submit">
						<svg class="zsl-icon--search" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
						<span class="zsl-btn__text"><?php esc_html_e( 'Search', 'zymarg-store-page' ); ?></span>
						<span class="zsl-sr"><?php esc_html_e( 'Search stores', 'zymarg-store-page' ); ?></span>
					</button>
				</div>

				<div class="zsl-controls__field zsl-controls__field--count">
					<span class="zsl-label" aria-hidden="true">&nbsp;</span>
					<p class="zsl-count" aria-live="polite">
				<?php
				if ( '' !== $zsl_search ) {
					printf(
						/* translators: 1: number of stores, 2: search term */
						esc_html( _n( '%1$s store matching "%2$s"', '%1$s stores matching "%2$s"', $zsl_total, 'zymarg-store-page' ) ),
						'<strong>' . esc_html( number_format_i18n( $zsl_total ) ) . '</strong>',
						esc_html( $zsl_search )
					);
				} else {
					printf(
						/* translators: %s: number of stores */
						esc_html( _n( '%s store', '%s stores', $zsl_total, 'zymarg-store-page' ) ),
						'<strong>' . esc_html( number_format_i18n( $zsl_total ) ) . '</strong>'
					);
				}
				?>
					</p>
				</div>
			</div>
		</form>

		<!-- ── Results ─────────────────────────────────────────────────── -->
		<?php if ( empty( $zsl_vendors ) ) : ?>

			<div class="zsl-empty">
				<h2 class="zsl-empty__title">
					<?php
					if ( '' !== $zsl_search ) {
						echo esc_html( sprintf( __( 'No stores match "%s"', 'zymarg-store-page' ), $zsl_search ) );
					} else {
						esc_html_e( 'No stores yet', 'zymarg-store-page' );
					}
					?>
				</h2>
				<p class="zsl-empty__text"><?php esc_html_e( 'Try a shorter search, or clear it to see every store.', 'zymarg-store-page' ); ?></p>
				<?php if ( '' !== $zsl_search ) : ?>
					<a class="zsl-btn zsl-btn--primary" href="<?php echo esc_url( remove_query_arg( array( 'store_search', 'store_page' ) ) ); ?>"><?php esc_html_e( 'Show all stores', 'zymarg-store-page' ); ?></a>
				<?php endif; ?>
			</div>

		<?php else : ?>

				<ul
					class="zsl-grid"
					data-zsl-grid
					data-zsl-paged="<?php echo esc_attr( $zsl_paged ); ?>"
					data-zsl-pages="<?php echo esc_attr( $zsl_pages ); ?>"
					data-zsl-total="<?php echo esc_attr( $zsl_total ); ?>"
					data-zsl-search="<?php echo esc_attr( $zsl_search ); ?>"
					data-zsl-sortkey="<?php echo esc_attr( $zsl_sort ); ?>"
				>
					<?php
					foreach ( $zsl_vendors as $zsl_vendor ) {
						// Same renderer the infinite-scroll endpoint uses, so an
						// appended card cannot drift from the ones above it.
						echo ZYMARG_SP_Store_Listing::render_card( $zsl_vendor ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by templates/partials/store-card.php.
					}
					?>
				</ul>

				<!-- ── Infinite scroll ─────────────────────────────────────── -->
				<?php if ( $zsl_pages > 1 ) : ?>
					<div class="zsl-infinite" data-zsl-infinite hidden>
						<div class="zsl-infinite__spinner" aria-hidden="true"></div>
						<p class="zsl-infinite__text" data-zsl-infinite-text><?php esc_html_e( 'Loading more stores…', 'zymarg-store-page' ); ?></p>
						<button class="zsl-btn zsl-btn--primary zsl-infinite__retry" type="button" data-zsl-retry hidden><?php esc_html_e( 'Try again', 'zymarg-store-page' ); ?></button>
					</div>

					<div data-zsl-sentinel aria-hidden="true"></div>
				<?php endif; ?>

				<p class="zsl-sr" data-zsl-a11y role="status" aria-live="polite"></p>

			<!-- ── Pagination ──────────────────────────────────────────── -->
			<?php if ( $zsl_pages > 1 ) : ?>
				<nav class="zsl-pager" data-zsl-pager aria-label="<?php esc_attr_e( 'Store pages', 'zymarg-store-page' ); ?>">
					<?php if ( $zsl_paged > 1 ) : ?>
						<a class="zsl-pager__link" href="<?php echo esc_url( add_query_arg( 'store_page', $zsl_paged - 1 ) ); ?>" rel="prev"><?php esc_html_e( 'Previous', 'zymarg-store-page' ); ?></a>
					<?php endif; ?>

					<?php for ( $zsl_i = 1; $zsl_i <= $zsl_pages; $zsl_i++ ) : ?>
						<a
							class="zsl-pager__link"
							href="<?php echo esc_url( add_query_arg( 'store_page', $zsl_i ) ); ?>"
							<?php echo $zsl_i === $zsl_paged ? ' aria-current="page"' : ''; ?>
						><?php echo esc_html( number_format_i18n( $zsl_i ) ); ?></a>
					<?php endfor; ?>

					<?php if ( $zsl_paged < $zsl_pages ) : ?>
						<a class="zsl-pager__link" href="<?php echo esc_url( add_query_arg( 'store_page', $zsl_paged + 1 ) ); ?>" rel="next"><?php esc_html_e( 'Next', 'zymarg-store-page' ); ?></a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>

		<?php endif; ?>

	</div>
</div>

<?php
get_footer();
