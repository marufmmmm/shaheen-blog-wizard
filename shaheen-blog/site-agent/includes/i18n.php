<?php
/**
 * Interface translation: English source strings, Bangla when Settings > Interface language = বাংলা.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function csa_t( $s ) {
	static $bn = null;
	if ( 'bn' !== csa_opt( 'ui_lang' ) ) {
		return $s;
	}
	if ( null === $bn ) {
		$bn = include CSA_DIR . 'includes/lang-bn.php';
		if ( ! is_array( $bn ) ) {
			$bn = array();
		}
	}
	return $bn[ $s ] ?? $s;
}
