<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMD_Shortcodes {
	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_shortcode( 'strong_members', array( __CLASS__, 'render_members_shortcode' ) );
		add_shortcode( 'strong_member', array( __CLASS__, 'render_single_member_shortcode' ) );
		add_shortcode( 'strong_member_dashboard', array( __CLASS__, 'render_member_dashboard_shortcode' ) );
		add_shortcode( 'strong_member_nomination', array( __CLASS__, 'render_member_nomination_shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'render_single_member_content' ), 20 );
		add_action( 'admin_post_smd_submit_member_nomination', array( __CLASS__, 'handle_member_nomination_submission' ) );
	}

	/**
	 * Render member listing shortcode.
	 *
	 * @param array $atts Shortcode atts.
	 * @return string
	 */
	public static function render_members_shortcode( $atts ) {
		wp_enqueue_style( 'smd-frontend' );

		if ( SMD_Settings::members_only_enabled() && ! is_user_logged_in() ) {
			return self::render_login_required();
		}

		$atts = shortcode_atts(
			array(
				'columns'      => 4,
				'limit'        => -1,
				'order'        => 'ASC',
				'show_filters' => 'yes',
				'member_type'  => '',
				'occupation'   => '',
			),
			$atts,
			'strong_members'
		);

		// Keep the main directory at a fixed four-column layout unless a different
		// page uses the shortcode intentionally elsewhere.
		if ( SMD_Settings::is_directory_page() ) {
			$atts['columns'] = 4;
		}

		$current_order  = isset( $_GET['smd_order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['smd_order'] ) ) ) : strtoupper( (string) $atts['order'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_order  = 'DESC' === $current_order ? 'DESC' : 'ASC';
		$current_search = isset( $_GET['smd_search'] ) ? sanitize_text_field( wp_unslash( $_GET['smd_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_type   = isset( $_GET['smd_member_type'] ) ? sanitize_text_field( wp_unslash( $_GET['smd_member_type'] ) ) : sanitize_text_field( (string) $atts['member_type'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_job    = isset( $_GET['smd_occupation_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['smd_occupation_filter'] ) ) : sanitize_text_field( (string) $atts['occupation'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$query_args = array(
			'post_type'      => SMD_Member_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => '' !== $current_search ? -1 : (int) $atts['limit'],
			'orderby'        => 'title',
			'order'          => $current_order,
		);

		$query = new WP_Query( $query_args );

		ob_start();

		if ( 'no' !== strtolower( (string) $atts['show_filters'] ) ) {
			$dashboard_url = SMD_Settings::get_dashboard_url();
			if ( $dashboard_url ) {
				printf(
					'<p class="smd-profile-back smd-dashboard-back"><a href="%s">&larr; %s</a></p>',
					esc_url( $dashboard_url ),
					esc_html__( 'Back to dashboard', 'strong-members-directory' )
				);
			}
			echo self::render_directory_filters( $current_order, $current_search, $current_type, $current_job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$member_ids = wp_list_pluck( $query->posts, 'ID' );

		if ( '' !== $current_search ) {
			$member_ids = array_values(
				array_filter(
					$member_ids,
					function ( $member_id ) use ( $current_search ) {
						return self::member_matches_search( (int) $member_id, $current_search );
					}
				)
			);

			if ( -1 !== (int) $atts['limit'] ) {
				$member_ids = array_slice( $member_ids, 0, (int) $atts['limit'] );
			}
		}

		if ( '' !== $current_type ) {
			$member_ids = array_values(
				array_filter(
					$member_ids,
					function ( $member_id ) use ( $current_type ) {
						$data = SMD_Member_Post_Type::get_member_data( (int) $member_id );
						return isset( $data['member_type'] ) && 0 === strcasecmp( trim( (string) $data['member_type'] ), $current_type );
					}
				)
			);
		}

		if ( '' !== $current_job ) {
			$member_ids = array_values(
				array_filter(
					$member_ids,
					function ( $member_id ) use ( $current_job ) {
						$data = SMD_Member_Post_Type::get_member_data( (int) $member_id );
						return isset( $data['occupation'] ) && 0 === strcasecmp( trim( (string) $data['occupation'] ), $current_job );
					}
				)
			);
		}

		if ( ! empty( $member_ids ) ) {
			printf(
				'<div class="smd-member-grid columns-%1$d">',
				max( 1, min( 4, (int) $atts['columns'] ) )
			);

			foreach ( $member_ids as $member_id ) {
				echo self::render_member_card( (int) $member_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '</div>';
		} else {
			echo '<p class="smd-empty">' . esc_html__( 'No members found.', 'strong-members-directory' ) . '</p>';
		}

		wp_reset_postdata();

		return (string) ob_get_clean();
	}

	/**
	 * Render directory sort/search controls.
	 *
	 * @param string $current_order Current sort order.
	 * @param string $current_search Current search query.
	 * @return string
	 */
	private static function render_directory_filters( $current_order, $current_search, $current_type, $current_job ) {
		$action_url = home_url( '/members/' );
		$member_types = SMD_Member_Post_Type::get_distinct_field_values( 'member_type' );
		$occupations  = SMD_Member_Post_Type::get_distinct_field_values( 'occupation' );
		$current_order_ui = isset( $_GET['smd_order'] ) ? $current_order : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dropdown_style = 'border:1px solid #0f4c81;border-radius:15px;appearance:none;-webkit-appearance:none;-moz-appearance:none;background-color:#ffffff;background-image:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2714%27 height=%279%27 viewBox=%270 0 14 9%27%3E%3Cpath d=%27M1 1l6 6 6-6%27 fill=%27none%27 stroke=%27%23102a43%27 stroke-width=%272%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:calc(100% - 16px) 50%;background-size:14px 9px;padding-right:46px;';

		ob_start();
		?>
		<form class="smd-directory-filters" method="get" action="<?php echo esc_url( $action_url ); ?>" style="display:flex;flex-direction:row;align-items:center;justify-content:space-between;gap:18px;flex-wrap:nowrap;width:100%;margin-bottom:15px;">
			<div class="smd-directory-filter-group smd-directory-filter-group-search" style="flex:0 1 480px;max-width:480px;width:100%;margin-right:auto;">
				<label class="screen-reader-text" for="smd_search"><?php esc_html_e( 'Search Members', 'strong-members-directory' ); ?></label>
				<input type="search" id="smd_search" name="smd_search" value="<?php echo esc_attr( $current_search ); ?>" placeholder="<?php esc_attr_e( 'Search Members', 'strong-members-directory' ); ?>" style="border:1px solid #0f4c81;border-radius:15px;" onsearch="if(this.value===''){this.form.submit();}">
			</div>
			<div class="smd-directory-filter-actions" style="display:flex;align-items:center;justify-content:flex-end;gap:14px;flex:0 0 auto;margin-left:auto;">
				<?php if ( ! empty( $occupations ) ) : ?>
					<div class="smd-directory-filter-group smd-directory-filter-group-dropdown" style="flex:0 0 220px;width:220px;">
						<label class="screen-reader-text" for="smd_occupation_filter"><?php esc_html_e( 'Filter by Occupation', 'strong-members-directory' ); ?></label>
						<select id="smd_occupation_filter" name="smd_occupation_filter" onchange="this.form.submit()" style="<?php echo esc_attr( $dropdown_style ); ?>">
							<option value=""><?php esc_html_e( 'Sort by occupation', 'strong-members-directory' ); ?></option>
							<?php foreach ( $occupations as $occupation ) : ?>
								<option value="<?php echo esc_attr( $occupation ); ?>" <?php selected( $current_job, $occupation ); ?>><?php echo esc_html( $occupation ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>
				<div class="smd-directory-filter-group smd-directory-filter-group-dropdown" style="flex:0 0 160px;width:160px;">
					<label class="screen-reader-text" for="smd_order"><?php esc_html_e( 'Sort Members', 'strong-members-directory' ); ?></label>
					<select id="smd_order" name="smd_order" onchange="this.form.submit()" style="<?php echo esc_attr( $dropdown_style ); ?>">
						<option value="" <?php selected( '', $current_order_ui ); ?>><?php esc_html_e( 'Sort by...', 'strong-members-directory' ); ?></option>
						<option value="ASC" <?php selected( 'ASC', $current_order_ui ); ?>><?php esc_html_e( 'Ascending (A-Z)', 'strong-members-directory' ); ?></option>
						<option value="DESC" <?php selected( 'DESC', $current_order_ui ); ?>><?php esc_html_e( 'Descending (Z-A)', 'strong-members-directory' ); ?></option>
					</select>
				</div>
			</div>
			<button type="submit" class="screen-reader-text"><?php esc_html_e( 'Apply filters', 'strong-members-directory' ); ?></button>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render single member shortcode.
	 *
	 * @param array $atts Shortcode atts.
	 * @return string
	 */
	public static function render_single_member_shortcode( $atts ) {
		wp_enqueue_style( 'smd-frontend' );

		if ( SMD_Settings::members_only_enabled() && ! is_user_logged_in() ) {
			return self::render_login_required();
		}

		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'strong_member'
		);

		$post_id = (int) $atts['id'];

		if ( ! $post_id || SMD_Member_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return '<p class="smd-empty">' . esc_html__( 'Member not found.', 'strong-members-directory' ) . '</p>';
		}

		return self::render_member_profile( $post_id );
	}

	/**
	 * Render member dashboard shortcode.
	 *
	 * @return string
	 */
	public static function render_member_dashboard_shortcode() {
		wp_enqueue_style( 'smd-frontend' );

		if ( SMD_Settings::members_only_enabled() && ! is_user_logged_in() ) {
			return self::render_login_required();
		}

		$member_id = self::get_current_member_id();

		if ( ! $member_id ) {
			return '<p class="smd-empty">' . esc_html__( 'We could not find a member profile linked to your login yet.', 'strong-members-directory' ) . '</p>';
		}

		$fields            = SMD_Member_Post_Type::get_member_data( $member_id );
		$directory_url     = home_url( '/members/' );
		$dashboard_url     = SMD_Settings::get_dashboard_url();
		$nomination_url    = SMD_Settings::get_nomination_url();
		$profile_url       = get_permalink( $member_id );
		$full_name         = trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] );

		ob_start();
		?>
		<section class="smd-dashboard">
			<header class="smd-dashboard-header">
				<h1 class="smd-dashboard-title"><?php echo esc_html( sprintf( __( 'Welcome, %s', 'strong-members-directory' ), $full_name ? $full_name : wp_get_current_user()->display_name ) ); ?></h1>
				<p class="smd-dashboard-copy"><?php esc_html_e( 'Use your member dashboard to browse the directory, manage your billing, and nominate someone for membership.', 'strong-members-directory' ); ?></p>
			</header>
			<div class="smd-dashboard-grid">
				<article class="smd-dashboard-card">
					<h2 class="smd-dashboard-card-title"><?php esc_html_e( 'View the Member Directory', 'strong-members-directory' ); ?></h2>
					<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Browse all current members and view their profiles.', 'strong-members-directory' ); ?></p>
					<a class="smd-dashboard-card-link" href="<?php echo esc_url( $directory_url ); ?>"><?php esc_html_e( 'Open Directory', 'strong-members-directory' ); ?></a>
				</article>
				<article class="smd-dashboard-card">
					<h2 class="smd-dashboard-card-title"><?php esc_html_e( 'Manage My Billing', 'strong-members-directory' ); ?></h2>
					<?php echo self::render_billing_panel( $member_id, $fields, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</article>
				<article class="smd-dashboard-card">
					<h2 class="smd-dashboard-card-title"><?php esc_html_e( 'Nominate Someone for Membership', 'strong-members-directory' ); ?></h2>
					<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Send us the details for someone you would like to nominate.', 'strong-members-directory' ); ?></p>
					<a class="smd-dashboard-card-link" href="<?php echo esc_url( $nomination_url ); ?>"><?php esc_html_e( 'Open Nomination Form', 'strong-members-directory' ); ?></a>
				</article>
				<article class="smd-dashboard-card">
					<h2 class="smd-dashboard-card-title"><?php esc_html_e( 'Join the GroupMe Thread', 'strong-members-directory' ); ?></h2>
					<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Stay connected with members in the GroupMe conversation.', 'strong-members-directory' ); ?></p>
					<a class="smd-dashboard-card-link" href="https://groupme.com/join_group/101977984/DqXAXVJ9" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Join GroupMe', 'strong-members-directory' ); ?></a>
				</article>
				<article class="smd-dashboard-card">
					<h2 class="smd-dashboard-card-title"><?php esc_html_e( 'Edit Your Profile', 'strong-members-directory' ); ?></h2>
					<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Update your profile photo and review your member information.', 'strong-members-directory' ); ?></p>
					<a class="smd-dashboard-card-link" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Open My Profile', 'strong-members-directory' ); ?></a>
				</article>
				<article class="smd-dashboard-card">
					<h2 class="smd-dashboard-card-title"><?php esc_html_e( 'Contact Us', 'strong-members-directory' ); ?></h2>
					<p class="smd-dashboard-card-copy"><?php esc_html_e( 'Reach out to the 100 Strong team with questions or support needs.', 'strong-members-directory' ); ?></p>
					<a class="smd-dashboard-card-link" href="mailto:contact@100strong.org"><?php esc_html_e( 'Email 100 Strong', 'strong-members-directory' ); ?></a>
				</article>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render member nomination form shortcode.
	 *
	 * @return string
	 */
	public static function render_member_nomination_shortcode() {
		wp_enqueue_style( 'smd-frontend' );

		if ( SMD_Settings::members_only_enabled() && ! is_user_logged_in() ) {
			return self::render_login_required();
		}

		$member_id = self::get_current_member_id();
		if ( ! $member_id ) {
			return '<p class="smd-empty">' . esc_html__( 'We could not find a member profile linked to your login yet.', 'strong-members-directory' ) . '</p>';
		}

		$notice = isset( $_GET['smd_nomination_notice'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_nomination_notice'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error  = isset( $_GET['smd_nomination_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_nomination_error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		?>
		<section class="smd-nomination">
			<?php if ( SMD_Settings::get_dashboard_url() ) : ?>
				<p class="smd-profile-back smd-dashboard-back"><a href="<?php echo esc_url( SMD_Settings::get_dashboard_url() ); ?>">&larr; <?php esc_html_e( 'Back to dashboard', 'strong-members-directory' ); ?></a></p>
			<?php endif; ?>
			<h1 class="smd-dashboard-title"><?php esc_html_e( 'Nominate Someone for Membership', 'strong-members-directory' ); ?></h1>
			<p class="smd-dashboard-copy"><?php esc_html_e( 'Share a few details about the person you would like to nominate and our team will review it.', 'strong-members-directory' ); ?></p>
			<?php if ( $notice ) : ?>
				<p class="smd-member-billing-notice"><?php echo esc_html( $notice ); ?></p>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<p class="smd-member-profile-error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<form class="smd-nomination-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'smd_submit_member_nomination', 'smd_submit_member_nomination_nonce' ); ?>
				<input type="hidden" name="action" value="smd_submit_member_nomination">
				<div class="smd-nomination-field">
					<label for="smd_nominee_name"><?php esc_html_e( 'Nominee Name', 'strong-members-directory' ); ?></label>
					<input type="text" id="smd_nominee_name" name="smd_nominee_name" required>
				</div>
				<div class="smd-nomination-field">
					<label for="smd_nominee_email"><?php esc_html_e( 'Nominee Email', 'strong-members-directory' ); ?></label>
					<input type="email" id="smd_nominee_email" name="smd_nominee_email">
				</div>
				<div class="smd-nomination-field">
					<label for="smd_nominee_occupation"><?php esc_html_e( 'Nominee Occupation', 'strong-members-directory' ); ?></label>
					<input type="text" id="smd_nominee_occupation" name="smd_nominee_occupation">
				</div>
				<div class="smd-nomination-field">
					<label for="smd_nomination_reason"><?php esc_html_e( 'Why are you nominating this person?', 'strong-members-directory' ); ?></label>
					<textarea id="smd_nomination_reason" name="smd_nomination_reason" rows="6" required></textarea>
				</div>
				<button type="submit" class="smd-billing-button"><?php esc_html_e( 'Submit Nomination', 'strong-members-directory' ); ?></button>
			</form>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render a single card.
	 *
	 * @param int $post_id Member post ID.
	 * @return string
	 */
	public static function render_member_card( $post_id ) {
		$fields    = SMD_Member_Post_Type::get_member_data( $post_id );
		$image     = get_the_post_thumbnail( $post_id, 'smd-member-card', array( 'class' => 'smd-member-image' ) );
		$bio       = get_post_field( 'post_content', $post_id );
		$full_name = trim( $fields['first_name'] . ' ' . $fields['last_name'] );
		$excerpt   = wp_trim_words( wp_strip_all_tags( $bio ), 18, '...' );
		$permalink = get_permalink( $post_id );

		ob_start();
		?>
		<article class="smd-member-card">
			<a class="smd-member-card-link" href="<?php echo esc_url( $permalink ); ?>">
				<div class="smd-member-image-wrap">
					<?php if ( $image ) : ?>
						<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php else : ?>
						<div class="smd-member-placeholder" aria-hidden="true"><?php echo esc_html( strtoupper( substr( $fields['first_name'] ?: 'M', 0, 1 ) ) ); ?></div>
					<?php endif; ?>
				</div>
				<div class="smd-member-content">
					<h3 class="smd-member-name"><?php echo esc_html( $full_name ?: get_the_title( $post_id ) ); ?></h3>
					<?php if ( $fields['occupation'] ) : ?>
						<p class="smd-member-occupation"><?php echo esc_html( $fields['occupation'] ); ?></p>
					<?php endif; ?>
					<?php if ( $excerpt ) : ?>
						<p class="smd-member-excerpt"><?php echo esc_html( $excerpt ); ?></p>
					<?php endif; ?>
					<span class="smd-member-cta"><?php esc_html_e( 'View profile', 'strong-members-directory' ); ?></span>
				</div>
			</a>
		</article>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render a condensed member profile.
	 *
	 * @param int $post_id Member post ID.
	 * @return string
	 */
	public static function render_member_profile( $post_id ) {
		wp_enqueue_style( 'smd-frontend' );
		wp_enqueue_script( 'smd-frontend' );

		$fields        = SMD_Member_Post_Type::get_member_data( $post_id );
		$image         = get_the_post_thumbnail( $post_id, 'smd-member-profile', array( 'class' => 'smd-member-image' ) );
		$bio           = get_post_field( 'post_content', $post_id );
		$full_name     = trim( $fields['first_name'] . ' ' . $fields['last_name'] );
		$directory_url = home_url( '/members/' );
		$dashboard_url = SMD_Settings::get_dashboard_url();
		$can_edit      = is_user_logged_in() && SMD_Auth::user_can_edit_member( get_current_user_id(), $post_id );
		$edit_section  = isset( $_GET['smd_edit_section'] ) ? sanitize_key( wp_unslash( $_GET['smd_edit_section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$profile_error = isset( $_GET['smd_profile_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_profile_error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$billing_notice = isset( $_GET['smd_billing_notice'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_billing_notice'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$billing_error  = isset( $_GET['smd_billing_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_billing_error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$image_input_id = 'smd_profile_image_' . $post_id;
		$show_billing   = $can_edit && class_exists( 'SMD_Stripe' );
		$stripe_ready   = $show_billing && SMD_Stripe::is_enabled();
		$billing_status = isset( $fields['billing_status'] ) ? (string) $fields['billing_status'] : '';
		$period_end     = isset( $fields['billing_period_end'] ) ? (string) $fields['billing_period_end'] : '';
		$failure_note   = isset( $fields['payment_failed_note'] ) ? (string) $fields['payment_failed_note'] : '';
		$has_subscription = ! empty( $fields['stripe_subscription_id'] );

		ob_start();
		?>
		<section class="smd-member-profile">
			<?php if ( $directory_url ) : ?>
				<p class="smd-profile-back"><a href="<?php echo esc_url( $directory_url ); ?>">&larr; <?php esc_html_e( 'Back to directory', 'strong-members-directory' ); ?></a></p>
			<?php endif; ?>
			<article class="smd-member-card smd-member-card-profile">
				<div class="smd-member-profile-layout">
					<div class="smd-member-profile-column smd-member-profile-column-photo smd-member-image-wrap smd-member-image-wrap-profile">
						<?php if ( $image ) : ?>
							<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php else : ?>
							<div class="smd-member-placeholder smd-member-placeholder-large" aria-hidden="true"><?php echo esc_html( strtoupper( substr( $fields['first_name'] ?: 'M', 0, 1 ) ) ); ?></div>
						<?php endif; ?>
						<?php if ( $can_edit ) : ?>
							<form class="smd-image-edit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-smd-image-editor="true">
								<?php wp_nonce_field( 'smd_frontend_member_update', 'smd_frontend_member_update_nonce' ); ?>
								<input type="hidden" name="action" value="smd_update_member_profile">
								<input type="hidden" name="member_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
								<input type="hidden" name="smd_update_type" value="image">
								<input type="hidden" name="smd_cropped_image_data" value="">
								<input class="smd-image-edit-input" type="file" id="<?php echo esc_attr( $image_input_id ); ?>" name="smd_profile_image" accept="image/jpeg,image/png,image/webp">
								<label class="smd-image-edit-overlay" for="<?php echo esc_attr( $image_input_id ); ?>" title="<?php esc_attr_e( 'Edit Image', 'strong-members-directory' ); ?>">
									<span class="smd-edit-pen" aria-hidden="true">&#9998;</span>
									<span class="screen-reader-text"><?php esc_html_e( 'Edit Image', 'strong-members-directory' ); ?></span>
								</label>
							</form>
						<?php endif; ?>
					</div>
					<div class="smd-member-profile-column smd-member-profile-column-details">
						<div class="smd-member-content smd-member-content-profile">
							<h1 class="smd-member-name smd-member-name-profile"><?php echo esc_html( $full_name ?: get_the_title( $post_id ) ); ?></h1>
							<div class="smd-member-detail-list">
								<?php if ( $fields['member_type'] ) : ?>
									<div class="smd-member-detail-row">
										<span class="smd-member-detail-label"><?php esc_html_e( 'Member Type', 'strong-members-directory' ); ?></span>
										<span class="smd-member-detail-value"><?php echo esc_html( $fields['member_type'] ); ?></span>
									</div>
								<?php endif; ?>
								<div class="smd-member-detail-row">
									<span class="smd-member-detail-label"><?php esc_html_e( 'First Name', 'strong-members-directory' ); ?></span>
									<span class="smd-member-detail-value"><?php echo esc_html( $fields['first_name'] ); ?></span>
								</div>
								<div class="smd-member-detail-row">
									<span class="smd-member-detail-label"><?php esc_html_e( 'Last Name', 'strong-members-directory' ); ?></span>
									<span class="smd-member-detail-value"><?php echo esc_html( $fields['last_name'] ); ?></span>
								</div>
								<?php if ( $fields['email'] ) : ?>
									<div class="smd-member-detail-row">
										<span class="smd-member-detail-label"><?php esc_html_e( 'Email', 'strong-members-directory' ); ?></span>
										<span class="smd-member-detail-value"><a href="mailto:<?php echo esc_attr( antispambot( $fields['email'] ) ); ?>"><?php echo esc_html( antispambot( $fields['email'] ) ); ?></a></span>
									</div>
								<?php endif; ?>
								<?php if ( $fields['occupation'] ) : ?>
									<div class="smd-member-detail-row">
										<span class="smd-member-detail-label">
											<?php esc_html_e( 'Occupation', 'strong-members-directory' ); ?>
											<?php if ( $can_edit ) : ?>
												<a class="smd-inline-edit-trigger" href="<?php echo esc_url( add_query_arg( 'smd_edit_section', 'occupation', get_permalink( $post_id ) ) ); ?>" title="<?php esc_attr_e( 'Edit Occupation', 'strong-members-directory' ); ?>">
													<span class="smd-edit-pen" aria-hidden="true">&#9998;</span>
													<span class="screen-reader-text"><?php esc_html_e( 'Edit Occupation', 'strong-members-directory' ); ?></span>
												</a>
											<?php endif; ?>
										</span>
										<?php if ( $can_edit && 'occupation' === $edit_section ) : ?>
											<form class="smd-inline-edit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<?php wp_nonce_field( 'smd_frontend_member_update', 'smd_frontend_member_update_nonce' ); ?>
												<input type="hidden" name="action" value="smd_update_member_profile">
												<input type="hidden" name="member_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
												<input type="hidden" name="smd_update_type" value="occupation">
												<input type="text" name="smd_occupation" value="<?php echo esc_attr( $fields['occupation'] ); ?>">
												<button type="submit" class="smd-inline-save-button" style="background:#0f4c81;border:1px solid #0f4c81;border-radius:999px;color:#ffffff;"><?php esc_html_e( 'Save', 'strong-members-directory' ); ?></button>
											</form>
										<?php else : ?>
											<span class="smd-member-detail-value"><?php echo esc_html( $fields['occupation'] ); ?></span>
										<?php endif; ?>
									</div>
								<?php endif; ?>
								<?php if ( $fields['phone'] ) : ?>
									<div class="smd-member-detail-row">
										<span class="smd-member-detail-label"><?php esc_html_e( 'Phone', 'strong-members-directory' ); ?></span>
										<span class="smd-member-detail-value"><?php echo esc_html( $fields['phone'] ); ?></span>
									</div>
								<?php endif; ?>
								<?php if ( $fields['website'] ) : ?>
									<div class="smd-member-detail-row">
										<span class="smd-member-detail-label"><?php esc_html_e( 'Website', 'strong-members-directory' ); ?></span>
										<span class="smd-member-detail-value"><a href="<?php echo esc_url( $fields['website'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( preg_replace( '#^https?://#', '', $fields['website'] ) ); ?></a></span>
									</div>
								<?php endif; ?>
								<?php if ( $fields['linkedin'] ) : ?>
									<div class="smd-member-detail-row">
										<span class="smd-member-detail-label"><?php esc_html_e( 'LinkedIn', 'strong-members-directory' ); ?></span>
										<span class="smd-member-detail-value"><a href="<?php echo esc_url( $fields['linkedin'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Profile', 'strong-members-directory' ); ?></a></span>
									</div>
								<?php endif; ?>
							</div>
							<?php if ( $bio ) : ?>
								<div class="smd-member-bio smd-member-bio-profile"><?php echo wpautop( wp_kses_post( $bio ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
							<?php endif; ?>
							<?php if ( $profile_error ) : ?>
								<p class="smd-member-profile-error"><?php echo esc_html( $profile_error ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</article>
			<?php if ( $dashboard_url ) : ?>
				<p class="smd-profile-back smd-dashboard-back smd-dashboard-back-bottom"><a href="<?php echo esc_url( $dashboard_url ); ?>">&larr; <?php esc_html_e( 'Back to dashboard', 'strong-members-directory' ); ?></a></p>
			<?php endif; ?>
			<?php if ( $can_edit ) : ?>
				<div class="smd-cropper-modal" hidden data-smd-cropper-modal>
					<div class="smd-cropper-backdrop" data-smd-cropper-cancel></div>
					<div class="smd-cropper-dialog" role="dialog" aria-modal="true" aria-labelledby="smd-cropper-title-<?php echo esc_attr( (string) $post_id ); ?>">
						<button type="button" class="smd-cropper-close" data-smd-cropper-cancel aria-label="<?php esc_attr_e( 'Close photo cropper', 'strong-members-directory' ); ?>">×</button>
						<h2 id="smd-cropper-title-<?php echo esc_attr( (string) $post_id ); ?>" class="smd-cropper-title"><?php esc_html_e( 'Crop Profile Photo', 'strong-members-directory' ); ?></h2>
						<p class="smd-cropper-help"><?php esc_html_e( 'Drag to reposition your photo, then use the slider to zoom.', 'strong-members-directory' ); ?></p>
						<div class="smd-cropper-stage" data-smd-cropper-stage>
							<img class="smd-cropper-image" alt="" data-smd-cropper-image>
							<div class="smd-cropper-frame" aria-hidden="true"></div>
						</div>
						<label class="smd-cropper-zoom-label">
							<span><?php esc_html_e( 'Zoom', 'strong-members-directory' ); ?></span>
							<input type="range" min="1" max="3" step="0.01" value="1" data-smd-cropper-zoom>
						</label>
						<div class="smd-cropper-actions">
							<button type="button" class="smd-cropper-button smd-cropper-button-secondary" data-smd-cropper-cancel><?php esc_html_e( 'Cancel', 'strong-members-directory' ); ?></button>
							<button type="button" class="smd-cropper-button smd-cropper-button-primary" data-smd-cropper-apply><?php esc_html_e( 'Use Photo', 'strong-members-directory' ); ?></button>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Replace single member post content with condensed profile markup.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function render_single_member_content( $content ) {
		if ( is_admin() || ! is_singular( SMD_Member_Post_Type::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( SMD_Settings::members_only_enabled() && ! is_user_logged_in() ) {
			return self::render_protected_profile_message();
		}

		return self::render_member_profile( get_the_ID() );
	}

	/**
	 * Render login requirement prompt.
	 *
	 * @return string
	 */
	public static function render_login_required() {
		$login_url = wp_login_url( SMD_Settings::get_directory_url() ?: home_url( '/' ) );

		return sprintf(
			'<p class="smd-login-message">%s <a href="%s">%s</a></p>',
			esc_html( SMD_Settings::get_logged_out_message() ),
			esc_url( $login_url ),
			esc_html__( 'Log in here.', 'strong-members-directory' )
		);
	}

	/**
	 * Render the logged-out state for a single member profile.
	 *
	 * @return string
	 */
	public static function render_protected_profile_message() {
		$directory_url = home_url( '/members/' );

		ob_start();
		?>
		<section class="smd-member-profile smd-member-profile-protected">
			<?php if ( $directory_url ) : ?>
				<p class="smd-profile-back"><a href="<?php echo esc_url( $directory_url ); ?>">&larr; <?php esc_html_e( 'Back to directory', 'strong-members-directory' ); ?></a></p>
			<?php endif; ?>
			<article class="smd-member-card smd-member-card-profile">
				<div class="smd-member-profile-layout">
					<div class="smd-member-profile-column smd-member-profile-column-photo smd-member-image-wrap smd-member-image-wrap-profile">
						<div class="smd-member-placeholder smd-member-placeholder-large" aria-hidden="true">M</div>
					</div>
					<div class="smd-member-profile-column smd-member-profile-column-details">
						<div class="smd-member-content smd-member-content-profile">
							<h1 class="smd-member-name smd-member-name-profile"><?php esc_html_e( 'Member Profile', 'strong-members-directory' ); ?></h1>
							<div class="smd-profile-message-wrap">
								<?php echo self::render_login_required(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						</div>
					</div>
				</div>
			</article>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Check whether a member matches the current search term.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $search Search term.
	 * @return bool
	 */
	private static function member_matches_search( $member_id, $search ) {
		$fields = SMD_Member_Post_Type::get_member_data( $member_id );
		$haystack = array(
			get_the_title( $member_id ),
			(string) $fields['first_name'],
			(string) $fields['last_name'],
			(string) $fields['email'],
			(string) $fields['occupation'],
			(string) $fields['member_type'],
			(string) $fields['phone'],
			(string) $fields['website'],
			(string) $fields['linkedin'],
		);

		$search = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );

		foreach ( $haystack as $value ) {
			$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value ) : strtolower( (string) $value );

			if ( false !== strpos( $value, $search ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Human-friendly billing status label.
	 *
	 * @param string $status Raw billing status.
	 * @return string
	 */
	private static function format_billing_status( $status ) {
		$map = array(
			''                => __( 'Not started', 'strong-members-directory' ),
			'active'          => __( 'Active', 'strong-members-directory' ),
			'trialing'        => __( 'Trialing', 'strong-members-directory' ),
			'past_due'        => __( 'Past due', 'strong-members-directory' ),
			'payment_failed'  => __( 'Payment update needed', 'strong-members-directory' ),
			'canceled'        => __( 'Canceled', 'strong-members-directory' ),
			'unpaid'          => __( 'Unpaid', 'strong-members-directory' ),
			'incomplete'      => __( 'Incomplete', 'strong-members-directory' ),
			'incomplete_expired' => __( 'Incomplete expired', 'strong-members-directory' ),
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : ucwords( str_replace( '_', ' ', $status ) );
	}

	/**
	 * Render the billing panel markup.
	 *
	 * @param int  $member_id Member ID.
	 * @param array $fields Member fields.
	 * @param bool $compact Whether to omit heading wrapper for dashboard usage.
	 * @return string
	 */
	private static function render_billing_panel( $member_id, $fields, $compact = false ) {
		$stripe_ready     = class_exists( 'SMD_Stripe' ) && SMD_Stripe::is_enabled();
		$billing_status   = isset( $fields['billing_status'] ) ? (string) $fields['billing_status'] : '';
		$period_end       = isset( $fields['billing_period_end'] ) ? (string) $fields['billing_period_end'] : '';
		$failure_note     = isset( $fields['payment_failed_note'] ) ? (string) $fields['payment_failed_note'] : '';
		$has_subscription = ! empty( $fields['stripe_subscription_id'] );
		$billing_notice   = isset( $_GET['smd_billing_notice'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_billing_notice'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$billing_error    = isset( $_GET['smd_billing_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_billing_error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		?>
		<div class="smd-member-billing<?php echo $compact ? ' smd-member-billing-compact' : ''; ?>">
			<?php if ( ! $compact ) : ?>
				<h2 class="smd-member-billing-title"><?php esc_html_e( 'Membership Billing', 'strong-members-directory' ); ?></h2>
			<?php endif; ?>
			<?php if ( ! $stripe_ready ) : ?>
				<p class="smd-member-billing-warning"><?php esc_html_e( 'Billing setup is not live yet. Once Stripe is connected, you will be able to start your subscription and manage your card here.', 'strong-members-directory' ); ?></p>
			<?php endif; ?>
			<?php if ( $billing_notice ) : ?>
				<p class="smd-member-billing-notice"><?php echo esc_html( $billing_notice ); ?></p>
			<?php endif; ?>
			<?php if ( $billing_error ) : ?>
				<p class="smd-member-profile-error"><?php echo esc_html( $billing_error ); ?></p>
			<?php endif; ?>
			<?php if ( 'payment_failed' === $billing_status ) : ?>
				<p class="smd-member-billing-warning"><?php echo esc_html( $failure_note ? $failure_note : __( 'Your last membership payment did not go through. Please update your card details.', 'strong-members-directory' ) ); ?></p>
			<?php endif; ?>
			<div class="smd-member-billing-summary">
				<p><strong><?php esc_html_e( 'Status:', 'strong-members-directory' ); ?></strong> <?php echo esc_html( self::format_billing_status( $billing_status ) ); ?></p>
				<?php if ( $period_end ) : ?>
					<p><strong><?php esc_html_e( 'Current period ends:', 'strong-members-directory' ); ?></strong> <?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $period_end ) ) ); ?></p>
				<?php endif; ?>
			</div>
			<div class="smd-member-billing-actions">
				<?php if ( ! $stripe_ready ) : ?>
					<button type="button" class="smd-billing-button" disabled><?php esc_html_e( 'Stripe setup required', 'strong-members-directory' ); ?></button>
				<?php elseif ( $has_subscription ) : ?>
					<a class="smd-billing-button smd-billing-button-link" href="<?php echo esc_url( SMD_Stripe::get_billing_action_url( 'open_billing_portal', $member_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage Billing', 'strong-members-directory' ); ?></a>
				<?php else : ?>
					<a class="smd-billing-button smd-billing-button-link" href="<?php echo esc_url( SMD_Stripe::get_billing_action_url( 'start_subscription', $member_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Start Membership Subscription', 'strong-members-directory' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Find current user's linked member profile ID.
	 *
	 * @return int
	 */
	private static function get_current_member_id() {
		return (int) get_user_meta( get_current_user_id(), '_smd_member_id', true );
	}

	/**
	 * Handle member nomination submission.
	 */
	public static function handle_member_nomination_submission() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to submit a nomination.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_submit_member_nomination', 'smd_submit_member_nomination_nonce' );

		$nomination_url = SMD_Settings::get_nomination_url() ? SMD_Settings::get_nomination_url() : home_url( '/' );
		$member_id      = self::get_current_member_id();
		$fields         = $member_id ? SMD_Member_Post_Type::get_member_data( $member_id ) : array();
		$nominee_name   = isset( $_POST['smd_nominee_name'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_nominee_name'] ) ) : '';
		$nominee_email  = isset( $_POST['smd_nominee_email'] ) ? sanitize_email( wp_unslash( $_POST['smd_nominee_email'] ) ) : '';
		$nominee_job    = isset( $_POST['smd_nominee_occupation'] ) ? sanitize_text_field( wp_unslash( $_POST['smd_nominee_occupation'] ) ) : '';
		$reason         = isset( $_POST['smd_nomination_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['smd_nomination_reason'] ) ) : '';

		if ( '' === $nominee_name || '' === $reason ) {
			wp_safe_redirect( add_query_arg( 'smd_nomination_error', rawurlencode( __( 'Please include the nominee name and your reason for nominating them.', 'strong-members-directory' ) ), $nomination_url ) );
			exit;
		}

		$subject = sprintf( __( 'New membership nomination: %s', 'strong-members-directory' ), $nominee_name );
		$message = sprintf(
			/* translators: 1: nominee name, 2: nominee email, 3: nominee occupation, 4: nominator name, 5: nominator email, 6: reason */
			__( "A new membership nomination was submitted.\n\nNominee: %1\$s\nNominee Email: %2\$s\nNominee Occupation: %3\$s\n\nSubmitted By: %4\$s\nMember Email: %5\$s\n\nReason:\n%6\$s", 'strong-members-directory' ),
			$nominee_name,
			$nominee_email ? $nominee_email : __( 'Not provided', 'strong-members-directory' ),
			$nominee_job ? $nominee_job : __( 'Not provided', 'strong-members-directory' ),
			trim( (string) ( $fields['first_name'] ?? '' ) . ' ' . (string) ( $fields['last_name'] ?? '' ) ),
			(string) ( $fields['email'] ?? '' ),
			$reason
		);

		wp_mail( get_option( 'admin_email' ), $subject, $message );

		wp_safe_redirect( add_query_arg( 'smd_nomination_notice', rawurlencode( __( 'Your nomination was submitted successfully.', 'strong-members-directory' ) ), $nomination_url ) );
		exit;
	}
}
