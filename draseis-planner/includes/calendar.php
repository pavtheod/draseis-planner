<?php
/**
 * Draseis Planner: ρυθμίσεις, Google Calendar και επεξεργασία δράσεων.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Ρυθμίσεις
 * ---------------------------------------------------------------------- */

function g5d_defaults() {
	return array(
		'api_key'       => '',
		'notify_email'  => '',
		'classes'       => '',
		'delete_data'   => 0,
		'calendar_id'   => '',
		'cache_minutes' => 15,
		'form_url'      => '',
		'service_account' => '',
		'code'          => '',
		'code_ver'      => 0,
		'access'        => 'code',
		'lead_days'     => 0,
		'holidays'      => '',
		'holidays_local' => '',
		'holidays_auto' => 1,
		'holidays_year' => 0,
		'sso_server'    => 'prod',
		'sso_service'   => '',
		'sso_units'     => '',
		'sso_roles'     => 'teacher, ateacher, pteacher',
	);
}

function g5d_settings() {
	$saved = get_option( G5D_OPT, array() );
	$saved = is_array( $saved ) ? $saved : array();
	$s     = wp_parse_args( $saved, g5d_defaults() );
	// Αργίες: μετάβαση από το παλιό πεδίο (μία φορά) και αυτόματη ενημέρωση με τη νέα σχολική χρονιά.
	$ch = g5d_holidays_maintain( $saved, $s );
	if ( ! isset( $saved['classes'] ) ) {
		// Υπάρχουσα εγκατάσταση πριν το πεδίο «Τμήματα»: κρατάμε τα τμήματα που ήταν γραμμένα στον κώδικα.
		// Νέα εγκατάσταση (καμία αποθηκευμένη ρύθμιση): κενό, το συμπληρώνει ο διαχειριστής.
		$ch['classes'] = $saved ? "Α1\nΑ2\nΑ3\nΑ4\nΑ5\nΒ1\nΒ2\nΒ3\nΒ4\nΓ1\nΓ2\nΓ3\nΓ4\nΓ5" : '';
	}
	if ( $ch ) {
		g5d_save_settings_raw( array_merge( $saved, $ch ) );
		$s = array_merge( $s, $ch );
	}
	return $s;
}

/* -------------------------------------------------------------------------
 * Ανάλυση δεδομένων (καθαρές συναρτήσεις, χωρίς πρόσβαση στο WordPress)
 * ---------------------------------------------------------------------- */

/**
 * Διαβάζει τα τμήματα από την αρχή του τίτλου, π.χ. "[Β1, Β2] Επίσκεψη".
 * Επιστρέφει [τμήματα, τίτλος χωρίς την αγκύλη].
 * Αν η αγκύλη δεν περιέχει μόνο τάξεις/τμήματα, ο τίτλος μένει ως έχει.
 */
function g5d_parse_title( $summary ) {
	$summary = trim( (string) $summary );
	if ( ! preg_match( '/^\[([^\]]*)\]\s*(.*)$/us', $summary, $m ) ) {
		return array( array(), $summary );
	}
	// Λατινικά γράμματα που μοιάζουν με ελληνικά, και πεζά, γίνονται κεφαλαία ελληνικά.
	$map    = array(
		'A' => 'Α', 'a' => 'Α', 'α' => 'Α', 'ά' => 'Α',
		'B' => 'Β', 'b' => 'Β', 'β' => 'Β',
		'G' => 'Γ', 'g' => 'Γ', 'γ' => 'Γ',
	);
	$tokens = preg_split( '/[\s,;\/]+/u', $m[1], -1, PREG_SPLIT_NO_EMPTY );
	if ( empty( $tokens ) ) {
		return array( array(), $summary );
	}
	$grades = array();
	foreach ( $tokens as $tok ) {
		$first = mb_substr( $tok, 0, 1, 'UTF-8' );
		$rest  = mb_substr( $tok, 1, null, 'UTF-8' );
		$first = isset( $map[ $first ] ) ? $map[ $first ] : $first;
		$rest  = preg_replace( "/[΄'’`ʹ]/u", '', $rest );
		if ( ! in_array( $first, array( 'Α', 'Β', 'Γ' ), true ) || ( '' !== $rest && ! ctype_digit( $rest ) ) ) {
			return array( array(), $summary );
		}
		$grades[] = $first . $rest;
	}
	return array( array_values( array_unique( $grades ) ), trim( $m[2] ) );
}

