<?php
/**
 * Draseis Planner: εγγραφή των εγκεκριμένων δράσεων στο Google Calendar.
 * Χρησιμοποιεί λογαριασμό υπηρεσίας (service account) με δικαίωμα αλλαγών στο ημερολόγιο.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'G5D_GCAL_SCOPE', 'https://www.googleapis.com/auth/calendar.events' );
define( 'G5D_GCAL_TOKEN_URL', 'https://oauth2.googleapis.com/token' );
define( 'G5D_GCAL_API', 'https://www.googleapis.com/calendar/v3/calendars/' );
define( 'G5D_GCAL_TOKEN', 'g5d_gcal_token' );

/** Τα στοιχεία του λογαριασμού υπηρεσίας από τις ρυθμίσεις, ή null αν λείπουν. */
function g5d_gcal_credentials() {
	$s   = g5d_settings();
	$raw = isset( $s['service_account'] ) ? (string) $s['service_account'] : '';
	if ( '' === trim( $raw ) ) {
		return null;
	}
	$c = json_decode( $raw, true );
	if ( ! is_array( $c ) || empty( $c['client_email'] ) || empty( $c['private_key'] ) ) {
		return null;
	}
	return $c;
}

function g5d_b64url( $s ) {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
}

/**
 * Διακριτικό πρόσβασης: υπογράφουμε JWT με το ιδιωτικό κλειδί και το ανταλλάσσουμε στο Google.
 * Αποθηκεύεται στο cache λίγο πριν λήξει.
 */
