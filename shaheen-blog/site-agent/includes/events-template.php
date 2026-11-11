<?php
/** Full-width template of an event page and of the new home page (used when the plugin runs the page). */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
$csa_id = (int) get_queried_object_id();
$csa_ev = csa_ev_page_event_id( $csa_id );
$GLOBALS['csa_ev_done'] = true;
if ( $csa_ev && csa_ev_get( $csa_ev ) ) {
	$csa_html = csa_ev_event_html( csa_ev_full( csa_ev_get( $csa_ev ) ) );
} else {
	$csa_html = csa_ev_home_html();
}
echo '<main id="csa-ev-page" class="csa-evpage">' . $csa_html . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput
get_footer();
