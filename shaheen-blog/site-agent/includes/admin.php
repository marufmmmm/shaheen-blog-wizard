<?php
/**
 * Admin: menus, settings, screens, notices, admin bar button, meta box, dashboard widget, undo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================== */
/* Settings registration                                               */
/* ================================================================== */

add_action( 'admin_init', 'csa_register_settings' );
function csa_register_settings() {
	register_setting(
		'csa_group',
		CSA_OPT,
		array(
			'sanitize_callback' => function ( $in ) {
				// PIN lock: a save from the Settings form is refused unless the PIN was entered.
				if ( 'csa_group' === ( $_POST['option_page'] ?? '' ) && ! csa_pin_unlocked() ) {
					add_settings_error( CSA_OPT, 'csa_pin', csa_t( 'Settings are locked. Enter the PIN first.' ) );
					return get_option( CSA_OPT );
				}
				$old  = csa_opts();
				$d    = csa_defaults();
				$in   = is_array( $in ) ? $in : array();
				$key  = trim( sanitize_text_field( $in['api_key'] ?? '' ) );
				$roles = function ( $v ) {
					return array_values( array_map( 'sanitize_key', is_array( $v ) ? $v : array() ) );
				};
				$time = sanitize_text_field( $in['slot_time'] ?? '10:00' );
				$keep = function ( $k ) use ( $in, $old ) {
					$v = trim( sanitize_text_field( $in[ $k ] ?? '' ) );
					if ( '-' === $v ) {
						return ''; // "-" clears a saved key.
					}
					return '' === $v ? (string) ( $old[ $k ] ?? '' ) : $v;
				};
				$custom = sanitize_textarea_field( $in['custom_models'] ?? '' );
				$valid  = array_merge( array_keys( csa_builtin_models() ), array_keys( csa_parse_custom_models( $custom ) ) );
				$task   = function ( $t ) use ( $in, $d, $valid ) {
					$v = sanitize_key( $in[ 'm_' . $t ] ?? '' );
					return in_array( $v, $valid, true ) ? $v : $d[ 'm_' . $t ];
				};
				return array(
					'api_key'          => '' === $key ? $old['api_key'] : $key,
					'key_moonshot'     => $keep( 'key_moonshot' ),
					'key_deepseek'     => $keep( 'key_deepseek' ),
					'key_gemini'       => $keep( 'key_gemini' ),
					'key_openrouter'   => $keep( 'key_openrouter' ),
					'key_openai'       => $keep( 'key_openai' ),
					'custom_models'    => $custom,
					'm_chat'           => $task( 'chat' ),
					'm_blog'           => $task( 'blog' ),
					'm_seo'            => $task( 'seo' ),
					'm_bulk'           => $task( 'bulk' ),
					'm_vision'         => $task( 'vision' ),
					'brand'            => sanitize_text_field( $in['brand'] ?? '' ) ? sanitize_text_field( $in['brand'] ) : 'Claude.api',
					'ui_lang'          => ( ( $in['ui_lang'] ?? '' ) === 'bn' ) ? 'bn' : 'en',
					'budget_usd'       => max( 0.5, (float) ( $in['budget_usd'] ?? 5 ) ),
					'allowance_tokens' => max( 100000, (int) ( $in['allowance_tokens'] ?? 12000000 ) ),
					'allow_live'       => empty( $in['allow_live'] ) ? 0 : 1,
					'seo_lang'         => ( ( $in['seo_lang'] ?? '' ) === 'Bangla' ) ? 'Bangla' : 'English',
					'roles_chat'       => $roles( $in['roles_chat'] ?? array() ),
					'roles_seo'        => $roles( $in['roles_seo'] ?? array() ),
					'roles_blog'       => $roles( $in['roles_blog'] ?? array() ),
					'roles_images'     => $roles( $in['roles_images'] ?? array() ),
					'weekly_email'     => empty( $in['weekly_email'] ) ? 0 : 1,
					'email_to'         => sanitize_email( $in['email_to'] ?? '' ),
					'weekly_audit'     => empty( $in['weekly_audit'] ) ? 0 : 1,
					'weekly_pages'     => max( 1, min( 50, (int) ( $in['weekly_pages'] ?? 10 ) ) ),
					'bot_author'       => empty( $in['bot_author'] ) ? 0 : 1,
					'psi_key'          => sanitize_text_field( $in['psi_key'] ?? '' ),
					'history_limit'    => max( 10, min( 1000, (int) ( $in['history_limit'] ?? 200 ) ) ),
					'max_request_tokens' => max( 8000, min( 200000, (int) ( $in['max_request_tokens'] ?? 40000 ) ) ),
					'job_mode'         => ( ( $in['job_mode'] ?? 'one' ) === 'all' ) ? 'all' : 'one',
					'economy'          => empty( $in['economy'] ) ? 0 : 1,
					'max_steps'        => max( 2, min( 60, (int) ( $in['max_steps'] ?? 25 ) ) ),
					'build_steps'      => max( 5, min( 150, (int) ( $in['build_steps'] ?? 60 ) ) ),
					'history_turns'    => max( 1, min( 20, (int) ( $in['history_turns'] ?? 6 ) ) ),
					'font_head'        => array_key_exists( $in['font_head'] ?? '', csa_font_list() ) ? $in['font_head'] : '',
					'font_body'        => array_key_exists( $in['font_body'] ?? '', csa_font_list() ) ? $in['font_body'] : '',
					'font_force'       => empty( $in['font_force'] ) ? 0 : 1,
					'chat_cap_pct'     => max( 1, min( 100, (int) ( $in['chat_cap_pct'] ?? 10 ) ) ),
					'day_cap_pct'      => max( 1, min( 100, (int) ( $in['day_cap_pct'] ?? 20 ) ) ),
					'slot_day'         => in_array( $in['slot_day'] ?? '', array( 'daily', 'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday' ), true ) ? $in['slot_day'] : 'sunday',
					'slot_time'        => preg_match( '/^\d{1,2}:\d{2}$/', $time ) ? $time : '10:00',
				);
			},
		)
	);
	register_setting(
		'csa_mem_group',
		CSA_MEM,
		array(
			'sanitize_callback' => function ( $in ) {
				return array(
					'instructions' => sanitize_textarea_field( $in['instructions'] ?? '' ),
					'notes'        => sanitize_textarea_field( $in['notes'] ?? '' ),
				);
			},
		)
	);
	register_setting(
		'csa_schema_group',
		'csa_schema',
		array(
			'sanitize_callback' => function ( $in ) {
				$out = array();
				foreach ( array( 'type', 'name', 'alt_name', 'logo', 'phone', 'email', 'street', 'city', 'region', 'postcode', 'country', 'founded' ) as $k ) {
					$out[ $k ] = sanitize_text_field( $in[ $k ] ?? '' );
				}
				$out['social']   = sanitize_textarea_field( $in['social'] ?? '' );
				$out['branches'] = sanitize_textarea_field( $in['branches'] ?? '' );
				$out['on']       = empty( $in['on'] ) ? 0 : 1;
				return $out;
			},
		)
	);
}

/* ================================================================== */
/* Menus + assets                                                      */
/* ================================================================== */

function csa_pages() {
	return array(
		'csa-chat'     => array( 'chat', csa_t( 'Chat' ), 'csa_screen_chat' ),
		'csa-blog'     => array( 'blog', csa_t( 'Blog Studio' ), 'csa_screen_blog' ),
		'csa-seo'      => array( 'seo', csa_t( 'SEO' ), 'csa_screen_seo' ),
		'csa-images'   => array( 'images', csa_t( 'Images' ), 'csa_screen_images' ),
		'csa-memory'   => array( 'admin', csa_t( 'Site Memory' ), 'csa_screen_memory' ),
		'csa-activity' => array( 'admin', csa_t( 'Activity' ), 'csa_screen_activity' ),
		'csa-settings' => array( 'admin', csa_t( 'Settings' ), 'csa_screen_settings' ),
	);
}

add_action( 'admin_menu', 'csa_menu' );
/**
 * Three menu groups in the merged plugin: "Blog Write .ai" (blog.php of the host),
 * "SEO" and "Site Agent". Blog Studio and Settings stay reachable by URL but are
 * not listed: Settings lives under Blog Write .ai → Settings (tab), Blog Studio is retired.
 */
function csa_menu() {
	if ( ! csa_can_any() ) {
		return;
	}
	$groups = array(
		'csa-seo'  => array( 'SEO', 'dashicons-chart-area', 27, array( 'csa-seo' ) ),
		'csa-chat' => array( 'Site Agent', 'dashicons-format-chat', 28, array( 'csa-chat', 'csa-images', 'csa-activity', 'csa-memory' ) ),
	);
	$pages  = csa_pages();
	foreach ( $groups as $top => $g ) {
		$done = false;
		foreach ( $g[3] as $slug ) {
			$p  = $pages[ $slug ];
			$ok = 'admin' === $p[0] ? current_user_can( 'manage_options' ) : csa_can( $p[0] );
			if ( ! $ok ) {
				continue;
			}
			if ( ! $done ) {
				add_menu_page( $g[0], $g[0], 'read', $slug, $p[2], $g[1], $g[2] );
				$done = true;
				$top  = $slug;
			}
			add_submenu_page( $top, $p[1] . ' – ' . $g[0], $p[1], 'read', $slug, $p[2] );
		}
	}
	// Hidden but reachable pages.
	foreach ( array( 'csa-blog', 'csa-settings' ) as $slug ) {
		$p  = $pages[ $slug ];
		$ok = 'admin' === $p[0] ? current_user_can( 'manage_options' ) : csa_can( $p[0] );
		if ( $ok ) {
			add_submenu_page( 'options.php', $p[1], $p[1], 'read', $slug, $p[2] );
		}
	}
}

function csa_js_strings() {
	$keys = array( 'Working…', 'Step', 'Retry', 'New chat', 'No saved chats yet.', 'Delete this chat?', 'Rename chat', 'Pin', 'Unpin', 'Approve & build', 'Change plan', 'Cancel', 'Plan', 'Waiting for your approval', 'Type what should change, then send.', 'Plan approved', 'Plan cancelled', 'Uploading image…', 'Image too large or unsupported.', 'Listening… speak now', 'Voice input is not supported in this browser. Use Chrome.', 'Prompt name', 'Saved prompt', 'Delete this saved prompt?', 'What should we work on?', 'Design or fix pages, check SEO, write blog posts, or tell me something to remember about the site. I always show a plan first and build only after you approve.', 'chats', 'Search chats…', 'Preview', 'Phone', 'Tablet', 'Desktop', 'Open', 'Server returned', 'The server timed out. Press Retry, or ask for smaller steps.', 'A security plugin or firewall blocked the request. Whitelist admin-ajax.php for csa_* actions.', 'PHP error on the server. Check the hosting error log.', 'Your login session may have expired. Reload this page.', 'Network error:', 'Analyse', 'Analysing…', 'View report', 'Hide report', 'Apply', 'Saving…', 'Applied ✓', 'Failed', 'Nothing to analyse.', 'Analyse %d page(s)? Each one uses AI allowance.', 'Analysing %1$d of %2$d…', 'Done.', 'error', 'Suggested SEO title', 'Suggested meta description', 'Suggested focus keyword', 'Install Yoast SEO or Rank Math to apply these with one click.', 'Source:', 'Checked', 'Title tag', 'Meta description', 'H1 count', 'Words', 'Images missing alt', 'Internal links', 'Schema blocks', 'Load time', 'Fix with agent', 'With screenshot', 'Testing…', 'Performance', 'Running checks…', 'Collecting links from pages…', 'Checking links…', 'No broken links found.', 'Found on', 'Create plan', 'Planning…', 'Save changes', 'Approve & write', 'Re-plan', 'Writing part %1$d of %2$d…', 'Finishing draft…', 'Edit draft', 'Preview', 'Upload images', 'Schedule', 'Next free slot', 'Scheduled for', 'Delete this item?', 'Add to queue', 'Added to Blog Studio queue.', 'Getting ideas…', 'Title', 'Outline (## heading, - point)', 'SEO title', 'Category', 'Keywords', 'Image slots', 'Internal links', 'FAQ', 'Continue writing', 'new', 'planned', 'approved', 'writing', 'built', 'Uploaded ✓', 'Inserted ✓', 'Inserted %d image(s).', 'Some images have no marker in this page. Use "Insert with agent" for them.', 'Nothing to insert yet. Upload images first.', 'Generate alt', 'Generate all on this page', 'Compress', 'Remove this slot?', 'Undo this change?', 'Undone ✓', 'No images without alt text.', 'No large images found.', 'Save', 'Loading…', 'Getting keywords…', 'Attach images', 'Voice input', 'Save as prompt', 'Send', 'Ask in Bangla or English… (Ctrl+Enter to send)', 'Model', 'Auto', 'Save Changes', 'Continue', 'Nothing collected yet.', 'Clear what the agent collected in this chat? The task stays.', 'Untick steps you want to leave for later. The agent will do the ticked steps one after another without stopping.', 'Left for later', 'not finished', 'Do these now', 'Tick at least one step to build.', 'Plan approved (some steps left for later)', 'Remaining steps approved', 'The agent is working. Type the next job: it will start after this one finishes.', 'Queued: runs after the current job', 'This looks like Bijoy (ANSI / SutonnyMJ) text. Convert it to Unicode Bangla?', 'Converted from Bijoy to Unicode', 'Delete this fact from auto memory?', 'page work', 'blog', 'images', 'chooses by task', 'SEO', 'Blog', 'Images' );
	$out  = array();
	foreach ( $keys as $k ) {
		$out[ $k ] = csa_t( $k );
	}
	return $out;
}

