<?php
/**
 * ZYMARG Marketplace Flash Sale page template.
 *
 * Every flash-sale product running anywhere on the marketplace, on one page.
 *
 * This template owns the page chrome and nothing else. The grid, the cards,
 * the countdowns and the load-more button all come from the Product Grid
 * engine rendering the Template Pack's 'flash' card, so a Template Pack
 * update changes this page without touching this file.
 *
 * Written in plain CSS against the --zym-* brand tokens rather than Tailwind
 * utilities, unlike store.php and store-lists.php. Those two need the Tailwind
 * browser build; this page does not, so it does not load it.
 *
 * @package ZYMARG_Store_Page
 * @since   1.17.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ZYMARG_SP_Flash_Sale' ) || ! class_exists( 'ZYMARG_SP_Flash_Hero' ) ) {
	return;
}

$zfs_grid           = ZYMARG_SP_Flash_Sale::render_grid();
$zfs_engine_missing = ! ZYMARG_SP_Flash_Sale::engine_available();
$zfs_card_missing   = ! ZYMARG_SP_Flash_Sale::card_available();

get_header();
?>

<div class="zfs">

	<?php
	/*
	 * The hero. Every part of it — copy, colours, height, background, slides,
	 * countdown, or a completely different design pasted in by the owner — is
	 * resolved by ZYMARG_SP_Flash_Hero from its own settings.
	 *
	 * This used to be three hard-coded strings and a hard-coded gradient. It is
	 * a single call now so that the page chrome has exactly one opinion about
	 * the hero: where it goes. What it contains is the admin's decision.
	 *
	 * Escaping happens inside the renderer, per field: content fields through
	 * esc_html()/esc_url(), and admin-authored markup through the design engine
	 * that confines its CSS to this section.
	 */
	echo ZYMARG_SP_Flash_Hero::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>

	<div class="zfs__body">
		<?php if ( $zfs_engine_missing ) : ?>

			<?php
			/*
			 * Said out loud rather than rendered as an empty page. An admin
			 * seeing "no flash sales" when the real problem is a deactivated
			 * plugin would go looking in the wrong place entirely.
			 *
			 * Shown only to users who can act on it. A shopper gets the
			 * ordinary empty state below instead of an internal diagnostic.
			 */
			?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<div class="zfs__notice">
					<p class="zfs__notice-title"><?php esc_html_e( 'ZYMARG WC Product Grid is not active', 'zymarg-store-page' ); ?></p>
					<p><?php esc_html_e( 'This page renders its products through the Product Grid engine. Activate that plugin and the grid will appear here. Only administrators can see this message.', 'zymarg-store-page' ); ?></p>
				</div>
			<?php else : ?>
				<p class="zfs__empty"><?php esc_html_e( 'No flash sales are running at the moment. Please check back soon.', 'zymarg-store-page' ); ?></p>
			<?php endif; ?>

		<?php else : ?>

			<?php if ( $zfs_card_missing && current_user_can( 'manage_options' ) ) : ?>
				<div class="zfs__notice zfs__notice--warn">
					<p class="zfs__notice-title"><?php esc_html_e( 'ZYMARG Template Pack is not active', 'zymarg-store-page' ); ?></p>
					<p><?php esc_html_e( 'The flash card design lives in Template Pack. Without it the engine falls back to its default card, which has no countdown and no stock bar. Products below are correct; the design is not the intended one. Only administrators can see this message.', 'zymarg-store-page' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $zfs_grid ) : ?>
				<?php
				// Pre-escaped by the engine's own template layer.
				echo $zfs_grid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php else : ?>
				<?php
				/*
				 * An empty grid and a broken grid are different statements and
				 * must not look alike -- the same principle that removed the
				 * mock product catalogue in 1.16.7. Nothing is invented here to
				 * fill the space.
				 */
				?>
				<p class="zfs__empty"><?php esc_html_e( 'No flash sales are running at the moment. Please check back soon.', 'zymarg-store-page' ); ?></p>
			<?php endif; ?>

		<?php endif; ?>
	</div>

</div>

<?php
get_footer();
