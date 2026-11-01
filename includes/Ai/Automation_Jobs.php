<?php
/**
 * Background AI work for new tickets (core Jobs, cron context): triage (optionally applied), a prepared
 * reply draft, and the opt-in auto-send rule.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Ai;

use Dev3\AiHelpdesk\Core3\Api\Ai;
use Dev3\AiHelpdesk\Core3\Core\Jobs;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Automation_Jobs.
 */
class Automation_Jobs {

	const JOB = 'dev3hd_ai_ticket';

	/**
	 * Registers the job type and the new-ticket hook.
	 */
	public static function register() {
		Jobs::register( self::JOB, array( self::class, 'run_chunk' ) );
		add_action( 'dev3hd_ticket_created', array( self::class, 'maybe_queue' ), 30 );
	}

	/**
	 * Whether any automatic AI step is on and possible.
	 *
	 * @return bool
	 */
	public static function wanted() {
		$any = Settings::get( 'ai_auto_triage', true ) || Settings::get( 'ai_auto_draft', false ) || Settings::get( 'ai_auto_send', false );
		return $any && Ai::enabled( 'cron' ) && Ai::status()['key_set'];
	}

	/**
	 * Queues the AI job for a new ticket.
	 *
	 * @param int $ticket_id Ticket id.
	 * @return int|null Job id.
	 */
	public static function maybe_queue( $ticket_id ) {
		if ( ! self::wanted() ) {
			return null;
		}
		$id = Jobs::create( self::JOB, array( (int) $ticket_id ), array(), 1 );
		if ( is_wp_error( $id ) ) {
			return null;
		}
		Tickets::update( $ticket_id, array( 'ai_state' => 'queued' ) );
		return $id;
	}

	/**
	 * Job handler.
	 *
	 * @param array $items Ticket ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		unset( $job );
		$out = array();
		foreach ( $items as $id ) {
			$out[ (string) $id ] = self::process( (int) $id );
		}
		return $out;
	}

	/**
	 * Runs the automatic steps for one ticket.
	 *
	 * @param int $ticket_id Ticket id.
	 * @return true|\WP_Error
	 */
	public static function process( $ticket_id ) {
		if ( ! Tickets::get( $ticket_id ) ) {
			return true;
		}
		if ( Settings::get( 'ai_auto_triage', true ) ) {
			$triage = Triage::run( $ticket_id, 'cron' );
			if ( is_wp_error( $triage ) ) {
				return $triage;
			}
			if ( Settings::get( 'ai_auto_apply_triage', false ) ) {
				Triage::apply( $ticket_id, 0, 'ai' );
			}
		}
		if ( Settings::get( 'ai_auto_draft', false ) || Settings::get( 'ai_auto_send', false ) ) {
			$draft = Reply_Drafter::draft( $ticket_id, 'cron', array( 'agent' => (string) Settings::get( 'ai_agent_name', 'Support assistant' ) ) );
			if ( is_wp_error( $draft ) ) {
				return $draft;
			}
			Reply_Drafter::maybe_auto_send( $ticket_id );
		}
		Tickets::update( $ticket_id, array( 'ai_state' => 'done' ) );
		return true;
	}
}