add_action( 'admin_enqueue_scripts', 'csa_assets' );
function csa_assets( $hook ) {
	$page = sanitize_key( $_GET['page'] ?? '' );
	if ( ! isset( csa_pages()[ $page ] ) ) {
		return;
	}
	wp_enqueue_style( 'csa-admin', CSA_URL . 'assets/admin.css', array(), CSA_VER );
	if ( 'csa-blog' === $page && 'format' === sanitize_key( $_GET['tab'] ?? '' ) ) {
		csa_format_enqueue();
	}
	wp_enqueue_script( 'csa-bijoy', CSA_URL . 'assets/bijoy.js', array(), CSA_VER, true );
	wp_enqueue_script( 'csa-admin', CSA_URL . 'assets/admin.js', array( 'csa-bijoy' ), CSA_VER, true );
	wp_localize_script(
		'csa-admin',
		'CSA',
		array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'csa' ),
			'chatUrl'   => admin_url( 'admin.php?page=csa-chat' ),
			'blogUrl'   => admin_url( 'admin.php?page=csa-blog' ),
			'imagesUrl' => admin_url( 'admin.php?page=csa-images' ),
			'seoPlugin' => csa_seo_plugin(),
			'brand'     => csa_brand(),
			'jobMode'   => 'all' === csa_opt( 'job_mode' ) ? 'all' : 'one',
			'uiLang'    => csa_opt( 'ui_lang' ),
			'prompts'   => csa_prompts(),
			'imgMax'    => csa_eco_val( 1024, 1568 ),
			'models'    => array_values(
				array_map(
					function ( $k, $m ) {
						return array( 'key' => $k, 'label' => $m['label'], 'price' => '$' . $m['in'] . '/$' . $m['out'], 'ok' => '' !== csa_provider_key( $m['provider'] ), 'vision' => $m['vision'] );
					},
					array_keys( csa_models() ),
					csa_models()
				)
			),
			'chatModel' => csa_model( csa_task_model_key( 'chat' ) )['label'],
			'kindModels' => array(
				'chat'   => csa_model( csa_task_model_key( 'chat' ) )['label'],
				'seo'    => csa_model( csa_task_model_key( 'seo' ) )['label'],
				'blog'   => csa_model( csa_task_model_key( 'blog' ) )['label'],
				'vision' => csa_model( csa_task_model_key( 'vision' ) )['label'],
			),
			'i18n'      => csa_js_strings(),
		)
	);
}

/* ================================================================== */
/* Notices, admin bar, meta box, dashboard widget                     */
/* ================================================================== */

add_action( 'admin_notices', 'csa_notices' );
function csa_notices() {
	if ( ! csa_can_any() ) {
		return;
	}
	$lvl = csa_alert_level();
	if ( ! $lvl ) {
		return;
	}
	$seen = get_user_meta( get_current_user_id(), 'csa_alert_seen', true );
	$key  = current_time( 'Y-m' ) . ':' . $lvl;
	if ( $seen === $key ) {
		return;
	}
	$cls = $lvl >= 90 ? 'notice-error' : ( $lvl >= 70 ? 'notice-warning' : 'notice-info' );
	echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible csa-alert-notice" data-key="' . esc_attr( $key ) . '"><p><strong>' . esc_html( csa_brand() ) . ':</strong> ' . esc_html( csa_alert_text( $lvl ) ) . '</p></div>';
	?>
	<script>
	document.addEventListener('click',function(e){
		var n=e.target.closest('.csa-alert-notice .notice-dismiss'); if(!n) return;
		var box=n.closest('.csa-alert-notice');
		fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'csa_dismiss',nonce:<?php echo wp_json_encode( wp_create_nonce( 'csa' ) ); ?>,key:box.dataset.key})});
	});
	</script>
	<?php
}

add_action(
	'wp_ajax_csa_dismiss',
	function () {
		check_ajax_referer( 'csa', 'nonce' );
		update_user_meta( get_current_user_id(), 'csa_alert_seen', sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ) );
		wp_send_json_success();
	}
);

add_action( 'admin_bar_menu', 'csa_admin_bar', 90 );
function csa_admin_bar( $bar ) {
	if ( ! csa_can( 'chat' ) ) {
		return;
	}
	$id = 0;
	if ( ! is_admin() && is_singular() ) {
		$id = get_queried_object_id();
	} elseif ( is_admin() && isset( $_GET['post'] ) && 'edit' === ( $_GET['action'] ?? '' ) ) {
		$id = (int) $_GET['post'];
	}
	$url = admin_url( 'admin.php?page=csa-chat' );
	if ( $id && csa_valid_post( $id ) ) {
		$url = add_query_arg( array( 'page_id' => $id, 'prompt' => rawurlencode( sprintf( csa_t( 'About page ID %1$d (%2$s): ' ), $id, get_the_title( $id ) ) ) ), $url );
	}
	$bar->add_node(
		array(
			'id'    => 'csa-ask',
			'title' => '<span class="ab-icon dashicons dashicons-format-chat" style="top:2px"></span>' . esc_html( sprintf( csa_t( 'Ask %s' ), csa_brand() ) ),
			'href'  => $url,
		)
	);
}

add_action( 'add_meta_boxes', 'csa_meta_box' );
function csa_meta_box() {
	if ( ! csa_can( 'chat' ) ) {
		return;
	}
	foreach ( array( 'post', 'page' ) as $pt ) {
		add_meta_box( 'csa-box', csa_brand(), 'csa_meta_box_html', $pt, 'side', 'default' );
	}
}

function csa_meta_box_html( $post ) {
	global $wpdb;
	$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, updated FROM ' . csa_table( 'chats' ) . ' WHERE pages LIKE %s ORDER BY updated DESC LIMIT 5', '%,' . (int) $post->ID . ',%' ), ARRAY_A );
	$seo   = get_post_meta( $post->ID, '_csa_seo', true );
	$slots = array_filter( csa_slots_get( $post->ID ), function ( $s ) { return 'pending' === $s['status']; } );
	$chat  = admin_url( 'admin.php?page=csa-chat' );
	echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'page_id' => $post->ID, 'prompt' => rawurlencode( sprintf( csa_t( 'About page ID %1$d (%2$s): ' ), $post->ID, $post->post_title ) ) ), $chat ) ) . '">' . esc_html( csa_t( 'New chat about this page' ) ) . '</a></p>';
	if ( is_array( $seo ) ) {
		echo '<p>' . esc_html( csa_t( 'SEO score' ) ) . ': <strong>' . (int) $seo['score'] . '</strong> <a href="' . esc_url( admin_url( 'admin.php?page=csa-seo&tab=audit' ) ) . '">' . esc_html( csa_t( 'details' ) ) . '</a></p>';
	}
	if ( $slots ) {
		echo '<p>' . esc_html( sprintf( csa_t( '%d suggested image(s) waiting.' ), count( $slots ) ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=csa-images' ) ) . '">' . esc_html( csa_t( 'Upload' ) ) . '</a></p>';
	}
	if ( $rows ) {
		echo '<p><strong>' . esc_html( csa_t( 'Chats about this page' ) ) . '</strong></p><ul style="margin:0">';
		foreach ( $rows as $r ) {
			echo '<li><a href="' . esc_url( add_query_arg( 'chat', $r['id'], $chat ) ) . '">' . esc_html( $r['title'] ? $r['title'] : '#' . $r['id'] ) . '</a> <span style="color:#8c8f94">' . esc_html( mysql2date( 'j M', $r['updated'] ) ) . '</span></li>';
		}
		echo '</ul>';
	}
}

add_action( 'wp_dashboard_setup', 'csa_dashboard_widget' );
function csa_dashboard_widget() {
	if ( ! csa_can_any() || ! function_exists( 'wp_add_dashboard_widget' ) ) {
		return;
	}
	wp_add_dashboard_widget(
		'csa_widget',
		csa_brand(),
		function () {
			$ov    = csa_seo_overview();
			$lvl   = csa_alert_level();
			$sched = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'future', 'numberposts' => 3, 'orderby' => 'date', 'order' => 'ASC' ) );
			echo '<p><strong>' . esc_html( csa_t( 'SEO score' ) ) . ':</strong> ' . ( $ov['audited'] ? (int) $ov['avg'] . ' / 100' : esc_html( csa_t( 'not checked yet' ) ) ) . ' · ' . esc_html( sprintf( csa_t( '%d pages need attention' ), $ov['bad'] ) ) . '</p>';
			if ( $lvl ) {
				echo '<p>⚠ ' . esc_html( csa_alert_text( $lvl ) ) . '</p>'; // Only after a reminder; no running percentage.
			}
			if ( $sched ) {
				echo '<p><strong>' . esc_html( csa_t( 'Next scheduled posts' ) ) . ':</strong><br>';
				foreach ( $sched as $p ) {
					echo esc_html( get_the_date( 'j M, g:i a', $p ) . ' – ' . $p->post_title ) . '<br>';
				}
				echo '</p>';
			}
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=csa-chat' ) ) . '">' . esc_html( csa_t( 'Open chat' ) ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=csa-seo' ) ) . '">' . esc_html( csa_t( 'SEO' ) ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=csa-blog' ) ) . '">' . esc_html( csa_t( 'Blog Studio' ) ) . '</a></p>';
		}
	);
}

add_action(
	'wp_ajax_csa_undo',
	function () {
		csa_guard( 'admin' );
		$r = csa_undo( sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ) );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( $r->get_error_message() );
		}
		wp_send_json_success();
	}
);

/* ================================================================== */
/* Screen helpers                                                      */
/* ================================================================== */

