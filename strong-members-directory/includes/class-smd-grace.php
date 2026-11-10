<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Failed-payment grace period and lapsed-member access control.
 *
 * A first failed payment starts a grace clock (default 30 days). Staff follow up during
 * that time. If the member has not paid when it ends, they are lapsed: login, directory
 * access and their public profile are shut off. A payment (or a staff Reinstate) restores them.
 */
class SMD_Grace {
	const META_FAILED_AT    = '_smd_payment_failed_at';
	const META_ACCESS       = '_smd_access_status';
	const META_LAPSED_AT    = '_smd_lapsed_at';
	const META_REINSTATED   = '_smd_reinstated_at';
	const CRON_HOOK         = 'smd_grace_daily_check';
	const DEFAULT_GRACE_DAYS = 30;

	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'schedule_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_check' ) );
		add_filter( 'authenticate', array( __CLASS__, 'block_lapsed_login' ), 100, 1 );
		add_action( 'init', array( __CLASS__, 'end_lapsed_session' ), 5 );
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_lapsed_members' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_post_smd_reinstate_member', array( __CLASS__, 'handle_reinstate' ) );
	}

	/**
	 * Grace period length in days.
	 *
	 * @return int
	 */
	public static function get_grace_days() {
		$settings = SMD_Settings::get_settings();
		$days     = isset( $settings['grace_period_days'] ) ? (int) $settings['grace_period_days'] : self::DEFAULT_GRACE_DAYS;

		return max( 1, $days );
	}

	/**
	 * Whether a member is lapsed.
	 *
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	public static function is_lapsed( $member_id ) {
		return 'lapsed' === get_post_meta( (int) $member_id, self::META_ACCESS, true );
	}

	/**
	 * Whether a WP user belongs to a lapsed member (staff are never locked out).
	 *
	 * @param WP_User|int $user User or ID.
	 * @return bool
	 */
	public static function is_lapsed_user( $user ) {
		$user_id = $user instanceof WP_User ? (int) $user->ID : (int) $user;

		if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
			return false;
		}

		$member_id = (int) get_user_meta( $user_id, '_smd_member_id', true );

		return $member_id && self::is_lapsed( $member_id );
	}

	/**
	 * Start the grace clock on the first failed payment. Later retries do not restart it.
	 *
	 * @param int $member_id Member ID.
	 * @return bool True when the clock was just started.
	 */
	public static function start_grace( $member_id ) {
		if ( get_post_meta( $member_id, self::META_FAILED_AT, true ) ) {
			return false;
		}

		update_post_meta( $member_id, self::META_FAILED_AT, current_time( 'mysql' ) );

		return true;
	}

	/**
	 * Date access ends if the member does not pay.
	 *
	 * @param int $member_id Member ID.
	 * @return int Unix timestamp, or 0 when not in grace.
	 */
	public static function get_grace_ends_at( $member_id ) {
		$failed = (string) get_post_meta( $member_id, self::META_FAILED_AT, true );
		$start  = $failed ? strtotime( $failed . ' UTC' ) : 0;

		return $start ? $start + self::get_grace_days() * DAY_IN_SECONDS : 0;
	}

	/**
	 * Clear grace and lapsed state (payment received or staff reinstated).
	 *
	 * @param int $member_id Member ID.
	 */
	public static function restore( $member_id ) {
		delete_post_meta( $member_id, self::META_FAILED_AT );
		delete_post_meta( $member_id, '_smd_payment_failed_note' );
		delete_post_meta( $member_id, self::META_ACCESS );
		delete_post_meta( $member_id, self::META_LAPSED_AT );
	}

	/**
	 * Schedule the daily check.
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Lapse every member whose grace period has run out.
	 *
	 * @return int Number of members lapsed.
	 */
	public static function run_daily_check() {
		$ids = get_posts(
			array(
				'post_type'      => SMD_Member_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_FAILED_AT,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_ACCESS,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$lapsed = 0;

		foreach ( $ids as $member_id ) {
			$ends = self::get_grace_ends_at( (int) $member_id );
			if ( $ends && time() >= $ends ) {
				self::lapse( (int) $member_id );
				++$lapsed;
			}
		}

		return $lapsed;
	}

	/**
	 * Shut off a member's access.
	 *
	 * @param int $member_id Member ID.
	 */
	public static function lapse( $member_id ) {
		update_post_meta( $member_id, self::META_ACCESS, 'lapsed' );
		update_post_meta( $member_id, self::META_LAPSED_AT, current_time( 'mysql' ) );

		$user_id = (int) get_post_meta( $member_id, '_smd_user_id', true );
		if ( ! $user_id ) {
			$users   = get_users( array( 'meta_key' => '_smd_member_id', 'meta_value' => $member_id, 'fields' => 'ID', 'number' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$user_id = ! empty( $users[0] ) ? (int) $users[0] : 0;
		}
		if ( $user_id && class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}

		$fields = SMD_Member_Post_Type::get_member_data( $member_id );
		$name   = trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] );

		if ( ! empty( $fields['email'] ) && is_email( $fields['email'] ) ) {
			wp_mail(
				(string) $fields['email'],
				__( 'Your 100 Strong membership access has been paused', 'strong-members-directory' ),
				sprintf(
					/* translators: 1: first name, 2: grace days */
					__( "Hi %1\$s,\n\nWe have not received an updated payment for your 100 Strong membership within %2\$d days, so your login, directory access and member profile have been paused.\n\nIf you update your payment information, please contact the 100 Strong team and we will restore your access.\n\n100 Strong", 'strong-members-directory' ),
					'' !== (string) $fields['first_name'] ? (string) $fields['first_name'] : __( 'there', 'strong-members-directory' ),
					self::get_grace_days()
				)
			);
		}

		self::notify_team(
			sprintf( /* translators: %s: member name */ __( 'Member lapsed: %s', 'strong-members-directory' ), $name ),
			sprintf( /* translators: 1: name, 2: edit url */ __( "%1\$s's grace period ended without payment, so their login, directory access and profile are now paused.\n\nTo reinstate them once they have updated their payment: %2\$s", 'strong-members-directory' ), $name, self::get_follow_up_url() )
		);
	}

	/**
	 * Email the 100 Strong team.
	 *
	 * @param string $subject Subject.
	 * @param string $message Body.
	 */
	public static function notify_team( $subject, $message ) {
		$settings = SMD_Settings::get_settings();
		$to       = ! empty( $settings['workflow_admin_email'] ) ? (string) $settings['workflow_admin_email'] : (string) get_option( 'admin_email' );

		if ( is_email( $to ) ) {
			wp_mail( $to, $subject, $message );
		}
	}

	/**
	 * Billing follow-up page URL.
	 *
	 * @return string
	 */
	public static function get_follow_up_url() {
		return add_query_arg(
			array(
				'post_type' => SMD_Member_Post_Type::POST_TYPE,
				'page'      => 'smd-billing-follow-up',
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Refuse login for lapsed members.
	 *
	 * @param WP_User|WP_Error|null $user Authenticated user so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_lapsed_login( $user ) {
		if ( $user instanceof WP_User && self::is_lapsed_user( $user ) ) {
			return new WP_Error(
				'smd_membership_paused',
				__( 'Your membership access is paused because of an unresolved payment. Please contact the 100 Strong team.', 'strong-members-directory' )
			);
		}

		return $user;
	}

	/**
	 * End the session of a member who lapsed while logged in.
	 */
	public static function end_lapsed_session() {
		if ( ! is_user_logged_in() || wp_doing_cron() || ! self::is_lapsed_user( get_current_user_id() ) ) {
			return;
		}

		wp_logout();
		wp_safe_redirect( add_query_arg( 'smd_paused', '1', home_url( '/' ) ) );
		exit;
	}

	/**
	 * Keep lapsed members out of directory listings, search and single profile pages.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function hide_lapsed_members( $query ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || current_user_can( 'manage_options' ) ) {
			return;
		}

		// Opt-in only: internal lookups (Stripe webhooks, imports) must still find lapsed members
		// so a late payment can restore them. Public pages are the main query and the directory listing.
		if ( ! $query->is_main_query() && ! $query->get( 'smd_public_listing' ) ) {
			return;
		}

		$types = (array) $query->get( 'post_type' );
		if ( ! in_array( SMD_Member_Post_Type::POST_TYPE, $types, true ) ) {
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' );
		$meta_query[] = array(
			'key'     => self::META_ACCESS,
			'compare' => 'NOT EXISTS',
		);
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Register the follow-up page.
	 */
	public static function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=' . SMD_Member_Post_Type::POST_TYPE,
			__( 'Billing Follow-up', 'strong-members-directory' ),
			__( 'Billing Follow-up', 'strong-members-directory' ),
			'manage_options',
			'smd-billing-follow-up',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Members in grace or lapsed.
	 *
	 * @return int[]
	 */
	private static function get_follow_up_member_ids() {
		return get_posts(
			array(
				'post_type'      => SMD_Member_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => self::META_FAILED_AT,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_ACCESS,
						'compare' => 'EXISTS',
					),
				),
			)
		);
	}

	/**
	 * Render the follow-up page: who is in grace, how long is left, who has lapsed.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'strong-members-directory' ) );
		}

		$ids    = self::get_follow_up_member_ids();
		$notice = isset( $_GET['smd_notice'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['smd_notice'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Billing Follow-up', 'strong-members-directory' ); ?></h1>
			<p><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Members with a failed payment get %d days to update their billing. After that, login, directory access and their profile are paused automatically. Reinstate restores everything.', 'strong-members-directory' ), self::get_grace_days() ) ); ?></p>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( empty( $ids ) ) : ?>
				<p><?php esc_html_e( 'No members need billing follow-up right now.', 'strong-members-directory' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Member', 'strong-members-directory' ); ?></th>
						<th><?php esc_html_e( 'Email', 'strong-members-directory' ); ?></th>
						<th><?php esc_html_e( 'Status', 'strong-members-directory' ); ?></th>
						<th><?php esc_html_e( 'Payment issue', 'strong-members-directory' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php
					foreach ( $ids as $member_id ) :
						$fields = SMD_Member_Post_Type::get_member_data( (int) $member_id );
						$ends   = self::get_grace_ends_at( (int) $member_id );
						if ( self::is_lapsed( $member_id ) ) {
							$status = sprintf( /* translators: %s: date */ __( 'Lapsed %s', 'strong-members-directory' ), wp_date( get_option( 'date_format' ), strtotime( (string) get_post_meta( $member_id, self::META_LAPSED_AT, true ) . ' UTC' ) ) );
						} else {
							$days   = max( 0, (int) ceil( ( $ends - time() ) / DAY_IN_SECONDS ) );
							$status = sprintf( /* translators: 1: days left, 2: date */ _n( 'In grace: %1$d day left (ends %2$s)', 'In grace: %1$d days left (ends %2$s)', $days, 'strong-members-directory' ), $days, wp_date( get_option( 'date_format' ), $ends ) );
						}
						$reinstate = wp_nonce_url(
							add_query_arg(
								array(
									'action'    => 'smd_reinstate_member',
									'member_id' => (int) $member_id,
								),
								admin_url( 'admin-post.php' )
							),
							'smd_reinstate_member_' . $member_id
						);
						?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( (int) $member_id ) ); ?>"><?php echo esc_html( trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] ) ); ?></a></td>
							<td><?php echo esc_html( (string) $fields['email'] ); ?></td>
							<td><?php echo esc_html( $status ); ?></td>
							<td><?php echo esc_html( (string) $fields['payment_failed_note'] ); ?></td>
							<td><a class="button button-primary" href="<?php echo esc_url( $reinstate ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Reinstate this member and restart their grace period clock?', 'strong-members-directory' ) ); ?>');"><?php esc_html_e( 'Reinstate', 'strong-members-directory' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Reinstate a member.
	 */
	public static function handle_reinstate() {
		$member_id = isset( $_GET['member_id'] ) ? (int) $_GET['member_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to reinstate members.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_reinstate_member_' . $member_id );

		if ( ! $member_id || SMD_Member_Post_Type::POST_TYPE !== get_post_type( $member_id ) ) {
			wp_die( esc_html__( 'Invalid member.', 'strong-members-directory' ) );
		}

		self::restore( $member_id );
		update_post_meta( $member_id, self::META_REINSTATED, current_time( 'mysql' ) );

		$fields = SMD_Member_Post_Type::get_member_data( $member_id );

		wp_safe_redirect(
			add_query_arg(
				'smd_notice',
				rawurlencode( sprintf( /* translators: %s: name */ __( '%s was reinstated.', 'strong-members-directory' ), trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] ) ) ),
				self::get_follow_up_url()
			)
		);
		exit;
	}
}
