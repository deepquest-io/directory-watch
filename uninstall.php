<?php
/**
 * Uninstall routine: remove options, transients and the scheduled event.
 *
 * @package DirectoryWatch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove every trace of the plugin for one site.
 */
function dw_uninstall_site() {
	delete_option( 'dw_state' );
	delete_option( 'dw_settings' );
	delete_option( 'dw_version' );

	// Enumerated by name so no LIKE escaping is needed.
	delete_transient( 'dw_lock' );
	delete_transient( 'dw_manual_cooldown' );

	wp_clear_scheduled_hook( 'dw_check_cron' );
}

if ( is_multisite() ) {
	$dw_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $dw_sites as $dw_site_id ) {
		switch_to_blog( (int) $dw_site_id );
		dw_uninstall_site();
		restore_current_blog();
	}
} else {
	dw_uninstall_site();
}
