<?php
/**
 * SLA maths: calendar vs business hours (days, opening hours, weekends, time zone), dues per priority,
 * state for the UI.
 *
 * @package Dev3\AiHelpdesk
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiHelpdesk\Helpdesk\Sla;

class SlaTest extends Dev3hd_Base_Case {

	/**
	 * Business-hour opts (Mon–Fri 9–17, UTC).
	 *
	 * @return array
	 */
	private function bh() {
		return array(
			'business' => true,
			'days'     => array( 1, 2, 3, 4, 5 ),
			'start'    => 9,
			'end'      => 17,
			'tz'       => new DateTimeZone( 'UTC' ),
		);
	}

	public function test_calendar_hours() {
		$start = strtotime( '2026-10-23 16:00:00 UTC' );
		$this->assertSame( $start + 2 * HOUR_IN_SECONDS, Sla::add_hours( $start, 2, array( 'business' => false ) ) );
	}

	public function test_business_hours_skip_nights_and_weekends() {
		// Friday 16:00 + 2 h = Monday 10:00.
		$this->assertSame( strtotime( '2026-10-26 10:00:00 UTC' ), Sla::add_hours( strtotime( '2026-10-23 16:00:00 UTC' ), 2, $this->bh() ) );
		// Saturday 12:00 + 1 h = Monday 10:00.
		$this->assertSame( strtotime( '2026-10-26 10:00:00 UTC' ), Sla::add_hours( strtotime( '2026-10-24 12:00:00 UTC' ), 1, $this->bh() ) );
		// Tuesday 07:00 + 8 h = Tuesday 17:00 (exactly one working day).
		$this->assertSame( strtotime( '2026-10-27 17:00:00 UTC' ), Sla::add_hours( strtotime( '2026-10-27 07:00:00 UTC' ), 8, $this->bh() ) );
		// 24 working hours from Monday 09:00 = Wednesday 17:00.
		$this->assertSame( strtotime( '2026-10-28 17:00:00 UTC' ), Sla::add_hours( strtotime( '2026-10-26 09:00:00 UTC' ), 24, $this->bh() ) );
	}

	public function test_business_hours_follow_the_site_time_zone() {
		$opts       = $this->bh();
		$opts['tz'] = new DateTimeZone( 'Asia/Dubai' ); // UTC+4.
		// Monday 04:00 UTC = 08:00 Dubai → opens 09:00 Dubai (05:00 UTC); +1 h = 06:00 UTC.
		$this->assertSame( strtotime( '2026-10-26 06:00:00 UTC' ), Sla::add_hours( strtotime( '2026-10-26 04:00:00 UTC' ), 1, $opts ) );
	}

	public function test_dues_use_priority_targets_and_can_be_off() {
		$this->settings(
			array(
				'sla' => array(
					'urgent' => array(
						'first'   => 1,
						'resolve' => 0,
					),
				),
			)
		);
		$now  = time();
		$dues = Sla::dues( 'urgent', $now );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS ), $dues['sla_first_due'] );
		$this->assertNull( $dues['sla_resolve_due'], '0 = no target' );
		$this->settings( array( 'sla_enabled' => false ) );
		$this->assertNull( Sla::dues( 'urgent', $now )['sla_first_due'] );
	}

	public function test_state_reports_overdue_met_and_paused() {
		$now    = strtotime( '2026-10-26 12:00:00 UTC' );
		$ticket = array(
			'status'            => 'open',
			'sla_first_due'     => '2026-10-26 11:00:00',
			'first_response_at' => null,
			'sla_resolve_due'   => '2026-10-27 12:00:00',
			'resolved_at'       => null,
			'sla_paused_at'     => null,
		);
		$s      = Sla::state( $ticket, $now );
		$this->assertTrue( $s['first']['overdue'] );
		$this->assertSame( DAY_IN_SECONDS, $s['resolve']['left'] );
		$ticket['first_response_at'] = '2026-10-26 10:30:00';
		$ticket['sla_paused_at']     = '2026-10-26 11:30:00';
		$s                           = Sla::state( $ticket, $now );
		$this->assertTrue( $s['first']['met'] );
		$this->assertTrue( $s['paused'] );
		$this->assertFalse( $s['resolve']['overdue'] );
	}
}
