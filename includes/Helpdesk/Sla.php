<?php
/**
 * SLA targets per priority, with optional business hours (site time zone) and a clock that pauses while
 * the ticket waits on the customer.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Helpdesk;

use Dev3\AiHelpdesk\Data\Tables;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SLA.
 */
class Sla {

	const FIRST = 1;

	const RESOLVE = 2;

	/**
	 * Adds working hours to a timestamp.
	 *
	 * @param int   $start Unix time.
	 * @param float $hours Hours.
	 * @param array $opts  Overrides: business (bool), days (int[] 0=Sun), start (hour), end (hour), tz (DateTimeZone).
	 * @return int
	 */
	public static function add_hours( $start, $hours, array $opts = array() ) {
		$seconds  = (int) round( (float) $hours * HOUR_IN_SECONDS );
		$business = $opts['business'] ?? (bool) Settings::get( 'business_hours', false );
		if ( ! $business || $seconds <= 0 ) {
			return (int) $start + max( 0, $seconds );
		}
		$days = array_map( 'intval', (array) ( $opts['days'] ?? Settings::get( 'bh_days', array( 1, 2, 3, 4, 5 ) ) ) );
		$from = (int) ( $opts['start'] ?? Settings::get( 'bh_start', 9 ) );
		$to   = (int) ( $opts['end'] ?? Settings::get( 'bh_end', 17 ) );
		$tz   = $opts['tz'] ?? wp_timezone();
		if ( ! $days || $to <= $from ) {
			return (int) $start + $seconds;
		}
		$t = (int) $start;
		for ( $i = 0; $i < 800; $i++ ) {
			$local    = ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( $tz );
			$midnight = $local->setTime( 0, 0, 0 );
			$open     = $midnight->getTimestamp() + $from * HOUR_IN_SECONDS;
			$close    = $midnight->getTimestamp() + $to * HOUR_IN_SECONDS;
			if ( in_array( (int) $local->format( 'w' ), $days, true ) ) {
				if ( $t < $open ) {
					$t = $open;
				}
				if ( $t < $close ) {
					$avail = $close - $t;
					if ( $seconds <= $avail ) {
						return $t + $seconds;
					}
					$seconds -= $avail;
				}
			}
			$t = $midnight->modify( '+1 day' )->getTimestamp();
		}
		return $t + $seconds;
	}

	/**
	 * Targets in hours for a priority.
	 *
	 * @param string $priority Priority.
	 * @return array { first, resolve }
	 */
	public static function targets( $priority ) {
		$sla = (array) Settings::get( 'sla', array() );
		$row = (array) ( $sla[ $priority ] ?? $sla['normal'] ?? array() );
		return array(
			'first'   => (float) ( $row['first'] ?? 0 ),
			'resolve' => (float) ( $row['resolve'] ?? 0 ),
		);
	}

	/**
	 * Due dates (GMT MySQL) for a priority from a start time.
	 *
	 * @param string $priority Priority.
	 * @param int    $from     Unix time.
	 * @return array { sla_first_due, sla_resolve_due } (null when off)
	 */
	public static function dues( $priority, $from ) {
		if ( ! Settings::get( 'sla_enabled', true ) ) {
			return array(
				'sla_first_due'   => null,
				'sla_resolve_due' => null,
			);
		}
		$t = self::targets( $priority );
		return array(
			'sla_first_due'   => $t['first'] > 0 ? gmdate( 'Y-m-d H:i:s', self::add_hours( $from, $t['first'] ) ) : null,
			'sla_resolve_due' => $t['resolve'] > 0 ? gmdate( 'Y-m-d H:i:s', self::add_hours( $from, $t['resolve'] ) ) : null,
		);
	}

	/**
	 * Columns to pause the clock (status → pending).
	 *
	 * @param array $ticket Ticket.
	 * @return array
	 */
	public static function pause( array $ticket ) {
		return empty( $ticket['sla_paused_at'] ) ? array( 'sla_paused_at' => Tickets::now() ) : array();
	}