function csa_header( $title, $tabs = array(), $current = '' ) {
	echo '<div class="wrap csa-wrap"><h1 class="csa-h1"><span class="dashicons dashicons-format-chat"></span> ' . esc_html( csa_brand() . ' · ' . $title ) . '</h1>';
	if ( ! csa_has_any_key() ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html( csa_t( 'Add the API key in Settings first.' ) ) . ( current_user_can( 'manage_options' ) ? ' <a href="' . esc_url( admin_url( 'admin.php?page=csa-settings' ) ) . '">' . esc_html( csa_t( 'Settings' ) ) . '</a>' : '' ) . '</p></div>';
	}
	if ( $tabs ) {
		echo '<nav class="nav-tab-wrapper csa-tabs">';
		foreach ( $tabs as $k => $label ) {
			echo '<a class="nav-tab' . ( $k === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', $k ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}
}

function csa_footer() {
	$sec = array( 'csa-seo' => 'seo', 'csa-blog' => 'blog', 'csa-images' => 'images' );
	$pg  = sanitize_key( $_GET['page'] ?? '' );
	if ( isset( $sec[ $pg ] ) && csa_can( 'chat' ) && ! did_action( 'csa_dock_done' ) ) {
		do_action( 'csa_dock_done' );
		csa_chat_dock( $sec[ $pg ] );
	}
	echo '</div>';
}

function csa_score_badge( $s ) {
	if ( null === $s ) {
		return '<span class="csa-muted">—</span>';
	}
	$s = (int) $s;
	return '<span class="csa-score ' . ( $s >= 80 ? 'good' : ( $s >= 50 ? 'ok' : 'bad' ) ) . '">' . $s . '</span>';
}

function csa_agent_link( $prompt, $label = null, $cls = 'button button-small' ) {
	return '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( add_query_arg( 'prompt', rawurlencode( $prompt ), admin_url( 'admin.php?page=csa-chat' ) ) ) . '">' . esc_html( $label ? $label : csa_t( 'Fix with agent' ) ) . '</a>';
}

/* ================================================================== */
/* Chat                                                                */
/* ================================================================== */

function csa_screen_chat() {
	csa_header( csa_t( 'Chat' ) );
	csa_chat_app_html( '' );
	csa_footer();
}

/** Quick-start chips for the main chat or a section's chat. */
function csa_section_chips( $section ) {
	$all = array(
		'mobile' => array( '📱 ' . csa_t( 'Fix mobile view' ), csa_t( 'Fix the mobile view of this page: ' ) ),
		'seo'    => array( '🔎 ' . csa_t( 'SEO fix' ), csa_t( 'Check the SEO of this page and fix the important issues: ' ) ),
		'images' => array( '🖼 ' . csa_t( 'Suggest images' ), csa_t( 'Which images should this page have? Suggest image slots for: ' ) ),
		'page'   => array( '✨ ' . csa_t( 'New page' ), csa_t( 'Design a new draft page: ' ) ),
		'mem'    => array( '🧠 ' . csa_t( 'Remember…' ), csa_t( 'Remember this about our site: ' ) ),
		'meta'   => array( '🏷 ' . csa_t( 'Title & meta' ), csa_t( 'Write a better SEO title and meta description for: ' ) ),
		'links'  => array( '🔗 ' . csa_t( 'Internal links' ), csa_t( 'Add useful internal links to: ' ) ),
		'schema' => array( '🧩 ' . csa_t( 'Schema / FAQ' ), csa_t( 'Add FAQ schema to: ' ) ),
		'blog'   => array( '✍️ ' . csa_t( 'Write a post' ), csa_t( 'Write a Bangla blog post about: ' ) ),
		'ideas'  => array( '💡 ' . csa_t( 'Post ideas' ), csa_t( 'Suggest 10 blog post ideas for our site about: ' ) ),
		'improve'=> array( '🛠 ' . csa_t( 'Improve a post' ), csa_t( 'Improve this post (readability, headings, FAQ): ' ) ),
		'alt'    => array( '🏷 ' . csa_t( 'Alt text' ), csa_t( 'Write SEO alt text for the images on: ' ) ),
	);
	$sets = array(
		''       => array( 'mobile', 'seo', 'images', 'page', 'mem' ),
		'seo'    => array( 'seo', 'meta', 'links', 'schema' ),
		'blog'   => array( 'blog', 'ideas', 'improve' ),
		'images' => array( 'images', 'alt' ),
	);
	return array_map( function ( $k ) use ( $all ) { return $all[ $k ]; }, $sets[ $section ] ?? $sets[''] );
}

/** The chat app: full page (section '') or the docked chat inside SEO / Blog / Images. */
function csa_chat_app_html( $section ) {
	?>
	<div class="csa-app<?php echo $section ? ' csa-app-dock' : ''; ?>" id="csa-chat-app" data-section="<?php echo esc_attr( $section ); ?>">
		<aside class="csa-side">
			<button type="button" class="button button-primary csa-new">+ <?php echo esc_html( csa_t( 'New chat' ) ); ?></button>
			<input type="search" id="csa-search" class="csa-search" placeholder="<?php echo esc_attr( csa_t( 'Search chats…' ) ); ?>">
			<div id="csa-count" class="csa-count"></div>
			<div id="csa-chats" class="csa-chats"></div>
		</aside>
		<section class="csa-main">
			<div class="csa-chat-head">
				<?php if ( $section ) : ?><button type="button" class="button button-small csa-new" title="<?php echo esc_attr( csa_t( 'New chat' ) ); ?>">+</button><?php endif; ?>
				<strong id="csa-chat-title"><?php echo esc_html( csa_t( 'New chat' ) ); ?></strong>
				<span class="csa-chat-tools">
					<select id="csa-model" class="csa-model-pick" title="<?php echo esc_attr( csa_t( 'Model for this chat' ) ); ?>"></select>
					<button type="button" class="button button-small" id="csa-pin" hidden>📌</button>
					<button type="button" class="button button-small" id="csa-rename" hidden>✏️</button>
					<button type="button" class="button button-small" id="csa-mem-btn" hidden title="<?php echo esc_attr( csa_t( 'What the agent remembers in this chat' ) ); ?>">🧠 <?php echo esc_html( csa_t( 'Memory' ) ); ?></button>
					<button type="button" class="button button-small" id="csa-preview-toggle"<?php echo $section ? ' hidden' : ''; ?>>📱 <?php echo esc_html( csa_t( 'Preview' ) ); ?></button>
					<?php if ( $section ) : ?>
						<a class="button button-small" id="csa-dock-full" href="<?php echo esc_url( admin_url( 'admin.php?page=csa-chat' ) ); ?>" title="<?php echo esc_attr( csa_t( 'Open in the full chat' ) ); ?>">⤢</a>
						<button type="button" class="button button-small" id="csa-dock-close" title="<?php echo esc_attr( csa_t( 'Close' ) ); ?>">✕</button>
					<?php endif; ?>
				</span>
			</div>
			<div id="csa-mem-panel" class="csa-mem-panel" hidden>
				<p class="description"><?php echo esc_html( csa_t( "This chat's memory file. It is sent with every message so the agent doesn't forget, even when old messages are left out to save cost." ) ); ?> <span id="csa-mem-size"></span></p>
				<label><strong><?php echo esc_html( csa_t( 'Task' ) ); ?></strong><input type="text" id="csa-mem-task" class="widefat" maxlength="300"></label>
				<label><strong><?php echo esc_html( csa_t( 'Notes (you and the agent can write here)' ) ); ?></strong><textarea id="csa-mem-notes" class="widefat" rows="4" maxlength="2000"></textarea></label>
				<details><summary><?php echo esc_html( csa_t( 'What the agent has collected' ) ); ?></summary><pre id="csa-mem-sum"></pre></details>
				<p>
					<button type="button" class="button button-primary button-small" id="csa-mem-save"><?php echo esc_html( csa_t( 'Save' ) ); ?></button>
					<button type="button" class="button button-small" id="csa-mem-clear"><?php echo esc_html( csa_t( 'Clear collected memory' ) ); ?></button>
					<button type="button" class="button-link" id="csa-mem-close"><?php echo esc_html( csa_t( 'Close' ) ); ?></button>
				</p>
			</div>
			<div id="csa-log" class="csa-log"></div>
			<div id="csa-attach" class="csa-attach"></div>
			<div class="csa-chips" id="csa-chips">
				<?php foreach ( csa_section_chips( $section ) as $c ) : ?>
					<button type="button" class="csa-chip" data-p="<?php echo esc_attr( $c[1] ); ?>"><?php echo esc_html( $c[0] ); ?></button>
				<?php endforeach; ?>
				<span id="csa-saved-chips"></span>
			</div>
			<form id="csa-form" class="csa-form">
				<div class="csa-input-wrap">
					<textarea id="csa-input" rows="3" placeholder="<?php echo esc_attr( csa_t( 'Ask in Bangla or English… (Ctrl+Enter to send)' ) ); ?>"></textarea>
					<div class="csa-input-bar">
						<label class="csa-icon-btn" title="<?php echo esc_attr( csa_t( 'Attach images' ) ); ?>">📎<input type="file" id="csa-file" accept="image/*" multiple hidden></label>
						<button type="button" class="csa-icon-btn" id="csa-mic" title="<?php echo esc_attr( csa_t( 'Voice input' ) ); ?>">🎤</button>
						<select id="csa-mic-lang" class="csa-mic-lang"><option value="bn-BD">বাংলা</option><option value="en-US">English</option></select>
						<button type="button" class="csa-icon-btn" id="csa-save-prompt" title="<?php echo esc_attr( csa_t( 'Save as prompt' ) ); ?>">⭐</button>
						<span class="csa-spacer"></span>
						<button type="submit" class="button button-primary" id="csa-send"><?php echo esc_html( csa_t( 'Send' ) ); ?></button>
					</div>
				</div>
			</form>
		</section>
		<aside class="csa-preview" id="csa-preview" hidden>
			<div class="csa-preview-bar">
				<input type="url" id="csa-preview-url" value="<?php echo esc_url( home_url( '/' ) ); ?>">
				<button type="button" class="button button-small" data-w="390"><?php echo esc_html( csa_t( 'Phone' ) ); ?></button>
				<button type="button" class="button button-small" data-w="768"><?php echo esc_html( csa_t( 'Tablet' ) ); ?></button>
				<button type="button" class="button button-small" data-w="1280"><?php echo esc_html( csa_t( 'Desktop' ) ); ?></button>
				<a class="button button-small" id="csa-preview-open" target="_blank"><?php echo esc_html( csa_t( 'Open' ) ); ?></a>
			</div>
			<div class="csa-frame-wrap"><iframe id="csa-frame" title="Preview"></iframe></div>
		</aside>
	</div>
	<?php
}

/** Section chat: a button at the bottom right that opens the agent inside this screen. */
function csa_chat_dock( $section ) {
	$names = array( 'seo' => csa_t( 'SEO agent' ), 'blog' => csa_t( 'Blog agent' ), 'images' => csa_t( 'Image agent' ) );
	?>
	<button type="button" class="button button-primary csa-dock-btn" id="csa-dock-btn">💬 <?php echo esc_html( $names[ $section ] ?? csa_t( 'Agent' ) ); ?></button>
	<div class="csa-dock" id="csa-dock" hidden>
		<?php csa_chat_app_html( $section ); ?>
	</div>
	<?php
}

/* ================================================================== */
/* Blog Studio                                                         */
/* ================================================================== */

function csa_screen_blog() {
	$tab = sanitize_key( $_GET['tab'] ?? 'queue' );
	csa_header(
		csa_t( 'Blog Studio' ),
		array(
			'queue'    => csa_t( 'Queue' ),
			'ideas'    => csa_t( 'Ideas' ),
			'calendar' => csa_t( 'Calendar' ),
			'format'   => csa_t( 'Format' ),
		),
		$tab
	);
	$cats = get_categories( array( 'hide_empty' => false ) );
	if ( 'format' === $tab ) {
		csa_format_screen();
		csa_footer();
		return;
	}

	if ( 'queue' === $tab ) {
		?>
		<div class="csa-card">
			<h2><?php echo esc_html( csa_t( 'Add keywords' ) ); ?></h2>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'One per line: keyword | short description of what the post should cover. Nothing is written until you approve its plan.' ) ); ?></p>
			<textarea id="csa-blog-items" rows="4" class="large-text" placeholder="<?php echo esc_attr( csa_t( "ক্যাডেট কলেজ ভর্তি প্রস্তুতি | how to prepare, syllabus, timeline\nadmission 2027 dhaka school | dates, documents, fees overview" ) ); ?>"></textarea>
			<div class="csa-row">
				<label><?php echo esc_html( csa_t( 'Type' ) ); ?> <select id="csa-blog-type"><option value="post"><?php echo esc_html( csa_t( 'Post' ) ); ?></option><option value="page"><?php echo esc_html( csa_t( 'Page' ) ); ?></option></select></label>
				<label><?php echo esc_html( csa_t( 'Language' ) ); ?> <select id="csa-blog-lang"><option>Bangla</option><option>English</option><option>Bangla + English</option></select></label>
				<label><?php echo esc_html( csa_t( 'Length' ) ); ?> <select id="csa-blog-length"><option value="short"><?php echo esc_html( csa_t( 'Short (~600 words)' ) ); ?></option><option value="medium" selected><?php echo esc_html( csa_t( 'Medium (~1200 words)' ) ); ?></option><option value="long"><?php echo esc_html( csa_t( 'Long (~2000 words)' ) ); ?></option></select></label>
				<label><?php echo esc_html( csa_t( 'Tone' ) ); ?> <input type="text" id="csa-blog-tone" value="friendly and informative"></label>
				<label><?php echo esc_html( csa_t( 'Audience' ) ); ?> <input type="text" id="csa-blog-audience" value="parents and students"></label>
				<label><?php echo esc_html( csa_t( 'Category' ) ); ?> <select id="csa-blog-cat"><option value="0">—</option>
				<?php foreach ( $cats as $c ) : ?>
					<option value="<?php echo (int) $c->term_id; ?>"><?php echo esc_html( $c->name ); ?></option>
				<?php endforeach; ?>
				</select></label>
				<button type="button" class="button button-primary" id="csa-blog-add"><?php echo esc_html( csa_t( 'Add to queue' ) ); ?></button>
			</div>
		</div>
		<div id="csa-blog-list" class="csa-blog-list" data-cats="<?php echo esc_attr( wp_json_encode( array_map( function ( $c ) { return array( 'id' => $c->term_id, 'name' => $c->name ); }, $cats ) ) ); ?>"><p class="csa-muted"><?php echo esc_html( csa_t( 'Loading…' ) ); ?></p></div>
		<?php
	} elseif ( 'ideas' === $tab ) {
		?>
		<div class="csa-card">
			<h2><?php echo esc_html( csa_t( 'Blog ideas' ) ); ?></h2>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'Ideas based on your site, the season (admissions, exams, results) and gaps in your content.' ) ); ?></p>
			<div class="csa-row"><input type="text" id="csa-ideas-focus" class="regular-text" placeholder="<?php echo esc_attr( csa_t( 'Optional focus, e.g. cadet coaching, admission' ) ); ?>"> <button type="button" class="button button-primary" id="csa-ideas-go"><?php echo esc_html( csa_t( 'Get ideas' ) ); ?></button></div>
			<div id="csa-ideas"></div>
		</div>
		<?php
	} else {
		$future = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'future', 'numberposts' => 60, 'orderby' => 'date', 'order' => 'ASC' ) );
		global $wpdb;
		$drafts = $wpdb->get_results( 'SELECT id, post_id FROM ' . csa_table( 'jobs' ) . " WHERE status = 'built' ORDER BY updated DESC LIMIT 50", ARRAY_A );
		echo '<div class="csa-card"><h2>' . esc_html( csa_t( 'Scheduled' ) ) . '</h2>';
		if ( ! $future ) {
			echo '<p class="csa-muted">' . esc_html( csa_t( 'Nothing scheduled yet.' ) ) . '</p>';
		}
		$week = '';
		foreach ( $future as $p ) {
			$w = get_the_date( 'o-\WW', $p );
			if ( $w !== $week ) {
				$week = $w;
				echo '<h3 class="csa-week">' . esc_html( sprintf( csa_t( 'Week of %s' ), date_i18n( 'j M Y', strtotime( 'monday this week', get_post_time( 'U', false, $p ) ) ) ) ) . '</h3>';
			}
			echo '<div class="csa-cal-row"><span class="csa-cal-date">' . esc_html( get_the_date( 'D j M, g:i a', $p ) ) . '</span> <a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( $p->post_title ) . '</a></div>';
		}
		echo '<p class="csa-muted">' . esc_html( sprintf( csa_t( 'Next free slot: %s (change the weekly slot in Settings).' ), csa_next_slot() ) ) . '</p></div>';
		echo '<div class="csa-card"><h2>' . esc_html( csa_t( 'Drafts ready to schedule' ) ) . '</h2>';
		$any = false;
		foreach ( (array) $drafts as $d ) {
			if ( 'draft' !== get_post_status( $d['post_id'] ) ) {
				continue;
			}
			$any = true;
			echo '<div class="csa-cal-row"><a href="' . esc_url( get_edit_post_link( $d['post_id'] ) ) . '">' . esc_html( get_the_title( $d['post_id'] ) ) . '</a> <a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=csa-blog' ) ) . '">' . esc_html( csa_t( 'Schedule' ) ) . '</a></div>';
		}
		if ( ! $any ) {
			echo '<p class="csa-muted">' . esc_html( csa_t( 'No unscheduled drafts.' ) ) . '</p>';
		}
		echo '</div>';
	}
	csa_footer();
}

