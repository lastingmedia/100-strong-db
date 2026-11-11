<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Additional ("sponsored") Stripe subscriptions held by one member.
 *
 * A member can pay for other people's dues. Those subscriptions belong to the sponsor, but
 * they must never replace the sponsor's own customer and subscription IDs, and a problem
 * with one must never lapse the sponsor. They are stored under their own meta keys:
 *
 * - META_ROW: one row per subscription (customer, subscription, status, email, failed_at).
 * - META_INDEX: the bare subscription ID, so a webhook can find the sponsor by subscription.
 *
 * Stripe events for these subscriptions only update their own status and alert the team.
 */
class SMD_Sponsored {
	const META_ROW   = '_smd_stripe_additional_subscription';
	const META_INDEX = '_smd_stripe_additional_sub_id';

	/**
	 * Replace the member's additional subscriptions.
	 *
	 * @param int   $member_id Member post ID.
	 * @param array $rows      Rows with customer, subscription, status and email.
	 */
	public static function set_for_member( $member_id, $rows ) {
		$previous = self::get_for_member( $member_id );
		$by_sub   = array();

		foreach ( $previous as $row ) {
			$by_sub[ $row['subscription'] ] = $row;
		}

		delete_post_meta( $member_id, self::META_ROW );
		delete_post_meta( $member_id, self::META_INDEX );

		foreach ( (array) $rows as $row ) {
			$sub_id = isset( $row['subscription'] ) ? (string) $row['subscription'] : '';

			if ( '' === $sub_id ) {
				continue;
			}

			$row = array(
				'customer'     => isset( $row['customer'] ) ? (string) $row['customer'] : '',
				'subscription' => $sub_id,
				'status'       => isset( $row['status'] ) ? (string) $row['status'] : '',
				'email'        => isset( $row['email'] ) ? (string) $row['email'] : '',
				'failed_at'    => isset( $by_sub[ $sub_id ]['failed_at'] ) ? (int) $by_sub[ $sub_id ]['failed_at'] : 0,
			);

			add_post_meta( $member_id, self::META_ROW, $row );
			add_post_meta( $member_id, self::META_INDEX, $sub_id );
		}
	}

