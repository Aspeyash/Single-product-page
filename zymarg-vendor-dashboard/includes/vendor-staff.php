<?php
/**
 * ZYMARG Vendor Dashboard -- Vendor Staff Accounts.
 *
 * Lets vendors add staff members who can access specific sections of
 * the vendor dashboard. Staff get their own WP login but see the
 * vendor's data (products, orders, etc.).
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/* ====================================================================== *
 * 1. ROLE REGISTRATION
 * ====================================================================== */

/**
 * Register the vendor staff role on init (safety net in case activation
 * hook did not fire).
 *
 * @return void
 */
function zymarg_vd_register_staff_role() {
	if ( ! get_role( 'zymarg_vendor_staff' ) ) {
		add_role(
			'zymarg_vendor_staff',
			__( 'Vendor Staff', 'zymarg-vendor-dashboard' ),
			array( 'read' => true )
		);
	}
}
add_action( 'init', 'zymarg_vd_register_staff_role' );

/**
 * Register the role on plugin activation.
 *
 * @return void
 */
function zymarg_vd_staff_activate() {
	zymarg_vd_register_staff_role();
}

/**
 * Remove the role on plugin deactivation.
 *
 * @return void
 */
function zymarg_vd_staff_deactivate() {
	remove_role( 'zymarg_vendor_staff' );
}

/* ====================================================================== *
 * 2. PERMISSION KEYS
 * ====================================================================== */

/**
 * All available staff permission keys with labels.
 *
 * @return array<string,string>
 */
function zymarg_vd_staff_permission_keys() {
	return array(
		'products'   => __( 'Products', 'zymarg-vendor-dashboard' ),
		'promotions' => __( 'Promotions', 'zymarg-vendor-dashboard' ),
		'orders'     => __( 'Orders', 'zymarg-vendor-dashboard' ),
		'messages'   => __( 'Messages', 'zymarg-vendor-dashboard' ),
		'reviews'    => __( 'Reviews', 'zymarg-vendor-dashboard' ),
		'analytics'  => __( 'Analytics', 'zymarg-vendor-dashboard' ),
		'earnings'   => __( 'Earnings (read-only)', 'zymarg-vendor-dashboard' ),
	);
}

/* ====================================================================== *
 * 3. HELPER FUNCTIONS
 * ====================================================================== */

/**
 * Whether the given user is a vendor staff member.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function zymarg_vd_is_staff( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}
	return in_array( 'zymarg_vendor_staff', (array) $user->roles, true );
}

/**
 * Get the vendor user ID that a staff member belongs to.
 *
 * @param int $user_id Staff user ID.
 * @return int Vendor user ID or 0.
 */
function zymarg_vd_staff_vendor_id( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	return (int) get_user_meta( $user_id, '_zymarg_staff_vendor_id', true );
}

/**
 * Get permissions array for a staff member.
 *
 * @param int $user_id Staff user ID.
 * @return string[]
 */
function zymarg_vd_staff_permissions( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$perms   = get_user_meta( $user_id, '_zymarg_staff_permissions', true );
	if ( ! is_array( $perms ) ) {
		return array();
	}
	return $perms;
}

/**
 * Check if a staff member has a specific permission.
 *
 * @param int    $user_id    Staff user ID.
 * @param string $permission Permission key.
 * @return bool
 */
function zymarg_vd_staff_can( $user_id, $permission ) {
	return in_array( $permission, zymarg_vd_staff_permissions( $user_id ), true );
}

/**
 * Get all staff members for a vendor.
 *
 * @param int $vendor_id Vendor user ID.
 * @return WP_User[]
 */
function zymarg_vd_get_vendor_staff( $vendor_id ) {
	$args = array(
		'role'       => 'zymarg_vendor_staff',
		'meta_key'   => '_zymarg_staff_vendor_id',
		'meta_value' => (int) $vendor_id,
		'orderby'    => 'registered',
		'order'      => 'DESC',
	);
	return get_users( $args );
}


