<?php
/**
 * Αργίες: οι πανελλαδικές σχολικές αργίες συμπληρώνονται αυτόματα κάθε σχολική χρονιά
 * και οι τοπικές αργίες προστίθενται από τον διαχειριστή. Ίδια λογική με το Exams Planner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Το έτος έναρξης της τρέχουσας σχολικής χρονιάς (από τον Ιούλιο και μετά ισχύει η επόμενη). */
function g5d_school_year_start() {
	$now = new DateTimeImmutable( 'now', wp_timezone() );
	$y   = (int) $now->format( 'Y' );
	return (int) $now->format( 'n' ) >= 7 ? $y : $y - 1;
}

/** Έγκυρη ημερομηνία «ΕΕΕΕ-ΜΜ-ΗΗ» ή null. */
function g5d_parse_date( $str ) {
	if ( ! is_string( $str ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $str, $m ) ) {
		return null;
	}
	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return null;
	}
	return new DateTimeImmutable( $str . ' 00:00:00', new DateTimeZone( 'UTC' ) );
}

/**
 * Κείμενο αργιών (μία γραμμή η καθεμία, ή διάστημα «από έως») σε [ΕΕΕΕ-ΜΜ-ΗΗ => όνομα].
 * Η μορφή «ΜΜ-ΗΗ» (χωρίς έτος) ισχύει κάθε σχολική χρονιά. Επιστρέφει array( χάρτης, γραμμές που δεν αναγνωρίστηκαν ).
 */
function g5d_parse_holidays( $text, $sy = null ) {
	$sy  = null === $sy ? g5d_school_year_start() : (int) $sy;
	$map = array();
	$bad = array();
	foreach ( preg_split( '/\R/u', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( ! preg_match( '/^((?:\d{4}-)?\d{2}-\d{2})(?:\s*(?:έως|-|–)\s*((?:\d{4}-)?\d{2}-\d{2}))?\s*(?:\|\s*(.*))?$/u', $line, $m ) ) {
			$bad[] = $line;
			continue;
		}
		foreach ( array( 1, 2 ) as $i ) { // «ΜΜ-ΗΗ» → πλήρης ημερομηνία μέσα στη σχολική χρονιά
			if ( ! empty( $m[ $i ] ) && 5 === strlen( $m[ $i ] ) ) {
				$m[ $i ] = ( (int) substr( $m[ $i ], 0, 2 ) >= 9 ? $sy : $sy + 1 ) . '-' . $m[ $i ];
			}
		}
		$from = g5d_parse_date( $m[1] );
		$to   = ! empty( $m[2] ) ? g5d_parse_date( $m[2] ) : $from;
		$name = isset( $m[3] ) && '' !== trim( $m[3] ) ? trim( $m[3] ) : 'Αργία';
		if ( ! $from || ! $to || $to < $from || $from->diff( $to )->days > 120 ) {
			$bad[] = $line;
			continue;
		}
		for ( $d = $from; $d <= $to; $d = $d->modify( '+1 day' ) ) {
			$map[ $d->format( 'Y-m-d' ) ] = mb_substr( $name, 0, 40 );
		}
	}
	return array( $map, $bad );
}

/** Οι αργίες που ισχύουν: οι τοπικές και οι πανελλαδικές, ως [ΕΕΕΕ-ΜΜ-ΗΗ => όνομα]. */
function g5d_holidays_map() {
	$s             = g5d_settings();
	list( $map )   = g5d_parse_holidays( $s['holidays'] );
	list( $local ) = g5d_parse_holidays( $s['holidays_local'] );
	return $local + $map;
}

/**
 * Ορθόδοξο Πάσχα ενός έτους (αλγόριθμος Meeus για το Ιουλιανό ημερολόγιο + 13 ημέρες, ισχύει 1900–2099).
 */
function g5d_orthodox_easter( $y ) {
	$a = $y % 4;
	$b = $y % 7;
	$c = $y % 19;
	$d = ( 19 * $c + 15 ) % 30;
	$e = ( 2 * $a + 4 * $b - $d + 34 ) % 7;
	$m = intdiv( $d + $e + 114, 31 );
	$n = ( ( $d + $e + 114 ) % 31 ) + 1;
	return ( new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $y, $m, $n ), new DateTimeZone( 'UTC' ) ) )->modify( '+13 days' );
}