/* ================================================================== */
/* SEO                                                                 */
/* ================================================================== */

function csa_screen_seo() {
	$tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
	csa_header(
		csa_t( 'SEO' ),
		array(
			'dashboard' => csa_t( 'Dashboard' ),
			'audit'     => csa_t( 'Page Audit' ),
			'technical' => csa_t( 'Technical' ),
			'content'   => csa_t( 'Content' ),
			'schema'    => csa_t( 'Schema & Local' ),
			'keywords'  => csa_t( 'Keywords' ),
			'consistency' => csa_t( 'Data check' ),
		),
		$tab
	);
	$fn = 'csa_seo_tab_' . $tab;
	if ( function_exists( $fn ) ) {
		$fn();
	}
	csa_footer();
}

function csa_seo_tab_dashboard() {
	$o     = csa_seo_overview();
	$trend = (array) get_option( 'csa_seo_trend', array() );
	?>
	<div class="csa-stats">
		<div class="csa-stat"><span><?php echo esc_html( csa_t( 'Average SEO score' ) ); ?></span><strong><?php echo $o['audited'] ? csa_score_badge( $o['avg'] ) : '—'; ?></strong></div>
		<div class="csa-stat"><span><?php echo esc_html( csa_t( 'Pages audited' ) ); ?></span><strong><?php echo (int) $o['audited'] . ' / ' . (int) $o['total']; ?></strong></div>
		<div class="csa-stat good"><span><?php echo esc_html( csa_t( 'Good (80+)' ) ); ?></span><strong><?php echo (int) $o['good']; ?></strong></div>
		<div class="csa-stat ok"><span><?php echo esc_html( csa_t( 'Needs work (50–79)' ) ); ?></span><strong><?php echo (int) $o['ok']; ?></strong></div>
		<div class="csa-stat bad"><span><?php echo esc_html( csa_t( 'Poor (under 50)' ) ); ?></span><strong><?php echo (int) $o['bad']; ?></strong></div>
	</div>
	<?php if ( ! $o['audited'] ) : ?>
		<div class="csa-card"><p><?php echo esc_html( csa_t( 'No pages audited yet.' ) ); ?> <a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'tab', 'audit' ) ); ?>"><?php echo esc_html( csa_t( 'Start with Page Audit' ) ); ?></a></p></div>
		<?php
		return;
	endif;
	?>
	<div class="csa-grid2">
		<div class="csa-card">
			<h2><?php echo esc_html( csa_t( 'Most common problems' ) ); ?></h2>
			<?php if ( ! $o['issues'] ) : ?>
				<p class="csa-muted"><?php echo esc_html( csa_t( 'No common problems found.' ) ); ?></p>
			<?php else : ?>
				<?php $max = max( wp_list_pluck( $o['issues'], 'count' ) ); ?>
				<?php foreach ( $o['issues'] as $i ) : ?>
					<div class="csa-bar-row"><span class="csa-bar-label"><?php echo esc_html( $i['label'] ); ?></span><span class="csa-bar"><i style="width:<?php echo (int) round( $i['count'] / $max * 100 ); ?>%"></i></span><span class="csa-bar-val"><?php echo (int) $i['count']; ?></span></div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<div class="csa-card">
			<h2><?php echo esc_html( csa_t( 'Pages that need attention' ) ); ?></h2>
			<table class="widefat striped"><tbody>
			<?php foreach ( $o['worst'] as $w ) : ?>
				<tr><td><?php echo esc_html( $w['title'] ); ?></td><td style="width:60px"><?php echo csa_score_badge( $w['score'] ); ?></td><td style="width:110px"><?php echo csa_agent_link( sprintf( csa_t( 'Check the SEO of page ID %1$d (%2$s) and fix the high-priority issues.' ), $w['id'], $w['title'] ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
	</div>
	<div class="csa-grid2">
		<div class="csa-card">
			<h2><?php echo esc_html( csa_t( 'Score trend' ) ); ?></h2>
			<?php if ( count( $trend ) < 2 ) : ?>
				<p class="csa-muted"><?php echo esc_html( csa_t( 'The trend appears after audits on at least two different days.' ) ); ?></p>
			<?php else : ?>
				<div class="csa-trend" role="img" aria-label="<?php echo esc_attr( csa_t( 'Average SEO score by day' ) ); ?>">
				<?php foreach ( array_slice( $trend, -14, null, true ) as $day => $v ) : ?>
					<div class="csa-trend-col" title="<?php echo esc_attr( $day . ': ' . $v ); ?>"><span class="csa-trend-val"><?php echo (int) $v; ?></span><i style="height:<?php echo (int) $v; ?>%"></i><span class="csa-trend-day"><?php echo esc_html( mysql2date( 'j M', $day ) ); ?></span></div>
				<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="csa-card">
			<h2><?php echo esc_html( csa_t( 'Score drops' ) ); ?></h2>
			<?php if ( ! $o['drops'] ) : ?>
				<p class="csa-muted"><?php echo esc_html( csa_t( 'No pages dropped since their last audit.' ) ); ?></p>
			<?php else : ?>
				<?php foreach ( $o['drops'] as $d ) : ?>
					<p><?php echo esc_html( $d['title'] . ': ' . $d['from'] . ' → ' . $d['to'] ); ?></p>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

function csa_seo_tab_audit() {
	$type  = ( isset( $_GET['type'] ) && 'post' === $_GET['type'] ) ? 'post' : 'page';
	$pages = get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'numberposts' => 300, 'orderby' => 'title', 'order' => 'ASC' ) );
	?>
	<p class="csa-muted"><?php echo esc_html( csa_t( 'Each audit reads the live page and gives a score, problems and fixes. Add a screenshot for design and mobile feedback. SEO plugin:' ) ); ?> <strong><?php echo esc_html( csa_seo_plugin() ? csa_seo_plugin() : csa_t( 'none' ) ); ?></strong></p>
	<div class="csa-row">
		<a class="button <?php echo 'page' === $type ? 'button-primary' : ''; ?>" href="<?php echo esc_url( remove_query_arg( 'type' ) ); ?>"><?php echo esc_html( csa_t( 'Pages' ) ); ?></a>
		<a class="button <?php echo 'post' === $type ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'type', 'post' ) ); ?>"><?php echo esc_html( csa_t( 'Posts' ) ); ?></a>
		<span class="csa-spacer-sm"></span>
		<button type="button" class="button" id="csa-seo-unchecked"><?php echo esc_html( csa_t( 'Analyse unchecked' ) ); ?></button>
		<button type="button" class="button" id="csa-seo-all"><?php echo esc_html( csa_t( 'Re-analyse all' ) ); ?></button>
		<span id="csa-seo-progress" class="csa-muted"></span>
	</div>
	<table class="widefat striped csa-seo-table">
		<thead><tr><th><?php echo esc_html( csa_t( 'Page' ) ); ?></th><th style="width:70px"><?php echo esc_html( csa_t( 'Score' ) ); ?></th><th style="width:110px">PageSpeed</th><th style="width:130px"><?php echo esc_html( csa_t( 'Checked' ) ); ?></th><th style="width:380px"></th></tr></thead>
		<tbody>
		<?php
		foreach ( $pages as $p ) :
			$s   = get_post_meta( $p->ID, '_csa_seo', true );
			$psi = get_post_meta( $p->ID, '_csa_psi', true );
			?>
			<tr class="csa-seo-row" data-id="<?php echo (int) $p->ID; ?>" data-checked="<?php echo is_array( $s ) ? '1' : ''; ?>">
				<td><strong><?php echo esc_html( $p->post_title ? $p->post_title : '(no title)' ); ?></strong><?php echo csa_is_elementor( $p->ID ) ? ' <span class="csa-tag">Elementor</span>' : ''; ?><br><a href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank" class="csa-muted"><?php echo esc_html( wp_make_link_relative( get_permalink( $p ) ) ); ?></a></td>
				<td class="csa-score-cell"><?php echo csa_score_badge( is_array( $s ) ? $s['score'] : null ); ?></td>
				<td class="csa-psi-cell"><?php echo is_array( $psi ) && isset( $psi['mobile'] ) ? '📱 ' . csa_score_badge( $psi['mobile']['score'] ) : ''; ?><?php echo is_array( $psi ) && isset( $psi['desktop'] ) ? ' 🖥 ' . csa_score_badge( $psi['desktop']['score'] ) : ''; ?></td>
				<td class="csa-time-cell csa-muted"><?php echo is_array( $s ) ? esc_html( mysql2date( 'j M, H:i', $s['time'] ) ) : ''; ?></td>
				<td class="csa-actions">
					<button type="button" class="button button-small csa-seo-run"><?php echo esc_html( csa_t( 'Analyse' ) ); ?></button>
					<label class="button button-small"><?php echo esc_html( csa_t( 'With screenshot' ) ); ?><input type="file" accept="image/*" class="csa-seo-shot" hidden></label>
					<button type="button" class="button button-small csa-psi-run" data-s="mobile">📱 PSI</button>
					<button type="button" class="button button-small csa-psi-run" data-s="desktop">🖥 PSI</button>
					<button type="button" class="button button-small csa-seo-view" <?php disabled( ! is_array( $s ) ); ?>><?php echo esc_html( csa_t( 'View report' ) ); ?></button>
				</td>
			</tr>
			<tr class="csa-seo-detail" data-for="<?php echo (int) $p->ID; ?>" hidden><td colspan="5"></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function csa_seo_tab_technical() {
	$last = get_option( 'csa_links_last' );
	?>
	<div class="csa-card">
		<h2><?php echo esc_html( csa_t( 'Site checks' ) ); ?></h2>
		<p><button type="button" class="button button-primary" id="csa-tech-run"><?php echo esc_html( csa_t( 'Run checks' ) ); ?></button></p>
		<div id="csa-tech"></div>
	</div>
	<div class="csa-card">
		<h2><?php echo esc_html( csa_t( 'Broken links' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'Scans every published page and post and checks each link. Free (no AI).' ) ); ?></p>
		<p><button type="button" class="button button-primary" id="csa-links-run"><?php echo esc_html( csa_t( 'Scan for broken links' ) ); ?></button> <span id="csa-links-progress" class="csa-muted"></span></p>
		<div id="csa-links">
		<?php if ( is_array( $last ) ) : ?>
			<p class="csa-muted"><?php echo esc_html( sprintf( csa_t( 'Last scan %1$s: %2$d links checked, %3$d broken.' ), mysql2date( 'j M, H:i', $last['time'] ), $last['checked'], count( $last['broken'] ) ) ); ?></p>
			<?php csa_links_table( $last['broken'] ); ?>
		<?php endif; ?>
		</div>
	</div>
	<?php
}

function csa_links_table( $rows ) {
	if ( ! $rows ) {
		echo '<p>' . esc_html( csa_t( 'No broken links found.' ) ) . '</p>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>URL</th><th style="width:90px">' . esc_html( csa_t( 'Status' ) ) . '</th><th>' . esc_html( csa_t( 'Found on' ) ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$pages = array();
		foreach ( $r['pages'] as $p ) {
			$pages[] = '<a href="' . esc_url( $p['edit'] ) . '">' . esc_html( $p['title'] ) . '</a>';
		}
		echo '<tr><td class="csa-break">' . esc_html( $r['url'] ) . '</td><td>' . esc_html( $r['code'] ) . '</td><td>' . implode( ', ', $pages ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

function csa_seo_tab_content() {
	$o    = csa_seo_overview();
	$list = function ( $title, $rows, $render, $empty ) {
		echo '<div class="csa-card"><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="csa-muted">' . esc_html( $empty ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>' . $render( $r ) . '</tr>'; // phpcs:ignore -- escaped in callbacks.
		}
		echo '</tbody></table></div>';
	};
	$none = csa_t( 'None found in audited pages.' );
	echo '<p class="csa-muted">' . esc_html( csa_t( 'Based on the latest page audits. Audit more pages for a fuller picture.' ) ) . '</p>';
	$list(
		csa_t( 'Duplicate titles' ),
		$o['dup_t'],
		function ( $r ) {
			return '<td>' . esc_html( $r['text'] ) . '</td><td>' . esc_html( implode( ', ', array_map( 'get_the_title', $r['ids'] ) ) ) . '</td><td style="width:120px">' . csa_agent_link( sprintf( csa_t( 'These pages have the same title tag: %s. Propose unique SEO titles for each.' ), implode( ', ', array_map( function ( $i ) { return '#' . $i; }, $r['ids'] ) ) ) ) . '</td>';
		},
		$none
	);
	$list(
		csa_t( 'Duplicate meta descriptions' ),
		$o['dup_d'],
		function ( $r ) {
			return '<td>' . esc_html( $r['text'] ) . '</td><td>' . esc_html( implode( ', ', array_map( 'get_the_title', $r['ids'] ) ) ) . '</td><td style="width:120px">' . csa_agent_link( sprintf( csa_t( 'These pages share one meta description: %s. Propose unique descriptions.' ), implode( ', ', array_map( function ( $i ) { return '#' . $i; }, $r['ids'] ) ) ) ) . '</td>';
		},
		$none
	);
	$list(
		csa_t( 'Thin content (under 300 words)' ),
		$o['thin'],
		function ( $r ) {
			return '<td>' . esc_html( $r['title'] ) . '</td><td style="width:90px">' . (int) $r['words'] . ' ' . esc_html( csa_t( 'words' ) ) . '</td><td style="width:120px">' . csa_agent_link( sprintf( csa_t( 'Page ID %1$d (%2$s) has thin content. Plan how to expand it with useful sections for its audience.' ), $r['id'], $r['title'] ) ) . '</td>';
		},
		$none
	);
	$list(
		csa_t( 'Pages hidden from Google (noindex)' ),
		$o['noindex'],
		function ( $r ) {
			return '<td>' . esc_html( $r['title'] ) . '</td><td style="width:120px"><a class="button button-small" href="' . esc_url( get_edit_post_link( $r['id'] ) ) . '">' . esc_html( csa_t( 'Edit' ) ) . '</a></td>';
		},
		$none
	);
	$list(
		csa_t( 'Slow pages (over 2.5s)' ),
		$o['slow'],
		function ( $r ) {
			return '<td>' . esc_html( $r['title'] ) . '</td><td style="width:90px">' . (int) $r['ms'] . ' ms</td><td style="width:120px"><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=csa-seo&tab=audit' ) ) . '">PageSpeed</a></td>';
		},
		$none
	);
	echo '<div class="csa-card"><h2>' . esc_html( csa_t( 'Internal linking' ) ) . '</h2><p class="csa-muted">' . esc_html( csa_t( 'Internal links help Google understand your site and keep visitors reading.' ) ) . '</p>' . csa_agent_link( csa_t( 'Review my main pages and propose internal links between related pages (which page, which anchor text, where).' ), csa_t( 'Suggest internal links' ), 'button' ) . '</div>';
}

function csa_seo_tab_schema() {
	$s    = csa_schema_opts();
	$done = (array) get_option( 'csa_local_done', array() );
	?>
	<div class="csa-grid2">
		<div class="csa-card">
			<h2><?php echo esc_html( csa_t( 'Organization schema' ) ); ?></h2>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'Helps Google show your name, logo, contacts and branches. Added to the home page automatically. FAQ schema is added to Blog Studio posts automatically.' ) ); ?></p>
			<form method="post" action="options.php" class="csa-form-grid">
				<?php settings_fields( 'csa_schema_group' ); ?>
				<label class="csa-check"><input type="checkbox" name="csa_schema[on]" value="1" <?php checked( $s['on'], 1 ); ?>> <?php echo esc_html( csa_t( 'Enable schema on the home page' ) ); ?></label>
				<label><?php echo esc_html( csa_t( 'Type' ) ); ?><select name="csa_schema[type]">
					<?php foreach ( array( 'EducationalOrganization', 'School', 'HighSchool', 'MiddleSchool', 'ElementarySchool', 'CollegeOrUniversity', 'Organization' ) as $t ) : ?>
						<option <?php selected( $s['type'], $t ); ?>><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select></label>
				<?php
				$fields = array(
					'name'     => csa_t( 'Name' ),
					'alt_name' => csa_t( 'Other name (e.g. Bangla name)' ),
					'logo'     => csa_t( 'Logo URL' ),
					'phone'    => csa_t( 'Phone' ),
					'email'    => csa_t( 'Email' ),
					'street'   => csa_t( 'Street address' ),
					'city'     => csa_t( 'City' ),
					'region'   => csa_t( 'Division' ),
					'postcode' => csa_t( 'Postcode' ),
					'country'  => csa_t( 'Country code' ),
					'founded'  => csa_t( 'Founded (year)' ),
				);
				foreach ( $fields as $k => $label ) {
					echo '<label>' . esc_html( $label ) . '<input type="text" name="csa_schema[' . esc_attr( $k ) . ']" value="' . esc_attr( $s[ $k ] ) . '"></label>';
				}
				?>
				<label class="csa-full"><?php echo esc_html( csa_t( 'Social profile URLs (one per line)' ) ); ?><textarea name="csa_schema[social]" rows="3"><?php echo esc_textarea( $s['social'] ); ?></textarea></label>
				<label class="csa-full"><?php echo esc_html( csa_t( 'Branches (one per line: Name | Address | Phone)' ) ); ?><textarea name="csa_schema[branches]" rows="4"><?php echo esc_textarea( $s['branches'] ); ?></textarea></label>
				<div class="csa-full"><?php submit_button( csa_t( 'Save schema' ), 'primary', 'submit', false ); ?></div>
			</form>
			<details><summary><?php echo esc_html( csa_t( 'Preview JSON-LD' ) ); ?></summary><pre class="csa-pre"><?php echo esc_html( wp_json_encode( csa_schema_json(), CSA_JSON | JSON_PRETTY_PRINT ) ); ?></pre></details>
		</div>
		<div class="csa-card">
			<h2><?php echo esc_html( csa_t( 'Local SEO checklist' ) ); ?></h2>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'For each branch to appear in Google Maps and "near me" searches.' ) ); ?></p>
			<?php foreach ( csa_local_items() as $k => $label ) : ?>
				<label class="csa-check csa-local"><input type="checkbox" data-key="<?php echo esc_attr( $k ); ?>" <?php checked( ! empty( $done[ $k ] ) ); ?>> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

