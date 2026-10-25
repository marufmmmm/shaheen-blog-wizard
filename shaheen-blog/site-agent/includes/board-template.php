<?php
/** Template of the notice board page (used when the plugin runs the page). */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
echo '<main id="sn-boardpage" class="sn-boardpage">' . csa_sn_board() . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput
get_footer();
