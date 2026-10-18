<?php
/**
 * Install: database tables, cron, upgrade check.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_DB_VERSION = '9';

function csa_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'csa_' . $name;
}

function csa_install() {
	global $wpdb;
	$c = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		'CREATE TABLE ' . csa_table( 'chats' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		title varchar(200) NOT NULL DEFAULT '',
		pinned tinyint(1) NOT NULL DEFAULT 0,
		state varchar(20) NOT NULL DEFAULT 'analyse',
		pages varchar(255) NOT NULL DEFAULT '',
		section varchar(20) NOT NULL DEFAULT '',
		messages longtext NOT NULL,
		created datetime NOT NULL,
		updated datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY updated (updated)
		) $c;"
	);

	dbDelta(
		'CREATE TABLE ' . csa_table( 'jobs' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		keyword varchar(255) NOT NULL DEFAULT '',
		description text NOT NULL,
		settings text NOT NULL,
		plan longtext NOT NULL,
		content longtext NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'new',
		post_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created datetime NOT NULL,
		updated datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY status (status)
		) $c;"
	);

	// v2.5: move sites still on the old $5 / 12M defaults to $20 / 50M and the safer caps.
	$o = get_option( CSA_OPT );
	if ( is_array( $o ) && version_compare( (string) get_option( 'csa_db_version', '0' ), '4', '<' ) ) {
		$map = array( 'budget_usd' => array( 5, 20 ), 'allowance_tokens' => array( 12000000, 50000000 ), 'chat_cap_pct' => array( 5, 3 ), 'day_cap_pct' => array( 25, 10 ) );
		foreach ( $map as $k => $v ) {
			if ( ! isset( $o[ $k ] ) || (float) $o[ $k ] === (float) $v[0] ) {
				$o[ $k ] = $v[1];
			}
		}
		update_option( CSA_OPT, $o );
	}
	// v2.7: approved plans run to the end; the old 5-step pause becomes 8 steps for investigating only.
	$o = get_option( CSA_OPT );
	if ( is_array( $o ) && version_compare( (string) get_option( 'csa_db_version', '0' ), '5', '<' ) ) {
		if ( ! isset( $o['max_steps'] ) || 5 === (int) $o['max_steps'] ) {
			$o['max_steps'] = 8;
		}
		$o['build_steps'] = $o['build_steps'] ?? 30;
		update_option( CSA_OPT, $o );
	}

	// v2.7.1: bigger jobs per chat: 3% → 10% per chat, 10% → 20% per day (only if still on the old defaults).
	$o = get_option( CSA_OPT );
	if ( is_array( $o ) && version_compare( (string) get_option( 'csa_db_version', '0' ), '6', '<' ) ) {
		if ( ! isset( $o['chat_cap_pct'] ) || 3 === (int) $o['chat_cap_pct'] ) {
			$o['chat_cap_pct'] = 10;
		}
		if ( ! isset( $o['day_cap_pct'] ) || 10 === (int) $o['day_cap_pct'] ) {
			$o['day_cap_pct'] = 20;
		}
		update_option( CSA_OPT, $o );
	}

	// v2.8: chat memory window 2 → 3 messages (the memory file keeps the rest).
	$o = get_option( CSA_OPT );
	if ( is_array( $o ) && version_compare( (string) get_option( 'csa_db_version', '0' ), '7', '<' ) ) {
		if ( ! isset( $o['history_turns'] ) || 2 === (int) $o['history_turns'] ) {
			$o['history_turns'] = 3;
		}
		update_option( CSA_OPT, $o );
	}

	// v2.33: "finish the whole job" defaults. Economy mode (short memory, 8-step pause, shortened tool results) made the
	// agent stop after a few jobs and forget earlier work; quality mode is the new default, the limits stay as safety nets.
	$o = get_option( CSA_OPT );
	if ( is_array( $o ) && version_compare( (string) get_option( 'csa_db_version', '0' ), '9', '<' ) ) {
		$o['economy']            = 0;
		$o['max_steps']          = max( 25, (int) ( $o['max_steps'] ?? 0 ) );
		$o['build_steps']        = max( 60, (int) ( $o['build_steps'] ?? 0 ) );
		$o['history_turns']      = max( 6, (int) ( $o['history_turns'] ?? 0 ) );
		$o['max_request_tokens'] = max( 60000, (int) ( $o['max_request_tokens'] ?? 0 ) );
		update_option( CSA_OPT, $o );
		// Site-wide facts the agent already learned now also show in Blog Write .ai > Memory.
		$am = get_option( 'csa_auto_mem', array() );
		if ( is_array( $am ) && ! empty( $am['site'] ) && function_exists( 'sb_memory_add' ) ) {
			foreach ( $am['site'] as $k => $f ) {
				if ( sb_memory_add( 'fact', mb_strtoupper( mb_substr( $k, 0, 1 ) ) . mb_substr( $k, 1 ) . ': ' . $f['v'], 'auto' ) ) {
					$am['site'][ $k ]['m'] = 1;
				}
			}
			update_option( 'csa_auto_mem', $am, false );
		}
	}

	csa_private_dir();
	if ( ! wp_next_scheduled( 'csa_weekly' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'csa_weekly' );
	}
	update_option( 'csa_db_version', CSA_DB_VERSION );
}

function csa_deactivate() {
	wp_clear_scheduled_hook( 'csa_weekly' );
}

add_action(
	'admin_init',
	function () {
		if ( CSA_DB_VERSION !== get_option( 'csa_db_version' ) ) {
			csa_install();
		}
	}
);
