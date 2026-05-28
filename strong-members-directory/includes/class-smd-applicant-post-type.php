<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMD_Applicant_Post_Type {
	const POST_TYPE = 'smd_applicant';

	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_applicant_meta' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'register_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
	}

	/**
	 * Register the applicant custom post type.
	 */
	public static function register() {
		$labels = array(
			'name'               => __( 'Applicants', 'strong-members-directory' ),
			'singular_name'      => __( 'Applicant', 'strong-members-directory' ),
			'add_new'            => __( 'Add Applicant', 'strong-members-directory' ),
			'add_new_item'       => __( 'Add New Applicant', 'strong-members-directory' ),
			'edit_item'          => __( 'Edit Applicant', 'strong-members-directory' ),
			'new_item'           => __( 'New Applicant', 'strong-members-directory' ),
			'view_item'          => __( 'View Applicant', 'strong-members-directory' ),
			'search_items'       => __( 'Search Applicants', 'strong-members-directory' ),
			'not_found'          => __( 'No applicants found', 'strong-members-directory' ),
			'not_found_in_trash' => __( 'No applicants found in Trash', 'strong-members-directory' ),
			'menu_name'          => __( 'Applicants', 'strong-members-directory' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => $labels,
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . SMD_Member_Post_Type::POST_TYPE,
				'menu_icon'          => 'dashicons-id',
				'supports'           => array( 'title' ),
				'capability_type'    => 'post',
				'has_archive'        => false,
				'rewrite'            => false,
				'show_in_rest'       => false,
				'publicly_queryable' => false,
				'exclude_from_search'=> true,
			)
		);
	}

	/**
	 * Applicant workflow statuses.
	 *
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			'new_application'             => __( 'New Application', 'strong-members-directory' ),
			'board_review'                => __( 'Board Review', 'strong-members-directory' ),
			'approved_awaiting_interest'  => __( 'Approved - Awaiting Interest Confirmation', 'strong-members-directory' ),
			'approved_awaiting_billing'   => __( 'Approved - Awaiting Billing Setup', 'strong-members-directory' ),
			'onboarded'                   => __( 'Onboarded', 'strong-members-directory' ),
			'declined'                    => __( 'Declined', 'strong-members-directory' ),
		);
	}

	/**
	 * Get a status label.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function get_status_label( $status ) {
		$statuses = self::statuses();
		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
	}

	/**
	 * Register applicant meta boxes.
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'smd-applicant-details',
			__( 'Applicant Details', 'strong-members-directory' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Render applicant detail fields.
	 *
	 * @param WP_Post $post Applicant post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'smd_save_applicant', 'smd_applicant_nonce' );

		$fields = self::get_applicant_data( $post->ID );
		?>
		<div class="smd-admin-panel smd-admin-panel-tight">
		<table class="form-table smd-admin-form-table" role="presentation">
			<tr>
				<th scope="row"><label for="smd_applicant_first_name"><?php esc_html_e( 'First Name', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_first_name" name="smd_applicant_first_name" value="<?php echo esc_attr( $fields['first_name'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_last_name"><?php esc_html_e( 'Last Name', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_last_name" name="smd_applicant_last_name" value="<?php echo esc_attr( $fields['last_name'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_email"><?php esc_html_e( 'Email', 'strong-members-directory' ); ?></label></th>
				<td><input type="email" class="regular-text" id="smd_applicant_email" name="smd_applicant_email" value="<?php echo esc_attr( $fields['email'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_phone"><?php esc_html_e( 'Phone', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_phone" name="smd_applicant_phone" value="<?php echo esc_attr( $fields['phone'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_occupation"><?php esc_html_e( 'Occupation', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_occupation" name="smd_applicant_occupation" value="<?php echo esc_attr( $fields['occupation'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_reason"><?php esc_html_e( 'Application Notes', 'strong-members-directory' ); ?></label></th>
				<td><textarea class="large-text" rows="6" id="smd_applicant_reason" name="smd_applicant_reason"><?php echo esc_textarea( $fields['reason'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_address_street"><?php esc_html_e( 'Street Address', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_address_street" name="smd_applicant_address_street" value="<?php echo esc_attr( $fields['address_street'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_address_city"><?php esc_html_e( 'City', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_address_city" name="smd_applicant_address_city" value="<?php echo esc_attr( $fields['address_city'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_address_state"><?php esc_html_e( 'State / Region', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_address_state" name="smd_applicant_address_state" value="<?php echo esc_attr( $fields['address_state'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_address_zip"><?php esc_html_e( 'ZIP / Postal Code', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_address_zip" name="smd_applicant_address_zip" value="<?php echo esc_attr( $fields['address_zip'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_nominator"><?php esc_html_e( 'Nominated By', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_nominator" name="smd_applicant_nominator" value="<?php echo esc_attr( $fields['nominator'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_quarterly_commitment"><?php esc_html_e( 'Quarterly Fee Commitment', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_quarterly_commitment" name="smd_applicant_quarterly_commitment" value="<?php echo esc_attr( $fields['quarterly_commitment'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_nonprofit_experience"><?php esc_html_e( 'Nonprofit / Community Experience', 'strong-members-directory' ); ?></label></th>
				<td><textarea class="large-text" rows="4" id="smd_applicant_nonprofit_experience" name="smd_applicant_nonprofit_experience"><?php echo esc_textarea( $fields['nonprofit_experience'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_membership_commitment"><?php esc_html_e( 'Membership Commitment', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_membership_commitment" name="smd_applicant_membership_commitment" value="<?php echo esc_attr( $fields['membership_commitment'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_photo_release"><?php esc_html_e( 'Photo / Video Release', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_applicant_photo_release" name="smd_applicant_photo_release" value="<?php echo esc_attr( $fields['photo_release'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Applicant Photo', 'strong-members-directory' ); ?></th>
				<td>
					<?php if ( $fields['photo_url'] ) : ?>
						<p><a href="<?php echo esc_url( $fields['photo_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open uploaded photo', 'strong-members-directory' ); ?></a></p>
						<p><img src="<?php echo esc_url( $fields['photo_url'] ); ?>" alt="" style="max-width:180px;height:auto;border-radius:8px;"></p>
					<?php else : ?>
						<?php esc_html_e( 'No applicant photo captured yet.', 'strong-members-directory' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_status"><?php esc_html_e( 'Workflow Status', 'strong-members-directory' ); ?></label></th>
				<td>
					<select id="smd_applicant_status" name="smd_applicant_status">
						<?php foreach ( self::statuses() as $status_key => $status_label ) : ?>
							<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $fields['status'], $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_applicant_board_notes"><?php esc_html_e( 'Board Notes', 'strong-members-directory' ); ?></label></th>
				<td><textarea class="large-text" rows="4" id="smd_applicant_board_notes" name="smd_applicant_board_notes"><?php echo esc_textarea( $fields['board_notes'] ); ?></textarea></td>
			</tr>
		</table>
		</div>
		<div class="smd-admin-panel smd-admin-panel-tight">
		<table class="form-table smd-admin-form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Gravity Forms Entry', 'strong-members-directory' ); ?></th>
				<td><?php echo esc_html( $fields['gravity_entry_id'] ? (string) $fields['gravity_entry_id'] : __( 'Not linked', 'strong-members-directory' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Stripe Customer ID', 'strong-members-directory' ); ?></th>
				<td><?php echo esc_html( $fields['stripe_customer_id'] ? $fields['stripe_customer_id'] : __( 'Not created yet', 'strong-members-directory' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Stripe Subscription ID', 'strong-members-directory' ); ?></th>
				<td><?php echo esc_html( $fields['stripe_subscription_id'] ? $fields['stripe_subscription_id'] : __( 'Not started yet', 'strong-members-directory' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Billing Status', 'strong-members-directory' ); ?></th>
				<td><?php echo esc_html( $fields['billing_status'] ? $fields['billing_status'] : __( 'Not started', 'strong-members-directory' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Linked Member', 'strong-members-directory' ); ?></th>
				<td>
					<?php if ( $fields['linked_member_id'] ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( (int) $fields['linked_member_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $fields['linked_member_id'] ) ); ?></a>
					<?php else : ?>
						<?php esc_html_e( 'Not linked yet', 'strong-members-directory' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mailchimp Sync', 'strong-members-directory' ); ?></th>
				<td><?php echo esc_html( $fields['mailchimp_sync_note'] ? $fields['mailchimp_sync_note'] : __( 'Not attempted yet', 'strong-members-directory' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Spreadsheet Sync', 'strong-members-directory' ); ?></th>
				<td><?php echo esc_html( $fields['spreadsheet_sync_note'] ? $fields['spreadsheet_sync_note'] : __( 'Not attempted yet', 'strong-members-directory' ) ); ?></td>
			</tr>
		</table>
		</div>
		<?php
	}

	/**
	 * Save applicant fields.
	 *
	 * @param int $post_id Applicant post ID.
	 */
	public static function save_applicant_meta( $post_id ) {
		if ( ! isset( $_POST['smd_applicant_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['smd_applicant_nonce'] ) ), 'smd_save_applicant' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$data = array(
			'first_name'  => isset( $_POST['smd_applicant_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_first_name'] ) ) : '',
			'last_name'   => isset( $_POST['smd_applicant_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_last_name'] ) ) : '',
			'email'       => isset( $_POST['smd_applicant_email'] ) ? sanitize_email( wp_unslash( $_POST['smd_applicant_email'] ) ) : '',
			'phone'       => isset( $_POST['smd_applicant_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_phone'] ) ) : '',
			'occupation'  => isset( $_POST['smd_applicant_occupation'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_occupation'] ) ) : '',
			'reason'      => isset( $_POST['smd_applicant_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['smd_applicant_reason'] ) ) : '',
			'address_street' => isset( $_POST['smd_applicant_address_street'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_address_street'] ) ) : '',
			'address_city'   => isset( $_POST['smd_applicant_address_city'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_address_city'] ) ) : '',
			'address_state'  => isset( $_POST['smd_applicant_address_state'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_address_state'] ) ) : '',
			'address_zip'    => isset( $_POST['smd_applicant_address_zip'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_address_zip'] ) ) : '',
			'nominator'      => isset( $_POST['smd_applicant_nominator'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_nominator'] ) ) : '',
			'quarterly_commitment' => isset( $_POST['smd_applicant_quarterly_commitment'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_quarterly_commitment'] ) ) : '',
			'nonprofit_experience' => isset( $_POST['smd_applicant_nonprofit_experience'] ) ? sanitize_textarea_field( wp_unslash( $_POST['smd_applicant_nonprofit_experience'] ) ) : '',
			'membership_commitment' => isset( $_POST['smd_applicant_membership_commitment'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_membership_commitment'] ) ) : '',
			'photo_release'  => isset( $_POST['smd_applicant_photo_release'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_applicant_photo_release'] ) ) : '',
			'status'      => isset( $_POST['smd_applicant_status'] ) ? sanitize_key( wp_unslash( $_POST['smd_applicant_status'] ) ) : 'new_application',
			'board_notes' => isset( $_POST['smd_applicant_board_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['smd_applicant_board_notes'] ) ) : '',
		);

		self::update_applicant_data( $post_id, $data );
	}

	/**
	 * Update applicant details.
	 *
	 * @param int   $post_id Applicant post ID.
	 * @param array $data Applicant data.
	 */
	public static function update_applicant_data( $post_id, $data ) {
		$allowed_statuses = array_keys( self::statuses() );
		$status           = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'new_application';

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'new_application';
		}

		update_post_meta( $post_id, '_smd_applicant_first_name', isset( $data['first_name'] ) ? (string) $data['first_name'] : '' );
		update_post_meta( $post_id, '_smd_applicant_last_name', isset( $data['last_name'] ) ? (string) $data['last_name'] : '' );
		update_post_meta( $post_id, '_smd_applicant_email', isset( $data['email'] ) ? (string) $data['email'] : '' );
		update_post_meta( $post_id, '_smd_applicant_phone', isset( $data['phone'] ) ? (string) $data['phone'] : '' );
		update_post_meta( $post_id, '_smd_applicant_occupation', isset( $data['occupation'] ) ? (string) $data['occupation'] : '' );
		update_post_meta( $post_id, '_smd_applicant_reason', isset( $data['reason'] ) ? (string) $data['reason'] : '' );
		update_post_meta( $post_id, '_smd_applicant_address_street', isset( $data['address_street'] ) ? (string) $data['address_street'] : '' );
		update_post_meta( $post_id, '_smd_applicant_address_city', isset( $data['address_city'] ) ? (string) $data['address_city'] : '' );
		update_post_meta( $post_id, '_smd_applicant_address_state', isset( $data['address_state'] ) ? (string) $data['address_state'] : '' );
		update_post_meta( $post_id, '_smd_applicant_address_zip', isset( $data['address_zip'] ) ? (string) $data['address_zip'] : '' );
		update_post_meta( $post_id, '_smd_applicant_nominator', isset( $data['nominator'] ) ? (string) $data['nominator'] : '' );
		update_post_meta( $post_id, '_smd_applicant_quarterly_commitment', isset( $data['quarterly_commitment'] ) ? (string) $data['quarterly_commitment'] : '' );
		update_post_meta( $post_id, '_smd_applicant_nonprofit_experience', isset( $data['nonprofit_experience'] ) ? (string) $data['nonprofit_experience'] : '' );
		update_post_meta( $post_id, '_smd_applicant_membership_commitment', isset( $data['membership_commitment'] ) ? (string) $data['membership_commitment'] : '' );
		update_post_meta( $post_id, '_smd_applicant_photo_release', isset( $data['photo_release'] ) ? (string) $data['photo_release'] : '' );
		update_post_meta( $post_id, '_smd_applicant_status', $status );
		update_post_meta( $post_id, '_smd_applicant_board_notes', isset( $data['board_notes'] ) ? (string) $data['board_notes'] : '' );

		$title = trim( ( isset( $data['first_name'] ) ? (string) $data['first_name'] : '' ) . ' ' . ( isset( $data['last_name'] ) ? (string) $data['last_name'] : '' ) );
		if ( ! $title ) {
			$title = isset( $data['email'] ) ? (string) $data['email'] : __( 'Applicant', 'strong-members-directory' );
		}

		remove_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_applicant_meta' ) );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $title,
				'post_name'  => sanitize_title( $title ),
			)
		);
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_applicant_meta' ) );
	}

	/**
	 * Get applicant fields.
	 *
	 * @param int $post_id Applicant post ID.
	 * @return array<string, string|int>
	 */
	public static function get_applicant_data( $post_id ) {
		return array(
			'first_name'            => (string) get_post_meta( $post_id, '_smd_applicant_first_name', true ),
			'last_name'             => (string) get_post_meta( $post_id, '_smd_applicant_last_name', true ),
			'email'                 => (string) get_post_meta( $post_id, '_smd_applicant_email', true ),
			'phone'                 => (string) get_post_meta( $post_id, '_smd_applicant_phone', true ),
			'occupation'            => (string) get_post_meta( $post_id, '_smd_applicant_occupation', true ),
			'reason'                => (string) get_post_meta( $post_id, '_smd_applicant_reason', true ),
			'address_street'        => (string) get_post_meta( $post_id, '_smd_applicant_address_street', true ),
			'address_city'          => (string) get_post_meta( $post_id, '_smd_applicant_address_city', true ),
			'address_state'         => (string) get_post_meta( $post_id, '_smd_applicant_address_state', true ),
			'address_zip'           => (string) get_post_meta( $post_id, '_smd_applicant_address_zip', true ),
			'nominator'             => (string) get_post_meta( $post_id, '_smd_applicant_nominator', true ),
			'quarterly_commitment'  => (string) get_post_meta( $post_id, '_smd_applicant_quarterly_commitment', true ),
			'nonprofit_experience'  => (string) get_post_meta( $post_id, '_smd_applicant_nonprofit_experience', true ),
			'membership_commitment' => (string) get_post_meta( $post_id, '_smd_applicant_membership_commitment', true ),
			'photo_release'         => (string) get_post_meta( $post_id, '_smd_applicant_photo_release', true ),
			'photo_url'             => (string) get_post_meta( $post_id, '_smd_applicant_photo_url', true ),
			'status'                => (string) get_post_meta( $post_id, '_smd_applicant_status', true ) ?: 'new_application',
			'board_notes'           => (string) get_post_meta( $post_id, '_smd_applicant_board_notes', true ),
			'gravity_entry_id'      => (int) get_post_meta( $post_id, '_smd_applicant_gravity_entry_id', true ),
			'gravity_form_id'       => (int) get_post_meta( $post_id, '_smd_applicant_gravity_form_id', true ),
			'interest_confirmed_at' => (string) get_post_meta( $post_id, '_smd_applicant_interest_confirmed_at', true ),
			'billing_invited_at'    => (string) get_post_meta( $post_id, '_smd_applicant_billing_invited_at', true ),
			'billing_status'        => (string) get_post_meta( $post_id, '_smd_applicant_billing_status', true ),
			'stripe_customer_id'    => (string) get_post_meta( $post_id, '_smd_applicant_stripe_customer_id', true ),
			'stripe_subscription_id'=> (string) get_post_meta( $post_id, '_smd_applicant_stripe_subscription_id', true ),
			'linked_member_id'      => (int) get_post_meta( $post_id, '_smd_linked_member_id', true ),
			'onboarding_token'      => (string) get_post_meta( $post_id, '_smd_applicant_onboarding_token', true ),
			'mailchimp_sync_status' => (string) get_post_meta( $post_id, '_smd_mailchimp_sync_status', true ),
			'mailchimp_sync_note'   => (string) get_post_meta( $post_id, '_smd_mailchimp_sync_note', true ),
			'spreadsheet_sync_status'=> (string) get_post_meta( $post_id, '_smd_spreadsheet_sync_status', true ),
			'spreadsheet_sync_note' => (string) get_post_meta( $post_id, '_smd_spreadsheet_sync_note', true ),
		);
	}

	/**
	 * Register admin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function register_admin_columns( $columns ) {
		$updated = array();

		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'title' === $key ) {
				$updated['smd_applicant_email']   = __( 'Email', 'strong-members-directory' );
				$updated['smd_applicant_status']  = __( 'Status', 'strong-members-directory' );
				$updated['smd_applicant_billing'] = __( 'Billing', 'strong-members-directory' );
				$updated['smd_applicant_member']  = __( 'Linked Member', 'strong-members-directory' );
			}
		}

		return $updated;
	}

	/**
	 * Render admin columns.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Applicant post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		$fields = self::get_applicant_data( $post_id );

		if ( 'smd_applicant_email' === $column ) {
			echo esc_html( $fields['email'] );
			return;
		}

		if ( 'smd_applicant_status' === $column ) {
			echo '<span class="smd-admin-status-badge smd-admin-status-' . esc_attr( sanitize_html_class( $fields['status'] ) ) . '">' . esc_html( self::get_status_label( $fields['status'] ) ) . '</span>';
			return;
		}

		if ( 'smd_applicant_billing' === $column ) {
			echo '<span class="smd-admin-status-badge smd-admin-status-billing">' . esc_html( $fields['billing_status'] ? $fields['billing_status'] : __( 'Not started', 'strong-members-directory' ) ) . '</span>';
			return;
		}

		if ( 'smd_applicant_member' === $column ) {
			if ( ! empty( $fields['linked_member_id'] ) ) {
				echo '<a href="' . esc_url( get_edit_post_link( (int) $fields['linked_member_id'] ) ) . '">' . esc_html( get_the_title( (int) $fields['linked_member_id'] ) ) . '</a>';
			} else {
				esc_html_e( 'Not linked', 'strong-members-directory' );
			}
		}
	}
}
