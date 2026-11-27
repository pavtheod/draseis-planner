<?php
/**
 * Draseis Planner: διαχείριση στην admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Ακύρωση εγκεκριμένης δράσης: κρύβεται από τη σελίδα, μένει στο αρχείο. */
function g5d_cancel_action() {
	$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	check_admin_referer( 'g5d_cancel_' . $id );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || G5D_CPT !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	update_post_meta( $id, 'g5d_status', 'cancelled' );
	g5d_gcal_remove( $id );
	wp_safe_redirect( admin_url( 'edit.php?post_type=' . G5D_CPT . '&g5d_cancelled=1' ) );
	exit;
}
add_action( 'admin_post_g5d_cancel', 'g5d_cancel_action' );

/** Επαναφέρει ακυρωμένη ή απορριφθείσα αίτηση σε εκκρεμότητα και στέλνει νέο email απόφασης. */
function g5d_reopen_action() {
	$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	check_admin_referer( 'g5d_reopen_' . $id );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || G5D_CPT !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	$d = g5d_request_data( $id );
	if ( ! in_array( $d['status'], array( 'cancelled', 'rejected' ), true ) ) {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . G5D_CPT ) );
		exit;
	}
	$token = g5d_token_new();
	update_post_meta( $id, 'g5d_status', 'pending' );
	update_post_meta( $id, 'g5d_token_hash', hash( 'sha256', $token ) );
	update_post_meta( $id, 'g5d_token_expires', time() + G5D_TOKEN_DAYS * DAY_IN_SECONDS );
	update_post_meta( $id, 'g5d_token_used', 0 );
	update_post_meta( $id, 'g5d_decided_at', '' );
	update_post_meta( $id, 'g5d_decision_note', '' );
	g5d_send_request_mail( $id, $d, $token );
	wp_safe_redirect( admin_url( 'edit.php?post_type=' . G5D_CPT . '&g5d_reopened=1' ) );
	exit;
}
add_action( 'admin_post_g5d_reopen', 'g5d_reopen_action' );

add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( G5D_CPT === $post->post_type && 'approved' === get_post_meta( $post->ID, 'g5d_status', true ) ) {
			$url                   = wp_nonce_url( admin_url( 'admin-post.php?action=g5d_cancel&id=' . $post->ID ), 'g5d_cancel_' . $post->ID );
			$actions['g5d_cancel'] = '<a href="' . esc_url( $url ) . '" onclick="return confirm(\'Να ακυρωθεί η δράση; Θα κρυφτεί από τη σελίδα του προγράμματος.\');">Ακύρωση δράσης</a>';
		}
		if ( G5D_CPT === $post->post_type && in_array( get_post_meta( $post->ID, 'g5d_status', true ), array( 'cancelled', 'rejected' ), true ) ) {
			$url                    = wp_nonce_url( admin_url( 'admin-post.php?action=g5d_reopen&id=' . $post->ID ), 'g5d_reopen_' . $post->ID );
			$actions['g5d_reopen']  = '<a href="' . esc_url( $url ) . '" onclick="return confirm(\'Να επαναφερθεί η αίτηση σε εκκρεμότητα; Θα σταλεί νέο email με κουμπιά έγκρισης.\');">Επαναφορά σε εκκρεμότητα</a>';
		}
		return $actions;
	},
	10,
	2
);

add_filter(
	'manage_' . G5D_CPT . '_posts_columns',
	function ( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['g5d_date']   = 'Ημερομηνία';
				$out['g5d_grades'] = 'ΟΜΑΔΑ / ΤΜΗΜΑΤΑ';
			}
		}
		$out['g5d_status'] = 'Κατάσταση';
		return $out;
	}
);

add_action(
	'manage_' . G5D_CPT . '_posts_custom_column',
	function ( $col, $id ) {
		if ( 'g5d_date' === $col ) {
			$from = (string) get_post_meta( $id, 'g5d_date_from', true );
			$to   = (string) get_post_meta( $id, 'g5d_date_to', true );
			$tf   = (string) get_post_meta( $id, 'g5d_time_from', true );
			$tt   = (string) get_post_meta( $id, 'g5d_time_to', true );
			$fmt  = function ( $d ) {
				return preg_match( '/^\d{4}-(\d{2})-(\d{2})$/', $d, $m ) ? $m[2] . '/' . $m[1] : $d;
			};
			$out  = '' !== $from ? $fmt( $from ) : '';
			if ( '' !== $to && $to !== $from ) {
				$out .= '–' . $fmt( $to );
			}
			if ( '' !== $tf ) {
				$out .= ' ' . $tf . ( '' !== $tt ? '–' . $tt : '' );
			}
			echo esc_html( '' !== $out ? $out : '—' );
			return;
		}
		if ( 'g5d_grades' === $col ) {
			$group  = (string) get_post_meta( $id, 'g5d_group_name', true );
			$grades = get_post_meta( $id, 'g5d_grades', true );
			$list   = is_array( $grades ) ? $grades : array();
			$parts  = array();
			if ( '' !== $group ) {
				$parts[] = $group;
			}
			if ( in_array( 'all', $list, true ) ) {
				$parts[] = 'Όλο το σχολείο';
			} elseif ( ! empty( $list ) ) {
				$parts[] = implode( ', ', $list );
			}
			echo esc_html( $parts ? implode( ' · ', $parts ) : '—' );
			return;
		}
		if ( 'g5d_status' !== $col ) {
			return;
		}
		$labels = array(
			'pending'   => 'Εκκρεμεί',
			'approved'  => 'Εγκρίθηκε',
			'rejected'  => 'Απορρίφθηκε',
			'cancelled' => 'Ακυρώθηκε',
		);
		$s      = get_post_meta( $id, 'g5d_status', true );
		echo esc_html( isset( $labels[ $s ] ) ? $labels[ $s ] : $s );
	},
	10,
	2
);