/**
 * Μετατρέπει ένα event της Google σε απλό πίνακα. Επιστρέφει null για ακυρωμένα ή άκυρα.
 */
function g5d_normalize_event( array $it, DateTimeZone $tz ) {
	if ( isset( $it['status'] ) && 'cancelled' === $it['status'] ) {
		return null;
	}
	$all_day = isset( $it['start']['date'] );
	try {
		if ( $all_day ) {
			$start = new DateTimeImmutable( $it['start']['date'] . ' 00:00:00', $tz );
			$end   = new DateTimeImmutable( ( isset( $it['end']['date'] ) ? $it['end']['date'] : $it['start']['date'] ) . ' 00:00:00', $tz );
			if ( $end <= $start ) {
				$end = $start->modify( '+1 day' );
			}
		} else {
			if ( empty( $it['start']['dateTime'] ) ) {
				return null;
			}
			$start = ( new DateTimeImmutable( $it['start']['dateTime'] ) )->setTimezone( $tz );
			$end   = ! empty( $it['end']['dateTime'] ) ? ( new DateTimeImmutable( $it['end']['dateTime'] ) )->setTimezone( $tz ) : $start;
		}
	} catch ( Exception $e ) {
		return null;
	}
	list( $grades, $title ) = g5d_parse_title( isset( $it['summary'] ) ? $it['summary'] : '' );
	if ( '' === $title ) {
		$title = '(χωρίς τίτλο)';
	}
	return array(
		'start'    => $start->getTimestamp(),
		'end'      => $end->getTimestamp(),
		'all_day'  => $all_day,
		'title'    => $title,
		'grades'   => $grades,
		'location' => trim( isset( $it['location'] ) ? (string) $it['location'] : '' ),
		'id'       => isset( $it['id'] ) ? (string) $it['id'] : '',
	);
}

/**
 * Το σχολικό έτος που περιέχει την ημερομηνία: 1 Σεπτεμβρίου – 31 Αυγούστου.
 * Από 1 Αυγούστου δείχνει ήδη το επόμενο έτος, για να φαίνονται οι νέες δράσεις.
 */
function g5d_school_year_range( DateTimeImmutable $now ) {
	$y     = (int) $now->format( 'Y' );
	$start = ( (int) $now->format( 'n' ) >= 8 ) ? $y : $y - 1;
	$tz    = $now->getTimezone();
	return array(
		new DateTimeImmutable( $start . '-09-01 00:00:00', $tz ),
		new DateTimeImmutable( ( $start + 1 ) . '-08-31 23:59:59', $tz ),
	);
}

/* -------------------------------------------------------------------------
 * Λήψη από το Google Calendar API και cache
 * ---------------------------------------------------------------------- */

function g5d_fetch_events() {
	$s = g5d_settings();
	if ( '' === trim( $s['api_key'] ) || '' === trim( $s['calendar_id'] ) ) {
		return new WP_Error( 'g5d_config', 'Λείπει το API key ή το ID του ημερολογίου (Ρυθμίσεις → Πρόγραμμα Δράσεων).' );
	}
	$tz              = wp_timezone();
	$now             = new DateTimeImmutable( 'now', $tz );
	list( $from, $to ) = g5d_school_year_range( $now );

	$events     = array();
	$page_token = '';
	for ( $page = 0; $page < 10; $page++ ) {
		$args = array(
			'key'          => trim( $s['api_key'] ),
			'singleEvents' => 'true',
			'orderBy'      => 'startTime',
			'timeMin'      => $from->format( DATE_RFC3339 ),
			'timeMax'      => $to->format( DATE_RFC3339 ),
			'maxResults'   => 250,
		);
		if ( false !== strpos( $tz->getName(), '/' ) ) {
			$args['timeZone'] = $tz->getName();
		}
		if ( '' !== $page_token ) {
			$args['pageToken'] = $page_token;
		}
		$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( trim( $s['calendar_id'] ) ) . '/events?' . http_build_query( $args );
		$res = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = ( is_array( $body ) && isset( $body['error']['message'] ) ) ? $body['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'g5d_http', 'Η Google απάντησε: ' . $msg );
		}
		foreach ( ( isset( $body['items'] ) ? $body['items'] : array() ) as $it ) {
			$e = g5d_normalize_event( $it, $tz );
			if ( $e ) {
				$events[] = $e;
			}
		}
		$page_token = isset( $body['nextPageToken'] ) ? $body['nextPageToken'] : '';
		if ( '' === $page_token ) {
			break;
		}
	}
	usort(
		$events,
		function ( $a, $b ) {
			return $a['start'] <=> $b['start'];
		}
	);
	return $events;
}

