<?php
/**
 * Plugin Name: Strong Members Directory
 * Description: Member directory plugin with bulk CSV import and Elementor-friendly listings.
 * Version: 1.9.1
 * Author: Lasting Media
 * Text Domain: strong-members-directory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SMD_VERSION', '1.9.1' );
define( 'SMD_PLUGIN_FILE', __FILE__ );
define( 'SMD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SMD_PLUGIN_DIR . 'includes/class-smd-plugin.php';

register_activation_hook( __FILE__, array( 'SMD_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SMD_Plugin', 'deactivate' ) );

SMD_Plugin::instance();
