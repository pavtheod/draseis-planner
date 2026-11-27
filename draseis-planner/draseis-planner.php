<?php
/**
 * Plugin Name:       Draseis Planner
 * Description:       Οι εκπαιδευτικοί υποβάλλουν προτάσεις για σχολικές δράσεις, ο διευθυντής τις εγκρίνει με ένα κλικ από το email και οι εγκεκριμένες εμφανίζονται σε πρόγραμμα στη σελίδα του σχολείου. Shortcodes: [draseis_planner], [draseis_form]
 * Version:           1.5.0
 * Author:            5ο Γυμνάσιο Χανίων
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       draseis-planner
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'G5D_VERSION', '1.5.0' );
define( 'G5D_OPT', 'g5d_settings' );
define( 'G5D_TRANSIENT', 'g5d_events' );
define( 'G5D_BACKUP', 'g5d_events_backup' );
define( 'G5D_LAST_ERROR', 'g5d_last_error' );
define( 'G5D_COOKIE', 'g5d_access' );
define( 'G5D_ACCESS_HOURS', 4 );

define( 'G5D_FILE', __FILE__ );

require_once __DIR__ . '/includes/calendar.php';
require_once __DIR__ . '/includes/holidays.php';
require_once __DIR__ . '/includes/display.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/sso.php';
require_once __DIR__ . '/includes/requests.php';
require_once __DIR__ . '/includes/form.php';
require_once __DIR__ . '/includes/gcal.php';
require_once __DIR__ . '/includes/exams.php';
require_once __DIR__ . '/includes/admin.php';

/*
 * Αυτόματες ενημερώσεις από τα Releases του GitHub (βιβλιοθήκη Plugin Update Checker, άδεια MIT).
 * Ελέγχει περιοδικά τη διεύθυνση του αποθετηρίου για νεότερη έκδοση· δεν στέλνει δεδομένα του σχολείου.
 * Φορτώνεται μόνο στη διαχείριση και στις προγραμματισμένες εργασίες, όχι στη δημόσια σελίδα.
 * Χρησιμοποιεί μόνο το συνημμένο draseis-planner.zip του Release (όχι το αυτόματο «Source code»).
 */
if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
	$g5d_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/pavtheod/draseis-planner/',
		__FILE__,
		'draseis-planner'
	);
	$g5d_update_checker->getVcsApi()->enableReleaseAssets(
		'/draseis-planner\.zip$/i',
		\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
	);
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'g5d_action_links' );
