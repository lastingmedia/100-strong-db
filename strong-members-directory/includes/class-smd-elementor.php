<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMD_Elementor {
	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Register Elementor widget when available.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor manager.
	 */
	public static function register_widget( $widgets_manager ) {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		require_once SMD_PLUGIN_DIR . 'includes/class-smd-elementor-widget.php';
		$widgets_manager->register( new SMD_Elementor_Members_Widget() );
	}
}