add_action(
	'admin_notices',
	function () {
		if ( isset( $_GET['g5d_cancelled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Η δράση ακυρώθηκε και δεν εμφανίζεται πλέον στη σελίδα του προγράμματος.</p></div>';
		}
		if ( isset( $_GET['g5d_reopened'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Η αίτηση επανήλθε σε εκκρεμότητα και στάλθηκε νέο email απόφασης.</p></div>';
		}
	}
);

/**
 * Το JSON του λογαριασμού υπηρεσίας. Κενό πεδίο σημαίνει «κράτα το υπάρχον».
 * Αποθηκεύεται ξανά ως καθαρό JSON, ώστε να μην χαλάει από τα backslash της επικόλλησης.
 */
function g5d_sanitize_service_account( $raw, $old ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return $old;
	}
	$j = json_decode( $raw, true );
	if ( ! is_array( $j ) ) {
		$j = json_decode( stripslashes( $raw ), true );
	}
	if ( is_array( $j ) && ! empty( $j['client_email'] ) && ! empty( $j['private_key'] ) ) {
		return wp_json_encode( $j );
	}
	add_settings_error( 'g5d', 'g5d_sa', 'Το αρχείο JSON του λογαριασμού υπηρεσίας δεν είναι έγκυρο. Η προηγούμενη ρύθμιση διατηρήθηκε.' );
	return $old;
}

function g5d_sanitize( $input ) {
	if ( is_array( $input ) && isset( $input['exams_link'] ) && in_array( $input['exams_link'], array( 'yes', 'no' ), true ) ) {
		update_option( G5D_LINK_OPT, $input['exams_link'], false ); // κοινή ρύθμιση με το Exams Planner
	}
	$input = is_array( $input ) ? $input : array();
	$old   = (array) get_option( G5D_OPT, array() );
	$code     = isset( $input['code'] ) ? trim( (string) $input['code'] ) : '';
	$code_old = isset( $old['code'] ) ? (string) $old['code'] : '';
	$code_ver = isset( $old['code_ver'] ) ? (int) $old['code_ver'] : 0;
	if ( '' === $code ) {
		$code = $code_old;
	} elseif ( mb_strlen( $code ) < 4 ) {
		add_settings_error( 'g5d', 'g5d_code', 'Ο κωδικός καθηγητών πρέπει να έχει τουλάχιστον 4 χαρακτήρες. Ο προηγούμενος διατηρήθηκε.' );
		$code = $code_old;
	} elseif ( $code !== $code_old ) {
		$code_ver++;
	}
	return array(
		'code'          => $code,
		'code_ver'      => $code_ver,
		'calendar_id'   => sanitize_text_field( isset( $input['calendar_id'] ) ? $input['calendar_id'] : '' ),
		'cache_minutes' => min( 1440, max( 1, absint( isset( $input['cache_minutes'] ) ? $input['cache_minutes'] : 15 ) ) ),
		'form_url'      => isset( $input['form_url'] ) ? esc_url_raw( trim( $input['form_url'] ) ) : '',
		'access'        => ( isset( $input['access'] ) && 'sso' === $input['access'] ) ? 'sso' : 'code',
		'notify_email'  => sanitize_email( isset( $input['notify_email'] ) ? $input['notify_email'] : '' ),
		'classes'       => implode( "\n", g5d_parse_classes( sanitize_textarea_field( isset( $input['classes'] ) ? $input['classes'] : '' ) ) ),
		'delete_data'   => empty( $input['delete_data'] ) ? 0 : 1,
		'lead_days'     => min( 60, max( 0, absint( isset( $input['lead_days'] ) ? $input['lead_days'] : 0 ) ) ),
		'holidays'      => sanitize_textarea_field( isset( $input['holidays'] ) ? $input['holidays'] : '' ),
		'holidays_local' => sanitize_textarea_field( isset( $input['holidays_local'] ) ? $input['holidays_local'] : '' ),
		'holidays_auto' => empty( $input['holidays_auto'] ) ? 0 : 1,
		'holidays_year' => isset( $old['holidays_year'] ) ? (int) $old['holidays_year'] : g5d_school_year_start(),
		'sso_server'    => ( isset( $input['sso_server'] ) && 'test' === $input['sso_server'] ) ? 'test' : 'prod',
		'sso_service'   => isset( $input['sso_service'] ) ? esc_url_raw( trim( $input['sso_service'] ) ) : '',
		'sso_units'     => isset( $input['sso_units'] ) ? sanitize_text_field( $input['sso_units'] ) : '',
		'sso_roles'     => ( isset( $input['sso_roles'] ) && '' !== trim( $input['sso_roles'] ) ) ? sanitize_text_field( $input['sso_roles'] ) : 'teacher, ateacher, pteacher',
		'service_account' => g5d_sanitize_service_account( isset( $input['service_account'] ) ? $input['service_account'] : '', isset( $old['service_account'] ) ? $old['service_account'] : '' ),
	);
}

/** «Επαναφορά πανελλαδικών αργιών»: ξαναγράφει το πεδίο με τις αργίες της τρέχουσας σχολικής χρονιάς. */
function g5d_admin_fill_holidays() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'g5d_fill_holidays' );
	$s                  = (array) get_option( G5D_OPT, array() );
	$sy                 = g5d_school_year_start();
	$s['holidays']      = g5d_national_holidays_text( $sy );
	$s['holidays_year'] = $sy;
	g5d_save_settings_raw( $s );
	wp_safe_redirect( admin_url( 'admin.php?page=g5d&tab=settings&g5d_msg=holfilled' ) );
	exit;
}
add_action( 'admin_post_g5d_fill_holidays', 'g5d_admin_fill_holidays' );

/**
 * «Δημιουργία σελίδας»: φτιάχνει πρόχειρη σελίδα με το shortcode και ανοίγει τον επεξεργαστή για «Δημοσίευση».
 * Αν υπάρχει ήδη πρόχειρη σελίδα με το shortcode, ανοίγει αυτήν (δεν φτιάχνει δεύτερη). Όπως στο Exams Planner.
 */
function g5d_admin_create_page() {
	global $wpdb;
	if ( ! current_user_can( 'publish_pages' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	$which = isset( $_POST['which'] ) && 'form' === $_POST['which'] ? 'form' : 'program'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	check_admin_referer( 'g5d_create_page_' . $which );
	$def = 'form' === $which
		? array( 'title' => 'Πρόταση Δράσης', 'slug' => 'draseis-form', 'code' => '[draseis_form]' )
		: array( 'title' => 'Πρόγραμμα Δράσεων', 'slug' => 'draseis-planner', 'code' => '[draseis_planner]' );
	$id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('draft','pending','future','private') AND post_content LIKE %s ORDER BY ID DESC LIMIT 1",
			'%' . $wpdb->esc_like( rtrim( $def['code'], ']' ) ) . '%'
		)
	);
	if ( ! $id ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $def['title'],
				'post_name'    => $def['slug'],
				'post_content' => '<!-- wp:shortcode -->' . $def['code'] . '<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( 'Η σελίδα δεν δημιουργήθηκε: ' . $id->get_error_message() ) );
		}
	}
	wp_safe_redirect( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) );
	exit;
}
add_action( 'admin_post_g5d_create_page', 'g5d_admin_create_page' );

/** Όταν δημοσιεύεται σελίδα με τη φόρμα και η «Σελίδα φόρμας» στις ρυθμίσεις είναι κενή, συμπληρώνεται μόνη της. */
function g5d_autofill_form_url( $new, $old, $post ) {
	if ( 'publish' !== $new || 'page' !== $post->post_type || ! has_shortcode( (string) $post->post_content, 'draseis_form' ) ) {
		return;
	}
	$saved = (array) get_option( G5D_OPT, array() );
	if ( ! empty( $saved['form_url'] ) ) {
		return;
	}
	$saved['form_url'] = get_permalink( $post );
	g5d_save_settings_raw( $saved );
}
add_action( 'transition_post_status', 'g5d_autofill_form_url', 10, 3 );

