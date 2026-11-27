<?php
// Όταν διαγράφεται το plugin: από προεπιλογή τα δεδομένα μένουν. Σβήνονται μόνο αν έχει επιλεγεί
// «Να σβήνονται όλα τα δεδομένα» στις ρυθμίσεις (ενότητα «Δεδομένα»).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$g5d_settings = get_option( 'g5d_settings', array() );
if ( ! is_array( $g5d_settings ) || empty( $g5d_settings['delete_data'] ) ) {
	return;
}

// Οι υποβολές (δράσεις και ιστορικό). Άμεσο ερώτημα στη βάση, γιατί ο τύπος εγγραφής δεν είναι εγγεγραμμένος κατά την απεγκατάσταση.
global $wpdb;
$g5d_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'g5d_request' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( (array) $g5d_ids as $g5d_id ) {
	wp_delete_post( (int) $g5d_id, true );
}

delete_option( 'g5d_settings' );
delete_option( 'g5d_show' );
delete_option( 'g5d_show_past' );
delete_option( 'draseis_exams_link' ); // κοινή με το Exams Planner: χωρίς αυτή ξαναρωτάει
delete_option( 'g5d_events_backup' );
delete_option( 'g5d_last_error' );
delete_option( 'g5d_access_hash' );
delete_transient( 'g5d_events' );
delete_transient( 'g5d_redirect_settings' );
