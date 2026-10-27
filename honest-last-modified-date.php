<?php
/**
 * Plugin Name:       Honest Last Modified Date
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Only changes a post's Last modified date when enough of it has really changed: a minimum number of words, a percentage, or both, set separately for posts and pages.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       honest-last-modified-date
 * Update URI:        https://github.com/jmsprsns/honest-last-modified-date
 *
 * @package Honest_Last_Modified_Date
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$honest_last_modified_date_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'HONEST_LAST_MODIFIED_DATE_VERSION', $honest_last_modified_date_headers['version'] );
define( 'HONEST_LAST_MODIFIED_DATE_REPO', $honest_last_modified_date_headers['repo'] );
define( 'HONEST_LAST_MODIFIED_DATE_FILE', __FILE__ );
define( 'HONEST_LAST_MODIFIED_DATE_DIR', plugin_dir_path( __FILE__ ) );
define( 'HONEST_LAST_MODIFIED_DATE_URL', plugin_dir_url( __FILE__ ) );

unset( $honest_last_modified_date_headers );

require_once HONEST_LAST_MODIFIED_DATE_DIR . 'includes/class-honest-last-modified-date-icons.php';
require_once HONEST_LAST_MODIFIED_DATE_DIR . 'includes/class-honest-last-modified-date-updater.php';
require_once HONEST_LAST_MODIFIED_DATE_DIR . 'includes/class-honest-last-modified-date-menu.php';
require_once HONEST_LAST_MODIFIED_DATE_DIR . 'includes/class-honest-last-modified-date-feedback.php';
require_once HONEST_LAST_MODIFIED_DATE_DIR . 'includes/class-honest-last-modified-date-diff.php';
require_once HONEST_LAST_MODIFIED_DATE_DIR . 'includes/class-honest-last-modified-date-log.php';
require_once HONEST_LAST_MODIFIED_DATE_DIR . 'includes/class-honest-last-modified-date-saves.php';
require_once HONEST_LAST_MODIFIED_DATE_DIR . 'includes/class-honest-last-modified-date-editor.php';
require_once HONEST_LAST_MODIFIED_DATE_DIR . 'includes/class-honest-last-modified-date-admin.php';
require_once HONEST_LAST_MODIFIED_DATE_DIR . 'includes/class-honest-last-modified-date-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Honest_Last_Modified_Date_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Honest_Last_Modified_Date_Plugin
 */
function honest_last_modified_date_plugin() {
	return Honest_Last_Modified_Date_Plugin::instance();
}
add_action( 'plugins_loaded', 'honest_last_modified_date_plugin' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Nothing is scheduled. Settings, the activity log and each post's record
 * of the version its date stands for stay put (uninstall.php removes them).
 * While the plugin is off, saves set today's date as WordPress normally does,
 * and those records are ignored once their date no longer matches.
 *
 * @return void
 */
function honest_last_modified_date_deactivate() {
}
register_deactivation_hook( HONEST_LAST_MODIFIED_DATE_FILE, 'honest_last_modified_date_deactivate' );
