<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMD_Elementor_Members_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'smd_members_directory';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Members Directory', 'strong-members-directory' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-person';
	}

	/**
	 * Widget categories.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Enable Elementor's native inner wrapper so Advanced tab selectors target
	 * the standard `.elementor-widget-container` element.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper(): bool {
		return true;
	}

	/**
	 * Register widget controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Directory Settings', 'strong-members-directory' ),
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'   => __( 'Columns', 'strong-members-directory' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '4',
				'options' => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'       => __( 'Number of Members', 'strong-members-directory' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => -1,
				'description' => __( 'Use -1 to show all members.', 'strong-members-directory' ),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => __( 'Sort Order', 'strong-members-directory' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'ASC',
				'options' => array(
					'ASC'  => __( 'A to Z', 'strong-members-directory' ),
					'DESC' => __( 'Z to A', 'strong-members-directory' ),
				),
			)
		);

		$this->add_control(
			'show_filters',
			array(
				'label'   => __( 'Show Filters', 'strong-members-directory' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'member_type',
			array(
				'label'       => __( 'Preset Member Type', 'strong-members-directory' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'Optional. Limits this widget to one member type.', 'strong-members-directory' ),
			)
		);

		$this->add_control(
			'occupation',
			array(
				'label'       => __( 'Preset Occupation', 'strong-members-directory' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'Optional. Limits this widget to one occupation.', 'strong-members-directory' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		echo do_shortcode(
			sprintf(
				'[strong_members columns="%1$d" limit="%2$d" order="%3$s" show_filters="%4$s" member_type="%5$s" occupation="%6$s"]',
				(int) $settings['columns'],
				(int) $settings['limit'],
				esc_attr( $settings['order'] ),
				! empty( $settings['show_filters'] ) ? 'yes' : 'no',
				esc_attr( $settings['member_type'] ?? '' ),
				esc_attr( $settings['occupation'] ?? '' )
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
