<?php
/**
 * Ticket repository: CRUD, numbering, inbox queries, counts and visibility.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Data;

use Dev3\AiHelpdesk\Roles;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tickets.
 */
class Tickets {

	const STATUSES = array( 'new', 'open', 'pending', 'on_hold', 'resolved', 'closed' );

	const ACTIVE = array( 'new', 'open', 'pending', 'on_hold' );

	const CHANNELS = array( 'portal', 'form', 'email', 'webhook', 'agent' );

	const SENTIMENTS = array( 'positive', 'neutral', 'negative', 'angry' );

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		/**
		 * Filters ticket status labels (keys are fixed).
		 *
		 * @param array $labels Labels.
		 */
		return (array) apply_filters(
			'dev3hd_statuses',
			array(
				'new'      => __( 'New', 'dev3-ai-helpdesk' ),
				'open'     => __( 'Open', 'dev3-ai-helpdesk' ),
				'pending'  => __( 'Waiting on customer', 'dev3-ai-helpdesk' ),
				'on_hold'  => __( 'On hold', 'dev3-ai-helpdesk' ),
				'resolved' => __( 'Resolved', 'dev3-ai-helpdesk' ),
				'closed'   => __( 'Closed', 'dev3-ai-helpdesk' ),
			)
		);
	}

	/**
	 * Priority labels.
	 *
	 * @return array
	 */
	public static function priority_labels() {
		/**
		 * Filters ticket priority labels (keys are fixed).
		 *
		 * @param array $labels Labels.
		 */
		return (array) apply_filters(
			'dev3hd_priorities',
			array(
				'low'    => __( 'Low', 'dev3-ai-helpdesk' ),
				'normal' => __( 'Normal', 'dev3-ai-helpdesk' ),
				'high'   => __( 'High', 'dev3-ai-helpdesk' ),
				'urgent' => __( 'Urgent', 'dev3-ai-helpdesk' ),
			)
		);
	}

	/**
	 * Channel labels.
	 *
	 * @return array
	 */
	public static function channel_labels() {
		return array(
			'portal'  => __( 'Portal', 'dev3-ai-helpdesk' ),
			'form'    => __( 'Form', 'dev3-ai-helpdesk' ),
			'email'   => __( 'Email', 'dev3-ai-helpdesk' ),
			'webhook' => __( 'Email (webhook)', 'dev3-ai-helpdesk' ),
			'agent'   => __( 'Agent', 'dev3-ai-helpdesk' ),
		);
	}

	/**
	 * Current GMT time in MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Inserts a ticket (number assigned here).
	 *
	 * @param array $data Columns.
	 * @return int|\WP_Error
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$table = Tables::name( 'tickets' );
		$now   = self::now();
		$row   = array_merge(
			array(
				'subject'        => '',
				'status'         => 'new',
				'priority'       => 'normal',
				'department'     => '',
				'category'       => '',
				'agent_id'       => 0,
				'customer_id'    => 0,
				'email'          => '',
				'name'           => '',
				'channel'        => 'portal',
				'access_key'     => wp_generate_password( 32, false, false ),
				'order_id'       => 0,
				'tags'           => '',
				'sentiment'      => '',
				'ai'             => '{}',
				'ai_state'       => 'none',
				'rating_comment' => '',
				'message_id'     => '',
				'last_reply_at'  => $now,
				'last_reply_by'  => 'customer',
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			$data
		);
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max           = (int) $wpdb->get_var( "SELECT MAX(number) FROM {$table}" );
			$row['number'] = max( $max + 1, (int) Settings::get( 'number_start', 1001 ) );
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				return (int) $wpdb->insert_id;
			}
		}
		return new \WP_Error( 'dev3hd_db', __( 'The ticket could not be saved.', 'dev3-ai-helpdesk' ), array( 'status' => 500 ) );
	}

	/**
	 * Decodes a row.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'id', 'number', 'agent_id', 'customer_id', 'order_id', 'rating', 'sla_breached' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$ai        = json_decode( (string) $row['ai'], true );
		$row['ai'] = is_array( $ai ) ? $ai : array();
		return $row;
	}

	/**
	 * Ticket by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ) );
	}

	/**
	 * Ticket by public number.
	 *
	 * @param int $number Number.
	 * @return array|null
	 */
	public static function get_by_number( $number ) {
		global $wpdb;
		$table = Tables::name( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE number = %d", (int) $number ), ARRAY_A ) );
	}

	/**
	 * Updates columns (ai arrays are JSON-encoded).
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		if ( isset( $data['ai'] ) && is_array( $data['ai'] ) ) {
			$data['ai'] = wp_json_encode( $data['ai'] );
		}
		$data['updated_at'] = self::now();
		return false !== $wpdb->update( Tables::name( 'tickets' ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Merges keys into the ai JSON.
	 *
	 * @param int   $id    Id.
	 * @param array $patch Keys.
	 * @param array $extra Extra columns.
	 */
	public static function update_ai( $id, array $patch, array $extra = array() ) {
		$ticket = self::get( $id );
		if ( ! $ticket ) {
			return;
		}
		self::update( $id, array_merge( $extra, array( 'ai' => array_merge( $ticket['ai'], $patch ) ) ) );
	}

	/**
	 * Deletes a ticket, its replies and attachments.
	 *
	 * @param int $id Id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		foreach ( Replies::for_ticket( $id, true ) as $reply ) {
			foreach ( $reply['attachments'] as $file ) {
				\Dev3\AiHelpdesk\Helpdesk\Files::delete( $file['key'] ?? '' );
			}
		}
		$wpdb->delete( Tables::name( 'replies' ), array( 'ticket_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Tables::name( 'tickets' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * SQL visibility clause for an agent (empty for managers / see-all).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function visibility_sql( $user_id ) {
		global $wpdb;
		if ( Roles::is_manager( $user_id ) || Settings::get( 'agents_see_all', false ) ) {
			return '';
		}
		$depts = Roles::departments_of( $user_id );
		$parts = array( $wpdb->prepare( 'agent_id = %d', (int) $user_id ) );
		if ( $depts ) {
			$parts[] = 'department IN (' . implode( ',', array_map( static fn( $d ) => $wpdb->prepare( '%s', $d ), $depts ) ) . ')';
		} else {
			$parts[] = 'agent_id = 0';
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * Whether a user (agent) may see a ticket.
	 *
	 * @param array $ticket  Ticket.
	 * @param int   $user_id User id.
	 * @return bool
	 */
	public static function agent_can_view( array $ticket, $user_id ) {
		if ( ! Roles::is_agent( $user_id ) ) {
			return false;
		}
		if ( Roles::is_manager( $user_id ) || Settings::get( 'agents_see_all', false ) || (int) $ticket['agent_id'] === (int) $user_id ) {
			return true;
		}
		$depts = Roles::departments_of( $user_id );
		if ( $depts ) {
			return in_array( (string) $ticket['department'], $depts, true );
		}
		return 0 === (int) $ticket['agent_id'];
	}

	/**
	 * WHERE clause for filters.
	 *
	 * @param array $args    Filters.
	 * @param int   $user_id Viewer (0 = no visibility restriction).
	 * @param bool  $status  Include the status filter.
	 * @return string
	 */
	private static function where( array $args, $user_id, $status = true ) {
		global $wpdb;
		$where = array( '1=1' );
		if ( $user_id ) {
			$vis = self::visibility_sql( $user_id );
			if ( $vis ) {
				$where[] = $vis;
			}
		}
		$st = $status ? (string) ( $args['status'] ?? '' ) : '';
		if ( 'active' === $st ) {
			$where[] = "status IN ('new','open','pending','on_hold')";
		} elseif ( in_array( $st, self::STATUSES, true ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $st );
		}
		$view = (string) ( $args['view'] ?? '' );
		if ( 'mine' === $view ) {
			$where[] = $wpdb->prepare( 'agent_id = %d', get_current_user_id() );
		} elseif ( 'unassigned' === $view ) {
			$where[] = 'agent_id = 0';
		} elseif ( 'overdue' === $view ) {
			$now     = self::now();
			$where[] = "status IN ('new','open','on_hold')";
			$where[] = $wpdb->prepare( '((first_response_at IS NULL AND sla_first_due IS NOT NULL AND sla_first_due < %s) OR (sla_resolve_due IS NOT NULL AND sla_resolve_due < %s))', $now, $now );
		}
		foreach ( array( 'department', 'category', 'priority', 'channel' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[] = $wpdb->prepare( "{$col} = %s", (string) $args[ $col ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		if ( isset( $args['agent'] ) && '' !== (string) $args['agent'] ) {
			$where[] = $wpdb->prepare( 'agent_id = %d', (int) $args['agent'] );
		}
		if ( ! empty( $args['customer_id'] ) || ! empty( $args['email'] ) ) {
			$who = array();
			if ( ! empty( $args['customer_id'] ) ) {
				$who[] = $wpdb->prepare( 'customer_id = %d', (int) $args['customer_id'] );
			}
			if ( ! empty( $args['email'] ) ) {
				$who[] = $wpdb->prepare( 'email = %s', (string) $args['email'] );
			}
			$where[] = '(' . implode( ' OR ', $who ) . ')';
		}
		if ( ! empty( $args['exclude'] ) ) {
			$where[] = $wpdb->prepare( 'id <> %d', (int) $args['exclude'] );
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$replies = Tables::name( 'replies' );
			$number  = (int) ltrim( $search, '#' );
			$where[] = $wpdb->prepare( "(subject LIKE %s OR email LIKE %s OR name LIKE %s OR number = %d OR id IN (SELECT ticket_id FROM {$replies} WHERE body LIKE %s))", $like, $like, $like, $number, $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return implode( ' AND ', $where );
	}

	/**
	 * Inbox query.
	 *
	 * @param array $args    Filters + page, per_page, orderby (updated|created|priority|number), order.
	 * @param int   $user_id Viewer for visibility (0 = everything).
	 * @return array { rows, total }
	 */
	public static function query( array $args, $user_id = 0 ) {
		global $wpdb;
		$table    = Tables::name( 'tickets' );
		$where    = self::where( $args, $user_id );
		$per_page = min( 200, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$orders   = array(
			'updated'  => 'updated_at',
			'created'  => 'created_at',
			'number'   => 'number',
			'priority' => "FIELD(priority,'urgent','high','normal','low')",
			'due'      => 'COALESCE(sla_first_due, sla_resolve_due)',
		);
		$orderby  = $orders[ (string) ( $args['orderby'] ?? 'updated' ) ] ?? 'updated_at';
		$dir      = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';
		if ( 'priority' === ( $args['orderby'] ?? '' ) ) {
			$dir = 'asc' === strtolower( (string) ( $args['order'] ?? 'asc' ) ) ? 'ASC' : 'DESC';
		}
		$offset = ( $page - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$dir}, id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => array_map( array( self::class, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Counts per status (+ active, mine, unassigned, overdue) for the given filters.
	 *
	 * @param array $args    Filters (status ignored).
	 * @param int   $user_id Viewer.
	 * @return array
	 */
	public static function counts( array $args, $user_id = 0 ) {
		global $wpdb;
		$table = Tables::name( 'tickets' );
		unset( $args['view'] );
		$where = self::where( $args, $user_id, false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY status", ARRAY_A );
		$out  = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		$out['active'] = $out['new'] + $out['open'] + $out['pending'] + $out['on_hold'];
		$out['all']    = array_sum( array_intersect_key( $out, array_flip( self::STATUSES ) ) );
		foreach ( array( 'mine', 'unassigned', 'overdue' ) as $view ) {
			$w            = self::where( array_merge( $args, array( 'view' => $view ) ), $user_id, false );
			$active       = 'overdue' === $view ? '' : " AND status IN ('new','open','pending','on_hold')";
			$out[ $view ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$w}{$active}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $out;
	}

	/**
	 * Tickets of a customer (portal).
	 *
	 * @param int    $user_id User id.
	 * @param string $email   Email.
	 * @param int    $limit   Limit.
	 * @return array
	 */
	public static function for_customer( $user_id, $email, $limit = 50 ) {
		return self::query(
			array(
				'customer_id' => (int) $user_id,
				'email'       => (string) $email,
				'per_page'    => $limit,
			)
		)['rows'];
	}

	/**
	 * Finds a ticket number in a subject ("[#1001]", "Ticket #1001").
	 *
	 * @param string $subject Subject.
	 * @return int
	 */
	public static function number_from_subject( $subject ) {
		if ( preg_match( '/\[(?:[^\]#]*?)#(\d{1,12})\]/', (string) $subject, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}
}
