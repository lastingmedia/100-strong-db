<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMD_Member_Post_Type {
	const POST_TYPE = 'smd_member';

	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_member_meta' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'register_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
	}

	/**
	 * Register the custom post type.
	 */
	public static function register() {
		$labels = array(
			'name'               => __( 'Members', 'strong-members-directory' ),
			'singular_name'      => __( 'Member', 'strong-members-directory' ),
			'add_new'            => __( 'Add Member', 'strong-members-directory' ),
			'add_new_item'       => __( 'Add New Member', 'strong-members-directory' ),
			'edit_item'          => __( 'Edit Member', 'strong-members-directory' ),
			'new_item'           => __( 'New Member', 'strong-members-directory' ),
			'view_item'          => __( 'View Member', 'strong-members-directory' ),
			'search_items'       => __( 'Search Members', 'strong-members-directory' ),
			'not_found'          => __( 'No members found', 'strong-members-directory' ),
			'not_found_in_trash' => __( 'No members found in Trash', 'strong-members-directory' ),
			'menu_name'          => __( 'Members', 'strong-members-directory' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => $labels,
				'public'             => true,
				'show_in_rest'       => true,
				'menu_icon'          => 'dashicons-groups',
				'supports'           => array( 'title', 'thumbnail', 'editor' ),
				'has_archive'        => false,
				'rewrite'            => array( 'slug' => 'members' ),
				'show_in_menu'       => true,
				'publicly_queryable' => true,
			)
		);
	}

	/**
	 * Register meta box.
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'smd-member-details',
			__( 'Member Details', 'strong-members-directory' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Render member detail fields.
	 *
	 * @param WP_Post $post Member post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'smd_save_member', 'smd_member_nonce' );

		$fields = self::get_member_data( $post->ID );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="smd_first_name"><?php esc_html_e( 'First Name', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_first_name" name="smd_first_name" value="<?php echo esc_attr( $fields['first_name'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_last_name"><?php esc_html_e( 'Last Name', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_last_name" name="smd_last_name" value="<?php echo esc_attr( $fields['last_name'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_email"><?php esc_html_e( 'Contact Email', 'strong-members-directory' ); ?></label></th>
				<td><input type="email" class="regular-text" id="smd_email" name="smd_email" value="<?php echo esc_attr( $fields['email'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_occupation"><?php esc_html_e( 'Occupation', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_occupation" name="smd_occupation" value="<?php echo esc_attr( $fields['occupation'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_member_type"><?php esc_html_e( 'Member Type', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_member_type" name="smd_member_type" value="<?php echo esc_attr( $fields['member_type'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_phone"><?php esc_html_e( 'Phone', 'strong-members-directory' ); ?></label></th>
				<td><input type="text" class="regular-text" id="smd_phone" name="smd_phone" value="<?php echo esc_attr( $fields['phone'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_website"><?php esc_html_e( 'Website', 'strong-members-directory' ); ?></label></th>
				<td><input type="url" class="regular-text" id="smd_website" name="smd_website" value="<?php echo esc_attr( $fields['website'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="smd_linkedin"><?php esc_html_e( 'LinkedIn', 'strong-members-directory' ); ?></label></th>
				<td><input type="url" class="regular-text" id="smd_linkedin" name="smd_linkedin" value="<?php echo esc_attr( $fields['linkedin'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Shown to other members', 'strong-members-directory' ); ?></th>
				<td>
					<?php $visibility = self::get_visibility( $post->ID ); ?>
					<?php foreach ( array( 'email' => __( 'Email', 'strong-members-directory' ), 'phone' => __( 'Phone', 'strong-members-directory' ), 'linkedin' => __( 'LinkedIn', 'strong-members-directory' ), 'website' => __( 'Website', 'strong-members-directory' ) ) as $vis_key => $vis_label ) : ?>
						<label style="display:inline-block;margin-right:14px"><input type="checkbox" name="smd_show[<?php echo esc_attr( $vis_key ); ?>]" value="1" <?php checked( $visibility[ $vis_key ] ); ?>> <?php echo esc_html( $vis_label ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Members choose this themselves on their profile. Administrators and the member always see everything.', 'strong-members-directory' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Member Login', 'strong-members-directory' ); ?></th>
				<td>
					<?php if ( ! empty( $fields['user_id'] ) ) : ?>
						<?php
						$user = get_userdata( (int) $fields['user_id'] );
						if ( $user ) :
							?>
							<p><?php echo esc_html( sprintf( __( 'Linked WordPress user: %s', 'strong-members-directory' ), $user->user_login ) ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<p><?php esc_html_e( 'No login is linked yet. Logins are created automatically during CSV import or when this member is saved with a valid email address. You can also bulk-create them under Members > Settings > Sync Member Logins.', 'strong-members-directory' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<p>
			<?php esc_html_e( 'Use the Featured Image panel for the profile picture and the main editor for any optional bio or notes.', 'strong-members-directory' ); ?>
		</p>
		<?php
	}

	/**
	 * Save member fields.
	 *
	 * @param int $post_id Member post ID.
	 */
	public static function save_member_meta( $post_id ) {
		if ( ! isset( $_POST['smd_member_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['smd_member_nonce'] ) ), 'smd_save_member' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$first_name = isset( $_POST['smd_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_first_name'] ) ) : '';
		$last_name  = isset( $_POST['smd_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_last_name'] ) ) : '';
		$email      = isset( $_POST['smd_email'] ) ? sanitize_email( wp_unslash( $_POST['smd_email'] ) ) : '';
		$occupation = isset( $_POST['smd_occupation'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_occupation'] ) ) : '';
		$extra      = array(
			'member_type' => isset( $_POST['smd_member_type'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_member_type'] ) ) : '',
			'phone'       => isset( $_POST['smd_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_phone'] ) ) : '',
			'website'     => isset( $_POST['smd_website'] ) ? esc_url_raw( wp_unslash( $_POST['smd_website'] ) ) : '',
			'linkedin'    => isset( $_POST['smd_linkedin'] ) ? esc_url_raw( wp_unslash( $_POST['smd_linkedin'] ) ) : '',
		);

		self::update_member_meta( $post_id, $first_name, $last_name, $email, $occupation, $extra );
		self::save_visibility( $post_id, isset( $_POST['smd_show'] ) && is_array( $_POST['smd_show'] ) ? array_map( 'absint', wp_unslash( $_POST['smd_show'] ) ) : array() );
	}

	/**
	 * Contact fields a member can choose to show to other members, with their defaults.
	 * Email and phone are private until the member opts in.
	 *
	 * @return array<string,bool>
	 */
	public static function visibility_defaults() {
		return array(
			'email'    => false,
			'phone'    => false,
			'linkedin' => true,
			'website'  => true,
		);
	}

	/**
	 * A member's visibility choices (falls back to the defaults).
	 *
	 * @param int $post_id Member ID.
	 * @return array<string,bool>
	 */
	public static function get_visibility( $post_id ) {
		$visibility = self::visibility_defaults();

		foreach ( $visibility as $field => $default ) {
			$stored = get_post_meta( $post_id, '_smd_show_' . $field, true );

			if ( '' !== $stored ) {
				$visibility[ $field ] = '1' === (string) $stored;
			}
		}

		return $visibility;
	}

	/**
	 * Save visibility choices from checkbox input (unchecked = hidden).
	 *
	 * @param int   $post_id Member ID.
	 * @param array $checked Map of field => truthy when checked.
	 */
	public static function save_visibility( $post_id, $checked ) {
		foreach ( array_keys( self::visibility_defaults() ) as $field ) {
			update_post_meta( $post_id, '_smd_show_' . $field, ! empty( $checked[ $field ] ) ? '1' : '0' );
		}
	}

	/**
	 * Whether the current viewer may see a contact field. Owners and admins always can.
	 *
	 * @param int    $post_id Member ID.
	 * @param string $field   email|phone|linkedin|website.
	 * @return bool
	 */
	public static function viewer_can_see( $post_id, $field ) {
		$visibility = self::get_visibility( $post_id );

		if ( ! empty( $visibility[ $field ] ) ) {
			return true;
		}

		return is_user_logged_in() && class_exists( 'SMD_Auth' ) && SMD_Auth::user_can_edit_member( get_current_user_id(), $post_id );
	}

	/**
	 * Update member data and title.
	 *
	 * @param int    $post_id Member post ID.
	 * @param string $first_name First name.
	 * @param string $last_name Last name.
	 * @param string $email Email address.
	 * @param string $occupation Occupation value.
	 * @param array  $extra Optional extra fields.
	 */
	public static function update_member_meta( $post_id, $first_name, $last_name, $email, $occupation, $extra = array() ) {
		update_post_meta( $post_id, '_smd_first_name', $first_name );
		update_post_meta( $post_id, '_smd_last_name', $last_name );
		update_post_meta( $post_id, '_smd_email', $email );
		update_post_meta( $post_id, '_smd_occupation', $occupation );
		update_post_meta( $post_id, '_smd_member_type', isset( $extra['member_type'] ) ? (string) $extra['member_type'] : (string) get_post_meta( $post_id, '_smd_member_type', true ) );
		update_post_meta( $post_id, '_smd_phone', isset( $extra['phone'] ) ? (string) $extra['phone'] : (string) get_post_meta( $post_id, '_smd_phone', true ) );
		update_post_meta( $post_id, '_smd_website', isset( $extra['website'] ) ? (string) $extra['website'] : (string) get_post_meta( $post_id, '_smd_website', true ) );
		update_post_meta( $post_id, '_smd_linkedin', isset( $extra['linkedin'] ) ? (string) $extra['linkedin'] : (string) get_post_meta( $post_id, '_smd_linkedin', true ) );

		if ( $email && class_exists( 'SMD_Auth' ) ) {
			SMD_Auth::ensure_member_user( $post_id, $email, $first_name, $last_name );
		}

		$title = trim( $first_name . ' ' . $last_name );

		if ( $title ) {
			remove_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_member_meta' ) );
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $title,
					'post_name'  => sanitize_title( $title ),
				)
			);
			add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_member_meta' ) );
		}
	}

	/**
	 * Member fields normalized.
	 *
	 * @param int $post_id Member post ID.
	 * @return array<string, string|int>
	 */
	public static function get_member_data( $post_id ) {
		return array(
			'first_name'            => (string) get_post_meta( $post_id, '_smd_first_name', true ),
			'last_name'             => (string) get_post_meta( $post_id, '_smd_last_name', true ),
			'email'                 => (string) get_post_meta( $post_id, '_smd_email', true ),
			'occupation'            => (string) get_post_meta( $post_id, '_smd_occupation', true ),
			'member_type'           => (string) get_post_meta( $post_id, '_smd_member_type', true ),
			'phone'                 => (string) get_post_meta( $post_id, '_smd_phone', true ),
			'website'               => (string) get_post_meta( $post_id, '_smd_website', true ),
			'linkedin'              => (string) get_post_meta( $post_id, '_smd_linkedin', true ),
			'user_id'               => (int) get_post_meta( $post_id, '_smd_user_id', true ),
			'stripe_customer_id'    => (string) get_post_meta( $post_id, '_smd_stripe_customer_id', true ),
			'stripe_subscription_id'=> (string) get_post_meta( $post_id, '_smd_stripe_subscription_id', true ),
			'billing_status'        => (string) get_post_meta( $post_id, '_smd_billing_status', true ),
			'billing_period_end'    => (string) get_post_meta( $post_id, '_smd_billing_period_end', true ),
			'payment_failed_at'     => (string) get_post_meta( $post_id, '_smd_payment_failed_at', true ),
			'payment_failed_note'   => (string) get_post_meta( $post_id, '_smd_payment_failed_note', true ),
		);
	}

	/**
	 * Register table columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function register_admin_columns( $columns ) {
		$updated = array();

		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'title' === $key ) {
				$updated['smd_email']       = __( 'Email', 'strong-members-directory' );
				$updated['smd_occupation']  = __( 'Occupation', 'strong-members-directory' );
				$updated['smd_member_type'] = __( 'Member Type', 'strong-members-directory' );
			}
		}

		return $updated;
	}

	/**
	 * Render table columns.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Member post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		$fields = self::get_member_data( $post_id );

		if ( 'smd_email' === $column ) {
			echo esc_html( (string) $fields['email'] );
		}

		if ( 'smd_occupation' === $column ) {
			echo esc_html( (string) $fields['occupation'] );
		}

		if ( 'smd_member_type' === $column ) {
			echo esc_html( (string) $fields['member_type'] );
		}
	}

	/**
	 * Find existing member by email or full name.
	 *
	 * @param string $email Email address.
	 * @param string $first_name First name.
	 * @param string $last_name Last name.
	 * @return int
	 */
	public static function find_existing_member( $email, $first_name, $last_name ) {
		if ( $email ) {
			$query = new WP_Query(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'meta_key'       => '_smd_email',
					'meta_value'     => $email,
				)
			);

			if ( ! empty( $query->posts[0] ) ) {
				return (int) $query->posts[0];
			}
		}

		$title = trim( $first_name . ' ' . $last_name );

		if ( ! $title ) {
			return 0;
		}

		$existing = get_page_by_title( $title, OBJECT, self::POST_TYPE );

		return $existing ? (int) $existing->ID : 0;
	}

	/**
	 * Collect distinct non-empty values for a member field.
	 *
	 * @param string $field Field key.
	 * @return string[]
	 */
	public static function get_distinct_field_values( $field ) {
		$post_ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$values   = array();

		foreach ( $post_ids as $post_id ) {
			$data  = self::get_member_data( (int) $post_id );
			$value = isset( $data[ $field ] ) ? trim( (string) $data[ $field ] ) : '';

			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		$values = array_values( array_unique( $values ) );
		natcasesort( $values );

		return array_values( $values );
	}
}
