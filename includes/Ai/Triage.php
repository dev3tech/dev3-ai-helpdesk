<?php
/**
 * A1: category, priority, sentiment, language, summary and tags for a ticket. Stored as a draft on the
 * ticket; "Apply triage" (or the opt-in auto-apply setting) writes it to the ticket.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Ai;

use Dev3\AiHelpdesk\Core3\Api\Prompts;
use Dev3\AiHelpdesk\Data\Replies;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Triage.
 */
class Triage {

	/**
	 * Runs triage and stores the draft.
	 *
	 * @param int    $ticket_id Ticket id.
	 * @param string $context   admin|cron.
	 * @return array|\WP_Error Triage draft.
	 */
	public static function run( $ticket_id, $context = 'admin' ) {
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return new \WP_Error( 'dev3hd_not_found', __( 'Ticket not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) );
		}
		$first = '';
		foreach ( Replies::for_ticket( $ticket_id ) as $reply ) {
			if ( 'customer' === $reply['author'] ) {
				$first = $reply['body'];
				break;
			}
		}
		Prompts_Library::register();
		$result = Prompts::run(
			'triage',
			array(
				'site'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'categories' => Prompts_Library::categories_text(),
				'subject'    => $ticket['subject'],
				'channel'    => $ticket['channel'],
				'message'    => mb_substr( $first, 0, 6000 ),
			),
			$context,
			array( 'cache' => DAY_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			Tickets::update( $ticket_id, array( 'ai_state' => 'error' ) );
			return $result;
		}
		$triage = self::clean( (array) ( $result['data'] ?? array() ) );
		$triage = array_merge(
			$triage,
			array(
				'model'   => (string) $result['model'],
				'tokens'  => (int) ( $result['usage']['total'] ?? 0 ),
				'at'      => Tickets::now(),
				'applied' => false,
			)
		);
		Tickets::update_ai( $ticket_id, array( 'triage' => $triage ), array( 'ai_state' => 'done' ) );
		return $triage;
	}

	/**
	 * Validates and sanitises model output.
	 *
	 * @param array $data Raw data.
	 * @return array
	 */
	public static function clean( array $data ) {
		$category = sanitize_key( (string) ( $data['category'] ?? '' ) );
		$category = in_array( $category, Settings::category_ids(), true ) ? $category : '';
		$tags     = array();
		foreach ( array_slice( (array) ( $data['tags'] ?? array() ), 0, 5 ) as $tag ) {
			$tag = mb_substr( sanitize_text_field( mb_strtolower( (string) $tag ) ), 0, 30 );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}
		return array(
			'category'   => $category,
			'department' => '' === $category ? '' : Settings::category_department( $category ),
			'priority'   => in_array( $data['priority'] ?? '', Settings::PRIORITIES, true ) ? $data['priority'] : 'normal',
			'sentiment'  => in_array( $data['sentiment'] ?? '', Tickets::SENTIMENTS, true ) ? $data['sentiment'] : 'neutral',
			'language'   => mb_substr( sanitize_text_field( (string) ( $data['language'] ?? '' ) ), 0, 40 ),
			'summary'    => mb_substr( sanitize_text_field( (string) ( $data['summary'] ?? '' ) ), 0, 200 ),
			'reason'     => mb_substr( sanitize_text_field( (string) ( $data['reason'] ?? '' ) ), 0, 240 ),
			'tags'       => array_values( array_unique( $tags ) ),
			'confidence' => round( min( 1, max( 0, (float) ( $data['confidence'] ?? 0 ) ) ), 2 ),
		);
	}

	/**
	 * Applies the stored triage to the ticket.
	 *
	 * @param int    $ticket_id Ticket id.
	 * @param int    $user_id   Actor (0 = automatic).
	 * @param string $actor     agent|ai.
	 * @return array|\WP_Error Ticket.
	 */
	public static function apply( $ticket_id, $user_id = 0, $actor = 'agent' ) {
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket || empty( $ticket['ai']['triage'] ) ) {
			return new \WP_Error( 'dev3hd_no_triage', __( 'Run DEV3 AI triage first.', 'dev3-ai-helpdesk' ), array( 'status' => 409 ) );
		}
		$t     = $ticket['ai']['triage'];
		$tags  = array_filter( explode( ',', (string) $ticket['tags'] ) );
		$props = array(
			'priority'  => $t['priority'],
			'sentiment' => $t['sentiment'],
			'tags'      => array_merge( $tags, (array) $t['tags'] ),
			'_actor'    => 'ai' === $actor ? 'ai' : 'system',
		);
		if ( '' !== $t['category'] ) {
			$props['category']   = $t['category'];
			$props['department'] = $t['department'];
		}
		$updated = Service::update_props( $ticket_id, $props, $user_id );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$t['applied'] = true;
		Tickets::update_ai( $ticket_id, array( 'triage' => $t ) );
		Service::event( $ticket_id, 'ai' === $actor ? __( 'DEV3 AI triage applied automatically.', 'dev3-ai-helpdesk' ) : __( 'DEV3 AI triage applied.', 'dev3-ai-helpdesk' ), $user_id, 'ai' === $actor ? 'ai' : 'agent' );
		return Tickets::get( $ticket_id );
	}
}
