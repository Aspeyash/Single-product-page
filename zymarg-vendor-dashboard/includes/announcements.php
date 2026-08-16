<?php
/**
 * ZYMARG Vendor Dashboard -- Vendor Announcement System.
 *
 * Registers the zymarg_vd_announcement CPT, admin CRUD page, vendor-facing
 * announcement cards in the Notifications section, and mark-as-read logic.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------- *
 * 1. CUSTOM POST TYPE
 * ---------------------------------------------------------------------- */

/**
 * Register the announcement CPT on init (not admin-only so vendors can query).
 *
 * @return void
 */
function zymarg_vd_register_announcement_cpt() {
	register_post_type(
		'zymarg_vd_announce',
		array(
			'labels'       => array(
				'name'          => __( 'Announcements', 'zymarg-vendor-dashboard' ),
				'singular_name' => __( 'Announcement', 'zymarg-vendor-dashboard' ),
			),
			'public'       => false,
			'show_ui'      => false,
			'supports'     => array( 'title', 'editor' ),
			'has_archive'  => false,
			'rewrite'      => false,
			'query_var'    => false,
		)
	);
}
add_action( 'init', 'zymarg_vd_register_announcement_cpt' );

/* ---------------------------------------------------------------------- *
 * 2. ADMIN SUBMENU
 * ---------------------------------------------------------------------- */

/**
 * Register the Announcements submenu under Vendor Hub.
 *
 * @return void
 */
function zymarg_vd_register_admin_announcements_menu() {
	add_submenu_page(
		'zymarg-vendor-hub',
		__( 'Announcements', 'zymarg-vendor-dashboard' ),
		__( 'Announcements', 'zymarg-vendor-dashboard' ),
		'manage_options',
		'zymarg-vendor-announcements',
		'zymarg_vd_render_admin_announcements_page'
	);
}
add_action( 'admin_menu', 'zymarg_vd_register_admin_announcements_menu', 10 );

/**
 * Enqueue admin announcements scripts on the Announcements admin page.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 * @return void
 */