function g5d_gcal_token() {
	$cached = get_transient( G5D_GCAL_TOKEN );
	if ( $cached ) {
		return $cached;
	}
	$c = g5d_gcal_credentials();
	if ( ! $c ) {
		return new WP_Error( 'g5d_gcal_config', 'Δεν έχει οριστεί λογαριασμός υπηρεσίας.' );
	}
	if ( ! function_exists( 'openssl_sign' ) ) {
		return new WP_Error( 'g5d_gcal_openssl', 'Ο διακομιστής δεν έχει την επέκταση OpenSSL.' );
	}
	$now    = time();
	$input  = g5d_b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) . '.' .
		g5d_b64url(
			wp_json_encode(
				array(
					'iss'   => $c['client_email'],
					'scope' => G5D_GCAL_SCOPE,
					'aud'   => G5D_GCAL_TOKEN_URL,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
	$sig = '';
	if ( ! openssl_sign( $input, $sig, $c['private_key'], OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'g5d_gcal_key', 'Το ιδιωτικό κλειδί του λογαριασμού δεν είναι έγκυρο.' );
	}
	$res  = wp_remote_post(
		G5D_GCAL_TOKEN_URL,
		array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $input . '.' . g5d_b64url( $sig ),
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body['access_token'] ) ) {
		$msg = isset( $body['error_description'] ) ? $body['error_description'] : 'Αποτυχία σύνδεσης με το Google.';
		return new WP_Error( 'g5d_gcal_auth', $msg );
	}
	$ttl = isset( $body['expires_in'] ) ? (int) $body['expires_in'] - 60 : 3000;
	set_transient( G5D_GCAL_TOKEN, $body['access_token'], max( 60, $ttl ) );
	return $body['access_token'];
}

/** Κλήση στο API του ημερολογίου. Επιστρέφει ['code' => int, 'body' => array|null] ή WP_Error. */
function g5d_gcal_request( $method, $url, $payload = null ) {
	$token = g5d_gcal_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$args = array(
		'method'  => $method,
		'timeout' => 15,
		'headers' => array( 'Authorization' => 'Bearer ' . $token ),
	);
	if ( null !== $payload ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $payload );
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 401 === $code ) {
		delete_transient( G5D_GCAL_TOKEN ); // το διακριτικό έληξε· το επόμενο αίτημα παίρνει νέο
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	return array( 'code' => $code, 'body' => $body );
}

/**
 * Το γεγονός του ημερολογίου από μια αίτηση. Καθαρή συνάρτηση.
 * Ο τίτλος παίρνει την αγκύλη με τα τμήματα, ώστε να διαβάζεται ξανά από τον πίνακα.
 */
function g5d_gcal_event_body( array $d, $request_id ) {
	$tz     = wp_timezone();
	$grades = is_array( $d['grades'] ) ? $d['grades'] : array();
	$title  = trim( (string) $d['title'] );
	if ( $grades && ! in_array( 'all', $grades, true ) ) {
		$title = '[' . implode( ', ', $grades ) . '] ' . $title;
	}

	$from = (string) $d['date_from'];
	$to   = '' !== (string) $d['date_to'] ? (string) $d['date_to'] : $from;
	if ( '' === (string) $d['time_from'] ) {
		// Ολοήμερη: η λήξη είναι η επόμενη μέρα από την τελευταία ημέρα.
		$start = array( 'date' => $from );
		$end   = array( 'date' => ( new DateTimeImmutable( $to, $tz ) )->modify( '+1 day' )->format( 'Y-m-d' ) );
	} else {
		$start_dt = new DateTimeImmutable( $from . ' ' . $d['time_from'], $tz );
		$end_dt   = '' !== (string) $d['time_to'] ? new DateTimeImmutable( $to . ' ' . $d['time_to'], $tz ) : $start_dt->modify( '+1 hour' );
		if ( $end_dt <= $start_dt ) {
			$end_dt = $start_dt->modify( '+1 hour' );
		}
		$start = array( 'dateTime' => $start_dt->format( DATE_RFC3339 ), 'timeZone' => $tz->getName() );
		$end   = array( 'dateTime' => $end_dt->format( DATE_RFC3339 ), 'timeZone' => $tz->getName() );
	}

	$lines = array();
	foreach ( g5d_field_labels() as $k => $label ) {
		// Στο ημερολόγιο μόνο τα πεδία που χρειάζονται: τύπος δράσης, υπεύθυνοι, συνοδοί και αναπληρωτές.
		if ( ! in_array( $k, array( 'action_type', 'teachers', 'companions', 'companions_alt' ), true ) ) {
			continue;
		}
		$v = g5d_display_value( $k, isset( $d[ $k ] ) ? $d[ $k ] : '' );
		if ( '' !== $v ) {
			$lines[] = $label . ': ' . $v;
		}
	}

	return array(
		'summary'             => $title,
		'location'            => (string) $d['place'],
		'description'         => implode( "\n", $lines ),
		'start'               => $start,
		'end'                 => $end,
		'extendedProperties'  => array( 'private' => array( 'g5d_request' => (string) $request_id ) ),
	);
}

function g5d_gcal_calendar_url() {
	$s = g5d_settings();
	return G5D_GCAL_API . rawurlencode( trim( $s['calendar_id'] ) ) . '/events';
}

/** Καταγράφει ή καθαρίζει το σφάλμα συγχρονισμού μιας αίτησης. */
function g5d_gcal_set_error( $id, $msg ) {
	update_post_meta( $id, 'g5d_gcal_error', $msg );
}

/**
 * Γράφει μια εγκεκριμένη δράση στο ημερολόγιο. Αν έχει ήδη εγγραφή, δεν κάνει τίποτα.
 * Επιστρέφει true αν η δράση είναι συγχρονισμένη ή δεν χρειάζεται συγχρονισμό.
 */
function g5d_gcal_sync_request( $id ) {
	$d = g5d_request_data( $id );
	if ( 'approved' !== $d['status'] || '' !== (string) get_post_meta( $id, 'g5d_gcal_event_id', true ) ) {
		return true;
	}
	if ( '' !== (string) $d['import_key'] ) {
		return true; // παλαιά εισαγωγή από το ημερολόγιο: υπάρχει ήδη εκεί, δεν ξαναγράφεται
	}
	if ( ! g5d_gcal_credentials() ) {
		return false; // δεν έχει οριστεί ακόμη λογαριασμός: η επανάληψη θα το πιάσει αργότερα
	}
	$res = g5d_gcal_request( 'POST', g5d_gcal_calendar_url(), g5d_gcal_event_body( $d, $id ) );
	if ( is_wp_error( $res ) ) {
		g5d_gcal_set_error( $id, $res->get_error_message() );
		return false;
	}
	if ( $res['code'] >= 300 || empty( $res['body']['id'] ) ) {
		g5d_gcal_set_error( $id, 'Το Google Calendar απέρριψε την εγγραφή (κωδικός ' . $res['code'] . ').' );
		return false;
	}
	update_post_meta( $id, 'g5d_gcal_event_id', $res['body']['id'] );
	delete_post_meta( $id, 'g5d_gcal_error' );
	return true;
}

/**
 * Σβήνει τη δράση από το ημερολόγιο. Αν το γεγονός έχει ήδη σβηστεί, θεωρείται επιτυχία.
 * Αν αποτύχει, το αναγνωριστικό μένει ώστε να δοκιμαστεί ξανά.
 */
function g5d_gcal_remove( $id ) {
	$event_id = (string) get_post_meta( $id, 'g5d_gcal_event_id', true );
	if ( '' === $event_id ) {
		return true;
	}
	if ( ! g5d_gcal_credentials() ) {
		return false;
	}
	$url = g5d_gcal_calendar_url() . '/' . rawurlencode( $event_id );
	$res = g5d_gcal_request( 'DELETE', $url );
	if ( is_wp_error( $res ) ) {
		g5d_gcal_set_error( $id, $res->get_error_message() );
		return false;
	}
	if ( $res['code'] >= 300 && 404 !== $res['code'] && 410 !== $res['code'] ) {
		g5d_gcal_set_error( $id, 'Η διαγραφή από το Google Calendar απέτυχε (κωδικός ' . $res['code'] . ').' );
		return false;
	}
	delete_post_meta( $id, 'g5d_gcal_event_id' );
	delete_post_meta( $id, 'g5d_gcal_error' );
	return true;
}

/* -------------------------------------------------------------------------
 * Σύνδεση με τις υπόλοιπες ενέργειες
 * ---------------------------------------------------------------------- */

function g5d_gcal_on_trash( $id ) {
	if ( G5D_CPT === get_post_type( $id ) ) {
		g5d_gcal_remove( $id );
	}
}
add_action( 'wp_trash_post', 'g5d_gcal_on_trash' );
add_action( 'before_delete_post', 'g5d_gcal_on_trash' );

function g5d_gcal_on_untrash( $id ) {
	if ( G5D_CPT === get_post_type( $id ) && 'approved' === get_post_meta( $id, 'g5d_status', true ) ) {
		g5d_gcal_sync_request( $id );
	}
}
add_action( 'untrash_post', 'g5d_gcal_on_untrash' );

/** Επανάληψη για όλες τις εγκεκριμένες δράσεις που δεν έχουν ακόμη εγγραφή. */
function g5d_gcal_resync_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'g5d_gcal_resync' );
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
	$ok = 0;
	foreach ( $ids as $id ) {
		if ( g5d_gcal_sync_request( $id ) ) {
			++$ok;
		}
	}
	wp_safe_redirect( admin_url( 'admin.php?page=g5d&g5d_gcal=' . ( $ok === count( $ids ) ? 'ok' : 'partial' ) ) );
	exit;
}
add_action( 'admin_post_g5d_gcal_resync', 'g5d_gcal_resync_action' );

/** Προειδοποίηση στο admin για δράσεις που δεν έγραψαν στο ημερολόγιο. */
function g5d_gcal_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$ids = get_posts(
		array(
			'post_type'   => G5D_CPT,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => 'g5d_gcal_error', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		)
	);
	if ( ! $ids ) {
		return;
	}
	$url = admin_url( 'admin.php?page=g5d&tab=settings' );
	echo '<div class="notice notice-warning"><p>' . esc_html( count( $ids ) ) . ' δράσεις δεν έχουν καταχωρηθεί στο Google Calendar. ' .
		'<a href="' . esc_url( $url ) . '">Επανάληψη συγχρονισμού</a>.</p></div>';
}
add_action( 'admin_notices', 'g5d_gcal_admin_notice' );
