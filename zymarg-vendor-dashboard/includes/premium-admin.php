<?php
/**
 * ZYMARG Vendor Dashboard -- Premium admin screen (PHASE 2).
 *
 * A Vendor Hub sub-screen with two jobs:
 *
 *   1. The master switches. Flash Sale and Featured Items are off until an
 *      admin turns them on. Nothing about Premium is visible to any vendor
 *      while a switch is off.
 *   2. The approval queue. Vendors request access; an admin approves or
 *      rejects, optionally with a note the vendor sees.
 *
 * All state lives in premium.php -- this file never reads or writes meta
 * directly, it only calls the helpers. That keeps the approval rules in one
 * place, so the vendor dashboard, the store page and this screen can never
 * disagree about who is allowed to do what.
 *
 * Design: registered in the hub section map, so it loads over AJAX like every
 * other screen. Markup uses existing .zvd-* classes plus the WordPress core
 * primitive layer added in v1.39.3. No inline styles, no raw colours.
 *
 * @package ZYMARG_Vendor_Dashboard
 * @since   1.40.0
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------- *
 * 1. MENU + HUB REGISTRATION
 * ---------------------------------------------------------------------- */

/**
 * Register the Premium sub-screen under Vendor Hub.
 *
 * @return void
 */
function zymarg_vd_premium_register_admin_menu() {
	add_submenu_page(
		'zymarg-vendor-hub',
		__( 'Premium', 'zymarg-vendor-dashboard' ),
		zymarg_vd_premium_menu_title_with_badge( __( 'Premium', 'zymarg-vendor-dashboard' ) ),
		'manage_options',
		'zymarg-vd-premium',
		'zymarg_vd_premium_render_admin_page'
	);
}
add_action( 'admin_menu', 'zymarg_vd_premium_register_admin_menu' );

/**
 * Add Premium to the hub's AJAX section map.
 *
 * Without this the screen would fall through to a hard browser navigation --
 * exactly the "some sections reload" behaviour fixed in v1.39.0.
 *
 * @param array $map Slug => array( callback, cap ).
 * @return array
 */
function zymarg_vd_premium_register_section( $map ) {
	$map['zymarg-vd-premium'] = array(
		'callback' => 'zymarg_vd_premium_render_admin_page',
		'cap'      => 'manage_options',
	);
	return $map;
}
add_filter( 'zymarg_vd_admin_sections', 'zymarg_vd_premium_register_section' );

/**
 * Enqueue the Premium screen's script.
 *
 * Runs on its own hook, and is also called by the hub on every other hub
 * screen through the section-enqueuers filter below, so an AJAX-swapped
 * Premium screen arrives fully wired.
 *
 * @param string $hook_suffix Current admin hook suffix.
 * @return void
 */
