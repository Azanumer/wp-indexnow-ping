<?php
/**
 * Uninstall WP IndexNow Ping — removes the settings, submission log and
 * the key file from the site root.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'wip_settings', array() );
if ( ! empty( $settings['key'] ) ) {
	$path = ABSPATH . sanitize_file_name( $settings['key'] ) . '.txt';
	if ( file_exists( $path ) ) {
		@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}

delete_option( 'wip_settings' );
delete_option( 'wip_log' );
