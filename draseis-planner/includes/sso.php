<?php
/**
 * Draseis Planner: είσοδος καθηγητών μέσω της Κεντρικής Υπηρεσίας Πιστοποίησης (SSO) του ΠΣΔ.
 *
 * Πρωτόκολλο CAS 3.0 με τη βιβλιοθήκη phpCAS (vendor/phpCAS). Το plugin είναι αυτόνομο:
 * δεν μοιράζεται κώδικα με το Exams Planner.
 *
 * Ροή:
 *   1. «Είσοδος με λογαριασμό ΠΣΔ» → <τρέχουσα σελίδα>?g5d_sso=1
 *   2. phpCAS → σύνδεση στο ΠΣΔ → επιστροφή στη διεύθυνση υπηρεσίας με ticket → επικύρωση
 *   3. Έλεγχος: ρόλος εκπαιδευτικού ΚΑΙ κωδικός σχολικής μονάδας
 *   4. Δημιουργία του ίδιου διακριτικού πρόσβασης με τον κοινό κωδικό (cookie 4 ωρών) και
 *      επιστροφή στη σελίδα της φόρμας.
 *
 * Δεν αποθηκεύεται το όνομα χρήστη ούτε κάποιο αναγνωριστικό του ΠΣΔ.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Διακομιστής CAS: κανονικός ή δοκιμαστικός, από τις ρυθμίσεις. */
function g5d_cas_host() {
	$s = g5d_settings();
	return ( 'test' === $s['sso_server'] ) ? 'sso-01-test.sch.gr' : 'sso.sch.gr';
}

/** Η διεύθυνση υπηρεσίας όπως δηλώθηκε στο ΠΣΔ. */
function g5d_sso_service_base( $s = null ) {
	$s = $s ? $s : g5d_settings();
	return trim( (string) $s['sso_service'] );
}

/** Η διεύθυνση που στέλνεται στο ΠΣΔ: η διεύθυνση υπηρεσίας με ?g5d_sso=1. */
function g5d_sso_service_url( $s = null ) {
	return add_query_arg( 'g5d_sso', '1', g5d_sso_service_base( $s ) );
}

function g5d_sso_requirements_ok() {
	return function_exists( 'curl_init' ) && class_exists( 'DOMDocument' );
}

/** Τιμές attribute ως πίνακας συμβολοσειρών, ανεξάρτητα από κεφαλαία/πεζά στο όνομα. */
function g5d_sso_attr( array $attrs, $name ) {
	foreach ( $attrs as $k => $v ) {
		if ( 0 === strcasecmp( (string) $k, $name ) ) {
			$vals = is_array( $v ) ? $v : array( $v );
			return array_values( array_filter( array_map( 'strval', $vals ), 'strlen' ) );
		}
	}
	return array();
}

/**
 * Έλεγχος εξουσιοδότησης από τα attributes του ΠΣΔ.
 *
 * @return true|string true αν επιτρέπεται, αλλιώς κωδικός λόγου απόρριψης.
 */
function g5d_sso_authorize( array $attrs, $s ) {
	$roles_ok = array_filter( array_map( 'strtolower', array_map( 'trim', explode( ',', (string) $s['sso_roles'] ) ) ) );
	$roles    = array_map( 'strtolower', g5d_sso_attr( $attrs, 'umdobject' ) );
	if ( ! array_intersect( $roles, $roles_ok ) ) {
		return 'role';
	}
	$units = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $s['sso_units'] ) ) );
	if ( ! $units ) {
		return 'nounit';
	}
	// Ο κωδικός μονάδας ψάχνεται ως ολόκληρη λέξη σε όλες τις τιμές εκτός από umdobject,
	// επειδή η ακριβής μορφή του attribute μονάδας στο XML του ΠΣΔ δεν είναι γνωστή εκ των προτέρων.
	$haystack = array();
	foreach ( $attrs as $k => $v ) {
		if ( 0 === strcasecmp( (string) $k, 'umdobject' ) ) {
			continue;
		}
		foreach ( ( is_array( $v ) ? $v : array( $v ) ) as $one ) {
			if ( is_scalar( $one ) ) {
				$haystack[] = (string) $one;
			}
		}
	}
	foreach ( $units as $u ) {
		foreach ( $haystack as $v ) {
			if ( preg_match( '/(?<![0-9A-Za-z])' . preg_quote( $u, '/' ) . '(?![0-9A-Za-z])/u', $v ) ) {
				return true;
			}
		}
	}
	return 'unit';
}

function g5d_sso_clear_session() {
	if ( PHP_SESSION_ACTIVE === session_status() ) {
		$_SESSION = array();
		session_destroy();
	}
}

