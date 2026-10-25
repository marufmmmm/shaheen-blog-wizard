<?php
/**
 * Site Agent + SEO suite, merged into Blog Write .ai.
 * Loaded from shaheen-blog.php. If the old stand-alone "Claude Site Agent"
 * plugin is still active we do not load (both would define the same functions)
 * and show a notice instead.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'CSA_VER' ) || function_exists( 'csa_install' ) ) {
	if ( ! defined( 'SB_SA_BLOCKED' ) ) {
		define( 'SB_SA_BLOCKED', true );
	}
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p><strong>Blog Write .ai:</strong> পুরনো "Claude Site Agent" প্লাগিন এখনো চালু আছে, তাই Site Agent ও SEO অংশ এখানে লোড হয়নি। আগে ডেটা ব্যাকআপ নিন, তারপর পুরনো প্লাগিনটি Deactivate করুন — সব সেটিংস ও ডেটা অক্ষত থাকবে।</p></div>';
			}
		}
	);
	return;
}

define( 'SB_SA_BLOCKED', false );
define( 'CSA_VER', SB_VERSION );
define( 'CSA_FILE', SB_FILE );
define( 'CSA_DIR', SB_PATH . 'site-agent/' );
define( 'CSA_URL', SB_URL . 'site-agent/' );

foreach ( array( 'core', 'i18n', 'models', 'install', 'agent', 'memory', 'automem', 'siteindex', 'design', 'keywords', 'seo', 'score', 'textedit', 'tohtml', 'jobs', 'autopilot', 'studio', 'notices', 'blog', 'media', 'guided', 'format', 'pin', 'fonts', 'admin' ) as $csa_f ) {
	require_once CSA_DIR . 'includes/' . $csa_f . '.php';
}
unset( $csa_f );

register_activation_hook( SB_FILE, 'csa_install' );
register_deactivation_hook( SB_FILE, 'csa_deactivate' );