function csa_seo_tab_keywords() {
	?>
	<div class="csa-card">
		<h2><?php echo esc_html( csa_t( 'Keyword ideas' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'AI suggestions of what people in Bangladesh search for. Difficulty is an estimate; there is no search-volume data.' ) ); ?></p>
		<div class="csa-row">
			<input type="text" id="csa-kw-topic" class="regular-text" placeholder="<?php echo esc_attr( csa_t( 'Topic, e.g. cadet coaching, school admission Dhaka' ) ); ?>">
			<select id="csa-kw-lang"><option>Bangla and English</option><option>Bangla</option><option>English</option></select>
			<button type="button" class="button button-primary" id="csa-kw-go"><?php echo esc_html( csa_t( 'Get keywords' ) ); ?></button>
		</div>
		<div id="csa-kw"></div>
	</div>
	<?php
}

/* ================================================================== */
/* Images                                                              */
/* ================================================================== */

function csa_screen_images() {
	$tab = sanitize_key( $_GET['tab'] ?? 'slots' );
	csa_header(
		csa_t( 'Images' ),
		array(
			'slots' => csa_t( 'Suggested images' ),
			'alt'   => csa_t( 'Missing alt text' ),
			'big'   => csa_t( 'Large images' ),
		),
		$tab
	);
	if ( 'slots' === $tab ) {
		$posts = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => array( 'publish', 'draft', 'future', 'pending', 'private' ), 'numberposts' => 100, 'meta_key' => '_csa_image_slots', 'orderby' => 'modified' ) );
		echo '<p class="csa-muted">' . esc_html( csa_t( 'The agent suggests where images are needed. Upload your photo for each slot: it is renamed for SEO, compressed and given alt text and a caption automatically.' ) ) . '</p>';
		$shown = 0;
		foreach ( $posts as $p ) {
			$slots = csa_slots_get( $p->ID );
			if ( ! $slots ) {
				continue;
			}
			$shown++;
			echo '<div class="csa-card csa-slot-post" data-post="' . (int) $p->ID . '"><div class="csa-slot-head"><h2>' . esc_html( $p->post_title ) . ' <span class="csa-tag">' . esc_html( get_post_status( $p ) ) . '</span></h2><span>';
			echo '<button type="button" class="button button-primary csa-slot-insert">' . esc_html( csa_t( 'Insert uploaded images' ) ) . '</button> ';
			echo csa_agent_link( sprintf( csa_t( 'Insert the uploaded images into page ID %1$d (%2$s) in the right places, using get_image_slots.' ), $p->ID, $p->post_title ), csa_t( 'Insert with agent' ), 'button' );
			echo ' <a class="button" href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( csa_t( 'Edit' ) ) . '</a></span></div><div class="csa-slots">';
			foreach ( $slots as $s ) {
				$thumb = $s['attachment'] ? wp_get_attachment_image_url( $s['attachment'], 'thumbnail' ) : '';
				echo '<div class="csa-slot" data-slot="' . esc_attr( $s['slot'] ) . '">';
				echo '<div class="csa-slot-img">' . ( $thumb ? '<img src="' . esc_url( $thumb ) . '" alt="">' : '<span class="dashicons dashicons-format-image"></span>' ) . '</div>';
				echo '<div class="csa-slot-body"><strong>' . esc_html( $s['slot'] ) . '</strong> <span class="csa-tag csa-st-' . esc_attr( $s['status'] ) . '">' . esc_html( csa_t( $s['status'] ) ) . '</span>';
				echo '<p>' . esc_html( $s['description'] ) . '</p>';
				echo '<p class="csa-muted">' . esc_html( csa_t( 'Where:' ) . ' ' . $s['position'] . ' · ' . csa_t( 'Size:' ) . ' ' . $s['size'] ) . '<br>' . esc_html( csa_t( 'Alt:' ) . ' ' . $s['alt'] ) . '<br>' . esc_html( csa_t( 'File:' ) . ' ' . $s['filename'] ) . '</p>';
				echo '<label class="button button-small">' . esc_html( $s['attachment'] ? csa_t( 'Replace' ) : csa_t( 'Upload' ) ) . '<input type="file" accept="image/jpeg,image/png,image/webp" class="csa-slot-file" hidden></label> ';
				echo '<button type="button" class="button button-small csa-slot-remove">×</button></div></div>';
			}
			echo '</div></div>';
		}
		if ( ! $shown ) {
			echo '<div class="csa-card"><p>' . esc_html( csa_t( 'No suggested images yet. Ask in chat: "Which images should page X have?" or create a post in Blog Studio.' ) ) . '</p></div>';
		}
	} elseif ( 'alt' === $tab ) {
		echo '<div class="csa-card"><p class="csa-muted">' . esc_html( csa_t( 'Images without alt text hurt SEO and accessibility. The AI looks at each image and writes alt text; you can edit it.' ) ) . '</p><p><button type="button" class="button button-primary" id="csa-alt-all">' . esc_html( csa_t( 'Generate all on this page' ) ) . '</button> <span id="csa-alt-progress" class="csa-muted"></span></p><div id="csa-alt-list" class="csa-media-grid"></div><div id="csa-alt-pager" class="csa-row"></div></div>';
	} else {
		echo '<div class="csa-card"><p class="csa-muted">' . esc_html( csa_t( 'Images over 300 KB slow pages down. Compress resizes to max 1920px wide and re-saves at good quality.' ) ) . '</p><div id="csa-big-list"></div></div>';
	}
	csa_footer();
}

