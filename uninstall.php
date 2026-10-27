<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * Dates themselves are left as they are: they're the posts' real dates.
 *
 * @package Honest_Last_Modified_Date
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site.
 *
 * @return void
 */
function honest_last_modified_date_uninstall_site() {
	delete_option( 'honest_last_modified_date_settings' );
	delete_option( 'honest_last_modified_date_log' );
	delete_option( 'honest_last_modified_date_totals' );
	delete_transient( 'honest_last_modified_date_typical' );

	// Each post's copy of the version its date stands for.
	delete_post_meta_by_key( '_hlmd_baseline' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 10000,
		)
	) as $honest_last_modified_date_site ) {
		switch_to_blog( (int) $honest_last_modified_date_site );
		honest_last_modified_date_uninstall_site();
		restore_current_blog();
	}
} else {
	honest_last_modified_date_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-honest-last-modified-date' );
