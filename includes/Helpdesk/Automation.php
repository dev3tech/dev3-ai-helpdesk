<?php
/**
 * Scheduled work: SLA breach checks and auto-close (hourly), mail polling ticks (every 5 minutes).
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Helpdesk;

use Dev3\AiHelpdesk\Data\Tables;
use Dev3\AiHelpdesk\Mail\Poller;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Automation.
 */
class Automation {

	const HOURLY = 'dev3hd_hourly';

	const MAIL_TICK = 'dev3hd_mail_tick';

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5-minute mail polling is opt-in.
		add_action( self::HOURLY, array( self::class, 'hourly' ) );
		add_action( self::MAIL_TICK, array( Poller::class, 'tick' ) );
		add_action( 'init', array( self::class, 'ensure_scheduled' ) );
	}

	/**
	 * Adds the 5-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['dev3hd_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every 5 minutes (DEV3 AI Helpdesk)',
		);
		return $schedules;
	}

	/**
	 * Schedules events (activation).
	 */
	public static function schedule() {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::MAIL_TICK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'dev3hd_five_minutes', self::MAIL_TICK );
		}
	}

	/**
	 * Re-creates missing events (e.g. after a migration).
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOURLY ) || ! wp_next_scheduled( self::MAIL_TICK ) ) {
			self::schedule();
		}
	}

	/**
	 * Clears events (deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOURLY );
		wp_clear_scheduled_hook( self::MAIL_TICK );
		wp_unschedule_hook( 'dev3hd_run_job' );
	}

	/**
	 * Hourly run.
	 */
	public static function hourly() {
		Sla::check_breaches();
		self::auto_close();
	}

	/**
	 * Closes resolved tickets with no customer reply for N days.
	 *
	 * @param int|null $now Now (tests).
	 * @return int Closed count.
	 */
	public static function auto_close( $now = null ) {
		global $wpdb;
		$days = (int) Settings::get( 'auto_close_days', 7 );
		if ( $days <= 0 ) {
			return 0;
		}
		$now    = null === $now ? time() : (int) $now;
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
		$table  = Tables::name( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'resolved' AND resolved_at IS NOT NULL AND resolved_at < %s LIMIT 200", $cutoff ) );
		foreach ( (array) $ids as $id ) {
			Service::set_status( (int) $id, 'closed' );
		}
		return count( (array) $ids );
	}
}
