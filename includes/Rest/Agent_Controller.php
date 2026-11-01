<?php
/**
 * Agent routes (helpdesk agents and managers; strict nonce): inbox, ticket detail, replies, properties,
 * bulk actions, WooCommerce orders and the DEV3 AI ticket tools.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Rest;

use Dev3\AiHelpdesk\Ai\Reply_Drafter;
use Dev3\AiHelpdesk\Ai\Summarizer;
use Dev3\AiHelpdesk\Ai\Triage;
use Dev3\AiHelpdesk\Core3\Api\Ai;
use Dev3\AiHelpdesk\Core3\Rest\Base_Controller;
use Dev3\AiHelpdesk\Data\Canned;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Helpdesk\Files;
use Dev3\AiHelpdesk\Helpdesk\Reports;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Roles;
use Dev3\AiHelpdesk\Settings;
use Dev3\AiHelpdesk\Woo\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Agent_Controller.
 */
class Agent_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$agent = array( $this, 'can_agent' );
		$id    = array( 'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) );
		$map   = array(
			array( '/boot', 'GET', 'boot' ),
			array( '/stats', 'GET', 'stats' ),
			array( '/tickets', 'GET', 'list_tickets' ),
			array( '/tickets', 'POST', 'create_ticket' ),
			array( '/tickets/bulk', 'POST', 'bulk' ),
			array( '/tickets/(?P<id>\d+)', 'GET', 'get_ticket' ),
			array( '/tickets/(?P<id>\d+)', 'PATCH', 'update_ticket' ),
			array( '/tickets/(?P<id>\d+)', 'DELETE', 'delete_ticket' ),
			array( '/tickets/(?P<id>\d+)/replies', 'POST', 'reply' ),
			array( '/tickets/(?P<id>\d+)/orders', 'GET', 'orders' ),
			array( '/tickets/(?P<id>\d+)/ai/triage', 'POST', 'ai_triage' ),
			array( '/tickets/(?P<id>\d+)/ai/apply-triage', 'POST', 'ai_apply_triage' ),
			array( '/tickets/(?P<id>\d+)/ai/draft', 'POST', 'ai_draft' ),
			array( '/tickets/(?P<id>\d+)/ai/summary', 'POST', 'ai_summary' ),
			array( '/ai/rewrite', 'POST', 'ai_rewrite' ),
			array( '/canned/(?P<id>\d+)/use', 'POST', 'use_canned' ),
		);
		foreach ( $map as $route ) {
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => $agent,
					'args'                => false !== strpos( $route[0], '(?P<id>' ) ? $id : array(),
				)
			);
		}
	}

	/**
	 * Permission: helpdesk agent + strict nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_agent( \WP_REST_Request $request ) {
		if ( ! $this->nonce_ok( $request ) ) {
			return new \WP_Error( 'dev3hd_bad_nonce', __( 'Your session has expired. Reload the page and try again.', 'dev3-ai-helpdesk' ), array( 'status' => 403 ) );
		}
		if ( ! Roles::is_agent() ) {
			return new \WP_Error( 'dev3hd_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-helpdesk' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Loads a ticket the current agent may see.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	protected function ticket( \WP_REST_Request $request ) {
		$ticket = Tickets::get( (int) $request['id'] );
		if ( ! $ticket ) {
			return new \WP_Error( 'dev3hd_not_found', __( 'Ticket not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) );
		}
		if ( ! Tickets::agent_can_view( $ticket, get_current_user_id() ) ) {
			return new \WP_Error( 'dev3hd_forbidden', __( 'This ticket belongs to another department.', 'dev3-ai-helpdesk' ), array( 'status' => 403 ) );
		}
		return $ticket;
	}

	/**
	 * GET /boot.
	 *
	 * @return \WP_REST_Response
	 */
	public function boot() {
		$user = wp_get_current_user();
		$ai   = Ai::status();
		unset( $ai['month'] );
		return $this->respond(
			array(
				'me'          => array(
					'id'          => (int) $user->ID,
					'name'        => $user->display_name,
					'manager'     => Roles::is_manager(),
					'departments' => Roles::departments_of( (int) $user->ID ),
					'can_kb'      => current_user_can( 'edit_posts' ),
				),
				'departments' => array_map(
					static fn( $d ) => array(
						'id'   => $d['id'],
						'name' => $d['name'],
					),
					Settings::departments()
				),
				'categories'  => Settings::categories(),
				'agents'      => array_map(
					static fn( $a ) => array(
						'id'          => $a['id'],
						'name'        => $a['name'],
						'avatar'      => $a['avatar'],
						'departments' => $a['departments'],
					),
					Roles::agents()
				),
				'canned'      => Canned::all(),
				'ai'          => $ai,
				'options'     => array(
					'sla'         => (bool) Settings::get( 'sla_enabled', true ),
					'csat'        => (bool) Settings::get( 'csat_enabled', true ),
					'attachments' => (bool) Settings::get( 'allow_attachments', true ),
					'max_files'   => (int) Settings::get( 'max_files', 3 ),
					'max_file_mb' => (int) Settings::get( 'max_file_mb', 5 ),
					'woo'         => Orders::active(),
					'auto_send'   => (bool) Settings::get( 'ai_auto_send', false ),
				),
				'counts'      => Tickets::counts( array(), get_current_user_id() ),
			)
		);
	}

	/**
	 * GET /stats.
	 *
	 * @return \WP_REST_Response
	 */
	public function stats() {
		return $this->respond( Reports::dashboard( get_current_user_id() ) );
	}

	/**
	 * Filters from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function filters( \WP_REST_Request $request ) {
		$args = array();
		foreach ( array( 'status', 'view', 'department', 'category', 'priority', 'channel', 'orderby', 'order' ) as $key ) {
			if ( null !== $request->get_param( $key ) && '' !== (string) $request->get_param( $key ) ) {
				$args[ $key ] = sanitize_key( (string) $request->get_param( $key ) );
			}
		}
		if ( null !== $request->get_param( 'agent' ) && '' !== (string) $request->get_param( 'agent' ) ) {
			$args['agent'] = (int) $request->get_param( 'agent' );
		}
		if ( $request->get_param( 'search' ) ) {
			$args['search'] = mb_substr( sanitize_text_field( (string) $request->get_param( 'search' ) ), 0, 100 );
		}
		$args['page']     = max( 1, (int) $request->get_param( 'page' ) );
		$args['per_page'] = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 20 ) ) );
		return $args;
	}

	/**
	 * GET /tickets.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_tickets( \WP_REST_Request $request ) {
		$args   = self::filters( $request );
		$result = Tickets::query( $args, get_current_user_id() );
		return $this->respond(
			array(
				'rows'   => array_map( array( Presenter::class, 'row' ), $result['rows'] ),
				'total'  => $result['total'],
				'counts' => Tickets::counts( $args, get_current_user_id() ),
			)
		);
	}

	/**
	 * POST /tickets: an agent opens a ticket for a customer (multipart).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_ticket( \WP_REST_Request $request ) {
		$id = Service::create_ticket(
			array(
				'name'       => (string) $request->get_param( 'name' ),
				'email'      => (string) $request->get_param( 'email' ),
				'subject'    => (string) $request->get_param( 'subject' ),
				'message'    => (string) $request->get_param( 'message' ),
				'category'   => (string) $request->get_param( 'category' ),
				'priority'   => (string) $request->get_param( 'priority' ),
				'department' => (string) $request->get_param( 'department' ),
				'agent_id'   => (int) $request->get_param( 'agent_id' ),
				'status'     => 'open',
				'channel'    => 'agent',
				'created_by' => get_current_user_id(),
			),
			Files::normalize( (array) $request->get_file_params() )
		);
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		return $this->respond( Presenter::full( Tickets::get( $id ) ), 201 );
	}

	/**
	 * GET /tickets/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_ticket( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		return $this->respond( is_wp_error( $ticket ) ? $ticket : Presenter::full( $ticket ) );
	}

	/**
	 * PATCH /tickets/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_ticket( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		$props = array();
		foreach ( array( 'status', 'priority', 'department', 'category', 'subject' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$props[ $key ] = (string) $request->get_param( $key );
			}
		}
		if ( null !== $request->get_param( 'agent_id' ) ) {
			$props['agent_id'] = (int) $request->get_param( 'agent_id' );
		}
		if ( null !== $request->get_param( 'tags' ) ) {
			$props['tags'] = $request->get_param( 'tags' );
		}
		$updated = Service::update_props( $ticket['id'], $props, get_current_user_id() );
		return $this->respond( is_wp_error( $updated ) ? $updated : Presenter::full( $updated ) );
	}

	/**
	 * DELETE /tickets/<id> (managers).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_ticket( \WP_REST_Request $request ) {
		if ( ! Roles::is_manager() ) {
			return $this->respond( new \WP_Error( 'dev3hd_forbidden', __( 'Only helpdesk managers can delete tickets.', 'dev3-ai-helpdesk' ), array( 'status' => 403 ) ) );
		}
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		Tickets::delete( $ticket['id'] );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * POST /tickets/bulk { ids, action: assign|status|priority|delete, value }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk( \WP_REST_Request $request ) {
		$ids    = array_slice( array_map( 'intval', (array) $request->get_param( 'ids' ) ), 0, 200 );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$value  = $request->get_param( 'value' );
		if ( 'delete' === $action && ! Roles::is_manager() ) {
			return $this->respond( new \WP_Error( 'dev3hd_forbidden', __( 'Only helpdesk managers can delete tickets.', 'dev3-ai-helpdesk' ), array( 'status' => 403 ) ) );
		}
		$done = 0;
		foreach ( $ids as $id ) {
			$ticket = Tickets::get( $id );
			if ( ! $ticket || ! Tickets::agent_can_view( $ticket, get_current_user_id() ) ) {
				continue;
			}
			if ( 'delete' === $action ) {
				Tickets::delete( $id );
				++$done;
				continue;
			}
			$props = array();
			if ( 'assign' === $action ) {
				$props['agent_id'] = (int) $value;
			} elseif ( 'status' === $action ) {
				$props['status'] = sanitize_key( (string) $value );
			} elseif ( 'priority' === $action ) {
				$props['priority'] = sanitize_key( (string) $value );
			} else {
				return $this->respond( new \WP_Error( 'dev3hd_bulk_action', __( 'Unknown bulk action.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) ) );
			}
			$res = Service::update_props( $id, $props, get_current_user_id() );
			if ( ! is_wp_error( $res ) ) {
				++$done;
			}
		}
		return $this->respond( array( 'updated' => $done ) );
	}

	/**
	 * POST /tickets/<id>/replies (multipart: body, type reply|note, status, notify).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reply( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		$data   = array(
			'type'    => 'note' === $request->get_param( 'type' ) ? 'note' : 'reply',
			'author'  => 'agent',
			'user_id' => get_current_user_id(),
			'body'    => (string) $request->get_param( 'body' ),
			'via'     => 'web',
		);
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( in_array( $status, Tickets::STATUSES, true ) ) {
			$data['status'] = $status;
		}
		$id = Service::add_reply( $ticket['id'], $data, Files::normalize( (array) $request->get_file_params() ) );
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		$fresh = Tickets::get( $ticket['id'] );
		if ( ! empty( $fresh['ai']['draft'] ) && 'reply' === $data['type'] ) {
			$draft         = $fresh['ai']['draft'];
			$draft['sent'] = true;
			Tickets::update_ai( $ticket['id'], array( 'draft' => $draft ) );
			$fresh = Tickets::get( $ticket['id'] );
		}
		return $this->respond( Presenter::full( $fresh ), 201 );
	}

	/**
	 * GET /tickets/<id>/orders.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function orders( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		if ( ! Orders::active() ) {
			return $this->respond( new \WP_Error( 'dev3hd_no_woo', __( 'WooCommerce is not active.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) ) );
		}
		return $this->respond( Orders::for_ticket( $ticket ) );
	}

	/**
	 * POST /tickets/<id>/ai/triage.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_triage( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		$res = Triage::run( $ticket['id'], 'admin' );
		return $this->respond( is_wp_error( $res ) ? $res : Presenter::full( Tickets::get( $ticket['id'] ) ) );
	}

	/**
	 * POST /tickets/<id>/ai/apply-triage.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_apply_triage( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		$res = Triage::apply( $ticket['id'], get_current_user_id() );
		return $this->respond( is_wp_error( $res ) ? $res : Presenter::full( $res ) );
	}

	/**
	 * POST /tickets/<id>/ai/draft { tone }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_draft( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		$res = Reply_Drafter::draft( $ticket['id'], 'admin', array( 'tone' => sanitize_key( (string) $request->get_param( 'tone' ) ) ) );
		return $this->respond( $res );
	}

	/**
	 * POST /tickets/<id>/ai/summary { force }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_summary( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		return $this->respond( Summarizer::run( $ticket['id'], (bool) $request->get_param( 'force' ) ) );
	}

	/**
	 * POST /ai/rewrite { text, mode }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_rewrite( \WP_REST_Request $request ) {
		return $this->respond( Reply_Drafter::rewrite( (string) $request->get_param( 'text' ), sanitize_key( (string) $request->get_param( 'mode' ) ) ) );
	}

	/**
	 * POST /canned/<id>/use { ticket_id } → filled text.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function use_canned( \WP_REST_Request $request ) {
		$ticket = null;
		if ( $request->get_param( 'ticket_id' ) ) {
			$ticket = Tickets::get( (int) $request->get_param( 'ticket_id' ) );
			if ( $ticket && ! Tickets::agent_can_view( $ticket, get_current_user_id() ) ) {
				$ticket = null;
			}
		}
		$text = Canned::use_for( (int) $request['id'], $ticket );
		if ( null === $text ) {
			return $this->respond( new \WP_Error( 'dev3hd_not_found', __( 'Saved reply not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) ) );
		}
		return $this->respond( array( 'text' => $text ) );
	}
}