/* ================================================================== */
/* Memory, Activity, Settings                                          */
/* ================================================================== */

function csa_screen_memory() {
	$m = csa_memory();
	csa_header( csa_t( 'Site Memory' ) );
	?>
	<form method="post" action="options.php" class="csa-card csa-memory">
		<?php settings_fields( 'csa_mem_group' ); ?>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'Everything here is given to the agent in every chat, SEO audit and blog post.' ) ); ?></p>
		<h2><?php echo esc_html( csa_t( 'Site instructions (written by you)' ) ); ?></h2>
		<textarea name="<?php echo esc_attr( CSA_MEM ); ?>[instructions]" rows="12" placeholder="<?php echo esc_attr( csa_t( "Example:\n- Brand colours: navy #0B2A5B and gold #F2B705\n- Official phone numbers: ... (never change them)\n- Use Bangla on Bangla pages, English page titles for SEO\n- Never edit the Home page without asking" ) ); ?>"><?php echo esc_textarea( $m['instructions'] ); ?></textarea>
		<h2><?php echo esc_html( csa_t( 'Agent notes (added when you say "remember…" in chat; editable)' ) ); ?></h2>
		<textarea name="<?php echo esc_attr( CSA_MEM ); ?>[notes]" rows="8"><?php echo esc_textarea( $m['notes'] ); ?></textarea>
		<?php submit_button( csa_t( 'Save Site Memory' ) ); ?>
	</form>
	<?php
	csa_si_card();
	csa_am_card();
	csa_footer();
}

function csa_screen_activity() {
	$log   = (array) get_option( CSA_LOG, array() );
	$usage = (array) get_option( CSA_USAGE, array() );
	$lvl   = csa_alert_level();
	$b     = max( 0.01, (float) csa_opt( 'budget_usd' ) );
	csa_header( csa_t( 'Activity' ) );
	?>
	<div class="csa-card">
		<h2><?php echo esc_html( csa_t( 'AI allowance' ) ); ?></h2>
		<?php $pct = min( 100, csa_usage_percent() ); ?>
		<div class="csa-meter"><i style="width:<?php echo esc_attr( $pct ); ?>%" class="<?php echo $pct >= 90 ? 'bad' : ( $pct >= 70 ? 'ok' : 'good' ); ?>"></i></div>
		<p><strong><?php echo esc_html( sprintf( csa_t( '%s%% of this month\'s allowance used' ), number_format_i18n( $pct, $pct < 10 ? 1 : 0 ) ) ); ?></strong></p>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'You get a message at 15%, 40%, 70% and 90%. At 100% the agent pauses until the 1st of next month.' ) ); ?></p>
		<?php if ( $usage ) : ?>
			<table class="widefat striped" style="max-width:420px"><thead><tr><th><?php echo esc_html( csa_t( 'Month' ) ); ?></th><th><?php echo esc_html( csa_t( 'Requests' ) ); ?></th><th><?php echo esc_html( csa_t( 'Allowance used' ) ); ?></th></tr></thead><tbody>
			<?php foreach ( $usage as $m => $u ) : ?>
				<tr><td><?php echo esc_html( $m ); ?></td><td><?php echo (int) $u['calls']; ?></td><td><?php echo esc_html( min( 100, round( (float) ( $u['cost'] ?? 0 ) / $b * 100 ) ) . '%' ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
	</div>
	<?php $reqs = (array) get_option( 'csa_reqlog', array() ); ?>
	<div class="csa-card">
		<h2><?php echo esc_html( csa_t( 'Recent AI requests' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'Every AI request and how much of the monthly allowance it used.' ) ); ?></p>
		<?php if ( ! $reqs ) : ?>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'Nothing yet.' ) ); ?></p>
		<?php else : ?>
		<table class="widefat striped"><thead><tr><th style="width:150px"><?php echo esc_html( csa_t( 'Time' ) ); ?></th><th><?php echo esc_html( csa_t( 'Feature' ) ); ?></th><th><?php echo esc_html( csa_t( 'Model' ) ); ?></th><th><?php echo esc_html( csa_t( 'Allowance used' ) ); ?></th></tr></thead><tbody>
		<?php foreach ( array_slice( $reqs, 0, 50 ) as $q ) : $pc = (float) $q['cost'] / $b * 100; ?>
			<tr<?php echo $pc >= 5 ? ' style="background:#fcf0f1"' : ''; ?>><td><?php echo esc_html( mysql2date( 'j M, H:i:s', $q['t'] ) ); ?></td><td><?php echo esc_html( $q['f'] ? $q['f'] : '—' ); ?></td><td><?php echo esc_html( $q['m'] ); ?></td><td><strong><?php echo esc_html( number_format_i18n( $pc, $pc < 1 ? 2 : 1 ) . '%' ); ?></strong></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php endif; ?>
	</div>
	<div class="csa-card">
		<h2><?php echo esc_html( csa_t( 'Recent changes' ) ); ?></h2>
		<table class="widefat striped csa-log-table">
			<thead><tr><th style="width:150px"><?php echo esc_html( csa_t( 'Time' ) ); ?></th><th style="width:130px"><?php echo esc_html( csa_t( 'User' ) ); ?></th><th><?php echo esc_html( csa_t( 'Action' ) ); ?></th><th style="width:90px"></th></tr></thead>
			<tbody>
			<?php if ( ! $log ) : ?>
				<tr><td colspan="4" class="csa-muted"><?php echo esc_html( csa_t( 'Nothing yet.' ) ); ?></td></tr>
			<?php else : foreach ( array_slice( $log, 0, 200 ) as $e ) : ?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'j M Y, H:i', $e['t'] ) ); ?></td>
					<td><?php echo esc_html( $e['u'] ); ?></td>
					<td><?php echo esc_html( $e['a'] ); ?></td>
					<td><?php if ( ! empty( $e['undo'] ) && empty( $e['done'] ) ) : ?><button type="button" class="button button-small csa-undo" data-id="<?php echo esc_attr( $e['id'] ); ?>">↶ <?php echo esc_html( csa_t( 'Undo' ) ); ?></button><?php elseif ( ! empty( $e['done'] ) ) : ?><span class="csa-muted"><?php echo esc_html( csa_t( 'undone' ) ); ?></span><?php endif; ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>
	<?php
	csa_footer();
}

