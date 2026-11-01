<?php
/**
 * A4: thread summary for agents (read-only), cached per thread content.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Ai;

use Dev3\AiHelpdesk\Core3\Api\Prompts;
use Dev3\AiHelpdesk\Data\Replies;
use Dev3\AiHelpdesk\Data\Tickets;

defined( 'ABSPATH' ) || exit;

/**
 * Summarizer.
 */
class Summarizer {

	/**
	 * Summarises a ticket (reuses the stored summary while the thread is unchanged).
	 *
	 * @param int  $ticket_id Ticket id.
	 * @param bool $force     Ignore the stored summary.
	 * @return array|\WP_Error Summary.
	 */
	public static function run( $ticket_id, $force = false ) {
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return new \WP_Error( 'dev3hd_not_found', __( 'Ticket not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) );
		}
		$thread = Thread::text( $ticket_id, true, 20000 );
		$hash   = md5( $thread );
		if ( ! $force && ! empty( $ticket['ai']['summary'] ) && ( $ticket['ai']['summary']['hash'] ?? '' ) === $hash ) {
			return $ticket['ai']['summary'];
		}
		$labels = Tickets::status_labels();
		$result = Prompts::run(
			'summary',
			array(
				'number'  => $ticket['number'],
				'subject' => $ticket['subject'],
				'status'  => $labels[ $ticket['status'] ] ?? $ticket['status'],
				'thread'  => $thread,
			),
			'admin',
			array( 'cache' => WEEK_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data    = (array) ( $result['data'] ?? array() );
		$list    = static function ( $items, $max ) {
			$out = array();
			foreach ( array_slice( (array) $items, 0, $max ) as $item ) {
				$item = mb_substr( sanitize_text_field( (string) $item ), 0, 160 );
				if ( '' !== $item ) {
					$out[] = $item;
				}
			}
			return $out;
		};
		$summary = array(
			'summary'        => mb_substr( sanitize_textarea_field( (string) ( $data['summary'] ?? '' ) ), 0, 600 ),
			'customer_goal'  => mb_substr( sanitize_text_field( (string) ( $data['customer_goal'] ?? '' ) ), 0, 200 ),
			'steps_taken'    => $list( $data['steps_taken'] ?? array(), 6 ),
			'open_questions' => $list( $data['open_questions'] ?? array(), 4 ),
			'next_action'    => mb_substr( sanitize_text_field( (string) ( $data['next_action'] ?? '' ) ), 0, 200 ),
			'hash'           => $hash,
			'messages'       => Replies::count_messages( $ticket_id ),
			'model'          => (string) $result['model'],
			'tokens'         => (int) ( $result['usage']['total'] ?? 0 ),
			'at'             => Tickets::now(),
		);
		Tickets::update_ai( $ticket_id, array( 'summary' => $summary ) );
		return $summary;
	}
}
