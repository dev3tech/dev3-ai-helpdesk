<?php
/**
 * Public routes: the customer portal (new ticket, my tickets, ticket view by owner or private key, reply,
 * close, rate, re-send links), help-centre search and votes, and the inbound email webhook.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Rest;

use Dev3\AiHelpdesk\Core3\Core\Rate_Limiter;
use Dev3\AiHelpdesk\Core3\Rest\Base_Controller;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Helpdesk\Files;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Kb\Kb;
use Dev3\AiHelpdesk\Kb\Search;
use Dev3\AiHelpdesk\Mail\Inbound;
use Dev3\AiHelpdesk\Mail\Mailer;
use Dev3\AiHelpdesk\Settings;
use Dev3\AiHelpdesk\Woo\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Public_Controller.
 */
class Public_Controller extends Base_Controller {

	const MIN_FILL_MS = 2500;

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$num = array( 'number' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) );
		$map = array(
			array( '/portal/tickets', 'POST', 'create', 'ticket', 5 ),
			array( '/portal/tickets', 'GET', 'mine', 'portal', 30 ),
			array( '/portal/tickets/(?P<number>\d+)', 'GET', 'view', 'portal', 30 ),
			array( '/portal/tickets/(?P<number>\d+)/reply', 'POST', 'reply', 'reply', 10 ),
			array( '/portal/tickets/(?P<number>\d+)/close', 'POST', 'close', 'portal', 30 ),
			array( '/portal/tickets/(?P<number>\d+)/rate', 'POST', 'rate', 'portal', 30 ),
			array( '/portal/resend', 'POST', 'resend', 'resend', 3 ),
			array( '/kb/search', 'GET', 'search', 'kb', 40 ),
			array( '/kb/vote', 'POST', 'vote', 'vote', 20 ),
		);
		foreach ( $map as $route ) {
			$bucket = $route[3];
			$limit  = $route[4];
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => fn( \WP_REST_Request $r ) => $this->public_permission( $r, $bucket, $limit ),
					'args'                => false !== strpos( $route[0], '(?P<number>' ) ? $num : array(),
				)
			);
		}
		register_rest_route(
			self::NS,
			'/inbound/(?P<token>[A-Za-z0-9]{16,64})',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'inbound' ),
				'permission_callback' => array( $this, 'can_inbound' ),
			)
		);
	}

	/**
	 * Webhook permission: enabled + secret + per-IP limit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_inbound( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'webhook_enabled', false ) ) {
			return new \WP_Error( 'dev3hd_webhook_off', __( 'The inbound email webhook is turned off.', 'dev3-ai-helpdesk' ), array( 'status' => 403 ) );
		}
		if ( ! hash_equals( Settings::inbound_token(), (string) $request['token'] ) ) {
			return new \WP_Error( 'dev3hd_webhook_token', __( 'Invalid webhook secret.', 'dev3-ai-helpdesk' ), array( 'status' => 403 ) );
		}
		if ( ! Rate_Limiter::hit( 'inbound', Rate_Limiter::client_ip(), (int) apply_filters( 'dev3hd_public_rate_limit', 120, 'inbound' ) ) ) {
			return new \WP_Error( 'dev3hd_rate_limited', __( 'Too many requests. Please wait a moment.', 'dev3-ai-helpdesk' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Logged-in customer (needs the REST nonce; cookie auth sets the user only then).
	 *
	 * @return \WP_User|null
	 */
	private function user() {
		$user = wp_get_current_user();
		return $user && $user->exists() ? $user : null;
	}

	/**
	 * Loads a ticket the visitor may see (owner, or the private key).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function ticket( \WP_REST_Request $request ) {
		$ticket = Tickets::get_by_number( (int) $request['number'] );
		$user   = $this->user();
		$key    = (string) $request->get_param( 'key' );
		$ok     = false;
		if ( $ticket ) {
			if ( '' !== $key && hash_equals( (string) $ticket['access_key'], $key ) ) {
				$ok = true;
			} elseif ( $user && ( (int) $ticket['customer_id'] === (int) $user->ID || strtolower( $user->user_email ) === strtolower( $ticket['email'] ) ) ) {
				$ok = true;
			}
		}
		if ( ! $ok ) {
			return new \WP_Error( 'dev3hd_ticket_access', __( 'We could not find that ticket. Use the link from your email, or log in.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) );
		}
		return $ticket;
	}

	/**
	 * POST /portal/tickets (multipart).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( \WP_REST_Request $request ) {
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) || (int) $request->get_param( 'elapsed' ) < self::MIN_FILL_MS ) {
			return $this->respond( new \WP_Error( 'dev3hd_spam', __( 'Please take a moment and try again.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) ) );
		}
		$user = $this->user();
		if ( ! $user && ( Settings::get( 'require_login', false ) || ! Settings::get( 'guest_tickets', true ) ) ) {
			return $this->respond( new \WP_Error( 'dev3hd_login_required', __( 'Please log in to contact support.', 'dev3-ai-helpdesk' ), array( 'status' => 401 ) ) );
		}
		$email = $user ? $user->user_email : (string) $request->get_param( 'email' );
		$order = 0;
		if ( Settings::get( 'woo_order_field', true ) && '' !== trim( (string) $request->get_param( 'order' ) ) && class_exists( 'WooCommerce' ) ) {
			$order = Orders::find_for( (string) $request->get_param( 'order' ), $email, $user ? (int) $user->ID : 0 );
			if ( ! $order ) {
				return $this->respond( new \WP_Error( 'dev3hd_order', __( 'We could not find that order for your email address. Check the number or leave it empty.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) ) );
			}
		}
		$id = Service::create_ticket(
			array(
				'name'        => $user ? $user->display_name : (string) $request->get_param( 'name' ),
				'email'       => $email,
				'customer_id' => $user ? (int) $user->ID : 0,
				'subject'     => (string) $request->get_param( 'subject' ),
				'message'     => (string) $request->get_param( 'message' ),
				'category'    => (string) $request->get_param( 'category' ),
				'priority'    => Settings::get( 'customer_priority', false ) ? (string) $request->get_param( 'priority' ) : 'normal',
				'order_id'    => $order,
				'channel'     => 'form' === $request->get_param( 'source' ) ? 'form' : 'portal',
			),
			Files::normalize( (array) $request->get_file_params() )
		);
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		return $this->respond( Presenter::customer( Tickets::get( $id ) ), 201 );
	}

	/**
	 * GET /portal/tickets (logged-in customers).
	 *
	 * @return \WP_REST_Response
	 */
	public function mine() {
		$user = $this->user();
		if ( ! $user ) {
			return $this->respond( new \WP_Error( 'dev3hd_login_required', __( 'Please log in to see your tickets.', 'dev3-ai-helpdesk' ), array( 'status' => 401 ) ) );
		}
		return $this->respond( array_map( array( Presenter::class, 'customer_row' ), Tickets::for_customer( (int) $user->ID, $user->user_email ) ) );
	}

	/**
	 * GET /portal/tickets/<number>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function view( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		return $this->respond( is_wp_error( $ticket ) ? $ticket : Presenter::customer( $ticket ) );
	}

	/**
	 * POST /portal/tickets/<number>/reply (multipart).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reply( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		$user = $this->user();
		$id   = Service::add_reply(
			$ticket['id'],
			array(
				'author'  => 'customer',
				'user_id' => $user ? (int) $user->ID : (int) $ticket['customer_id'],
				'body'    => (string) $request->get_param( 'body' ),
				'via'     => 'web',
			),
			Files::normalize( (array) $request->get_file_params() )
		);
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		return $this->respond( Presenter::customer( Tickets::get( $ticket['id'] ) ), 201 );
	}

	/**
	 * POST /portal/tickets/<number>/close.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function close( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		if ( ! in_array( $ticket['status'], array( 'resolved', 'closed' ), true ) ) {
			Service::set_status( $ticket['id'], 'resolved', 0, 'customer' );
		}
		return $this->respond( Presenter::customer( Tickets::get( $ticket['id'] ) ) );
	}

	/**
	 * POST /portal/tickets/<number>/rate { rating 1–3, comment }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rate( \WP_REST_Request $request ) {
		$ticket = $this->ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $this->respond( $ticket );
		}
		if ( ! Settings::get( 'csat_enabled', true ) ) {
			return $this->respond( new \WP_Error( 'dev3hd_csat_off', __( 'Ratings are turned off.', 'dev3-ai-helpdesk' ), array( 'status' => 403 ) ) );
		}
		if ( $ticket['rating'] ) {
			return $this->respond( new \WP_Error( 'dev3hd_rated', __( 'Thanks, you already rated this ticket.', 'dev3-ai-helpdesk' ), array( 'status' => 409 ) ) );
		}
		$res = Service::rate( $ticket['id'], (int) $request->get_param( 'rating' ), (string) $request->get_param( 'comment' ) );
		return $this->respond( is_wp_error( $res ) ? $res : Presenter::customer( Tickets::get( $ticket['id'] ) ) );
	}

	/**
	 * POST /portal/resend { email }: always the same answer (no account probing).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function resend( \WP_REST_Request $request ) {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return $this->respond( new \WP_Error( 'dev3hd_email', __( 'Enter a valid email address.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) ) );
		}
		$key = 'dev3hd_resend_' . md5( strtolower( $email ) );
		if ( ! get_transient( $key ) ) {
			set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
			Mailer::send_links( $email );
		}
		return $this->respond( array( 'sent' => true ) );
	}

	/**
	 * GET /kb/search?q=&limit=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search( \WP_REST_Request $request ) {
		$q     = mb_substr( sanitize_text_field( (string) $request->get_param( 'q' ) ), 0, 200 );
		$limit = min( 10, max( 1, (int) ( $request->get_param( 'limit' ) ? $request->get_param( 'limit' ) : 8 ) ) );
		$res   = Search::search( $q, $limit, 'public' );
		foreach ( $res['results'] as &$row ) {
			unset( $row['status'], $row['score'], $row['views'], $row['votes'], $row['helpful'], $row['modified'] );
		}
		unset( $row );
		return $this->respond( $res );
	}

	/**
	 * POST /kb/vote { id, helpful }: one vote per article per IP per day.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function vote( \WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$key = 'dev3hd_vote_' . md5( $id . '|' . Rate_Limiter::client_ip() );
		if ( get_transient( $key ) ) {
			return $this->respond( array( 'ok' => true ) );
		}
		if ( ! Kb::vote( $id, (bool) $request->get_param( 'helpful' ) ) ) {
			return $this->respond( new \WP_Error( 'dev3hd_not_found', __( 'Article not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) ) );
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		return $this->respond( array( 'ok' => true ) );
	}

	/**
	 * POST /inbound/<token>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function inbound( \WP_REST_Request $request ) {
		$msg = Inbound::from_request( $request );
		if ( ! $msg ) {
			return $this->respond( new \WP_Error( 'dev3hd_inbound_unreadable', __( 'The email could not be read.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) ) );
		}
		// Providers retry on non-2xx responses; ignored mail is still a success for them.
		return $this->respond( Inbound::process( $msg, 'webhook' ) );
	}
}
