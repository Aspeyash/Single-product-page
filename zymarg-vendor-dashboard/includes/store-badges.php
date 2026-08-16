<?php
/**
 * Store badges — admin-granted trust marks shown beside a vendor's store name.
 *
 * WHY THIS EXISTS
 * ---------------
 * The Store Page template used to hardcode a verified tick and an
 * "OFFICIAL STORE" pill next to every store name. That meant a brand new
 * seller with zero orders looked exactly as trustworthy as an established
 * one, which makes the marks worthless to buyers.
 *
 * Each mark is now an explicit, per-vendor grant made by the marketplace
 * admin. Nothing is inferred and nothing is automatic.
 *
 * DEFAULT IS OFF
 * --------------
 * Every badge defaults to false. After this update no store shows any badge
 * until an admin deliberately switches one on. That is the correct direction
 * to fail: a missing badge is a cosmetic loss, a wrongly granted badge is a
 * trust claim the marketplace cannot back up.
 *
 * OWNERSHIP
 * ---------
 * The Vendor Dashboard owns this data because it owns the admin vendor list.
 * The Store Page only ever reads it, behind function_exists(), so the store
 * still renders normally if this plugin is deactivated.
 *
 * @package ZYMARG_Vendor_Dashboard
 * @since   1.42.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User meta key holding the badge grants for one vendor.
 */
if ( ! defined( 'ZYMARG_VD_BADGES_META' ) ) {
	define( 'ZYMARG_VD_BADGES_META', '_zymarg_vd_store_badges' );
}

/**
 * The badges an admin can grant, in the order they appear on the store page.
 *
 * Keep this list short. It sits beside the store name, and every extra mark
 * makes the others count for less.
 *
 * @return array<string,string> Badge key => admin-facing label.
 */
function zymarg_vd_store_badge_keys() {
	$keys = array(
		'tick'            => __( 'Verified tick', 'zymarg-vendor-dashboard' ),
		'official'        => __( 'OFFICIAL STORE', 'zymarg-vendor-dashboard' ),
		'verified_seller' => __( 'Verified Seller', 'zymarg-vendor-dashboard' ),
	);

	return (array) apply_filters( 'zymarg_vd_store_badge_keys', $keys );
}

/**
 * Every badge off. Used as the default and as the shape all readers expect.
 *
 * @return array<string,bool>
 */
function zymarg_vd_store_badge_defaults() {
	$defaults = array();

	foreach ( array_keys( zymarg_vd_store_badge_keys() ) as $key ) {
		$defaults[ $key ] = false;
	}

	return $defaults;
}

/**
 * Read one vendor's badge grants.
 *
 * Always returns the full set of keys as booleans, so callers never have to
 * test isset() before reading a badge.
 *
 * @param int $vendor_id Vendor user ID.
 * @return array<string,bool>
 */
function zymarg_vd_store_badges( $vendor_id ) {
	$vendor_id = absint( $vendor_id );
	$badges    = zymarg_vd_store_badge_defaults();

	if ( $vendor_id <= 0 ) {
		return $badges;
	}

	$stored = get_user_meta( $vendor_id, ZYMARG_VD_BADGES_META, true );

	if ( is_array( $stored ) ) {
		foreach ( $badges as $key => $unused ) {
			$badges[ $key ] = ! empty( $stored[ $key ] );
		}
	}

	/**
	 * Filter a vendor's resolved badges.
	 *
	 * @param array<string,bool> $badges    Resolved badge flags.
	 * @param int                $vendor_id Vendor user ID.
	 */
	return (array) apply_filters( 'zymarg_vd_store_badges', $badges, $vendor_id );
}

/**
 * Is a single badge granted to this vendor?
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $key       Badge key.
 * @return bool
 */
function zymarg_vd_store_badge_enabled( $vendor_id, $key ) {
	$badges = zymarg_vd_store_badges( $vendor_id );

	return ! empty( $badges[ $key ] );
}

/**
 * Save a vendor's badge grants.
 *
 * Only known keys are written, so a crafted request cannot inject arbitrary
 * meta. The full set is always written, which makes un-ticking a box actually
 * revoke the badge rather than silently leaving the old value in place.
 *
 * @param int   $vendor_id Vendor user ID.
 * @param array $raw       Raw key => truthy map from the request.
 * @return array<string,bool> The stored flags.
 */
function zymarg_vd_update_store_badges( $vendor_id, $raw ) {
	$vendor_id = absint( $vendor_id );

	if ( $vendor_id <= 0 ) {
		return zymarg_vd_store_badge_defaults();
	}

	$raw   = is_array( $raw ) ? $raw : array();
	$clean = array();

	foreach ( array_keys( zymarg_vd_store_badge_keys() ) as $key ) {
		$value = isset( $raw[ $key ] ) ? $raw[ $key ] : false;

		// Accept the usual truthy transports: true, 1, "1", "true", "yes", "on".
		if ( is_string( $value ) ) {
			$value = in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}

		$clean[ $key ] = (bool) $value;
	}

	update_user_meta( $vendor_id, ZYMARG_VD_BADGES_META, $clean );

	/**
	 * Fires after a vendor's badges change.
	 *
	 * @param int                $vendor_id Vendor user ID.
	 * @param array<string,bool> $clean     The stored flags.
	 */
	do_action( 'zymarg_vd_store_badges_updated', $vendor_id, $clean );

	return $clean;
}

/**
 * Render the badge checkboxes inside an admin vendor card.
 *
 * Uses the card's own field classes so it inherits the surrounding styling
 * instead of introducing a competing look.
 *
 * @param int $vendor_id Vendor user ID.
 * @return void
 */
function zymarg_vd_render_store_badge_fields( $vendor_id ) {
	$vendor_id = absint( $vendor_id );

	if ( $vendor_id <= 0 ) {
		return;
	}

	$badges = zymarg_vd_store_badges( $vendor_id );
	?>
	<div class="zymarg-vendor-card__badges">
		<span class="zymarg-vendor-card__label"><?php esc_html_e( 'Store page badges', 'zymarg-vendor-dashboard' ); ?></span>
		<p class="zvd-help zvd-m-0"><?php esc_html_e( 'Shown beside this store name. Off by default.', 'zymarg-vendor-dashboard' ); ?></p>

		<div class="zymarg-vendor-card__badge-list">
			<?php foreach ( zymarg_vd_store_badge_keys() as $key => $label ) : ?>
				<label class="zymarg-vendor-card__badge-toggle" for="zvd-badge-<?php echo esc_attr( $key . '-' . $vendor_id ); ?>">
					<input
						type="checkbox"
						class="zymarg-store-badge"
						id="zvd-badge-<?php echo esc_attr( $key . '-' . $vendor_id ); ?>"
						data-badge="<?php echo esc_attr( $key ); ?>"
						value="1"
						<?php checked( ! empty( $badges[ $key ] ) ); ?>
					/>
					<span><?php echo esc_html( $label ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}
