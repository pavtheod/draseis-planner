<?php
/**
 * Draseis Planner: φόρμα πρότασης, έλεγχος, email και αποφάσεις.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ελέγχει τα πεδία της φόρμας. Καθαρή συνάρτηση.
 * Επιστρέφει [λάθη (κλειδιά πεδίων), καθαρά δεδομένα].
 */
function g5d_validate_request( array $in ) {
	$errors = array();
	$c      = array();
	$get    = function ( $k ) use ( $in ) {
		return isset( $in[ $k ] ) && is_string( $in[ $k ] ) ? trim( $in[ $k ] ) : '';
	};

	$c['title']       = sanitize_text_field( $get( 'title' ) );
	$type             = $get( 'action_type' );
	if ( ! in_array( $type, g5d_action_types(), true ) ) {
		$errors[] = 'action_type';
		$type     = '';
	}
	$c['action_type'] = $type;
	$c['place']       = sanitize_text_field( $get( 'place' ) );
	$c['subject']     = sanitize_text_field( $get( 'subject' ) );
	$c['teachers']    = sanitize_text_field( $get( 'teachers' ) );
	$c['companions']  = sanitize_text_field( $get( 'companions' ) );
	$c['companions_alt'] = sanitize_text_field( $get( 'companions_alt' ) );
	$c['transport']   = sanitize_text_field( $get( 'transport' ) );
	$c['description'] = sanitize_textarea_field( $get( 'description' ) );
	$c['materials']   = sanitize_textarea_field( $get( 'materials' ) );
	$students         = $get( 'students' );
	$c['students']    = '' === $students ? '' : (string) absint( $students );

	if ( '' === $c['title'] ) {
		$errors[] = 'title';
	}
	// Αριθμός μαθητών: υποχρεωτικός και θετικός.
	if ( '' === $c['students'] || '0' === $c['students'] ) {
		$errors[] = 'students';
	}
	// Ώρα: «από» προαιρετική (κενό = ολοήμερη)· «έως» προαιρετική, μόνο αν υπάρχει «από», και μεγαλύτερη από αυτή.
	$from_t = $get( 'time_from' );
	$to_t   = $get( 'time_to' );
	$c['time_from'] = '';
	$c['time_to']   = '';
	if ( '' !== $from_t ) {
		$t_from = g5d_clean_time( $from_t );
		if ( false === $t_from ) {
			$errors[] = 'time_from';
		} else {
			$c['time_from'] = $t_from;
		}
	}
	if ( '' !== $to_t ) {
		$t_to = g5d_clean_time( $to_t );
		// Αν η «από» είναι άκυρη, δείχνουμε μόνο αυτό το λάθος.
		if ( false === $t_to || '' === $from_t || ( '' !== $c['time_from'] && $t_to <= $c['time_from'] ) ) {
			$errors[] = 'time_to';
		} else {
			$c['time_to'] = $t_to;
		}
	}
	foreach ( array( 'place', 'transport', 'subject', 'teachers', 'companions', 'description' ) as $k ) {
		if ( '' === $c[ $k ] ) {
			$errors[] = $k;
		}
	}
	// Περιγραφή: από 20 έως 400 χαρακτήρες.
	if ( '' !== $c['description'] ) {
		$len = mb_strlen( $c['description'], 'UTF-8' );
		if ( $len < 20 || $len > 400 ) {
			$errors[] = 'description';
		}
	}

	$from = g5d_clean_date( $get( 'date_from' ) );
	if ( false === $from ) {
		$errors[]         = 'date_from';
		$c['date_from']   = '';
	} else {
		$c['date_from'] = $from;
	}

	$to_raw = $get( 'date_to' );
	$to     = '' === $to_raw ? '' : g5d_clean_date( $to_raw );
	if ( false === $to || ( '' !== $to && false !== $from && $to < $from ) ) {
		$errors[] = 'date_to';
		$to       = '';
	}
	$c['date_to'] = (string) $to;

	$raw = isset( $in['grades'] ) && is_array( $in['grades'] ) ? array_map( 'strval', $in['grades'] ) : array();
	$ok  = array_values( array_intersect( $raw, array_merge( g5d_grade_options(), array( 'all' ) ) ) );
	$c['grades'] = in_array( 'all', $ok, true ) ? array( 'all' ) : $ok;

	// Τα τμήματα είναι πάντα υποχρεωτικά, ακόμα και για ομάδα, ώστε να ξέρουμε από ποια τμήματα είναι τα παιδιά.
	if ( empty( $ok ) ) {
		$errors[] = 'grades';
	}
	$c['group_name'] = sanitize_text_field( $get( 'group_name' ) );

	// Όριο μήκους στα ελεύθερα πεδία (τα ίδια όρια έχει και η φόρμα με maxlength).
	foreach ( g5d_field_max() as $k => $max ) {
		if ( isset( $c[ $k ] ) && mb_strlen( $c[ $k ], 'UTF-8' ) > $max ) {
			$c[ $k ] = mb_substr( $c[ $k ], 0, $max, 'UTF-8' );
		}
	}

	// Πολυήμερες δράσεις (με διανυκτέρευση) δεν έχουν ώρες: μόνο ημερομηνίες.
	if ( '' !== $c['date_to'] && $c['date_to'] > $c['date_from'] ) {
		$c['time_from'] = '';
		$c['time_to']   = '';
		$errors         = array_values( array_diff( $errors, array( 'time_from', 'time_to' ) ) );
	}

	return array( $errors, $c );
}

