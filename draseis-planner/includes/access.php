<?php
/**
 * Draseis Planner: πρόσβαση με κωδικό για τη φόρμα.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Υποσέλιδο «Δημιουργία: 5ο Γυμνάσιο Χανίων · v…». Το script το μεταφέρει μέσα στο <article>
 * της σελίδας, κάτω δεξιά, ώστε να μην προσθέτει κενό.
 */
function g5d_credit() {
	return '<p class="g5d-credit">Δημιουργία: 5ο Γυμνάσιο Χανίων · Ελεύθερο λογισμικό · <a href="https://github.com/pavtheod/draseis-planner" target="_blank" rel="noopener noreferrer">Κατεβάστε το Draseis Planner (GitHub)</a> · v' . esc_html( G5D_VERSION ) . '</p>'
		. '<script>(function(){var c=document.querySelector(".g5d-credit");var a=c&&c.closest?c.closest("article"):null;if(!a)return;a.appendChild(c);if(getComputedStyle(a).position==="static")a.classList.add("g5d-credit-host");c.classList.add("g5d-credit-corner");})();</script>';
}

function g5d_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'past' => '' ), $atts, 'draseis_planner' );
	// Το past="show" ή past="hide" στο shortcode υπερισχύει της ρύθμισης «Παλαιότερες δράσεις» (καρτέλα Εμφάνιση).
	$show_past = 'show' === $atts['past'] || ( 'hide' !== $atts['past'] && (bool) get_option( 'g5d_show_past', 0 ) );
	// Τα plugins cache (WP Fastest Cache κ.ά.) δεν αποθηκεύουν αυτή τη σελίδα,
	// ώστε οι νέες δράσεις να φαίνονται χωρίς καθυστέρηση από την cache της σελίδας.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	wp_enqueue_style( 'g5d' );
	wp_enqueue_script( 'g5d' );

	$data = g5d_get_events();
	if ( is_wp_error( $data ) ) {
		if ( current_user_can( 'manage_options' ) ) {
			return '<p class="g5d-error">Πρόγραμμα Δράσεων: ' . esc_html( $data->get_error_message() ) . '</p>';
		}
		return '<p class="g5d-error">Το πρόγραμμα δράσεων δεν είναι διαθέσιμο αυτή τη στιγμή. Δοκιμάστε ξανά αργότερα.</p>';
	}

	$html = g5d_render( $data['events'], $data['fetched'], $show_past, time(), wp_timezone() );
	$url  = g5d_settings()['form_url'];
	if ( '' !== $url ) {
		$html .= '<p class="g5d-cta"><a class="g5d-btn" href="' . esc_url( ( 'sso' === g5d_settings()['access'] && ! g5d_has_access() ) ? add_query_arg( 'g5d_sso', '1', $url ) : $url ) . '">Είσοδος καθηγητών</a></p>';
	}
	$html .= g5d_credit();
	return $html;
}
add_shortcode( 'draseis_planner', 'g5d_shortcode' );

/* -------------------------------------------------------------------------
 * Σελίδα ρυθμίσεων: Ρυθμίσεις → Πρόγραμμα Δράσεων
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Πρόσβαση καθηγητών: διακριτικά (cookies) για τον κοινό κωδικό και για τη σύνδεση ΠΣΔ.
 * ---------------------------------------------------------------------- */

function g5d_secret() {
	// Η αλλαγή του κοινού κωδικού (code_ver) ή του τρόπου εισόδου (κωδικός/ΠΣΔ) ακυρώνει όλα τα διακριτικά.
	$s = g5d_settings();
	return wp_salt( 'auth' ) . '|' . (int) $s['code_ver'] . '|' . $s['access'];
}

/**
 * Διακριτικό: «λήξη.υπογραφή». Η υπογραφή κλειδώνει τη λήξη με μυστικό του site.
 * Το $purpose χωρίζει τα είδη: 'g5d' = cookie πρόσβασης, 'g5d-sso' = σύντομο διακριτικό επιστροφής από το ΠΣΔ.
 */
function g5d_token_make( $expires, $secret, $purpose = 'g5d' ) {
	return $expires . '.' . hash_hmac( 'sha256', $purpose . '|' . $expires, $secret );
}

