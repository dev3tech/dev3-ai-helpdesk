<?php
/**
 * Optional WooCommerce order panel: the customer's recent orders (WC CRUD only, so HPOS-safe) and
 * order-number validation for the ticket form.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Woo;

use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Orders.
 */
class Orders {

	/**
	 * Whether the panel is available.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) && (bool) Settings::get( 'woo_panel', true );
	}

	/**
	 * Money without HTML entities.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	private static function money( $amount, $currency ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Order for JSON.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function present( \WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name' => $item->get_name(),
				'qty'  => (int) $item->get_quantity(),
			);
		}
		$created = $order->get_date_created();
		return array(
			'id'       => (int) $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'status'   => wc_get_order_status_name( $order->get_status() ),
			'slug'     => $order->get_status(),
			'total'    => self::money( (float) $order->get_total(), $order->get_currency() ),
			'date'     => $created ? $created->date_i18n( get_option( 'date_format' ) ) : '',
			'items'    => array_slice( $items, 0, 8 ),
			'count'    => count( $items ),
			'payment'  => $order->get_payment_method_title(),
			'shipping' => $order->get_shipping_method(),
			'edit_url' => $order->get_edit_order_url(),
		);
	}

	/**
	 * Orders for a ticket's customer (by user id or billing email), linked order first.
	 *
	 * @param array $ticket Ticket.
	 * @param int   $limit  Limit.
	 * @return array { orders, total_spent, count }
	 */
	public static function for_ticket( array $ticket, $limit = 10 ) {
		if ( ! self::active() ) {
			return array(
				'orders' => array(),
				'count'  => 0,
			);
		}
		$args = array(
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
		);
		$list = $ticket['customer_id']
			? wc_get_orders( array_merge( $args, array( 'customer_id' => (int) $ticket['customer_id'] ) ) )
			: array();
		if ( ! $list ) {
			$list = wc_get_orders( array_merge( $args, array( 'billing_email' => (string) $ticket['email'] ) ) );
		}
		$orders = array();
		$seen   = array();
		if ( $ticket['order_id'] ) {
			$linked = wc_get_order( (int) $ticket['order_id'] );
			if ( $linked instanceof \WC_Order ) {
				$row           = self::present( $linked );
				$row['linked'] = true;
				$orders[]      = $row;
				$seen[]        = $linked->get_id();
			}
		}
		foreach ( (array) $list as $order ) {
			if ( $order instanceof \WC_Order && ! in_array( $order->get_id(), $seen, true ) ) {
				$row           = self::present( $order );
				$row['linked'] = false;
				$orders[]      = $row;
			}
		}
		return array(
			'orders' => array_slice( $orders, 0, $limit ),
			'count'  => count( $orders ),
		);
	}

	/**
	 * Order id for an order number that belongs to the email (0 when not found or not theirs).
	 *
	 * @param string $number Order number as typed.
	 * @param string $email  Customer email.
	 * @param int    $user   Logged-in user id.
	 * @return int
	 */
	public static function find_for( $number, $email, $user = 0 ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return 0;
		}
		$number = (int) preg_replace( '/\D/', '', (string) $number );
		if ( $number <= 0 ) {
			return 0;
		}
		$order = wc_get_order( $number );
		if ( ! $order instanceof \WC_Order ) {
			$found = wc_get_orders(
				array(
					'limit'        => 1,
					'meta_key'     => '_order_number', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'   => (string) $number, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_compare' => '=',
				)
			);
			$order = $found ? $found[0] : null;
		}
		if ( ! $order instanceof \WC_Order ) {
			return 0;
		}
		$mine = ( $user && (int) $order->get_customer_id() === (int) $user ) || hash_equals( strtolower( (string) $order->get_billing_email() ), strtolower( (string) $email ) );
		return $mine ? (int) $order->get_id() : 0;
	}
}