/** Οι πανελλαδικές σχολικές αργίες της χρονιάς $sy–($sy+1), ως κείμενο (μία ανά γραμμή, κατά ημερομηνία). */
function g5d_national_holidays_text( $sy ) {
	$sy = (int) $sy;
	$n  = $sy + 1;
	$e  = g5d_orthodox_easter( $n );
	$f  = function ( DateTimeImmutable $d ) {
		return $d->format( 'Y-m-d' );
	};
	// Σχολική εορτή: η τελευταία εργάσιμη (Δευτέρα–Παρασκευή) πριν από την επέτειο.
	$eve = function ( $date ) {
		$d = ( new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) ) )->modify( '-1 day' );
		while ( (int) $d->format( 'N' ) > 5 ) {
			$d = $d->modify( '-1 day' );
		}
		return $d->format( 'Y-m-d' );
	};
	$e28 = $eve( "$sy-10-28" );
	$e25 = $eve( "$n-03-25" );
	$l   = array(
		array( $e28, "$e28 | Σχολική εορτή (28η Οκτωβρίου)" ),
		array( $e25, "$e25 | Σχολική εορτή (25η Μαρτίου)" ),
		array( "$sy-10-28", "$sy-10-28 | 28η Οκτωβρίου" ),
		array( "$sy-11-17", "$sy-11-17 | Πολυτεχνείο" ),
		array( "$sy-12-24", "$sy-12-24 έως $n-01-07 | Χριστούγεννα" ),
		array( "$n-01-30", "$n-01-30 | Τριών Ιεραρχών" ),
		array( $f( $e->modify( '-48 days' ) ), $f( $e->modify( '-48 days' ) ) . ' | Καθαρά Δευτέρα' ),
		array( "$n-03-25", "$n-03-25 | 25η Μαρτίου" ),
		array( $f( $e->modify( '-6 days' ) ), $f( $e->modify( '-6 days' ) ) . ' έως ' . $f( $e->modify( '+7 days' ) ) . ' | Πάσχα' ),
		array( "$n-05-01", "$n-05-01 | Πρωτομαγιά" ),
		array( $f( $e->modify( '+50 days' ) ), $f( $e->modify( '+50 days' ) ) . ' | Αγίου Πνεύματος' ),
	);
	usort(
		$l,
		function ( $x, $y ) {
			return strcmp( $x[0], $y[0] );
		}
	);
	return implode( "\n", array_column( $l, 1 ) );
}

/**
 * Πρώτη φορά (χωρίς αποθηκευμένο «holidays_year»): οι πανελλαδικές συμπληρώνονται για τη φετινή χρονιά
 * και όσες γραμμές του παλιού πεδίου δεν καλύπτονται από αυτές μεταφέρονται στις τοπικές. Καμία ημέρα δεν «ξεμπλοκάρει».
 * Αν το παλιό πεδίο δεν μοιάζει με ελληνικό σχολικό ημερολόγιο, μένει όπως είναι και η αυτόματη ενημέρωση κλείνει.
 * Επιστρέφει μόνο τα κλειδιά που αλλάζουν.
 */
function g5d_migrate_holidays( array $s ) {
	$sy  = g5d_school_year_start();
	$old = isset( $s['holidays'] ) ? (string) $s['holidays'] : '';
	$nat = g5d_national_holidays_text( $sy );
	list( $natmap ) = g5d_parse_holidays( $nat, $sy );
	list( $oldmap ) = g5d_parse_holidays( $old, $sy );
	$out            = array(
		'holidays_year'  => $sy,
		'holidays_local' => isset( $s['holidays_local'] ) ? (string) $s['holidays_local'] : '',
	);
	if ( '' !== trim( $old ) && count( array_intersect_key( $natmap, $oldmap ) ) * 2 < count( $natmap ) ) {
		$out['holidays_auto'] = 0; // άλλο ημερολόγιο: κρατάμε το κείμενο όπως είναι
		return $out;
	}
	$local = array();
	foreach ( preg_split( '/\R/u', $old ) as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		list( $m, $bad ) = g5d_parse_holidays( $line, $sy );
		if ( $bad || array_diff_key( $m, $natmap ) ) {
			$local[] = trim( $line ); // τοπική (ή μη αναγνωρίσιμη): μεταφέρεται, δεν χάνεται
		}
	}
	$out['holidays']       = $nat;
	$out['holidays_auto']  = 1;
	$out['holidays_local'] = trim( implode( "\n", array_filter( array( trim( $out['holidays_local'] ), implode( "\n", $local ) ) ) ) );
	return $out;
}

/**
 * Συντήρηση των αργιών κατά την ανάγνωση των ρυθμίσεων: μετάβαση από το παλιό πεδίο (μία φορά) και
 * αυτόματη ενημέρωση με τη νέα σχολική χρονιά. Επιστρέφει τα κλειδιά που αλλάζουν (κενό = τίποτα).
 */
function g5d_holidays_maintain( array $saved, array $s ) {
	$ch = array();
	if ( ! isset( $saved['holidays_year'] ) ) {
		$ch = g5d_migrate_holidays( $s );
	}
	$cur = array_merge( $s, $ch );
	$sy  = g5d_school_year_start();
	if ( ! empty( $cur['holidays_auto'] ) && (int) $cur['holidays_year'] !== $sy ) {
		// Νέα σχολική χρονιά: οι πανελλαδικές αργίες ξαναγράφονται για τη νέα χρονιά.
		$ch['holidays']           = g5d_national_holidays_text( $sy );
		$ch['holidays_year']      = $sy;
		$ch['holidays_refreshed'] = $sy; // για μήνυμα στις Ρυθμίσεις
	}
	return $ch;
}

/** Αποθήκευση των ρυθμίσεων όπως είναι, χωρίς να περάσουν από τον καθαρισμό της φόρμας. */
function g5d_save_settings_raw( array $s ) {
	$had = has_filter( 'sanitize_option_' . G5D_OPT, 'g5d_sanitize' );
	if ( false !== $had ) {
		remove_filter( 'sanitize_option_' . G5D_OPT, 'g5d_sanitize' );
	}
	update_option( G5D_OPT, $s );
	if ( false !== $had ) {
		add_filter( 'sanitize_option_' . G5D_OPT, 'g5d_sanitize' );
	}
}
