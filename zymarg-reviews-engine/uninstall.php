<?php
/**
 * Reviews Engine — uninstall.
 *
 * Only ever removes this plugin's own settings, and only when the site owner
 * has opted in under Advanced. Reviews, votes, media and report counts live in
 * comments and comment meta and are never touched.
 *
 * @package ZymargReviewsEngine
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$zymarg_re_settings = get_option( 'zymarg_reviews_engine_settings', array() );

if ( is_array( $zymarg_re_settings ) && ! empty( $zymarg_re_settings['reviews_delete_data_on_uninstall'] ) ) {
	delete_option( 'zymarg_reviews_engine_settings' );
	delete_option( 'zymarg_reviews_engine_migrated' );
	delete_transient( 'zymarg_sp_reported_total' );
}
