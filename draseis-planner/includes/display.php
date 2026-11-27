<?php
/**
 * Draseis Planner: εμφάνιση του πίνακα δράσεων και shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Φτιάχνει το HTML. Καθαρή συνάρτηση: όλα τα δεδομένα έρχονται ως παράμετροι.
 */
/** Ο τύπος στον πίνακα χωρίς τον αριθμό του άρθρου («3. Επίσκεψη…» → «Επίσκεψη…»). Ο αριθμός μένει στη φόρμα. */
function g5d_strip_type_number( $type ) {
	return trim( (string) preg_replace( '/^\s*\d+\.\s*/u', '', (string) $type ) );
}

function g5d_render( array $events, $fetched, $show_past, $now_ts, DateTimeZone $tz, $notice = '' ) {
	$months   = array();
	// Η «επόμενη» ημέρα: η ημέρα της πρώτης δράσης που δεν έχει τελειώσει. Όλες οι δράσεις της ημέρας είναι «επόμενες».
	$next_day = null;
	foreach ( $events as $e ) {
		if ( $e['end'] > $now_ts ) {
			$next_day = ( new DateTimeImmutable( '@' . $e['start'] ) )->setTimezone( $tz )->format( 'Y-m-d' );
			break;
		}
	}
	foreach ( $events as $e ) {
		$start   = ( new DateTimeImmutable( '@' . $e['start'] ) )->setTimezone( $tz );
		$is_past = $e['end'] <= $now_ts;
		$is_next = ! $is_past && $start->format( 'Y-m-d' ) === $next_day;
		$key = $start->format( 'Y-m' );
		if ( ! isset( $months[ $key ] ) ) {
			$months[ $key ] = array(
				'label' => g5d_month_name( $start->format( 'n' ) ) . ' ' . $start->format( 'Y' ),
				'rows'  => array(),
			);
		}
		$letters = array();
		foreach ( $e['grades'] as $g ) {
			$letters[] = mb_substr( $g, 0, 1, 'UTF-8' );
		}
		$months[ $key ]['rows'][] = array(
			'e'       => $e,
			'day'     => $start->format( 'j' ),
			'day_key' => $start->format( 'Y-m-d' ),
			'wd'      => g5d_weekday( $start->format( 'w' ) ),
			'mo'      => g5d_month_short( $start->format( 'n' ) ),
			'time'    => g5d_time_label( $e, $tz ),
			'is_past' => $is_past,
			'is_next' => $is_next,
			'letters' => implode( ' ', array_unique( $letters ) ),
		);
	}

	$root_class = 'g5d' . ( $show_past ? ' g5d-show-past' : '' );
	$show_keys  = g5d_show_keys();
	$labels     = g5d_extra_fields();
	ob_start();
	?>
	<div class="<?php echo esc_attr( $root_class ); ?>">
		<?php if ( '' !== $notice ) : ?>
			<p class="g5d-notice"><?php echo esc_html( $notice ); ?></p>
		<?php endif; ?>
		<div class="g5d-toolbar">
			<div class="g5d-filters" role="group" aria-label="Φίλτρο τάξης">
				<span class="g5d-filters-label">Τάξη</span>
				<?php
				// Οι τάξεις βγαίνουν από το πρώτο γράμμα των τμημάτων των ρυθμίσεων (π.χ. Α1 → Α΄).
				$opts = array( 'all' => 'Όλες' );
				foreach ( g5d_grade_options() as $cls ) {
					$ch = mb_substr( $cls, 0, 1, 'UTF-8' );
					if ( ! isset( $opts[ $ch ] ) ) {
						$opts[ $ch ] = $ch . '΄';
					}
				}
				foreach ( $opts as $id => $label ) :
					?>
					<button type="button" class="g5d-filter" data-grade="<?php echo esc_attr( $id ); ?>" aria-pressed="<?php echo 'all' === $id ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</div>
			<button type="button" class="g5d-toggle-past" aria-pressed="<?php echo $show_past ? 'true' : 'false'; ?>"><?php echo $show_past ? 'Απόκρυψη παλαιότερων' : 'Εμφάνιση παλαιότερων'; ?></button>
		</div>

		<?php
		$any_visible = false;
		foreach ( $months as $month ) :
			$visible = 0;
			foreach ( $month['rows'] as $r ) {
				if ( $show_past || ! $r['is_past'] ) {
					$visible++;
				}
			}
			if ( $visible ) {
				$any_visible = true;
			}
			?>
			<section class="g5d-month"<?php echo $visible ? '' : ' hidden'; ?>>
				<div class="g5d-month-head">
					<h2 class="g5d-month-title"><?php echo esc_html( $month['label'] ); ?></h2>
					<span class="g5d-count"><?php echo esc_html( g5d_count_label( $visible ) ); ?></span>
				</div>
				<?php
				foreach ( $month['rows'] as $r ) :
					$e   = $r['e'];
					$cls = 'g5d-row' . ( $r['is_past'] ? ' is-past' : '' ) . ( $r['is_next'] ? ' is-next' : '' );
					?>
					<div class="<?php echo esc_attr( $cls ); ?>" data-grades="<?php echo esc_attr( $r['letters'] ); ?>" data-past="<?php echo $r['is_past'] ? '1' : '0'; ?>" data-day="<?php echo esc_attr( $r['day_key'] ); ?>"<?php echo ( ! $show_past && $r['is_past'] ) ? ' hidden' : ''; ?>>
						<div class="g5d-date">
							<span class="g5d-wd"><?php echo esc_html( $r['wd'] ); ?></span>
							<span class="g5d-day"><?php echo esc_html( $r['day'] ); ?></span>
							<span class="g5d-mo"><?php echo esc_html( $r['mo'] ); ?></span>
						</div>
						<div class="g5d-time"><?php echo esc_html( $r['time'] ); ?></div>
						<div class="g5d-main">
							<div class="g5d-titleline">
								<span class="g5d-title"><?php echo esc_html( $e['title'] ); ?></span>
								<span class="g5d-badge g5d-badge-next">Επόμενη</span>
								<span class="g5d-badge g5d-badge-past">Ολοκληρώθηκε</span>
							</div>
							<?php
							$group_parts = array();
							if ( ! empty( $e['group'] ) ) {
								$group_parts[] = $e['group'];
							}
							foreach ( $e['grades'] as $g ) {
								$group_parts[] = g5d_chip_label( $g );
							}
							if ( ! $group_parts ) {
								$group_parts[] = 'Όλο το σχολείο';
							}
							?>
							<dl class="g5d-extra">
								<?php if ( ! empty( $e['extra']['action_type'] ) ) : ?>
									<div><dt>Τύπος δράσης</dt><dd><?php echo esc_html( g5d_strip_type_number( $e['extra']['action_type'] ) ); ?></dd></div>
								<?php endif; ?>
								<?php if ( '' !== $e['location'] ) : ?>
									<div><dt>Χώρος</dt><dd><?php echo esc_html( $e['location'] ); ?></dd></div>
								<?php endif; ?>
								<div><dt>Ομάδα / Τμήματα</dt><dd><?php echo esc_html( implode( ', ', $group_parts ) ); ?></dd></div>
							</dl>
							<?php
							$extra_fields = array();
							foreach ( $show_keys as $k ) {
								if ( 'action_type' !== $k && isset( $e['extra'][ $k ] ) ) {
									$extra_fields[ $k ] = $e['extra'][ $k ];
								}
							}
							if ( $extra_fields ) :
								?>
								<details class="g5d-more">
									<summary>Περισσότερα</summary>
									<dl class="g5d-extra">
										<?php foreach ( $extra_fields as $k => $v ) : ?>
											<div><dt><?php echo esc_html( $labels[ $k ] ); ?></dt><dd><?php echo nl2br( esc_html( $v ) ); ?></dd></div>
										<?php endforeach; ?>
									</dl>
								</details>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</section>
		<?php endforeach; ?>

		<p class="g5d-empty"<?php echo $any_visible ? ' hidden' : ''; ?>>Δεν υπάρχουν δράσεις για εμφάνιση.</p>

		<?php if ( $fetched ) : ?>
			<p class="g5d-updated">Τελευταία ενημέρωση: <?php echo esc_html( ( new DateTimeImmutable( '@' . (int) $fetched ) )->setTimezone( $tz )->format( 'j/n/Y H:i' ) ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Shortcode και αρχεία στυλ/script
 * ---------------------------------------------------------------------- */

function g5d_register_assets() {
	wp_register_style( 'g5d', plugins_url( 'assets/draseis-planner.css', G5D_FILE ), array(), G5D_VERSION );
	wp_register_script( 'g5d', plugins_url( 'assets/draseis-planner.js', G5D_FILE ), array(), G5D_VERSION, true );
	// Φορτώνει το στυλ στο <head> όταν η σελίδα έχει το shortcode.
	if ( is_singular() ) {
		$post = get_post();
		if ( $post && has_shortcode( $post->post_content, 'draseis_planner' ) ) {
			wp_enqueue_style( 'g5d' );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'g5d_register_assets' );
