<?php
/** Template of the branch page (used when the plugin runs the page). */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
echo '<main id="sbr-page" class="sbrpage">' . csa_br_page() . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput
get_footer();
