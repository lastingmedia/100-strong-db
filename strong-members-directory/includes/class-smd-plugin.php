<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SMD_PLUGIN_DIR . 'includes/class-smd-member-post-type.php';
require_once SMD_PLUGIN_DIR . 'includes/class-smd-applicant-post-type.php';
require_once SMD_PLUGIN_DIR . 'includes/class-smd-importer.php';
require_once SMD_PLUGIN_DIR . 'includes/class-smd-applications.php';
require_once SMD_PLUGIN_DIR . 'includes/class-smd-shortcodes.php';
require_once SMD_PLUGIN_DIR . 'includes/class-smd-elementor.php';
require_once SMD_PLUGIN_DIR . 'includes/class-smd-settings.php';
require_once SMD_PLUGIN_DIR . 'includes/class-smd-auth.php';
require_once SMD_PLUGIN_DIR . 'includes/class-smd-stripe.php';

class SMD_Plugin {
	/**
	 * Stored plugin version option key.
	 */
	const VERSION_OPTION = 'smd_plugin_version';

	/**
	 * Plugin singleton.
	 *
	 * @var SMD_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SMD_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin.
	 */
	private function __construct() {
		add_action( 'after_setup_theme', array( $this, 'register_image_sizes' ) );
		add_action( 'init', array( 'SMD_Member_Post_Type', 'register' ) );
		add_action( 'init', array( 'SMD_Applicant_Post_Type', 'register' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
		add_action( 'plugins_loaded', array( $this, 'load_components' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		SMD_Auth::register_role();
		SMD_Member_Post_Type::register();
		SMD_Applicant_Post_Type::register();
		SMD_Settings::ensure_directory_page();
		SMD_Settings::ensure_dashboard_page();
		SMD_Settings::ensure_nomination_page();
		SMD_Settings::ensure_applicant_onboarding_page();
		update_option( self::VERSION_OPTION, SMD_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		SMD_Auth::remove_role();
		flush_rewrite_rules();
	}

	/**
	 * Run upgrade tasks when the plugin version changes.
	 */
	public function maybe_upgrade() {
		$stored_version = (string) get_option( self::VERSION_OPTION, '' );

		if ( SMD_VERSION === $stored_version ) {
			return;
		}

		SMD_Auth::register_role();
		SMD_Member_Post_Type::register();
		SMD_Applicant_Post_Type::register();
		SMD_Settings::ensure_directory_page();
		SMD_Settings::ensure_dashboard_page();
		SMD_Settings::ensure_nomination_page();
		SMD_Settings::ensure_applicant_onboarding_page();
		flush_rewrite_rules( false );
		update_option( self::VERSION_OPTION, SMD_VERSION );
	}

	/**
	 * Load shared components.
	 */
	public function load_components() {
		SMD_Member_Post_Type::hooks();
		SMD_Applicant_Post_Type::hooks();
		SMD_Applications::hooks();
		SMD_Importer::hooks();
		SMD_Shortcodes::hooks();
		SMD_Elementor::hooks();
		SMD_Settings::hooks();
		SMD_Auth::hooks();
		SMD_Stripe::hooks();
	}

	/**
	 * Register cropped image sizes used by the directory and member profiles.
	 */
	public function register_image_sizes() {
		add_image_size( 'smd-member-card', 640, 480, array( 'center', 'top' ) );
		add_image_size( 'smd-member-profile', 900, 1125, array( 'center', 'top' ) );
	}

	/**
	 * Enqueue frontend styles.
	 */
	public function enqueue_assets() {
		wp_register_style(
			'smd-frontend',
			SMD_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			SMD_VERSION
		);

		wp_register_script(
			'smd-frontend',
			SMD_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			SMD_VERSION,
			true
		);
		wp_localize_script(
			'smd-frontend',
			'smdFrontend',
			array(
				'invalidImage' => __( 'Please choose a valid image file.', 'strong-members-directory' ),
			)
		);

		if ( is_singular( SMD_Member_Post_Type::POST_TYPE ) || SMD_Settings::is_directory_page() || SMD_Settings::is_applicant_onboarding_page() ) {
			wp_enqueue_style( 'smd-frontend' );
		}

		if ( is_singular( SMD_Member_Post_Type::POST_TYPE ) ) {
			wp_enqueue_script( 'smd-frontend' );
		}
	}

	/**
	 * Enqueue admin styles for applicant workflow screens.
	 */
	public function enqueue_admin_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return;
		}

		$allowed_screens = array(
			'edit-' . SMD_Applicant_Post_Type::POST_TYPE,
			SMD_Applicant_Post_Type::POST_TYPE,
			SMD_Member_Post_Type::POST_TYPE . '_page_smd-applications-dashboard',
		);

		if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'smd-admin',
			SMD_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SMD_VERSION
		);
	}
}