/**
 * Επιστρέφει ['events' => ..., 'fetched' => timestamp] ή WP_Error.
 * Αν η Google δεν απαντήσει, δείχνει την τελευταία επιτυχημένη λήψη.
 */
function g5d_get_events() {
	$tz              = wp_timezone();
	$now             = new DateTimeImmutable( 'now', $tz );
	list( $from, $to ) = g5d_school_year_range( $now );
	return array(
		'events'  => g5d_approved_events( $tz, $from, $to ),
		'fetched' => time(),
	);
}

/** Οι μη κενές τιμές των πρόσθετων πεδίων, για εμφάνιση αν επιλεγούν. */
function g5d_extra_values( array $d ) {
	$out = array();
	foreach ( array_keys( g5d_extra_fields() ) as $k ) {
		$v = g5d_display_value( $k, isset( $d[ $k ] ) ? $d[ $k ] : '' );
		if ( '' !== $v ) {
			$out[ $k ] = $v;
		}
	}
	return $out;
}

/**
 * Οι εγκεκριμένες δράσεις της βάσης, σε πίνακες της ίδιας μορφής με τα events της Google,
 * ώστε η εμφάνιση να μη χρειάζεται αλλαγή. Όσες τέμνουν το εύρος [από, έως] του σχολικού έτους.
 */
function g5d_approved_events( DateTimeZone $tz, DateTimeImmutable $from, DateTimeImmutable $to ) {
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
	$events = array();
	foreach ( $ids as $id ) {
		$d = g5d_request_data( $id );
		if ( empty( $d['date_from'] ) || ! g5d_clean_date( $d['date_from'] ) ) {
			continue;
		}
		$date_to = ( ! empty( $d['date_to'] ) && g5d_clean_date( $d['date_to'] ) ) ? $d['date_to'] : $d['date_from'];
		// Πολυήμερες δράσεις εμφανίζονται χωρίς ώρες, όπως και οι παλιότερες με ώρα.
		$all_day = '' === $d['time_from'] || $date_to !== $d['date_from'];
		try {
			if ( $all_day ) {
				$start = new DateTimeImmutable( $d['date_from'] . ' 00:00:00', $tz );
				$end   = ( new DateTimeImmutable( $date_to . ' 00:00:00', $tz ) )->modify( '+1 day' ); // στα ολοήμερα η λήξη είναι η επόμενη μέρα
			} else {
				$start = new DateTimeImmutable( $d['date_from'] . ' ' . $d['time_from'] . ':00', $tz );
				$end_t = '' !== $d['time_to'] ? $d['time_to'] : $d['time_from'];
				$end   = new DateTimeImmutable( $date_to . ' ' . $end_t . ':00', $tz );
			}
		} catch ( Exception $e ) {
			continue;
		}
		if ( $end < $from || $start > $to ) {
			continue;
		}
		$grades = is_array( $d['grades'] ) ? $d['grades'] : array();
		// Το «Όλο το σχολείο» εμφανίζεται όπως πριν: χωρίς τμήματα στη λίστα.
		if ( in_array( 'all', $grades, true ) ) {
			$grades = array();
		}
		$events[] = array(
			'id'       => (int) $id,
			'start'    => $start->getTimestamp(),
			'end'      => $end->getTimestamp(),
			'all_day'  => $all_day,
			'title'    => '' !== $d['title'] ? $d['title'] : '(χωρίς τίτλο)',
			'grades'   => array_values( $grades ),
			'group'    => (string) $d['group_name'],
			'location' => (string) $d['place'],
			'extra'    => g5d_extra_values( $d ),
		);
	}
	usort(
		$events,
		function ( $a, $b ) {
			return $a['start'] <=> $b['start'];
		}
	);
	return $events;
}