/** Χειρισμός της επιστροφής από το ΠΣΔ (?g5d_sso=1). Τρέχει πριν από την έξοδο της σελίδας. */
function g5d_sso_handle() {
	if ( ! isset( $_GET['g5d_sso'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$s = g5d_settings();
	if ( 'sso' !== $s['access'] ) {
		return;
	}
	nocache_headers();

	// Επιστροφή από την έξοδο του ΠΣΔ: πίσω στη φόρμα.
	if ( 'out' === $_GET['g5d_sso'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		nocache_headers();
		$to = trim( (string) $s['form_url'] );
		wp_safe_redirect( '' !== $to ? $to : home_url( '/' ) );
		exit;
	}

	// Επιστροφή στη σελίδα της φόρμας (όπου είναι η πύλη εισόδου), αλλιώς στη σελίδα όπου ξεκίνησε η είσοδος.
	$back = trim( (string) $s['form_url'] );
	if ( '' === $back ) {
		$back = get_permalink( get_queried_object_id() );
	}
	if ( ! $back ) {
		$back = home_url( '/' );
	}
	$back = remove_query_arg( array( 'g5d_sso', 'ticket', 'g5d' ), $back );

	if ( ! g5d_sso_requirements_ok() ) {
		wp_safe_redirect( add_query_arg( 'g5d', 'sso_server', $back ) );
		exit;
	}
	if ( '' === g5d_sso_service_base( $s ) || '' === trim( (string) $s['sso_units'] ) ) {
		wp_safe_redirect( add_query_arg( 'g5d', 'sso_nounit', $back ) );
		exit;
	}

	require_once plugin_dir_path( G5D_FILE ) . 'vendor/phpCAS/source/CAS.php';

	$service = g5d_sso_service_url( $s );
	$parts   = wp_parse_url( $service );
	$origin  = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

	try {
		phpCAS::client( CAS_VERSION_3_0, g5d_cas_host(), 443, '', $origin );
		// Πάντα η διεύθυνση που δηλώθηκε στο ΠΣΔ.
		phpCAS::setFixedServiceURL( $service );
		phpCAS::setCasServerCACert( apply_filters( 'g5d_cas_ca_cert', ABSPATH . WPINC . '/certificates/ca-bundle.crt' ) );
		phpCAS::setNoClearTicketsFromUrl();
		phpCAS::forceAuthentication(); // ανακατευθύνει στο ΠΣΔ αν χρειάζεται, αλλιώς επικυρώνει το ticket
		$attrs = (array) phpCAS::getAttributes();
	} catch ( Exception $e ) {
		g5d_sso_clear_session();
		wp_safe_redirect( add_query_arg( 'g5d', 'sso_server', $back ) );
		exit;
	}

	// Δεν κρατάμε τη συνεδρία του phpCAS: η επόμενη είσοδος περνάει ξανά από το ΠΣΔ.
	g5d_sso_clear_session();

	$auth = g5d_sso_authorize( $attrs, $s );
	if ( true !== $auth ) {
		wp_safe_redirect( add_query_arg( 'g5d', 'sso_' . $auth, $back ) );
		exit;
	}

	// Το cookie δεν περνάει από τον έναν host στον άλλο (ο host της υπηρεσίας ΠΣΔ → ο host του ιστότοπου), γι' αυτό
	// στέλνουμε σύντομο διακριτικό στο fragment (#g5d-sso=…). Το fragment δεν φτάνει ποτέ στον server.
	// Η σελίδα της φόρμας το ανταλλάσσει με cookie στον δικό της host (g5d_sso_session_action).
	$handoff = g5d_token_make( time() + 5 * MINUTE_IN_SECONDS, g5d_secret(), 'g5d-sso' );
	wp_safe_redirect( $back . '#g5d-sso=' . $handoff );
	exit;
}
add_action( 'template_redirect', 'g5d_sso_handle', 1 );

/** Ανταλλάσσει το διακριτικό του fragment με cookie πρόσβασης στον host της σελίδας. */
function g5d_sso_session_action() {
	check_admin_referer( 'g5d_sso_session' );
	$s     = g5d_settings();
	$token = isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';
	if ( 'sso' === $s['access'] && g5d_token_valid( $token, time(), g5d_secret(), 'g5d-sso' ) ) {
		$exp = time() + G5D_ACCESS_HOURS * HOUR_IN_SECONDS;
		setcookie( G5D_COOKIE, g5d_token_make( $exp, g5d_secret() ), g5d_cookie_args( $exp ) );
	}
	$to = trim( (string) $s['form_url'] );
	wp_safe_redirect( '' !== $to ? $to : g5d_back_url() );
	exit;
}
add_action( 'admin_post_g5d_sso_session', 'g5d_sso_session_action' );
add_action( 'admin_post_nopriv_g5d_sso_session', 'g5d_sso_session_action' );

/** Το wp_safe_redirect επιτρέπει μόνο τους δικούς μας hosts· προσθέτουμε τη φόρμα και την υπηρεσία ΠΣΔ. */
add_filter( 'allowed_redirect_hosts', function ( $hosts ) {
	$s = g5d_settings();
	foreach ( array( (string) $s['form_url'], g5d_sso_service_base( $s ) ) as $u ) {
		$h = '' !== $u ? wp_parse_url( $u, PHP_URL_HOST ) : '';
		if ( $h ) {
			$hosts[] = $h;
		}
	}
	return $hosts;
} );