	/**
	 * All additional subscriptions for a member.
	 *
	 * @param int $member_id Member post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_member( $member_id ) {
		$rows = get_post_meta( $member_id, self::META_ROW, false );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['subscription'] ) ) {
				$out[] = $row + array(
					'customer'  => '',
					'status'    => '',
					'email'     => '',
					'failed_at' => 0,
				);
			}
		}

		return $out;
	}

	/**
	 * One additional subscription, only if it belongs to this member.
	 *
	 * @param int    $member_id Member post ID.
	 * @param string $sub_id    Subscription ID.
	 * @return array<string, mixed>|null
	 */
	public static function find_row( $member_id, $sub_id ) {
		foreach ( self::get_for_member( $member_id ) as $row ) {
			if ( (string) $row['subscription'] === (string) $sub_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Find the sponsor that holds a subscription as an additional one.
	 *
	 * @param string $sub_id Subscription ID.
	 * @return int Member ID, or 0.
	 */
	public static function find_member_by_subscription( $sub_id ) {
		if ( '' === (string) $sub_id ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => SMD_Member_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => self::META_INDEX, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $sub_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return ! empty( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Update one additional subscription.
	 *
	 * @param int    $member_id Member post ID.
	 * @param string $sub_id    Subscription ID.
	 * @param array  $changes   Fields to change (status, failed_at).
	 */
	public static function update_row( $member_id, $sub_id, $changes ) {
		$rows = self::get_for_member( $member_id );

		foreach ( $rows as $index => $row ) {
			if ( (string) $row['subscription'] === (string) $sub_id ) {
				$rows[ $index ] = array_merge( $row, array_intersect_key( $changes, array( 'status' => 1, 'failed_at' => 1 ) ) );
			}
		}

		delete_post_meta( $member_id, self::META_ROW );
		delete_post_meta( $member_id, self::META_INDEX );

		foreach ( $rows as $row ) {
			add_post_meta( $member_id, self::META_ROW, $row );
			add_post_meta( $member_id, self::META_INDEX, $row['subscription'] );
		}
	}

	/**
	 * Whether any additional subscription has a failed payment.
	 *
	 * @param int $member_id Member post ID.
	 * @return bool
	 */
	public static function has_failed( $member_id ) {
		foreach ( self::get_for_member( $member_id ) as $row ) {
			if ( ! empty( $row['failed_at'] ) || in_array( $row['status'], array( 'payment_failed', 'past_due', 'unpaid' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Billing state key for a subscription row (matches SMD_Billing_Admin::state_labels()).
	 *
	 * @param array $row Subscription row.
	 * @return string
	 */
	public static function state_for_row( $row ) {
		$status = isset( $row['status'] ) ? (string) $row['status'] : '';

		if ( in_array( $status, array( 'active', 'trialing' ), true ) && empty( $row['failed_at'] ) ) {
			return 'active';
		}

		if ( ! empty( $row['failed_at'] ) || in_array( $status, array( 'past_due', 'unpaid', 'payment_failed' ), true ) ) {
			return 'past_due';
		}

		if ( in_array( $status, array( 'canceled', 'cancelled', 'incomplete_expired' ), true ) ) {
			return 'canceled';
		}

		return 'none';
	}

	/**
	 * Which subscription a webhook event is about, when it is an additional one.
	 *
	 * @param string $type   Event type.
	 * @param array  $object Event object.
	 * @return int Sponsor member ID, or 0 when the event is not for an additional subscription.
	 */
	public static function find_sponsor_for_event( $type, $object ) {
		$sub_id = '';

		if ( 0 === strpos( $type, 'customer.subscription.' ) && ! empty( $object['id'] ) ) {
			$sub_id = (string) $object['id'];
		} elseif ( 0 === strpos( $type, 'invoice.' ) && ! empty( $object['subscription'] ) && is_string( $object['subscription'] ) ) {
			$sub_id = $object['subscription'];
		}

		return self::find_member_by_subscription( $sub_id );
	}

	/**
	 * Apply a Stripe event for an additional subscription.
	 *
	 * Only that subscription's own status changes. The sponsor's own billing state, grace
	 * period and access are left alone; the team is told once per failed payment.
	 *
	 * @param int    $member_id Sponsor member ID.
	 * @param string $type      Event type.
	 * @param array  $object    Event object.
	 */
	public static function handle_event( $member_id, $type, $object ) {
		$sub_id = 0 === strpos( $type, 'invoice.' ) ? (string) ( $object['subscription'] ?? '' ) : (string) ( $object['id'] ?? '' );
		$row    = self::find_row( $member_id, $sub_id );

		if ( ! $row ) {
			return;
		}

		if ( 'invoice.payment_failed' === $type ) {
			$first_failure = empty( $row['failed_at'] );
			self::update_row( $member_id, $sub_id, array( 'status' => 'payment_failed', 'failed_at' => $first_failure ? time() : (int) $row['failed_at'] ) );

			if ( $first_failure ) {
				self::notify_failure( $member_id, $row );
			}

			return;
		}

		if ( 'invoice.paid' === $type ) {
			self::update_row( $member_id, $sub_id, array( 'status' => 'active', 'failed_at' => 0 ) );
			return;
		}

		if ( 0 === strpos( $type, 'customer.subscription.' ) ) {
			$status = 'customer.subscription.deleted' === $type ? 'canceled' : sanitize_text_field( (string) ( $object['status'] ?? $row['status'] ) );
			$changes = array( 'status' => $status );

			if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
				$changes['failed_at'] = 0;
			}

			self::update_row( $member_id, $sub_id, $changes );
		}
	}

	/**
	 * Tell the team that a sponsored subscription's payment failed.
	 *
	 * @param int   $member_id Sponsor member ID.
	 * @param array $row       Subscription row.
	 */
	private static function notify_failure( $member_id, $row ) {
		$fields = SMD_Member_Post_Type::get_member_data( $member_id );
		$name   = trim( (string) $fields['first_name'] . ' ' . (string) $fields['last_name'] );

		SMD_Grace::notify_team(
			sprintf( /* translators: %s: sponsor name */ __( 'Sponsored subscription payment failed: %s', 'strong-members-directory' ), $name ),
			sprintf(
				/* translators: 1: sponsor name, 2: subscription id, 3: billing email, 4: member edit url */
				__( "A payment failed on an additional subscription held by %1\$s (subscription %2\$s, billed to %3\$s).\n\n%1\$s's own membership and access are not affected. Please follow up about the sponsored membership.\n\nOpen the member: %4\$s", 'strong-members-directory' ),
				$name,
				(string) $row['subscription'],
				'' !== (string) $row['email'] ? (string) $row['email'] : __( 'no email on file', 'strong-members-directory' ),
				(string) get_edit_post_link( $member_id, 'raw' )
			)
		);
	}
}