/* ====================================================================== *
 * 4. DASHBOARD ACCESS INTEGRATION
 * ====================================================================== */

/**
 * Allow vendor staff to view the dashboard.
 *
 * @param bool $can Whether user can view.
 * @return bool
 */
function zymarg_vd_staff_can_view_dashboard( $can ) {
	if ( $can ) {
		return $can;
	}
	if ( is_user_logged_in() && zymarg_vd_is_staff( get_current_user_id() ) ) {
		return true;
	}
	return $can;
}

/**
 * Get the effective vendor ID for the current user. If the user is staff,
 * returns their vendor's ID. Otherwise returns the user's own ID.
 *
 * @param int $user_id Optional user ID.
 * @return int
 */
function zymarg_vd_effective_vendor_id( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( zymarg_vd_is_staff( $user_id ) ) {
		$vid = zymarg_vd_staff_vendor_id( $user_id );
		return $vid ? $vid : $user_id;
	}
	return $user_id;
}

/**
 * Sections that are ALWAYS hidden from staff regardless of permissions.
 *
 * @return string[]
 */
function zymarg_vd_staff_blocked_sections() {
	// 'store-settings' was removed in v1.32.0 — its fields now live inside
	// 'settings' (Section 5 "Store Profile"), which is already blocked below.
	return array( 'payments', 'shipping', 'notifications', 'settings', 'staff', 'support' );
}

/**
 * Map section keys to permission keys (some sections share names).
 *
 * @return array<string,string>
 */
function zymarg_vd_section_to_permission() {
	return array(
		'products'      => 'products',
		'orders'        => 'orders',
		'earnings'      => 'earnings',
		'messages'      => 'messages',
		'reviews'       => 'reviews',
		'promotions'    => 'promotions',
		'analytics'     => 'analytics',
		'customers'     => 'orders',
	);
}

/**
 * Whether the given (staff) user may access a section.
 *
 * SINGLE SOURCE OF TRUTH for staff section access. Enforced at the top of
 * zymarg_vd_render_section_content(), which is the one function BOTH the
 * full-page render AND the SPA AJAX endpoint call — so this covers direct
 * URLs (?vsection=), sidebar clicks and SPA navigation alike.
 *
 * Non-staff users (real vendors, shop managers, admins) are always allowed;
 * their access is governed elsewhere.
 *
 * @param string $active  Section key.
 * @param int    $user_id User ID. Defaults to the current user.
 * @return bool
 */
function zymarg_vd_staff_section_allowed( $active, $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	// Non-staff are unaffected by this gate.
	if ( ! zymarg_vd_is_staff( $user_id ) ) {
		return true;
	}

	// Dashboard home is always visible to staff.
	if ( 'dashboard' === $active || '' === $active ) {
		return true;
	}

	// Always-blocked sections (payouts, store settings, staff mgmt, etc.).
	if ( in_array( $active, zymarg_vd_staff_blocked_sections(), true ) ) {
		return false;
	}

	$map = zymarg_vd_section_to_permission();

	// The product editor screen follows the "products" permission.
	if ( 'product-edit' === $active ) {
		$map['product-edit'] = 'products';
	}

	// Permission-gated sections.
	if ( isset( $map[ $active ] ) ) {
		return zymarg_vd_staff_can( $user_id, $map[ $active ] );
	}

	// Unknown sections: deny for safety.
	return false;
}

/**
 * Filter nav items for staff: remove items they cannot access.
 *
 * @param array $items Nav items.
 * @return array
 */
function zymarg_vd_staff_filter_nav_items( $items ) {
	if ( ! is_user_logged_in() || ! zymarg_vd_is_staff( get_current_user_id() ) ) {
		return $items;
	}

	$uid     = get_current_user_id();
	$blocked = zymarg_vd_staff_blocked_sections();
	$map     = zymarg_vd_section_to_permission();

	return array_values(
		array_filter(
			$items,
			function ( $item ) use ( $uid, $blocked, $map ) {
				$key = $item[0];
				// Dashboard is always visible.
				if ( 'dashboard' === $key ) {
					return true;
				}
				// Always-blocked sections.
				if ( in_array( $key, $blocked, true ) ) {
					return false;
				}
				// Check permission mapping.
				if ( isset( $map[ $key ] ) ) {
					return zymarg_vd_staff_can( $uid, $map[ $key ] );
				}
				// Unknown sections: hide for safety.
				return false;
			}
		)
	);
}
add_filter( 'zymarg_os_vendor_nav_items', 'zymarg_vd_staff_filter_nav_items', 20 );