/** Μέγιστο μήκος (χαρακτήρες) των ελεύθερων πεδίων. Η περιγραφή έχει δικό της έλεγχο (20–400). */
function g5d_field_max() {
	return array(
		'title'      => 150,
		'place'      => 150,
		'group_name' => 150,
		'subject'    => 150,
		'teachers'   => 300,
		'companions' => 300,
		'companions_alt' => 300,
		'transport'  => 150,
		'materials'  => 1000,
	);
}

/** Επιστρέφει 'HH:MM' αν η ώρα είναι έγκυρη, αλλιώς false. */
function g5d_clean_time( $s ) {
	$s = trim( (string) $s );
	if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $s ) ) {
		return false;
	}
	return $s;
}

function g5d_token_new() {
	return bin2hex( random_bytes( 32 ) );
}

/** Ισχύει μόνο αν το διακριτικό ταιριάζει, δεν έχει λήξει και δεν έχει χρησιμοποιηθεί. */
function g5d_token_ok( array $meta, $token, $now ) {
	if ( ! is_string( $token ) || '' === $token || empty( $meta['token_hash'] ) || ! empty( $meta['token_used'] ) ) {
		return false;
	}
	if ( (int) $meta['token_expires'] < $now ) {
		return false;
	}
	return hash_equals( (string) $meta['token_hash'], hash( 'sha256', $token ) );
}

/** Αποθηκεύει την αίτηση ως ιδιωτική ανάρτηση. Επιστρέφει [id, διακριτικό] ή null. */
function g5d_create_request( array $c ) {
	$id = wp_insert_post(
		array(
			'post_type'   => G5D_CPT,
			'post_status' => 'private',
			'post_title'  => $c['title'],
		),
		true
	);
	if ( is_wp_error( $id ) || ! $id ) {
		return null;
	}
	foreach ( $c as $k => $v ) {
		update_post_meta( $id, 'g5d_' . $k, $v );
	}
	$token = g5d_token_new();
	update_post_meta( $id, 'g5d_status', 'pending' );
	update_post_meta( $id, 'g5d_token_hash', hash( 'sha256', $token ) );
	update_post_meta( $id, 'g5d_token_expires', time() + G5D_TOKEN_DAYS * DAY_IN_SECONDS );
	update_post_meta( $id, 'g5d_token_used', 0 );
	return array( (int) $id, $token );
}

function g5d_request_data( $id ) {
	$keys = array( 'title', 'action_type', 'date_from', 'date_to', 'time_from', 'time_to', 'place', 'transport', 'grades', 'group_name', 'subject', 'students', 'teachers', 'companions', 'companions_alt', 'description', 'materials', 'status', 'token_hash', 'token_expires', 'token_used', 'decided_at', 'decision_note', 'import_key' );
	$d    = array();
	foreach ( $keys as $k ) {
		$d[ $k ] = get_post_meta( $id, 'g5d_' . $k, true );
	}
	return $d;
}

