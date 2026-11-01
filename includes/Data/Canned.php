<?php
/**
 * Canned (saved) replies with placeholders.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Canned replies.
 */
class Canned {

	/**
	 * All replies (optionally for a department; department-less ones always match).
	 *
	 * @param string $department Department id.
	 * @return array
	 */
	public static function all( $department = '' ) {
		global $wpdb;
		$table = Tables::name( 'canned' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = '' === $department
			? $wpdb->get_results( "SELECT * FROM {$table} ORDER BY uses DESC, title ASC LIMIT 500", ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE department = '' OR department = %s ORDER BY uses DESC, title ASC LIMIT 500", $department ), ARRAY_A );
		// phpcs:enable
		return array_map( array( self::class, 'shape' ), (array) $rows );
	}

	/**
	 * Row shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function shape( array $row ) {
		return array(
			'id'         => (int) $row['id'],
			'title'      => (string) $row['title'],
			'body'       => (string) $row['body'],
			'department' => (string) $row['department'],
			'uses'       => (int) $row['uses'],
			'updated_at' => (string) $row['updated_at'],
		);
	}

	/**
	 * One reply.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'canned' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Creates or updates.
	 *
	 * @param array $data title, body, department.
	 * @param int   $id   Id (0 = new).
	 * @return int|\WP_Error
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		$title = mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 190 );
		$body  = mb_substr( sanitize_textarea_field( (string) ( $data['body'] ?? '' ) ), 0, 10000 );
		if ( '' === $title || '' === trim( $body ) ) {
			return new \WP_Error( 'dev3hd_canned_invalid', __( 'Give the reply a title and some text.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$dept  = sanitize_key( (string) ( $data['department'] ?? '' ) );
		$row   = array(
			'title'      => $title,
			'body'       => $body,
			'department' => in_array( $dept, \Dev3\AiHelpdesk\Settings::department_ids(), true ) ? $dept : '',
			'updated_at' => Tickets::now(),
		);
		$table = Tables::name( 'canned' );
		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $id;
		}
		$row['created_at'] = Tickets::now();
		$row['created_by'] = get_current_user_id();
		$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Deletes.
	 *
	 * @param int $id Id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Tables::name( 'canned' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Counts a use and returns the body filled for a ticket.
	 *
	 * @param int        $id     Id.
	 * @param array|null $ticket Ticket.
	 * @return string|null
	 */
	public static function use_for( $id, $ticket ) {
		global $wpdb;
		$reply = self::get( $id );
		if ( ! $reply ) {
			return null;
		}
		$table = Tables::name( 'canned' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET uses = uses + 1 WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::fill( $reply['body'], $ticket );
	}

	/**
	 * Replaces placeholders.
	 *
	 * @param string     $body   Body.
	 * @param array|null $ticket Ticket.
	 * @return string
	 */
	public static function fill( $body, $ticket ) {
		$user  = wp_get_current_user();
		$first = $ticket ? trim( strtok( (string) $ticket['name'], ' ' ) ) : '';
		$vars  = array(
			'{{customer_name}}'  => $ticket ? (string) $ticket['name'] : '',
			'{{customer_first}}' => $first,
			'{{ticket_number}}'  => $ticket ? '#' . (int) $ticket['number'] : '',
			'{{subject}}'        => $ticket ? (string) $ticket['subject'] : '',
			'{{agent_name}}'     => $user && $user->exists() ? $user->display_name : '',
			'{{portal_link}}'    => $ticket ? \Dev3\AiHelpdesk\Frontend\Portal::ticket_url( $ticket ) : '',
			'{{site_name}}'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		return strtr( (string) $body, $vars );
	}
}