/** Πεδία της φόρμας που μπορεί να εμφανίζονται στη σελίδα του προγράμματος (καρτέλα «Εμφάνιση»). */
function g5d_extra_fields() {
	return array(
		'action_type' => 'Τύπος δράσης',
		'subject'     => 'Μάθημα / άξονας',
		'transport'   => 'Μέσα μεταφοράς',
		'students'    => 'Αριθμός μαθητών',
		'teachers'    => 'Υπεύθυνοι εκπ/κοί',
		'companions'  => 'Συνοδοί εκπ/κοί',
		'companions_alt' => 'Αναπληρωτές συνοδοί εκπ/κοί',
		'description' => 'Περιγραφή',
		'materials'   => 'Υλικά',
	);
}

/** Τα πεδία που έχουν επιλεγεί για εμφάνιση, με τη σειρά της φόρμας. */
function g5d_show_keys() {
	$opt = get_option( 'g5d_show', array() );
	$opt = is_array( $opt ) ? $opt : array();
	return array_values( array_intersect( array_keys( g5d_extra_fields() ), $opt ) );
}

function g5d_sanitize_show( $input ) {
	$input = is_array( $input ) ? $input : array();
	$input = array_map( 'sanitize_key', $input );
	return array_values( array_intersect( array_keys( g5d_extra_fields() ), $input ) );
}

/* -------------------------------------------------------------------------
 * Εμφάνιση
 * ---------------------------------------------------------------------- */

function g5d_month_name( $n ) {
	$names = array( 1 => 'Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος', 'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος' );
	return $names[ (int) $n ];
}

function g5d_month_short( $n ) {
	$names = array( 1 => 'Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μαΐ', 'Ιουν', 'Ιουλ', 'Αυγ', 'Σεπ', 'Οκτ', 'Νοε', 'Δεκ' );
	return $names[ (int) $n ];
}

function g5d_weekday( $n ) {
	$names = array( 'Κυρ', 'Δευ', 'Τρι', 'Τετ', 'Πεμ', 'Παρ', 'Σαβ' );
	return $names[ (int) $n ];
}

function g5d_count_label( $n ) {
	return 1 === $n ? '1 δράση' : $n . ' δράσεις';
}

/** Το κείμενο της στήλης «Ώρα». */
function g5d_time_label( array $e, DateTimeZone $tz ) {
	$start = ( new DateTimeImmutable( '@' . $e['start'] ) )->setTimezone( $tz );
	$end   = ( new DateTimeImmutable( '@' . $e['end'] ) )->setTimezone( $tz );
	if ( $e['all_day'] ) {
		$last = $end->modify( '-1 day' ); // στα ολοήμερα η λήξη της Google είναι η επόμενη μέρα
		if ( $last->format( 'Y-m-d' ) === $start->format( 'Y-m-d' ) ) {
			return 'Ολοήμερη';
		}
		return 'Έως ' . $last->format( 'j/n' );
	}
	if ( $end <= $start ) {
		return $start->format( 'H:i' );
	}
	if ( $end->format( 'Y-m-d' ) === $start->format( 'Y-m-d' ) ) {
		return $start->format( 'H:i' ) . ' – ' . $end->format( 'H:i' );
	}
	return $start->format( 'H:i' ) . ' – ' . $end->format( 'j/n H:i' );
}

function g5d_chip_label( $grade ) {
	// "Β" (όλη η τάξη) → "Β΄ τάξη", "Β2" → "Β2"
	return 1 === mb_strlen( $grade, 'UTF-8' ) ? $grade . '΄ τάξη' : $grade;
}