function g5d_display_value( $k, $v ) {
	if ( 'time_from' === $k && '' === (string) $v ) {
		return 'Ολοήμερη';
	}
	if ( 'grades' === $k ) {
		if ( ! is_array( $v ) ) {
			return (string) $v;
		}
		return in_array( 'all', $v, true ) ? 'Όλο το σχολείο' : implode( ', ', $v );
	}
	if ( ( 'date_from' === $k || 'date_to' === $k ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $v, $m ) ) {
		return $m[3] . '/' . $m[2] . '/' . $m[1];
	}
	return is_array( $v ) ? '' : (string) $v;
}

/** Γραμμές πίνακα με τα πεδία, κοινές για το email και τη σελίδα επιβεβαίωσης. */
function g5d_rows_html( array $vals ) {
	$rows = '';
	foreach ( g5d_field_labels() as $k => $label ) {
		$val = g5d_display_value( $k, isset( $vals[ $k ] ) ? $vals[ $k ] : '' );
		if ( '' === $val ) {
			continue;
		}
		$rows .= '<tr><td style="padding:6px 14px 6px 0;color:#64748b;vertical-align:top">' . esc_html( $label ) . '</td>'
			. '<td style="padding:6px 0;color:#1e293b">' . nl2br( esc_html( $val ) ) . '</td></tr>';
	}
	return '<table style="border-collapse:collapse;font-size:15px">' . $rows . '</table>';
}

