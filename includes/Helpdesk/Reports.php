<?php
/**
 * Dashboard numbers and reports (response times, SLA compliance, CSAT, breakdowns).
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Helpdesk;

use Dev3\AiHelpdesk\Data\Tables;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Roles;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reports.
 */
class Reports {

	/**
	 * Visibility clause for a viewer.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function vis( $user_id ) {
		$sql = $user_id ? Tickets::visibility_sql( $user_id ) : '';
		return '' === $sql ? '1=1' : $sql;
	}

	/**
	 * Daily created/resolved series.
	 *
	 * @param int    $days Days.
	 * @param string $vis  Visibility SQL.
	 * @return array [ { date, created, resolved } ]
	 */
	private static function trend( $days, $vis ) {
		global $wpdb;
		$table = Tables::name( 'tickets' );
		$tz    = wp_timezone();
		$from  = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 )->modify( '-' . ( $days - 1 ) . ' days' );
		$since = gmdate( 'Y-m-d H:i:s', $from->getTimestamp() );
		$off   = (int) $tz->getOffset( new \DateTime( 'now', $tz ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$created  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS d, COUNT(*) AS n FROM {$table} WHERE {$vis} AND created_at >= %s GROUP BY d", $off, $since ), ARRAY_A );
		$resolved = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(DATE_ADD(resolved_at, INTERVAL %d SECOND)) AS d, COUNT(*) AS n FROM {$table} WHERE {$vis} AND resolved_at IS NOT NULL AND resolved_at >= %s GROUP BY d", $off, $since ), ARRAY_A );
		// phpcs:enable
		$c   = wp_list_pluck( (array) $created, 'n', 'd' );
		$r   = wp_list_pluck( (array) $resolved, 'n', 'd' );
		$out = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$day   = $from->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$out[] = array(
				'date'     => $day,
				'label'    => wp_date( 'M j', $from->modify( '+' . $i . ' days' )->getTimestamp() ),
				'created'  => (int) ( $c[ $day ] ?? 0 ),
				'resolved' => (int) ( $r[ $day ] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Aggregates for a period.
	 *
	 * @param string $since GMT start.
	 * @param string $vis   Visibility SQL.
	 * @return array
	 */
	private static function aggregates( $since, $vis ) {
		global $wpdb;
		$table = Tables::name( 'tickets' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS created,
					SUM(resolved_at IS NOT NULL) AS resolved,
					AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, first_response_at) END) AS first_avg,
					AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, resolved_at) END) AS resolve_avg,
					SUM(sla_first_due IS NOT NULL AND (first_response_at IS NOT NULL OR sla_first_due < UTC_TIMESTAMP())) AS sla_due,
					SUM(sla_first_due IS NOT NULL AND first_response_at IS NOT NULL AND first_response_at <= sla_first_due) AS sla_met,
					SUM(rating > 0) AS rated,
					SUM(rating = 3) AS great,
					SUM(rating = 1) AS bad
				FROM {$table} WHERE {$vis} AND created_at >= %s",
				$since
			),
			ARRAY_A
		);
		// phpcs:enable
		$rated = (int) $row['rated'];
		$due   = (int) $row['sla_due'];
		return array(
			'created'         => (int) $row['created'],
			'resolved'        => (int) $row['resolved'],
			'first_response'  => null === $row['first_avg'] ? null : (int) round( (float) $row['first_avg'] ),
			'resolution_time' => null === $row['resolve_avg'] ? null : (int) round( (float) $row['resolve_avg'] ),
			'sla_compliance'  => $due > 0 ? (int) round( 100 * (int) $row['sla_met'] / $due ) : null,
			'csat'            => $rated > 0 ? (int) round( 100 * (int) $row['great'] / $rated ) : null,
			'ratings'         => $rated,
			'ratings_bad'     => (int) $row['bad'],
		);
	}

	/**
	 * Dashboard data.
	 *
	 * @param int $user_id Viewer.
	 * @return array
	 */
	public static function dashboard( $user_id ) {
		global $wpdb;
		$table  = Tables::name( 'tickets' );
		$vis    = self::vis( $user_id );
		$counts = Tickets::counts( array(), $user_id );
		$agg    = self::aggregates( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), $vis );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$week   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$vis} AND resolved_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		$by_cat = $wpdb->get_results( "SELECT category, COUNT(*) AS n FROM {$table} WHERE {$vis} AND status IN ('new','open','pending','on_hold') GROUP BY category ORDER BY n DESC", ARRAY_A );
		$now    = Tickets::now();
		$urgent = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$vis} AND status IN ('new','open','on_hold') AND ( priority IN ('urgent','high') OR (first_response_at IS NULL AND sla_first_due < %s) OR sla_resolve_due < %s ) ORDER BY FIELD(priority,'urgent','high','normal','low'), COALESCE(sla_first_due, sla_resolve_due) ASC LIMIT 6", $now, $now ), ARRAY_A );
		// phpcs:enable
		$cats = array();
		foreach ( (array) $by_cat as $row ) {
			$cats[] = array(
				'id'    => (string) $row['category'],
				'label' => '' === $row['category'] ? __( 'Uncategorised', 'dev3-ai-helpdesk' ) : ( Settings::category_name( $row['category'] ) ? Settings::category_name( $row['category'] ) : $row['category'] ),
				'count' => (int) $row['n'],
			);
		}
		return array(
			'counts'    => $counts,
			'period'    => $agg,
			'resolved7' => $week,
			'trend'     => self::trend( 14, $vis ),
			'by_cat'    => $cats,
			'attention' => array_map( array( '\Dev3\AiHelpdesk\Rest\Presenter', 'row' ), array_map( array( self::class, 'hydrate' ), (array) $urgent ) ),
			'setup'     => Roles::is_manager( $user_id ) ? self::setup() : null,
		);
	}

	/**
	 * Raw row to ticket.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function hydrate( array $row ) {
		return Tickets::get( (int) $row['id'] );
	}

	/**
	 * Setup checklist.
	 *
	 * @return array
	 */
	public static function setup() {
		$pages  = \Dev3\AiHelpdesk\Frontend\Portal::page_links();
		$status = \Dev3\AiHelpdesk\Core3\Api\Ai::status();
		return array(
			array(
				'id'   => 'pages',
				'done' => ! empty( $pages['portal'] ) && ! empty( $pages['help'] ),
			),
			array(
				'id'   => 'mail',
				'done' => (bool) ( Settings::get( 'imap_enabled', false ) || Settings::get( 'webhook_enabled', false ) ),
			),
			array(
				'id'   => 'kb',
				'done' => \Dev3\AiHelpdesk\Kb\Kb::count() > 0,
			),
			array(
				'id'   => 'agents',
				'done' => count( Roles::agents() ) > 1,
			),
			array(
				'id'   => 'ai',
				'done' => (bool) $status['key_set'],
			),
		);
	}

	/**
	 * Report for a period.
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function report( $days ) {
		global $wpdb;
		$days  = max( 7, min( 365, (int) $days ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table = Tables::name( 'tickets' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$agents   = $wpdb->get_results( $wpdb->prepare( "SELECT agent_id, COUNT(*) AS assigned, SUM(resolved_at IS NOT NULL) AS resolved, AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, first_response_at) END) AS first_avg, SUM(rating > 0) AS rated, SUM(rating = 3) AS great FROM {$table} WHERE created_at >= %s GROUP BY agent_id ORDER BY assigned DESC", $since ), ARRAY_A );
		$cats     = $wpdb->get_results( $wpdb->prepare( "SELECT category AS k, COUNT(*) AS n, SUM(resolved_at IS NOT NULL) AS resolved FROM {$table} WHERE created_at >= %s GROUP BY category ORDER BY n DESC", $since ), ARRAY_A );
		$channels = $wpdb->get_results( $wpdb->prepare( "SELECT channel AS k, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY channel ORDER BY n DESC", $since ), ARRAY_A );
		$prio     = $wpdb->get_results( $wpdb->prepare( "SELECT priority AS k, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY priority", $since ), ARRAY_A );
		// phpcs:enable
		$by_agent = array();
		foreach ( (array) $agents as $row ) {
			$user       = $row['agent_id'] ? get_userdata( (int) $row['agent_id'] ) : null;
			$rated      = (int) $row['rated'];
			$by_agent[] = array(
				'id'             => (int) $row['agent_id'],
				'name'           => $row['agent_id'] ? ( $user ? $user->display_name : '#' . $row['agent_id'] ) : __( 'Unassigned', 'dev3-ai-helpdesk' ),
				'assigned'       => (int) $row['assigned'],
				'resolved'       => (int) $row['resolved'],
				'first_response' => null === $row['first_avg'] ? null : (int) round( (float) $row['first_avg'] ),
				'csat'           => $rated ? (int) round( 100 * (int) $row['great'] / $rated ) : null,
			);
		}
		$labels  = Tickets::channel_labels();
		$plabels = Tickets::priority_labels();
		$by_cat  = array_map(
			static fn( $r ) => array(
				'label'    => '' === $r['k'] ? __( 'Uncategorised', 'dev3-ai-helpdesk' ) : ( Settings::category_name( $r['k'] ) ? Settings::category_name( $r['k'] ) : $r['k'] ),
				'count'    => (int) $r['n'],
				'resolved' => (int) $r['resolved'],
			),
			(array) $cats
		);
		$by_chan = array_map(
			static fn( $r ) => array(
				'label' => $labels[ $r['k'] ] ?? $r['k'],
				'count' => (int) $r['n'],
			),
			(array) $channels
		);
		$by_prio = array_map(
			static fn( $r ) => array(
				'label' => $plabels[ $r['k'] ] ?? $r['k'],
				'count' => (int) $r['n'],
			),
			(array) $prio
		);
		return array(
			'days'        => $days,
			'totals'      => self::aggregates( $since, '1=1' ),
			'trend'       => self::trend( min( $days, 60 ), '1=1' ),
			'by_agent'    => $by_agent,
			'by_category' => $by_cat,
			'by_channel'  => $by_chan,
			'by_priority' => $by_prio,
		);
	}

	/**
	 * CSV rows for a report period (one row per ticket).
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function csv_rows( $days ) {
		global $wpdb;
		$table = Tables::name( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s ORDER BY id ASC LIMIT 20000", gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS ) ), ARRAY_A );
		$out  = array( array( 'number', 'subject', 'status', 'priority', 'category', 'department', 'agent', 'customer', 'email', 'channel', 'created_at', 'first_response_at', 'resolved_at', 'rating' ) );
		foreach ( (array) $rows as $row ) {
			$agent = $row['agent_id'] ? get_userdata( (int) $row['agent_id'] ) : null;
			$out[] = array( $row['number'], $row['subject'], $row['status'], $row['priority'], $row['category'], $row['department'], $agent ? $agent->display_name : '', $row['name'], $row['email'], $row['channel'], $row['created_at'], $row['first_response_at'], $row['resolved_at'], $row['rating'] );
		}
		return $out;
	}
}
