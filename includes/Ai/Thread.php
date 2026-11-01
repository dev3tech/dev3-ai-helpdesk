<?php
/**
 * Ticket thread as prompt text (newest messages kept when it is long).
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Ai;

use Dev3\AiHelpdesk\Data\Replies;

defined( 'ABSPATH' ) || exit;

/**
 * Thread.
 */
class Thread {

	/**
	 * Thread text.
	 *
	 * @param int  $ticket_id Ticket id.
	 * @param bool $notes     Include internal notes (agent-only uses).
	 * @param int  $max       Max chars.
	 * @return string
	 */
	public static function text( $ticket_id, $notes = false, $max = 12000 ) {
		$parts = array();
		foreach ( Replies::for_ticket( $ticket_id, true ) as $reply ) {
			if ( 'event' === $reply['type'] || ( 'note' === $reply['type'] && ! $notes ) ) {
				continue;
			}
			$who     = 'note' === $reply['type'] ? 'Internal note' : ( 'customer' === $reply['author'] ? 'Customer' : 'Support' );
			$files   = $reply['attachments'] ? ' [attachments: ' . implode( ', ', wp_list_pluck( $reply['attachments'], 'name' ) ) . ']' : '';
			$parts[] = '--- ' . $who . ' (' . substr( $reply['created_at'], 0, 16 ) . ')' . $files . "\n" . mb_substr( $reply['body'], 0, 4000 );
		}
		$text = implode( "\n\n", $parts );
		if ( mb_strlen( $text ) > $max ) {
			$text = "[earlier messages omitted]\n" . mb_substr( $text, -$max );
		}
		return str_replace( array( '<customer_data>', '</customer_data>' ), '', $text );
	}

	/**
	 * Last customer message.
	 *
	 * @param int $ticket_id Ticket id.
	 * @return string
	 */
	public static function last_customer( $ticket_id ) {
		$last = '';
		foreach ( Replies::for_ticket( $ticket_id ) as $reply ) {
			if ( 'customer' === $reply['author'] ) {
				$last = $reply['body'];
			}
		}
		return $last;
	}
}