/** Οι δύο λίστες τμημάτων δίπλα-δίπλα, για την ερώτηση σύνδεσης. */
function g5d_link_compare_html( array $own, array $ex ) {
	$a = array_map( 'g5d_norm_cls', $own );
	$b = array_map( 'g5d_norm_cls', $ex );
	sort( $a );
	sort( $b );
	if ( $a === $b && $own ) {
		return '<p style="margin:4px 0">Τα τμήματα είναι ίδια και στα δύο plugins.</p>';
	}
	return '<p style="margin:4px 0">Τμήματα Draseis Planner: ' . esc_html( $own ? implode( ', ', $own ) : '(κανένα)' ) . '<br>'
		. 'Τμήματα Exams Planner: ' . esc_html( $ex ? implode( ', ', $ex ) : '(κανένα)' ) . '</p>'
		. '<p style="margin:4px 0">Με «Ναι», η φόρμα θα δείχνει τα τμήματα του Exams Planner. Αν δεν είναι σωστά, διορθώστε τα πρώτα στο <a href="' . esc_url( admin_url( 'admin.php?page=exams-planner' ) ) . '">Exams Planner → Ρυθμίσεις</a>.</p>';
}

/** Μήνυμα σε όλες τις σελίδες της διαχείρισης μέχρι να απαντηθεί η σύνδεση (δεν κλείνει με το Χ). */
function g5d_link_notice() {
	if ( ! current_user_can( 'manage_options' ) || ! g5d_exams_active() || '' !== g5d_exams_link() ) {
		return;
	}
	$own = g5d_parse_classes( g5d_settings()['classes'] );
	$btn = function ( $val, $label, $primary ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px">'
			. '<input type="hidden" name="action" value="g5d_link"><input type="hidden" name="link" value="' . esc_attr( $val ) . '">'
			. wp_nonce_field( 'g5d_link', '_wpnonce', true, false )
			. '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	};
	echo '<div class="notice notice-warning"><p><strong>Draseis Planner:</strong> βρέθηκε το Exams Planner (πρόγραμμα διαγωνισμάτων). Θέλετε να συνδεθούν τα δύο plugins;</p>'
		. g5d_link_compare_html( $own, g5d_exams_classes() ) // phpcs:ignore WordPress.Security.EscapeOutput
		. '<p>' . $btn( 'yes', 'Ναι, να συνδεθούν', true ) . $btn( 'no', 'Όχι, ανεξάρτητα', false ) // phpcs:ignore WordPress.Security.EscapeOutput
		. ' <span style="color:#646970">Μπορείτε να το αλλάξετε αργότερα από τις Ρυθμίσεις του Draseis Planner.</span></p></div>';
}
add_action( 'admin_notices', 'g5d_link_notice' );

function g5d_admin_link() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'g5d_link' );
	$v = isset( $_POST['link'] ) && 'yes' === $_POST['link'] ? 'yes' : 'no';
	update_option( G5D_LINK_OPT, $v, false );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=g5d&tab=settings' ) );
	exit;
}
add_action( 'admin_post_g5d_link', 'g5d_admin_link' );

function g5d_admin_init() {
	register_setting( 'g5d', G5D_OPT, array( 'sanitize_callback' => 'g5d_sanitize' ) );
	register_setting( 'g5d_show_group', 'g5d_show_past', array( 'sanitize_callback' => function ( $v ) { return empty( $v ) ? 0 : 1; }, 'default' => 0 ) );
	register_setting( 'g5d_show_group', 'g5d_show', array( 'sanitize_callback' => 'g5d_sanitize_show', 'default' => array() ) );
}
add_action( 'admin_init', 'g5d_admin_init' );

function g5d_admin_menu() {
	add_menu_page( 'Draseis Planner', 'Draseis Planner', 'manage_options', 'g5d', 'g5d_admin_page', 'dashicons-calendar-alt', 30 );
}
add_action( 'admin_menu', 'g5d_admin_menu' );

/* -------------------------------------------------------------------------
 * Υπενθύμιση για το email υποβολών
 * ---------------------------------------------------------------------- */

/** Μετά την ενεργοποίηση, ανοίγει απευθείας η σελίδα ρυθμίσεων αν λείπει το email. */
function g5d_on_activate() {
	set_transient( 'g5d_redirect_settings', 1, 60 );
}
register_activation_hook( G5D_FILE, 'g5d_on_activate' );

function g5d_maybe_redirect_after_activation() {
	if ( ! get_transient( 'g5d_redirect_settings' ) || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	delete_transient( 'g5d_redirect_settings' );
	if ( '' === g5d_notify_email() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=g5d&tab=settings' ) );
		exit;
	}
}
add_action( 'admin_init', 'g5d_maybe_redirect_after_activation' );

/** Κόκκινο μήνυμα σε όλες τις σελίδες του WordPress, μέχρι να οριστεί το email. */
function g5d_notify_missing_notice() {
	if ( '' !== g5d_notify_email() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>Draseis Planner:</strong> δεν έχει οριστεί email υποβολών. ' .
		'Η φόρμα δεν δέχεται υποβολές μέχρι να συμπληρωθεί. ' .
		'<a href="' . esc_url( admin_url( 'admin.php?page=g5d&tab=settings' ) ) . '">Συμπληρώστε το εδώ</a>.</p></div>';
}
add_action( 'admin_notices', 'g5d_notify_missing_notice' );

function g5d_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tabs = array(
		'settings' => 'Ρυθμίσεις',
		'display'  => 'Εμφάνιση',
		'backup'   => 'Αντίγραφο',
	);
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'settings';
	}
	echo '<div class="wrap"><h1>Draseis Planner</h1>';
	echo '<nav class="nav-tab-wrapper">';
	printf( '<a class="nav-tab" href="%s">Αιτήσεις</a>', esc_url( admin_url( 'edit.php?post_type=' . G5D_CPT ) ) );
	foreach ( $tabs as $k => $label ) {
		printf( '<a class="nav-tab%s" href="%s">%s</a>', $k === $tab ? ' nav-tab-active' : '', esc_url( admin_url( 'admin.php?page=g5d&tab=' . $k ) ), esc_html( $label ) );
	}
	echo '</nav>';
	if ( 'display' === $tab ) {
		g5d_admin_tab_display();
	} elseif ( 'backup' === $tab ) {
		g5d_admin_tab_backup();
	} else {
		g5d_admin_tab_settings();
	}
	echo '</div>';
}

/** Καρτέλα «Αντίγραφο»: εξαγωγή των εγκεκριμένων δράσεων σε αρχείο CSV. */
function g5d_admin_tab_backup() {
	echo '<div style="max-width:720px;margin-top:20px">';
	echo '<h2 style="margin-top:0">Αντίγραφο ασφαλείας</h2>';
	echo '<p>Κατεβάστε τις εγκεκριμένες δράσεις σε αρχείο CSV.</p>';
	echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=g5d_export_csv' ), 'g5d_export_csv' ) ) . '">Εξαγωγή εγκεκριμένων (CSV)</a></p>';
	echo '</div>';
}

