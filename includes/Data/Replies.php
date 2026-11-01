<?php
/**
 * Ticket timeline rows: customer/agent replies, internal notes and system events.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Replies.
 */
class Replies {

	/**
	 * Adds a row.
	 *
	 * @param int   $ticket_id Ticket id.
	 * @param array $data      type (reply|note|event), author (customer|agent|system|ai), user_id, name, email, body, attachments, via, message_id.
	 * @return int
	 */
	public static function add( $ticket_id, array $data ) {
		global $wpdb;
		$row = array(
			'ticket_id'   => (int) $ticket_id,
			'type'        => in_array( $data['type'] ?? 'reply', array( 'reply', 'note', 'event' ), true ) ? $data['type'] : 'reply',
			'author'      => in_array( $data['author'] ?? 'customer', array( 'customer', 'agent', 'system', 'ai' ), true ) ? $data['author'] : 'customer',
			'user_id'     => (int) ( $data['user_id'] ?? 0 ),
			'name'        => mb_substr( (string) ( $data['name'] ?? '' ), 0, 190 ),
			'email'       => mb_substr( (string) ( $data['email'] ?? '' ), 0, 190 ),
			'body'        => (string) ( $data['body'] ?? '' ),
			'attachments' => wp_json_encode( array_values( (array) ( $data['attachments'] ?? array() ) ) ),
			'via'         => mb_substr( (string) ( $data['via'] ?? 'web' ), 0, 10 ),
			'message_id'  => mb_substr( (string) ( $data['message_id'] ?? '' ), 0, 255 ),
			'created_at'  => (string) ( $data['created_at'] ?? Tickets::now() ),
		);
		$wpdb->insert( Tables::name( 'replies' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Sets the outgoing Message-ID of a reply.
	 *
	 * @param int    $id         Reply id.
	 * @param string $message_id Message-ID.
	 */
	public static function set_message_id( $id, $message_id ) {
		global $wpdb;
		$wpdb->update( Tables::name( 'replies' ), array( 'message_id' => mb_substr( (string) $message_id, 0, 255 ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Decodes a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function hydrate( array $row ) {
		$row['id']          = (int) $row['id'];
		$row['ticket_id']   = (int) $row['ticket_id'];
		$row['user_id']     = (int) $row['user_id'];
		$files              = json_decode( (string) $row['attachments'], true );
		$row['attachments'] = is_array( $files ) ? $files : array();
		return $row;
	}

	/**
	 * One row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'replies' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Timeline of a ticket, oldest first.
	 *
	 * @param int  $ticket_id Ticket id.
	 * @param bool $internal  Include internal notes and events (agents only).
	 * @return array
	 */
	public static function for_ticket( $ticket_id, $internal = false ) {
		global $wpdb;
		$table = Tables::name( 'replies' );
		$types = $internal ? '' : " AND type = 'reply'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_id = %d{$types} ORDER BY id ASC", (int) $ticket_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Number of replies (customer + agent messages, no notes/events).
	 *
	 * @param int $ticket_id Ticket id.
	 * @return int
	 */
	public static function count_messages( $ticket_id ) {
		global $wpdb;
		$table = Tables::name( 'replies' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d AND type = 'reply'", (int) $ticket_id ) );
	}

	/**
	 * Ticket id for any of the given Message-IDs (email threading).
	 *
	 * @param string[] $message_ids Message-IDs.
	 * @return int
	 */
	public static function ticket_for_message_ids( array $message_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( static fn( $m ) => trim( (string) $m ), $message_ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$ids     = array_slice( $ids, -20 );
		$in      = implode( ',', array_map( static fn( $m ) => $wpdb->prepare( '%s', $m ), $ids ) );
		$replies = Tables::name( 'replies' );
		$tickets = Tables::name( 'tickets' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( "SELECT ticket_id FROM {$replies} WHERE message_id IN ({$in}) ORDER BY id DESC LIMIT 1" );
		if ( ! $id ) {
			$id = (int) $wpdb->get_var( "SELECT id FROM {$tickets} WHERE message_id IN ({$in}) ORDER BY id DESC LIMIT 1" );
		}
		// phpcs:enable
		return $id;
	}

	/**
	 * Whether a Message-ID was already stored (duplicate delivery).
	 *
	 * @param string $message_id Message-ID.
	 * @return bool
	 */
	public static function seen( $message_id ) {
		global $wpdb;
		if ( '' === trim( (string) $message_id ) ) {
			return false;
		}
		$table = Tables::name( 'replies' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE message_id = %s LIMIT 1", (string) $message_id ) );
	}
}