function csa_stars( $n ) {
	return $n ? str_repeat( '★', $n ) . str_repeat( '☆', 5 - $n ) : '—';
}

/** Settings: providers, model per task, presets and a comparison guide. */
function csa_models_settings_html( $o, $n ) {
	$models = csa_models();
	?>
	<div class="csa-card csa-models">
		<h2><?php echo esc_html( csa_t( 'AI models & providers' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'Pick a different model for each kind of work. Cheaper models cost far less; the best models give better design and Bangla. You only need keys for the providers you use.' ) ); ?></p>

		<h3><?php echo esc_html( csa_t( '1. API keys' ) ); ?></h3>
		<table class="form-table">
		<?php foreach ( csa_providers() as $pk => $p ) : $v = (string) ( $o[ $p['opt'] ] ?? '' ); ?>
			<tr><th><?php echo esc_html( $p['label'] ); ?></th><td>
				<input type="password" class="regular-text" name="<?php echo esc_attr( $n . '[' . $p['opt'] . ']' ); ?>" autocomplete="off" placeholder="<?php echo $v ? esc_attr( csa_t( 'Saved (ends' ) . ' ' . substr( $v, -4 ) . ') – ' . csa_t( 'leave blank to keep' ) ) : esc_attr( csa_t( 'not set' ) ); ?>">
				<?php echo $v ? '<span class="csa-key-ok">✓ ' . esc_html( csa_t( 'connected' ) ) . '</span>' : ''; ?>
				<a href="<?php echo esc_url( $p['signup'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( csa_t( 'Get a key' ) ); ?></a>
			</td></tr>
		<?php endforeach; ?>
		</table>
		<p class="description"><?php echo esc_html( csa_t( 'Type "-" in a key box to remove a saved key. Set a monthly spend limit on each provider\'s website too.' ) ); ?></p>

		<h3><?php echo esc_html( csa_t( '2. Which model for which work' ) ); ?></h3>
		<p class="csa-presets"><?php echo esc_html( csa_t( 'Quick setup:' ) ); ?>
		<?php foreach ( csa_presets() as $pk => $p ) : ?>
			<button type="button" class="button csa-preset" data-preset="<?php echo esc_attr( wp_json_encode( $p ) ); ?>"><?php echo esc_html( $p['label'] ); ?></button>
		<?php endforeach; ?>
		</p>
		<div class="csa-smart-note">
			<strong>⭐ <?php echo esc_html( csa_t( 'Smart mix (recommended)' ) ); ?>:</strong>
			<?php echo esc_html( csa_t( 'page work with Kimi K2.6, Bangla blogs with ChatGPT (GPT-6 Sol), SEO audits and alt text with Claude Haiku, bulk work with GPT-6 Luna. Only 3 keys. About $7–8 a month for 30 page jobs, 20 blog posts, 40 SEO audits and 100 alt texts.' ) ); ?>
			<br><?php echo esc_html( csa_t( 'Keys needed: Anthropic (Claude), Moonshot (Kimi), OpenAI (ChatGPT). Leave the other key boxes empty.' ) ); ?>
			<br>💡 <?php echo esc_html( csa_t( 'For a hard page, pick Claude Sonnet in the model box at the top of that chat. Set it back to Auto afterwards.' ) ); ?>
		</div>
		<table class="form-table">
		<?php foreach ( csa_tasks() as $tk => $t ) : $cur = csa_task_model_key( $tk ); ?>
			<tr><th><?php echo esc_html( $t['label'] ); ?></th><td><select name="<?php echo esc_attr( $n . '[m_' . $tk . ']' ); ?>" class="csa-task-select" data-task="<?php echo esc_attr( $tk ); ?>">
			<?php foreach ( $models as $mk => $m ) : $has = '' !== csa_provider_key( $m['provider'] ); ?>
				<option value="<?php echo esc_attr( $mk ); ?>" <?php selected( $cur, $mk ); ?>><?php echo esc_html( $m['label'] . ' — $' . $m['in'] . ' / $' . $m['out'] . ( $has ? '' : ' (' . csa_t( 'no key yet' ) . ')' ) ); ?></option>
			<?php endforeach; ?>
			</select></td></tr>
		<?php endforeach; ?>
		</table>

		<h3><?php echo esc_html( csa_t( '3. Guide: why choose which model' ) ); ?></h3>
		<div class="csa-table-wrap"><table class="widefat striped csa-guide">
			<thead><tr><th><?php echo esc_html( csa_t( 'Model' ) ); ?></th><th><?php echo esc_html( csa_t( 'Price per 1M tokens (read / write)' ) ); ?></th><th><?php echo esc_html( csa_t( 'Cost vs Claude Sonnet' ) ); ?></th><th><?php echo esc_html( csa_t( 'Page design & code' ) ); ?></th><th><?php echo esc_html( csa_t( 'Bangla writing' ) ); ?></th><th><?php echo esc_html( csa_t( 'Sees images' ) ); ?></th><th><?php echo esc_html( csa_t( 'Best for' ) ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $models as $mk => $m ) : $r = csa_model_ratio( $m ); ?>
				<tr>
					<td><strong><?php echo esc_html( $m['label'] ); ?></strong><br><span class="csa-muted"><?php echo esc_html( csa_providers()[ $m['provider'] ]['label'] ); ?></span>
					<?php if ( 'sonnet' === $mk ) : ?><br><span class="csa-badge gold">⭐ <?php echo esc_html( csa_t( 'Best quality' ) ); ?></span><?php endif; ?>
					<?php if ( $r >= 5 ) : ?><br><span class="csa-badge green">💰 <?php echo esc_html( csa_t( 'Very cheap' ) ); ?></span><?php endif; ?>
					<?php if ( 'premium' === ( $m['tier'] ?? '' ) ) : ?><br><span class="csa-badge red">💸 <?php echo esc_html( csa_t( 'Expensive: not recommended' ) ); ?></span><?php endif; ?>
					<?php if ( 'haiku' === $mk ) : ?><br><span class="csa-badge blue">✔ <?php echo esc_html( csa_t( 'Default' ) ); ?></span><?php endif; ?>
					</td>
					<td>$<?php echo esc_html( $m['in'] . ' / $' . $m['out'] ); ?></td>
					<td><?php echo 'sonnet' === $mk ? '—' : ( $r >= 1 ? esc_html( sprintf( csa_t( '~%s× cheaper' ), number_format_i18n( $r, $r < 10 ? 1 : 0 ) ) ) : esc_html( sprintf( csa_t( '~%s× more expensive' ), number_format_i18n( 1 / $r, 1 ) ) ) ); ?></td>
					<td class="csa-stars"><?php echo esc_html( csa_stars( $m['code'] ) ); ?></td>
					<td class="csa-stars"><?php echo esc_html( csa_stars( $m['bn'] ) ); ?></td>
					<td><?php echo $m['vision'] ? '✅' : '❌'; ?></td>
					<td><?php echo esc_html( csa_t( $m['best'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<ul class="csa-tips">
			<li><?php echo esc_html( csa_t( 'Best quality: use Claude Sonnet for important pages and complex Elementor designs.' ) ); ?></li>
			<li><?php echo esc_html( csa_t( 'Save money: Kimi K2.6 or DeepSeek V4 Pro for everyday page fixes, DeepSeek Flash or Gemini Flash-Lite for bulk work.' ) ); ?></li>
			<li><?php echo esc_html( csa_t( 'Screenshots and alt text need a model that sees images (✅). If the chosen model cannot, the Images model is used automatically.' ) ); ?></li>
			<li><?php echo esc_html( csa_t( 'Kimi (Moonshot) and DeepSeek are Chinese companies; your page content is processed on their servers. Do not send private student or staff data.' ) ); ?></li>
			<li><?php echo esc_html( csa_t( 'DeepSeek prices shown are peak rates; outside peak hours they are half.' ) ); ?></li>
			<li><?php echo esc_html( csa_t( 'Ratings are our assessment. Try a cheaper model on one page first and compare.' ) ); ?></li>
		</ul>

		<details><summary><?php echo esc_html( csa_t( 'Add another model (advanced)' ) ); ?></summary>
			<p class="description"><?php echo esc_html( csa_t( 'One per line: key | provider | model-id | input price | output price | images yes/no | name. Providers: anthropic, moonshot, deepseek, gemini, openai, openrouter. Example:' ) ); ?><br><code>qwen | openrouter | qwen/qwen3-coder | 0.3 | 1.2 | no | Qwen3 Coder</code></p>
			<textarea name="<?php echo esc_attr( $n ); ?>[custom_models]" rows="3" class="large-text code"><?php echo esc_textarea( $o['custom_models'] ); ?></textarea>
		</details>
	</div>
	<?php
}

function csa_screen_settings() {
	$o     = csa_opts();
	$n     = CSA_OPT;
	$roles = wp_roles()->get_names();
	csa_header( csa_t( 'Settings' ) );
	echo '<nav class="nav-tab-wrapper csa-tabs"><a class="nav-tab" href="' . esc_url( admin_url( 'admin.php?page=sb-settings' ) ) . '">Blog Write .ai</a><a class="nav-tab nav-tab-active" href="' . esc_url( admin_url( 'admin.php?page=csa-settings' ) ) . '">Site Agent &amp; SEO</a></nav>';
	if ( ! csa_pin_unlocked() ) {
		csa_pin_lock_screen();
		csa_footer();
		return;
	}
	csa_pin_notice();
	csa_pin_card();
	$role_boxes = function ( $key ) use ( $o, $n, $roles ) {
		foreach ( $roles as $slug => $label ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			echo '<label class="csa-inline"><input type="checkbox" name="' . esc_attr( $n . '[' . $key . '][]' ) . '" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, (array) $o[ $key ], true ), true, false ) . '> ' . esc_html( translate_user_role( $label ) ) . '</label>';
		}
	};
	?>
	<form method="post" action="options.php">
		<?php settings_fields( 'csa_group' ); ?>
		<?php csa_models_settings_html( $o, $n ); ?>
		<?php csa_fonts_card( $o, $n ); ?>

		<div class="csa-card">
			<h2>🎯 <?php echo esc_html( csa_t( 'How the agent works through a plan' ) ); ?></h2>
			<label class="csa-check"><input type="radio" name="<?php echo esc_attr( $n ); ?>[job_mode]" value="one" <?php checked( $o['job_mode'] ?? 'one', 'one' ); ?>> <strong><?php echo esc_html( csa_t( 'One job at a time (recommended)' ) ); ?></strong></label>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'The agent finishes ONE step completely, reports it, and then asks you whether to do the next step.' ) ); ?></p>
			<label class="csa-check"><input type="radio" name="<?php echo esc_attr( $n ); ?>[job_mode]" value="all" <?php checked( $o['job_mode'] ?? 'one', 'all' ); ?>> <strong><?php echo esc_html( csa_t( 'All approved steps in one go' ) ); ?></strong></label>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'The agent works through every ticked step without stopping.' ) ); ?></p>
		</div>

		<div class="csa-card csa-eco">
			<h2>🌱 <?php echo esc_html( csa_t( 'Economy mode' ) ); ?></h2>
			<label class="csa-check"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[economy]" value="1" <?php checked( $o['economy'], 1 ); ?>> <strong><?php echo esc_html( csa_t( 'Economy mode: cheaper, but the agent may stop long jobs (leave OFF for the best quality)' ) ); ?></strong></label>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'When on: short replies, small page reads, compact page lists, smaller images, the agent sends only the latest part of each chat, and these limits apply:' ) ); ?></p>
			<table class="form-table">
				<tr><th><?php echo esc_html( csa_t( 'Steps before asking' ) ); ?></th><td><input type="number" min="2" max="60" name="<?php echo esc_attr( $n ); ?>[max_steps]" value="<?php echo esc_attr( $o['max_steps'] ); ?>" style="width:70px"> <span class="description"><?php echo esc_html( csa_t( 'While investigating (before a plan), the agent pauses after this many steps and waits for you to press Continue.' ) ); ?></span></td></tr>
				<tr><th><?php echo esc_html( csa_t( 'Build safety limit' ) ); ?></th><td><input type="number" min="5" max="150" name="<?php echo esc_attr( $n ); ?>[build_steps]" value="<?php echo esc_attr( $o['build_steps'] ?? 30 ); ?>" style="width:70px"> <span class="description"><?php echo esc_html( csa_t( 'After you approve a plan the agent works through it without stopping. It only stops if a build takes more steps than this (a safety net against loops).' ) ); ?></span></td></tr>
				<tr><th><?php echo esc_html( csa_t( 'Chat memory' ) ); ?></th><td><input type="number" min="1" max="20" name="<?php echo esc_attr( $n ); ?>[history_turns]" value="<?php echo esc_attr( $o['history_turns'] ); ?>" style="width:70px"> <span class="description"><?php echo esc_html( csa_t( 'latest messages from you that are sent with each request (older ones stay saved but are not re-sent).' ) ); ?> <strong><?php echo esc_html( csa_t( 'Only used in Economy mode. With Economy mode OFF the whole chat is sent (shortened automatically if it gets too big).' ) ); ?></strong></span></td></tr>
				<tr><th><?php echo esc_html( csa_t( 'Limit per chat' ) ); ?></th><td><input type="number" min="1" max="100" name="<?php echo esc_attr( $n ); ?>[chat_cap_pct]" value="<?php echo esc_attr( $o['chat_cap_pct'] ); ?>" style="width:70px"> <?php echo esc_html( csa_t( '% of the monthly budget' ) ); ?> <span class="description">(<?php echo esc_html( '$' . number_format_i18n( (float) $o['budget_usd'] * (int) $o['chat_cap_pct'] / 100, 2 ) ); ?>)</span></td></tr>
				<tr><th><?php echo esc_html( csa_t( 'Limit per day' ) ); ?></th><td><input type="number" min="1" max="100" name="<?php echo esc_attr( $n ); ?>[day_cap_pct]" value="<?php echo esc_attr( $o['day_cap_pct'] ); ?>" style="width:70px"> <?php echo esc_html( csa_t( '% of the monthly budget' ) ); ?> <span class="description">(<?php echo esc_html( '$' . number_format_i18n( (float) $o['budget_usd'] * (int) $o['day_cap_pct'] / 100, 2 ) ); ?>)</span></td></tr>
			</table>
		</div>

		<div class="csa-card"><h2><?php echo esc_html( csa_t( 'Monthly AI allowance' ) ); ?></h2>
		<table class="form-table">
			<tr><th><?php echo esc_html( csa_t( 'Monthly budget (USD)' ) ); ?></th><td><input type="number" step="0.5" min="0.5" name="<?php echo esc_attr( $n ); ?>[budget_usd]" value="<?php echo esc_attr( $o['budget_usd'] ); ?>" style="width:100px"></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Monthly token allowance' ) ); ?></th><td><input type="number" step="100000" min="100000" name="<?php echo esc_attr( $n ); ?>[allowance_tokens]" value="<?php echo esc_attr( $o['allowance_tokens'] ); ?>" style="width:160px"><p class="description"><?php echo esc_html( csa_t( 'The allowance equals the budget. Tokens are deducted in proportion to their real price (writing costs more than reading), so the allowance never costs more than the budget. You get messages at 15%, 40%, 70% and 90%; at 100% the agent pauses until next month.' ) ); ?></p></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Used this month' ) ); ?></th><td><strong><?php echo esc_html( number_format_i18n( min( 100, csa_usage_percent() ), 1 ) . '%' ); ?></strong> <span class="csa-muted"><?php echo esc_html( sprintf( csa_t( '≈ %1$s of %2$s allowance tokens' ), number_format_i18n( csa_tokens_used() ), number_format_i18n( (int) $o['allowance_tokens'] ) ) ); ?></span></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Max size per AI request' ) ); ?></th><td><input type="number" step="1000" min="8000" max="200000" name="<?php echo esc_attr( $n ); ?>[max_request_tokens]" value="<?php echo esc_attr( $o['max_request_tokens'] ); ?>" style="width:120px"> <?php echo esc_html( csa_t( 'tokens' ) ); ?><p class="description"><?php echo esc_html( csa_t( 'Safety limit: a single chat step bigger than this is shortened or stopped, so one big page can never use a large part of the budget. 40,000 is about 0.4% of a $20 budget with Claude Sonnet, and much less with cheaper models.' ) ); ?></p></td></tr>
		</table>
		<h3><?php echo esc_html( csa_t( 'How each model uses the allowance' ) ); ?></h3>
		<p class="csa-muted"><?php echo esc_html( sprintf( csa_t( 'Rule: %1$s allowance tokens = $%2$s. Each real token is charged by its price: allowance tokens = model price per 1M × %3$s. Reading is cheap, writing costs more, and re-reading the same chat (cache) costs least. All of this is calculated automatically; other screens only show the percentage used.' ), number_format_i18n( (int) $o['allowance_tokens'] ), number_format_i18n( (float) $o['budget_usd'], 2 ), number_format_i18n( (float) $o['allowance_tokens'] / ( max( 0.01, (float) $o['budget_usd'] ) * 1000000 ), 2 ) ) ); ?></p>
		<div class="csa-table-wrap"><table class="widefat striped csa-rules">
			<thead><tr><th><?php echo esc_html( csa_t( 'Model' ) ); ?></th><th><?php echo esc_html( csa_t( '1 token read uses' ) ); ?></th><th><?php echo esc_html( csa_t( '1 token written uses' ) ); ?></th><th><?php echo esc_html( csa_t( '1 cached token uses' ) ); ?></th><th><?php echo esc_html( csa_t( 'The monthly allowance is enough for about' ) ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( csa_models() as $mk => $mm ) : $wr = csa_wallet_rate( $mm ); ?>
				<tr>
					<td><strong><?php echo esc_html( $mm['label'] ); ?></strong></td>
					<td><?php echo esc_html( number_format_i18n( $wr['read'], $wr['read'] < 1 ? 2 : 1 ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $wr['write'], $wr['write'] < 1 ? 2 : 1 ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $wr['cached'], $wr['cached'] < 1 ? 3 : 1 ) ); ?></td>
					<td><?php echo esc_html( sprintf( csa_t( '%1$sM tokens read, or %2$sM written' ), number_format_i18n( (float) $o['allowance_tokens'] / max( 0.0001, $wr['read'] ) / 1000000, 1 ), number_format_i18n( (float) $o['allowance_tokens'] / max( 0.0001, $wr['write'] ) / 1000000, 1 ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		</div>

		<div class="csa-card"><h2><?php echo esc_html( csa_t( 'Look & language' ) ); ?></h2>
		<table class="form-table">
			<tr><th><?php echo esc_html( csa_t( 'Agent name' ) ); ?></th><td><input type="text" name="<?php echo esc_attr( $n ); ?>[brand]" value="<?php echo esc_attr( $o['brand'] ); ?>"><p class="description"><?php echo esc_html( csa_t( 'Shown in the menu, chat and as the author of generated drafts.' ) ); ?></p></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Interface language' ) ); ?></th><td><select name="<?php echo esc_attr( $n ); ?>[ui_lang]"><option value="en" <?php selected( $o['ui_lang'], 'en' ); ?>>English</option><option value="bn" <?php selected( $o['ui_lang'], 'bn' ); ?>>বাংলা</option></select></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'SEO report language' ) ); ?></th><td><select name="<?php echo esc_attr( $n ); ?>[seo_lang]"><option <?php selected( $o['seo_lang'], 'English' ); ?>>English</option><option <?php selected( $o['seo_lang'], 'Bangla' ); ?>>Bangla</option></select></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Draft author' ) ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[bot_author]" value="1" <?php checked( $o['bot_author'], 1 ); ?>> <?php echo esc_html( csa_t( 'Show the agent name as the author of drafts it creates' ) ); ?></label></td></tr>
		</table></div>

		<div class="csa-card"><h2><?php echo esc_html( csa_t( 'Access & safety' ) ); ?></h2>
		<table class="form-table">
			<tr><th><?php echo esc_html( csa_t( 'Chat (design & edit)' ) ); ?></th><td><?php $role_boxes( 'roles_chat' ); ?></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'SEO' ) ); ?></th><td><?php $role_boxes( 'roles_seo' ); ?></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Blog Studio' ) ); ?></th><td><?php $role_boxes( 'roles_blog' ); ?></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Images' ) ); ?></th><td><?php $role_boxes( 'roles_images' ); ?><p class="description"><?php echo esc_html( csa_t( 'Administrators always have full access. Site Memory, Activity and Settings are admin-only.' ) ); ?></p></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Live pages' ) ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[allow_live]" value="1" <?php checked( $o['allow_live'], 1 ); ?>> <?php echo esc_html( csa_t( 'Allow approved plans to change published pages' ) ); ?></label></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Chat history' ) ); ?></th><td><input type="number" min="10" max="1000" name="<?php echo esc_attr( $n ); ?>[history_limit]" value="<?php echo esc_attr( $o['history_limit'] ); ?>" style="width:90px"> <?php echo esc_html( csa_t( 'chats kept (oldest unpinned are deleted first)' ) ); ?></td></tr>
		</table></div>

		<div class="csa-card"><h2><?php echo esc_html( csa_t( 'Automation' ) ); ?></h2>
		<table class="form-table">
			<tr><th><?php echo esc_html( csa_t( 'Weekly email summary' ) ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[weekly_email]" value="1" <?php checked( $o['weekly_email'], 1 ); ?>> <?php echo esc_html( csa_t( 'Send to' ) ); ?></label> <input type="email" name="<?php echo esc_attr( $n ); ?>[email_to]" value="<?php echo esc_attr( $o['email_to'] ); ?>"></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Weekly SEO re-audit' ) ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[weekly_audit]" value="1" <?php checked( $o['weekly_audit'], 1 ); ?>> <?php echo esc_html( csa_t( 'Re-audit the' ) ); ?></label> <input type="number" min="1" max="50" name="<?php echo esc_attr( $n ); ?>[weekly_pages]" value="<?php echo esc_attr( $o['weekly_pages'] ); ?>" style="width:70px"> <?php echo esc_html( csa_t( 'least recently checked pages (uses the fast model; stops at 90% allowance)' ) ); ?></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Blog publishing slot' ) ); ?></th><td><select name="<?php echo esc_attr( $n ); ?>[slot_day]">
				<?php foreach ( array( 'daily', 'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday' ) as $d ) : ?>
					<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $o['slot_day'], $d ); ?>><?php echo esc_html( 'daily' === $d ? csa_t( 'Every day' ) : ucfirst( $d ) ); ?></option>
				<?php endforeach; ?>
			</select> <input type="time" name="<?php echo esc_attr( $n ); ?>[slot_time]" value="<?php echo esc_attr( $o['slot_time'] ); ?>"><p class="description"><?php echo esc_html( csa_t( 'Used by the "Next free slot" button in Blog Studio.' ) ); ?></p></td></tr>
			<tr><th>PageSpeed API key</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[psi_key]" value="<?php echo esc_attr( $o['psi_key'] ); ?>"><p class="description"><?php echo esc_html( csa_t( 'Optional, free from Google Cloud. Without it PageSpeed tests still work but may hit a daily limit.' ) ); ?></p></td></tr>
		</table></div>
		<?php submit_button(); ?>
	</form>
	<?php
	csa_footer();
}
