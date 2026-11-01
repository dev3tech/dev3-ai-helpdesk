<?php
/**
 * Manager routes (manage capability; strict nonce): canned replies, agents, reports, knowledge base and
 * DEV3 AI article tools, email piping and setup.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Rest;

use Dev3\AiHelpdesk\Ai\Article_Builder;
use Dev3\AiHelpdesk\Core3\Core\Settings_Store;
use Dev3\AiHelpdesk\Core3\Rest\Base_Controller;
use Dev3\AiHelpdesk\Data\Canned;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Frontend\Portal;
use Dev3\AiHelpdesk\Helpdesk\Reports;
use Dev3\AiHelpdesk\Kb\Indexer;
use Dev3\AiHelpdesk\Kb\Kb;
use Dev3\AiHelpdesk\Kb\Search;
use Dev3\AiHelpdesk\Core3\Data\Embeddings_Store;
use Dev3\AiHelpdesk\Mail\Poller;
use Dev3\AiHelpdesk\Roles;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Manager_Controller.
 */
class Manager_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		$id     = array( 'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) );
		$map    = array(
			array( '/tickets/export', 'GET', 'export_tickets', $manage ),
			array( '/canned', 'GET', 'canned_list', $manage ),
			array( '/canned', 'POST', 'canned_create', $manage ),
			array( '/canned/(?P<id>\d+)', 'PATCH', 'canned_update', $manage ),
			array( '/canned/(?P<id>\d+)', 'DELETE', 'canned_delete', $manage ),
			array( '/agents', 'GET', 'agents', $manage ),
			array( '/agents', 'POST', 'add_agent', $manage ),
			array( '/agents/(?P<id>\d+)', 'DELETE', 'remove_agent', $manage ),
			array( '/reports', 'GET', 'reports', $manage ),
			array( '/reports/export', 'GET', 'reports_export', $manage ),
			array( '/kb/articles', 'GET', 'kb_articles', array( $this, 'can_kb' ) ),
			array( '/kb/articles', 'POST', 'kb_create', array( $this, 'can_kb' ) ),
			array( '/kb/status', 'GET', 'kb_status', array( $this, 'can_kb' ) ),
			array( '/kb/reindex', 'POST', 'kb_reindex', array( $this, 'can_kb' ) ),
			array( '/kb/gaps', 'GET', 'kb_gaps_get', array( $this, 'can_kb' ) ),
			array( '/kb/gaps', 'POST', 'kb_gaps', array( $this, 'can_kb' ) ),
			array( '/tickets/(?P<id>\d+)/ai/article', 'POST', 'ai_article', array( $this, 'can_kb' ) ),
			array( '/mail/status', 'GET', 'mail_status', $manage ),
			array( '/mail/test', 'POST', 'mail_test', $manage ),
			array( '/mail/poll', 'POST', 'mail_poll', $manage ),
			array( '/setup/pages', 'POST', 'setup_pages', $manage ),
		);
		foreach ( $map as $route ) {
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => $route[3],
					'args'                => false !== strpos( $route[0], '(?P<id>' ) ? $id : array(),
				)
			);
		}
	}

	/**
	 * Knowledge-base tools: helpdesk agents who can also edit posts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_kb( \WP_REST_Request $request ) {
		if ( ! $this->nonce_ok( $request ) ) {
			return new \WP_Error( 'dev3hd_bad_nonce', __( 'Your session has expired. Reload the page and try again.', 'dev3-ai-helpdesk' ), array( 'status' => 403 ) );
		}
		if ( ! Roles::is_agent() || ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'dev3hd_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-helpdesk' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * CSV payload.
	 *
	 * @param array  $rows     Rows.
	 * @param string $filename File name.
	 * @return array
	 */
	private static function csv( array $rows, $filename ) {
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = implode(
				',',
				array_map(
					static function ( $cell ) {
						$cell = (string) $cell;
						if ( preg_match( '/^[=+\-@\t\r]/', $cell ) ) {
							$cell = "'" . $cell; // Spreadsheet formula injection guard.
						}
						return '"' . str_replace( '"', '""', $cell ) . '"';
					},
					$row
				)
			);
		}
		return array(
			'filename' => $filename,
			'csv'      => implode( "\n", $lines ),
		);
	}

	/**
	 * GET /tickets/export (current filters).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function export_tickets( \WP_REST_Request $request ) {
		$args             = Agent_Controller::filters( $request );
		$args['per_page'] = 200;
		$rows             = array( array( 'number', 'subject', 'status', 'priority', 'category', 'department', 'agent', 'customer', 'email', 'channel', 'created', 'updated' ) );
		for ( $page = 1; $page <= 50; $page++ ) {
			$args['page'] = $page;
			$res          = Tickets::query( $args );
			foreach ( $res['rows'] as $t ) {
				$agent  = $t['agent_id'] ? get_userdata( $t['agent_id'] ) : null;
				$rows[] = array( $t['number'], $t['subject'], $t['status'], $t['priority'], $t['category'], $t['department'], $agent ? $agent->display_name : '', $t['name'], $t['email'], $t['channel'], $t['created_at'], $t['updated_at'] );
			}
			if ( count( $res['rows'] ) < 200 ) {
				break;
			}
		}
		return $this->respond( self::csv( $rows, 'tickets-' . gmdate( 'Y-m-d' ) . '.csv' ) );
	}

	/**
	 * GET /canned.
	 *
	 * @return \WP_REST_Response
	 */
	public function canned_list() {
		return $this->respond( Canned::all() );
	}

	/**
	 * POST /canned.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function canned_create( \WP_REST_Request $request ) {
		$id = Canned::save( (array) $request->get_params() );
		return $this->respond( is_wp_error( $id ) ? $id : Canned::get( $id ), 201 );
	}

	/**
	 * PATCH /canned/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function canned_update( \WP_REST_Request $request ) {
		if ( ! Canned::get( (int) $request['id'] ) ) {
			return $this->respond( new \WP_Error( 'dev3hd_not_found', __( 'Saved reply not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) ) );
		}
		$id = Canned::save( (array) $request->get_params(), (int) $request['id'] );
		return $this->respond( is_wp_error( $id ) ? $id : Canned::get( $id ) );
	}

	/**
	 * DELETE /canned/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function canned_delete( \WP_REST_Request $request ) {
		Canned::delete( (int) $request['id'] );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * GET /agents.
	 *
	 * @return \WP_REST_Response
	 */
	public function agents() {
		return $this->respond( Roles::agents() );
	}

	/**
	 * POST /agents { user (login or email), departments[] }: gives the user the agent role.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function add_agent( \WP_REST_Request $request ) {
		$who  = sanitize_text_field( (string) $request->get_param( 'user' ) );
		$user = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
		if ( ! $user ) {
			return $this->respond( new \WP_Error( 'dev3hd_no_user', __( 'No user with that user name or email. Create the WordPress user first (Users → Add New).', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) ) );
		}
		Roles::install();
		if ( ! Roles::is_agent( $user->ID ) ) {
			$user->add_role( Roles::ROLE );
		}
		$this->set_departments( (int) $user->ID, (array) $request->get_param( 'departments' ) );
		return $this->respond( Roles::agents(), 201 );
	}

	/**
	 * Puts an agent into exactly these departments.
	 *
	 * @param int   $user_id User id.
	 * @param array $ids     Department ids.
	 */
	private function set_departments( $user_id, array $ids ) {
		$ids   = array_map( 'sanitize_key', $ids );
		$depts = Settings::departments();
		foreach ( $depts as &$dept ) {
			$agents = array_map( 'intval', (array) $dept['agents'] );
			$agents = array_values( array_diff( $agents, array( $user_id ) ) );
			if ( in_array( (string) $dept['id'], $ids, true ) ) {
				$agents[] = $user_id;
			}
			$dept['agents'] = $agents;
		}
		unset( $dept );
		Settings_Store::update( array( 'departments' => $depts ) );
	}

	/**
	 * DELETE /agents/<id>: removes the agent role (managers stay managers).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function remove_agent( \WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['id'] );
		if ( ! $user ) {
			return $this->respond( new \WP_Error( 'dev3hd_no_user', __( 'User not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) ) );
		}
		$user->remove_role( Roles::ROLE );
		$user->remove_cap( Roles::CAP );
		$this->set_departments( (int) $user->ID, array() );
		return $this->respond( Roles::agents() );
	}

	/**
	 * GET /reports?days=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reports( \WP_REST_Request $request ) {
		return $this->respond( Reports::report( (int) ( $request->get_param( 'days' ) ? $request->get_param( 'days' ) : 30 ) ) );
	}

	/**
	 * GET /reports/export?days=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reports_export( \WP_REST_Request $request ) {
		$days = (int) ( $request->get_param( 'days' ) ? $request->get_param( 'days' ) : 30 );
		return $this->respond( self::csv( Reports::csv_rows( $days ), 'helpdesk-report-' . gmdate( 'Y-m-d' ) . '.csv' ) );
	}

	/**
	 * GET /kb/articles.
	 *
	 * @return \WP_REST_Response
	 */
	public function kb_articles() {
		$posts = get_posts(
			array(
				'post_type'      => Kb::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded admin listing.
				'orderby'        => 'modified',
			)
		);
		$store = Embeddings_Store::instance();
		$out   = array();
		foreach ( $posts as $post ) {
			$row            = Kb::present( $post );
			$row['indexed'] = 'publish' === $post->post_status && ! $store->is_stale( Search::TYPE, $post->ID, Embeddings_Store::hash( Kb::text( $post ) ) );
			$row['edit']    = get_edit_post_link( $post->ID, 'raw' );
			$row['source']  = (int) get_post_meta( $post->ID, '_dev3hd_source_ticket', true );
			$out[]          = $row;
		}
		return $this->respond( $out );
	}

	/**
	 * POST /kb/articles { title, excerpt, content, category, ticket_id }: draft from an accepted AI draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function kb_create( \WP_REST_Request $request ) {
		return $this->respond(
			Article_Builder::create(
				array(
					'title'    => (string) $request->get_param( 'title' ),
					'excerpt'  => (string) $request->get_param( 'excerpt' ),
					'content'  => (string) $request->get_param( 'content' ),
					'category' => (string) $request->get_param( 'category' ),
				),
				(int) $request->get_param( 'ticket_id' )
			),
			201
		);
	}

	/**
	 * GET /kb/status.
	 *
	 * @return \WP_REST_Response
	 */
	public function kb_status() {
		return $this->respond( Indexer::status() );
	}

	/**
	 * POST /kb/reindex.
	 *
	 * @return \WP_REST_Response
	 */
	public function kb_reindex() {
		$job = Indexer::queue();
		if ( is_wp_error( $job ) ) {
			return $this->respond( $job );
		}
		return $this->respond(
			array(
				'job'    => $job,
				'status' => Indexer::status(),
			)
		);
	}

	/**
	 * GET /kb/gaps (last report).
	 *
	 * @return \WP_REST_Response
	 */
	public function kb_gaps_get() {
		$report = get_option( Article_Builder::GAPS_OPTION, null );
		return $this->respond( is_array( $report ) ? $report : null );
	}

	/**
	 * POST /kb/gaps.
	 *
	 * @return \WP_REST_Response
	 */
	public function kb_gaps() {
		return $this->respond( Article_Builder::gaps() );
	}

	/**
	 * POST /tickets/<id>/ai/article.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_article( \WP_REST_Request $request ) {
		$ticket = Tickets::get( (int) $request['id'] );
		if ( ! $ticket || ! Tickets::agent_can_view( $ticket, get_current_user_id() ) ) {
			return $this->respond( new \WP_Error( 'dev3hd_not_found', __( 'Ticket not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) ) );
		}
		return $this->respond( Article_Builder::draft( $ticket['id'] ) );
	}

	/**
	 * GET /mail/status.
	 *
	 * @return \WP_REST_Response
	 */
	public function mail_status() {
		$last = get_option( Poller::LAST, null );
		return $this->respond(
			array(
				'last'        => is_array( $last ) ? array_merge( $last, array( 'at_iso' => gmdate( 'c', (int) $last['at'] ) ) ) : null,
				'webhook_url' => rest_url( self::NS . '/inbound/' . Settings::inbound_token() ),
				'job'         => \Dev3\AiHelpdesk\Core3\Core\Jobs::active( Poller::JOB ),
				'from'        => \Dev3\AiHelpdesk\Mail\Mailer::from_email(),
			)
		);
	}

	/**
	 * POST /mail/test { host, port, encryption, user, password, folder } (unsaved values allowed).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function mail_test( \WP_REST_Request $request ) {
		$over = array();
		foreach ( array( 'host', 'user', 'folder' ) as $key ) {
			if ( $request->get_param( $key ) ) {
				$over[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) );
			}
		}
		if ( $request->get_param( 'port' ) ) {
			$over['port'] = (int) $request->get_param( 'port' );
		}
		if ( in_array( $request->get_param( 'encryption' ), array( 'ssl', 'tls', 'none' ), true ) ) {
			$over['encryption'] = (string) $request->get_param( 'encryption' );
		}
		$pass = (string) $request->get_param( 'password' );
		if ( '' !== $pass && false === strpos( $pass, '•' ) ) {
			$over['password'] = $pass;
		}
		return $this->respond( Poller::test( $over ) );
	}

	/**
	 * POST /mail/poll: queues a poll job now.
	 *
	 * @return \WP_REST_Response
	 */
	public function mail_poll() {
		if ( '' === (string) Settings::get( 'imap_host', '' ) ) {
			return $this->respond( new \WP_Error( 'dev3hd_imap_config', __( 'Add the IMAP host and user name first.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) ) );
		}
		return $this->respond( Poller::queue(), 201 );
	}

	/**
	 * POST /setup/pages: (re)creates the portal and help-centre pages.
	 *
	 * @return \WP_REST_Response
	 */
	public function setup_pages() {
		Portal::create_pages( true );
		return $this->respond( Portal::page_links() );
	}
}
