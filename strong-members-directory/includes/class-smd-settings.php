<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMD_Settings {
	const OPTION_KEY = 'smd_settings';

	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_directory_page' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'directory_page_id'      => 0,
			'dashboard_page_id'      => 0,
			'nomination_page_id'     => 0,
			'applicant_onboarding_page_id' => 0,
			'members_only'           => 1,
			'logged_out_message'     => __( 'This member directory is available to logged-in members only.', 'strong-members-directory' ),
			'gravity_form_id'        => 3,
			'gravity_field_first_name' => '16',
			'gravity_field_last_name'  => '17',
			'gravity_field_email'      => '6',
			'gravity_field_occupation' => '7',
			'gravity_field_phone'      => '5',
			'gravity_field_reason'     => '10',
			'gravity_field_address_street' => '4.1',
			'gravity_field_address_city'   => '4.3',
			'gravity_field_address_state'  => '4.4',
			'gravity_field_address_zip'    => '4.5',
			'gravity_field_nominator'      => '8',
			'gravity_field_quarterly_commitment' => '11.1',
			'gravity_field_nonprofit_experience' => '12',
			'gravity_field_membership_commitment' => '13.1',
			'gravity_field_photo'          => '14',
			'gravity_field_photo_release'  => '15.1',
			'stripe_secret_key'      => '',
			'stripe_price_id'        => '',
			'stripe_webhook_secret'  => '',
			'mailchimp_api_key'      => '',
			'mailchimp_audience_id'  => '',
			'mailchimp_tags'         => 'Member,100 Strong',
			'spreadsheet_webhook_url'=> '',
			'email_from_name'        => '100 Strong',
			'email_from_address'     => get_option( 'admin_email' ),
			'workflow_admin_email'   => get_option( 'admin_email' ),
			'onboarding_email_subject' => 'Your 100 Strong membership approval next steps',
			'onboarding_email_body'    => "Hi {first_name},\n\nYour membership application has been approved by the board.\n\nPlease use the link below to confirm that you are still interested and complete your billing setup. Billing setup is the first required onboarding step before we create your full member account.\n\n{onboarding_url}\n\nWe are excited to have you join us.\n\n100 Strong",
			'welcome_email_subject'    => 'Welcome to 100 Strong',
			'welcome_email_body'       => "Hi {first_name},\n\nYour membership billing is complete and your member account is now active.\n\nYou should also receive a WordPress password setup email shortly if your account is new. Once you set your password, you can log in here:\n{dashboard_url}\n\nWelcome to 100 Strong.\n",
			'password_setup_email_subject' => 'Set up your 100 Strong account',
			'password_setup_email_body'    => "Hi {first_name},\n\nYour 100 Strong account is ready.\n\nPlease use the link below to set your password and finish setting up your account:\n\n{reset_url}\n\nUsername: {username}\n\nIf you did not expect this email, you can ignore it.\n\n100 Strong",
			'membership_canceled_email_subject' => 'Your 100 Strong membership has been canceled',
			'membership_canceled_email_body'    => "Hi {first_name},\n\nThis email confirms that your 100 Strong membership and recurring billing have been canceled.\n\nIf you believe this was done in error or you would like to rejoin, please contact us at contact@100strong.org.\n\nThank you,\n100 Strong",
		);
	}

	/**
	 * Read merged settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::defaults() );
	}

	/**
	 * Settings page registration.
	 */
	public static function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=' . SMD_Member_Post_Type::POST_TYPE,
			__( 'Member Directory Settings', 'strong-members-directory' ),
			__( 'Settings', 'strong-members-directory' ),
			'manage_options',
			'smd-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings and fields.
	 */
	public static function register_settings() {
		register_setting(
			'smd_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'smd_general_settings',
			__( 'Directory Access', 'strong-members-directory' ),
			'__return_false',
			'smd-settings'
		);

		add_settings_field(
			'directory_page_id',
			__( 'Directory Landing Page', 'strong-members-directory' ),
			array( __CLASS__, 'render_directory_page_field' ),
			'smd-settings',
			'smd_general_settings'
		);

		add_settings_field(
			'dashboard_page_id',
			__( 'Member Dashboard Page', 'strong-members-directory' ),
			array( __CLASS__, 'render_dashboard_page_field' ),
			'smd-settings',
			'smd_general_settings'
		);

		add_settings_field(
			'nomination_page_id',
			__( 'Nomination Page', 'strong-members-directory' ),
			array( __CLASS__, 'render_nomination_page_field' ),
			'smd-settings',
			'smd_general_settings'
		);

		add_settings_field(
			'applicant_onboarding_page_id',
			__( 'Applicant Onboarding Page', 'strong-members-directory' ),
			array( __CLASS__, 'render_applicant_onboarding_page_field' ),
			'smd-settings',
			'smd_general_settings'
		);

		add_settings_field(
			'members_only',
			__( 'Restrict Directory To Logged-In Members', 'strong-members-directory' ),
			array( __CLASS__, 'render_members_only_field' ),
			'smd-settings',
			'smd_general_settings'
		);

		add_settings_field(
			'logged_out_message',
			__( 'Logged-Out Message', 'strong-members-directory' ),
			array( __CLASS__, 'render_logged_out_message_field' ),
			'smd-settings',
			'smd_general_settings'
		);

		add_settings_section(
			'smd_application_settings',
			__( 'Application Workflow', 'strong-members-directory' ),
			'__return_false',
			'smd-settings'
		);

		add_settings_field(
			'gravity_form_id',
			__( 'Gravity Form ID', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_form_id_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_first_name',
			__( 'Gravity Field ID: First Name', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_first_name_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_last_name',
			__( 'Gravity Field ID: Last Name', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_last_name_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_email',
			__( 'Gravity Field ID: Email', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_email_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_occupation',
			__( 'Gravity Field ID: Occupation', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_occupation_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_phone',
			__( 'Gravity Field ID: Phone', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_phone_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_reason',
			__( 'Gravity Field ID: Application Notes', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_reason_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_address_street',
			__( 'Gravity Field ID: Address Street', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_address_street_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_address_city',
			__( 'Gravity Field ID: Address City', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_address_city_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_address_state',
			__( 'Gravity Field ID: Address State', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_address_state_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_address_zip',
			__( 'Gravity Field ID: Address ZIP', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_address_zip_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_nominator',
			__( 'Gravity Field ID: Nominator', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_nominator_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_quarterly_commitment',
			__( 'Gravity Field ID: Quarterly Fee Commitment', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_quarterly_commitment_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_nonprofit_experience',
			__( 'Gravity Field ID: Nonprofit Experience', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_nonprofit_experience_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_membership_commitment',
			__( 'Gravity Field ID: Membership Commitment', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_membership_commitment_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_photo',
			__( 'Gravity Field ID: Applicant Photo', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_photo_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_field(
			'gravity_field_photo_release',
			__( 'Gravity Field ID: Photo Release', 'strong-members-directory' ),
			array( __CLASS__, 'render_gravity_field_photo_release_field' ),
			'smd-settings',
			'smd_application_settings'
		);

		add_settings_section(
			'smd_stripe_settings',
			__( 'Stripe Billing', 'strong-members-directory' ),
			'__return_false',
			'smd-settings'
		);

		add_settings_field(
			'stripe_secret_key',
			__( 'Stripe Secret Key', 'strong-members-directory' ),
			array( __CLASS__, 'render_stripe_secret_key_field' ),
			'smd-settings',
			'smd_stripe_settings'
		);

		add_settings_field(
			'stripe_price_id',
			__( 'Quarterly Membership Price ID', 'strong-members-directory' ),
			array( __CLASS__, 'render_stripe_price_id_field' ),
			'smd-settings',
			'smd_stripe_settings'
		);

		add_settings_field(
			'stripe_webhook_secret',
			__( 'Stripe Webhook Secret', 'strong-members-directory' ),
			array( __CLASS__, 'render_stripe_webhook_secret_field' ),
			'smd-settings',
			'smd_stripe_settings'
		);

		add_settings_section(
			'smd_email_settings',
			__( 'Member Emails', 'strong-members-directory' ),
			'__return_false',
			'smd-settings'
		);

		add_settings_field(
			'email_from_name',
			__( 'From Name', 'strong-members-directory' ),
			array( __CLASS__, 'render_email_from_name_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_field(
			'email_from_address',
			__( 'From Email', 'strong-members-directory' ),
			array( __CLASS__, 'render_email_from_address_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_field(
			'workflow_admin_email',
			__( 'Workflow Admin Email', 'strong-members-directory' ),
			array( __CLASS__, 'render_workflow_admin_email_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_field(
			'onboarding_email_subject',
			__( 'Onboarding Email Subject', 'strong-members-directory' ),
			array( __CLASS__, 'render_onboarding_email_subject_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_field(
			'onboarding_email_body',
			__( 'Onboarding Email Body', 'strong-members-directory' ),
			array( __CLASS__, 'render_onboarding_email_body_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_field(
			'welcome_email_subject',
			__( 'Welcome Email Subject', 'strong-members-directory' ),
			array( __CLASS__, 'render_welcome_email_subject_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_field(
			'welcome_email_body',
			__( 'Welcome Email Body', 'strong-members-directory' ),
			array( __CLASS__, 'render_welcome_email_body_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_field(
			'password_setup_email_subject',
			__( 'Password Setup Email Subject', 'strong-members-directory' ),
			array( __CLASS__, 'render_password_setup_email_subject_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_field(
			'password_setup_email_body',
			__( 'Password Setup Email Body', 'strong-members-directory' ),
			array( __CLASS__, 'render_password_setup_email_body_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_field(
			'membership_canceled_email_subject',
			__( 'Cancellation Email Subject', 'strong-members-directory' ),
			array( __CLASS__, 'render_membership_canceled_email_subject_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_field(
			'membership_canceled_email_body',
			__( 'Cancellation Email Body', 'strong-members-directory' ),
			array( __CLASS__, 'render_membership_canceled_email_body_field' ),
			'smd-settings',
			'smd_email_settings'
		);

		add_settings_section(
			'smd_post_approval_integrations',
			__( 'Post-Approval Integrations', 'strong-members-directory' ),
			'__return_false',
			'smd-settings'
		);

		add_settings_field(
			'mailchimp_api_key',
			__( 'Mailchimp API Key', 'strong-members-directory' ),
			array( __CLASS__, 'render_mailchimp_api_key_field' ),
			'smd-settings',
			'smd_post_approval_integrations'
		);

		add_settings_field(
			'mailchimp_audience_id',
			__( 'Mailchimp Audience ID', 'strong-members-directory' ),
			array( __CLASS__, 'render_mailchimp_audience_id_field' ),
			'smd-settings',
			'smd_post_approval_integrations'
		);

		add_settings_field(
			'mailchimp_tags',
			__( 'Mailchimp Tags', 'strong-members-directory' ),
			array( __CLASS__, 'render_mailchimp_tags_field' ),
			'smd-settings',
			'smd_post_approval_integrations'
		);

		add_settings_field(
			'spreadsheet_webhook_url',
			__( 'Spreadsheet Webhook URL', 'strong-members-directory' ),
			array( __CLASS__, 'render_spreadsheet_webhook_url_field' ),
			'smd-settings',
			'smd_post_approval_integrations'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw values.
	 * @return array<string, int>
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::defaults();

		return array(
			'directory_page_id'     => isset( $input['directory_page_id'] ) ? (int) $input['directory_page_id'] : 0,
			'dashboard_page_id'     => isset( $input['dashboard_page_id'] ) ? (int) $input['dashboard_page_id'] : 0,
			'nomination_page_id'    => isset( $input['nomination_page_id'] ) ? (int) $input['nomination_page_id'] : 0,
			'applicant_onboarding_page_id' => isset( $input['applicant_onboarding_page_id'] ) ? (int) $input['applicant_onboarding_page_id'] : 0,
			'members_only'          => ! empty( $input['members_only'] ) ? 1 : 0,
			'logged_out_message'    => isset( $input['logged_out_message'] ) && '' !== trim( (string) $input['logged_out_message'] )
				? sanitize_textarea_field( wp_unslash( $input['logged_out_message'] ) )
				: $defaults['logged_out_message'],
			'gravity_form_id'       => isset( $input['gravity_form_id'] ) ? (int) $input['gravity_form_id'] : 0,
			'gravity_field_first_name' => isset( $input['gravity_field_first_name'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_first_name'] ) ) : '',
			'gravity_field_last_name'  => isset( $input['gravity_field_last_name'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_last_name'] ) ) : '',
			'gravity_field_email'      => isset( $input['gravity_field_email'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_email'] ) ) : '',
			'gravity_field_occupation' => isset( $input['gravity_field_occupation'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_occupation'] ) ) : '',
			'gravity_field_phone'      => isset( $input['gravity_field_phone'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_phone'] ) ) : '',
			'gravity_field_reason'     => isset( $input['gravity_field_reason'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_reason'] ) ) : '',
			'gravity_field_address_street' => isset( $input['gravity_field_address_street'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_address_street'] ) ) : '',
			'gravity_field_address_city'   => isset( $input['gravity_field_address_city'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_address_city'] ) ) : '',
			'gravity_field_address_state'  => isset( $input['gravity_field_address_state'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_address_state'] ) ) : '',
			'gravity_field_address_zip'    => isset( $input['gravity_field_address_zip'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_address_zip'] ) ) : '',
			'gravity_field_nominator'      => isset( $input['gravity_field_nominator'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_nominator'] ) ) : '',
			'gravity_field_quarterly_commitment' => isset( $input['gravity_field_quarterly_commitment'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_quarterly_commitment'] ) ) : '',
			'gravity_field_nonprofit_experience' => isset( $input['gravity_field_nonprofit_experience'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_nonprofit_experience'] ) ) : '',
			'gravity_field_membership_commitment' => isset( $input['gravity_field_membership_commitment'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_membership_commitment'] ) ) : '',
			'gravity_field_photo'          => isset( $input['gravity_field_photo'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_photo'] ) ) : '',
			'gravity_field_photo_release'  => isset( $input['gravity_field_photo_release'] ) ? sanitize_text_field( wp_unslash( $input['gravity_field_photo_release'] ) ) : '',
			'stripe_secret_key'     => isset( $input['stripe_secret_key'] ) ? sanitize_text_field( wp_unslash( $input['stripe_secret_key'] ) ) : '',
			'stripe_price_id'       => isset( $input['stripe_price_id'] ) ? sanitize_text_field( wp_unslash( $input['stripe_price_id'] ) ) : '',
			'stripe_webhook_secret' => isset( $input['stripe_webhook_secret'] ) ? sanitize_text_field( wp_unslash( $input['stripe_webhook_secret'] ) ) : '',
			'mailchimp_api_key'     => isset( $input['mailchimp_api_key'] ) ? sanitize_text_field( wp_unslash( $input['mailchimp_api_key'] ) ) : '',
			'mailchimp_audience_id' => isset( $input['mailchimp_audience_id'] ) ? sanitize_text_field( wp_unslash( $input['mailchimp_audience_id'] ) ) : '',
			'mailchimp_tags'        => isset( $input['mailchimp_tags'] ) ? sanitize_text_field( wp_unslash( $input['mailchimp_tags'] ) ) : '',
			'spreadsheet_webhook_url' => isset( $input['spreadsheet_webhook_url'] ) ? esc_url_raw( wp_unslash( $input['spreadsheet_webhook_url'] ) ) : '',
			'email_from_name'       => isset( $input['email_from_name'] ) ? sanitize_text_field( wp_unslash( $input['email_from_name'] ) ) : '100 Strong',
			'email_from_address'    => isset( $input['email_from_address'] ) ? sanitize_email( wp_unslash( $input['email_from_address'] ) ) : get_option( 'admin_email' ),
			'workflow_admin_email'  => isset( $input['workflow_admin_email'] ) ? sanitize_email( wp_unslash( $input['workflow_admin_email'] ) ) : get_option( 'admin_email' ),
			'onboarding_email_subject' => isset( $input['onboarding_email_subject'] ) ? sanitize_text_field( wp_unslash( $input['onboarding_email_subject'] ) ) : $defaults['onboarding_email_subject'],
			'onboarding_email_body'    => isset( $input['onboarding_email_body'] ) ? wp_kses_post( wp_unslash( $input['onboarding_email_body'] ) ) : $defaults['onboarding_email_body'],
			'welcome_email_subject'    => isset( $input['welcome_email_subject'] ) ? sanitize_text_field( wp_unslash( $input['welcome_email_subject'] ) ) : $defaults['welcome_email_subject'],
			'welcome_email_body'       => isset( $input['welcome_email_body'] ) ? wp_kses_post( wp_unslash( $input['welcome_email_body'] ) ) : $defaults['welcome_email_body'],
			'password_setup_email_subject' => isset( $input['password_setup_email_subject'] ) ? sanitize_text_field( wp_unslash( $input['password_setup_email_subject'] ) ) : $defaults['password_setup_email_subject'],
			'password_setup_email_body'    => isset( $input['password_setup_email_body'] ) ? wp_kses_post( wp_unslash( $input['password_setup_email_body'] ) ) : $defaults['password_setup_email_body'],
			'membership_canceled_email_subject' => isset( $input['membership_canceled_email_subject'] ) ? sanitize_text_field( wp_unslash( $input['membership_canceled_email_subject'] ) ) : $defaults['membership_canceled_email_subject'],
			'membership_canceled_email_body'    => isset( $input['membership_canceled_email_body'] ) ? wp_kses_post( wp_unslash( $input['membership_canceled_email_body'] ) ) : $defaults['membership_canceled_email_body'],
		);
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page() {
		$sync_report      = isset( $_GET['smd_sync_report'] ) ? json_decode( rawurldecode( wp_unslash( $_GET['smd_sync_report'] ) ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$resend_passwords = isset( $_GET['smd_resend_passwords'] ) ? (int) $_GET['smd_resend_passwords'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$resend_sent      = isset( $_GET['smd_resend_passwords_sent'] ) ? (int) $_GET['smd_resend_passwords_sent'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stripe_import_error   = isset( $_GET['smd_stripe_import_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_stripe_import_error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stripe_import_preview = isset( $_GET['smd_stripe_import_preview'] ) ? sanitize_key( wp_unslash( $_GET['smd_stripe_import_preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stripe_import_report_token = isset( $_GET['smd_stripe_import_report'] ) ? sanitize_key( wp_unslash( $_GET['smd_stripe_import_report'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stripe_preview        = $stripe_import_preview ? SMD_Stripe::get_import_preview( $stripe_import_preview ) : false;
		$stripe_import_report  = $stripe_import_report_token ? SMD_Stripe::get_import_report( $stripe_import_report_token ) : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Member Directory Settings', 'strong-members-directory' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'smd_settings_group' );
				do_settings_sections( 'smd-settings' );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Member Login Sync', 'strong-members-directory' ); ?></h2>
			<p><?php esc_html_e( 'CSV imports already create member logins automatically when a valid email address is included. Use this button only to backfill logins for members that were imported or created before that automation was in place.', 'strong-members-directory' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php wp_nonce_field( 'smd_sync_member_logins', 'smd_sync_member_logins_nonce' ); ?>
				<input type="hidden" name="action" value="smd_sync_member_logins">
				<?php submit_button( __( 'Sync Member Logins', 'strong-members-directory' ), 'secondary', 'submit', false ); ?>
			</form>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:10px;">
				<?php wp_nonce_field( 'smd_resend_member_password_setups', 'smd_resend_member_password_setups_nonce' ); ?>
				<input type="hidden" name="action" value="smd_resend_member_password_setups">
				<?php submit_button( __( 'Resend Password Setup Emails', 'strong-members-directory' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( ! empty( $sync_report ) ) : ?>
				<h3><?php esc_html_e( 'Last Sync Summary', 'strong-members-directory' ); ?></h3>
				<ul>
					<li><?php echo esc_html( sprintf( __( 'New users created: %d', 'strong-members-directory' ), (int) $sync_report['created'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Existing users linked/already linked: %d', 'strong-members-directory' ), (int) $sync_report['linked'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Skipped (usually missing email): %d', 'strong-members-directory' ), (int) $sync_report['skipped'] ) ); ?></li>
				</ul>
				<?php if ( ! empty( $sync_report['errors'] ) ) : ?>
					<h4><?php esc_html_e( 'Errors', 'strong-members-directory' ); ?></h4>
					<ul>
						<?php foreach ( $sync_report['errors'] as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $resend_passwords ) : ?>
				<p><?php echo esc_html( sprintf( __( 'Password setup emails sent: %d', 'strong-members-directory' ), $resend_sent ) ); ?></p>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Stripe Webhook URL', 'strong-members-directory' ); ?></h2>
			<p><?php esc_html_e( 'Use this endpoint in your Stripe Dashboard webhook configuration for subscription and invoice events.', 'strong-members-directory' ); ?></p>
			<code><?php echo esc_html( SMD_Stripe::get_webhook_url() ); ?></code>

			<hr>

			<h2><?php esc_html_e( 'Import Existing Stripe Subscriptions', 'strong-members-directory' ); ?></h2>
			<p><?php esc_html_e( 'Use this to match existing recurring Stripe subscriptions to current members so they do not need to enter their card details again. The import runs as a dry-run preview first.', 'strong-members-directory' ); ?></p>
			<?php if ( $stripe_import_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $stripe_import_error ); ?></p></div>
			<?php endif; ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php wp_nonce_field( 'smd_preview_stripe_subscription_import', 'smd_preview_stripe_subscription_import_nonce' ); ?>
				<input type="hidden" name="action" value="smd_preview_stripe_subscription_import">
				<?php submit_button( __( 'Preview Stripe Subscription Import', 'strong-members-directory' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( $stripe_preview && is_array( $stripe_preview ) ) : ?>
				<h3><?php esc_html_e( 'Stripe Import Preview', 'strong-members-directory' ); ?></h3>
				<ul>
					<li><?php echo esc_html( sprintf( __( 'Subscriptions found: %d', 'strong-members-directory' ), (int) $stripe_preview['summary']['total'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Ready to import: %d', 'strong-members-directory' ), (int) $stripe_preview['summary']['importable'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Conflicts: %d', 'strong-members-directory' ), (int) $stripe_preview['summary']['conflicts'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Skipped: %d', 'strong-members-directory' ), (int) $stripe_preview['summary']['skipped'] ) ); ?></li>
				</ul>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin:16px 0 20px;">
					<?php wp_nonce_field( 'smd_commit_stripe_subscription_import', 'smd_commit_stripe_subscription_import_nonce' ); ?>
					<input type="hidden" name="action" value="smd_commit_stripe_subscription_import">
					<input type="hidden" name="smd_stripe_import_preview_token" value="<?php echo esc_attr( $stripe_import_preview ); ?>">
					<?php submit_button( __( 'Apply Stripe Subscription Import', 'strong-members-directory' ), 'primary', 'submit', false ); ?>
				</form>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Subscription ID', 'strong-members-directory' ); ?></th>
							<th><?php esc_html_e( 'Stripe Email', 'strong-members-directory' ); ?></th>
							<th><?php esc_html_e( 'Matched Member', 'strong-members-directory' ); ?></th>
							<th><?php esc_html_e( 'Action', 'strong-members-directory' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'strong-members-directory' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stripe_preview['rows'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['subscription_id'] ); ?></td>
								<td><?php echo esc_html( $row['customer_email'] ? $row['customer_email'] : __( 'No email', 'strong-members-directory' ) ); ?></td>
								<td><?php echo esc_html( $row['member_label'] ); ?></td>
								<td><?php echo esc_html( $row['action_label'] ); ?></td>
								<td><?php echo esc_html( $row['notes'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $stripe_import_report ) ) : ?>
				<h3><?php esc_html_e( 'Last Stripe Import Summary', 'strong-members-directory' ); ?></h3>
				<ul>
					<li><?php echo esc_html( sprintf( __( 'Imported: %d', 'strong-members-directory' ), (int) $stripe_import_report['imported'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Skipped: %d', 'strong-members-directory' ), (int) $stripe_import_report['skipped'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Conflicts: %d', 'strong-members-directory' ), (int) $stripe_import_report['conflicts'] ) ); ?></li>
				</ul>
				<?php if ( ! empty( $stripe_import_report['rows'] ) ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Subscription ID', 'strong-members-directory' ); ?></th>
								<th><?php esc_html_e( 'Stripe Email', 'strong-members-directory' ); ?></th>
								<th><?php esc_html_e( 'Matched Member', 'strong-members-directory' ); ?></th>
								<th><?php esc_html_e( 'Action', 'strong-members-directory' ); ?></th>
								<th><?php esc_html_e( 'Notes', 'strong-members-directory' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $stripe_import_report['rows'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['subscription_id'] ); ?></td>
									<td><?php echo esc_html( $row['customer_email'] ? $row['customer_email'] : __( 'No email', 'strong-members-directory' ) ); ?></td>
									<td><?php echo esc_html( $row['member'] ); ?></td>
									<td><?php echo esc_html( $row['action'] ); ?></td>
									<td><?php echo esc_html( $row['notes'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render page selector.
	 */
	public static function render_directory_page_field() {
		$settings = self::get_settings();
		wp_dropdown_pages(
			array(
				'name'             => self::OPTION_KEY . '[directory_page_id]',
				'selected'         => (int) $settings['directory_page_id'],
				'show_option_none' => __( 'Select a page', 'strong-members-directory' ),
			)
		);

		echo '<p class="description">' . esc_html__( 'Members will be redirected here after logging in. This page should contain the [strong_members] shortcode or the Elementor Members Directory widget.', 'strong-members-directory' ) . '</p>';
	}

	/**
	 * Render dashboard selector.
	 */
	public static function render_dashboard_page_field() {
		$settings = self::get_settings();
		wp_dropdown_pages(
			array(
				'name'             => self::OPTION_KEY . '[dashboard_page_id]',
				'selected'         => (int) $settings['dashboard_page_id'],
				'show_option_none' => __( 'Select a page', 'strong-members-directory' ),
			)
		);

		echo '<p class="description">' . esc_html__( 'Members land here after logging in. This page should contain the [strong_member_dashboard] shortcode.', 'strong-members-directory' ) . '</p>';
	}

	/**
	 * Render nomination selector.
	 */
	public static function render_nomination_page_field() {
		$settings = self::get_settings();
		wp_dropdown_pages(
			array(
				'name'             => self::OPTION_KEY . '[nomination_page_id]',
				'selected'         => (int) $settings['nomination_page_id'],
				'show_option_none' => __( 'Select a page', 'strong-members-directory' ),
			)
		);

		echo '<p class="description">' . esc_html__( 'This page hosts the member nomination form via the [strong_member_nomination] shortcode.', 'strong-members-directory' ) . '</p>';
	}

	/**
	 * Render applicant onboarding selector.
	 */
	public static function render_applicant_onboarding_page_field() {
		$settings = self::get_settings();
		wp_dropdown_pages(
			array(
				'name'             => self::OPTION_KEY . '[applicant_onboarding_page_id]',
				'selected'         => (int) $settings['applicant_onboarding_page_id'],
				'show_option_none' => __( 'Select a page', 'strong-members-directory' ),
			)
		);

		echo '<p class="description">' . esc_html__( 'Approved applicants land here to confirm interest and complete billing. This page should contain the [strong_applicant_onboarding] shortcode.', 'strong-members-directory' ) . '</p>';
	}

	/**
	 * Render members-only checkbox.
	 */
	public static function render_members_only_field() {
		$settings = self::get_settings();
		?>
		<label for="smd_members_only">
			<input type="checkbox" id="smd_members_only" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[members_only]" value="1" <?php checked( 1, (int) $settings['members_only'] ); ?>>
			<?php esc_html_e( 'Require login before viewing the directory page and member profiles.', 'strong-members-directory' ); ?>
		</label>
		<?php
	}

	/**
	 * Render logged-out message field.
	 */
	public static function render_logged_out_message_field() {
		$settings = self::get_settings();
		?>
		<textarea
			id="smd_logged_out_message"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[logged_out_message]"
			rows="4"
			class="large-text"
		><?php echo esc_textarea( (string) $settings['logged_out_message'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'This message is shown to visitors who are not logged in when they try to view protected member content.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render Stripe secret key field.
	 */
	public static function render_stripe_secret_key_field() {
		$settings = self::get_settings();
		?>
		<input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[stripe_secret_key]" value="<?php echo esc_attr( (string) $settings['stripe_secret_key'] ); ?>" autocomplete="off">
		<p class="description"><?php esc_html_e( 'Use your Stripe secret key for the account that owns the quarterly membership subscription.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render Stripe price ID field.
	 */
	public static function render_stripe_price_id_field() {
		$settings = self::get_settings();
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[stripe_price_id]" value="<?php echo esc_attr( (string) $settings['stripe_price_id'] ); ?>">
		<p class="description"><?php esc_html_e( 'Enter the recurring Stripe Price ID for your quarterly membership product.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render Stripe webhook secret field.
	 */
	public static function render_stripe_webhook_secret_field() {
		$settings = self::get_settings();
		?>
		<input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[stripe_webhook_secret]" value="<?php echo esc_attr( (string) $settings['stripe_webhook_secret'] ); ?>" autocomplete="off">
		<p class="description"><?php esc_html_e( 'Paste the webhook signing secret from Stripe so incoming billing events can be verified.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render Gravity Forms mapping helper.
	 *
	 * @param string $key Settings key.
	 * @param string $description Description copy.
	 */
	private static function render_gravity_field_id_input( $key, $description ) {
		$settings = self::get_settings();
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>">
		<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php
	}

	/**
	 * Render Gravity Form ID field.
	 */
	public static function render_gravity_form_id_field() {
		$settings = self::get_settings();
		?>
		<input type="number" class="small-text" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[gravity_form_id]" value="<?php echo esc_attr( (string) $settings['gravity_form_id'] ); ?>">
		<p class="description"><?php esc_html_e( 'When this Gravity Form is submitted, the plugin will create an Applicant record automatically.', 'strong-members-directory' ); ?></p>
		<?php
	}

	public static function render_gravity_field_first_name_field() {
		self::render_gravity_field_id_input( 'gravity_field_first_name', __( 'Enter the Gravity Forms field ID that contains the applicant first name.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_last_name_field() {
		self::render_gravity_field_id_input( 'gravity_field_last_name', __( 'Enter the Gravity Forms field ID that contains the applicant last name.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_email_field() {
		self::render_gravity_field_id_input( 'gravity_field_email', __( 'Enter the Gravity Forms field ID that contains the applicant email address.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_occupation_field() {
		self::render_gravity_field_id_input( 'gravity_field_occupation', __( 'Enter the Gravity Forms field ID that contains the applicant occupation.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_phone_field() {
		self::render_gravity_field_id_input( 'gravity_field_phone', __( 'Enter the Gravity Forms field ID that contains the applicant phone number.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_reason_field() {
		self::render_gravity_field_id_input( 'gravity_field_reason', __( 'Enter the Gravity Forms field ID that contains the application notes, bio, or reason for applying.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_address_street_field() {
		self::render_gravity_field_id_input( 'gravity_field_address_street', __( 'For the current form this is typically `4.1`.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_address_city_field() {
		self::render_gravity_field_id_input( 'gravity_field_address_city', __( 'For the current form this is typically `4.3`.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_address_state_field() {
		self::render_gravity_field_id_input( 'gravity_field_address_state', __( 'For the current form this is typically `4.4`.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_address_zip_field() {
		self::render_gravity_field_id_input( 'gravity_field_address_zip', __( 'For the current form this is typically `4.5`.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_nominator_field() {
		self::render_gravity_field_id_input( 'gravity_field_nominator', __( 'Who nominated the applicant. For the current form this is typically `8`.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_quarterly_commitment_field() {
		self::render_gravity_field_id_input( 'gravity_field_quarterly_commitment', __( 'Checkbox or choice field for quarterly fee commitment. For the current form this is typically `11.1`.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_nonprofit_experience_field() {
		self::render_gravity_field_id_input( 'gravity_field_nonprofit_experience', __( 'Field for prior community or nonprofit involvement. For the current form this is typically `12`.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_membership_commitment_field() {
		self::render_gravity_field_id_input( 'gravity_field_membership_commitment', __( 'Checkbox or choice field for membership responsibility commitment. For the current form this is typically `13.1`.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_photo_field() {
		self::render_gravity_field_id_input( 'gravity_field_photo', __( 'File upload field for the applicant photo. For the current form this is typically `14`.', 'strong-members-directory' ) );
	}

	public static function render_gravity_field_photo_release_field() {
		self::render_gravity_field_id_input( 'gravity_field_photo_release', __( 'Checkbox or choice field for the photo release. For the current form this is typically `15.1`.', 'strong-members-directory' ) );
	}

	/**
	 * Render Mailchimp API key field.
	 */
	public static function render_mailchimp_api_key_field() {
		$settings = self::get_settings();
		?>
		<input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mailchimp_api_key]" value="<?php echo esc_attr( (string) $settings['mailchimp_api_key'] ); ?>" autocomplete="off">
		<p class="description"><?php esc_html_e( 'Optional. If provided, fully onboarded members will be added to Mailchimp automatically.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render Mailchimp audience field.
	 */
	public static function render_mailchimp_audience_id_field() {
		$settings = self::get_settings();
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mailchimp_audience_id]" value="<?php echo esc_attr( (string) $settings['mailchimp_audience_id'] ); ?>">
		<p class="description"><?php esc_html_e( 'Optional. The target Mailchimp audience/list ID for new members.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render Mailchimp tags field.
	 */
	public static function render_mailchimp_tags_field() {
		$settings = self::get_settings();
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mailchimp_tags]" value="<?php echo esc_attr( (string) $settings['mailchimp_tags'] ); ?>">
		<p class="description"><?php esc_html_e( 'Optional. Comma-separated Mailchimp tags to add when a member finishes onboarding.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render spreadsheet webhook URL field.
	 */
	public static function render_spreadsheet_webhook_url_field() {
		$settings = self::get_settings();
		?>
		<input type="url" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[spreadsheet_webhook_url]" value="<?php echo esc_attr( (string) $settings['spreadsheet_webhook_url'] ); ?>">
		<p class="description"><?php esc_html_e( 'Optional. Send each fully onboarded member to a Google Sheets, Apps Script, Zapier, or Make webhook so the master spreadsheet can be updated automatically.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render email from name field.
	 */
	public static function render_email_from_name_field() {
		$settings = self::get_settings();
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_from_name]" value="<?php echo esc_attr( (string) $settings['email_from_name'] ); ?>">
		<p class="description"><?php esc_html_e( 'This is the display name applicants will see in the From field.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render email from address field.
	 */
	public static function render_email_from_address_field() {
		$settings = self::get_settings();
		?>
		<input type="email" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_from_address]" value="<?php echo esc_attr( (string) $settings['email_from_address'] ); ?>">
		<p class="description"><?php esc_html_e( 'Use an email address that is allowed by your site mail sender. If you use SMTP, this should usually match that mailbox or domain.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render workflow admin email field.
	 */
	public static function render_workflow_admin_email_field() {
		$settings = self::get_settings();
		?>
		<input type="email" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[workflow_admin_email]" value="<?php echo esc_attr( (string) $settings['workflow_admin_email'] ); ?>">
		<p class="description"><?php esc_html_e( 'This address receives the admin notification when an approved applicant completes billing and becomes a member.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render onboarding email subject field.
	 */
	public static function render_onboarding_email_subject_field() {
		$settings = self::get_settings();
		?>
		<input type="text" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[onboarding_email_subject]" value="<?php echo esc_attr( (string) $settings['onboarding_email_subject'] ); ?>">
		<p class="description"><?php esc_html_e( 'Available merge tags: {first_name}, {last_name}.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render onboarding email body field.
	 */
	public static function render_onboarding_email_body_field() {
		$settings = self::get_settings();
		?>
		<textarea rows="8" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[onboarding_email_body]"><?php echo esc_textarea( (string) $settings['onboarding_email_body'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Available merge tags: {first_name}, {last_name}, {full_name}, {onboarding_url}.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render welcome email subject field.
	 */
	public static function render_welcome_email_subject_field() {
		$settings = self::get_settings();
		?>
		<input type="text" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[welcome_email_subject]" value="<?php echo esc_attr( (string) $settings['welcome_email_subject'] ); ?>">
		<p class="description"><?php esc_html_e( 'Available merge tags: {first_name}, {last_name}.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render welcome email body field.
	 */
	public static function render_welcome_email_body_field() {
		$settings = self::get_settings();
		?>
		<textarea rows="8" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[welcome_email_body]"><?php echo esc_textarea( (string) $settings['welcome_email_body'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Available merge tags: {first_name}, {last_name}, {full_name}, {dashboard_url}.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render password setup email subject field.
	 */
	public static function render_password_setup_email_subject_field() {
		$settings = self::get_settings();
		?>
		<input type="text" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[password_setup_email_subject]" value="<?php echo esc_attr( (string) $settings['password_setup_email_subject'] ); ?>">
		<p class="description"><?php esc_html_e( 'Available merge tags: {first_name}, {last_name}, {full_name}, {username}, {site_name}.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render password setup email body field.
	 */
	public static function render_password_setup_email_body_field() {
		$settings = self::get_settings();
		?>
		<textarea rows="8" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[password_setup_email_body]"><?php echo esc_textarea( (string) $settings['password_setup_email_body'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Available merge tags: {first_name}, {last_name}, {full_name}, {username}, {reset_url}, {site_name}.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render cancellation email subject field.
	 */
	public static function render_membership_canceled_email_subject_field() {
		$settings = self::get_settings();
		?>
		<input type="text" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[membership_canceled_email_subject]" value="<?php echo esc_attr( (string) $settings['membership_canceled_email_subject'] ); ?>">
		<p class="description"><?php esc_html_e( 'Available merge tags: {first_name}, {last_name}, {full_name}.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render cancellation email body field.
	 */
	public static function render_membership_canceled_email_body_field() {
		$settings = self::get_settings();
		?>
		<textarea rows="8" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[membership_canceled_email_body]"><?php echo esc_textarea( (string) $settings['membership_canceled_email_body'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Available merge tags: {first_name}, {last_name}, {full_name}, {email}.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Check members-only mode.
	 *
	 * @return bool
	 */
	public static function members_only_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['members_only'] );
	}

	/**
	 * Check whether current request is for the configured directory page.
	 *
	 * @return bool
	 */
	public static function is_directory_page() {
		$settings = self::get_settings();
		return ! empty( $settings['directory_page_id'] ) && is_page( (int) $settings['directory_page_id'] );
	}

	/**
	 * Resolve directory URL.
	 *
	 * @return string
	 */
	public static function get_directory_url() {
		$settings = self::get_settings();

		if ( empty( $settings['directory_page_id'] ) ) {
			return '';
		}

		return (string) get_permalink( (int) $settings['directory_page_id'] );
	}

	/**
	 * Resolve dashboard URL.
	 *
	 * @return string
	 */
	public static function get_dashboard_url() {
		$settings = self::get_settings();

		if ( empty( $settings['dashboard_page_id'] ) ) {
			return '';
		}

		return (string) get_permalink( (int) $settings['dashboard_page_id'] );
	}

	/**
	 * Resolve nomination URL.
	 *
	 * @return string
	 */
	public static function get_nomination_url() {
		$settings = self::get_settings();

		if ( empty( $settings['nomination_page_id'] ) ) {
			return '';
		}

		return (string) get_permalink( (int) $settings['nomination_page_id'] );
	}

	/**
	 * Check whether current request is for the configured applicant onboarding page.
	 *
	 * @return bool
	 */
	public static function is_applicant_onboarding_page() {
		$settings = self::get_settings();
		return ! empty( $settings['applicant_onboarding_page_id'] ) && is_page( (int) $settings['applicant_onboarding_page_id'] );
	}

	/**
	 * Resolve applicant onboarding page URL.
	 *
	 * @return string
	 */
	public static function get_applicant_onboarding_url() {
		$settings = self::get_settings();

		if ( empty( $settings['applicant_onboarding_page_id'] ) ) {
			return '';
		}

		return (string) get_permalink( (int) $settings['applicant_onboarding_page_id'] );
	}

	/**
	 * Get the message shown to logged-out visitors.
	 *
	 * @return string
	 */
	public static function get_logged_out_message() {
		$settings = self::get_settings();
		return (string) $settings['logged_out_message'];
	}

	/**
	 * Ensure a default directory page exists.
	 *
	 * @return int
	 */
	public static function ensure_directory_page() {
		$settings = self::get_settings();
		if ( ! empty( $settings['directory_page_id'] ) && get_post( (int) $settings['directory_page_id'] ) ) {
			return (int) $settings['directory_page_id'];
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Member Directory', 'strong-members-directory' ),
				'post_name'    => 'member-directory',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[strong_members columns="4" limit="-1" order="ASC"]',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		$settings['directory_page_id'] = (int) $page_id;
		update_option( self::OPTION_KEY, $settings );

		return (int) $page_id;
	}

	/**
	 * Ensure a default member dashboard page exists.
	 *
	 * @return int
	 */
	public static function ensure_dashboard_page() {
		$settings = self::get_settings();
		if ( ! empty( $settings['dashboard_page_id'] ) && get_post( (int) $settings['dashboard_page_id'] ) ) {
			return (int) $settings['dashboard_page_id'];
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Member Dashboard', 'strong-members-directory' ),
				'post_name'    => 'member-dashboard',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[strong_member_dashboard]',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		$settings['dashboard_page_id'] = (int) $page_id;
		update_option( self::OPTION_KEY, $settings );

		return (int) $page_id;
	}

	/**
	 * Ensure a default nomination page exists.
	 *
	 * @return int
	 */
	public static function ensure_nomination_page() {
		$settings = self::get_settings();
		if ( ! empty( $settings['nomination_page_id'] ) && get_post( (int) $settings['nomination_page_id'] ) ) {
			return (int) $settings['nomination_page_id'];
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Nominate a Member', 'strong-members-directory' ),
				'post_name'    => 'nominate-a-member',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[strong_member_nomination]',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		$settings['nomination_page_id'] = (int) $page_id;
		update_option( self::OPTION_KEY, $settings );

		return (int) $page_id;
	}

	/**
	 * Ensure a default applicant onboarding page exists.
	 *
	 * @return int
	 */
	public static function ensure_applicant_onboarding_page() {
		$settings = self::get_settings();
		if ( ! empty( $settings['applicant_onboarding_page_id'] ) && get_post( (int) $settings['applicant_onboarding_page_id'] ) ) {
			return (int) $settings['applicant_onboarding_page_id'];
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Applicant Onboarding', 'strong-members-directory' ),
				'post_name'    => 'applicant-onboarding',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[strong_applicant_onboarding]',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		$settings['applicant_onboarding_page_id'] = (int) $page_id;
		update_option( self::OPTION_KEY, $settings );

		return (int) $page_id;
	}

	/**
	 * Ensure the directory page exists after updates as well.
	 */
	public static function maybe_create_directory_page() {
		$settings = self::get_settings();

		if ( ! empty( $settings['directory_page_id'] ) && get_post( (int) $settings['directory_page_id'] ) ) {
			self::maybe_upgrade_directory_page( (int) $settings['directory_page_id'] );
		} else {
			self::ensure_directory_page();
		}

		if ( empty( $settings['dashboard_page_id'] ) || ! get_post( (int) $settings['dashboard_page_id'] ) ) {
			self::ensure_dashboard_page();
		}

		if ( empty( $settings['nomination_page_id'] ) || ! get_post( (int) $settings['nomination_page_id'] ) ) {
			self::ensure_nomination_page();
		}

		if ( empty( $settings['applicant_onboarding_page_id'] ) || ! get_post( (int) $settings['applicant_onboarding_page_id'] ) ) {
			self::ensure_applicant_onboarding_page();
		}
	}

	/**
	 * Upgrade the seeded directory page content if it still uses the old default shortcode.
	 *
	 * @param int $page_id Directory page ID.
	 */
	private static function maybe_upgrade_directory_page( $page_id ) {
		$page = get_post( $page_id );

		if ( ! $page instanceof WP_Post ) {
			return;
		}

		if ( '[strong_members columns="3" limit="-1" order="ASC"]' !== trim( (string) $page->post_content ) ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => '[strong_members columns="4" limit="-1" order="ASC"]',
			)
		);
	}
}