	/**
	 * Columns to resume the clock: due dates move forward by the paused time.
	 *
	 * @param array $ticket Ticket.
	 * @return array
	 */
	public static function resume( array $ticket ) {
		if ( empty( $ticket['sla_paused_at'] ) ) {
			return array();
		}
		$paused = max( 0, time() - (int) strtotime( $ticket['sla_paused_at'] . ' UTC' ) );
		$out    = array( 'sla_paused_at' => null );
		if ( ! empty( $ticket['sla_resolve_due'] ) ) {
			$out['sla_resolve_due'] = gmdate( 'Y-m-d H:i:s', (int) strtotime( $ticket['sla_resolve_due'] . ' UTC' ) + $paused );
		}
		if ( ! empty( $ticket['sla_first_due'] ) && empty( $ticket['first_response_at'] ) ) {
			$out['sla_first_due'] = gmdate( 'Y-m-d H:i:s', (int) strtotime( $ticket['sla_first_due'] . ' UTC' ) + $paused );
		}
		return $out;
	}

	/**
	 * SLA state for the UI.
	 *
	 * @param array    $ticket Ticket.
	 * @param int|null $now    Now (tests).
	 * @return array { first: {due, met, overdue, left}, resolve: {...}, paused }
	 */
	public static function state( array $ticket, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$done   = in_array( $ticket['status'], array( 'resolved', 'closed' ), true );
		$part   = static function ( $due, $at, $stopped ) use ( $now ) {
			if ( empty( $due ) ) {
				return null;
			}
			$due_ts = (int) strtotime( $due . ' UTC' );
			if ( ! empty( $at ) ) {
				return array(
					'due'     => $due,
					'met'     => (int) strtotime( $at . ' UTC' ) <= $due_ts,
					'overdue' => false,
					'left'    => 0,
				);
			}
			return array(
				'due'     => $due,
				'met'     => null,
				'overdue' => ! $stopped && $now > $due_ts,
				'left'    => $stopped ? 0 : $due_ts - $now,
			);
		};
		$paused = ! empty( $ticket['sla_paused_at'] );
		return array(
			'first'   => $part( $ticket['sla_first_due'] ?? null, $ticket['first_response_at'] ?? null, $done ),
			'resolve' => $part( $ticket['sla_resolve_due'] ?? null, $ticket['resolved_at'] ?? null, $done || $paused ),
			'paused'  => $paused,
		);
	}

	/**
	 * Marks new breaches and fires `dev3hd_sla_breached` once per ticket and kind.
	 *
	 * @return int Number of new breaches.
	 */
	public static function check_breaches() {
		global $wpdb;
		$table = Tables::name( 'tickets' );
		$now   = Tickets::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, sla_breached, first_response_at, sla_first_due, sla_resolve_due, sla_paused_at FROM {$table} WHERE status IN ('new','open','on_hold') AND ( (first_response_at IS NULL AND sla_first_due IS NOT NULL AND sla_first_due < %s AND (sla_breached & 1) = 0) OR (sla_resolve_due IS NOT NULL AND sla_resolve_due < %s AND sla_paused_at IS NULL AND (sla_breached & 2) = 0) ) LIMIT 200", $now, $now ), ARRAY_A );
		$count = 0;
		foreach ( (array) $rows as $row ) {
			$bits  = (int) $row['sla_breached'];
			$kinds = array();
			if ( empty( $row['first_response_at'] ) && ! empty( $row['sla_first_due'] ) && $row['sla_first_due'] < $now && ! ( $bits & self::FIRST ) ) {
				$bits   |= self::FIRST;
				$kinds[] = 'first';
			}
			if ( ! empty( $row['sla_resolve_due'] ) && $row['sla_resolve_due'] < $now && empty( $row['sla_paused_at'] ) && ! ( $bits & self::RESOLVE ) ) {
				$bits   |= self::RESOLVE;
				$kinds[] = 'resolve';
			}
			if ( ! $kinds ) {
				continue;
			}
			$wpdb->update( $table, array( 'sla_breached' => $bits ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $kinds as $kind ) {
				++$count;
				/**
				 * Fires once when a ticket breaches an SLA target.
				 *
				 * @param int    $ticket_id Ticket id.
				 * @param string $kind      first|resolve.
				 */
				do_action( 'dev3hd_sla_breached', (int) $row['id'], $kind );
			}
		}
		return $count;
	}
}