/**
 * Check staff section access before rendering. If staff tries to access
 * a section they lack permission for, return an access denied message.
 *
 * @param string  $html   Section HTML.
 * @param string  $active Active section key.
 * @param WP_User $user   Current user.
 * @return string
 */
function zymarg_vd_staff_gate_section( $html, $active, $user ) {
	if ( ! zymarg_vd_is_staff( $user->ID ) ) {
		return $html;
	}

	$blocked = zymarg_vd_staff_blocked_sections();
	$map     = zymarg_vd_section_to_permission();

	// Always-blocked sections.
	if ( in_array( $active, $blocked, true ) ) {
		return zymarg_vd_staff_access_denied();
	}

	// Permission-gated sections.
	if ( isset( $map[ $active ] ) && ! zymarg_vd_staff_can( $user->ID, $map[ $active ] ) ) {
		return zymarg_vd_staff_access_denied();
	}

	return $html;
}
add_filter( 'zymarg_os_vendor_render_section', 'zymarg_vd_staff_gate_section', 5, 3 );

/**
 * Access denied message for staff.
 *
 * @return string
 */
function zymarg_vd_staff_access_denied() {
	ob_start();
	?>
	<div class="zymarg-vendor-card zymarg-vendor-soon">
		<?php echo zymarg_os_vendor_icon( 'gear' ); ?>
		<h2><?php esc_html_e( 'Access denied', 'zymarg-vendor-dashboard' ); ?></h2>
		<p><?php esc_html_e( 'You do not have permission to access this section. Contact your store owner if you need access.', 'zymarg-vendor-dashboard' ); ?></p>
		<a class="zymarg-vendor-soon__btn" href="<?php echo esc_url( zymarg_os_vendor_dashboard_base_url() ); ?>"><?php esc_html_e( 'Back to Dashboard', 'zymarg-vendor-dashboard' ); ?></a>
	</div>
	<?php
	return (string) ob_get_clean();
}


/* ====================================================================== *
 * 5. SIDEBAR MODIFICATIONS FOR STAFF
 * ====================================================================== */

/**
 * Filter the sidebar store name display to show the vendor's store name
 * when current user is staff, plus add a "Staff: {name}" label.
 *
 * We hook into the sidebar rendering by filtering the user object used
 * for data display. Since the sidebar is rendered inside
 * zymarg_os_vendor_sidebar(), we hook the user identity resolution.
 */

/**
 * Modify the vendor dashboard render to use the vendor's identity when
 * current user is staff.
 *
 * @param string $content Takeover content.
 * @return string
 */
function zymarg_vd_staff_takeover_intercept( $content ) {
	// This is handled by modifying can_view and the sidebar hooks.
	return $content;
}

/**
 * When staff is logged in, treat them as "a real vendor" for rendering
 * purposes by making user_is_vendor return true for their vendor_id.
 *
 * @param bool $is_vendor Original check result.
 * @param int  $user_id   User ID being checked.
 * @return bool
 */
function zymarg_vd_staff_vendor_check( $is_vendor, $user_id ) {
	if ( $is_vendor ) {
		return $is_vendor;
	}
	// If this user is staff, their vendor_id should pass the vendor check.
	if ( zymarg_vd_is_staff( $user_id ) ) {
		$vid = zymarg_vd_staff_vendor_id( $user_id );
		if ( $vid && function_exists( 'zymarg_os_user_is_vendor' ) ) {
			return zymarg_os_user_is_vendor( $vid );
		}
	}
	return $is_vendor;
}
add_filter( 'zymarg_os_user_is_vendor', 'zymarg_vd_staff_vendor_check', 10, 2 );