function g5d_token_valid( $token, $now, $secret, $purpose = 'g5d' ) {
	if ( ! is_string( $token ) || false === strpos( $token, '.' ) ) {
		return false;
	}
	list( $exp, $sig ) = explode( '.', $token, 2 );
	if ( ! ctype_digit( $exp ) || (int) $exp < $now ) {
		return false;
	}
	return hash_equals( hash_hmac( 'sha256', $purpose . '|' . $exp, $secret ), (string) $sig );
}

function g5d_has_access() {
	$token = isset( $_COOKIE[ G5D_COOKIE ] ) ? wp_unslash( $_COOKIE[ G5D_COOKIE ] ) : '';
	return g5d_token_valid( $token, time(), g5d_secret() );
}

function g5d_cookie_args( $expires ) {
	return array(
		'expires'  => $expires,
		'path'     => '/',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	);
}

/**
 * Ελέγχει τον κωδικό. Επιστρέφει 'ok', 'bad', 'locked' ή 'nocode'.
 * Μετά από 5 λάθη, κλειδώνει για 15 λεπτά για αυτή τη διεύθυνση IP.
 */
function g5d_try_login( $code ) {
	$set = (string) g5d_settings()['code'];
	if ( '' === $set ) {
		return 'nocode';
	}
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$key   = 'g5d_fail_' . md5( $ip );
	$fails = (int) get_transient( $key );
	if ( $fails >= 5 ) {
		return 'locked';
	}
	if ( hash_equals( $set, (string) $code ) ) {
		delete_transient( $key );
		return 'ok';
	}
	set_transient( $key, $fails + 1, 15 * MINUTE_IN_SECONDS );
	return 'bad';
}

/** Επιστρέφει στη σελίδα από όπου ήρθε ο χρήστης, με προαιρετικές παραμέτρους. */
function g5d_back_url( array $args = array() ) {
	$url = wp_get_referer();
	if ( ! $url ) {
		$url = home_url( '/' );
	}
	$url = remove_query_arg( 'g5d', $url );
	return add_query_arg( $args, $url );
}

function g5d_login_action() {
	check_admin_referer( 'g5d_login' );
	if ( 'sso' === g5d_settings()['access'] ) {
		// Με ΠΣΔ ενεργό, ο κοινός κωδικός δεν γίνεται δεκτός.
		wp_safe_redirect( g5d_back_url( array( 'g5d' => 'sso_only' ) ) );
		exit;
	}
	$result = g5d_try_login( isset( $_POST['g5d_code'] ) ? wp_unslash( $_POST['g5d_code'] ) : '' );
	if ( 'ok' === $result ) {
		setcookie( G5D_COOKIE, g5d_token_make( time() + G5D_ACCESS_HOURS * HOUR_IN_SECONDS, g5d_secret() ), g5d_cookie_args( time() + G5D_ACCESS_HOURS * HOUR_IN_SECONDS ) );
		wp_safe_redirect( g5d_back_url() );
		exit;
	}
	wp_safe_redirect( g5d_back_url( array( 'g5d' => $result ) ) );
	exit;
}

function g5d_logout_action() {
	check_admin_referer( 'g5d_logout' );
	setcookie( G5D_COOKIE, '', g5d_cookie_args( time() - HOUR_IN_SECONDS ) );
	// Με ΠΣΔ, η έξοδος πρέπει να βγάλει και τη σύνδεση στο ΠΣΔ· αλλιώς η είσοδος ξαναμπαίνει αμέσως.
	if ( 'sso' === g5d_settings()['access'] ) {
		// Η υπηρεσία που δηλώθηκε στο ΠΣΔ (όχι η φόρμα), ώστε το ΠΣΔ να την αναγνωρίζει μετά την έξοδο.
		$home = add_query_arg( 'g5d_sso', 'out', g5d_sso_service_base() );
		if ( '' === $home ) {
			$home = g5d_back_url();
		}
		wp_redirect( 'https://' . g5d_cas_host() . '/logout?service=' . rawurlencode( $home ) );
		exit;
	}
	wp_safe_redirect( g5d_back_url() );
	exit;
}

/**
 * Shortcode [g5d_proposals]: φόρμα σύνδεσης με τον κοινό κωδικό.
 * Η ίδια η φόρμα υποβολής θα μπει εδώ μετά την έγκριση του σχεδίου.
 */
