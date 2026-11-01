<?php
/**
 * GDPR exporter + eraser for support tickets (by email): erasing anonymises tickets and the customer's
 * messages and deletes their attachments.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Data;

use Dev3\AiHelpdesk\Helpdesk\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Personal_Data.
 */
class Personal_Data {

	/**
	 * Tickets for an email.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	private static function rows( $email ) {
		$user = get_user_by( 'email', $email );
		return Tickets::for_customer( $user ? (int) $user->ID : 0, strtolower( (string) $email ), 500 );
	}

	/**
	 * Exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		unset( $page );
		$data = array();
		foreach ( self::rows( $email ) as $ticket ) {
			$messages = array();
			foreach ( Replies::for_ticket( $ticket['id'] ) as $reply ) {
				if ( 'customer' === $reply['author'] ) {
					$messages[] = $reply['created_at'] . ': ' . $reply['body'];
				}
			}
			$data[] = array(
				'group_id'    => 'dev3hd-tickets',
				'group_label' => __( 'Support tickets', 'dev3-ai-helpdesk' ),
				'item_id'     => 'dev3hd-' . $ticket['id'],
				'data'        => array(
					array(
						'name'  => __( 'Ticket', 'dev3-ai-helpdesk' ),
						'value' => '#' . $ticket['number'],
					),
					array(
						'name'  => __( 'Subject', 'dev3-ai-helpdesk' ),
						'value' => $ticket['subject'],
					),
					array(
						'name'  => __( 'Name', 'dev3-ai-helpdesk' ),
						'value' => $ticket['name'],
					),
					array(
						'name'  => __( 'Email', 'dev3-ai-helpdesk' ),
						'value' => $ticket['email'],
					),
					array(
						'name'  => __( 'Created', 'dev3-ai-helpdesk' ),
						'value' => $ticket['created_at'],
					),
					array(
						'name'  => __( 'Messages', 'dev3-ai-helpdesk' ),
						'value' => implode( "\n\n", $messages ),
					),
					array(
						'name'  => __( 'Rating', 'dev3-ai-helpdesk' ),
						'value' => (string) $ticket['rating'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Eraser: anonymises tickets and customer messages, deletes customer attachments.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		global $wpdb;
		$removed = 0;
		foreach ( self::rows( $email ) as $ticket ) {
			foreach ( Replies::for_ticket( $ticket['id'], true ) as $reply ) {
				if ( 'customer' !== $reply['author'] ) {
					continue;
				}
				foreach ( $reply['attachments'] as $file ) {
					Files::delete( $file['key'] ?? '' );
				}
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					Tables::name( 'replies' ),
					array(
						'name'        => __( 'Anonymous', 'dev3-ai-helpdesk' ),
						'email'       => '',
						'user_id'     => 0,
						'body'        => __( '[removed at the customer’s request]', 'dev3-ai-helpdesk' ),
						'attachments' => '[]',
					),
					array( 'id' => (int) $reply['id'] )
				);
			}
			Tickets::update(
				$ticket['id'],
				array(
					'name'           => __( 'Anonymous', 'dev3-ai-helpdesk' ),
					'email'          => 'deleted-' . (int) $ticket['id'] . '@invalid.invalid',
					'customer_id'    => 0,
					'rating_comment' => '',
					'ai'             => array(),
					'access_key'     => wp_generate_password( 32, false, false ),
				)
			);
			++$removed;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