/* ====================================================================== *
 * 6. STAFF LOGIN EXPERIENCE
 * ====================================================================== */

/**
 * Redirect staff away from wp-admin to the vendor dashboard.
 *
 * @return void
 */
function zymarg_vd_staff_block_admin() {
	if ( ! is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		return;
	}
	if ( ! zymarg_vd_is_staff( get_current_user_id() ) ) {
		return;
	}
	$dashboard_url = function_exists( 'zymarg_os_vendor_dashboard_base_url' )
		? zymarg_os_vendor_dashboard_base_url()
		: home_url( '/dashboard/' );
	wp_safe_redirect( $dashboard_url );
	exit;
}
add_action( 'admin_init', 'zymarg_vd_staff_block_admin' );

/**
 * Redirect staff to the vendor dashboard after login.
 *
 * @param string  $redirect_to Default redirect.
 * @param string  $requested   Requested redirect.
 * @param WP_User $user        User object.
 * @return string
 */
function zymarg_vd_staff_login_redirect( $redirect_to, $requested, $user ) {
	if ( ! is_a( $user, 'WP_User' ) ) {
		return $redirect_to;
	}
	if ( ! in_array( 'zymarg_vendor_staff', (array) $user->roles, true ) ) {
		return $redirect_to;
	}
	$dashboard_url = function_exists( 'zymarg_os_vendor_dashboard_base_url' )
		? zymarg_os_vendor_dashboard_base_url()
		: home_url( '/dashboard/' );
	return $dashboard_url;
}
add_filter( 'login_redirect', 'zymarg_vd_staff_login_redirect', 10, 3 );

/**
 * Hide the admin bar for staff.
 *
 * @param bool $show Whether to show.
 * @return bool
 */
function zymarg_vd_staff_hide_admin_bar( $show ) {
	if ( is_user_logged_in() && zymarg_vd_is_staff( get_current_user_id() ) ) {
		return false;
	}
	return $show;
}
add_filter( 'show_admin_bar', 'zymarg_vd_staff_hide_admin_bar' );


/* ====================================================================== *
 * 7. STAFF NAV ITEM (visible only to actual vendors)
 * ====================================================================== */

/**
 * Add the "Staff" nav item to the sidebar (between customers and shipping).
 * Only visible to actual vendors (not staff themselves).
 *
 * @param array $items Nav items.
 * @return array
 */
function zymarg_vd_add_staff_nav_item( $items ) {
	// Only show when feature is enabled.
	if ( function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( 'staff' ) ) {
		return $items;
	}

	// Only show to actual vendors, not to staff.
	if ( is_user_logged_in() && zymarg_vd_is_staff( get_current_user_id() ) ) {
		return $items;
	}

	// Insert after 'customers', before 'shipping'.
	$new_items = array();
	foreach ( $items as $item ) {
		$new_items[] = $item;
		if ( 'customers' === $item[0] ) {
			$new_items[] = array( 'staff', __( 'Staff', 'zymarg-vendor-dashboard' ), 'users', '' );
		}
	}
	return $new_items;
}
add_filter( 'zymarg_os_vendor_nav_items', 'zymarg_vd_add_staff_nav_item', 5 );

/**
 * Register 'staff' as a native section so it renders in the shell.
 *
 * @param array $sections Native sections.
 * @return array
 */
function zymarg_vd_register_staff_section( $sections ) {
	if ( ! in_array( 'staff', $sections, true ) ) {
		$sections[] = 'staff';
	}
	return $sections;
}
add_filter( 'zymarg_os_vendor_native_sections', 'zymarg_vd_register_staff_section' );

/* ====================================================================== *
 * 8. STAFF SECTION RENDER (vendor-facing)
 * ====================================================================== */

/**
 * Render the Staff section content.
 *
 * @param string  $html   Section HTML (empty by default).
 * @param string  $active Active section key.
 * @param WP_User $user   Current user.
 * @return string
 */