function g5d_gate_shortcode( $atts ) {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	wp_enqueue_style( 'g5d' );
	$flag     = isset( $_GET['g5d'] ) ? sanitize_key( wp_unslash( $_GET['g5d'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$messages = array(
		'bad'    => 'Ο κωδικός δεν είναι σωστός.',
		'locked' => 'Πολλές λανθασμένες προσπάθειες. Δοκιμάστε ξανά σε 15 λεπτά.',
		'nocode' => 'Ο κωδικός πρόσβασης δεν έχει οριστεί ακόμη.',
	);
	$messages += array(
		'sso_only'   => 'Η είσοδος γίνεται μόνο με τον λογαριασμό ΠΣΔ.',
		'sso_role'   => 'Ο λογαριασμός σας δεν έχει ρόλο εκπαιδευτικού.',
		'sso_unit'   => 'Ο λογαριασμός σας δεν ανήκει στη σχολική μονάδα.',
		'sso_nounit' => 'Η είσοδος με ΠΣΔ δεν έχει ρυθμιστεί ακόμη.',
		'sso_server' => 'Η σύνδεση με το ΠΣΔ δεν είναι διαθέσιμη αυτή τη στιγμή. Δοκιμάστε ξανά αργότερα.',
	);
	$sso = 'sso' === g5d_settings()['access'];
	$msg = isset( $messages[ $flag ] ) ? $messages[ $flag ] : '';
	$action   = esc_url( admin_url( 'admin-post.php' ) );

	ob_start();
	echo '<div class="g5d g5d-gate">';
	if ( g5d_has_access() ) {
		echo '<p class="g5d-notice">Έχετε πρόσβαση.</p>';
		echo '<p>Η φόρμα υποβολής δράσεων θα εμφανιστεί εδώ.</p>';
		echo '<form method="post" action="' . $action . '">';
		echo '<input type="hidden" name="action" value="g5d_logout">';
		wp_nonce_field( 'g5d_logout' );
		echo '<button type="submit" class="g5d-filter">Αποσύνδεση</button>';
		echo '</form>';
	} else {
		if ( '' !== $msg ) {
			echo '<p class="g5d-notice">' . esc_html( $msg ) . '</p>';
		}
		if ( $sso ) {
				echo '<p class="g5d-cta"><a class="g5d-btn" href="' . esc_url( add_query_arg( 'g5d_sso', '1' ) ) . '">Είσοδος με λογαριασμό ΠΣΔ</a></p>';
				// Μετά την επιστροφή από το ΠΣΔ, το διακριτικό έρχεται στο fragment· το στέλνουμε για να μπει cookie σε αυτόν τον host.
				echo '<form id="g5d-sso-session" method="post" action="' . $action . '" hidden>';
				echo '<input type="hidden" name="action" value="g5d_sso_session">';
				wp_nonce_field( 'g5d_sso_session' );
				echo '<input type="hidden" name="token" id="g5d-sso-token" value="">';
				echo '</form>';
				echo '<script>(function(){var m=/^#g5d-sso=([0-9]+\.[0-9a-f]{64})$/.exec(window.location.hash);if(!m){return;}document.getElementById("g5d-sso-token").value=m[1];document.getElementById("g5d-sso-session").submit();})();</script>';
			echo '</div>';
			return ob_get_clean();
		}
		echo '<form method="post" action="' . $action . '" class="g5d-gate-form">';
		echo '<input type="hidden" name="action" value="g5d_login">';
		wp_nonce_field( 'g5d_login' );
		echo '<label for="g5d_code_input">Κωδικός πρόσβασης</label> ';
		echo '<input type="password" id="g5d_code_input" name="g5d_code" autocomplete="current-password" required> ';
		echo '<button type="submit" class="g5d-filter">Είσοδος</button>';
		echo '</form>';
	}
	echo '</div>';
	return ob_get_clean();
}

add_shortcode( 'g5d_proposals', 'g5d_gate_shortcode' );
add_action( 'admin_post_g5d_login', 'g5d_login_action' );
add_action( 'admin_post_nopriv_g5d_login', 'g5d_login_action' );
add_action( 'admin_post_g5d_logout', 'g5d_logout_action' );
add_action( 'admin_post_nopriv_g5d_logout', 'g5d_logout_action' );
