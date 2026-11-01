<?php
/**
 * Auto-assignment: round-robin over the department's agents.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Helpdesk;

use Dev3\AiHelpdesk\Roles;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routing.
 */
class Routing {

	const OPTION = 'dev3hd_round_robin';

	/**
	 * Agent for a new ticket in a department (0 = leave unassigned).
	 *
	 * @param string $department Department id.
	 * @return int
	 */
	public static function pick_agent( $department ) {
		if ( 'round_robin' !== Settings::get( 'auto_assign', 'none' ) ) {
			return 0;
		}
		$dept   = Settings::department( $department );
		$agents = $dept ? array_values( array_filter( array_map( 'intval', (array) $dept['agents'] ), array( Roles::class, 'is_agent' ) ) ) : array();
		if ( ! $agents ) {
			return 0;
		}
		$state                = (array) get_option( self::OPTION, array() );
		$last                 = (int) ( $state[ $department ] ?? -1 );
		$next                 = ( $last + 1 ) % count( $agents );
		$state[ $department ] = $next;
		update_option( self::OPTION, $state, false );
		/**
		 * Filters the auto-assigned agent.
		 *
		 * @param int    $agent_id   Agent user id.
		 * @param string $department Department id.
		 */
		return (int) apply_filters( 'dev3hd_auto_assign', $agents[ $next ], $department );
	}
}
