<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMD_Auth {
	const ROLE = 'smd_member';
	const MANAGED_PROFILE_IMAGE_META = '_smd_managed_profile_image';

	/**
	 * Register auth-related hooks.
	 */
	public static function hooks() {
		add_filter( 'login_redirect', array( __CLASS__, 'redirect_after_login' ), 10, 3 );
		add_filter( 'retrieve_password_notification_email', array( __CLASS__, 'filter_member_password_setup_email' ), 10, 4 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'maybe_hide_admin_bar' ) );
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'add_auth_link_to_navigation' ), 10, 2 );
		add_filter( 'render_block', array( __CLASS__, 'add_auth_link_to_navigation_block' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'filter_member_content' ) );
		add_filter( 'the_title', array( __CLASS__, 'filter_member_titles_for_guests' ), 10, 2 );
		add_filter( 'template_include', array( __CLASS__, 'use_protected_member_template' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_member_queries_for_guests' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'block_member_rest_access' ), 10, 3 );
		add_action( 'admin_post_smd_sync_member_logins', array( __CLASS__, 'handle_sync_member_logins' ) );
		add_action( 'admin_post_smd_resend_member_password_setups', array( __CLASS__, 'handle_resend_password_setups' ) );
		add_action( 'admin_post_smd_update_member_profile', array( __CLASS__, 'handle_frontend_member_update' ) );
	}

	/**
	 * Register custom role.
	 */
	public static function register_role() {
		add_role(
			self::ROLE,
			__( 'Member', 'strong-members-directory' ),
			array(
				'read' => true,
			)
		);
	}

	/**
	 * Remove role on deactivation.
	 */
	public static function remove_role() {
		remove_role( self::ROLE );
	}

	/**
	 * Create or find a WordPress user for a member.
	 *
	 * @param int    $member_id Member post ID.
	 * @param string $email Member email.
	 * @param string $first_name Member first name.
	 * @param string $last_name Member last name.
	 * @return int|WP_Error
	 */
	public static function ensure_member_user( $member_id, $email, $first_name, $last_name ) {
		if ( ! $email || ! is_email( $email ) ) {
			return 0;
		}

		$linked_user_id = (int) get_post_meta( $member_id, '_smd_user_id', true );

		if ( $linked_user_id && get_user_by( 'id', $linked_user_id ) ) {
			self::sync_member_user_profile( $linked_user_id, $member_id, $email, $first_name, $last_name );
			return $linked_user_id;
		}

		$user = get_user_by( 'email', $email );

		if ( $user ) {
			update_post_meta( $member_id, '_smd_user_id', (int) $user->ID );
			update_user_meta( $user->ID, '_smd_member_id', $member_id );
			self::sync_member_user_profile( (int) $user->ID, $member_id, $email, $first_name, $last_name );
			return (int) $user->ID;
		}

		$username = self::generate_username( $first_name, $last_name, $email );
		$password = wp_generate_password( 20, true, true );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => $password,
				'user_email'   => $email,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
				'role'         => self::ROLE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_post_meta( $member_id, '_smd_user_id', (int) $user_id );
		update_user_meta( $user_id, '_smd_member_id', $member_id );
		retrieve_password( get_user_by( 'id', $user_id )->user_login );

		return (int) $user_id;
	}

	/**
	 * Update core user profile data.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $member_id Member post ID.
	 * @param string $email Member email.
	 * @param string $first_name First name.
	 * @param string $last_name Last name.
	 */
	public static function sync_member_user_profile( $user_id, $member_id, $email, $first_name, $last_name ) {
		$user_data = array(
			'ID'           => $user_id,
			'user_email'   => $email,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
		);

		$current_user = get_userdata( $user_id );
		if ( $current_user && ! in_array( self::ROLE, (array) $current_user->roles, true ) ) {
			$user_data['role'] = self::ROLE;
		}

		wp_update_user( $user_data );
		update_post_meta( $member_id, '_smd_user_id', $user_id );
		update_user_meta( $user_id, '_smd_member_id', $member_id );
	}

	/**
	 * Remove member-specific access from a linked WordPress user.
	 *
	 * @param int $member_id Member post ID.
	 */
	public static function revoke_member_user_access( $member_id ) {
		$user_id = (int) get_post_meta( $member_id, '_smd_user_id', true );

		if ( ! $user_id ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		delete_user_meta( $user_id, '_smd_member_id' );

		if ( in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->remove_role( self::ROLE );

			if ( empty( $user->roles ) ) {
				$user->add_role( 'subscriber' );
			}
		}
	}

	/**
	 * Redirect logged-in users to the configured directory page.
	 *
	 * @param string           $redirect_to Requested redirect target.
	 * @param string           $request Raw redirect request.
	 * @param WP_User|WP_Error $user User object.
	 * @return string
	 */
	public static function redirect_after_login( $redirect_to, $request, $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		$dashboard_url = SMD_Settings::get_dashboard_url();
		if ( ! $dashboard_url ) {
			return $redirect_to;
		}

		return $dashboard_url;
	}

	/**
	 * Hide the frontend admin bar for member users.
	 *
	 * @param bool $show Whether WordPress plans to show the admin bar.
	 * @return bool
	 */
	public static function maybe_hide_admin_bar( $show ) {
		if ( is_admin() ) {
			return $show;
		}

		return self::is_member_user() ? false : $show;
	}

	/**
	 * Add a login/logout link to the primary navigation menu.
	 *
	 * @param string   $items Menu markup.
	 * @param stdClass $args Menu args.
	 * @return string
	 */
	public static function add_auth_link_to_navigation( $items, $args ) {
		if ( is_admin() || ! isset( $args->theme_location ) ) {
			return $items;
		}

		if ( false !== strpos( $items, 'smd-menu-auth-link' ) || false !== strpos( $items, 'smd-menu-dashboard-link' ) ) {
			return $items;
		}

		if ( empty( $items ) ) {
			return $items;
		}

		if ( is_user_logged_in() ) {
			$items .= self::get_classic_menu_dashboard_item_markup();
		}

		return $items . self::get_classic_menu_auth_item_markup();
	}

	/**
	 * Add a login/logout link to Navigation block output.
	 *
	 * @param string $block_content Rendered block content.
	 * @param array  $block Parsed block.
	 * @return string
	 */
	public static function add_auth_link_to_navigation_block( $block_content, $block ) {
		if ( is_admin() || empty( $block['blockName'] ) || 'core/navigation' !== $block['blockName'] ) {
			return $block_content;
		}

		if ( false !== strpos( $block_content, 'smd-menu-auth-link' ) || false !== strpos( $block_content, 'smd-menu-dashboard-link' ) ) {
			return $block_content;
		}

		$menu_items = '';

		if ( is_user_logged_in() ) {
			$menu_items .= self::get_block_menu_dashboard_item_markup();
		}

		$menu_items .= self::get_block_menu_auth_item_markup();

		if ( false !== strpos( $block_content, '</ul>' ) ) {
			return preg_replace( '/<\/ul>(?!.*<\/ul>)/', $menu_items . '</ul>', $block_content, 1 );
		}

		return $block_content . $menu_items;
	}

	/**
	 * Hide member content inside post/page bodies when not logged in.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_member_content( $content ) {
		if ( is_user_logged_in() || ! SMD_Settings::members_only_enabled() ) {
			return $content;
		}

		if ( is_singular( SMD_Member_Post_Type::POST_TYPE ) && in_the_loop() && is_main_query() ) {
			return SMD_Shortcodes::render_login_required();
		}

		if ( has_shortcode( $content, 'strong_members' ) || has_shortcode( $content, 'strong_member' ) ) {
			return SMD_Shortcodes::render_login_required();
		}

		return $content;
	}

	/**
	 * Hide member names from guest-facing titles.
	 *
	 * @param string $title Post title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function filter_member_titles_for_guests( $title, $post_id ) {
		if ( is_admin() || is_user_logged_in() || ! SMD_Settings::members_only_enabled() ) {
			return $title;
		}

		if ( SMD_Member_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return $title;
		}

		return __( 'Member Profile', 'strong-members-directory' );
	}

	/**
	 * Keep member profiles out of frontend queries for logged-out visitors.
	 *
	 * @param WP_Query $query Query instance.
	 */
	public static function hide_member_queries_for_guests( $query ) {
		if ( is_admin() || ! $query->is_main_query() || is_user_logged_in() || ! SMD_Settings::members_only_enabled() ) {
			return;
		}

		if ( $query->is_search() ) {
			$public_types = get_post_types(
				array(
					'public' => true,
				),
				'names'
			);

			unset( $public_types[ SMD_Member_Post_Type::POST_TYPE ] );
			$query->set( 'post_type', array_values( $public_types ) );
		}
	}

	/**
	 * Block REST access to member endpoints for logged-out visitors.
	 *
	 * @param mixed           $result Existing result.
	 * @param WP_REST_Server  $server REST server.
	 * @param WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public static function block_member_rest_access( $result, $server, $request ) {
		if ( is_user_logged_in() || ! SMD_Settings::members_only_enabled() ) {
			return $result;
		}

		$route = $request->get_route();

		if ( false === strpos( $route, '/wp/v2/' . SMD_Member_Post_Type::POST_TYPE ) ) {
			return $result;
		}

		return new WP_Error(
			'smd_members_login_required',
			__( 'You must be logged in to view member profiles.', 'strong-members-directory' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Use a plugin-controlled template for logged-out member profiles.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public static function use_protected_member_template( $template ) {
		if ( is_user_logged_in() || ! SMD_Settings::members_only_enabled() || ! is_singular( SMD_Member_Post_Type::POST_TYPE ) ) {
			return $template;
		}

		wp_enqueue_style( 'smd-frontend' );

		return SMD_PLUGIN_DIR . 'templates/protected-member.php';
	}

	/**
	 * Bulk sync member logins from the admin.
	 */
	public static function handle_sync_member_logins() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to sync member logins.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_sync_member_logins', 'smd_sync_member_logins_nonce' );

		$report = self::sync_all_member_logins();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'       => SMD_Member_Post_Type::POST_TYPE,
					'page'            => 'smd-settings',
					'smd_sync_report' => rawurlencode( wp_json_encode( $report ) ),
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Update the logged-in member's allowed frontend profile fields.
	 */
	public static function handle_frontend_member_update() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to update your profile.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_frontend_member_update', 'smd_frontend_member_update_nonce' );

		$member_id = isset( $_POST['member_id'] ) ? (int) $_POST['member_id'] : 0;
		$user_id   = get_current_user_id();

		if ( ! $member_id || ! self::user_can_edit_member( $user_id, $member_id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this member profile.', 'strong-members-directory' ) );
		}

		$fields     = SMD_Member_Post_Type::get_member_data( $member_id );
		$occupation = isset( $_POST['smd_occupation'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_occupation'] ) ) : '';
		$update_type = isset( $_POST['smd_update_type'] ) ? sanitize_key( wp_unslash( $_POST['smd_update_type'] ) ) : '';

		if ( 'image' !== $update_type ) {
			SMD_Member_Post_Type::update_member_meta(
				$member_id,
				(string) $fields['first_name'],
				(string) $fields['last_name'],
				(string) $fields['email'],
				$occupation
			);
		}

		$cropped_image_data = isset( $_POST['smd_cropped_image_data'] ) ? trim( (string) wp_unslash( $_POST['smd_cropped_image_data'] ) ) : '';

		$previous_attachment_id = (int) get_post_thumbnail_id( $member_id );

		if ( '' !== $cropped_image_data ) {
			$attachment_id = self::handle_cropped_frontend_image(
				$cropped_image_data,
				$member_id,
				(string) $fields['first_name'],
				(string) $fields['last_name']
			);

			if ( is_wp_error( $attachment_id ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'smd_edit_section'  => 'image',
							'smd_profile_error' => rawurlencode( $attachment_id->get_error_message() ),
						),
						get_permalink( $member_id )
					)
				);
				exit;
			}

			self::replace_member_profile_image( $member_id, (int) $attachment_id, $previous_attachment_id );
		} elseif ( ! empty( $_FILES['smd_profile_image']['name'] ) ) {
			$validation = self::validate_frontend_image_upload( $_FILES['smd_profile_image'] );

			if ( is_wp_error( $validation ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'smd_edit_section' => 'image',
							'smd_profile_error'=> rawurlencode( $validation->get_error_message() ),
						),
						get_permalink( $member_id )
					)
				);
				exit;
			}

			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$attachment_id = media_handle_upload( 'smd_profile_image', $member_id );

			if ( ! is_wp_error( $attachment_id ) ) {
				self::replace_member_profile_image( $member_id, (int) $attachment_id, $previous_attachment_id );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'smd_edit_section'    => false,
				),
				get_permalink( $member_id )
			)
		);
		exit;
	}

	/**
	 * Resend password setup emails to linked member users.
	 */
	public static function handle_resend_password_setups() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to resend member setup emails.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_resend_member_password_setups', 'smd_resend_member_password_setups_nonce' );

		$members = get_posts(
			array(
				'post_type'      => SMD_Member_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$sent    = 0;

		foreach ( $members as $member_id ) {
			$fields  = SMD_Member_Post_Type::get_member_data( (int) $member_id );
			$user_id = (int) $fields['user_id'];

			if ( ! $user_id ) {
				continue;
			}

			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				continue;
			}

			if ( retrieve_password( $user->user_login ) ) {
				++$sent;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'               => SMD_Member_Post_Type::POST_TYPE,
					'page'                    => 'smd-settings',
					'smd_resend_passwords'    => 1,
					'smd_resend_passwords_sent'=> $sent,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Create or link WordPress users for all members with valid email addresses.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_all_member_logins() {
		$members = get_posts(
			array(
				'post_type'      => SMD_Member_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$report = array(
			'created' => 0,
			'linked'  => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		foreach ( $members as $member_id ) {
			$member_id = (int) $member_id;
			$fields    = SMD_Member_Post_Type::get_member_data( $member_id );
			$email     = (string) $fields['email'];

			if ( ! $email || ! is_email( $email ) ) {
				++$report['skipped'];
				continue;
			}

			$linked_user_id = (int) $fields['user_id'];
			if ( $linked_user_id && get_user_by( 'id', $linked_user_id ) ) {
				++$report['linked'];
				continue;
			}

			$existing_user = get_user_by( 'email', $email );
			$result        = self::ensure_member_user(
				$member_id,
				$email,
				(string) $fields['first_name'],
				(string) $fields['last_name']
			);

			if ( is_wp_error( $result ) ) {
				$report['errors'][] = sprintf(
					/* translators: 1: member title, 2: error message */
					__( '%1$s: %2$s', 'strong-members-directory' ),
					get_the_title( $member_id ),
					$result->get_error_message()
				);
				continue;
			}

			if ( $existing_user ) {
				++$report['linked'];
			} else {
				++$report['created'];
			}
		}

		return $report;
	}

	/**
	 * Whether a user may edit a given member from the frontend.
	 *
	 * @param int $user_id User ID.
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	public static function user_can_edit_member( $user_id, $member_id ) {
		if ( ! $user_id || ! $member_id ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return (int) get_user_meta( $user_id, '_smd_member_id', true ) === (int) $member_id;
	}

	/**
	 * Whether the current user is a member account without admin privileges.
	 *
	 * @return bool
	 */
	private static function is_member_user() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' );
	}

	/**
	 * Customize the password setup/reset email for member users.
	 *
	 * @param array   $defaults Default email arguments.
	 * @param string  $key Reset key.
	 * @param string  $user_login Username.
	 * @param WP_User $user_data User object.
	 * @return array
	 */
	public static function filter_member_password_setup_email( $defaults, $key, $user_login, $user_data ) {
		if ( ! $user_data instanceof WP_User ) {
			return $defaults;
		}

		$is_member = in_array( self::ROLE, (array) $user_data->roles, true ) || (int) get_user_meta( $user_data->ID, '_smd_member_id', true );
		if ( ! $is_member ) {
			return $defaults;
		}

		$settings   = SMD_Settings::get_settings();
		$reset_url  = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login ), 'login' );
		$full_name  = trim( (string) $user_data->first_name . ' ' . (string) $user_data->last_name );
		$site_name  = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$tokens     = array(
			'{first_name}' => (string) $user_data->first_name,
			'{last_name}'  => (string) $user_data->last_name,
			'{full_name}'  => $full_name ? $full_name : (string) $user_data->display_name,
			'{username}'   => (string) $user_login,
			'{reset_url}'  => $reset_url,
			'{site_name}'  => $site_name,
		);
		$subject    = isset( $settings['password_setup_email_subject'] ) ? strtr( (string) $settings['password_setup_email_subject'], $tokens ) : $defaults['subject'];
		$message    = isset( $settings['password_setup_email_body'] ) ? strtr( (string) $settings['password_setup_email_body'], $tokens ) : $defaults['message'];
		$from_name  = ! empty( $settings['email_from_name'] ) ? (string) $settings['email_from_name'] : '100 Strong';
		$from_email = ! empty( $settings['email_from_address'] ) ? (string) $settings['email_from_address'] : (string) get_option( 'admin_email' );

		$defaults['subject'] = $subject;
		$defaults['message'] = $message;

		if ( $from_name && $from_email && is_email( $from_email ) ) {
			$defaults['headers'] = array( 'From: ' . $from_name . ' <' . $from_email . '>' );
		}

		return $defaults;
	}

	/**
	 * Build the login/logout link info.
	 *
	 * @return array<string, string>
	 */
	private static function get_auth_link_data() {
		$is_logged_in = is_user_logged_in();

		return array(
			'url'        => $is_logged_in ? wp_logout_url( home_url( '/' ) ) : wp_login_url( home_url( '/members/' ) ),
			'label'      => $is_logged_in ? __( 'Log Out', 'strong-members-directory' ) : __( 'Log In', 'strong-members-directory' ),
			'class_name' => $is_logged_in ? 'smd-menu-auth-link smd-menu-auth-link-logout' : 'smd-menu-auth-link smd-menu-auth-link-login',
		);
	}

	/**
	 * Build the dashboard link info.
	 *
	 * @return array<string, string>
	 */
	private static function get_dashboard_link_data() {
		$dashboard_url = SMD_Settings::get_dashboard_url();

		return array(
			'url'        => $dashboard_url ? $dashboard_url : home_url( '/member-dashboard/' ),
			'label'      => __( 'My Dashboard', 'strong-members-directory' ),
			'class_name' => 'smd-menu-dashboard-link',
		);
	}

	/**
	 * Classic menu item markup.
	 *
	 * @return string
	 */
	private static function get_classic_menu_auth_item_markup() {
		$data = self::get_auth_link_data();

		return sprintf(
			'<li class="menu-item menu-item-type-custom menu-item-object-custom %1$s"><a class="elementor-item" href="%2$s">%3$s</a></li>',
			esc_attr( $data['class_name'] ),
			esc_url( $data['url'] ),
			esc_html( $data['label'] )
		);
	}

	/**
	 * Navigation block item markup.
	 *
	 * @return string
	 */
	private static function get_block_menu_auth_item_markup() {
		$data = self::get_auth_link_data();

		return sprintf(
			'<li class="wp-block-navigation-item menu-item menu-item-type-custom menu-item-object-custom %1$s"><a class="wp-block-navigation-item__content elementor-item" href="%2$s"><span class="wp-block-navigation-item__label">%3$s</span></a></li>',
			esc_attr( $data['class_name'] ),
			esc_url( $data['url'] ),
			esc_html( $data['label'] )
		);
	}

	/**
	 * Classic menu dashboard item markup.
	 *
	 * @return string
	 */
	private static function get_classic_menu_dashboard_item_markup() {
		$data = self::get_dashboard_link_data();

		return sprintf(
			'<li class="menu-item menu-item-type-custom menu-item-object-custom %1$s"><a class="elementor-item" href="%2$s">%3$s</a></li>',
			esc_attr( $data['class_name'] ),
			esc_url( $data['url'] ),
			esc_html( $data['label'] )
		);
	}

	/**
	 * Navigation block dashboard item markup.
	 *
	 * @return string
	 */
	private static function get_block_menu_dashboard_item_markup() {
		$data = self::get_dashboard_link_data();

		return sprintf(
			'<li class="wp-block-navigation-item menu-item menu-item-type-custom menu-item-object-custom %1$s"><a class="wp-block-navigation-item__content elementor-item" href="%2$s"><span class="wp-block-navigation-item__label">%3$s</span></a></li>',
			esc_attr( $data['class_name'] ),
			esc_url( $data['url'] ),
			esc_html( $data['label'] )
		);
	}

	/**
	 * Validate frontend image uploads.
	 *
	 * @param array $file Uploaded file entry.
	 * @return true|WP_Error
	 */
	private static function validate_frontend_image_upload( $file ) {
		$max_size = 5 * 1024 * 1024;

		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'smd_image_upload_error', __( 'The image upload did not complete successfully.', 'strong-members-directory' ) );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
			return new WP_Error( 'smd_image_too_large', __( 'Please upload an image smaller than 5 MB.', 'strong-members-directory' ) );
		}

		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$allowed  = array( 'image/jpeg', 'image/png', 'image/webp' );

		if ( empty( $filetype['type'] ) || ! in_array( $filetype['type'], $allowed, true ) ) {
			return new WP_Error( 'smd_invalid_image_type', __( 'Please upload a JPG, PNG, or WebP image.', 'strong-members-directory' ) );
		}

		return true;
	}

	/**
	 * Create an attachment from a cropped frontend image data URI.
	 *
	 * @param string $cropped_image_data Data URI for the cropped image.
	 * @param int    $member_id Member ID.
	 * @param string $first_name Member first name.
	 * @param string $last_name Member last name.
	 * @return int|WP_Error
	 */
	private static function handle_cropped_frontend_image( $cropped_image_data, $member_id, $first_name, $last_name ) {
		if ( ! preg_match( '#^data:(image/(?:jpeg|png|webp));base64,(.+)$#', $cropped_image_data, $matches ) ) {
			return new WP_Error( 'smd_invalid_cropped_image', __( 'The cropped image could not be read.', 'strong-members-directory' ) );
		}

		$mime_type = strtolower( (string) $matches[1] );
		$binary    = base64_decode( str_replace( ' ', '+', (string) $matches[2] ), true );

		if ( false === $binary || empty( $binary ) ) {
			return new WP_Error( 'smd_invalid_cropped_image_data', __( 'The cropped image was empty.', 'strong-members-directory' ) );
		}

		if ( strlen( $binary ) > 6 * 1024 * 1024 ) {
			return new WP_Error( 'smd_cropped_image_too_large', __( 'Please upload an image smaller than 6 MB.', 'strong-members-directory' ) );
		}

		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		);

		if ( ! isset( $extensions[ $mime_type ] ) ) {
			return new WP_Error( 'smd_invalid_cropped_image_type', __( 'Please upload a JPG, PNG, or WebP image.', 'strong-members-directory' ) );
		}

		$base_name = sanitize_file_name( trim( $first_name . '-' . $last_name ) );
		if ( '' === $base_name ) {
			$base_name = 'member-photo';
		}

		$filename = wp_unique_filename(
			wp_get_upload_dir()['path'],
			$base_name . '-cropped.' . $extensions[ $mime_type ]
		);
		$upload   = wp_upload_bits( $filename, null, $binary );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'smd_cropped_image_upload_failed', $upload['error'] );
		}

		$file_path = $upload['file'];
		$image_info = wp_getimagesize( $file_path );

		if ( empty( $image_info['mime'] ) || $image_info['mime'] !== $mime_type ) {
			@unlink( $file_path );
			return new WP_Error( 'smd_invalid_cropped_image_file', __( 'The cropped image file was not valid.', 'strong-members-directory' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => sanitize_text_field( trim( $first_name . ' ' . $last_name ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path, $member_id );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $file_path );
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		update_post_meta( $attachment_id, self::MANAGED_PROFILE_IMAGE_META, 1 );

		return (int) $attachment_id;
	}

	/**
	 * Set a member's new profile image and remove the old managed one.
	 *
	 * @param int $member_id Member ID.
	 * @param int $new_attachment_id New attachment ID.
	 * @param int $previous_attachment_id Previous attachment ID.
	 */
	private static function replace_member_profile_image( $member_id, $new_attachment_id, $previous_attachment_id ) {
		if ( ! $new_attachment_id ) {
			return;
		}

		update_post_meta( $new_attachment_id, self::MANAGED_PROFILE_IMAGE_META, 1 );
		set_post_thumbnail( $member_id, $new_attachment_id );

		if ( $previous_attachment_id && $previous_attachment_id !== $new_attachment_id ) {
			self::maybe_delete_managed_profile_image( $previous_attachment_id, $member_id );
		}
	}

	/**
	 * Delete a managed profile image attachment when it has been replaced.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $member_id Member ID.
	 */
	private static function maybe_delete_managed_profile_image( $attachment_id, $member_id ) {
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return;
		}

		$is_managed = (bool) get_post_meta( $attachment_id, self::MANAGED_PROFILE_IMAGE_META, true );
		$parent_id  = (int) wp_get_post_parent_id( $attachment_id );

		if ( ! $is_managed || $parent_id !== $member_id ) {
			return;
		}

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Build a unique username.
	 *
	 * @param string $first_name First name.
	 * @param string $last_name Last name.
	 * @param string $email Email address.
	 * @return string
	 */
	private static function generate_username( $first_name, $last_name, $email ) {
		$base = sanitize_user( strtolower( $first_name . $last_name ), true );

		if ( ! $base ) {
			$base = sanitize_user( current( explode( '@', $email ) ), true );
		}

		if ( ! $base ) {
			$base = 'member';
		}

		$username = $base;
		$counter  = 1;

		while ( username_exists( $username ) ) {
			$username = $base . $counter;
			++$counter;
		}

		return $username;
	}
}
