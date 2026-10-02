<?php
/**
 * Plugin Name: Meet the Salmans – Complete Opinion Polls
 * Description: Direct-access opinion polls with dynamic categories, AJAX search, randomized answers, optional gender/context, and always-visible horizontal result charts.
 * Version:     3.0.0
 * Author:      Syed Abdul Quaseem
 * Text Domain: mts-complete-opinion-polls
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MTS_CP_VERSION', '3.0.0' );
define( 'MTS_CP_FILE', __FILE__ );
define( 'MTS_CP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MTS_CP_URL', plugin_dir_url( __FILE__ ) );

require_once MTS_CP_DIR . 'includes/class-mts-cp-core.php';
require_once MTS_CP_DIR . 'includes/class-mts-cp-admin.php';
require_once MTS_CP_DIR . 'includes/class-mts-cp-frontend.php';

register_activation_hook( __FILE__, array( 'MTS_CP_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MTS_CP_Core', 'deactivate' ) );

function mts_cp_boot_plugin() {
    $core = MTS_CP_Core::instance();

    if ( is_admin() ) {
        new MTS_CP_Admin( $core );
    }

    new MTS_CP_Frontend( $core );
}
add_action( 'plugins_loaded', 'mts_cp_boot_plugin' );
