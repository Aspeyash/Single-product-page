<?php
/**
 * One store card.
 *
 * The single source of the card markup. Both the first server-rendered page
 * and every page appended by infinite scroll go through this file, so the two
 * cannot drift apart -- which is the usual way a "load more" feature starts
 * quietly rendering something slightly different from the page underneath it.
 *
 * Expects $c, the array returned by ZYMARG_SP_Store_Listing::card_data().
 *
 * @package ZYMARG_Store_Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $c ) || ! is_array( $c ) ) {
	return;
}
?>
<li>
	<article class="zsl-card<?php echo $c['on_vacation'] ? ' zsl-card--vacation' : ''; ?>">

		<div class="zsl-card__media">
			<?php if ( $c['banner'] ) : ?>
				<img src="<?php echo esc_url( $c['banner'] ); ?>" alt="" loading="lazy" decoding="async">
			<?php endif; ?>

			<div class="zsl-card__logo">
				<?php if ( $c['logo'] ) : ?>
					<img src="<?php echo esc_url( $c['logo'] ); ?>" alt="" loading="lazy" decoding="async">
				<?php else : ?>
					<?php echo esc_html( $c['initial'] ); ?>
				<?php endif; ?>
			</div>
		</div>

		<div class="zsl-card__body">

			<div class="zsl-card__nameline">
				<h2 class="zsl-card__name">
					<a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a>
				</h2>
				<?php if ( $c['on_vacation'] ) : ?>
					<span class="zsl-pill zsl-pill--vacation"><?php esc_html_e( 'On vacation', 'zymarg-store-page' ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( $c['badges'] ) : ?>
				<div class="zsl-card__badges"><?php echo $c['badges']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by zymarg_sp_store_badge_row(). ?></div>
			<?php endif; ?>

			<?php if ( $c['tagline'] ) : ?>
				<p class="zsl-card__tagline"><?php echo esc_html( $c['tagline'] ); ?></p>
			<?php endif; ?>

			<?php if ( $c['on_vacation'] && $c['vacation_msg'] ) : ?>
				<p class="zsl-card__vacation">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
					<span><?php echo esc_html( $c['vacation_msg'] ); ?></span>
				</p>
			<?php endif; ?>

			<div class="zsl-card__meta">
				<?php if ( $c['location'] ) : ?>
					<span>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
						<?php echo esc_html( $c['location'] ); ?>
					</span>
				<?php endif; ?>

				<span data-zsl-followers="<?php echo esc_attr( $c['id'] ); ?>">
					<strong><?php echo esc_html( number_format_i18n( $c['followers'] ) ); ?></strong>
					<?php echo esc_html( _n( 'follower', 'followers', $c['followers'], 'zymarg-store-page' ) ); ?>
				</span>

				<span>
					<strong><?php echo esc_html( number_format_i18n( $c['products'] ) ); ?></strong>
					<?php echo esc_html( _n( 'product', 'products', $c['products'], 'zymarg-store-page' ) ); ?>
				</span>

				<span><?php echo esc_html( sprintf( __( 'Since %s', 'zymarg-store-page' ), $c['member_since'] ) ); ?></span>

				<?php if ( null !== $c['response_rate'] ) : ?>
					<span class="zsl-card__response">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10.5h8M8 14h5m-9 6.5 2.5-3H18a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v14Z"/></svg>
						<?php
						printf(
							/* translators: %s: percentage */
							esc_html__( 'Responds to %s%% of messages', 'zymarg-store-page' ),
							esc_html( number_format_i18n( $c['response_rate'] ) )
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<div class="zsl-card__actions">
				<a class="zsl-btn zsl-btn--primary" href="<?php echo esc_url( $c['url'] ); ?>">
					<?php esc_html_e( 'Visit Store', 'zymarg-store-page' ); ?>
					<span class="zsl-sr"><?php echo esc_html( $c['name'] ); ?></span>
				</a>

				<button
					class="zsl-btn zsl-btn--follow"
					type="button"
					data-zsl-follow="<?php echo esc_attr( $c['id'] ); ?>"
					aria-pressed="<?php echo $c['is_following'] ? 'true' : 'false'; ?>"
				>
					<svg class="zsl-icon--plus" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
					<svg class="zsl-icon--check" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
					<span data-zsl-follow-label><?php echo $c['is_following'] ? esc_html__( 'Following', 'zymarg-store-page' ) : esc_html__( 'Follow', 'zymarg-store-page' ); ?></span>
					<span class="zsl-sr"><?php echo esc_html( $c['name'] ); ?></span>
				</button>
			</div>

		</div>
	</article>
</li>