function zymarg_vd_premium_admin_enqueue( $hook_suffix ) {
	if ( 'vendor-hub_page_zymarg-vd-premium' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_script(
		'zymarg-vd-admin-premium',
		ZYMARG_VD_URL . 'assets/js/admin-premium.js',
		array( 'jquery' ),
		ZYMARG_VD_VERSION,
		true
	);

	wp_localize_script(
		'zymarg-vd-admin-premium',
		'ZymargPremiumAdmin',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zymarg_vd_premium_admin' ),
			'i18n'    => array(
				'saving'    => __( 'Saving', 'zymarg-vendor-dashboard' ),
				'saved'     => __( 'Saved.', 'zymarg-vendor-dashboard' ),
				'failed'    => __( 'That did not save. Try again.', 'zymarg-vendor-dashboard' ),
				'working'   => __( 'Working', 'zymarg-vendor-dashboard' ),
				'approved'  => __( 'Approved.', 'zymarg-vendor-dashboard' ),
				'rejected'  => __( 'Rejected.', 'zymarg-vendor-dashboard' ),
				'revoked'   => __( 'Access revoked.', 'zymarg-vendor-dashboard' ),
				'network'   => __( 'Network error.', 'zymarg-vendor-dashboard' ),
				'confirmRevoke' => __( 'Revoke this vendor\'s access? Their products stay saved but stop showing.', 'zymarg-vendor-dashboard' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'zymarg_vd_premium_admin_enqueue' );

/**
 * Tell the hub to load the Premium script on every hub screen.
 *
 * @param array $enqueuers Hook suffix => callback.
 * @return array
 */
function zymarg_vd_premium_section_enqueuer( $enqueuers ) {
	$enqueuers['vendor-hub_page_zymarg-vd-premium'] = 'zymarg_vd_premium_admin_enqueue';
	return $enqueuers;
}
add_filter( 'zymarg_vd_admin_section_enqueuers', 'zymarg_vd_premium_section_enqueuer' );

/* ---------------------------------------------------------------------- *
 * 2. SCREEN RENDER
 * ---------------------------------------------------------------------- */

/**
 * A vendor's display name for the queue tables.
 *
 * Prefers the Dokan store name, because that is what an admin recognises.
 *
 * @param int $vendor_id Vendor user ID.
 * @return string
 */
function zymarg_vd_premium_vendor_name( $vendor_id ) {
	$vendor_id = (int) $vendor_id;

	if ( function_exists( 'dokan_get_store_info' ) ) {
		$info = dokan_get_store_info( $vendor_id );
		if ( ! empty( $info['store_name'] ) ) {
			return (string) $info['store_name'];
		}
	}

	$user = get_userdata( $vendor_id );
	return $user ? $user->display_name : __( 'Unknown vendor', 'zymarg-vendor-dashboard' );
}

/**
 * Every vendor currently approved for a functionality.
 *
 * @param string $feature Feature key.
 * @return array<int,int> Vendor user IDs.
 */
function zymarg_vd_premium_approved_vendors( $feature ) {
	$key = zymarg_vd_premium_state_meta_key( $feature );
	if ( '' === $key ) {
		return array();
	}

	$ids = get_users(
		array(
			'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'fields'     => 'ID',
			'number'     => 500,
		)
	);

	$out = array();
	foreach ( $ids as $vendor_id ) {
		if ( zymarg_vd_premium_vendor_can_use( (int) $vendor_id, $feature ) ) {
			$out[] = (int) $vendor_id;
		}
	}

	return $out;
}

/**
 * Render the Premium admin screen.
 *
 * @return void
 */
function zymarg_vd_premium_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'zymarg-vendor-dashboard' ) );
	}

	$settings = zymarg_vd_premium_settings();
	$pending  = zymarg_vd_premium_pending_requests();

	zymarg_vd_admin_shell_open();
	zymarg_vd_admin_header(
		__( 'Premium', 'zymarg-vendor-dashboard' ),
		__( 'Flash Sale and Featured Items for vendors, unlocked one vendor at a time.', 'zymarg-vendor-dashboard' )
	);
	zymarg_vd_admin_back_link();
	?>

	<div class="zvd-card">
		<h2 class="zvd-section-title"><?php esc_html_e( 'Master switches', 'zymarg-vendor-dashboard' ); ?></h2>

		<p class="zvd-body-text">
			<?php esc_html_e( 'Turn a functionality on to offer it to your vendors. While a switch is off, no vendor sees it and no request can be made. Turning a switch off later hides the functionality everywhere without deleting anything vendors have set up.', 'zymarg-vendor-dashboard' ); ?>
		</p>

		<form id="zvd-premium-switches">
			<?php foreach ( zymarg_vd_premium_features() as $feature ) : ?>
				<div class="zvd-check-row">
					<label class="zvd-toggle-label">
						<input
							type="checkbox"
							name="<?php echo esc_attr( $feature ); ?>"
							value="1"
							<?php checked( ! empty( $settings[ $feature ] ) ); ?>
						/>
						<?php echo esc_html( zymarg_vd_premium_label( $feature ) ); ?>
					</label>
					<p class="zvd-hint">
						<?php
						if ( ZYMARG_VD_PREMIUM_FLASH === $feature ) {
							esc_html_e( 'Vendors can run a timed discount on their own products. These never appear in the Product Grid or on your homepage.', 'zymarg-vendor-dashboard' );
						} else {
							esc_html_e( 'Vendors can highlight chosen products on their store page. This does not use the WooCommerce Featured flag, so it stays out of your homepage.', 'zymarg-vendor-dashboard' );
						}
						?>
					</p>
				</div>
			<?php endforeach; ?>

			<div class="zvd-save-row">
				<button type="submit" class="zvd-btn zvd-btn--primary">
					<?php esc_html_e( 'Save switches', 'zymarg-vendor-dashboard' ); ?>
				</button>
				<span class="zvd-status-msg" id="zvd-premium-switch-status" aria-live="polite"></span>
			</div>
		</form>
	</div>

	<?php $display = zymarg_vd_premium_display_settings(); ?>

	<div class="zvd-card">
		<h2 class="zvd-section-title"><?php esc_html_e( 'Limits and display', 'zymarg-vendor-dashboard' ); ?></h2>

		<p class="zvd-body-text">
			<?php esc_html_e( 'You decide how many products a vendor may promote and how those products move on the store page. Vendors choose which products, never how many and never how fast.', 'zymarg-vendor-dashboard' ); ?>
		</p>

		<form id="zvd-premium-display">
			<h3 class="zvd-section-heading"><?php esc_html_e( 'How many products', 'zymarg-vendor-dashboard' ); ?></h3>

			<div class="zvd-field zvd-narrow">
				<label class="zvd-label" for="zvd-featured-min">
					<?php esc_html_e( 'Featured Items: minimum to go live', 'zymarg-vendor-dashboard' ); ?>
				</label>
				<input type="number" id="zvd-featured-min" name="featured_min" min="0" max="50" step="1"
					value="<?php echo esc_attr( $display['featured_min'] ); ?>" />
				<p class="zvd-hint">
					<?php esc_html_e( 'The Featured section stays hidden on the store page until the vendor has picked at least this many. They can still save fewer while they build the list up. Set 0 to remove the requirement.', 'zymarg-vendor-dashboard' ); ?>
				</p>
			</div>

			<div class="zvd-field zvd-narrow">
				<label class="zvd-label" for="zvd-featured-max">
					<?php esc_html_e( 'Featured Items: maximum', 'zymarg-vendor-dashboard' ); ?>
				</label>
				<input type="number" id="zvd-featured-max" name="featured_max" min="1" max="50" step="1"
					value="<?php echo esc_attr( $display['featured_max'] ); ?>" />
			</div>

			<div class="zvd-field zvd-narrow">
				<label class="zvd-label" for="zvd-flash-max">
					<?php esc_html_e( 'Flash Sale: maximum', 'zymarg-vendor-dashboard' ); ?>
				</label>
				<input type="number" id="zvd-flash-max" name="flash_max" min="1" max="50" step="1"
					value="<?php echo esc_attr( $display['flash_max'] ); ?>" />
				<p class="zvd-hint">
					<?php esc_html_e( 'Flash Sale has no minimum. A single timed discount is a perfectly good offer.', 'zymarg-vendor-dashboard' ); ?>
				</p>
			</div>

			<hr class="zvd-rule" />

			<h3 class="zvd-section-heading"><?php esc_html_e( 'How they show', 'zymarg-vendor-dashboard' ); ?></h3>

			<div class="zvd-field">
				<span class="zvd-label"><?php esc_html_e( 'Layout', 'zymarg-vendor-dashboard' ); ?></span>
				<div class="zvd-check-row">
					<label class="zvd-toggle-label">
						<input type="radio" name="layout" value="grid" <?php checked( 'grid', $display['layout'] ); ?> />
						<?php esc_html_e( 'Grid -- everything visible, nothing moves', 'zymarg-vendor-dashboard' ); ?>
					</label>
				</div>
				<div class="zvd-check-row">
					<label class="zvd-toggle-label">
						<input type="radio" name="layout" value="carousel" <?php checked( 'carousel', $display['layout'] ); ?> />
						<?php esc_html_e( 'Carousel -- rotates automatically', 'zymarg-vendor-dashboard' ); ?>
					</label>
				</div>
				<p class="zvd-hint">
					<?php esc_html_e( 'This applies to every vendor store page.', 'zymarg-vendor-dashboard' ); ?>
				</p>
			</div>

			<?php
			/*
			 * v1.46.14: Grid column counts.
			 *
			 * Only meaningful when Layout above is Grid -- Carousel has no
			 * column count of its own, the same way marquee/glide speed above
			 * only meaningfully apply to Carousel. This screen does not hide
			 * fields based on the Layout/Rotation selection anywhere else, so
			 * these three follow that same always-visible-with-a-hint pattern
			 * rather than introducing a new show/hide behaviour just for them.
			 * Shared between Flash Sale and Featured Items: both sections
			 * render on the same store page grid, so one set of three numbers
			 * covers both.
			 */
			?>
			<div class="zvd-field zvd-narrow">
				<label class="zvd-label" for="zvd-columns-desktop">
					<?php esc_html_e( 'Columns: desktop', 'zymarg-vendor-dashboard' ); ?>
				</label>
				<input type="number" id="zvd-columns-desktop" name="columns_desktop" min="1" max="6" step="1"
					value="<?php echo esc_attr( $display['columns_desktop'] ); ?>" />
			</div>

			<div class="zvd-field zvd-narrow">
				<label class="zvd-label" for="zvd-columns-tablet">
					<?php esc_html_e( 'Columns: tablet', 'zymarg-vendor-dashboard' ); ?>
				</label>
				<input type="number" id="zvd-columns-tablet" name="columns_tablet" min="1" max="6" step="1"
					value="<?php echo esc_attr( $display['columns_tablet'] ); ?>" />
			</div>

			<div class="zvd-field zvd-narrow">
				<label class="zvd-label" for="zvd-columns-mobile">
					<?php esc_html_e( 'Columns: mobile', 'zymarg-vendor-dashboard' ); ?>
				</label>
				<input type="number" id="zvd-columns-mobile" name="columns_mobile" min="1" max="6" step="1"
					value="<?php echo esc_attr( $display['columns_mobile'] ); ?>" />
				<p class="zvd-hint">
					<?php esc_html_e( '1 to 6 for each. Used by Flash Sale and Featured Items together on the store page grid. Only applies when Layout above is Grid.', 'zymarg-vendor-dashboard' ); ?>
				</p>
			</div>

			<div class="zvd-field">
				<span class="zvd-label"><?php esc_html_e( 'Rotation style', 'zymarg-vendor-dashboard' ); ?></span>
				<div class="zvd-check-row">
					<label class="zvd-toggle-label">
						<input type="radio" name="rotation" value="step" <?php checked( 'step', $display['rotation'] ); ?> />
						<?php esc_html_e( 'Step -- move one screen, wait, move again', 'zymarg-vendor-dashboard' ); ?>
					</label>
				</div>
				<div class="zvd-check-row">
					<label class="zvd-toggle-label">
						<input type="radio" name="rotation" value="continuous" <?php checked( 'continuous', $display['rotation'] ); ?> />
						<?php esc_html_e( 'Continuous -- drifts steadily, like a ticker', 'zymarg-vendor-dashboard' ); ?>
					</label>
				</div>
				<p class="zvd-hint">
					<?php esc_html_e( 'Only used when the layout is Carousel. This matches the rotation styles on your homepage sections.', 'zymarg-vendor-dashboard' ); ?>
				</p>
			</div>

			<div class="zvd-field zvd-narrow">
				<label class="zvd-label" for="zvd-marquee-speed">
					<?php esc_html_e( 'Continuous speed (pixels per second)', 'zymarg-vendor-dashboard' ); ?>
				</label>
				<input type="number" id="zvd-marquee-speed" name="marquee_speed" min="10" max="200" step="1"
					value="<?php echo esc_attr( $display['marquee_speed'] ); ?>" />
				<p class="zvd-hint">
					<?php esc_html_e( '10 to 200, default 40. Around 40 reads as a gentle drift.', 'zymarg-vendor-dashboard' ); ?>
				</p>
			</div>

			<div class="zvd-field zvd-narrow">
				<label class="zvd-label" for="zvd-glide-speed">
					<?php esc_html_e( 'Glide speed (milliseconds)', 'zymarg-vendor-dashboard' ); ?>
				</label>
				<input type="number" id="zvd-glide-speed" name="glide_speed" min="100" max="3000" step="10"
					value="<?php echo esc_attr( $display['glide_speed'] ); ?>" />
				<p class="zvd-hint">
					<?php esc_html_e( '100 to 3000, default 400. How long one slide movement takes, not the wait in between. The Continuous style does not use it.', 'zymarg-vendor-dashboard' ); ?>
				</p>
			</div>

			<div class="zvd-save-row">
				<button type="submit" class="zvd-btn zvd-btn--primary">
					<?php esc_html_e( 'Save limits and display', 'zymarg-vendor-dashboard' ); ?>
				</button>
				<span class="zvd-status-msg" id="zvd-premium-display-status" aria-live="polite"></span>
			</div>
		</form>
	</div>

	<div class="zvd-card">
		<h2 class="zvd-section-title">
			<?php esc_html_e( 'Approval queue', 'zymarg-vendor-dashboard' ); ?>
		</h2>

		<div id="zvd-premium-queue">
			<?php if ( empty( $pending ) ) : ?>
				<p class="zvd-empty">
					<?php esc_html_e( 'No requests waiting. When a vendor asks for a functionality, it appears here.', 'zymarg-vendor-dashboard' ); ?>
				</p>
			<?php else : ?>
				<table class="zvd-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Vendor', 'zymarg-vendor-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Requested', 'zymarg-vendor-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Functionality', 'zymarg-vendor-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Note to vendor', 'zymarg-vendor-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Decision', 'zymarg-vendor-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $pending as $row ) : ?>
						<tr class="zvd-premium-row"
							data-vendor="<?php echo esc_attr( $row['vendor_id'] ); ?>"
							data-feature="<?php echo esc_attr( $row['feature'] ); ?>">
							<td><?php echo esc_html( zymarg_vd_premium_vendor_name( $row['vendor_id'] ) ); ?></td>
							<td><?php echo esc_html( $row['state']['requested_at'] ); ?></td>
							<td><?php echo esc_html( zymarg_vd_premium_label( $row['feature'] ) ); ?></td>
							<td>
								<label class="screen-reader-text" for="zvd-note-<?php echo esc_attr( $row['vendor_id'] . '-' . $row['feature'] ); ?>">
									<?php esc_html_e( 'Optional note to the vendor', 'zymarg-vendor-dashboard' ); ?>
								</label>
								<input
									type="text"
									class="zvd-premium-note"
									id="zvd-note-<?php echo esc_attr( $row['vendor_id'] . '-' . $row['feature'] ); ?>"
									placeholder="<?php esc_attr_e( 'Optional', 'zymarg-vendor-dashboard' ); ?>"
								/>
							</td>
							<td>
								<button type="button" class="zvd-btn zvd-btn--primary zvd-premium-approve">
									<?php esc_html_e( 'Approve', 'zymarg-vendor-dashboard' ); ?>
								</button>
								<button type="button" class="zvd-btn zvd-btn--secondary zvd-premium-reject">
									<?php esc_html_e( 'Reject', 'zymarg-vendor-dashboard' ); ?>
								</button>
								<span class="zvd-status-msg zvd-premium-row-status" aria-live="polite"></span>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<?php foreach ( zymarg_vd_premium_features() as $feature ) : ?>
		<?php $approved = zymarg_vd_premium_approved_vendors( $feature ); ?>
		<div class="zvd-card">
			<h2 class="zvd-section-title">
				<?php
				/* translators: %s: functionality name. */
				printf( esc_html__( 'Approved for %s', 'zymarg-vendor-dashboard' ), esc_html( zymarg_vd_premium_label( $feature ) ) );
				?>
			</h2>

			<?php if ( empty( $settings[ $feature ] ) ) : ?>
				<p class="zvd-notice">
					<?php esc_html_e( 'The master switch for this functionality is off, so approved vendors cannot use it right now.', 'zymarg-vendor-dashboard' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( empty( $approved ) ) : ?>
				<p class="zvd-empty"><?php esc_html_e( 'No vendors approved yet.', 'zymarg-vendor-dashboard' ); ?></p>
			<?php else : ?>
				<table class="zvd-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Vendor', 'zymarg-vendor-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Approved on', 'zymarg-vendor-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Limits for this vendor', 'zymarg-vendor-dashboard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action', 'zymarg-vendor-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $approved as $vendor_id ) : ?>
						<?php $state = zymarg_vd_premium_get_state( $vendor_id, $feature ); ?>
						<tr class="zvd-premium-row"
							data-vendor="<?php echo esc_attr( $vendor_id ); ?>"
							data-feature="<?php echo esc_attr( $feature ); ?>">
							<td><?php echo esc_html( zymarg_vd_premium_vendor_name( $vendor_id ) ); ?></td>
							<td><?php echo esc_html( $state['decided_at'] ); ?></td>
							<td>
								<?php
								$overrides = zymarg_vd_premium_vendor_limit_overrides( $vendor_id );
								$row_id    = esc_attr( $vendor_id . '-' . $feature );
								?>
								<?php if ( ZYMARG_VD_PREMIUM_FEATURED === $feature ) : ?>
									<label class="zvd-label" for="zvd-lim-min-<?php echo $row_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
										<?php esc_html_e( 'Minimum', 'zymarg-vendor-dashboard' ); ?>
									</label>
									<input type="number" min="0" max="50" step="1"
										id="zvd-lim-min-<?php echo $row_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
										class="zvd-premium-limit" data-key="featured_min"
										placeholder="<?php echo esc_attr( $display['featured_min'] ); ?>"
										value="<?php echo esc_attr( null === $overrides['featured_min'] ? '' : $overrides['featured_min'] ); ?>" />

									<label class="zvd-label" for="zvd-lim-max-<?php echo $row_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
										<?php esc_html_e( 'Maximum', 'zymarg-vendor-dashboard' ); ?>
									</label>
									<input type="number" min="1" max="50" step="1"
										id="zvd-lim-max-<?php echo $row_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
										class="zvd-premium-limit" data-key="featured_max"
										placeholder="<?php echo esc_attr( $display['featured_max'] ); ?>"
										value="<?php echo esc_attr( null === $overrides['featured_max'] ? '' : $overrides['featured_max'] ); ?>" />
								<?php else : ?>
									<label class="zvd-label" for="zvd-lim-flash-<?php echo $row_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
										<?php esc_html_e( 'Maximum', 'zymarg-vendor-dashboard' ); ?>
									</label>
									<input type="number" min="1" max="50" step="1"
										id="zvd-lim-flash-<?php echo $row_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
										class="zvd-premium-limit" data-key="flash_max"
										placeholder="<?php echo esc_attr( $display['flash_max'] ); ?>"
										value="<?php echo esc_attr( null === $overrides['flash_max'] ? '' : $overrides['flash_max'] ); ?>" />
								<?php endif; ?>

								<button type="button" class="zvd-btn zvd-btn--secondary zvd-premium-save-limits">
									<?php esc_html_e( 'Save limits', 'zymarg-vendor-dashboard' ); ?>
								</button>
								<p class="zvd-hint">
									<?php esc_html_e( 'Leave blank to use the settings above.', 'zymarg-vendor-dashboard' ); ?>
								</p>
							</td>
							<td>
								<button type="button" class="zvd-btn zvd-btn--danger zvd-premium-revoke">
									<?php esc_html_e( 'Revoke', 'zymarg-vendor-dashboard' ); ?>
								</button>
								<span class="zvd-status-msg zvd-premium-row-status" aria-live="polite"></span>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<?php
	zymarg_vd_admin_shell_close();
}

/* ---------------------------------------------------------------------- *
 * 3. AJAX ENDPOINTS
 * ---------------------------------------------------------------------- */

/**
 * Shared guard for every Premium admin AJAX call.
 *
 * @return void Dies with a JSON error when the caller is not allowed.
 */
function zymarg_vd_premium_admin_ajax_guard() {
	check_ajax_referer( 'zymarg_vd_premium_admin', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
}

/**
 * Read and validate the vendor + feature pair from the request.
 *
 * @return array{0:int,1:string} Vendor ID and feature key.
 */
function zymarg_vd_premium_admin_ajax_target() {
	$vendor_id = isset( $_POST['vendor'] ) ? absint( wp_unslash( $_POST['vendor'] ) ) : 0;
	$feature   = isset( $_POST['feature'] ) ? sanitize_key( wp_unslash( $_POST['feature'] ) ) : '';

	if ( $vendor_id <= 0 || ! zymarg_vd_premium_is_feature( $feature ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid request.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	return array( $vendor_id, $feature );
}

/**
 * AJAX: save the master switches.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_save_switches() {
	zymarg_vd_premium_admin_ajax_guard();

	$switches = array();
	foreach ( zymarg_vd_premium_features() as $feature ) {
		$switches[ $feature ] = ! empty( $_POST[ $feature ] );
	}

	$saved = zymarg_vd_premium_update_settings( $switches );

	wp_send_json_success(
		array(
			'message'  => __( 'Saved.', 'zymarg-vendor-dashboard' ),
			'switches' => $saved,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_premium_save_switches', 'zymarg_vd_premium_ajax_save_switches' );

/**
 * AJAX: approve a request.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_approve() {
	zymarg_vd_premium_admin_ajax_guard();
	list( $vendor_id, $feature ) = zymarg_vd_premium_admin_ajax_target();

	$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

	zymarg_vd_premium_approve( $vendor_id, $feature, $note );

	wp_send_json_success( array( 'message' => __( 'Approved.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_premium_approve', 'zymarg_vd_premium_ajax_approve' );

/**
 * AJAX: reject a request.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_reject() {
	zymarg_vd_premium_admin_ajax_guard();
	list( $vendor_id, $feature ) = zymarg_vd_premium_admin_ajax_target();

	$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

	zymarg_vd_premium_reject( $vendor_id, $feature, $note );

	wp_send_json_success( array( 'message' => __( 'Rejected.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_premium_reject', 'zymarg_vd_premium_ajax_reject' );

/**
 * AJAX: revoke an approved vendor.
 *
 * Sends the vendor back to 'off' rather than 'rejected', so they can ask
 * again if the situation changes.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_revoke() {
	zymarg_vd_premium_admin_ajax_guard();
	list( $vendor_id, $feature ) = zymarg_vd_premium_admin_ajax_target();

	zymarg_vd_premium_set_state(
		$vendor_id,
		$feature,
		array(
			'status'     => ZYMARG_VD_PREMIUM_OFF,
			'decided_at' => current_time( 'mysql' ),
			'decided_by' => get_current_user_id(),
			'note'       => '',
		)
	);

	wp_send_json_success( array( 'message' => __( 'Access revoked.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_premium_revoke', 'zymarg_vd_premium_ajax_revoke' );

/* ---------------------------------------------------------------------- *
 * 4. LIMITS AND DISPLAY ENDPOINTS                             v1.41.0
 * ---------------------------------------------------------------------- */

/**
 * AJAX: save the global limits and display settings.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_save_display() {
	zymarg_vd_premium_admin_ajax_guard();

	$raw = array();
	foreach ( array( 'featured_min', 'featured_max', 'flash_max', 'layout', 'rotation', 'marquee_speed', 'glide_speed', 'columns_desktop', 'columns_tablet', 'columns_mobile' ) as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			$raw[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}
	}

	// The sanitizer clamps every value into range, so what comes back is what
	// was actually stored. Send it to the browser and let the form re-sync,
	// otherwise an out-of-range entry stays on screen looking accepted.
	$saved = zymarg_vd_premium_update_display_settings( $raw );

	wp_send_json_success(
		array(
			'message'  => __( 'Saved.', 'zymarg-vendor-dashboard' ),
			'settings' => $saved,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_premium_save_display', 'zymarg_vd_premium_ajax_save_display' );

/**
 * Feed the live pending-request count into WordPress's own Heartbeat tick.
 *
 * Heartbeat is core's own polling loop (already running on every admin
 * screen for post-lock and autosave), so this rides that existing loop
 * instead of adding a second independent setInterval/AJAX cycle. It ticks
 * every 15-60 seconds depending on wp-admin's own Heartbeat interval setting
 * -- close to real time, but not sub-second, exactly like WordPress core's
 * own Plugins-update bubble and WooCommerce's own order-count bubble, both
 * of which refresh no faster than a page load unless something like this
 * hooks Heartbeat for them.
 *
 * Scoped to admin-side Heartbeat only ('admin_enqueue_scripts' registers the
 * script with 'heartbeat' as a dependency below) so this never fires on the
 * front end, where Heartbeat can also run for other purposes.
 *
 * @param array $response Existing Heartbeat response payload.
 * @return array Response with the pending count appended.
 */
function zymarg_vd_premium_heartbeat_received( $response ) {
	$response['zymarg_vd_premium_pending'] = zymarg_vd_premium_pending_count();

	return $response;
}
add_filter( 'heartbeat_received', 'zymarg_vd_premium_heartbeat_received' );

/**
 * Also answer the very first tick, which core sends before any handler has
 * run once -- 'heartbeat_send' fires unconditionally on every tick, whereas
 * 'heartbeat_received' only fires when the browser's tick payload already
 * contains a matching key. Filtering both means the badge starts updating
 * from the very first Heartbeat tick after page load, not the second one.
 *
 * @param array $response Existing Heartbeat response payload.
 * @return array Response with the pending count appended.
 */
add_filter( 'heartbeat_send', 'zymarg_vd_premium_heartbeat_received' );

/**
 * AJAX: save one vendor's limit overrides.
 *
 * Only the keys the row actually submitted are touched. A Flash Sale row
 * carries flash_max alone, so rewriting the whole override set from that
 * payload would silently wipe the same vendor's Featured limits.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_save_vendor_limits() {
	zymarg_vd_premium_admin_ajax_guard();

	$vendor_id = isset( $_POST['vendor'] ) ? (int) $_POST['vendor'] : 0;
	if ( $vendor_id <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'Unknown vendor.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	$submitted = isset( $_POST['limits'] ) ? (array) wp_unslash( $_POST['limits'] ) : array();

	// Start from what is already stored, then overlay only the submitted keys.
	$existing = zymarg_vd_premium_vendor_limit_overrides( $vendor_id );
	$merged   = array();
	foreach ( array( 'featured_min', 'featured_max', 'flash_max' ) as $key ) {
		if ( array_key_exists( $key, $submitted ) ) {
			// Present but blank means "clear this override".
			$merged[ $key ] = sanitize_text_field( (string) $submitted[ $key ] );
			continue;
		}

		$merged[ $key ] = ( null === $existing[ $key ] ) ? '' : (string) $existing[ $key ];
	}

	zymarg_vd_premium_update_vendor_limits( $vendor_id, $merged );

	// Report the limits the vendor will actually get, global values included,
	// so the admin can see the result of leaving a field blank.
	$effective = zymarg_vd_premium_vendor_limits( $vendor_id );

	wp_send_json_success(
		array(
			'message'   => __( 'Limits saved.', 'zymarg-vendor-dashboard' ),
			'effective' => $effective,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_premium_save_vendor_limits', 'zymarg_vd_premium_ajax_save_vendor_limits' );
