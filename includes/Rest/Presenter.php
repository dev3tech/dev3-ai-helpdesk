<?php
/**
 * Ticket → JSON for agents (inbox rows, full detail) and for customers (portal view, no internal data).
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Rest;

use Dev3\AiHelpdesk\Data\Replies;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Frontend\Portal;
use Dev3\AiHelpdesk\Helpdesk\Files;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Helpdesk\Sla;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Presenter.
 */
class Presenter {

	/**
	 * GMT MySQL date → ISO 8601 (or null).
	 *
	 * @param string|null $date Date.
	 * @return string|null
	 */
	public static function iso( $date ) {
		return empty( $date ) ? null : str_replace( ' ', 'T', (string) $date ) . 'Z';
	}

	/**
	 * Agent summary.
	 *
	 * @param int $id User id.
	 * @return array|null
	 */
	private static function agent( $id ) {
		if ( ! $id ) {
			return null;
		}
		$user = get_userdata( (int) $id );
		return $user ? array(
			'id'     => (int) $id,
			'name'   => $user->display_name,
			'avatar' => get_avatar_url( $id, array( 'size' => 48 ) ),
		) : null;
	}

	/**
	 * Inbox row.
	 *
	 * @param array|null $t Ticket.
	 * @return array|null
	 */
	public static function row( $t ) {
		if ( ! $t ) {
			return null;
		}
		$sla       = Sla::state( $t );
		$triage    = $t['ai']['triage'] ?? null;
		$sentiment = '' !== $t['sentiment'] ? $t['sentiment'] : (string) ( $triage['sentiment'] ?? '' );
		return array(
			'id'              => (int) $t['id'],
			'number'          => (int) $t['number'],
			'subject'         => $t['subject'],
			'status'          => $t['status'],
			'priority'        => $t['priority'],
			'department'      => $t['department'],
			'department_name' => Settings::department_name( $t['department'] ),
			'category'        => $t['category'],
			'category_name'   => Settings::category_name( $t['category'] ),
			'agent'           => self::agent( $t['agent_id'] ),
			'name'            => $t['name'],
			'email'           => $t['email'],
			'customer_id'     => (int) $t['customer_id'],
			'channel'         => $t['channel'],
			'tags'            => array_values( array_filter( explode( ',', (string) $t['tags'] ) ) ),
			'sentiment'       => $sentiment,
			'sla'             => $sla,
			'overdue'         => ( $sla['first']['overdue'] ?? false ) || ( $sla['resolve']['overdue'] ?? false ),
			'last_reply_by'   => $t['last_reply_by'],
			'ai_state'        => $t['ai_state'],
			'has_triage'      => ! empty( $triage ) && empty( $triage['applied'] ),
			'has_draft'       => ! empty( $t['ai']['draft'] ) && empty( $t['ai']['draft']['sent'] ),
			'rating'          => (int) $t['rating'],
			'order_id'        => (int) $t['order_id'],
			'created_at'      => self::iso( $t['created_at'] ),
			'updated_at'      => self::iso( $t['updated_at'] ),
			'last_reply_at'   => self::iso( $t['last_reply_at'] ),
		);
	}

	/**
	 * Timeline row for agents.
	 *
	 * @param array $r Reply.
	 * @return array
	 */
	private static function reply( array $r ) {
		return array(
			'id'          => (int) $r['id'],
			'type'        => $r['type'],
			'author'      => $r['author'],
			'name'        => $r['name'],
			'email'       => $r['email'],
			'avatar'      => $r['user_id'] ? get_avatar_url( $r['user_id'], array( 'size' => 48 ) ) : get_avatar_url( $r['email'] ? $r['email'] : 'x@x.invalid', array( 'size' => 48 ) ),
			'body'        => $r['body'],
			'attachments' => Files::present( $r['attachments'] ),
			'via'         => $r['via'],
			'created_at'  => self::iso( $r['created_at'] ),
		);
	}

