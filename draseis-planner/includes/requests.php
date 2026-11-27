<?php
/**
 * Draseis Planner: τύπος αίτησης, πεδία και ετικέτες.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Αιτήσεις δράσεων: φόρμα, email με κουμπιά, σελίδα επιβεβαίωσης
 * ---------------------------------------------------------------------- */

define( 'G5D_CPT', 'g5d_request' );
define( 'G5D_TOKEN_DAYS', 14 );
/** Διεύθυνση που λαμβάνει τις υποβολές. Κενή = η φόρμα δεν δέχεται υποβολές. */
function g5d_notify_email() {
	return (string) g5d_settings()['notify_email'];
}

function g5d_register_cpt() {
	register_post_type(
		G5D_CPT,
		array(
			'labels'          => array(
				'name'          => 'Αιτήσεις Δράσεων',
				'singular_name' => 'Αίτηση δράσης',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-calendar-alt',
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		)
	);
}
add_action( 'init', 'g5d_register_cpt' );

/** Πλαίσιο «Στοιχεία αίτησης» στη σελίδα κάθε αίτησης, μόνο για ανάγνωση. */
function g5d_add_details_box() {
	add_meta_box( 'g5d_details', 'Στοιχεία αίτησης', 'g5d_details_box', G5D_CPT, 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'g5d_add_details_box' );

function g5d_details_box( $post ) {
	$d        = g5d_request_data( $post->ID );
	$statuses = array(
		'pending'   => 'Εκκρεμεί',
		'approved'  => 'Εγκρίθηκε',
		'rejected'  => 'Απορρίφθηκε',
		'cancelled' => 'Ακυρώθηκε',
	);
	$status   = isset( $statuses[ $d['status'] ] ) ? $statuses[ $d['status'] ] : (string) $d['status'];
	$extra    = array(
		'Κατάσταση'           => $status,
		'Ημερομηνία απόφασης' => $d['decided_at'] ? wp_date( 'd/m/Y H:i', (int) $d['decided_at'] ) : '',
		'Σημείωση απόφασης'   => (string) $d['decision_note'],
	);
	$rows = '';
	foreach ( $extra as $label => $val ) {
		if ( '' === $val ) {
			continue;
		}
		$rows .= '<tr><td style="padding:6px 14px 6px 0;color:#64748b;vertical-align:top">' . esc_html( $label ) . '</td>'
			. '<td style="padding:6px 0">' . nl2br( esc_html( $val ) ) . '</td></tr>';
	}
	echo '<div class="g5d-details">' . wp_kses_post( g5d_rows_html( $d ) );
	if ( '' !== $rows ) {
		echo '<table style="border-collapse:collapse;font-size:14px;margin-top:12px">' . $rows . '</table>';
	}
	echo '</div>';
}

/** Τύποι εκπαιδευτικών δράσεων εκτός σχολικής μονάδας, Άρθρο 3 της 109113/ΓΔ4/2026. */
function g5d_action_types() {
	return array(
		'1. Σχολικός περίπατος',
		'2. Διδακτική επίσκεψη',
		'3. Επίσκεψη στο πλαίσιο εγκεκριμένων προγραμμάτων σχολικών δραστηριοτήτων, εκπαιδευτικών ομάδων ή Κέντρων Καινοτομίας',
		'4. Επίσκεψη στο πλαίσιο των Αναλυτικών Προγραμμάτων Σπουδών',
		'5. Ημερήσια ή πολυήμερη εκπαιδευτική εκδρομή',
		'6. Συμμετοχή σε διαγωνισμούς, μαθητικά συνέδρια, ημερίδες και εκδηλώσεις στο εσωτερικό',
		'7. Επίσκεψη στη Βουλή των Ελλήνων',
		'8. Εκπαιδευτική επίσκεψη στο εξωτερικό στο πλαίσιο ευρωπαϊκών και διεθνών προγραμμάτων',
		'9. Δράση στο πλαίσιο του προγράμματος Erasmus+',
		'10. Σχολική αθλητική δραστηριότητα – αγώνες',
	);
}

/** Τμήματα από κείμενο (ένα ανά γραμμή, χωρίς διπλότυπα, έως 20 χαρακτήρες). */
function g5d_parse_classes( $text ) {
	$out = array();
	foreach ( preg_split( '/\R/u', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line && mb_strlen( $line, 'UTF-8' ) <= 20 && ! in_array( $line, $out, true ) ) {
			$out[] = $line;
		}
	}
	return $out;
}

/**
 * Σύνδεση με το Exams Planner. Η απάντηση φυλάσσεται σε κοινή ρύθμιση («draseis_exams_link»),
 * ώστε να τη διαβάζουν και να μπορούν να τη ρωτήσουν και τα δύο plugins:
 * 'yes' = συνδεδεμένα, 'no' = ανεξάρτητα, '' = δεν έχει απαντηθεί ακόμα.
 */
define( 'G5D_LINK_OPT', 'draseis_exams_link' );

/**
 * Σταθερή δημόσια συνάρτηση για το Exams Planner (εντοπισμός με function_exists): τα τμήματα που έχουν
 * οριστεί στις ρυθμίσεις του Draseis Planner, ένα ανά στοιχείο, όπως τα έγραψε ο διαχειριστής
 * (χωρίς κενές γραμμές και διπλότυπα, έως 20 χαρακτήρες). Το όνομα δεν θα αλλάξει.
 */
function draseis_planner_classes() {
	return g5d_parse_classes( g5d_settings()['classes'] );
}

/** Αν είναι ενεργό το Exams Planner. */
function g5d_exams_active() {
	return function_exists( 'dgn_classes' );
}

function g5d_exams_link() {
	$v = get_option( G5D_LINK_OPT, '' );
	return in_array( $v, array( 'yes', 'no' ), true ) ? $v : '';
}

/** Συνδεδεμένα: το Exams Planner είναι ενεργό και ο διαχειριστής απάντησε «Ναι». */
function g5d_exams_linked() {
	return g5d_exams_active() && 'yes' === g5d_exams_link();
}

/** Τα τμήματα του Exams Planner (κενό αν δεν είναι ενεργό). */
function g5d_exams_classes() {
	return g5d_exams_active() ? array_values( array_map( 'strval', (array) dgn_classes() ) ) : array();
}

/** Τα τμήματα της φόρμας: από το Exams Planner αν είναι συνδεδεμένα, αλλιώς από τις ρυθμίσεις. */
function g5d_grade_options() {
	if ( g5d_exams_linked() ) {
		return g5d_exams_classes();
	}
	return g5d_parse_classes( g5d_settings()['classes'] );
}

/** Ετικέτες πεδίων, με τη σειρά που εμφανίζονται στο email και στη σελίδα επιβεβαίωσης. */
function g5d_field_labels() {
	return array(
		'action_type' => 'Τύπος δράσης',
		'title'       => 'Τίτλος δράσης',
		'date_from'   => 'Ημερομηνία',
		'date_to'     => 'Έως',
		'time_from'   => 'Ώρα από',
		'time_to'     => 'Ώρα έως',
		'place'       => 'Χώρος',
		'transport'   => 'Μέσα μεταφοράς',
		'grades'      => 'Τμήματα',
		'group_name'  => 'Ομάδα',
		'subject'     => 'Μάθημα / άξονας',
		'students'    => 'Αριθμός μαθητών',
		'teachers'    => 'Υπεύθυνοι εκπ/κοί',
		'companions'  => 'Συνοδοί εκπ/κοί',
		'companions_alt' => 'Αναπληρωτές συνοδοί εκπ/κοί',
		'description' => 'Περιγραφή',
		'materials'   => 'Υλικά',
	);
}

/** Επιστρέφει 'Y-m-d' αν η ημερομηνία είναι έγκυρη, αλλιώς false. */
function g5d_clean_date( $s ) {
	$s = trim( (string) $s );
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return false;
	}
	return $s;
}
