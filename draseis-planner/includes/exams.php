<?php
/**
 * Draseis Planner: έλεγχος σύγκρουσης δράσεων με διαγωνίσματα (Exams Planner).
 * Διαβάζει μόνο τον πίνακα των διαγωνισμάτων· δεν αλλάζει τίποτα εκεί.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Κανονικοποίηση ονόματος τμήματος: κεφαλαία ελληνικά, χωρίς κενά. Π.χ. «b1 » → «Β1». */
function g5d_norm_cls( $s ) {
	$map = array( 'A' => 'Α', 'B' => 'Β', 'G' => 'Γ' );
	$s   = mb_strtoupper( trim( (string) $s ), 'UTF-8' );
	$s   = strtr( $s, $map );
	return preg_replace( '/\s+/u', '', $s );
}

/**
 * Οι ομάδες προσανατολισμού του Exams Planner ως [ετικέτα «Γ·ΘΕΤ» => τμήματα της ομάδας].
 * Κενή λίστα τμημάτων = όλη η τάξη (και όταν το Exams Planner δεν δίνει ακόμα τμήματα στις ομάδες).
 */
function g5d_exam_groups() {
	if ( ! function_exists( 'dgn_groups' ) ) {
		return array();
	}
	$out = array();
	foreach ( (array) dgn_groups() as $label => $g ) {
		$cls = isset( $g['classes'] ) && is_array( $g['classes'] ) ? array_map( 'g5d_norm_cls', $g['classes'] ) : array();
		$out[ g5d_norm_cls( $label ) ] = array_values( array_filter( $cls ) );
	}
	return $out;
}

/**
 * Αν ένα διαγώνισμα αφορά τα τμήματα της δράσης.
 * - Τμήμα (π.χ. «Γ1»): ταιριάζει με το ίδιο τμήμα ή με ολόκληρη την τάξη («Γ»).
 * - Ομάδα προσανατολισμού (ετικέτα με «·», π.χ. «Γ·ΘΕΤ»): ταιριάζει με τα τμήματα της ομάδας·
 *   αν η ομάδα δεν έχει τμήματα (ή έχει όλα), με όλη την τάξη της.
 */
function g5d_cls_in_grades( $cls, array $grades, array $groups = array() ) {
	$cls = g5d_norm_cls( $cls );
	if ( false !== mb_strpos( $cls, '·', 0, 'UTF-8' ) ) {
		$grade   = mb_substr( $cls, 0, 1, 'UTF-8' );
		$members = isset( $groups[ $cls ] ) ? $groups[ $cls ] : array();
		foreach ( $grades as $g ) {
			$g = g5d_norm_cls( $g );
			if ( mb_substr( $g, 0, 1, 'UTF-8' ) !== $grade ) {
				continue;
			}
			// Ολόκληρη τάξη στη δράση, ή ομάδα χωρίς τμήματα: σύγκρουση. Αλλιώς μόνο αν το τμήμα είναι στην ομάδα.
			if ( 1 === mb_strlen( $g, 'UTF-8' ) || ! $members || in_array( $g, $members, true ) ) {
				return true;
			}
		}
		return false;
	}
	foreach ( $grades as $g ) {
		$g = g5d_norm_cls( $g );
		if ( $g === $cls ) {
			return true;
		}
		if ( 1 === mb_strlen( $g, 'UTF-8' ) && mb_substr( $cls, 0, 1, 'UTF-8' ) === $g ) {
			return true;
		}
	}
	return false;
}

/** Καθαρή συνάρτηση: ποια διαγωνίσματα αφορούν τη δράση. Το «Όλο το σχολείο» συγκρούεται με όλα. */
function g5d_match_exams( array $rows, array $grades, $groups = null ) {
	$groups = null === $groups ? g5d_exam_groups() : $groups;
	$all    = in_array( 'all', $grades, true );
	$out    = array();
	foreach ( $rows as $r ) {
		if ( $all || g5d_cls_in_grades( $r['cls'], $grades, $groups ) ) {
			$out[] = $r;
		}
	}
	return $out;
}

/** Κείμενο για τη σύγκρουση, π.χ. «21/10 Γ1 (Μαθηματικά); 22/10 Β2». */
function g5d_conflict_text( array $list ) {
	$parts = array();
	foreach ( $list as $r ) {
		$d = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $r['exam_date'], $m ) ? $m[3] . '/' . $m[2] : (string) $r['exam_date'];
		$p = $d . ' ' . trim( (string) $r['cls'] );
		if ( ! empty( $r['subject'] ) ) {
			$p .= ' (' . trim( (string) $r['subject'] ) . ')';
		}
		$parts[] = $p;
	}
	return implode( '; ', $parts );
}

/**
 * Διαγωνίσματα που συγκρούονται με τη δράση στο διάστημα [από, έως].
 * Κενή λίστα αν το Exams Planner δεν είναι ενεργό (δεν υπάρχει ο πίνακας).
 */
function g5d_exam_conflicts( array $grades, $from, $to ) {
	global $wpdb;
	// Με «Όχι» στη σύνδεση τα δύο plugins είναι ανεξάρτητα: δεν γίνεται έλεγχος.
	// Χωρίς απάντηση ακόμα, ο έλεγχος συνεχίζει όπως πριν, ώστε να μην αλλάξει τίποτα μέχρι να απαντήσει ο διαχειριστής.
	if ( empty( $grades ) || '' === (string) $from || 'no' === g5d_exams_link() ) {
		return array();
	}
	$t = $wpdb->prefix . 'dgn_exams';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return array();
	}
	$to   = '' !== (string) $to ? (string) $to : (string) $from;
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "SELECT cls, exam_date, subject FROM $t WHERE exam_date BETWEEN %s AND %s ORDER BY exam_date, cls", $from, $to ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
	return g5d_match_exams( is_array( $rows ) ? $rows : array(), $grades );
}

/**
 * Το Exams Planner ρωτάει αν η ημέρα είναι μπλοκαρισμένη για ένα τμήμα (φίλτρο dgn_exam_blocked).
 * Μπλοκάρει αν υπάρχει εγκεκριμένη δράση εκείνη τη μέρα για το τμήμα (ή για όλο το σχολείο).
 */
function g5d_activity_block_message( $msg, $cls, $day ) {
	// Με «Όχι» στη σύνδεση τα δύο plugins είναι ανεξάρτητα: οι δράσεις δεν μπλοκάρουν διαγωνίσματα.
	if ( '' !== (string) $msg || 'no' === g5d_exams_link() ) {
		return $msg;
	}
	$groups = g5d_exam_groups();
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
	foreach ( $ids as $id ) {
		$d    = g5d_request_data( $id );
		$from = (string) $d['date_from'];
		$to   = '' !== (string) $d['date_to'] ? (string) $d['date_to'] : $from;
		if ( '' === $from || $day < $from || $day > $to ) {
			continue;
		}
		$grades = is_array( $d['grades'] ) ? $d['grades'] : array();
		if ( in_array( 'all', $grades, true ) || g5d_cls_in_grades( $cls, $grades, $groups ) ) {
			return 'Την ημέρα αυτή υπάρχει εγκεκριμένη δράση για το ' . $cls . ('' !== (string) $d['title'] ? ': ' . $d['title'] : '') . '. Επιλέξτε άλλη ημέρα.';
		}
	}
	return '';
}
add_filter( 'dgn_exam_blocked', 'g5d_activity_block_message', 10, 3 );