	/**
	 * Full ticket for agents.
	 *
	 * @param array $t Ticket.
	 * @return array
	 */
	public static function full( array $t ) {
		$out                    = self::row( $t );
		$out['replies']         = array_map( array( self::class, 'reply' ), Replies::for_ticket( $t['id'], true ) );
		$out['messages']        = Replies::count_messages( $t['id'] );
		$ai                     = $t['ai'];
		$out['ai']              = array(
			'triage'  => $ai['triage'] ?? null,
			'draft'   => $ai['draft'] ?? null,
			'summary' => $ai['summary'] ?? null,
		);
		$out['summary_stale']   = ! empty( $ai['summary'] ) && (int) ( $ai['summary']['messages'] ?? 0 ) !== $out['messages'];
		$out['summary_suggest'] = $out['messages'] >= (int) Settings::get( 'ai_summary_min', 6 );
		$out['rating_comment']  = $t['rating_comment'];
		$out['rating_label']    = $t['rating'] ? Service::rating_labels()[ (int) $t['rating'] ] : '';
		$out['first_response']  = self::iso( $t['first_response_at'] );
		$out['resolved_at']     = self::iso( $t['resolved_at'] );
		$out['portal_url']      = Portal::ticket_url( $t );
		$user                   = $t['customer_id'] ? get_userdata( $t['customer_id'] ) : null;
		$out['customer']        = array(
			'name'       => $t['name'],
			'email'      => $t['email'],
			'avatar'     => get_avatar_url( $t['email'], array( 'size' => 96 ) ),
			'registered' => $user ? self::iso( $user->user_registered ) : null,
			'user_url'   => $user && current_user_can( 'edit_user', $user->ID ) ? get_edit_user_link( $user->ID ) : '',
			'tickets'    => array_map(
				array( self::class, 'row' ),
				Tickets::query(
					array(
						'customer_id' => (int) $t['customer_id'],
						'email'       => $t['email'],
						'exclude'     => (int) $t['id'],
						'per_page'    => 5,
					)
				)['rows']
			),
		);
		return $out;
	}

	/**
	 * Customer (portal) view: public replies only, no internal data.
	 *
	 * @param array $t Ticket.
	 * @return array
	 */
	public static function customer( array $t ) {
		$labels  = Tickets::status_labels();
		$replies = array();
		foreach ( Replies::for_ticket( $t['id'], false ) as $r ) {
			$replies[] = array(
				'id'          => (int) $r['id'],
				'mine'        => 'customer' === $r['author'],
				'name'        => 'customer' === $r['author'] ? $r['name'] : ( '' !== $r['name'] ? $r['name'] : __( 'Support team', 'dev3-ai-helpdesk' ) ),
				'body'        => $r['body'],
				'attachments' => Files::present( $r['attachments'] ),
				'created_at'  => self::iso( $r['created_at'] ),
			);
		}
		$done  = in_array( $t['status'], array( 'resolved', 'closed' ), true );
		$reply = 'closed' !== $t['status'] || Settings::get( 'reopen_on_reply', true );
		return array(
			'number'       => (int) $t['number'],
			'key'          => $t['access_key'],
			'subject'      => $t['subject'],
			'status'       => $t['status'],
			'status_label' => $labels[ $t['status'] ] ?? $t['status'],
			'category'     => Settings::category_name( $t['category'] ),
			'created_at'   => self::iso( $t['created_at'] ),
			'updated_at'   => self::iso( $t['updated_at'] ),
			'replies'      => $replies,
			'can_reply'    => $reply,
			'can_close'    => ! $done,
			'can_rate'     => $done && Settings::get( 'csat_enabled', true ) && ! $t['rating'],
			'rating'       => (int) $t['rating'],
		);
	}

	/**
	 * Customer list row.
	 *
	 * @param array $t Ticket.
	 * @return array
	 */
	public static function customer_row( array $t ) {
		$labels = Tickets::status_labels();
		return array(
			'number'       => (int) $t['number'],
			'key'          => $t['access_key'],
			'subject'      => $t['subject'],
			'status'       => $t['status'],
			'status_label' => $labels[ $t['status'] ] ?? $t['status'],
			'updated_at'   => self::iso( $t['updated_at'] ),
			'awaiting'     => 'pending' === $t['status'],
		);
	}
}