function zymarg_vd_admin_announcements_enqueue( $hook_suffix ) {
	if ( 'vendor-hub_page_zymarg-vendor-announcements' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_script(
		'zymarg-vd-admin-announcements',
		ZYMARG_VD_URL . 'assets/js/admin-announcements.js',
		array( 'jquery' ),
		ZYMARG_VD_VERSION,
		true
	);

	wp_localize_script(
		'zymarg-vd-admin-announcements',
		'ZymargAnnouncements',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zymarg_vd_announcements' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'zymarg_vd_admin_announcements_enqueue' );

/* ---------------------------------------------------------------------- *
 * 3. ADMIN AJAX HANDLERS
 * ---------------------------------------------------------------------- */

/**
 * AJAX: Create a new announcement.
 *
 * @return void
 */
function zymarg_vd_ajax_create_announcement() {
	check_ajax_referer( 'zymarg_vd_announcements', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
	$body   = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
	$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : 'all';

	if ( empty( $title ) ) {
		wp_send_json_error( array( 'message' => __( 'Title is required.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'zymarg_vd_announce',
			'post_title'   => $title,
			'post_content' => $body,
			'post_status'  => 'publish',
		)
	);

	if ( is_wp_error( $post_id ) ) {
		wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 500 );
	}

	update_post_meta( $post_id, '_target', $target );
	update_post_meta( $post_id, '_status', 'active' );
	update_post_meta( $post_id, '_created_by', get_current_user_id() );

	// Return the rendered row so the browser can insert it in place
	// instead of reloading the whole admin page.
	wp_send_json_success( array(
		'message' => __( 'Announcement created.', 'zymarg-vendor-dashboard' ),
		'id'      => $post_id,
		'html'    => zymarg_vd_render_announcement_row( get_post( $post_id ) ),
	) );
}
add_action( 'wp_ajax_zymarg_vd_create_announcement', 'zymarg_vd_ajax_create_announcement' );

/**
 * AJAX: Deactivate an announcement.
 *
 * @return void
 */
function zymarg_vd_ajax_deactivate_announcement() {
	check_ajax_referer( 'zymarg_vd_announcements', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id || get_post_type( $post_id ) !== 'zymarg_vd_announce' ) {
		wp_send_json_error( array( 'message' => __( 'Invalid announcement.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	update_post_meta( $post_id, '_status', 'expired' );

	wp_send_json_success( array( 'message' => __( 'Announcement deactivated.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_deactivate_announcement', 'zymarg_vd_ajax_deactivate_announcement' );

/**
 * AJAX: Delete an announcement.
 *
 * @return void
 */
function zymarg_vd_ajax_delete_announcement() {
	check_ajax_referer( 'zymarg_vd_announcements', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id || get_post_type( $post_id ) !== 'zymarg_vd_announce' ) {
		wp_send_json_error( array( 'message' => __( 'Invalid announcement.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	wp_delete_post( $post_id, true );

	wp_send_json_success( array( 'message' => __( 'Announcement deleted.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_delete_announcement', 'zymarg_vd_ajax_delete_announcement' );

/**
 * AJAX: Mark an announcement as read (vendor-side).
 *
 * @return void
 */
function zymarg_vd_ajax_mark_announcement_read() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not logged in.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$user_id = get_current_user_id();

	// Verify vendor role.
	$user = get_userdata( $user_id );
	if ( ! $user || ( ! in_array( 'seller', (array) $user->roles, true ) && ! in_array( 'vendor', (array) $user->roles, true ) && ! current_user_can( 'manage_options' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id || get_post_type( $post_id ) !== 'zymarg_vd_announce' ) {
		wp_send_json_error( array( 'message' => __( 'Invalid announcement.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	$read_list = get_user_meta( $user_id, '_zymarg_vd_read_announcements', true );
	if ( ! is_array( $read_list ) ) {
		$read_list = array();
	}

	if ( ! in_array( $post_id, $read_list, true ) ) {
		$read_list[] = $post_id;
		update_user_meta( $user_id, '_zymarg_vd_read_announcements', $read_list );
	}

	wp_send_json_success( array( 'message' => __( 'Marked as read.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_mark_announcement_read', 'zymarg_vd_ajax_mark_announcement_read' );

/* ---------------------------------------------------------------------- *
 * 4. HELPER FUNCTIONS
 * ---------------------------------------------------------------------- */

/**
 * Get all announcements.
 *
 * @param string $status 'active', 'expired', or 'any'.
 * @return WP_Post[]
 */
function zymarg_vd_get_announcements( $status = 'any' ) {
	$args = array(
		'post_type'      => 'zymarg_vd_announce',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);

	if ( 'any' !== $status ) {
		$args['meta_query'] = array(
			array(
				'key'   => '_status',
				'value' => $status,
			),
		);
	}

	return get_posts( $args );
}

/**
 * Get active announcements for a specific vendor.
 *
 * @param int $vendor_id Vendor user ID.
 * @return WP_Post[]
 */
function zymarg_vd_get_vendor_announcements( $vendor_id ) {
	// Feature toggle: if announcements are disabled, return empty.
	if ( function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( 'announcements' ) ) {
		return array();
	}
	$all = zymarg_vd_get_announcements( 'active' );
	$result = array();

	foreach ( $all as $post ) {
		$target = get_post_meta( $post->ID, '_target', true );
		if ( 'all' === $target || '' === $target ) {
			$result[] = $post;
		} else {
			$ids = array_map( 'absint', explode( ',', $target ) );
			if ( in_array( (int) $vendor_id, $ids, true ) ) {
				$result[] = $post;
			}
		}
	}

	return $result;
}

/**
 * Check if a vendor has unread announcements.
 *
 * @param int $vendor_id Vendor user ID.
 * @return bool
 */
function zymarg_vd_has_unread_announcements( $vendor_id ) {
	$announcements = zymarg_vd_get_vendor_announcements( $vendor_id );
	if ( empty( $announcements ) ) {
		return false;
	}

	$read_list = get_user_meta( $vendor_id, '_zymarg_vd_read_announcements', true );
	if ( ! is_array( $read_list ) ) {
		$read_list = array();
	}

	foreach ( $announcements as $post ) {
		if ( ! in_array( $post->ID, $read_list, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Get list of all vendor users (for the target dropdown).
 *
 * @return array [ id => display_name ]
 */
function zymarg_vd_get_vendor_users() {
	$users = get_users(
		array(
			'role__in' => array( 'seller', 'vendor' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		)
	);

	$list = array();
	foreach ( $users as $u ) {
		$list[ $u->ID ] = $u->display_name;
	}

	return $list;
}

/**
 * Render a single announcement row for the admin list.
 *
 * Shared by the initial page render and by the create-announcement AJAX
 * response so the row markup lives in exactly one place.
 *
 * @param WP_Post $post Announcement post object.
 * @return string Row HTML.
 */
function zymarg_vd_render_announcement_row( $post ) {
	$target_val   = get_post_meta( $post->ID, '_target', true );
	$status_val   = get_post_meta( $post->ID, '_status', true );
	$target_label = ( 'all' === $target_val || '' === $target_val ) ? __( 'All Vendors', 'zymarg-vendor-dashboard' ) : __( 'Specific Vendors', 'zymarg-vendor-dashboard' );
	$status_label = 'active' === $status_val ? __( 'Active', 'zymarg-vendor-dashboard' ) : __( 'Expired', 'zymarg-vendor-dashboard' );
	$status_class = 'active' === $status_val ? 'zymarg-announce-status--active' : 'zymarg-announce-status--expired';

	ob_start();
	?>
	<div class="zymarg-announce-row" data-announce-id="<?php echo esc_attr( $post->ID ); ?>">
		<div class="zymarg-announce-row__info">
			<strong class="zymarg-announce-row__title"><?php echo esc_html( $post->post_title ); ?></strong>
			<span class="zymarg-announce-row__meta">
				<?php echo esc_html( $target_label ); ?> &middot;
				<?php echo esc_html( get_the_date( 'M j, Y', $post ) ); ?> &middot;
				<span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
			</span>
		</div>
		<div class="zymarg-announce-row__actions">
			<?php if ( 'active' === $status_val ) : ?>
				<button type="button" class="button zymarg-announce-deactivate" data-id="<?php echo esc_attr( $post->ID ); ?>"><?php esc_html_e( 'Deactivate', 'zymarg-vendor-dashboard' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button zymarg-announce-delete" data-id="<?php echo esc_attr( $post->ID ); ?>"><?php esc_html_e( 'Delete', 'zymarg-vendor-dashboard' ); ?></button>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/* ---------------------------------------------------------------------- *
 * 5. ADMIN RENDER
 * ---------------------------------------------------------------------- */

/**
 * Render the admin Announcements page.
 *
 * @return void
 */
function zymarg_vd_render_admin_announcements_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$is_ajax = ! empty( $GLOBALS['zymarg_vd_ajax_render'] );
	if ( ! $is_ajax ) {
		echo '<div id="zymarg-admin-ajax-content" class="zymarg-admin">';
	}

	echo '<a href="' . esc_url( admin_url( 'admin.php?page=zymarg-vendor-hub' ) ) . '" class="zvd-back zvd-nav-link">&larr; Back to Vendor Hub</a>';

	$announcements = zymarg_vd_get_announcements();
	$vendors       = zymarg_vd_get_vendor_users();
	?>
	<div class="wrap zymarg-admin-announcements-wrap">
		<?php
		zymarg_vd_admin_header(
			__( 'Announcements', 'zymarg-vendor-dashboard' ),
			__( 'Broadcast messages to your vendors.', 'zymarg-vendor-dashboard' )
		);
		?>

		<!-- New Announcement Form -->
		<div class="zymarg-announce-form-card">
			<div class="zymarg-announce-form-card__accent"></div>
			<h2 class="zymarg-announce-form-card__title"><?php esc_html_e( 'New Announcement', 'zymarg-vendor-dashboard' ); ?></h2>
			<form id="zymarg-announce-form" class="zymarg-announce-form">
				<div class="zymarg-announce-form__field">
					<label for="zymarg-announce-title"><?php esc_html_e( 'Title', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="text" id="zymarg-announce-title" name="title" placeholder="<?php esc_attr_e( 'Announcement title...', 'zymarg-vendor-dashboard' ); ?>" required />
				</div>
				<div class="zymarg-announce-form__field">
					<label for="zymarg-announce-body"><?php esc_html_e( 'Body', 'zymarg-vendor-dashboard' ); ?></label>
					<textarea id="zymarg-announce-body" name="body" rows="4" placeholder="<?php esc_attr_e( 'Announcement details...', 'zymarg-vendor-dashboard' ); ?>"></textarea>
				</div>
				<div class="zymarg-announce-form__field">
					<label for="zymarg-announce-target"><?php esc_html_e( 'Target', 'zymarg-vendor-dashboard' ); ?></label>
					<select id="zymarg-announce-target" name="target">
						<option value="all"><?php esc_html_e( 'All Vendors', 'zymarg-vendor-dashboard' ); ?></option>
						<option value="select"><?php esc_html_e( 'Select Vendors', 'zymarg-vendor-dashboard' ); ?></option>
					</select>
				</div>
				<div class="zymarg-announce-form__field zymarg-announce-form__vendors zvd-is-hidden">
					<label for="zymarg-announce-vendor-select"><?php esc_html_e( 'Select Vendors', 'zymarg-vendor-dashboard' ); ?></label>
					<select id="zymarg-announce-vendor-select" name="vendor_ids[]" multiple>
						<?php foreach ( $vendors as $vid => $vname ) : ?>
							<option value="<?php echo esc_attr( $vid ); ?>"><?php echo esc_html( $vname ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="zymarg-announce-form__actions">
					<button type="submit" class="button button-primary zymarg-announce-submit"><?php esc_html_e( 'Publish Announcement', 'zymarg-vendor-dashboard' ); ?></button>
					<span class="zymarg-announce-form__feedback"></span>
				</div>
			</form>
		</div>

		<!-- Announcements List -->
		<div class="zymarg-announce-list-card">
			<h2 class="zymarg-announce-list-card__title"><?php esc_html_e( 'All Announcements', 'zymarg-vendor-dashboard' ); ?></h2>
			<div id="zymarg-announce-list" class="zymarg-announce-list">
				<?php if ( empty( $announcements ) ) : ?>
					<p class="zymarg-announce-empty"><?php esc_html_e( 'No announcements yet.', 'zymarg-vendor-dashboard' ); ?></p>
				<?php else : ?>
					<?php foreach ( $announcements as $post ) : ?>
						<?php echo zymarg_vd_render_announcement_row( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php
	if ( ! $is_ajax ) {
		echo '</div><!-- #zymarg-admin-ajax-content -->';
	}
}