function g5d_send_request_mail( $id, array $c, $token ) {
	$base    = add_query_arg( array( 'g5d_decide' => $id, 't' => $token ), home_url( '/' ) );
	$approve = add_query_arg( 'do', 'approve', $base );
	$reject  = add_query_arg( 'do', 'reject', $base );
	$btn     = 'display:inline-block;padding:12px 22px;border-radius:8px;color:#fff;text-decoration:none;font-weight:600;margin-right:10px;';
	$html    = '<div style="font-family:Arial,sans-serif;color:#1e293b">'
		. '<p>Νέα πρόταση δράσης από εκπαιδευτικό.</p>'
		. g5d_rows_html( $c )
		. '<p style="margin-top:24px">'
		. '<a href="' . esc_url( $approve ) . '" style="' . $btn . 'background:#15803d">Έγκριση</a>'
		. '<a href="' . esc_url( $reject ) . '" style="' . $btn . 'background:#b91c1c">Απόρριψη</a></p>'
		. '<p style="font-size:13px;color:#64748b">Τα κουμπιά ανοίγουν σελίδα επιβεβαίωσης. Ο σύνδεσμος ισχύει 14 μέρες και μόνο μία φορά.</p>'
		. '</div>';
	return wp_mail( g5d_notify_email(), 'Νέα πρόταση δράσης: ' . $c['title'], $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/** Απλή σελίδα για την επιβεβαίωση. Το $html είναι ήδη ασφαλές. */
function g5d_render_page( $heading, $html ) {
	nocache_headers();
	echo '<!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $heading ) . '</title></head>'
		. '<body style="margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#1e293b"><main style="max-width:640px;margin:32px auto;padding:24px;background:#fff;border-radius:12px">'
		. '<h1 style="font-size:22px;margin:0 0 16px">' . esc_html( $heading ) . '</h1>' . $html . '</main></body></html>';
	exit;
}

/** Σελίδα επιβεβαίωσης, όταν ο διευθυντής ανοίγει τον σύνδεσμο από το email. */
function g5d_decision_page() {
	if ( empty( $_GET['g5d_decide'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$id    = absint( wp_unslash( $_GET['g5d_decide'] ) ); // phpcs:ignore
	$token = isset( $_GET['t'] ) ? (string) wp_unslash( $_GET['t'] ) : ''; // phpcs:ignore
	$do    = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : ''; // phpcs:ignore
	$post  = $id ? get_post( $id ) : null;
	if ( ! $post || G5D_CPT !== $post->post_type ) {
		g5d_render_page( 'Η αίτηση δεν βρέθηκε', '<p>Ελέγξτε τον σύνδεσμο.</p>' );
	}
	$d = g5d_request_data( $id );
	if ( 'pending' !== $d['status'] ) {
		$words = array( 'approved' => 'εγκριθεί', 'rejected' => 'απορριφθεί', 'cancelled' => 'ακυρωθεί' );
		$word  = isset( $words[ $d['status'] ] ) ? $words[ $d['status'] ] : 'αποφασιστεί';
		g5d_render_page( 'Η πρόταση έχει ήδη ' . $word, '<p>Δεν χρειάζεται άλλη ενέργεια.</p>' );
	}
	if ( ! g5d_token_ok( $d, $token, time() ) ) {
		g5d_render_page( 'Ο σύνδεσμος δεν ισχύει', '<p>Ο σύνδεσμος έχει λήξει ή έχει ήδη χρησιμοποιηθεί.</p>' );
	}
	if ( ! in_array( $do, array( 'approve', 'reject' ), true ) ) {
		g5d_render_page( 'Άκυρος σύνδεσμος', '<p>Ο σύνδεσμος δεν είναι πλήρης.</p>' );
	}
	$note_field = 'reject' === $do
		? '<label style="display:block;margin:16px 0">Αιτιολογία (προαιρετικά)<textarea name="note" rows="3" style="display:block;width:100%;margin-top:6px"></textarea></label>'
		: '';
	$label  = 'approve' === $do ? 'Επιβεβαίωση έγκρισης' : 'Επιβεβαίωση απόρριψης';
	$colour = 'approve' === $do ? '#15803d' : '#b91c1c';
	$html   = g5d_rows_html( $d )
		. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:20px">'
		. '<input type="hidden" name="action" value="g5d_decide">'
		. '<input type="hidden" name="id" value="' . (int) $id . '">'
		. '<input type="hidden" name="t" value="' . esc_attr( $token ) . '">'
		. '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
		. $note_field
		. '<button type="submit" style="padding:12px 22px;border:0;border-radius:8px;color:#fff;font-weight:600;font-size:15px;background:' . $colour . ';cursor:pointer">' . esc_html( $label ) . '</button>'
		. '</form>';
	g5d_render_page( 'approve' === $do ? 'Έγκριση δράσης' : 'Απόρριψη δράσης', $html );
}
add_action( 'template_redirect', 'g5d_decision_page' );

function g5d_decide_action() {
	$id    = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore
	$token = isset( $_POST['t'] ) ? (string) wp_unslash( $_POST['t'] ) : ''; // phpcs:ignore
	$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore
	$note  = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore
	$post  = $id ? get_post( $id ) : null;
	if ( ! $post || G5D_CPT !== $post->post_type || ! in_array( $do, array( 'approve', 'reject' ), true ) ) {
		g5d_render_page( 'Άκυρη ενέργεια', '<p>Ο σύνδεσμος δεν είναι πλήρης.</p>' );
	}
	$d = g5d_request_data( $id );
	if ( 'pending' !== $d['status'] || ! g5d_token_ok( $d, $token, time() ) ) {
		g5d_render_page( 'Ο σύνδεσμος δεν ισχύει', '<p>Ο σύνδεσμος έχει λήξει ή έχει ήδη χρησιμοποιηθεί.</p>' );
	}
	if ( 'approve' === $do ) {
		$conflicts = g5d_exam_conflicts( is_array( $d['grades'] ) ? $d['grades'] : array(), $d['date_from'], $d['date_to'] );
		if ( $conflicts ) {
			update_post_meta( $id, 'g5d_status', 'rejected' );
			update_post_meta( $id, 'g5d_token_used', 1 );
			update_post_meta( $id, 'g5d_decided_at', time() );
			update_post_meta( $id, 'g5d_decision_note', 'Σύγκρουση με διαγώνισμα: ' . g5d_conflict_text( $conflicts ) );
			g5d_render_page( 'Η δράση δεν εγκρίθηκε', '<p>Υπάρχει διαγώνισμα την ίδια ημέρα: ' . esc_html( g5d_conflict_text( $conflicts ) ) . '. Η αίτηση σημειώθηκε ως απορριφθείσα· ο καθηγητής πρέπει να υποβάλει νέα πρόταση με άλλη ημερομηνία.</p>' );
		}
	}
	update_post_meta( $id, 'g5d_status', 'approve' === $do ? 'approved' : 'rejected' );
	if ( 'approve' === $do ) {
		g5d_gcal_sync_request( $id );
	}
	update_post_meta( $id, 'g5d_token_used', 1 );
	update_post_meta( $id, 'g5d_decided_at', time() );
	update_post_meta( $id, 'g5d_decision_note', $note );
	g5d_render_page(
		'approve' === $do ? 'Η δράση εγκρίθηκε' : 'Η δράση απορρίφθηκε',
		'<p>Ευχαριστούμε. Μπορείτε να κλείσετε τη σελίδα.</p>'
	);
}
add_action( 'admin_post_nopriv_g5d_decide', 'g5d_decide_action' );
add_action( 'admin_post_g5d_decide', 'g5d_decide_action' );

/** Η τελευταία μέρα υποβολής: $n εργάσιμες ημέρες πριν από την ημερομηνία έναρξης $from. */
function g5d_deadline_for( $from, $n, array $hol ) {
	$d = new DateTimeImmutable( $from . ' 00:00:00', new DateTimeZone( 'UTC' ) );
	for ( $i = 0; $i < (int) $n; ) {
		$d = $d->modify( '-1 day' );
		if ( (int) $d->format( 'N' ) <= 5 && ! isset( $hol[ $d->format( 'Y-m-d' ) ] ) ) {
			++$i;
		}
	}
	return $d->format( 'Y-m-d' );
}

/** Υποβολή της φόρμας από εκπαιδευτικό με πρόσβαση. */
function g5d_submit_action() {
	if ( ! g5d_has_access() ) {
		wp_die( 'Χρειάζεται σύνδεση για να υποβάλετε πρόταση.' );
	}
	check_admin_referer( 'g5d_submit' );
	if ( '' === g5d_notify_email() ) {
		wp_safe_redirect( g5d_back_url( array( 'g5d' => 'error' ) ) );
		exit;
	}
	$lead = (int) g5d_settings()['lead_days'];
	if ( $lead > 0 ) {
		$from = g5d_clean_date( isset( $_POST['date_from'] ) ? wp_unslash( $_POST['date_from'] ) : '' ); // phpcs:ignore
		if ( $from ) {
			$deadline = g5d_deadline_for( $from, $lead, g5d_holidays_map() );
			if ( current_time( 'Y-m-d' ) > $deadline ) {
				wp_safe_redirect( g5d_back_url( array( 'g5d' => 'late' ) ) );
				exit;
			}
		}
	}
	list( $errors, $c ) = g5d_validate_request( wp_unslash( $_POST ) ); // phpcs:ignore
	if ( $errors ) {
		wp_safe_redirect( g5d_back_url( array( 'g5d' => 'invalid', 'g5d_err' => implode( ',', $errors ) ) ) );
		exit;
	}
	$conflicts = g5d_exam_conflicts( $c['grades'], $c['date_from'], $c['date_to'] );
	if ( $conflicts ) {
		// Το κείμενο της σύγκρουσης μένει στον server· στη διεύθυνση πηγαίνει μόνο ένα τυχαίο κλειδί.
		$ck = wp_generate_password( 12, false );
		set_transient( 'g5d_conf_' . $ck, g5d_conflict_text( $conflicts ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( g5d_back_url( array( 'g5d' => 'conflict', 'g5d_conf' => $ck ) ) );
		exit;
	}
	$created = g5d_create_request( $c );
	if ( ! $created ) {
		wp_safe_redirect( g5d_back_url( array( 'g5d' => 'error' ) ) );
		exit;
	}
	list( $id, $token ) = $created;
	g5d_send_request_mail( $id, $c, $token );
	wp_safe_redirect( g5d_back_url( array( 'g5d' => 'sent' ) ) );
	exit;
}
add_action( 'admin_post_g5d_submit', 'g5d_submit_action' );
add_action( 'admin_post_nopriv_g5d_submit', 'g5d_submit_action' );

/** Shortcode [draseis_form]: φόρμα υποβολής πρότασης δράσης. */
function g5d_form_shortcode( $atts ) {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	wp_enqueue_style( 'g5d' );
	if ( ! g5d_has_access() ) {
		return g5d_gate_shortcode( array() );
	}
	$flag = isset( $_GET['g5d'] ) ? sanitize_key( wp_unslash( $_GET['g5d'] ) ) : ''; // phpcs:ignore
	$errs = isset( $_GET['g5d_err'] ) ? array_map( 'sanitize_key', explode( ',', wp_unslash( $_GET['g5d_err'] ) ) ) : array(); // phpcs:ignore
	$labels = g5d_field_labels();

	ob_start();
	echo '<div class="g5d g5d-form">';
	if ( 'sent' === $flag ) {
		echo '<p class="g5d-notice g5d-notice-ok">Η πρόταση στάλθηκε στη διεύθυνση του σχολείου.</p>';
	} elseif ( 'error' === $flag ) {
		echo '<p class="g5d-notice">Η πρόταση δεν αποθηκεύτηκε. Δοκιμάστε ξανά.</p>';
	} elseif ( 'conflict' === $flag ) {
		$ck   = isset( $_GET['g5d_conf'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['g5d_conf'] ) ) : ''; // phpcs:ignore
		$conf = '' !== $ck ? (string) get_transient( 'g5d_conf_' . $ck ) : '';
		echo '<p class="g5d-notice">Δεν μπορεί να υποβληθεί η πρόταση, γιατί υπάρχει διαγώνισμα' . ( '' !== $conf ? ': ' . esc_html( $conf ) : '' ) . '. Επιλέξτε άλλη ημερομηνία ή τμήματα.</p>';
	} elseif ( 'invalid' === $flag ) {
		$names = array();
		foreach ( $errs as $e ) {
			$names[] = isset( $labels[ $e ] ) ? $labels[ $e ] : ( 'grades' === $e ? 'Τμήματα' : $e );
		}
		echo '<p class="g5d-notice">Συμπληρώστε σωστά: ' . esc_html( implode( ', ', $names ) ) . '.</p>';
	} elseif ( 'late' === $flag ) {
		echo '<p class="g5d-notice">Η προθεσμία υποβολής έχει περάσει.</p>';
	}

	if ( '' === g5d_notify_email() ) {
		echo '<p class="g5d-notice">Η φόρμα δεν είναι διαθέσιμη αυτή τη στιγμή. Επικοινωνήστε με το σχολείο.</p></div>';
		echo g5d_credit();
		return ob_get_clean();
	}
	echo '<div class="g5d-notes">';
	$lead = (int) g5d_settings()['lead_days'];
	if ( $lead > 0 ) {
		echo '<p class="g5d-deadline">Προθεσμία υποβολής τουλάχιστον <strong>' . $lead . ' ' . ( 1 === $lead ? 'εργάσιμη ημέρα' : 'εργάσιμες ημέρες' ) . '</strong> πριν τη δράση.</p>';
	}
	$prog = g5d_pages_with_shortcode( array( 'draseis_planner' ) );
	if ( $prog ) {
		echo '<p class="g5d-deadline g5d-overlap">Πριν συμπληρώσετε τη φόρμα, δείτε <a href="' . esc_url( $prog[0] ) . '">το πρόγραμμα δράσεων</a> για τυχόν επικαλύψεις.</p>';
	}
	echo '</div>';
	$grades = array_merge( g5d_grade_options() );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="g5d-proposal">';
	echo '<input type="hidden" name="action" value="g5d_submit">';
	wp_nonce_field( 'g5d_submit' );
	echo '<div class="g5d-sec">';
	echo '<h3 class="g5d-sec-title">Η δράση</h3>';
	echo '<label>Τύπος δράσης <span class="g5d-req">*</span><select name="action_type" required><option value="">— Επιλέξτε —</option>';
	foreach ( g5d_action_types() as $type ) {
		echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $type ) . '</option>';
	}
	echo '</select></label>';
	echo '<label>Τίτλος δράσης <span class="g5d-req">*</span><input type="text" name="title" maxlength="' . (int) g5d_field_max()['title'] . '" required></label>';
	echo '</div><div class="g5d-sec">';
	echo '<h3 class="g5d-sec-title">Πότε και πού</h3>';
	echo '<div class="g5d-two"><label>Ημερομηνία <span class="g5d-req">*</span><input type="date" name="date_from" required></label>';
	echo '<label>Έως (για πολυήμερη)<input type="date" name="date_to"></label></div>';
	echo '<div class="g5d-two"><label>Ώρα από (κενό = ολοήμερη)<input type="time" name="time_from"></label>';
	echo '<label>Ώρα έως (προαιρετικό)<input type="time" name="time_to"></label></div>';
	echo '<p class="g5d-hint" id="g5d-hours-hint" hidden>Οι ώρες συμπληρώνονται μόνο για δράσεις μίας ημέρας.</p>';
	echo '<script>(function(){var f=document.querySelector(".g5d-proposal");if(!f)return;var d=f.elements.date_from,e=f.elements.date_to,a=f.elements.time_from,b=f.elements.time_to,h=document.getElementById("g5d-hours-hint");if(!d||!e||!a||!b)return;function s(){var m=!!(d.value&&e.value&&e.value>d.value);a.disabled=b.disabled=m;if(m){a.value=b.value="";}h.hidden=!m;}d.addEventListener("change",s);e.addEventListener("change",s);s();})();</script>';
	echo '<label>Χώρος <span class="g5d-req">*</span><input type="text" name="place" maxlength="' . (int) g5d_field_max()['place'] . '" required></label>';
	echo '</div><div class="g5d-sec">';
	echo '<h3 class="g5d-sec-title">Τμήματα / Ομάδα</h3>';
	echo '<fieldset class="g5d-grades"><legend>Τμήματα <span class="g5d-req">*</span> <small>(για ομάδα επιλέξτε όλα τα τμήματα των μαθητών)</small></legend>';
	echo '<label><input type="checkbox" name="grades[]" value="all"> Όλο το σχολείο</label>';
	foreach ( $grades as $g ) {
		echo '<label><input type="checkbox" name="grades[]" value="' . esc_attr( $g ) . '"> ' . esc_html( $g ) . '</label>';
	}
	echo '</fieldset>';
	echo '<label>Όνομα ομάδας (αν υπάρχει)<input type="text" name="group_name" maxlength="' . (int) g5d_field_max()['group_name'] . '"></label>';
	echo '</div><div class="g5d-sec">';
	echo '<h3 class="g5d-sec-title">Συμμετέχοντες</h3>';
	echo '<label>Μάθημα / θεματικός άξονας <span class="g5d-req">*</span><input type="text" name="subject" maxlength="' . (int) g5d_field_max()['subject'] . '" required></label>';
	echo '<label>Αριθμός μαθητών <span class="g5d-req">*</span><input type="number" name="students" min="1" required></label>';
	echo '<label>Υπεύθυνοι εκπ/κοί <span class="g5d-req">*</span><input type="text" name="teachers" maxlength="' . (int) g5d_field_max()['teachers'] . '" required></label>';
	echo '<label>Συνοδοί εκπ/κοί <span class="g5d-req">*</span><input type="text" name="companions" maxlength="' . (int) g5d_field_max()['companions'] . '" placeholder="Αν δεν υπάρχουν, γράψτε «Κανένας»" required></label>';
	echo '<label>Αναπληρωτές συνοδοί εκπ/κοί<input type="text" name="companions_alt" maxlength="' . (int) g5d_field_max()['companions_alt'] . '"></label>';
	echo '<label>Μέσα μεταφοράς <span class="g5d-req">*</span><input type="text" name="transport" maxlength="' . (int) g5d_field_max()['transport'] . '" placeholder="π.χ. λεωφορείο, με τα πόδια" required></label>';
	echo '</div><div class="g5d-sec">';
	echo '<h3 class="g5d-sec-title">Περιγραφή</h3>';
	echo '<label>Σύντομη περιγραφή <span class="g5d-req">*</span><textarea name="description" rows="4" minlength="20" maxlength="400" required></textarea>';
	echo '<span class="g5d-hint" id="g5d-desc-count" aria-live="polite">0 / 400 χαρακτήρες (ελάχιστο 20)</span></label>';
	echo '<script>(function(){var t=document.querySelector(".g5d-proposal textarea[name=description]"),c=document.getElementById("g5d-desc-count");if(!t||!c)return;function u(){var n=t.value.length;c.textContent=n+" / 400 χαρακτήρες (ελάχιστο 20)";}t.addEventListener("input",u);u();})();</script>';
	echo '<label>Υλικά<textarea name="materials" rows="1" class="g5d-one" maxlength="' . (int) g5d_field_max()['materials'] . '"></textarea></label>';
	echo '</div>';
	echo '<div class="g5d-actions">';
	echo '<button type="submit" class="g5d-btn g5d-btn-primary">Υποβολή</button>';
	echo '<button type="submit" form="g5d-logout" class="g5d-btn g5d-btn-cancel" onclick="return confirm(&quot;Να ακυρωθεί η υποβολή; Τα στοιχεία που έχετε συμπληρώσει θα χαθούν.&quot;);">Άκυρο</button>';
	echo '</div>';
		echo '</form>';
	echo '<form id="g5d-logout" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>';
	echo '<input type="hidden" name="action" value="g5d_logout">';
	wp_nonce_field( 'g5d_logout' );
	echo '</form>';
	echo g5d_credit();
	echo '</div>';
	return ob_get_clean();
}
add_shortcode( 'draseis_form', 'g5d_form_shortcode' );