/** Καρτέλα «Εμφάνιση»: τι βλέπουν οι επισκέπτες στη δημόσια σελίδα του προγράμματος. */
function g5d_admin_tab_display() {
	$selected = g5d_show_keys();
	?>
	<style>
		.g5d-display { max-width: 1100px; }
		.g5d-display .g5d-card { background: #fff; border: 1px solid #c3c4c7; margin: 0 0 20px; }
		.g5d-display .g5d-card-head { display: flex; align-items: center; flex-wrap: wrap; gap: 4px 12px; padding: 12px 20px; border-bottom: 1px solid #dcdcde; }
		.g5d-display .g5d-num { width: 26px; height: 26px; border-radius: 50%; background: #2271b1; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; flex-shrink: 0; }
		.g5d-display .g5d-card-title { margin: 0; padding: 0; font-size: 16px; font-weight: 600; }
		.g5d-display .g5d-card-sub { color: #646970; font-size: 13px; }
		.g5d-display .g5d-row { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 20px; padding: 16px 20px; border-bottom: 1px solid #f0f0f1; }
		.g5d-display .g5d-row:last-child { border-bottom: 0; }
		.g5d-display .g5d-lbl { font-weight: 600; padding-top: 1px; }
		.g5d-display .g5d-row .description { margin: 6px 0 0; }
		.g5d-display .g5d-opt { display: block; margin: 4px 0; }
		@media (max-width: 782px) { .g5d-display .g5d-row { grid-template-columns: minmax(0, 1fr); gap: 6px; } }
	</style>
	<p>Τι βλέπουν οι επισκέπτες στη δημόσια σελίδα του προγράμματος. Οι αλλαγές ισχύουν αμέσως.</p>
	<form method="post" action="options.php" class="g5d-display">
		<?php settings_fields( 'g5d_show_group' ); ?>
		<input type="hidden" name="g5d_show[]" value="">
		<section class="g5d-card">
			<div class="g5d-card-head"><span class="g5d-num">1</span><h2 class="g5d-card-title">Ποιες δράσεις φαίνονται</h2></div>
			<div class="g5d-row">
				<div class="g5d-lbl">Παλαιότερες δράσεις</div>
				<div>
					<label><input type="checkbox" name="g5d_show_past" value="1" <?php checked( (bool) get_option( 'g5d_show_past', 0 ) ); ?>> Να εμφανίζονται από την αρχή</label>
					<p class="description">Αν δεν είναι τικαρισμένο, ο πίνακας δείχνει μόνο τις επερχόμενες δράσεις. Ο επισκέπτης μπορεί πάντα να αλλάξει την προβολή με το κουμπί πάνω από τον πίνακα.</p>
					<p class="description">Προχωρημένοι: αν σε μία συγκεκριμένη σελίδα θέλετε να ισχύει κάτι διαφορετικό, γράψτε στο shortcode <code>[draseis_planner past="show"]</code> (να φαίνονται) ή <code>[draseis_planner past="hide"]</code> (να κρύβονται).</p>
				</div>
			</div>
		</section>
		<section class="g5d-card">
			<div class="g5d-card-head"><span class="g5d-num">2</span><h2 class="g5d-card-title">Τι φαίνεται σε κάθε δράση</h2></div>
			<div class="g5d-row">
				<div class="g5d-lbl">Πάντα</div>
				<div>Ημερομηνία, ώρα, τίτλος, τύπος δράσης, χώρος και ομάδα/τμήματα.</div>
			</div>
			<div class="g5d-row">
				<div class="g5d-lbl">Επιπλέον στοιχεία</div>
				<div>
					<?php foreach ( g5d_extra_fields() as $k => $label ) : ?>
						<?php if ( 'action_type' === $k ) { continue; } // Ο τύπος φαίνεται πάντα κάτω από τον τίτλο. ?>
						<label class="g5d-opt"><input type="checkbox" name="g5d_show[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $selected, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php submit_button( 'Αποθήκευση' ); ?>
	</form>
	<?php
}

/** Καρτέλα «Ρυθμίσεις»: στοιχεία σύνδεσης, κατάσταση και κοινός κωδικός. */
/** Δημοσιευμένες σελίδες του site που περιέχουν κάποιο από τα shortcodes. Επιστρέφει διευθύνσεις. */
function g5d_pages_with_shortcode( array $tags ) {
	$out = array();
	foreach ( $tags as $tag ) {
		$posts = get_posts(
			array(
				'post_type'   => array( 'page', 'post' ),
				'post_status' => 'publish',
				'numberposts' => 50,
				's'           => $tag,
			)
		);
		foreach ( $posts as $p ) {
			if ( has_shortcode( $p->post_content, $tag ) ) {
				$out[ $p->ID ] = get_permalink( $p );
			}
		}
	}
	return array_values( $out );
}

function g5d_admin_tab_settings() {
	$s        = g5d_settings();
	$sa       = g5d_gcal_credentials();
	$n        = esc_attr( G5D_OPT );
	$pages    = g5d_pages_with_shortcode( array( 'draseis_planner' ) );
	$formpgs  = g5d_pages_with_shortcode( array( 'draseis_form' ) );
	$exams_on = g5d_exams_active();
	$exams_cl = g5d_exams_classes();
	$own_cl   = g5d_parse_classes( $s['classes'] );
	$link     = g5d_exams_link();
	$use_ex   = $exams_on && 'yes' === $link;
	$sy       = g5d_school_year_start();
	list( , $bad )       = g5d_parse_holidays( $s['holidays'], $sy );
	list( , $bad_local ) = g5d_parse_holidays( $s['holidays_local'], $sy );
	settings_errors( 'g5d' );
	// Το παλιό shortcode [g5d_proposals] καταργήθηκε στην 1.5.1: αν το έχει κάποια σελίδα, το λέμε.
	foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => 20, 's' => 'g5d_proposals' ) ) as $old_p ) {
		if ( false !== strpos( (string) $old_p->post_content, '[g5d_proposals' ) ) {
			echo '<div class="notice notice-warning inline" style="max-width:1100px"><p>Η σελίδα <a href="' . esc_url( get_edit_post_link( $old_p->ID ) ) . '">' . esc_html( get_the_title( $old_p ) ) . '</a> έχει το παλιό <code>[g5d_proposals]</code>, που δεν υποστηρίζεται πια. Αντικαταστήστε το με <code>[draseis_form]</code>.</p></div>';
		}
	}
	if ( isset( $_GET['g5d_msg'] ) && 'holfilled' === $_GET['g5d_msg'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success inline" style="max-width:780px"><p>Οι πανελλαδικές αργίες επαναφέρθηκαν για το σχολικό έτος ' . (int) $sy . '–' . ( (int) $sy + 1 ) . '.</p></div>';
	}
	if ( ! empty( $s['holidays_refreshed'] ) ) {
		// Μήνυμα μία φορά, μετά την αυτόματη ενημέρωση της νέας χρονιάς.
		$opt = (array) get_option( G5D_OPT, array() );
		unset( $opt['holidays_refreshed'] );
		g5d_save_settings_raw( $opt );
		echo '<div class="notice notice-info inline" style="max-width:780px"><p>Οι πανελλαδικές αργίες ενημερώθηκαν αυτόματα για το σχολικό έτος ' . (int) $s['holidays_refreshed'] . '–' . ( (int) $s['holidays_refreshed'] + 1 ) . '. Ελέγξτε αν χρειάζεται κάποια διόρθωση.</p></div>';
	}
	$approved = count(
		get_posts(
			array(
				'post_type'   => G5D_CPT,
				'post_status' => 'private',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => 'g5d_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 'approved', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		)
	);
	?>
	<style>
		.g5d-settings, .g5d-info { max-width: 1100px; }
		.g5d-info { background: #fff; border: 1px solid #c3c4c7; padding: 12px 20px; margin: 16px 0; line-height: 1.9; }
		.g5d-info .g5d-muted { color: #646970; }
		.g5d-card { background: #fff; border: 1px solid #c3c4c7; margin: 0 0 20px; }
		.g5d-card-head { display: flex; align-items: center; flex-wrap: wrap; gap: 4px 12px; padding: 12px 20px; border-bottom: 1px solid #dcdcde; }
		.g5d-num { width: 26px; height: 26px; border-radius: 50%; background: #2271b1; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; flex-shrink: 0; }
		.g5d-card-title { margin: 0; padding: 0; font-size: 16px; font-weight: 600; }
		.g5d-card-sub { color: #646970; font-size: 13px; }
		.g5d-row { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 20px; padding: 16px 20px; border-bottom: 1px solid #f0f0f1; }
		.g5d-row:last-child { border-bottom: 0; }
		.g5d-lbl { font-weight: 600; padding-top: 5px; }
		.g5d-lbl label { font-weight: 600; }
		.g5d-row .description { margin: 6px 0 0; }
		.g5d-opt { display: block; margin: 4px 0; }
		.g5d-sublbl { display: block; font-weight: 600; margin: 14px 0 4px; }
		.g5d-sublbl:first-child { margin-top: 0; }
		.g5d-off { opacity: .5; }
		.g5d-danger { border-left: 4px solid #d63638; padding: 2px 16px; }
		.g5d-settings details { margin-top: 14px; padding-top: 12px; border-top: 1px solid #dcdcde; }
		.g5d-settings summary { cursor: pointer; color: #2271b1; }
		@media (max-width: 782px) { .g5d-row { grid-template-columns: minmax(0, 1fr); gap: 6px; } .g5d-lbl { padding-top: 0; } }
	</style>

	<?php if ( ! $pages || ! $formpgs ) : ?>
		<div class="notice notice-warning inline" style="margin:16px 0;max-width:1100px;padding:10px 14px">
			<p style="margin:.3em 0 .6em"><strong>Λείπει <?php echo ( ! $pages && ! $formpgs ) ? 'η σελίδα του προγράμματος και η σελίδα της φόρμας' : ( ! $pages ? 'η σελίδα του προγράμματος' : 'η σελίδα της φόρμας' ); ?>.</strong></p>
			<ol style="margin:0 0 .6em 1.6em;line-height:1.9">
				<li>Πατήστε το κουμπί: ανοίγει έτοιμη η σελίδα.</li>
				<li>Στη σελίδα που ανοίγει, πατήστε <strong>Δημοσίευση</strong>.</li>
			</ol>
			<p style="margin:.4em 0 .5em;display:flex;flex-wrap:wrap;gap:8px">
				<?php if ( ! $pages ) : ?>
					<button type="submit" form="g5d-create-program" class="button button-primary">Δημιουργία σελίδας προγράμματος</button>
				<?php endif; ?>
				<?php if ( ! $formpgs ) : ?>
					<button type="submit" form="g5d-create-form" class="button button-primary">Δημιουργία σελίδας φόρμας</button>
				<?php endif; ?>
			</p>
			<p style="margin:.2em 0 .4em;color:#646970">Εναλλακτικά: Σελίδες → Προσθήκη νέας, γράψτε μέσα στη σελίδα <code><?php echo ! $pages ? '[draseis_planner]' : '[draseis_form]'; ?></code><?php echo ( ! $pages && ! $formpgs ) ? ' (και σε δεύτερη σελίδα <code>[draseis_form]</code>)' : ''; ?> και πατήστε Δημοσίευση.</p>
		</div>
	<?php endif; ?>
	<div class="g5d-info">
		<?php if ( $pages ) : ?>
			<?php foreach ( $pages as $url ) : ?>
				<div><strong>Σελίδα προγράμματος:</strong> <a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $url ); ?></a></div>
			<?php endforeach; ?>
		<?php else : ?>
			<div><strong>Σελίδα προγράμματος:</strong> δεν βρέθηκε.</div>
		<?php endif; ?>
		<?php if ( $formpgs ) : ?>
			<?php foreach ( $formpgs as $url ) : ?>
				<div><strong>Σελίδα φόρμας:</strong> <a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $url ); ?></a></div>
			<?php endforeach; ?>
		<?php else : ?>
			<div><strong>Σελίδα φόρμας:</strong> δεν βρέθηκε.</div>
		<?php endif; ?>
		<?php if ( 'code' === $s['access'] && '' !== (string) $s['code'] ) : ?>
			<div><strong>Κωδικός καθηγητών:</strong> <code style="font-size:15px"><?php echo esc_html( $s['code'] ); ?></code></div>
		<?php endif; ?>
		<div class="g5d-muted">Αν χρησιμοποιείτε πρόσθετο cache (π.χ. WP Fastest Cache, LiteSpeed, WP Rocket), εξαιρέστε τις δύο σελίδες από την cache, ώστε να εμφανίζονται πάντα οι τρέχουσες δράσεις.</div>
	</div>

	<form class="g5d-settings" method="post" action="options.php">
		<?php settings_fields( 'g5d' ); ?>

		<section class="g5d-card">
			<div class="g5d-card-head"><span class="g5d-num">1</span><h2 class="g5d-card-title">Είσοδος καθηγητών</h2><span class="g5d-card-sub">ποιοι και πώς υποβάλλουν δράσεις</span></div>
			<div class="g5d-row">
				<div class="g5d-lbl">Τρόπος εισόδου</div>
				<div>
					<fieldset>
						<label class="g5d-opt"><input type="radio" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[access]" value="sso" <?php checked( $s['access'], 'sso' ); ?>> Με τον <strong>λογαριασμό ΠΣΔ (sch.gr)</strong>, μόνο εκπαιδευτικοί της σχολικής μονάδας με εγγραφή στο ΠΣΔ</label>
						<label class="g5d-opt"><input type="radio" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[access]" value="code" <?php checked( $s['access'], 'code' ); ?>> Με <strong>κοινό κωδικό καθηγητών</strong>, που τον ορίζετε εσείς παρακάτω</label>
					</fieldset>
				</div>
			</div>
			<div class="g5d-row g5d-code-row">
				<div class="g5d-lbl"><label for="dgn-code">Κωδικός καθηγητών</label></div>
				<div>
					<input id="dgn-code" class="regular-text" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[code]" value="<?php echo esc_attr( $s['code'] ); ?>" autocomplete="off">
					<p class="description">Αν τον αλλάξετε, όσοι είχαν μπει με τον παλιό θα πρέπει να γράψουν τον νέο. Οι δηλώσεις τους μένουν.</p>
				</div>
			</div>
			<div class="g5d-row g5d-sso-row">
				<div class="g5d-lbl">Σύνδεση με ΠΣΔ</div>
				<div>
					<?php if ( ! g5d_sso_requirements_ok() ) : ?>
						<p style="color:#b32d2e"><strong>Ο server δεν έχει τις επεκτάσεις PHP curl/dom που χρειάζονται.</strong> Ζητήστε τις από τη φιλοξενία.</p>
					<?php endif; ?>
					<fieldset>
						<span class="g5d-sublbl">Διακομιστής ΠΣΔ</span>
						<label class="g5d-opt"><input type="radio" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[sso_server]" value="prod" <?php checked( $s['sso_server'], 'prod' ); ?>> Κανονικός (<code>sso.sch.gr</code>)</label>
						<label class="g5d-opt"><input type="radio" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[sso_server]" value="test" <?php checked( $s['sso_server'], 'test' ); ?>> Δοκιμαστικός (<code>sso-01-test.sch.gr</code>) — μόνο όσο το ΠΣΔ έχει εγγράψει την υπηρεσία εκεί</label>
					</fieldset>
					<label class="g5d-sublbl" for="g5d_sso_service">Διεύθυνση υπηρεσίας <span style="font-weight:400">(ακριβώς όπως δηλώθηκε στο ΠΣΔ)</span></label>
					<input type="url" id="g5d_sso_service" class="large-text code" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[sso_service]" value="<?php echo esc_attr( $s['sso_service'] ); ?>" placeholder="https://example.sch.gr/draseis-planner/">
					<label class="g5d-sublbl" for="g5d_sso_units">Κωδικός σχολικής μονάδας <span style="font-weight:400">(όπως στο myschool· περισσότεροι με κόμμα)</span></label>
					<input type="text" id="g5d_sso_units" class="regular-text" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[sso_units]" value="<?php echo esc_attr( $s['sso_units'] ); ?>">
					<label class="g5d-sublbl" for="g5d_sso_roles">Ρόλοι που επιτρέπονται <span style="font-weight:400">(τιμές του umdobject)</span></label>
					<input type="text" id="g5d_sso_roles" class="regular-text" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[sso_roles]" value="<?php echo esc_attr( $s['sso_roles'] ); ?>">
					<details>
						<summary>Οδηγίες για την αίτηση στο ΠΣΔ</summary>
						<p class="description">Χρειάζεται και η «Σελίδα φόρμας» παρακάτω. Στο ΠΣΔ ζητήστε τα attributes: <code>uid, sn, umdobject, edupersonorgunitdn:gsnunitcode:extended</code>.<br>
						Διεύθυνση που στέλνεται στο ΠΣΔ: <code><?php echo esc_html( g5d_sso_service_url( $s ) ); ?></code></p>
					</details>
				</div>
			</div>
		</section>

		<section class="g5d-card">
			<div class="g5d-card-head"><span class="g5d-num">2</span><h2 class="g5d-card-title">Υποβολές</h2><span class="g5d-card-sub">προθεσμία και ειδοποιήσεις</span></div>
			<div class="g5d-row">
				<div class="g5d-lbl"><label for="g5d-notify">Email υποβολών <span style="color:#b42318">(υποχρεωτικό)</span></label></div>
				<div>
					<input type="email" id="g5d-notify" class="regular-text" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>" required>
					<?php if ( '' === (string) $s['notify_email'] ) : ?>
						<p class="description" style="color:#d63638">Συμπληρώστε το email. Όσο λείπει, η φόρμα δεν δέχεται υποβολές.</p>
					<?php endif; ?>
					<p class="description">Εδώ φτάνουν τα email με τις νέες υποβολές και τα κουμπιά έγκρισης ή απόρριψης.</p>
				</div>
			</div>
			<div class="g5d-row">
				<div class="g5d-lbl"><label for="g5d-classes">Τμήματα</label></div>
				<div>
					<?php if ( $use_ex ) : ?>
						<input type="hidden" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[classes]" value="<?php echo esc_attr( $s['classes'] ); ?>">
						<textarea id="g5d-classes" class="code" rows="6" style="width:200px" readonly><?php echo esc_textarea( implode( "\n", $exams_cl ) ); ?></textarea>
						<?php if ( ! $exams_cl ) : ?>
							<p class="description" style="color:#d63638">Το Exams Planner δεν έχει ακόμα τμήματα. Συμπληρώστε τα εκεί ή επιλέξτε «Όχι» στη σύνδεση.</p>
						<?php endif; ?>
						<p class="description">Τα τμήματα έρχονται από το Exams Planner. Για να τα αλλάξετε, πηγαίνετε στο <a href="<?php echo esc_url( admin_url( 'admin.php?page=exams-planner' ) ); ?>">Exams Planner → Ρυθμίσεις</a>.</p>
					<?php else : ?>
						<textarea id="g5d-classes" class="code" rows="6" style="width:200px" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[classes]"><?php echo esc_textarea( $s['classes'] ); ?></textarea>
						<?php if ( ! $own_cl ) : ?>
							<p class="description" style="color:#d63638">Συμπληρώστε τα τμήματα. Όσο λείπουν, η φόρμα δείχνει μόνο το «Όλο το σχολείο».</p>
						<?php endif; ?>
						<p class="description">Ένα τμήμα ανά γραμμή, π.χ. Α1, Α2, Β1. Η τάξη βγαίνει από το πρώτο γράμμα. Οι αιτήσεις που έχουν ήδη υποβληθεί κρατούν τα τμήματά τους.</p>
					<?php endif; ?>
					<?php if ( $exams_on ) : ?>
						<div style="margin-top:12px;padding:10px 12px;background:#f6f7f7;border-left:4px solid <?php echo '' === $link ? '#dba617' : '#c3c4c7'; ?>">
							<strong>Σύνδεση με το Exams Planner</strong>
							<?php if ( '' === $link ) : ?>
								<p style="margin:4px 0 6px">Στο site σας είναι εγκατεστημένο και το Exams Planner (πρόγραμμα διαγωνισμάτων). Επιλέξτε αν θα συνδεθούν τα δύο plugins.</p>
								<?php echo g5d_link_compare_html( $own_cl, $exams_cl ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
							<label style="display:block;margin-top:6px"><input type="radio" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[exams_link]" value="yes" <?php checked( $link, 'yes' ); ?>> <strong>Ναι:</strong> τα τμήματα έρχονται από το Exams Planner και η φόρμα δεν δέχεται δράση την ημέρα που ένα τμήμα της έχει διαγώνισμα.</label>
							<label style="display:block;margin-top:4px"><input type="radio" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[exams_link]" value="no" <?php checked( $link, 'no' ); ?>> <strong>Όχι:</strong> τα δύο plugins λειτουργούν ανεξάρτητα, με δικά τους τμήματα και χωρίς έλεγχο διαγωνισμάτων.</label>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<div class="g5d-row">
				<div class="g5d-lbl"><label for="g5d_form_url">Σελίδα φόρμας</label></div>
				<div>
					<input type="url" id="g5d_form_url" class="large-text code" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[form_url]" value="<?php echo esc_attr( $s['form_url'] ); ?>">
					<p class="description">Η διεύθυνση της σελίδας με τη φόρμα υποβολής. Συμπληρώνεται αυτόματα όταν δημοσιεύετε τη σελίδα. Από αυτή εμφανίζεται το κουμπί «Είσοδος καθηγητών» κάτω από το πρόγραμμα. Αφήστε κενό αν δεν θέλετε να φαίνεται το κουμπί.</p>
				</div>
			</div>
			<div class="g5d-row">
				<div class="g5d-lbl"><label for="g5d_lead_days">Προθεσμία υποβολής</label></div>
				<div>
					<input type="number" id="g5d_lead_days" min="0" max="60" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[lead_days]" value="<?php echo (int) $s['lead_days']; ?>" style="width:60px;height:35px;min-height:35px;vertical-align:middle"> εργάσιμες ημέρες πριν τη δράση, χωρίς Σαββατοκύριακα και αργίες. Το 0 σημαίνει χωρίς προθεσμία.
				</div>
			</div>
		</section>

		<section class="g5d-card">
			<div class="g5d-card-head"><span class="g5d-num">3</span><h2 class="g5d-card-title">Αργίες</h2><span class="g5d-card-sub">ημέρες που δεν προσμετρώνται στην προθεσμία</span></div>
			<div class="g5d-row">
				<div class="g5d-lbl"><label for="g5d-holidays">Πανελλαδικές αργίες <?php echo (int) $sy . '–' . ( (int) $sy + 1 ); ?></label></div>
				<div>
					<textarea id="g5d-holidays" class="large-text code" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[holidays]" rows="9"><?php echo esc_textarea( $s['holidays'] ); ?></textarea>
					<?php if ( $bad ) : ?>
						<p style="color:#b32d2e"><strong>Δεν αναγνωρίστηκαν:</strong> <?php echo esc_html( implode( ' · ', $bad ) ); ?></p>
					<?php endif; ?>
					<label style="display:block;margin:8px 0 4px"><input type="checkbox" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[holidays_auto]" value="1" <?php checked( ! empty( $s['holidays_auto'] ) ); ?>> Αυτόματη ενημέρωση των πανελλαδικών αργιών κάθε σχολική χρονιά</label>
					<p class="description" style="margin:0 0 8px">Κάθε νέα σχολική χρονιά το πεδίο ξαναγεμίζει μόνο του με τις αργίες της. Μέσα στη χρονιά μπορείτε να το διορθώσετε, π.χ. αν μετατεθεί η Πρωτομαγιά. Βγάλτε το τικ αν θέλετε να το συμπληρώνετε μόνοι σας.</p>
					<p style="margin:0"><button type="submit" form="g5d-fill-holidays" class="button" onclick="return confirm('Το πεδίο «Πανελλαδικές αργίες» θα αντικατασταθεί με τις αργίες του <?php echo (int) $sy . '–' . ( (int) $sy + 1 ); ?>. Συνέχεια;');">Επαναφορά πανελλαδικών αργιών</button> <span style="color:#646970">ξαναγράφει το πεδίο με τις αργίες του <?php echo (int) $sy . '–' . ( (int) $sy + 1 ); ?>, αν το έχετε αλλάξει ή σβήσει κατά λάθος</span></p>
				</div>
			</div>
			<div class="g5d-row">
				<div class="g5d-lbl"><label for="g5d-holidays-local">Τοπικές αργίες</label></div>
				<div>
					<textarea id="g5d-holidays-local" class="large-text code" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[holidays_local]" rows="3"><?php echo esc_textarea( $s['holidays_local'] ); ?></textarea>
					<?php if ( $bad_local ) : ?>
						<p style="color:#b32d2e"><strong>Δεν αναγνωρίστηκαν:</strong> <?php echo esc_html( implode( ' · ', $bad_local ) ); ?></p>
					<?php endif; ?>
					<p class="description">Του σχολείου ή της πόλης σας, μία ανά γραμμή. Χωρίς έτος ισχύει κάθε χρόνο: <code>11-08 | Πολιούχος</code>. Με έτος ισχύει μόνο τότε: <code>2027-05-20 έως 2027-05-21 | Επέτειος</code>. Οι αργίες δεν προσμετρώνται στην προθεσμία.</p>
				</div>
			</div>
		</section>

		<section class="g5d-card">
			<div class="g5d-card-head"><span class="g5d-num">4</span><h2 class="g5d-card-title">Google Calendar</h2><span class="g5d-card-sub">προαιρετικά, βασικά στοιχεία των εγκεκριμένων δράσεων γράφονται αυτόματα και στο google ημερολόγιο σας</span></div>
			<div class="g5d-row">
				<div class="g5d-lbl"><label for="g5d_sa">Λογαριασμός υπηρεσίας</label></div>
				<div>
					<p style="margin:0 0 8px"><strong>Κατάσταση:</strong> <?php echo $sa ? 'συνδεδεμένος (' . esc_html( $sa['client_email'] ) . ')' : 'δεν έχει οριστεί'; ?></p>
					<textarea id="g5d_sa" class="large-text code" rows="4" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[service_account]" autocomplete="off" spellcheck="false" placeholder="Επικολλήστε το περιεχόμενο του αρχείου JSON"></textarea>
					<p class="description">Το κλειδί δεν εμφανίζεται ξανά μετά την αποθήκευση. Αφήστε κενό για να διατηρηθεί το υπάρχον.</p>
				</div>
			</div>
			<div class="g5d-row">
				<div class="g5d-lbl"><label for="g5d_calendar_id">ID ημερολογίου</label></div>
				<div>
					<input type="text" id="g5d_calendar_id" class="large-text code" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[calendar_id]" value="<?php echo esc_attr( $s['calendar_id'] ); ?>">
					<p class="description">Google Calendar → Ρυθμίσεις του ημερολογίου → «Ενσωμάτωση ημερολογίου» → «Αναγνωριστικό ημερολογίου».</p>
				</div>
			</div>
			<div class="g5d-row">
				<div class="g5d-lbl">Κατάσταση συγχρονισμού</div>
				<div>
					<p style="margin:0 0 10px">Εγκεκριμένες δράσεις στη βάση: <strong><?php echo esc_html( $approved ); ?></strong>. Η σελίδα του προγράμματος τις διαβάζει απευθείας από εδώ, χωρίς αναμονή.</p>
					<button type="submit" form="g5d-resync-form" class="button" formnovalidate>Επανάληψη συγχρονισμού με Google Calendar</button>
				</div>
			</div>
		</section>

		<section class="g5d-card">
			<div class="g5d-card-head"><span class="g5d-num">5</span><h2 class="g5d-card-title">Δεδομένα</h2><span class="g5d-card-sub">τι γίνεται με τις υποβολές και τις ρυθμίσεις</span></div>
			<div class="g5d-row">
				<div class="g5d-lbl">Διαγραφή πρόσθετου</div>
				<div class="g5d-danger">
					<label><input type="checkbox" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[delete_data]" value="1" <?php checked( ! empty( $s['delete_data'] ) ); ?>> Να σβήνονται όλα τα δεδομένα όταν διαγράφεται το πρόσθετο</label>
					<p class="description">Αν το checkbox παραμένει χωρίς τικ (προτείνεται), οι υποβολές και οι ρυθμίσεις παραμένουν στη βάση δεδομένων. Έτσι, αν το πρόσθετο διαγραφεί και εγκατασταθεί ξανά, όλα θα είναι διαθέσιμα όπως πριν.</p>
					<p class="description">Επιλέξτε το checkbox μόνο αν θέλετε, κατά τη διαγραφή του πρόσθετου, να διαγραφούν οριστικά όλες οι υποβολές και οι ρυθμίσεις του. Οι εγγραφές στο Google Calendar δεν διαγράφονται σε καμία περίπτωση.</p>
				</div>
			</div>
		</section>

		<?php submit_button( 'Αποθήκευση' ); ?>
	</form>

	<form id="g5d-resync-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="g5d_gcal_resync">
		<?php wp_nonce_field( 'g5d_gcal_resync' ); ?>
	</form>
	<form id="g5d-create-program" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="g5d_create_page"><input type="hidden" name="which" value="program">
		<?php wp_nonce_field( 'g5d_create_page_program' ); ?>
	</form>
	<form id="g5d-create-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="g5d_create_page"><input type="hidden" name="which" value="form">
		<?php wp_nonce_field( 'g5d_create_page_form' ); ?>
	</form>
	<form id="g5d-fill-holidays" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="g5d_fill_holidays">
		<?php wp_nonce_field( 'g5d_fill_holidays' ); ?>
	</form>

	<script>
	( function () {
		var opt = document.querySelector( 'form[action="options.php"]' );
		function sync() {
			var cur = ( document.querySelector( 'input[name="g5d_settings[access]"]:checked' ) || {} ).value;
			var sso = 'sso' === cur;
			[ [ '.g5d-sso-row', sso ], [ '.g5d-code-row', ! sso ] ].forEach( function ( pair ) {
				document.querySelectorAll( pair[0] ).forEach( function ( row ) {
					row.classList.toggle( 'g5d-off', ! pair[1] );
					row.querySelectorAll( 'input, textarea, select' ).forEach( function ( el ) { el.disabled = ! pair[1]; } );
				} );
			} );
		}
		document.querySelectorAll( 'input[name="g5d_settings[access]"]' ).forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
		// Τα ανενεργά πεδία δεν στέλνονται· τα ενεργοποιούμε πριν την αποθήκευση, ώστε να μη χαθούν οι τιμές τους.
		if ( opt ) {
			opt.addEventListener( 'submit', function () {
				opt.querySelectorAll( '.g5d-off input, .g5d-off textarea, .g5d-off select' ).forEach( function ( el ) { el.disabled = false; } );
			} );
		}
		sync();
	} )();
	</script>
	<?php
}

function g5d_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=g5d' ) ) . '">Ρυθμίσεις</a>' );
	return $links;
}

add_action( 'admin_post_g5d_export_csv', 'g5d_export_csv_action' );

/** Εξαγωγή των εγκεκριμένων δράσεων σε CSV (διαχωριστής «;» για ελληνικό Excel). */
function g5d_export_csv_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν επιτρέπεται.' );
	}
	check_admin_referer( 'g5d_export_csv' );

	$ids = get_posts(
		array(
			'post_type'   => G5D_CPT,
			'post_status' => 'private',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => 'g5d_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => 'approved', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	$rows = array();
	foreach ( $ids as $id ) {
		$d      = g5d_request_data( $id );
		$grades = is_array( $d['grades'] ) ? implode( ', ', $d['grades'] ) : (string) $d['grades'];
		$time   = '' === (string) $d['time_from'] ? 'Ολοήμερη' : (string) $d['time_from'];
		$rows[] = array(
			(string) $d['date_from'],
			(string) $d['date_to'],
			$time,
			(string) $d['time_to'],
			(string) $d['action_type'],
			(string) $d['title'],
			(string) $d['place'],
			$grades,
			(string) $d['group_name'],
			(string) $d['subject'],
			(string) $d['students'],
			(string) $d['teachers'],
			(string) $d['companions'],
			(string) $d['companions_alt'],
			(string) $d['transport'],
			(string) $d['description'],
			(string) $d['materials'],
		);
	}
	usort(
		$rows,
		function ( $a, $b ) {
			return strcmp( $a[0], $b[0] );
		}
	);

	$headers = array( 'Ημερομηνία', 'Έως', 'Ώρα από', 'Ώρα έως', 'Τύπος δράσης', 'Τίτλος δράσης', 'Χώρος', 'Τμήματα', 'Ομάδα', 'Μάθημα / θεματικός άξονας', 'Αριθμός μαθητών', 'Υπεύθυνοι εκπ/κοί', 'Συνοδοί εκπ/κοί', 'Αναπληρωτές συνοδοί εκπ/κοί', 'Μέσα μεταφοράς', 'Σύντομη περιγραφή', 'Υλικά' );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="draseis-egkekrimenes-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM, ώστε το Excel να διαβάσει σωστά τα ελληνικά.
	fputcsv( $out, $headers, ';' );
	foreach ( $rows as $row ) {
		// Προστασία από τύπους Excel: κείμενο που αρχίζει με = + - @ γράφεται με μπροστινό apostrophe.
		$row = array_map(
			function ( $v ) {
				return preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
			},
			$row
		);
		fputcsv( $out, $row, ';' );
	}
	fclose( $out );
	exit;
}
