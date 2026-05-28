<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMD_Stripe {
	const IMPORT_PREVIEW_TRANSIENT_PREFIX = 'smd_stripe_import_preview_';
	const IMPORT_REPORT_TRANSIENT_PREFIX  = 'smd_stripe_import_report_';

	/**
	 * Register Stripe-related hooks.
	 */
	public static function hooks() {
		add_action( 'admin_post_smd_preview_stripe_subscription_import', array( __CLASS__, 'handle_preview_subscription_import' ) );
		add_action( 'admin_post_smd_commit_stripe_subscription_import', array( __CLASS__, 'handle_commit_subscription_import' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'handle_member_deletion' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_frontend_billing_action' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Cancel the member subscription in Stripe before a member record is permanently deleted.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public static function handle_member_deletion( $post_id ) {
		if ( SMD_Member_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$fields = SMD_Member_Post_Type::get_member_data( $post_id );

		if ( ! empty( $fields['stripe_subscription_id'] ) && self::get_secret_key() ) {
			$response = self::stripe_request( 'DELETE', '/subscriptions/' . rawurlencode( (string) $fields['stripe_subscription_id'] ) );

			if ( is_wp_error( $response ) ) {
				wp_die(
					esc_html(
						sprintf(
							/* translators: %s: Stripe error message */
							__( 'This member could not be deleted because the Stripe subscription cancellation failed: %s', 'strong-members-directory' ),
							$response->get_error_message()
						)
					),
					esc_html__( 'Stripe Cancellation Failed', 'strong-members-directory' ),
					array(
						'response'  => 500,
						'back_link' => true,
					)
				);
			}
		}

		$mailchimp_result = self::remove_member_from_mailchimp( $fields );
		if ( is_wp_error( $mailchimp_result ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: Mailchimp error message */
						__( 'This member could not be deleted because the Mailchimp removal failed: %s', 'strong-members-directory' ),
						$mailchimp_result->get_error_message()
					)
				),
				esc_html__( 'Mailchimp Removal Failed', 'strong-members-directory' ),
				array(
					'response'  => 500,
					'back_link' => true,
				)
			);
		}

		self::send_member_cancellation_email( $fields );

		if ( class_exists( 'SMD_Auth' ) ) {
			SMD_Auth::revoke_member_user_access( $post_id );
		}
	}

	/**
	 * Whether Stripe billing is configured enough to run.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '' !== self::get_secret_key() && '' !== self::get_price_id();
	}

	/**
	 * Start the hosted Stripe Checkout subscription flow.
	 */
	public static function handle_start_membership_checkout() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to start membership billing.', 'strong-members-directory' ) );
		}

		$member_id = self::get_request_member_id();
		$user_id   = get_current_user_id();

		if ( ! $member_id || ! SMD_Auth::user_can_edit_member( $user_id, $member_id ) ) {
			wp_die( esc_html__( 'You are not allowed to manage billing for this member.', 'strong-members-directory' ) );
		}

		if ( ! self::is_enabled() ) {
			self::redirect_to_member(
				$member_id,
				array(
					'smd_billing_error' => rawurlencode( __( 'Stripe billing is not configured yet.', 'strong-members-directory' ) ),
				)
			);
		}

		$fields      = SMD_Member_Post_Type::get_member_data( $member_id );
		$customer_id = self::get_or_create_customer_id( $member_id, $fields );

		if ( is_wp_error( $customer_id ) ) {
			self::redirect_to_member(
				$member_id,
				array(
					'smd_billing_error' => rawurlencode( $customer_id->get_error_message() ),
				)
			);
		}

		$member_url = self::get_member_return_url( $member_id );
		$session    = self::stripe_request(
			'POST',
			'/checkout/sessions',
			array(
				'mode'                                      => 'subscription',
				'customer'                                  => $customer_id,
				'success_url'                               => add_query_arg( 'smd_billing_notice', rawurlencode( __( 'Your membership subscription setup is in progress.', 'strong-members-directory' ) ), $member_url ),
				'cancel_url'                                => add_query_arg( 'smd_billing_error', rawurlencode( __( 'Membership signup was canceled.', 'strong-members-directory' ) ), $member_url ),
				'client_reference_id'                       => (string) $member_id,
				'line_items[0][price]'                      => self::get_price_id(),
				'line_items[0][quantity]'                   => 1,
				'subscription_data[metadata][member_id]'    => (string) $member_id,
				'subscription_data[metadata][user_id]'      => (string) $user_id,
				'subscription_data[metadata][member_email]' => (string) $fields['email'],
			)
		);

		if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
			self::redirect_to_member(
				$member_id,
				array(
					'smd_billing_error' => rawurlencode( is_wp_error( $session ) ? $session->get_error_message() : __( 'Stripe Checkout could not be started.', 'strong-members-directory' ) ),
				)
			);
		}

		wp_redirect( esc_url_raw( $session['url'] ) );
		exit;
	}

	/**
	 * Open the Stripe Billing Portal for the current member.
	 */
	public static function handle_open_billing_portal() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to manage billing.', 'strong-members-directory' ) );
		}

		$member_id = self::get_request_member_id();
		$user_id   = get_current_user_id();

		if ( ! $member_id || ! SMD_Auth::user_can_edit_member( $user_id, $member_id ) ) {
			wp_die( esc_html__( 'You are not allowed to manage billing for this member.', 'strong-members-directory' ) );
		}

		if ( ! self::is_enabled() ) {
			self::redirect_to_member(
				$member_id,
				array(
					'smd_billing_error' => rawurlencode( __( 'Stripe billing is not configured yet.', 'strong-members-directory' ) ),
				)
			);
		}

		$fields      = SMD_Member_Post_Type::get_member_data( $member_id );
		$customer_id = self::get_or_create_customer_id( $member_id, $fields );

		if ( is_wp_error( $customer_id ) ) {
			self::redirect_to_member(
				$member_id,
				array(
					'smd_billing_error' => rawurlencode( $customer_id->get_error_message() ),
				)
			);
		}

		$session = self::stripe_request(
			'POST',
			'/billing_portal/sessions',
			array(
				'customer'   => $customer_id,
				'return_url' => self::get_member_return_url( $member_id ),
			)
		);

		if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
			self::redirect_to_member(
				$member_id,
				array(
					'smd_billing_error' => rawurlencode( is_wp_error( $session ) ? $session->get_error_message() : __( 'Stripe Billing Portal could not be opened.', 'strong-members-directory' ) ),
				)
			);
		}

		wp_redirect( esc_url_raw( $session['url'] ) );
		exit;
	}

	/**
	 * Register webhook route.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'smd/v1',
			'/stripe/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle front-end billing action query and redirect to Stripe.
	 */
	public static function handle_frontend_billing_action() {
		if ( is_admin() || ! isset( $_GET['smd_billing_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$action       = isset( $_GET['smd_billing_action'] ) ? sanitize_key( wp_unslash( $_GET['smd_billing_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$member_id    = isset( $_GET['member_id'] ) ? (int) $_GET['member_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$applicant_id = isset( $_GET['applicant_id'] ) ? (int) $_GET['applicant_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expires      = isset( $_GET['smd_billing_expires'] ) ? (int) $_GET['smd_billing_expires'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$signature    = isset( $_GET['smd_billing_sig'] ) ? (string) wp_unslash( $_GET['smd_billing_sig'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, array( 'start_subscription', 'open_billing_portal', 'start_applicant_subscription' ), true ) ) {
			wp_die( esc_html__( 'Invalid billing action.', 'strong-members-directory' ) );
		}

		$subject_type = 'start_applicant_subscription' === $action ? 'applicant' : 'member';
		$subject_id   = 'applicant' === $subject_type ? $applicant_id : $member_id;

		if ( ! self::verify_billing_action_signature( $action, $subject_type, $subject_id, $expires, $signature ) ) {
			if ( $member_id ) {
				self::redirect_to_member(
					$member_id,
					array(
						'smd_billing_error' => rawurlencode( __( 'Billing link verification failed.', 'strong-members-directory' ) ),
					)
				);
			}

			if ( $applicant_id ) {
				self::redirect_to_applicant(
					$applicant_id,
					array(
						'smd_billing_error' => rawurlencode( __( 'Billing link verification failed.', 'strong-members-directory' ) ),
					)
				);
			}

			wp_die( esc_html__( 'Billing link verification failed.', 'strong-members-directory' ) );
		}

		if ( 'start_applicant_subscription' === $action ) {
			self::handle_start_applicant_checkout();
		}

		if ( 'start_subscription' === $action ) {
			self::handle_start_membership_checkout();
		}

		self::handle_open_billing_portal();
	}

	/**
	 * Try to finish applicant onboarding when the browser returns from Stripe.
	 *
	 * @param int    $applicant_id Applicant ID.
	 * @param string $session_id Optional Checkout Session ID from Stripe redirect.
	 */
	public static function maybe_finalize_applicant_after_billing_return( $applicant_id, $session_id = '' ) {
		if ( ! $applicant_id || SMD_Applicant_Post_Type::POST_TYPE !== get_post_type( $applicant_id ) ) {
			return;
		}

		$fields = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );

		if ( ! empty( $fields['linked_member_id'] ) ) {
			return;
		}

		if ( '' !== $session_id ) {
			$session = self::stripe_request( 'GET', '/checkout/sessions/' . rawurlencode( $session_id ) );

			if ( ! is_wp_error( $session ) ) {
				if ( ! empty( $session['customer'] ) ) {
					update_post_meta( $applicant_id, '_smd_applicant_stripe_customer_id', sanitize_text_field( (string) $session['customer'] ) );
				}

				if ( ! empty( $session['subscription'] ) ) {
					update_post_meta( $applicant_id, '_smd_applicant_stripe_subscription_id', sanitize_text_field( (string) $session['subscription'] ) );
				}
			}
		}

		$fields = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );

		if ( ! empty( $fields['stripe_subscription_id'] ) ) {
			$subscription = self::stripe_request( 'GET', '/subscriptions/' . rawurlencode( (string) $fields['stripe_subscription_id'] ) );

			if ( ! is_wp_error( $subscription ) && ! empty( $subscription['status'] ) ) {
				SMD_Applications::finalize_paid_applicant_onboarding(
					$applicant_id,
					array(
						'customer'           => ! empty( $subscription['customer'] ) ? (string) $subscription['customer'] : (string) $fields['stripe_customer_id'],
						'subscription'       => ! empty( $subscription['id'] ) ? (string) $subscription['id'] : (string) $fields['stripe_subscription_id'],
						'status'             => sanitize_text_field( (string) $subscription['status'] ),
						'current_period_end' => ! empty( $subscription['current_period_end'] ) ? (int) $subscription['current_period_end'] : 0,
					)
				);

				return;
			}
		}

		if ( in_array( $fields['status'], array( 'approved_awaiting_billing', 'onboarded' ), true ) ) {
			SMD_Applications::finalize_paid_applicant_onboarding(
				$applicant_id,
				array(
					'customer'     => (string) $fields['stripe_customer_id'],
					'subscription' => (string) $fields['stripe_subscription_id'],
					'status'       => $fields['billing_status'] ? (string) $fields['billing_status'] : 'checkout_completed',
				)
			);
		}
	}

	/**
	 * Start the hosted Stripe Checkout flow for an approved applicant.
	 */
	public static function handle_start_applicant_checkout() {
		$applicant_id = self::get_request_applicant_id();

		if ( ! $applicant_id || SMD_Applicant_Post_Type::POST_TYPE !== get_post_type( $applicant_id ) ) {
			wp_die( esc_html__( 'This billing link is no longer valid.', 'strong-members-directory' ) );
		}

		$fields = SMD_Applicant_Post_Type::get_applicant_data( $applicant_id );

		if ( ! in_array( $fields['status'], array( 'approved_awaiting_billing', 'onboarded' ), true ) ) {
			self::redirect_to_applicant(
				$applicant_id,
				array(
					'smd_billing_error' => rawurlencode( __( 'This applicant is not ready for billing setup yet.', 'strong-members-directory' ) ),
				)
			);
		}

		if ( ! self::is_enabled() ) {
			self::redirect_to_applicant(
				$applicant_id,
				array(
					'smd_billing_error' => rawurlencode( __( 'Stripe billing is not configured yet.', 'strong-members-directory' ) ),
				)
			);
		}

		$customer_id = self::get_or_create_applicant_customer_id( $applicant_id, $fields );

		if ( is_wp_error( $customer_id ) ) {
			self::redirect_to_applicant(
				$applicant_id,
				array(
					'smd_billing_error' => rawurlencode( $customer_id->get_error_message() ),
				)
			);
		}

		$return_url = self::get_applicant_return_url( $applicant_id );
		$session    = self::stripe_request(
			'POST',
			'/checkout/sessions',
			array(
				'mode'                                       => 'subscription',
				'customer'                                   => $customer_id,
				'success_url'                                => add_query_arg(
					array(
						'smd_billing_notice'    => rawurlencode( __( 'Your billing setup was received. We are finishing your member onboarding now.', 'strong-members-directory' ) ),
						'smd_stripe_session_id' => '{CHECKOUT_SESSION_ID}',
					),
					$return_url
				),
				'cancel_url'                                 => add_query_arg( 'smd_billing_error', rawurlencode( __( 'Billing setup was canceled before it finished.', 'strong-members-directory' ) ), $return_url ),
				'client_reference_id'                        => 'applicant_' . $applicant_id,
				'line_items[0][price]'                       => self::get_price_id(),
				'line_items[0][quantity]'                    => 1,
				'metadata[applicant_id]'                     => (string) $applicant_id,
				'subscription_data[metadata][applicant_id]'  => (string) $applicant_id,
				'subscription_data[metadata][applicant_email]'=> (string) $fields['email'],
			)
		);

		if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
			self::redirect_to_applicant(
				$applicant_id,
				array(
					'smd_billing_error' => rawurlencode( is_wp_error( $session ) ? $session->get_error_message() : __( 'Stripe Checkout could not be started.', 'strong-members-directory' ) ),
				)
			);
		}

		update_post_meta( $applicant_id, '_smd_applicant_billing_status', 'checkout_started' );
		wp_redirect( esc_url_raw( $session['url'] ) );
		exit;
	}

	/**
	 * Handle Stripe webhooks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( WP_REST_Request $request ) {
		$payload    = $request->get_body();
		$signature  = (string) $request->get_header( 'stripe-signature' );
		$secret     = self::get_webhook_secret();
		$event_data = json_decode( $payload, true );

		if ( '' !== $secret ) {
			$verification = self::verify_webhook_signature( $payload, $signature, $secret );
			if ( is_wp_error( $verification ) ) {
				return new WP_REST_Response(
					array( 'error' => $verification->get_error_message() ),
					400
				);
			}
		}

		if ( ! is_array( $event_data ) || empty( $event_data['type'] ) ) {
			return new WP_REST_Response( array( 'received' => false ), 400 );
		}

		self::process_event( $event_data );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Process supported Stripe events.
	 *
	 * @param array $event_data Event payload.
	 */
	private static function process_event( $event_data ) {
		$type   = isset( $event_data['type'] ) ? (string) $event_data['type'] : '';
		$object = isset( $event_data['data']['object'] ) && is_array( $event_data['data']['object'] ) ? $event_data['data']['object'] : array();

		if ( 'checkout.session.completed' === $type ) {
			self::handle_checkout_completed( $object );
			return;
		}

		if ( in_array( $type, array( 'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' ), true ) ) {
			self::handle_subscription_event( $object, $type );
			return;
		}

		if ( 'invoice.payment_failed' === $type ) {
			self::handle_invoice_payment_failed( $object );
			return;
		}

		if ( 'invoice.paid' === $type ) {
			self::handle_invoice_paid( $object );
		}
	}

	/**
	 * Update local member state after Checkout completes.
	 *
	 * @param array $session Stripe Checkout session object.
	 */
	private static function handle_checkout_completed( $session ) {
		if ( empty( $session['mode'] ) || 'subscription' !== $session['mode'] ) {
			return;
		}

		$applicant_id = ! empty( $session['metadata']['applicant_id'] ) ? (int) $session['metadata']['applicant_id'] : 0;
		if ( $applicant_id ) {
			if ( ! empty( $session['customer'] ) ) {
				update_post_meta( $applicant_id, '_smd_applicant_stripe_customer_id', sanitize_text_field( (string) $session['customer'] ) );
			}

			if ( ! empty( $session['subscription'] ) ) {
				update_post_meta( $applicant_id, '_smd_applicant_stripe_subscription_id', sanitize_text_field( (string) $session['subscription'] ) );
			}

			update_post_meta( $applicant_id, '_smd_applicant_billing_status', 'checkout_completed' );

			if ( ! empty( $session['subscription'] ) ) {
				$subscription = self::stripe_request( 'GET', '/subscriptions/' . rawurlencode( (string) $session['subscription'] ) );

				if ( ! is_wp_error( $subscription ) && ! empty( $subscription['status'] ) ) {
					$status = sanitize_text_field( (string) $subscription['status'] );
					update_post_meta( $applicant_id, '_smd_applicant_billing_status', $status );

					if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
						SMD_Applications::finalize_paid_applicant_onboarding(
							$applicant_id,
							array(
								'customer'           => ! empty( $subscription['customer'] ) ? (string) $subscription['customer'] : '',
								'subscription'       => ! empty( $subscription['id'] ) ? (string) $subscription['id'] : (string) $session['subscription'],
								'status'             => $status,
								'current_period_end' => ! empty( $subscription['current_period_end'] ) ? (int) $subscription['current_period_end'] : 0,
							)
						);
					}
				}
			}

			return;
		}

		$member_id = isset( $session['client_reference_id'] ) ? (int) $session['client_reference_id'] : 0;
		if ( ! $member_id && ! empty( $session['customer'] ) ) {
			$member_id = self::find_member_by_customer_id( (string) $session['customer'] );
		}

		if ( ! $member_id ) {
			return;
		}

		if ( ! empty( $session['customer'] ) ) {
			update_post_meta( $member_id, '_smd_stripe_customer_id', sanitize_text_field( (string) $session['customer'] ) );
		}

		if ( ! empty( $session['subscription'] ) ) {
			update_post_meta( $member_id, '_smd_stripe_subscription_id', sanitize_text_field( (string) $session['subscription'] ) );
		}
	}

	/**
	 * Handle subscription lifecycle events.
	 *
	 * @param array  $subscription Subscription object.
	 * @param string $event_type Event type.
	 */
	private static function handle_subscription_event( $subscription, $event_type ) {
		$applicant_id = self::find_applicant_for_subscription_event( $subscription );

		if ( $applicant_id ) {
			$status = isset( $subscription['status'] ) ? sanitize_text_field( (string) $subscription['status'] ) : '';
			update_post_meta( $applicant_id, '_smd_applicant_stripe_customer_id', isset( $subscription['customer'] ) ? sanitize_text_field( (string) $subscription['customer'] ) : '' );
			update_post_meta( $applicant_id, '_smd_applicant_stripe_subscription_id', isset( $subscription['id'] ) ? sanitize_text_field( (string) $subscription['id'] ) : '' );
			update_post_meta( $applicant_id, '_smd_applicant_billing_status', $status );

			if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
				SMD_Applications::finalize_paid_applicant_onboarding(
					$applicant_id,
					array(
						'customer'           => isset( $subscription['customer'] ) ? (string) $subscription['customer'] : '',
						'subscription'       => isset( $subscription['id'] ) ? (string) $subscription['id'] : '',
						'status'             => $status,
						'current_period_end' => ! empty( $subscription['current_period_end'] ) ? (int) $subscription['current_period_end'] : 0,
					)
				);
			}

			return;
		}

		$member_id = self::find_member_for_subscription_event( $subscription );

		if ( ! $member_id ) {
			return;
		}

		$status = isset( $subscription['status'] ) ? sanitize_text_field( (string) $subscription['status'] ) : '';
		update_post_meta( $member_id, '_smd_stripe_customer_id', isset( $subscription['customer'] ) ? sanitize_text_field( (string) $subscription['customer'] ) : '' );
		update_post_meta( $member_id, '_smd_stripe_subscription_id', isset( $subscription['id'] ) ? sanitize_text_field( (string) $subscription['id'] ) : '' );
		update_post_meta( $member_id, '_smd_billing_status', $status );

		if ( ! empty( $subscription['current_period_end'] ) ) {
			update_post_meta( $member_id, '_smd_billing_period_end', gmdate( 'c', (int) $subscription['current_period_end'] ) );
		}

		if ( 'customer.subscription.deleted' === $event_type ) {
			update_post_meta( $member_id, '_smd_billing_status', 'canceled' );
		}

		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			delete_post_meta( $member_id, '_smd_payment_failed_at' );
			delete_post_meta( $member_id, '_smd_payment_failed_note' );
		}
	}

	/**
	 * Build a dry-run preview for existing Stripe subscriptions.
	 */
	public static function handle_preview_subscription_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import Stripe subscriptions.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_preview_stripe_subscription_import', 'smd_preview_stripe_subscription_import_nonce' );

		if ( '' === self::get_secret_key() || '' === self::get_price_id() ) {
			self::redirect_to_settings(
				array(
					'smd_stripe_import_error' => rawurlencode( __( 'Please configure the Stripe secret key and quarterly Price ID first.', 'strong-members-directory' ) ),
				)
			);
		}

		$subscriptions = self::fetch_all_subscriptions_for_price( self::get_price_id() );

		if ( is_wp_error( $subscriptions ) ) {
			self::redirect_to_settings(
				array(
					'smd_stripe_import_error' => rawurlencode( $subscriptions->get_error_message() ),
				)
			);
		}

		$preview = self::build_subscription_import_preview( $subscriptions );
		$token   = wp_generate_password( 20, false, false );

		set_transient( self::IMPORT_PREVIEW_TRANSIENT_PREFIX . $token, $preview, HOUR_IN_SECONDS );

		self::redirect_to_settings(
			array(
				'smd_stripe_import_preview' => $token,
			)
		);
	}

	/**
	 * Apply a reviewed Stripe subscription import preview.
	 */
	public static function handle_commit_subscription_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import Stripe subscriptions.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_commit_stripe_subscription_import', 'smd_commit_stripe_subscription_import_nonce' );

		$token   = isset( $_POST['smd_stripe_import_preview_token'] ) ? sanitize_key( wp_unslash( $_POST['smd_stripe_import_preview_token'] ) ) : '';
		$preview = $token ? get_transient( self::IMPORT_PREVIEW_TRANSIENT_PREFIX . $token ) : false;

		if ( ! $token || ! is_array( $preview ) ) {
			self::redirect_to_settings(
				array(
					'smd_stripe_import_error' => rawurlencode( __( 'The Stripe import preview expired. Please run the preview again.', 'strong-members-directory' ) ),
				)
			);
		}

		$report = array(
			'imported'   => 0,
			'skipped'    => 0,
			'conflicts'  => 0,
			'rows'       => array(),
			'errors'     => array(),
			'total'      => isset( $preview['summary']['total'] ) ? (int) $preview['summary']['total'] : 0,
		);

		foreach ( $preview['rows'] as $row ) {
			$report['rows'][] = array(
				'subscription_id' => $row['subscription_id'],
				'customer_email'  => $row['customer_email'],
				'member'          => $row['member_label'],
				'action'          => $row['action_label'],
				'notes'           => $row['notes'],
			);

			if ( 'import' !== $row['action'] || empty( $row['member_id'] ) ) {
				if ( 'conflict' === $row['action'] ) {
					++$report['conflicts'];
				} else {
					++$report['skipped'];
				}
				continue;
			}

			self::apply_subscription_import_to_member( (int) $row['member_id'], $row['subscription'] );
			++$report['imported'];
		}

		delete_transient( self::IMPORT_PREVIEW_TRANSIENT_PREFIX . $token );

		$report_token = wp_generate_password( 20, false, false );
		set_transient( self::IMPORT_REPORT_TRANSIENT_PREFIX . $report_token, $report, HOUR_IN_SECONDS );

		self::redirect_to_settings(
			array(
				'smd_stripe_import_report' => $report_token,
			)
		);
	}

	/**
	 * Handle failed recurring charges.
	 *
	 * @param array $invoice Invoice object.
	 */
	private static function handle_invoice_payment_failed( $invoice ) {
		$member_id = self::find_member_for_invoice_event( $invoice );

		if ( ! $member_id ) {
			return;
		}

		$fields = SMD_Member_Post_Type::get_member_data( $member_id );
		$note   = __( 'A recent membership payment attempt failed. Please log in and update your card details.', 'strong-members-directory' );

		if ( ! empty( $invoice['last_finalization_error']['message'] ) ) {
			$note = sanitize_text_field( (string) $invoice['last_finalization_error']['message'] );
		}

		update_post_meta( $member_id, '_smd_billing_status', 'payment_failed' );
		update_post_meta( $member_id, '_smd_payment_failed_at', current_time( 'mysql' ) );
		update_post_meta( $member_id, '_smd_payment_failed_note', $note );

		if ( ! empty( $invoice['customer'] ) ) {
			update_post_meta( $member_id, '_smd_stripe_customer_id', sanitize_text_field( (string) $invoice['customer'] ) );
		}

		if ( ! empty( $invoice['subscription'] ) ) {
			update_post_meta( $member_id, '_smd_stripe_subscription_id', sanitize_text_field( (string) $invoice['subscription'] ) );
		}

		self::send_payment_failed_email( $member_id, $fields, $note );
	}

	/**
	 * Clear failed-payment warnings after a successful invoice payment.
	 *
	 * @param array $invoice Invoice object.
	 */
	private static function handle_invoice_paid( $invoice ) {
		$member_id = self::find_member_for_invoice_event( $invoice );

		if ( ! $member_id ) {
			return;
		}

		update_post_meta( $member_id, '_smd_billing_status', 'active' );
		delete_post_meta( $member_id, '_smd_payment_failed_at' );
		delete_post_meta( $member_id, '_smd_payment_failed_note' );
	}

	/**
	 * Email the member after a failed charge.
	 *
	 * @param int    $member_id Member ID.
	 * @param array  $fields Member fields.
	 * @param string $note Failure note.
	 */
	private static function send_payment_failed_email( $member_id, $fields, $note ) {
		$email = isset( $fields['email'] ) ? (string) $fields['email'] : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$subject = __( 'Membership payment update needed', 'strong-members-directory' );
		$message = sprintf(
			/* translators: 1: first name, 2: note, 3: member profile url */
			__( "Hi %1\$s,\n\nWe attempted to process your membership subscription payment, but it did not go through.\n\nDetails: %2\$s\n\nYour membership has not been automatically removed. Please log in and use the Manage Billing button to update your card details:\n%3\$s\n", 'strong-members-directory' ),
			(string) $fields['first_name'],
			$note,
			get_permalink( $member_id )
		);

		wp_mail( $email, $subject, $message );
	}

	/**
	 * Find a member from subscription webhook data.
	 *
	 * @param array $subscription Subscription object.
	 * @return int
	 */
	private static function find_member_for_subscription_event( $subscription ) {
		if ( ! empty( $subscription['metadata']['member_id'] ) ) {
			return (int) $subscription['metadata']['member_id'];
		}

		if ( ! empty( $subscription['customer'] ) ) {
			return self::find_member_by_customer_id( (string) $subscription['customer'] );
		}

		if ( ! empty( $subscription['id'] ) ) {
			return self::find_member_by_subscription_id( (string) $subscription['id'] );
		}

		return 0;
	}

	/**
	 * Find an applicant from subscription webhook data.
	 *
	 * @param array $subscription Subscription object.
	 * @return int
	 */
	private static function find_applicant_for_subscription_event( $subscription ) {
		if ( ! empty( $subscription['metadata']['applicant_id'] ) ) {
			return (int) $subscription['metadata']['applicant_id'];
		}

		if ( ! empty( $subscription['customer'] ) ) {
			$applicant_id = self::find_applicant_by_customer_id( (string) $subscription['customer'] );
			if ( $applicant_id ) {
				return $applicant_id;
			}
		}

		if ( ! empty( $subscription['id'] ) ) {
			return self::find_applicant_by_subscription_id( (string) $subscription['id'] );
		}

		return 0;
	}

	/**
	 * Find a member from invoice webhook data.
	 *
	 * @param array $invoice Invoice object.
	 * @return int
	 */
	private static function find_member_for_invoice_event( $invoice ) {
		if ( ! empty( $invoice['customer'] ) ) {
			$member_id = self::find_member_by_customer_id( (string) $invoice['customer'] );
			if ( $member_id ) {
				return $member_id;
			}
		}

		if ( ! empty( $invoice['subscription'] ) ) {
			return self::find_member_by_subscription_id( (string) $invoice['subscription'] );
		}

		return 0;
	}

	/**
	 * Find a member by Stripe customer ID.
	 *
	 * @param string $customer_id Customer ID.
	 * @return int
	 */
	private static function find_member_by_customer_id( $customer_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => SMD_Member_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_smd_stripe_customer_id',
				'meta_value'     => $customer_id,
			)
		);

		return ! empty( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find an applicant by Stripe customer ID.
	 *
	 * @param string $customer_id Customer ID.
	 * @return int
	 */
	private static function find_applicant_by_customer_id( $customer_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => SMD_Applicant_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_smd_applicant_stripe_customer_id',
				'meta_value'     => $customer_id,
			)
		);

		return ! empty( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find a member by Stripe subscription ID.
	 *
	 * @param string $subscription_id Subscription ID.
	 * @return int
	 */
	private static function find_member_by_subscription_id( $subscription_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => SMD_Member_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_smd_stripe_subscription_id',
				'meta_value'     => $subscription_id,
			)
		);

		return ! empty( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find an applicant by Stripe subscription ID.
	 *
	 * @param string $subscription_id Subscription ID.
	 * @return int
	 */
	private static function find_applicant_by_subscription_id( $subscription_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => SMD_Applicant_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_smd_applicant_stripe_subscription_id',
				'meta_value'     => $subscription_id,
			)
		);

		return ! empty( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Create or retrieve Stripe customer ID for a member.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $fields Member data.
	 * @return string|WP_Error
	 */
	private static function get_or_create_customer_id( $member_id, $fields ) {
		if ( ! empty( $fields['stripe_customer_id'] ) ) {
			return (string) $fields['stripe_customer_id'];
		}

		if ( empty( $fields['email'] ) || ! is_email( (string) $fields['email'] ) ) {
			return new WP_Error( 'smd_stripe_missing_email', __( 'This member needs a valid email address before billing can be set up.', 'strong-members-directory' ) );
		}

		$customer = self::stripe_request(
			'POST',
			'/customers',
			array(
				'email'                 => (string) $fields['email'],
				'name'                  => trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] ),
				'metadata[member_id]'   => (string) $member_id,
				'metadata[user_id]'     => (string) $fields['user_id'],
				'metadata[member_email]'=> (string) $fields['email'],
			)
		);

		if ( is_wp_error( $customer ) || empty( $customer['id'] ) ) {
			return is_wp_error( $customer ) ? $customer : new WP_Error( 'smd_stripe_customer_failed', __( 'Stripe customer creation failed.', 'strong-members-directory' ) );
		}

		update_post_meta( $member_id, '_smd_stripe_customer_id', sanitize_text_field( (string) $customer['id'] ) );

		return (string) $customer['id'];
	}

	/**
	 * Create or retrieve Stripe customer ID for an applicant.
	 *
	 * @param int   $applicant_id Applicant ID.
	 * @param array $fields Applicant data.
	 * @return string|WP_Error
	 */
	private static function get_or_create_applicant_customer_id( $applicant_id, $fields ) {
		if ( ! empty( $fields['stripe_customer_id'] ) ) {
			return (string) $fields['stripe_customer_id'];
		}

		if ( empty( $fields['email'] ) || ! is_email( (string) $fields['email'] ) ) {
			return new WP_Error( 'smd_stripe_missing_email', __( 'This applicant needs a valid email address before billing can be set up.', 'strong-members-directory' ) );
		}

		$customer = self::stripe_request(
			'POST',
			'/customers',
			array(
				'email'                    => (string) $fields['email'],
				'name'                     => trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] ),
				'metadata[applicant_id]'   => (string) $applicant_id,
				'metadata[applicant_email]'=> (string) $fields['email'],
			)
		);

		if ( is_wp_error( $customer ) || empty( $customer['id'] ) ) {
			return is_wp_error( $customer ) ? $customer : new WP_Error( 'smd_stripe_customer_failed', __( 'Stripe customer creation failed.', 'strong-members-directory' ) );
		}

		update_post_meta( $applicant_id, '_smd_applicant_stripe_customer_id', sanitize_text_field( (string) $customer['id'] ) );

		return (string) $customer['id'];
	}

	/**
	 * Fetch all Stripe subscriptions for the configured membership price.
	 *
	 * @param string $price_id Price ID.
	 * @return array<int, array>|WP_Error
	 */
	private static function fetch_all_subscriptions_for_price( $price_id ) {
		$subscriptions = array();
		$starting_after = '';

		do {
			$params = array(
				'limit'  => 100,
				'status' => 'all',
				'price'  => $price_id,
				'expand' => array(
					'data.customer',
					'data.default_payment_method',
				),
			);

			if ( '' !== $starting_after ) {
				$params['starting_after'] = $starting_after;
			}

			$response = self::stripe_request( 'GET', '/subscriptions', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
			foreach ( $data as $subscription ) {
				if ( is_array( $subscription ) ) {
					$subscriptions[] = $subscription;
				}
			}

			$has_more       = ! empty( $response['has_more'] );
			$last_item      = ! empty( $data ) ? end( $data ) : array();
			$starting_after = ( $has_more && ! empty( $last_item['id'] ) ) ? (string) $last_item['id'] : '';
		} while ( ! empty( $has_more ) && '' !== $starting_after );

		return $subscriptions;
	}

	/**
	 * Build a dry-run preview of Stripe subscription imports.
	 *
	 * @param array<int, array> $subscriptions Stripe subscriptions.
	 * @return array<string, mixed>
	 */
	private static function build_subscription_import_preview( $subscriptions ) {
		$preview = array(
			'summary' => array(
				'total'      => count( $subscriptions ),
				'importable' => 0,
				'skipped'    => 0,
				'conflicts'  => 0,
			),
			'rows'    => array(),
		);

		foreach ( $subscriptions as $subscription ) {
			$subscription_id = isset( $subscription['id'] ) ? (string) $subscription['id'] : '';
			$customer        = isset( $subscription['customer'] ) && is_array( $subscription['customer'] ) ? $subscription['customer'] : array();
			$customer_id     = isset( $customer['id'] ) ? (string) $customer['id'] : ( isset( $subscription['customer'] ) && is_string( $subscription['customer'] ) ? (string) $subscription['customer'] : '' );
			$customer_email  = isset( $customer['email'] ) ? sanitize_email( (string) $customer['email'] ) : '';
			$member_match    = self::match_member_for_subscription_import( $subscription_id, $customer_id, $customer_email );

			$row = array(
				'subscription_id' => $subscription_id,
				'customer_id'     => $customer_id,
				'customer_email'  => $customer_email,
				'member_id'       => $member_match['member_id'],
				'member_label'    => $member_match['member_label'],
				'action'          => $member_match['action'],
				'action_label'    => self::import_action_label( $member_match['action'] ),
				'notes'           => $member_match['notes'],
				'subscription'    => array(
					'id'                 => $subscription_id,
					'customer'           => $customer_id,
					'status'             => isset( $subscription['status'] ) ? (string) $subscription['status'] : '',
					'current_period_end' => ! empty( $subscription['current_period_end'] ) ? (int) $subscription['current_period_end'] : 0,
				),
			);

			if ( 'import' === $row['action'] ) {
				++$preview['summary']['importable'];
			} elseif ( 'conflict' === $row['action'] ) {
				++$preview['summary']['conflicts'];
			} else {
				++$preview['summary']['skipped'];
			}

			$preview['rows'][] = $row;
		}

		return $preview;
	}

	/**
	 * Match a Stripe subscription to a member.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @param string $customer_id Stripe customer ID.
	 * @param string $customer_email Stripe customer email.
	 * @return array<string, mixed>
	 */
	private static function match_member_for_subscription_import( $subscription_id, $customer_id, $customer_email ) {
		$member_id = 0;
		$notes     = '';

		if ( '' !== $subscription_id ) {
			$member_id = self::find_member_by_subscription_id( $subscription_id );
			if ( $member_id ) {
				return array(
					'member_id'    => $member_id,
					'member_label' => get_the_title( $member_id ),
					'action'       => 'import',
					'notes'        => __( 'Already linked by subscription ID. This import will refresh billing metadata.', 'strong-members-directory' ),
				);
			}
		}

		if ( '' !== $customer_id ) {
			$member_id = self::find_member_by_customer_id( $customer_id );
			if ( $member_id ) {
				return array(
					'member_id'    => $member_id,
					'member_label' => get_the_title( $member_id ),
					'action'       => 'import',
					'notes'        => __( 'Matched by existing Stripe customer ID.', 'strong-members-directory' ),
				);
			}
		}

		if ( '' !== $customer_email ) {
			$member_id = SMD_Member_Post_Type::find_existing_member( $customer_email, '', '' );
			if ( $member_id ) {
				$fields = SMD_Member_Post_Type::get_member_data( $member_id );

				if ( ! empty( $fields['stripe_subscription_id'] ) && $fields['stripe_subscription_id'] !== $subscription_id ) {
					return array(
						'member_id'    => $member_id,
						'member_label' => get_the_title( $member_id ),
						'action'       => 'conflict',
						'notes'        => __( 'Email matched a member that is already linked to a different Stripe subscription.', 'strong-members-directory' ),
					);
				}

				if ( ! empty( $fields['stripe_customer_id'] ) && $fields['stripe_customer_id'] !== $customer_id ) {
					return array(
						'member_id'    => $member_id,
						'member_label' => get_the_title( $member_id ),
						'action'       => 'conflict',
						'notes'        => __( 'Email matched a member that is already linked to a different Stripe customer.', 'strong-members-directory' ),
					);
				}

				return array(
					'member_id'    => $member_id,
					'member_label' => get_the_title( $member_id ),
					'action'       => 'import',
					'notes'        => __( 'Matched by member email.', 'strong-members-directory' ),
				);
			}

			$notes = __( 'No member was found with this Stripe customer email.', 'strong-members-directory' );
		} else {
			$notes = __( 'This Stripe subscription does not expose a customer email to match against.', 'strong-members-directory' );
		}

		return array(
			'member_id'    => 0,
			'member_label' => __( 'No matching member', 'strong-members-directory' ),
			'action'       => 'skip',
			'notes'        => $notes,
		);
	}

	/**
	 * Apply Stripe subscription metadata to a member record.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $subscription Simplified subscription payload.
	 */
	private static function apply_subscription_import_to_member( $member_id, $subscription ) {
		update_post_meta( $member_id, '_smd_stripe_customer_id', sanitize_text_field( (string) $subscription['customer'] ) );
		update_post_meta( $member_id, '_smd_stripe_subscription_id', sanitize_text_field( (string) $subscription['id'] ) );
		update_post_meta( $member_id, '_smd_billing_status', sanitize_text_field( (string) $subscription['status'] ) );

		if ( ! empty( $subscription['current_period_end'] ) ) {
			update_post_meta( $member_id, '_smd_billing_period_end', gmdate( 'c', (int) $subscription['current_period_end'] ) );
		}

		if ( in_array( (string) $subscription['status'], array( 'active', 'trialing' ), true ) ) {
			delete_post_meta( $member_id, '_smd_payment_failed_at' );
			delete_post_meta( $member_id, '_smd_payment_failed_note' );
		}
	}

	/**
	 * Human-readable import action label.
	 *
	 * @param string $action Action key.
	 * @return string
	 */
	private static function import_action_label( $action ) {
		if ( 'import' === $action ) {
			return __( 'Import', 'strong-members-directory' );
		}

		if ( 'conflict' === $action ) {
			return __( 'Conflict', 'strong-members-directory' );
		}

		return __( 'Skip', 'strong-members-directory' );
	}

	/**
	 * Perform a Stripe API request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path API path.
	 * @param array  $body Request body.
	 * @return array|WP_Error
	 */
	private static function stripe_request( $method, $path, $body = array() ) {
		$method = strtoupper( $method );
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::get_secret_key(),
			),
		);

		$url = 'https://api.stripe.com/v1' . $path;

		if ( 'GET' === $method && ! empty( $body ) ) {
			$url .= '?' . http_build_query( $body, '', '&' );
		} elseif ( ! empty( $body ) ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Stripe returned an unexpected error.', 'strong-members-directory' );
			return new WP_Error( 'smd_stripe_api_error', $message );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Remove a member from Mailchimp when they leave.
	 *
	 * @param array $fields Member fields.
	 * @return true|WP_Error
	 */
	private static function remove_member_from_mailchimp( $fields ) {
		$settings = SMD_Settings::get_settings();
		$api_key  = isset( $settings['mailchimp_api_key'] ) ? trim( (string) $settings['mailchimp_api_key'] ) : '';
		$audience = isset( $settings['mailchimp_audience_id'] ) ? trim( (string) $settings['mailchimp_audience_id'] ) : '';
		$email    = isset( $fields['email'] ) ? strtolower( trim( (string) $fields['email'] ) ) : '';

		if ( '' === $api_key || '' === $audience || '' === $email || ! is_email( $email ) ) {
			return true;
		}

		$data_center = strstr( $api_key, '-' );
		if ( false === $data_center || '' === $data_center ) {
			return new WP_Error( 'smd_mailchimp_invalid_key', __( 'Mailchimp API key format is invalid.', 'strong-members-directory' ) );
		}

		$response = wp_remote_request(
			'https://' . ltrim( $data_center, '-' ) . '.api.mailchimp.com/3.0/lists/' . rawurlencode( $audience ) . '/members/' . md5( $email ),
			array(
				'method'  => 'DELETE',
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return true;
		}

		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error( 'smd_mailchimp_remove_failed', $body ? $body : __( 'Mailchimp returned an unexpected error while removing the contact.', 'strong-members-directory' ) );
		}

		return true;
	}

	/**
	 * Send a cancellation confirmation email to the leaving member.
	 *
	 * @param array $fields Member fields.
	 */
	private static function send_member_cancellation_email( $fields ) {
		$email = isset( $fields['email'] ) ? (string) $fields['email'] : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$settings = SMD_Settings::get_settings();
		$tokens   = array(
			'{first_name}' => isset( $fields['first_name'] ) ? (string) $fields['first_name'] : '',
			'{last_name}'  => isset( $fields['last_name'] ) ? (string) $fields['last_name'] : '',
			'{full_name}'  => trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] ),
			'{email}'      => $email,
		);
		$subject  = strtr( isset( $settings['membership_canceled_email_subject'] ) ? (string) $settings['membership_canceled_email_subject'] : '', $tokens );
		$body     = strtr( isset( $settings['membership_canceled_email_body'] ) ? (string) $settings['membership_canceled_email_body'] : '', $tokens );

		self::send_configured_email( $email, $subject, $body );
	}

	/**
	 * Send an email using the configured From identity.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Email subject.
	 * @param string $body Email body.
	 * @return bool
	 */
	private static function send_configured_email( $to, $subject, $body ) {
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
	 * Verify Stripe webhook signature.
	 *
	 * @param string $payload Request body.
	 * @param string $signature Stripe signature header.
	 * @param string $secret Endpoint secret.
	 * @return true|WP_Error
	 */
	private static function verify_webhook_signature( $payload, $signature, $secret ) {
		if ( '' === $signature ) {
			return new WP_Error( 'smd_missing_signature', __( 'Missing Stripe signature header.', 'strong-members-directory' ) );
		}

		$parts      = array();
		$signatures = explode( ',', $signature );

		foreach ( $signatures as $segment ) {
			$pair = array_map( 'trim', explode( '=', $segment, 2 ) );
			if ( 2 === count( $pair ) ) {
				$parts[ $pair[0] ] = $pair[1];
			}
		}

		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return new WP_Error( 'smd_invalid_signature', __( 'Invalid Stripe signature header.', 'strong-members-directory' ) );
		}

		$expected = hash_hmac( 'sha256', $parts['t'] . '.' . $payload, $secret );

		if ( ! hash_equals( $expected, $parts['v1'] ) ) {
			return new WP_Error( 'smd_signature_mismatch', __( 'Stripe webhook signature check failed.', 'strong-members-directory' ) );
		}

		return true;
	}

	/**
	 * Redirect to a member profile with query args.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $args Query args.
	 */
	private static function redirect_to_member( $member_id, $args ) {
		wp_safe_redirect( add_query_arg( $args, self::get_member_return_url( $member_id ) ) );
		exit;
	}

	/**
	 * Redirect to an applicant onboarding page with query args.
	 *
	 * @param int   $applicant_id Applicant ID.
	 * @param array $args Query args.
	 */
	private static function redirect_to_applicant( $applicant_id, $args ) {
		wp_safe_redirect( add_query_arg( $args, self::get_applicant_return_url( $applicant_id ) ) );
		exit;
	}

	/**
	 * Redirect to plugin settings with query args.
	 *
	 * @param array $args Query args.
	 */
	private static function redirect_to_settings( $args ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'post_type' => SMD_Member_Post_Type::POST_TYPE,
						'page'      => 'smd-settings',
					),
					$args
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Get Stripe secret key from settings.
	 *
	 * @return string
	 */
	public static function get_secret_key() {
		$settings = SMD_Settings::get_settings();
		return isset( $settings['stripe_secret_key'] ) ? trim( (string) $settings['stripe_secret_key'] ) : '';
	}

	/**
	 * Get Stripe webhook secret from settings.
	 *
	 * @return string
	 */
	public static function get_webhook_secret() {
		$settings = SMD_Settings::get_settings();
		return isset( $settings['stripe_webhook_secret'] ) ? trim( (string) $settings['stripe_webhook_secret'] ) : '';
	}

	/**
	 * Get Stripe recurring quarterly price ID from settings.
	 *
	 * @return string
	 */
	public static function get_price_id() {
		$settings = SMD_Settings::get_settings();
		return isset( $settings['stripe_price_id'] ) ? trim( (string) $settings['stripe_price_id'] ) : '';
	}

	/**
	 * Get webhook URL for admin display.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		return rest_url( 'smd/v1/stripe/webhook' );
	}

	/**
	 * Build a signed front-end URL for member billing actions.
	 *
	 * @param string $action Billing action key.
	 * @param int    $member_id Member ID.
	 * @return string
	 */
	public static function get_billing_action_url( $action, $member_id ) {
		$expires   = time() + HOUR_IN_SECONDS;
		$signature = self::generate_billing_action_signature( $action, 'member', $member_id, $expires );
		$base_url  = SMD_Settings::get_dashboard_url() ? SMD_Settings::get_dashboard_url() : home_url( '/' );

		return add_query_arg(
			array(
				'smd_billing_action'  => $action,
				'member_id'           => (int) $member_id,
				'smd_billing_expires' => $expires,
				'smd_billing_sig'     => $signature,
			),
			$base_url
		);
	}

	/**
	 * Build a signed front-end URL for applicant billing setup.
	 *
	 * @param int $applicant_id Applicant ID.
	 * @return string
	 */
	public static function get_applicant_billing_action_url( $applicant_id ) {
		$expires   = time() + HOUR_IN_SECONDS;
		$signature = self::generate_billing_action_signature( 'start_applicant_subscription', 'applicant', $applicant_id, $expires );
		$base_url  = self::get_applicant_return_url( $applicant_id );

		return add_query_arg(
			array(
				'smd_billing_action'  => 'start_applicant_subscription',
				'applicant_id'        => (int) $applicant_id,
				'smd_billing_expires' => $expires,
				'smd_billing_sig'     => $signature,
			),
			$base_url
		);
	}

	/**
	 * Generate a signed billing action token.
	 *
	 * @param string $action Billing action.
	 * @param int    $member_id Member ID.
	 * @param int    $expires Expiration timestamp.
	 * @return string
	 */
	private static function generate_billing_action_signature( $action, $subject_type, $subject_id, $expires ) {
		return hash_hmac( 'sha256', $action . '|' . $subject_type . '|' . (int) $subject_id . '|' . (int) $expires, wp_salt( 'auth' ) );
	}

	/**
	 * Verify a signed billing action token.
	 *
	 * @param string $action Billing action.
	 * @param int    $member_id Member ID.
	 * @param int    $expires Expiration timestamp.
	 * @param string $signature Signature hash.
	 * @return bool
	 */
	private static function verify_billing_action_signature( $action, $subject_type, $subject_id, $expires, $signature ) {
		if ( ! $subject_id || ! $expires || '' === $signature ) {
			return false;
		}

		if ( time() > $expires ) {
			return false;
		}

		$expected = self::generate_billing_action_signature( $action, $subject_type, $subject_id, $expires );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Get member ID from request parameters.
	 *
	 * @return int
	 */
	private static function get_request_member_id() {
		return isset( $_REQUEST['member_id'] ) ? (int) $_REQUEST['member_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Get applicant ID from request parameters.
	 *
	 * @return int
	 */
	private static function get_request_applicant_id() {
		return isset( $_REQUEST['applicant_id'] ) ? (int) $_REQUEST['applicant_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Resolve the best return URL for a member billing action.
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	private static function get_member_return_url( $member_id ) {
		$dashboard_url = SMD_Settings::get_dashboard_url();

		if ( $dashboard_url ) {
			return $dashboard_url;
		}

		return get_permalink( $member_id );
	}

	/**
	 * Resolve the best return URL for an applicant billing action.
	 *
	 * @param int $applicant_id Applicant ID.
	 * @return string
	 */
	private static function get_applicant_return_url( $applicant_id ) {
		if ( class_exists( 'SMD_Applications' ) ) {
			$url = SMD_Applications::get_applicant_onboarding_url( $applicant_id );
			if ( $url ) {
				return $url;
			}
		}

		$base = SMD_Settings::get_applicant_onboarding_url();

		return $base ? $base : home_url( '/' );
	}

	/**
	 * Get stored Stripe subscription import preview.
	 *
	 * @param string $token Preview token.
	 * @return array|false
	 */
	public static function get_import_preview( $token ) {
		return get_transient( self::IMPORT_PREVIEW_TRANSIENT_PREFIX . $token );
	}

	/**
	 * Get stored Stripe subscription import report.
	 *
	 * @param string $token Report token.
	 * @return array|false
	 */
	public static function get_import_report( $token ) {
		return get_transient( self::IMPORT_REPORT_TRANSIENT_PREFIX . $token );
	}
}
