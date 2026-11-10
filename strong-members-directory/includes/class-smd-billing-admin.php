<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe billing management inside WordPress, so staff rarely need the Stripe dashboard.
 *
 * Adds a Stripe Billing panel to each member (live status, card, payment history, actions)
 * and a billing column and filter to the Members list. Every action is restricted to
 * administrators and recorded in a staff audit log on the member.
 */
class SMD_Billing_Admin {
	const NONCE_ACTION = 'smd_billing_admin';
	const LOG_META     = '_smd_billing_admin_log';

	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_smd_billing_overview', array( __CLASS__, 'ajax_overview' ) );
		add_action( 'wp_ajax_smd_billing_action', array( __CLASS__, 'ajax_action' ) );
		add_filter( 'manage_' . SMD_Member_Post_Type::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_' . SMD_Member_Post_Type::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 20, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filter' ) );
	}

	/**
	 * Billing state of a member, from local data only (no Stripe call).
	 *
	 * @param int $member_id Member ID.
	 * @return string lapsed|in_grace|past_due|canceled|active|none
	 */
	public static function get_billing_state( $member_id ) {
		if ( 'lapsed' === get_post_meta( $member_id, '_smd_access_status', true ) ) {
			return 'lapsed';
		}

		if ( get_post_meta( $member_id, '_smd_payment_failed_at', true ) ) {
			return 'in_grace';
		}

		$status = (string) get_post_meta( $member_id, '_smd_billing_status', true );

		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			return 'active';
		}

		if ( in_array( $status, array( 'past_due', 'unpaid', 'payment_failed' ), true ) ) {
			return 'past_due';
		}

		if ( in_array( $status, array( 'canceled', 'cancelled', 'incomplete_expired' ), true ) ) {
			return 'canceled';
		}

		return 'none';
	}

	/**
	 * Labels for billing states.
	 *
	 * @return array
	 */
	public static function state_labels() {
		return array(
			'active'   => __( 'Active', 'strong-members-directory' ),
			'in_grace' => __( 'In grace period', 'strong-members-directory' ),
			'past_due' => __( 'Past due', 'strong-members-directory' ),
			'lapsed'   => __( 'Lapsed', 'strong-members-directory' ),
			'canceled' => __( 'Canceled', 'strong-members-directory' ),
			'none'     => __( 'No subscription', 'strong-members-directory' ),
		);
	}

	/**
	 * Add the billing column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns['smd_billing_state'] = __( 'Billing', 'strong-members-directory' );

		return $columns;
	}

	/**
	 * Render the billing column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Member ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'smd_billing_state' !== $column ) {
			return;
		}

		$state  = self::get_billing_state( (int) $post_id );
		$labels = self::state_labels();
		$colors = array(
			'active'   => '#1a7f37',
			'in_grace' => '#9a6700',
			'past_due' => '#9a6700',
			'lapsed'   => '#cf222e',
			'canceled' => '#57606a',
			'none'     => '#57606a',
		);

		printf( '<span style="font-weight:600;color:%s">%s</span>', esc_attr( $colors[ $state ] ), esc_html( $labels[ $state ] ) );
	}

	/**
	 * Billing filter dropdown on the Members list.
	 *
	 * @param string $post_type Post type.
	 */
	public static function render_filter( $post_type ) {
		if ( SMD_Member_Post_Type::POST_TYPE !== $post_type ) {
			return;
		}

		$current = isset( $_GET['smd_billing_state'] ) ? sanitize_key( wp_unslash( $_GET['smd_billing_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<select name="smd_billing_state"><option value="">' . esc_html__( 'All billing states', 'strong-members-directory' ) . '</option>';
		foreach ( self::state_labels() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Apply the billing filter to the Members list query.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || SMD_Member_Post_Type::POST_TYPE !== $query->get( 'post_type' ) || empty( $_GET['smd_billing_state'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$state = sanitize_key( wp_unslash( $_GET['smd_billing_state'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$not   = array(
			array( 'key' => '_smd_access_status', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_smd_payment_failed_at', 'compare' => 'NOT EXISTS' ),
		);

		switch ( $state ) {
			case 'lapsed':
				$meta = array( array( 'key' => '_smd_access_status', 'value' => 'lapsed' ) );
				break;
			case 'in_grace':
				$meta = array(
					array( 'key' => '_smd_payment_failed_at', 'compare' => 'EXISTS' ),
					array( 'key' => '_smd_access_status', 'compare' => 'NOT EXISTS' ),
				);
				break;
			case 'active':
				$meta = array_merge( $not, array( array( 'key' => '_smd_billing_status', 'value' => array( 'active', 'trialing' ), 'compare' => 'IN' ) ) );
				break;
			case 'past_due':
				$meta = array_merge( $not, array( array( 'key' => '_smd_billing_status', 'value' => array( 'past_due', 'unpaid', 'payment_failed' ), 'compare' => 'IN' ) ) );
				break;
			case 'canceled':
				$meta = array_merge( $not, array( array( 'key' => '_smd_billing_status', 'value' => array( 'canceled', 'cancelled', 'incomplete_expired' ), 'compare' => 'IN' ) ) );
				break;
			case 'none':
				$meta = array_merge(
					$not,
					array(
						array(
							'relation' => 'OR',
							array( 'key' => '_smd_billing_status', 'compare' => 'NOT EXISTS' ),
							array( 'key' => '_smd_billing_status', 'value' => array( 'active', 'trialing', 'past_due', 'unpaid', 'payment_failed', 'canceled', 'cancelled', 'incomplete_expired' ), 'compare' => 'NOT IN' ),
						),
					)
				);
				break;
			default:
				return;
		}

		$existing = (array) $query->get( 'meta_query' );
		$query->set( 'meta_query', array_merge( array( 'relation' => 'AND' ), $existing, $meta ) );
	}

	/**
	 * Register the panel on the member edit screen.
	 */
	public static function register_meta_box() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_meta_box( 'smd-stripe-billing', __( 'Stripe Billing', 'strong-members-directory' ), array( __CLASS__, 'render_meta_box' ), SMD_Member_Post_Type::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Render the (initially empty) panel; JavaScript loads live Stripe data.
	 *
	 * @param WP_Post $post Member post.
	 */
	public static function render_meta_box( $post ) {
		$fields = SMD_Member_Post_Type::get_member_data( $post->ID );
		echo '<div id="smd-billing-panel" data-member="' . esc_attr( (string) $post->ID ) . '">';
		if ( empty( $fields['stripe_customer_id'] ) ) {
			echo '<p>' . esc_html__( 'This member is not linked to a Stripe customer yet. They will be linked when they start a subscription or are matched by the Stripe import.', 'strong-members-directory' ) . '</p>';
		} else {
			echo '<p class="smd-billing-loading">' . esc_html__( 'Loading billing details from Stripe…', 'strong-members-directory' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Load panel assets on the member edit screen.
	 *
	 * @param string $hook Admin hook.
	 */
	public static function enqueue( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || SMD_Member_Post_Type::POST_TYPE !== $screen->post_type || 'post' !== $screen->base || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script( 'smd-billing-admin', SMD_PLUGIN_URL . 'assets/js/admin-billing.js', array(), SMD_VERSION, true );
		wp_localize_script(
			'smd-billing-admin',
			'SMDBilling',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	/**
	 * Common checks for AJAX requests. Returns the member's fields.
	 *
	 * @return array
	 */
	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage billing.', 'strong-members-directory' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$member_id = isset( $_POST['member_id'] ) ? (int) $_POST['member_id'] : 0;
		if ( ! $member_id || SMD_Member_Post_Type::POST_TYPE !== get_post_type( $member_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid member.', 'strong-members-directory' ) ), 400 );
		}

		$fields              = SMD_Member_Post_Type::get_member_data( $member_id );
		$fields['member_id'] = $member_id;

		if ( empty( $fields['stripe_customer_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This member has no Stripe customer yet.', 'strong-members-directory' ) ), 400 );
		}

		return $fields;
	}

	/**
	 * Unwrap a Stripe response or stop with a JSON error.
	 *
	 * @param array|WP_Error $result Result.
	 * @return array
	 */
	private static function unwrap( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		return $result;
	}

	/**
	 * Live overview: subscription, card and payment history.
	 */
	public static function ajax_overview() {
		$fields      = self::authorize();
		$customer_id = (string) $fields['stripe_customer_id'];
		$sub_id      = (string) $fields['stripe_subscription_id'];

		$subscription = null;
		if ( '' !== $sub_id ) {
			$sub_result = SMD_Stripe::api( 'GET', '/subscriptions/' . rawurlencode( $sub_id ), array( 'expand' => array( 'default_payment_method' ) ) );
			if ( ! is_wp_error( $sub_result ) ) {
				$subscription = $sub_result;
			}
		}

		if ( ! $subscription ) {
			$list = self::unwrap( SMD_Stripe::api( 'GET', '/subscriptions', array( 'customer' => $customer_id, 'status' => 'all', 'limit' => 1, 'expand' => array( 'data.default_payment_method' ) ) ) );
			$subscription = ! empty( $list['data'][0] ) ? $list['data'][0] : null;
		}

		$card = null;
		$pm   = $subscription['default_payment_method'] ?? null;
		if ( ! is_array( $pm ) ) {
			$customer = SMD_Stripe::api( 'GET', '/customers/' . rawurlencode( $customer_id ), array( 'expand' => array( 'invoice_settings.default_payment_method' ) ) );
			$pm       = ! is_wp_error( $customer ) ? ( $customer['invoice_settings']['default_payment_method'] ?? null ) : null;
		}
		if ( is_array( $pm ) && ! empty( $pm['card'] ) ) {
			$card = array(
				'brand'     => (string) $pm['card']['brand'],
				'last4'     => (string) $pm['card']['last4'],
				'exp_month' => (int) $pm['card']['exp_month'],
				'exp_year'  => (int) $pm['card']['exp_year'],
			);
		}

		$invoice_list = self::unwrap( SMD_Stripe::api( 'GET', '/invoices', array( 'customer' => $customer_id, 'limit' => 24, 'expand' => array( 'data.charge' ) ) ) );
		$invoices     = array();

		foreach ( (array) ( $invoice_list['data'] ?? array() ) as $invoice ) {
			$charge   = is_array( $invoice['charge'] ?? null ) ? $invoice['charge'] : null;
			$status   = (string) $invoice['status'];
			$attempts = (int) ( $invoice['attempt_count'] ?? 0 );

			if ( 'open' === $status && $attempts > 0 ) {
				$status = 'failed';
			}

			$invoices[] = array(
				'id'              => (string) $invoice['id'],
				'number'          => (string) ( $invoice['number'] ?? '' ),
				'created'         => (int) $invoice['created'],
				'status'          => $status,
				'currency'        => (string) $invoice['currency'],
				'amount_due'      => (int) $invoice['amount_due'],
				'amount_paid'     => (int) $invoice['amount_paid'],
				'attempts'        => $attempts,
				'next_attempt'    => (int) ( $invoice['next_payment_attempt'] ?? 0 ),
				'hosted_url'      => (string) ( $invoice['hosted_invoice_url'] ?? '' ),
				'charge_id'       => $charge ? (string) $charge['id'] : '',
				'amount_refunded' => $charge ? (int) $charge['amount_refunded'] : 0,
				'failure_message' => $charge ? (string) ( $charge['failure_message'] ?? '' ) : '',
			);
		}

		$log = get_post_meta( $fields['member_id'], self::LOG_META, true );

		wp_send_json_success(
			array(
				'state'        => self::get_billing_state( $fields['member_id'] ),
				'state_label'  => self::state_labels()[ self::get_billing_state( $fields['member_id'] ) ],
				'subscription' => $subscription ? array(
					'id'                   => (string) $subscription['id'],
					'status'               => (string) $subscription['status'],
					'currency'             => (string) ( $subscription['currency'] ?? '' ),
					'amount'               => (int) ( $subscription['items']['data'][0]['price']['unit_amount'] ?? 0 ),
					'interval'             => (string) ( $subscription['items']['data'][0]['price']['recurring']['interval'] ?? '' ),
					'interval_count'       => (int) ( $subscription['items']['data'][0]['price']['recurring']['interval_count'] ?? 1 ),
					'current_period_end'   => (int) ( $subscription['current_period_end'] ?? 0 ),
					'cancel_at_period_end' => ! empty( $subscription['cancel_at_period_end'] ),
					'paused'               => ! empty( $subscription['pause_collection'] ),
				) : null,
				'card'         => $card,
				'invoices'     => $invoices,
				'log'          => is_array( $log ) ? array_slice( array_reverse( $log ), 0, 15 ) : array(),
			)
		);
	}

	/**
	 * Run a billing action.
	 */
	public static function ajax_action() {
		$fields      = self::authorize();
		$member_id   = (int) $fields['member_id'];
		$customer_id = (string) $fields['stripe_customer_id'];
		$sub_id      = (string) $fields['stripe_subscription_id'];
		$do          = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$target      = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$amount      = isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0;
		$message     = '';

		if ( in_array( $do, array( 'pause', 'resume', 'cancel_at_period_end', 'keep', 'cancel_now' ), true ) && '' === $sub_id ) {
			wp_send_json_error( array( 'message' => __( 'This member has no subscription to change.', 'strong-members-directory' ) ), 400 );
		}

		switch ( $do ) {
			case 'send_card_link':
				$link = method_exists( 'SMD_Stripe', 'get_billing_hub_url' ) ? SMD_Stripe::get_billing_hub_url( 'member', $member_id ) : '';
				if ( '' === $link ) {
					$link = SMD_Settings::get_dashboard_url();
				}
				if ( '' === $link || ! is_email( $fields['email'] ) ) {
					wp_send_json_error( array( 'message' => __( 'No member email or billing page is available to send a link.', 'strong-members-directory' ) ), 400 );
				}
				wp_mail(
					(string) $fields['email'],
					__( 'Please update your 100 Strong billing details', 'strong-members-directory' ),
					sprintf(
						/* translators: 1: first name, 2: link */
						__( "Hi %1\$s,\n\nUse this link to update your card and manage your 100 Strong membership billing:\n\n%2\$s\n\n100 Strong", 'strong-members-directory' ),
						'' !== (string) $fields['first_name'] ? (string) $fields['first_name'] : __( 'there', 'strong-members-directory' ),
						$link
					)
				);
				$message = __( 'Update-card link emailed to the member.', 'strong-members-directory' );
				break;

			case 'pause':
				self::unwrap( SMD_Stripe::api( 'POST', '/subscriptions/' . rawurlencode( $sub_id ), array( 'pause_collection' => array( 'behavior' => 'void' ) ) ) );
				$message = __( 'Billing paused. No charges until resumed.', 'strong-members-directory' );
				break;

			case 'resume':
				self::unwrap( SMD_Stripe::api( 'POST', '/subscriptions/' . rawurlencode( $sub_id ), array( 'pause_collection' => '' ) ) );
				$message = __( 'Billing resumed.', 'strong-members-directory' );
				break;

			case 'cancel_at_period_end':
				self::unwrap( SMD_Stripe::api( 'POST', '/subscriptions/' . rawurlencode( $sub_id ), array( 'cancel_at_period_end' => 'true' ) ) );
				$message = __( 'Subscription will cancel at the end of the current period.', 'strong-members-directory' );
				break;

			case 'keep':
				self::unwrap( SMD_Stripe::api( 'POST', '/subscriptions/' . rawurlencode( $sub_id ), array( 'cancel_at_period_end' => 'false' ) ) );
				$message = __( 'Scheduled cancellation removed.', 'strong-members-directory' );
				break;

			case 'cancel_now':
				self::unwrap( SMD_Stripe::api( 'DELETE', '/subscriptions/' . rawurlencode( $sub_id ) ) );
				update_post_meta( $member_id, '_smd_billing_status', 'canceled' );
				$message = __( 'Subscription canceled immediately.', 'strong-members-directory' );
				break;

			case 'refund':
				$charge = self::unwrap( SMD_Stripe::api( 'GET', '/charges/' . rawurlencode( $target ) ) );
				if ( ( $charge['customer'] ?? '' ) !== $customer_id ) {
					wp_send_json_error( array( 'message' => __( 'That payment does not belong to this member.', 'strong-members-directory' ) ), 403 );
				}
				$refundable = (int) $charge['amount'] - (int) $charge['amount_refunded'];
				if ( $refundable <= 0 ) {
					wp_send_json_error( array( 'message' => __( 'This payment is already fully refunded.', 'strong-members-directory' ) ), 400 );
				}
				$amount = $amount > 0 ? min( $amount, $refundable ) : $refundable;
				self::unwrap(
					SMD_Stripe::api(
						'POST',
						'/refunds',
						array(
							'charge' => $target,
							'amount' => $amount,
							'reason' => 'requested_by_customer',
						),
						array( 'Idempotency-Key' => 'smd-refund-' . md5( $target . '|' . $amount . '|' . (int) $charge['amount_refunded'] ) )
					)
				);
				$message = sprintf( /* translators: %s: amount */ __( 'Refunded %s.', 'strong-members-directory' ), number_format_i18n( $amount / 100, 2 ) . ' ' . strtoupper( (string) $charge['currency'] ) );
				break;

			case 'send_receipt':
				$invoice = self::unwrap( SMD_Stripe::api( 'GET', '/invoices/' . rawurlencode( $target ) ) );
				if ( ( $invoice['customer'] ?? '' ) !== $customer_id || empty( $invoice['hosted_invoice_url'] ) || ! is_email( $fields['email'] ) ) {
					wp_send_json_error( array( 'message' => __( 'That invoice cannot be sent to this member.', 'strong-members-directory' ) ), 400 );
				}
				wp_mail(
					(string) $fields['email'],
					__( 'Your 100 Strong receipt', 'strong-members-directory' ),
					sprintf( /* translators: %s: url */ __( "Here is your 100 Strong receipt:\n\n%s\n\n100 Strong", 'strong-members-directory' ), $invoice['hosted_invoice_url'] )
				);
				$message = __( 'Receipt emailed to the member.', 'strong-members-directory' );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Unknown action.', 'strong-members-directory' ) ), 400 );
		}

		self::log( $member_id, $do, $message );
		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * Record a staff action on the member.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $action    Action key.
	 * @param string $detail    Human-readable result.
	 */
	private static function log( $member_id, $action, $detail ) {
		$log   = get_post_meta( $member_id, self::LOG_META, true );
		$log   = is_array( $log ) ? $log : array();
		$user  = wp_get_current_user();
		$log[] = array(
			'time'   => time(),
			'user'   => $user ? $user->display_name : '',
			'action' => $action,
			'detail' => $detail,
		);

		update_post_meta( $member_id, self::LOG_META, array_slice( $log, -100 ) );
	}
}
