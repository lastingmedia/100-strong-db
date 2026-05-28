<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMD_Applications {
	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'rename_applicants_submenu' ), 95 );
		add_action( 'admin_menu', array( __CLASS__, 'reorder_members_submenu' ), 99 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_export_meta_box' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_workflow_meta_box' ) );
		add_action( 'admin_post_smd_applicant_workflow_action', array( __CLASS__, 'handle_workflow_action' ) );
		add_action( 'admin_post_smd_export_applicant_pdf', array( __CLASS__, 'handle_export_applicant_pdf' ) );
		add_action( 'gform_after_submission', array( __CLASS__, 'maybe_capture_gravity_application' ), 10, 2 );
		add_shortcode( 'strong_applicant_onboarding', array( __CLASS__, 'render_applicant_onboarding_shortcode' ) );
	}

	/**
	 * Register admin dashboard page.
	 */
	public static function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=' . SMD_Member_Post_Type::POST_TYPE,
			__( 'Dashboard', 'strong-members-directory' ),
			__( 'Dashboard', 'strong-members-directory' ),
			'edit_posts',
			'smd-applications-dashboard',
			array( __CLASS__, 'render_admin_dashboard' )
		);
	}

	/**
	 * Rename the Applicants submenu label to App DB.
	 */
	public static function rename_applicants_submenu() {
		global $submenu;

		$parent = 'edit.php?post_type=' . SMD_Member_Post_Type::POST_TYPE;

		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		foreach ( $submenu[ $parent ] as &$item ) {
			if ( isset( $item[2] ) && 'edit.php?post_type=' . SMD_Applicant_Post_Type::POST_TYPE === $item[2] ) {
				$item[0] = __( 'App DB', 'strong-members-directory' );
			}
		}
		unset( $item );
	}

	/**
	 * Reorder the Members submenu to keep the workflow simple.
	 */
	public static function reorder_members_submenu() {
		global $submenu;

		$parent = 'edit.php?post_type=' . SMD_Member_Post_Type::POST_TYPE;

		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$ordered = array();

		foreach ( array( 'smd-applications-dashboard', 'edit.php?post_type=' . SMD_Member_Post_Type::POST_TYPE, 'post-new.php?post_type=' . SMD_Member_Post_Type::POST_TYPE, 'smd-import-members', 'edit.php?post_type=' . SMD_Applicant_Post_Type::POST_TYPE, 'smd-settings' ) as $target_slug ) {
			foreach ( $submenu[ $parent ] as $item ) {
				if ( isset( $item[2] ) && $item[2] === $target_slug ) {
					$ordered[] = $item;
				}
			}
		}

		foreach ( $submenu[ $parent ] as $item ) {
			if ( isset( $item[2] ) && ! in_array( $item[2], array_column( $ordered, 2 ), true ) ) {
				$ordered[] = $item;
			}
		}

		$submenu[ $parent ] = $ordered;
	}

	/**
	 * Register workflow actions metabox for applicants.
	 */
	public static function register_workflow_meta_box() {
		add_meta_box(
			'smd-applicant-workflow',
			__( 'Workflow Actions', 'strong-members-directory' ),
			array( __CLASS__, 'render_workflow_meta_box' ),
			SMD_Applicant_Post_Type::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Register export metabox for applicants.
	 */
	public static function register_export_meta_box() {
		add_meta_box(
			'smd-applicant-export',
			__( 'Export', 'strong-members-directory' ),
			array( __CLASS__, 'render_export_meta_box' ),
			SMD_Applicant_Post_Type::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render export metabox.
	 *
	 * @param WP_Post $post Applicant post.
	 */
	public static function render_export_meta_box( $post ) {
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'       => 'smd_export_applicant_pdf',
					'applicant_id' => (int) $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'smd_export_applicant_pdf_' . $post->ID
		);
		?>
		<div class="smd-admin-panel smd-admin-panel-tight">
			<div class="smd-admin-kicker"><?php esc_html_e( 'Application Export', 'strong-members-directory' ); ?></div>
			<p class="smd-admin-help-copy"><?php esc_html_e( 'Download a simple PDF snapshot of this applicant record.', 'strong-members-directory' ); ?></p>
			<a
				href="<?php echo esc_url( $export_url ); ?>"
				class="button button-secondary smd-admin-button smd-admin-workflow-link"
				style="width:100%;border:2px solid #0f4c81;color:#0f4c81;background:#ffffff;"
				onmouseover="this.style.setProperty('background','#154D7C','important');this.style.setProperty('color','#ffffff','important');this.style.setProperty('border-color','#154D7C','important');this.style.setProperty('-webkit-text-fill-color','#ffffff','important');"
				onfocus="this.style.setProperty('background','#154D7C','important');this.style.setProperty('color','#ffffff','important');this.style.setProperty('border-color','#154D7C','important');this.style.setProperty('-webkit-text-fill-color','#ffffff','important');"
				onmouseout="this.style.setProperty('background','#ffffff','important');this.style.setProperty('color','#0f4c81','important');this.style.setProperty('border-color','#0f4c81','important');this.style.setProperty('-webkit-text-fill-color','#0f4c81','important');"
				onblur="this.style.setProperty('background','#ffffff','important');this.style.setProperty('color','#0f4c81','important');this.style.setProperty('border-color','#0f4c81','important');this.style.setProperty('-webkit-text-fill-color','#0f4c81','important');"
			><?php esc_html_e( 'Export Applicant', 'strong-members-directory' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Render workflow actions metabox.
	 *
	 * @param WP_Post $post Applicant post.
	 */
	public static function render_workflow_meta_box( $post ) {
		$fields        = SMD_Applicant_Post_Type::get_applicant_data( $post->ID );
		$onboarding_url = self::get_applicant_onboarding_url( $post->ID );
		?>
		<div class="smd-admin-panel smd-admin-panel-tight">
			<div class="smd-admin-kicker"><?php esc_html_e( 'Workflow Status', 'strong-members-directory' ); ?></div>
			<div class="smd-admin-status-badge smd-admin-status-<?php echo esc_attr( sanitize_html_class( $fields['status'] ) ); ?>"><?php echo esc_html( SMD_Applicant_Post_Type::get_status_label( $fields['status'] ) ); ?></div>
			<?php if ( $onboarding_url ) : ?>
				<p class="smd-admin-inline-link"><a href="<?php echo esc_url( $onboarding_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open onboarding page', 'strong-members-directory' ); ?></a></p>
			<?php endif; ?>
		</div>
		<div class="smd-admin-action-grid">
			<?php self::render_workflow_button( $post->ID, 'move_to_review', __( 'Move to Board Review', 'strong-members-directory' ) ); ?>
			<?php self::render_workflow_button( $post->ID, 'approve_and_send', __( 'Approve and Send Onboarding Email', 'strong-members-directory' ) ); ?>
			<?php self::render_workflow_button( $post->ID, 'resend_onboarding', __( 'Resend Onboarding Email', 'strong-members-directory' ) ); ?>
			<?php self::render_workflow_button( $post->ID, 'confirm_interest', __( 'Mark Interest Confirmed', 'strong-members-directory' ) ); ?>
			<?php self::render_workflow_button( $post->ID, 'decline', __( 'Decline Application', 'strong-members-directory' ) ); ?>
		</div>
		<p class="smd-admin-help-copy"><?php esc_html_e( 'Approved applicants should confirm interest and complete billing before they are converted into full members.', 'strong-members-directory' ); ?></p>
		<?php
	}

	/**
	 * Render one workflow button.
	 *
	 * @param int    $applicant_id Applicant ID.
	 * @param string $action_key Action key.
	 * @param string $label Button label.
	 */
	private static function render_workflow_button( $applicant_id, $action_key, $label ) {
		$action_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'              => 'smd_applicant_workflow_action',
					'smd_workflow_action' => $action_key,
					'applicant_id'        => (int) $applicant_id,
				),
				admin_url( 'admin-post.php' )
			),
			'smd_applicant_workflow_action_' . $action_key
		);
		?>
		<div class="smd-admin-action-form">
			<a
				href="<?php echo esc_url( $action_url ); ?>"
				class="button button-secondary smd-admin-button smd-admin-workflow-link"
				style="width:100%;border:2px solid #0f4c81;color:#0f4c81;background:#ffffff;"
				onmouseover="this.style.setProperty('background','#154D7C','important');this.style.setProperty('color','#ffffff','important');this.style.setProperty('border-color','#154D7C','important');this.style.setProperty('-webkit-text-fill-color','#ffffff','important');"
				onfocus="this.style.setProperty('background','#154D7C','important');this.style.setProperty('color','#ffffff','important');this.style.setProperty('border-color','#154D7C','important');this.style.setProperty('-webkit-text-fill-color','#ffffff','important');"
				onmouseout="this.style.setProperty('background','#ffffff','important');this.style.setProperty('color','#0f4c81','important');this.style.setProperty('border-color','#0f4c81','important');this.style.setProperty('-webkit-text-fill-color','#0f4c81','important');"
				onblur="this.style.setProperty('background','#ffffff','important');this.style.setProperty('color','#0f4c81','important');this.style.setProperty('border-color','#0f4c81','important');this.style.setProperty('-webkit-text-fill-color','#0f4c81','important');"
			><?php echo esc_html( $label ); ?></a>
		</div>
		<?php
	}

	/**
	 * Render admin workflow dashboard.
	 */
	public static function render_admin_dashboard() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the applications dashboard.', 'strong-members-directory' ) );
		}

		$counts         = self::get_status_counts();
		$pending_board  = self::get_applicants_by_statuses( array( 'new_application', 'board_review' ), 10 );
		$pending_next   = self::get_applicants_by_statuses( array( 'approved_awaiting_interest', 'approved_awaiting_billing' ), 10 );
		$status_update  = isset( $_GET['smd_status_update'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_status_update'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated_record = isset( $_GET['smd_applicant_id'] ) ? (int) $_GET['smd_applicant_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap smd-admin-wrap">
			<section class="smd-admin-hero">
				<div>
					<div class="smd-admin-kicker"><?php esc_html_e( 'Applications Workflow', 'strong-members-directory' ); ?></div>
					<h1><?php esc_html_e( 'Dashboard', 'strong-members-directory' ); ?></h1>
					<p><?php esc_html_e( 'Capture the applicant, review them, send onboarding, collect billing, and let the system convert paid approved applicants into real members.', 'strong-members-directory' ); ?></p>
				</div>
			</section>

			<?php if ( $status_update && $updated_record ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( sprintf( __( 'Applicant updated successfully. New status: %s', 'strong-members-directory' ), $status_update ) ); ?></p>
				</div>
			<?php endif; ?>

			<section class="smd-admin-summary-grid">
				<?php foreach ( SMD_Applicant_Post_Type::statuses() as $status_key => $status_label ) : ?>
					<article class="smd-admin-summary-card">
						<div class="smd-admin-kicker"><?php echo esc_html( $status_label ); ?></div>
						<div class="smd-admin-summary-count"><?php echo esc_html( (string) ( isset( $counts[ $status_key ] ) ? (int) $counts[ $status_key ] : 0 ) ); ?></div>
					</article>
				<?php endforeach; ?>
			</section>

			<section class="smd-admin-panel">
				<div class="smd-admin-section-head">
					<h2><?php esc_html_e( 'Needs Board Attention', 'strong-members-directory' ); ?></h2>
					<p><?php esc_html_e( 'Fresh submissions and records that still need a board decision.', 'strong-members-directory' ); ?></p>
				</div>
				<?php self::render_applicant_table( $pending_board ); ?>
			</section>

			<section class="smd-admin-panel">
				<div class="smd-admin-section-head">
					<h2><?php esc_html_e( 'Awaiting Applicant Action', 'strong-members-directory' ); ?></h2>
					<p><?php esc_html_e( 'Applicants who still need to confirm interest or finish billing setup.', 'strong-members-directory' ); ?></p>
				</div>
				<?php self::render_applicant_table( $pending_next ); ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Handle admin workflow actions.
	 */
	public static function handle_workflow_action() {
		$action_key   = isset( $_REQUEST['smd_workflow_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['smd_workflow_action'] ) ) : '';
		$applicant_id = isset( $_REQUEST['applicant_id'] ) ? (int) $_REQUEST['applicant_id'] : 0;

		if ( ! $applicant_id || SMD_Applicant_Post_Type::POST_TYPE !== get_post_type( $applicant_id ) ) {
			wp_die( esc_html__( 'Invalid applicant record.', 'strong-members-directory' ) );
		}

		if ( ! current_user_can( 'edit_post', $applicant_id ) ) {
			wp_die( esc_html__( 'You do not have permission to update this applicant.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_applicant_workflow_action_' . $action_key );

		$fields = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );

		switch ( $action_key ) {
			case 'move_to_review':
				update_post_meta( $applicant_id, '_smd_applicant_status', 'board_review' );
				break;

			case 'approve_and_send':
				update_post_meta( $applicant_id, '_smd_applicant_status', 'approved_awaiting_interest' );
				self::send_onboarding_email( $applicant_id );
				break;

			case 'resend_onboarding':
				self::send_onboarding_email( $applicant_id );
				break;

			case 'confirm_interest':
				self::mark_interest_confirmed( $applicant_id );
				break;

			case 'decline':
				update_post_meta( $applicant_id, '_smd_applicant_status', 'declined' );
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'         => SMD_Member_Post_Type::POST_TYPE,
					'page'              => 'smd-applications-dashboard',
					'smd_applicant_id'  => $applicant_id,
					'smd_status_update' => rawurlencode( SMD_Applicant_Post_Type::get_status_label( (string) get_post_meta( $applicant_id, '_smd_applicant_status', true ) ) ),
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Export a simple applicant PDF.
	 */
	public static function handle_export_applicant_pdf() {
		$applicant_id = isset( $_REQUEST['applicant_id'] ) ? (int) $_REQUEST['applicant_id'] : 0;

		if ( ! $applicant_id || SMD_Applicant_Post_Type::POST_TYPE !== get_post_type( $applicant_id ) ) {
			wp_die( esc_html__( 'Invalid applicant record.', 'strong-members-directory' ) );
		}

		if ( ! current_user_can( 'edit_post', $applicant_id ) ) {
			wp_die( esc_html__( 'You do not have permission to export this applicant.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_export_applicant_pdf_' . $applicant_id );

		$fields   = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );
		$pdf_data = self::build_applicant_pdf(
			array(
				__( 'Applicant Export', 'strong-members-directory' ),
				'',
				sprintf( 'Name: %s', trim( $fields['first_name'] . ' ' . $fields['last_name'] ) ),
				sprintf( 'Email: %s', (string) $fields['email'] ),
				sprintf( 'Phone: %s', (string) $fields['phone'] ),
				sprintf( 'Profession: %s', (string) $fields['occupation'] ),
				sprintf( 'Status: %s', SMD_Applicant_Post_Type::get_status_label( (string) $fields['status'] ) ),
				sprintf( 'Nominated By: %s', (string) $fields['nominator'] ),
				sprintf( 'Quarterly Fee Commitment: %s', (string) $fields['quarterly_commitment'] ),
				sprintf( 'Membership Commitment: %s', (string) $fields['membership_commitment'] ),
				sprintf( 'Photo / Video Release: %s', (string) $fields['photo_release'] ),
				'',
				'Address:',
				(string) $fields['address_street'],
				trim( (string) $fields['address_city'] . ', ' . (string) $fields['address_state'] . ' ' . (string) $fields['address_zip'] ),
				'',
				'Why they are interested:',
				(string) $fields['reason'],
				'',
				'Nonprofit / Community Experience:',
				(string) $fields['nonprofit_experience'],
				'',
				'Board Notes:',
				(string) $fields['board_notes'],
			)
		);

		$filename = sanitize_title( trim( $fields['first_name'] . ' ' . $fields['last_name'] ) ?: 'applicant' ) . '-application.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf_data ) );
		echo $pdf_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Capture an application from Gravity Forms if the configured form was submitted.
	 *
	 * @param array $entry Gravity Forms entry.
	 * @param array $form Gravity Forms form object.
	 */
	public static function maybe_capture_gravity_application( $entry, $form ) {
		$settings = SMD_Settings::get_settings();
		$form_id  = isset( $settings['gravity_form_id'] ) ? (int) $settings['gravity_form_id'] : 0;

		if ( ! $form_id || empty( $form['id'] ) || (int) $form['id'] !== $form_id ) {
			return;
		}

		$first_name = self::read_gf_value( $entry, isset( $settings['gravity_field_first_name'] ) ? (string) $settings['gravity_field_first_name'] : '' );
		$last_name  = self::read_gf_value( $entry, isset( $settings['gravity_field_last_name'] ) ? (string) $settings['gravity_field_last_name'] : '' );
		$email      = sanitize_email( self::read_gf_value( $entry, isset( $settings['gravity_field_email'] ) ? (string) $settings['gravity_field_email'] : '' ) );
		$occupation = self::read_gf_value( $entry, isset( $settings['gravity_field_occupation'] ) ? (string) $settings['gravity_field_occupation'] : '' );
		$phone      = self::read_gf_value( $entry, isset( $settings['gravity_field_phone'] ) ? (string) $settings['gravity_field_phone'] : '' );
		$reason     = self::read_gf_value( $entry, isset( $settings['gravity_field_reason'] ) ? (string) $settings['gravity_field_reason'] : '' );
		$address_street = self::read_gf_value( $entry, isset( $settings['gravity_field_address_street'] ) ? (string) $settings['gravity_field_address_street'] : '' );
		$address_city   = self::read_gf_value( $entry, isset( $settings['gravity_field_address_city'] ) ? (string) $settings['gravity_field_address_city'] : '' );
		$address_state  = self::read_gf_value( $entry, isset( $settings['gravity_field_address_state'] ) ? (string) $settings['gravity_field_address_state'] : '' );
		$address_zip    = self::read_gf_value( $entry, isset( $settings['gravity_field_address_zip'] ) ? (string) $settings['gravity_field_address_zip'] : '' );
		$nominator      = self::read_gf_value( $entry, isset( $settings['gravity_field_nominator'] ) ? (string) $settings['gravity_field_nominator'] : '' );
		$quarterly_commitment = self::read_gf_value( $entry, isset( $settings['gravity_field_quarterly_commitment'] ) ? (string) $settings['gravity_field_quarterly_commitment'] : '' );
		$nonprofit_experience = self::read_gf_value( $entry, isset( $settings['gravity_field_nonprofit_experience'] ) ? (string) $settings['gravity_field_nonprofit_experience'] : '' );
		$membership_commitment = self::read_gf_value( $entry, isset( $settings['gravity_field_membership_commitment'] ) ? (string) $settings['gravity_field_membership_commitment'] : '' );
		$photo_url            = self::read_gf_value( $entry, isset( $settings['gravity_field_photo'] ) ? (string) $settings['gravity_field_photo'] : '' );
		$photo_release        = self::read_gf_value( $entry, isset( $settings['gravity_field_photo_release'] ) ? (string) $settings['gravity_field_photo_release'] : '' );

		$applicant_id = wp_insert_post(
			array(
				'post_type'   => SMD_Applicant_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => trim( $first_name . ' ' . $last_name ) ? trim( $first_name . ' ' . $last_name ) : $email,
			),
			true
		);

		if ( is_wp_error( $applicant_id ) || ! $applicant_id ) {
			return;
		}

		SMD_Applicant_Post_Type::update_applicant_data(
			(int) $applicant_id,
			array(
				'first_name'  => $first_name,
				'last_name'   => $last_name,
				'email'       => $email,
				'phone'       => $phone,
				'occupation'  => $occupation,
				'reason'      => $reason,
				'address_street' => $address_street,
				'address_city'   => $address_city,
				'address_state'  => $address_state,
				'address_zip'    => $address_zip,
				'nominator'      => $nominator,
				'quarterly_commitment' => $quarterly_commitment,
				'nonprofit_experience' => $nonprofit_experience,
				'membership_commitment' => $membership_commitment,
				'photo_release' => $photo_release,
				'status'      => 'new_application',
				'board_notes' => '',
			)
		);

		update_post_meta( (int) $applicant_id, '_smd_applicant_gravity_entry_id', isset( $entry['id'] ) ? (int) $entry['id'] : 0 );
		update_post_meta( (int) $applicant_id, '_smd_applicant_gravity_form_id', $form_id );
		update_post_meta( (int) $applicant_id, '_smd_applicant_photo_url', esc_url_raw( $photo_url ) );
	}

	/**
	 * Render applicant onboarding frontend shortcode.
	 *
	 * @return string
	 */
	public static function render_applicant_onboarding_shortcode() {
		wp_enqueue_style( 'smd-frontend' );

		$token = isset( $_GET['smd_applicant_token'] ) ? sanitize_text_field( wp_unslash( $_GET['smd_applicant_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $token ) {
			return '<p class="smd-empty">' . esc_html__( 'This onboarding link is missing the applicant token.', 'strong-members-directory' ) . '</p>';
		}

		$applicant_id = self::find_applicant_by_token( $token );
		if ( ! $applicant_id ) {
			return '<p class="smd-empty">' . esc_html__( 'This onboarding link is no longer valid.', 'strong-members-directory' ) . '</p>';
		}

		if ( isset( $_GET['smd_applicant_action'] ) && 'confirm_interest' === sanitize_key( wp_unslash( $_GET['smd_applicant_action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::mark_interest_confirmed( $applicant_id );
		}

		$billing_notice = isset( $_GET['smd_billing_notice'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_billing_notice'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$billing_error  = isset( $_GET['smd_billing_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_billing_error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$session_id     = isset( $_GET['smd_stripe_session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['smd_stripe_session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( class_exists( 'SMD_Stripe' ) && ( '' !== $billing_notice || '' !== $session_id ) ) {
			SMD_Stripe::maybe_finalize_applicant_after_billing_return( $applicant_id, $session_id );
		}

		$fields         = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );
		$account_setup_url = self::get_applicant_account_setup_url( $applicant_id );
		$show_account_setup = '' !== $account_setup_url;
		$status_label = $show_account_setup ? SMD_Applicant_Post_Type::get_status_label( 'approved_awaiting_billing' ) : SMD_Applicant_Post_Type::get_status_label( $fields['status'] );

		if ( $show_account_setup && '' === $billing_notice ) {
			$billing_notice = __( 'Your billing setup was received. We are finishing your member onboarding now.', 'strong-members-directory' );
		}

		$confirm_url    = add_query_arg(
			array(
				'smd_applicant_token'  => $token,
				'smd_applicant_action' => 'confirm_interest',
			),
			SMD_Settings::get_applicant_onboarding_url()
		);
		$billing_url = class_exists( 'SMD_Stripe' ) ? SMD_Stripe::get_applicant_billing_action_url( $applicant_id ) : '';

		ob_start();
		?>
		<section class="smd-dashboard">
			<header class="smd-dashboard-header">
				<h1 class="smd-dashboard-title"><?php echo esc_html( sprintf( __( 'Welcome, %s', 'strong-members-directory' ), trim( $fields['first_name'] . ' ' . $fields['last_name'] ) ) ); ?></h1>
				<p class="smd-dashboard-copy"><?php esc_html_e( 'This page walks you through the final steps to become an active member. We use billing setup as the first onboarding gate after approval.', 'strong-members-directory' ); ?></p>
			</header>
			<div class="smd-dashboard-grid">
				<article class="smd-dashboard-card">
					<h2 class="smd-dashboard-card-title"><?php esc_html_e( 'Application Status', 'strong-members-directory' ); ?></h2>
					<p class="smd-dashboard-card-copy"><?php echo esc_html( $status_label ); ?></p>
					<?php if ( $billing_notice ) : ?>
						<p class="smd-member-billing-notice"><?php echo esc_html( $billing_notice ); ?></p>
					<?php endif; ?>
					<?php if ( $billing_error ) : ?>
						<p class="smd-member-profile-error"><?php echo esc_html( $billing_error ); ?></p>
					<?php endif; ?>
				</article>
				<article class="smd-dashboard-card">
					<h2 class="smd-dashboard-card-title"><?php esc_html_e( 'Next Step', 'strong-members-directory' ); ?></h2>
					<?php if ( 'declined' === $fields['status'] ) : ?>
						<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Your application is not moving forward at this time. Thank you again for your interest.', 'strong-members-directory' ); ?></p>
					<?php elseif ( $show_account_setup ) : ?>
						<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Your approval is confirmed. The next required step is setting up your official 100 Strong account.', 'strong-members-directory' ); ?></p>
						<a class="smd-dashboard-card-link smd-billing-button-link smd-billing-button" href="<?php echo esc_url( $account_setup_url ); ?>"><?php esc_html_e( 'Setup Account', 'strong-members-directory' ); ?></a>
					<?php elseif ( 'onboarded' === $fields['status'] ) : ?>
						<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Your onboarding is complete. Please check your email for login instructions and next steps.', 'strong-members-directory' ); ?></p>
					<?php elseif ( 'approved_awaiting_interest' === $fields['status'] ) : ?>
						<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Please confirm that you are still interested in becoming a member. Once you confirm, we will immediately move you into billing setup.', 'strong-members-directory' ); ?></p>
						<a class="smd-dashboard-card-link smd-billing-button-link smd-billing-button" href="<?php echo esc_url( $confirm_url ); ?>"><?php esc_html_e( 'Yes, I am still interested', 'strong-members-directory' ); ?></a>
					<?php elseif ( 'approved_awaiting_billing' === $fields['status'] ) : ?>
						<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Your approval is confirmed. The next required step is setting up your recurring membership billing.', 'strong-members-directory' ); ?></p>
						<?php if ( $billing_url ) : ?>
							<a class="smd-dashboard-card-link smd-billing-button-link smd-billing-button" href="<?php echo esc_url( $billing_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Set Up Membership Billing', 'strong-members-directory' ); ?></a>
						<?php endif; ?>
					<?php else : ?>
						<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Your application is still in review. We will email you as soon as the board has made a decision.', 'strong-members-directory' ); ?></p>
					<?php endif; ?>
				</article>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Finalize a paid applicant into a member record.
	 *
	 * @param int   $applicant_id Applicant ID.
	 * @param array $billing_data Stripe billing data.
	 * @return int Member ID.
	 */
	public static function finalize_paid_applicant_onboarding( $applicant_id, $billing_data = array() ) {
		$fields = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );

		if ( ! $applicant_id || 'declined' === $fields['status'] ) {
			return 0;
		}

		$member_id = (int) $fields['linked_member_id'];

		if ( ! $member_id || SMD_Member_Post_Type::POST_TYPE !== get_post_type( $member_id ) ) {
			$member_id = wp_insert_post(
				array(
					'post_type'   => SMD_Member_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => trim( $fields['first_name'] . ' ' . $fields['last_name'] ),
				),
				true
			);

			if ( is_wp_error( $member_id ) || ! $member_id ) {
				return 0;
			}
		}

		SMD_Member_Post_Type::update_member_meta(
			(int) $member_id,
			(string) $fields['first_name'],
			(string) $fields['last_name'],
			(string) $fields['email'],
			(string) $fields['occupation'],
			array(
				'phone' => (string) $fields['phone'],
			)
		);

		if ( ! empty( $billing_data['customer'] ) ) {
			update_post_meta( $member_id, '_smd_stripe_customer_id', sanitize_text_field( (string) $billing_data['customer'] ) );
		}

		if ( ! empty( $billing_data['subscription'] ) ) {
			update_post_meta( $member_id, '_smd_stripe_subscription_id', sanitize_text_field( (string) $billing_data['subscription'] ) );
		}

		if ( ! empty( $billing_data['status'] ) ) {
			update_post_meta( $member_id, '_smd_billing_status', sanitize_text_field( (string) $billing_data['status'] ) );
		}

		if ( ! empty( $billing_data['current_period_end'] ) ) {
			update_post_meta( $member_id, '_smd_billing_period_end', gmdate( 'c', (int) $billing_data['current_period_end'] ) );
		}

		if ( ! empty( $billing_data['status'] ) ) {
			update_post_meta( $applicant_id, '_smd_applicant_billing_status', sanitize_text_field( (string) $billing_data['status'] ) );
		}

		update_post_meta( $applicant_id, '_smd_linked_member_id', (int) $member_id );
		update_post_meta( $applicant_id, '_smd_applicant_status', 'onboarded' );
		update_post_meta( $applicant_id, '_smd_applicant_member_activated_at', current_time( 'mysql' ) );

		if ( 'private' !== get_post_status( $applicant_id ) ) {
			wp_update_post(
				array(
					'ID'          => (int) $applicant_id,
					'post_status' => 'private',
				)
			);
		}

		self::sync_to_mailchimp( $applicant_id, $member_id );
		self::sync_to_spreadsheet( $applicant_id, $member_id );
		self::send_welcome_email( $applicant_id, $member_id );
		self::send_admin_onboarding_notification( $applicant_id, $member_id );

		return (int) $member_id;
	}

	/**
	 * Return the applicant onboarding URL with token.
	 *
	 * @param int $applicant_id Applicant ID.
	 * @return string
	 */
	public static function get_applicant_onboarding_url( $applicant_id ) {
		$base_url = SMD_Settings::get_applicant_onboarding_url();
		if ( ! $base_url ) {
			return '';
		}

		return add_query_arg(
			array(
				'smd_applicant_token' => self::get_or_create_onboarding_token( $applicant_id ),
			),
			$base_url
		);
	}

	/**
	 * Build a stable admin edit URL for an applicant record.
	 *
	 * @param int $applicant_id Applicant ID.
	 * @return string
	 */
	public static function get_applicant_edit_url( $applicant_id ) {
		return add_query_arg(
			array(
				'post'   => (int) $applicant_id,
				'action' => 'edit',
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * Find applicant by onboarding token.
	 *
	 * @param string $token Onboarding token.
	 * @return int
	 */
	public static function find_applicant_by_token( $token ) {
		$query = new WP_Query(
			array(
				'post_type'      => SMD_Applicant_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_smd_applicant_onboarding_token',
				'meta_value'     => $token,
			)
		);

		return ! empty( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Mark an applicant as still interested.
	 *
	 * @param int $applicant_id Applicant ID.
	 */
	public static function mark_interest_confirmed( $applicant_id ) {
		update_post_meta( $applicant_id, '_smd_applicant_interest_confirmed_at', current_time( 'mysql' ) );
		update_post_meta( $applicant_id, '_smd_applicant_status', 'approved_awaiting_billing' );
		update_post_meta( $applicant_id, '_smd_applicant_billing_invited_at', current_time( 'mysql' ) );
	}

	/**
	 * Send the applicant onboarding email.
	 *
	 * @param int $applicant_id Applicant ID.
	 */
	private static function send_onboarding_email( $applicant_id ) {
		$fields = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );
		$email  = isset( $fields['email'] ) ? (string) $fields['email'] : '';
		$url    = self::get_applicant_onboarding_url( $applicant_id );

		if ( '' === $email || ! is_email( $email ) || '' === $url ) {
			return;
		}

		$tokens = array(
			'{first_name}'     => $fields['first_name'] ? (string) $fields['first_name'] : __( 'there', 'strong-members-directory' ),
			'{last_name}'      => (string) $fields['last_name'],
			'{full_name}'      => trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] ),
			'{onboarding_url}' => $url,
		);

		self::send_configured_email(
			$email,
			'onboarding_email_subject',
			'onboarding_email_body',
			$tokens
		);
	}

	/**
	 * Send the welcome email after full onboarding.
	 *
	 * @param int $applicant_id Applicant ID.
	 * @param int $member_id Member ID.
	 */
	private static function send_welcome_email( $applicant_id, $member_id ) {
		if ( get_post_meta( $applicant_id, '_smd_welcome_email_sent_at', true ) ) {
			return;
		}

		$fields = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );
		$email  = isset( $fields['email'] ) ? (string) $fields['email'] : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$dashboard_url = SMD_Settings::get_dashboard_url();
		$tokens        = array(
			'{first_name}'    => $fields['first_name'] ? (string) $fields['first_name'] : __( 'there', 'strong-members-directory' ),
			'{last_name}'     => (string) $fields['last_name'],
			'{full_name}'     => trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] ),
			'{dashboard_url}' => $dashboard_url ? $dashboard_url : home_url( '/' ),
		);

		$sent = self::send_configured_email(
			$email,
			'welcome_email_subject',
			'welcome_email_body',
			$tokens
		);

		if ( $sent ) {
			update_post_meta( $applicant_id, '_smd_welcome_email_sent_at', current_time( 'mysql' ) );
		}
	}

	/**
	 * Notify the workflow admin that billing is complete and the applicant is now a member.
	 *
	 * @param int $applicant_id Applicant ID.
	 * @param int $member_id Member ID.
	 */
	private static function send_admin_onboarding_notification( $applicant_id, $member_id ) {
		if ( get_post_meta( $applicant_id, '_smd_admin_onboarding_notice_sent_at', true ) ) {
			return;
		}

		$settings = SMD_Settings::get_settings();
		$to       = ! empty( $settings['workflow_admin_email'] ) ? sanitize_email( (string) $settings['workflow_admin_email'] ) : sanitize_email( (string) get_option( 'admin_email' ) );
		$fields   = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );

		if ( ! $to || ! is_email( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: member name */
			__( '%s completed billing and is now a member', 'strong-members-directory' ),
			trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] )
		);
		$message = sprintf(
			/* translators: 1: member name, 2: email, 3: member edit URL, 4: applicant edit URL */
			__( "The applicant %1\$s has completed billing and has been converted into a full member.\n\nEmail: %2\$s\n\nMember record:\n%3\$s\n\nApplicant record:\n%4\$s", 'strong-members-directory' ),
			trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] ),
			(string) $fields['email'],
			get_edit_post_link( $member_id ),
			self::get_applicant_edit_url( $applicant_id )
		);

		if ( self::send_raw_email( $to, $subject, $message ) ) {
			update_post_meta( $applicant_id, '_smd_admin_onboarding_notice_sent_at', current_time( 'mysql' ) );
		}
	}

	/**
	 * Sync the new member into Mailchimp if configured.
	 *
	 * @param int $applicant_id Applicant ID.
	 * @param int $member_id Member ID.
	 */
	private static function sync_to_mailchimp( $applicant_id, $member_id ) {
		$settings   = SMD_Settings::get_settings();
		$api_key    = isset( $settings['mailchimp_api_key'] ) ? trim( (string) $settings['mailchimp_api_key'] ) : '';
		$audience   = isset( $settings['mailchimp_audience_id'] ) ? trim( (string) $settings['mailchimp_audience_id'] ) : '';
		$tag_string = isset( $settings['mailchimp_tags'] ) ? trim( (string) $settings['mailchimp_tags'] ) : '';
		$fields     = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );

		if ( '' === $api_key || '' === $audience ) {
			update_post_meta( $applicant_id, '_smd_mailchimp_sync_status', 'skipped' );
			update_post_meta( $applicant_id, '_smd_mailchimp_sync_note', __( 'Mailchimp is not configured yet.', 'strong-members-directory' ) );
			return;
		}

		$email = isset( $fields['email'] ) ? strtolower( trim( (string) $fields['email'] ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			update_post_meta( $applicant_id, '_smd_mailchimp_sync_status', 'error' );
			update_post_meta( $applicant_id, '_smd_mailchimp_sync_note', __( 'Applicant email was missing, so Mailchimp sync was skipped.', 'strong-members-directory' ) );
			return;
		}

		$data_center = strstr( $api_key, '-' );
		if ( false === $data_center || '' === $data_center ) {
			update_post_meta( $applicant_id, '_smd_mailchimp_sync_status', 'error' );
			update_post_meta( $applicant_id, '_smd_mailchimp_sync_note', __( 'Mailchimp API key format is invalid.', 'strong-members-directory' ) );
			return;
		}

		$response = wp_remote_request(
			'https://' . ltrim( $data_center, '-' ) . '.api.mailchimp.com/3.0/lists/' . rawurlencode( $audience ) . '/members/' . md5( $email ),
			array(
				'method'  => 'PUT',
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email_address' => $email,
						'status_if_new' => 'subscribed',
						'status'        => 'subscribed',
						'merge_fields'  => array(
							'FNAME' => (string) $fields['first_name'],
							'LNAME' => (string) $fields['last_name'],
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			update_post_meta( $applicant_id, '_smd_mailchimp_sync_status', 'error' );
			update_post_meta( $applicant_id, '_smd_mailchimp_sync_note', $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			update_post_meta( $applicant_id, '_smd_mailchimp_sync_status', 'error' );
			update_post_meta( $applicant_id, '_smd_mailchimp_sync_note', wp_remote_retrieve_body( $response ) );
			return;
		}

		$tags = array_filter( array_map( 'trim', explode( ',', $tag_string ) ) );
		if ( ! empty( $tags ) ) {
			wp_remote_post(
				'https://' . ltrim( $data_center, '-' ) . '.api.mailchimp.com/3.0/lists/' . rawurlencode( $audience ) . '/members/' . md5( $email ) . '/tags',
				array(
					'timeout' => 20,
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'tags' => array_map(
								function ( $tag ) {
									return array(
										'name'   => $tag,
										'status' => 'active',
									);
								},
								$tags
							),
						)
					),
				)
			);
		}

		update_post_meta( $applicant_id, '_smd_mailchimp_sync_status', 'synced' );
		update_post_meta( $applicant_id, '_smd_mailchimp_sync_note', __( 'Mailchimp contact synced successfully.', 'strong-members-directory' ) );
	}

	/**
	 * Sync the new member into a spreadsheet automation webhook if configured.
	 *
	 * @param int $applicant_id Applicant ID.
	 * @param int $member_id Member ID.
	 */
	private static function sync_to_spreadsheet( $applicant_id, $member_id ) {
		$settings    = SMD_Settings::get_settings();
		$webhook_url = isset( $settings['spreadsheet_webhook_url'] ) ? trim( (string) $settings['spreadsheet_webhook_url'] ) : '';
		$fields      = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );

		if ( '' === $webhook_url ) {
			update_post_meta( $applicant_id, '_smd_spreadsheet_sync_status', 'skipped' );
			update_post_meta( $applicant_id, '_smd_spreadsheet_sync_note', __( 'Spreadsheet webhook is not configured yet.', 'strong-members-directory' ) );
			return;
		}

		$response = wp_remote_post(
			$webhook_url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'applicant_id' => $applicant_id,
						'member_id'    => $member_id,
						'first_name'   => (string) $fields['first_name'],
						'last_name'    => (string) $fields['last_name'],
						'email'        => (string) $fields['email'],
						'phone'        => (string) $fields['phone'],
						'occupation'   => (string) $fields['occupation'],
						'status'       => 'onboarded',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			update_post_meta( $applicant_id, '_smd_spreadsheet_sync_status', 'error' );
			update_post_meta( $applicant_id, '_smd_spreadsheet_sync_note', $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			update_post_meta( $applicant_id, '_smd_spreadsheet_sync_status', 'error' );
			update_post_meta( $applicant_id, '_smd_spreadsheet_sync_note', wp_remote_retrieve_body( $response ) );
			return;
		}

		update_post_meta( $applicant_id, '_smd_spreadsheet_sync_status', 'synced' );
		update_post_meta( $applicant_id, '_smd_spreadsheet_sync_note', __( 'Spreadsheet automation webhook accepted the member payload.', 'strong-members-directory' ) );
	}

	/**
	 * Build a workflow summary by status.
	 *
	 * @return array<string, int>
	 */
	private static function get_status_counts() {
		$counts = array_fill_keys( array_keys( SMD_Applicant_Post_Type::statuses() ), 0 );
		$query  = new WP_Query(
			array(
				'post_type'      => SMD_Applicant_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $query->posts as $applicant_id ) {
			$status = (string) get_post_meta( (int) $applicant_id, '_smd_applicant_status', true );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}

	/**
	 * Return applicant IDs for the given statuses.
	 *
	 * @param array $statuses Statuses.
	 * @param int   $limit Limit.
	 * @return array<int, int>
	 */
	private static function get_applicants_by_statuses( $statuses, $limit ) {
		$query = new WP_Query(
			array(
				'post_type'      => SMD_Applicant_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_smd_applicant_status',
						'value'   => $statuses,
						'compare' => 'IN',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Render a small applicant table.
	 *
	 * @param array<int, int> $applicant_ids Applicant IDs.
	 */
	private static function render_applicant_table( $applicant_ids ) {
		if ( empty( $applicant_ids ) ) {
			echo '<p class="smd-admin-empty">' . esc_html__( 'No applicants in this bucket right now.', 'strong-members-directory' ) . '</p>';
			return;
		}

		?>
		<table class="widefat striped smd-admin-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Applicant', 'strong-members-directory' ); ?></th>
					<th><?php esc_html_e( 'Email', 'strong-members-directory' ); ?></th>
					<th><?php esc_html_e( 'Status', 'strong-members-directory' ); ?></th>
					<th><?php esc_html_e( 'Billing', 'strong-members-directory' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'strong-members-directory' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $applicant_ids as $applicant_id ) : ?>
					<?php $fields = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id ); ?>
					<tr>
						<td>
							<a class="smd-admin-table-title" href="<?php echo esc_url( self::get_applicant_edit_url( $applicant_id ) ); ?>"><?php echo esc_html( get_the_title( $applicant_id ) ); ?></a>
							<?php if ( $fields['occupation'] ) : ?>
								<div class="smd-admin-table-subcopy"><?php echo esc_html( $fields['occupation'] ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $fields['email'] ); ?></td>
						<td><span class="smd-admin-status-badge smd-admin-status-<?php echo esc_attr( sanitize_html_class( $fields['status'] ) ); ?>"><?php echo esc_html( SMD_Applicant_Post_Type::get_status_label( $fields['status'] ) ); ?></span></td>
						<td><span class="smd-admin-status-badge smd-admin-status-billing"><?php echo esc_html( $fields['billing_status'] ? $fields['billing_status'] : __( 'Not started', 'strong-members-directory' ) ); ?></span></td>
						<td><a class="button button-secondary smd-admin-button-inline" href="<?php echo esc_url( self::get_applicant_edit_url( $applicant_id ) ); ?>"><?php esc_html_e( 'Open Applicant', 'strong-members-directory' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Read a Gravity Forms field value.
	 *
	 * @param array  $entry Entry.
	 * @param string $field_id Field ID.
	 * @return string
	 */
	private static function read_gf_value( $entry, $field_id ) {
		if ( '' === $field_id || ! isset( $entry[ $field_id ] ) ) {
			return '';
		}

		return sanitize_text_field( (string) $entry[ $field_id ] );
	}

	/**
	 * Get or create the onboarding token for an applicant.
	 *
	 * @param int $applicant_id Applicant ID.
	 * @return string
	 */
	private static function get_or_create_onboarding_token( $applicant_id ) {
		$token = (string) get_post_meta( $applicant_id, '_smd_applicant_onboarding_token', true );

		if ( '' === $token ) {
			$token = wp_generate_password( 32, false, false );
			update_post_meta( $applicant_id, '_smd_applicant_onboarding_token', $token );
		}

		return $token;
	}

	/**
	 * Build a very simple text-only PDF document.
	 *
	 * @param array<int, string> $lines Document lines.
	 * @return string
	 */
	private static function build_applicant_pdf( $lines ) {
		$prepared_lines = array();

		foreach ( $lines as $line ) {
			$line = trim( preg_replace( "/[\r\n\t]+/", ' ', (string) $line ) );
			if ( '' === $line ) {
				$prepared_lines[] = '';
				continue;
			}

			$wrapped = wordwrap( $line, 92, "\n", true );
			foreach ( explode( "\n", $wrapped ) as $wrapped_line ) {
				$prepared_lines[] = $wrapped_line;
			}
		}

		$content = "BT\n/F1 11 Tf\n50 790 Td\n14 TL\n";

		foreach ( $prepared_lines as $index => $line ) {
			if ( 0 !== $index ) {
				$content .= "T*\n";
			}

			$escaped = str_replace(
				array( '\\', '(', ')' ),
				array( '\\\\', '\\(', '\\)' ),
				self::pdf_clean_text( $line )
			);
			$content .= '(' . $escaped . ") Tj\n";
		}

		$content .= "ET";

		$objects   = array();
		$objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
		$objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
		$objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
		$objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
		$objects[] = "<< /Length " . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";

		$pdf     = "%PDF-1.4\n";
		$offsets = array();

		foreach ( $objects as $index => $object ) {
			$object_number = $index + 1;
			$offsets[ $object_number ] = strlen( $pdf );
			$pdf .= $object_number . " 0 obj\n" . $object . "\nendobj\n";
		}

		$xref_position = strlen( $pdf );
		$pdf          .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n";
		$pdf          .= "0000000000 65535 f \n";

		for ( $i = 1; $i <= count( $objects ); $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}

		$pdf .= "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref_position . "\n%%EOF";

		return $pdf;
	}

	/**
	 * Reduce text to safe PDF-friendly characters.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function pdf_clean_text( $text ) {
		$clean = wp_strip_all_tags( (string) $text );
		$clean = html_entity_decode( $clean, ENT_QUOTES, 'UTF-8' );

		if ( function_exists( 'iconv' ) ) {
			$converted = iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $clean );
			if ( false !== $converted ) {
				$clean = $converted;
			}
		}

		return preg_replace( '/[^\x20-\x7E\x80-\xFF]/', '', $clean );
	}

	/**
	 * Send a configurable member workflow email.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject_key Settings subject key.
	 * @param string $body_key Settings body key.
	 * @param array  $tokens Merge tag replacements.
	 */
	private static function send_configured_email( $to, $subject_key, $body_key, $tokens ) {
		$settings      = SMD_Settings::get_settings();
		$subject       = isset( $settings[ $subject_key ] ) ? (string) $settings[ $subject_key ] : '';
		$body          = isset( $settings[ $body_key ] ) ? (string) $settings[ $body_key ] : '';
		$final_subject = strtr( $subject, $tokens );
		$final_body    = strtr( $body, $tokens );
		return self::send_raw_email( $to, $final_subject, $final_body );
	}

	/**
	 * Send an email using the configured From identity.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Email subject.
	 * @param string $body Email body.
	 * @return bool
	 */
	private static function send_raw_email( $to, $subject, $body ) {
		$settings     = SMD_Settings::get_settings();
		$from_name    = ! empty( $settings['email_from_name'] ) ? (string) $settings['email_from_name'] : '100 Strong';
		$from_address = ! empty( $settings['email_from_address'] ) ? (string) $settings['email_from_address'] : (string) get_option( 'admin_email' );
		$headers      = array();

		if ( $from_name && $from_address && is_email( $from_address ) ) {
			$headers[] = 'From: ' . $from_name . ' <' . $from_address . '>';
		}

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Build a direct account setup URL for a newly created applicant user.
	 *
	 * @param int $applicant_id Applicant ID.
	 * @return string
	 */
	private static function get_applicant_account_setup_url( $applicant_id ) {
		$fields    = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );
		$member_id = (int) $fields['linked_member_id'];

		if ( ! $member_id || SMD_Member_Post_Type::POST_TYPE !== get_post_type( $member_id ) ) {
			return '';
		}

		$member_fields = SMD_Member_Post_Type::get_member_data( $member_id );
		$user_id       = ! empty( $member_fields['user_id'] ) ? (int) $member_fields['user_id'] : 0;

		if ( ! $user_id ) {
			return '';
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return '';
		}

		$reset_key = get_password_reset_key( $user );
		if ( is_wp_error( $reset_key ) || ! $reset_key ) {
			return wp_lostpassword_url();
		}

		return network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $reset_key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
	}
}
