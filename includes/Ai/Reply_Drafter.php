<?php
/**
 * A2/A3: knowledge-base-grounded reply drafts (embeddings retrieval, keyword fallback), the opt-in
 * auto-send rule, and rewrites of the agent's own text.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Ai;

use Dev3\AiHelpdesk\Core3\Api\Prompts;
use Dev3\AiHelpdesk\Data\Replies;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Kb\Kb;
use Dev3\AiHelpdesk\Kb\Search;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reply_Drafter.
 */
class Reply_Drafter {

	const TONES = array( 'friendly', 'formal', 'concise', 'empathetic' );

	/**
	 * Drafts a reply and stores it on the ticket (never sends).
	 *
	 * @param int    $ticket_id Ticket id.
	 * @param string $context   admin|cron.
	 * @param array  $opts      tone, agent (name).
	 * @return array|\WP_Error Draft.
	 */
	public static function draft( $ticket_id, $context = 'admin', array $opts = array() ) {
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return new \WP_Error( 'dev3hd_not_found', __( 'Ticket not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) );
		}
		$query    = $ticket['subject'] . "\n" . mb_substr( Thread::last_customer( $ticket_id ), 0, 1500 );
		$hits     = Search::for_text( $query, 4, $context );
		$articles = array();
		$known    = array();
		foreach ( $hits as $hit ) {
			$post               = $hit[0];
			$known[ $post->ID ] = $post;
			$articles[]         = '[' . $post->ID . '] ' . get_the_title( $post ) . "\n" . mb_substr( Kb::text( $post, 2500 ), 0, 2500 );
		}
		$tone  = in_array( $opts['tone'] ?? '', self::TONES, true ) ? $opts['tone'] : 'friendly';
		$agent = (string) ( $opts['agent'] ?? '' );
		if ( '' === $agent ) {
			$user  = wp_get_current_user();
			$agent = $user && $user->exists() ? $user->display_name : (string) Settings::get( 'ai_agent_name', 'Support assistant' );
		}
		$lang   = (string) ( $ticket['ai']['triage']['language'] ?? '' );
		$result = Prompts::run(
			'reply_draft',
			array(
				'site'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'language'   => '' === $lang ? 'same as the customer' : $lang,
				'tone'       => $tone,
				'first_name' => trim( (string) strtok( (string) $ticket['name'], ' ' ) ),
				'agent'      => $agent,
				'articles'   => $articles ? implode( "\n\n", $articles ) : '(no matching articles)',
				'number'     => $ticket['number'],
				'subject'    => $ticket['subject'],
				'thread'     => Thread::text( $ticket_id, false, 10000 ),
			),
			$context,
			array( 'cache' => HOUR_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data    = (array) ( $result['data'] ?? array() );
		$sources = array();
		foreach ( array_slice( (array) ( $data['sources'] ?? array() ), 0, 5 ) as $id ) {
			$id = (int) $id;
			if ( isset( $known[ $id ] ) ) {
				$sources[] = array(
					'id'    => $id,
					'title' => html_entity_decode( get_the_title( $known[ $id ] ), ENT_QUOTES, 'UTF-8' ),
					'url'   => get_permalink( $known[ $id ] ),
				);
			}
		}
		$draft = array(
			'reply'        => Service::clean_text( (string) ( $data['reply'] ?? '' ), 4000 ),
			'sources'      => $sources,
			'confidence'   => round( min( 1, max( 0, (float) ( $data['confidence'] ?? 0 ) ) ), 2 ),
			'needs_human'  => ! empty( $data['needs_human'] ) || ! $sources,
			'missing_info' => mb_substr( sanitize_text_field( (string) ( $data['missing_info'] ?? '' ) ), 0, 300 ),
			'tone'         => $tone,
			'model'        => (string) $result['model'],
			'tokens'       => (int) ( $result['usage']['total'] ?? 0 ),
			'cached'       => ! empty( $result['cached'] ),
			'at'           => Tickets::now(),
			'messages'     => Replies::count_messages( $ticket_id ),
		);
		if ( '' === $draft['reply'] ) {
			return new \WP_Error( 'dev3hd_ai_empty', __( 'DEV3 AI returned an empty draft. Try again.', 'dev3-ai-helpdesk' ), array( 'status' => 502 ) );
		}
		Tickets::update_ai( $ticket_id, array( 'draft' => $draft ) );
		return $draft;
	}

	/**
	 * Sends the stored draft automatically when the opt-in rule allows it.
	 *
	 * @param int $ticket_id Ticket id.
	 * @return bool Sent.
	 */
	public static function maybe_auto_send( $ticket_id ) {
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket || ! Settings::get( 'ai_auto_send', false ) || empty( $ticket['ai']['draft'] ) ) {
			return false;
		}
		$draft = $ticket['ai']['draft'];
		$ok    = ! $draft['needs_human']
			&& ! empty( $draft['sources'] )
			&& (float) $draft['confidence'] >= (float) Settings::get( 'ai_send_threshold', 0.85 )
			&& empty( $ticket['first_response_at'] )
			&& in_array( $ticket['status'], array( 'new', 'open' ), true );
		/**
		 * Last word on auto-sending a DEV3 AI reply.
		 *
		 * @param bool  $ok     Allowed.
		 * @param array $ticket Ticket.
		 * @param array $draft  Draft.
		 */
		if ( ! apply_filters( 'dev3hd_ai_auto_send', $ok, $ticket, $draft ) ) {
			return false;
		}
		$id = Service::add_reply(
			$ticket_id,
			array(
				'type'   => 'reply',
				'author' => 'ai',
				'body'   => $draft['reply'],
				'status' => 'pending',
				'via'    => 'api',
			)
		);
		if ( is_wp_error( $id ) ) {
			return false;
		}
		$draft['sent'] = true;
		Tickets::update_ai( $ticket_id, array( 'draft' => $draft ) );
		/* translators: %s: confidence percent. */
		Service::event( $ticket_id, sprintf( __( 'Reply sent automatically by DEV3 AI (confidence %s%%).', 'dev3-ai-helpdesk' ), (int) round( $draft['confidence'] * 100 ) ), 0, 'ai' );
		return true;
	}

	/**
	 * Rewrites text.
	 *
	 * @param string $text Text.
	 * @param string $mode friendly|formal|shorter|grammar|empathetic.
	 * @return array|\WP_Error { text, model, tokens }
	 */
	public static function rewrite( $text, $mode ) {
		$text = Service::clean_text( (string) $text, 4000 );
		if ( '' === $text ) {
			return new \WP_Error( 'dev3hd_empty', __( 'Write something first.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$instructions = array(
			'friendly'   => 'Make it warmer and friendlier.',
			'formal'     => 'Make it more formal and professional.',
			'shorter'    => 'Make it shorter and clearer; keep every step and fact.',
			'grammar'    => 'Fix spelling, grammar and punctuation only.',
			'empathetic' => 'Acknowledge the customer\'s frustration with genuine empathy, then keep the solution.',
		);
		$mode         = isset( $instructions[ $mode ] ) ? $mode : 'grammar';
		$result       = Prompts::run(
			'rewrite',
			array(
				'instruction' => $instructions[ $mode ],
				'text'        => $text,
			),
			'admin',
			array( 'cache' => HOUR_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'text'   => Service::clean_text( (string) ( $result['data']['text'] ?? '' ), 4000 ),
			'model'  => (string) $result['model'],
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
			'cached' => ! empty( $result['cached'] ),
		);
	}
}