function zymarg_vd_render_staff_section( $html, $active, $user ) {
	if ( 'staff' !== $active ) {
		return $html;
	}

	// Staff cannot see this section.
	if ( zymarg_vd_is_staff( $user->ID ) ) {
		return zymarg_vd_staff_access_denied();
	}

	// Only actual vendors can manage staff.
	if ( ! function_exists( 'zymarg_os_user_is_vendor' ) || ! zymarg_os_user_is_vendor( $user->ID ) ) {
		return zymarg_vd_staff_access_denied();
	}

	$staff_members = zymarg_vd_get_vendor_staff( $user->ID );
	$permissions   = zymarg_vd_staff_permission_keys();

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Staff', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Add team members who can help manage your store with specific permissions.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<div class="zymarg-zpe-layout">
		<!-- Add Staff card -->
		<div class="zymarg-zpe-card zymarg-zpe-card--left">
			<div class="zymarg-zpe-card__accent"></div>
			<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Add Staff Member', 'zymarg-vendor-dashboard' ); ?></div>
			<div class="zymarg-zpe-card__body">
				<form class="zymarg-zpe-form" id="zymarg-staff-add-form">
					<div class="zymarg-zpe-row">
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php esc_html_e( 'First name', 'zymarg-vendor-dashboard' ); ?></span>
							<input type="text" name="first_name" required>
						</label>
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php esc_html_e( 'Last name', 'zymarg-vendor-dashboard' ); ?></span>
							<input type="text" name="last_name" required>
						</label>
					</div>
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Email', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="email" name="email" required>
					</label>
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Password', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="password" name="password" required minlength="6">
					</label>
					<fieldset class="zymarg-staff-perms">
						<legend class="zymarg-zp-field__label"><?php esc_html_e( 'Permissions', 'zymarg-vendor-dashboard' ); ?></legend>
						<?php foreach ( $permissions as $key => $label ) : ?>
							<label class="zymarg-staff-perm-check">
								<input type="checkbox" name="permissions[]" value="<?php echo esc_attr( $key ); ?>">
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<div class="zymarg-zpe-actions">
						<button type="submit" class="zymarg-vendor-cta zymarg-zpe-save">
							<?php echo zymarg_os_vendor_icon( 'users' ); ?>
							<span><?php esc_html_e( 'Add staff member', 'zymarg-vendor-dashboard' ); ?></span>
						</button>
					</div>
					<p class="zymarg-staff-msg" id="zymarg-staff-add-msg" hidden></p>
				</form>
			</div>
		</div>

		<!-- Current Staff card -->
		<div class="zymarg-zpe-card zymarg-zpe-card--right">
			<div class="zymarg-zpe-card__accent"></div>
			<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Your Staff', 'zymarg-vendor-dashboard' ); ?></div>
			<div class="zymarg-zpe-card__body" id="zymarg-staff-list-wrap">
				<?php echo zymarg_vd_staff_list_html( $staff_members, $permissions ); ?>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_filter( 'zymarg_os_vendor_render_section', 'zymarg_vd_render_staff_section', 10, 3 );


/**
 * Render the staff members list HTML.
 *
 * @param WP_User[] $staff_members Staff users.
 * @param array     $permissions   Permission keys => labels.
 * @return string
 */
function zymarg_vd_staff_list_html( $staff_members, $permissions ) {
	if ( empty( $staff_members ) ) {
		return '<p class="zymarg-vendor-empty">' . esc_html__( 'No staff members yet. Add your first team member to get help managing your store.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	ob_start();
	foreach ( $staff_members as $staff ) :
		$staff_perms = zymarg_vd_staff_permissions( $staff->ID );
		$added_date  = $staff->user_registered
			? date_i18n( get_option( 'date_format' ), strtotime( $staff->user_registered ) )
			: '';
		?>
		<div class="zymarg-staff-member" data-staff-id="<?php echo esc_attr( $staff->ID ); ?>">
			<div class="zymarg-staff-member__head">
				<div class="zymarg-staff-member__info">
					<span class="zymarg-staff-member__name"><?php echo esc_html( $staff->first_name . ' ' . $staff->last_name ); ?></span>
					<span class="zymarg-staff-member__email"><?php echo esc_html( $staff->user_email ); ?></span>
					<?php if ( $added_date ) : ?>
						<span class="zymarg-staff-member__date"><?php
							/* translators: %s: date added */
							printf( esc_html__( 'Added %s', 'zymarg-vendor-dashboard' ), esc_html( $added_date ) );
						?></span>
					<?php endif; ?>
				</div>
				<button type="button" class="zymarg-staff-remove" data-staff-remove="<?php echo esc_attr( $staff->ID ); ?>" title="<?php esc_attr_e( 'Remove staff', 'zymarg-vendor-dashboard' ); ?>">&times;</button>
			</div>
			<div class="zymarg-staff-member__perms">
				<?php foreach ( $permissions as $key => $label ) : ?>
					<label class="zymarg-staff-perm-check">
						<input type="checkbox" data-staff-perm="<?php echo esc_attr( $staff->ID ); ?>" value="<?php echo esc_attr( $key ); ?>"<?php checked( in_array( $key, $staff_perms, true ) ); ?>>
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<button type="button" class="zymarg-staff-save-perms" data-staff-save="<?php echo esc_attr( $staff->ID ); ?>"><?php esc_html_e( 'Save permissions', 'zymarg-vendor-dashboard' ); ?></button>
		</div>
	<?php
	endforeach;
	return (string) ob_get_clean();
}

/* ====================================================================== *
 * 9. AJAX ENDPOINTS
 * ====================================================================== */

/**
 * AJAX: Add a new staff member.
 *
 * @return void
 */
function zymarg_vd_add_staff_ajax() {
	check_ajax_referer( 'zymarg_vd_staff', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$vendor_id = get_current_user_id();

	// Only actual vendors can add staff (not staff themselves).
	if ( ! function_exists( 'zymarg_os_user_is_vendor' ) || ! zymarg_os_user_is_vendor( $vendor_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Only vendors can manage staff.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( zymarg_vd_is_staff( $vendor_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Staff cannot add other staff.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
	$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
	$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$password   = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
	$perms      = isset( $_POST['permissions'] ) && is_array( $_POST['permissions'] )
		? array_map( 'sanitize_key', $_POST['permissions'] )
		: array();

	if ( '' === $first_name || '' === $email || '' === $password ) {
		wp_send_json_error( array( 'message' => __( 'Please fill in all required fields.', 'zymarg-vendor-dashboard' ) ) );
	}

	if ( email_exists( $email ) ) {
		wp_send_json_error( array( 'message' => __( 'A user with that email already exists.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Validate permissions against allowed keys.
	$valid_keys = array_keys( zymarg_vd_staff_permission_keys() );
	$perms      = array_values( array_intersect( $perms, $valid_keys ) );

	// Create the user.
	$user_id = wp_insert_user(
		array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $password,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
			'role'         => 'zymarg_vendor_staff',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
	}

	// Set staff meta.
	update_user_meta( $user_id, '_zymarg_staff_vendor_id', $vendor_id );
	update_user_meta( $user_id, '_zymarg_staff_permissions', $perms );

	wp_send_json_success(
		array(
			'message' => __( 'Staff member added successfully.', 'zymarg-vendor-dashboard' ),
			'reload'  => true,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_add_staff', 'zymarg_vd_add_staff_ajax' );


/**
 * AJAX: Update staff permissions.
 *
 * @return void
 */
function zymarg_vd_update_staff_permissions_ajax() {
	check_ajax_referer( 'zymarg_vd_staff', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$vendor_id = get_current_user_id();

	// Only actual vendors (not staff).
	if ( ! function_exists( 'zymarg_os_user_is_vendor' ) || ! zymarg_os_user_is_vendor( $vendor_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Only vendors can manage staff.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( zymarg_vd_is_staff( $vendor_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Staff cannot modify permissions.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;
	$perms    = isset( $_POST['permissions'] ) && is_array( $_POST['permissions'] )
		? array_map( 'sanitize_key', $_POST['permissions'] )
		: array();

	if ( ! $staff_id ) {
		wp_send_json_error( array( 'message' => __( 'Invalid staff member.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Verify this staff belongs to the current vendor.
	$staff_vendor = zymarg_vd_staff_vendor_id( $staff_id );
	if ( (int) $staff_vendor !== (int) $vendor_id ) {
		wp_send_json_error( array( 'message' => __( 'This staff member does not belong to your store.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	// Validate permissions.
	$valid_keys = array_keys( zymarg_vd_staff_permission_keys() );
	$perms      = array_values( array_intersect( $perms, $valid_keys ) );

	update_user_meta( $staff_id, '_zymarg_staff_permissions', $perms );

	wp_send_json_success(
		array( 'message' => __( 'Permissions updated.', 'zymarg-vendor-dashboard' ) )
	);
}
add_action( 'wp_ajax_zymarg_vd_update_staff_permissions', 'zymarg_vd_update_staff_permissions_ajax' );

/**
 * AJAX: Remove a staff member (change role to subscriber).
 *
 * @return void
 */
function zymarg_vd_remove_staff_ajax() {
	check_ajax_referer( 'zymarg_vd_staff', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$vendor_id = get_current_user_id();

	// Only actual vendors (not staff).
	if ( ! function_exists( 'zymarg_os_user_is_vendor' ) || ! zymarg_os_user_is_vendor( $vendor_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Only vendors can manage staff.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( zymarg_vd_is_staff( $vendor_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Staff cannot remove other staff.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;
	if ( ! $staff_id ) {
		wp_send_json_error( array( 'message' => __( 'Invalid staff member.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Verify this staff belongs to the current vendor.
	$staff_vendor = zymarg_vd_staff_vendor_id( $staff_id );
	if ( (int) $staff_vendor !== (int) $vendor_id ) {
		wp_send_json_error( array( 'message' => __( 'This staff member does not belong to your store.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	// Change role to subscriber (safe removal).
	$user = get_userdata( $staff_id );
	if ( $user ) {
		$user->set_role( 'subscriber' );
		delete_user_meta( $staff_id, '_zymarg_staff_vendor_id' );
		delete_user_meta( $staff_id, '_zymarg_staff_permissions' );
	}

	wp_send_json_success(
		array(
			'message' => __( 'Staff member removed.', 'zymarg-vendor-dashboard' ),
			'reload'  => true,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_remove_staff', 'zymarg_vd_remove_staff_ajax' );

/* ====================================================================== *
 * 10. STAFF ASSETS (JS for the staff section)
 * ====================================================================== */

/**
 * Enqueue staff JS when on the vendor dashboard.
 *
 * @param string $ver Plugin version.
 * @return void
 */
function zymarg_vd_staff_enqueue_assets( $ver ) {
	wp_enqueue_script(
		'zymarg-vd-staff',
		ZYMARG_VD_URL . 'assets/js/vendor-staff.js',
		array(),
		$ver,
		true
	);
	wp_localize_script(
		'zymarg-vd-staff',
		'ZymargStaff',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zymarg_vd_staff' ),
			'i18n'    => array(
				'confirmRemove' => __( 'Remove this staff member? They will lose access to your dashboard.', 'zymarg-vendor-dashboard' ),
				'working'       => __( 'Working...', 'zymarg-vendor-dashboard' ),
				'error'         => __( 'Something went wrong. Please try again.', 'zymarg-vendor-dashboard' ),
			),
		)
	);
}
add_action( 'zymarg_os_vendor_enqueue_assets', 'zymarg_vd_staff_enqueue_assets' );

/* ====================================================================== *
 * 11. ACTIVATION / DEACTIVATION HOOKS
 * ====================================================================== */

// These are called from the main plugin file's activation/deactivation hooks.
// The role is also registered on init (safety net) -- see section 1.

