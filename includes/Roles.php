<?php
/**
 * Helpdesk agent role and capability. Managers (manage_options by default) always hold the agent capability.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk;

use Dev3\AiHelpdesk\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Roles.
 */
class Roles {

	const CAP = 'dev3hd_handle_tickets';

	const ROLE = 'dev3hd_agent';

	/**
	 * Creates the agent role (activation).
	 */
	public static function install() {
		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				'Helpdesk agent',
				array(
					'read'    => true,
					self::CAP => true,
				)
			);
		}
	}

	/**
	 * Hooks.
	 */
	public static function register() {
		add_filter( 'user_has_cap', array( self::class, 'grant' ), 10, 1 );
		add_action( 'init', array( self::class, 'install' ) );
	}

	/**
	 * Managers get the agent capability.
	 *
	 * @param array $allcaps Caps.
	 * @return array
	 */
	public static function grant( $allcaps ) {
		if ( ! empty( $allcaps[ Capabilities::manage() ] ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}

	/**
	 * Current (or given) user handles tickets.
	 *
	 * @param int|null $user_id User id.
	 * @return bool
	 */
	public static function is_agent( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return $user_id > 0 && user_can( $user_id, self::CAP );
	}

	/**
	 * Current (or given) user manages the helpdesk.
	 *
	 * @param int|null $user_id User id.
	 * @return bool
	 */
	public static function is_manager( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return $user_id > 0 && user_can( $user_id, Capabilities::manage() );
	}

	/**
	 * All agents (agent role + managers).
	 *
	 * @return array [ { id, name, email, avatar, manager, departments } ]
	 */
	public static function agents() {
		$users = get_users(
			array(
				'capability__in' => array( self::CAP, Capabilities::manage() ),
				'orderby'        => 'display_name',
				'number'         => 200,
			)
		);
		$out   = array();
		foreach ( $users as $user ) {
			$out[] = array(
				'id'          => (int) $user->ID,
				'name'        => $user->display_name,
				'email'       => $user->user_email,
				'avatar'      => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
				'manager'     => user_can( $user, Capabilities::manage() ),
				'departments' => self::departments_of( (int) $user->ID ),
			);
		}
		return $out;
	}

	/**
	 * Department ids an agent belongs to.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	public static function departments_of( $user_id ) {
		$out = array();
		foreach ( (array) Settings::get( 'departments', array() ) as $dept ) {
			if ( in_array( (int) $user_id, array_map( 'intval', (array) ( $dept['agents'] ?? array() ) ), true ) ) {
				$out[] = (string) $dept['id'];
			}
		}
		return $out;
	}
}
