<?php
/**
 * IMAP polling through core Jobs: a cron tick (every 5 minutes) queues a poll job when the interval has
 * passed; "Check now" queues one from the admin and shows its progress.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Mail;

use Dev3\AiHelpdesk\Core3\Core\Jobs;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Poller.
 */
class Poller {

	const JOB = 'dev3hd_mail_poll';

	const LAST = 'dev3hd_last_poll';

	/**
	 * Test hook: a transport to inject into the client.
	 *
	 * @var Imap_Transport|null
	 */
	public static $transport = null;

	/**
	 * Registers the job type.
	 */
	public static function register() {
		Jobs::register( self::JOB, array( self::class, 'run_job' ) );
	}

	/**
	 * IMAP client from settings.
	 *
	 * @param array $over Overrides (test connection with unsaved values).
	 * @return Imap_Client
	 */
	public static function client( array $over = array() ) {
		return new Imap_Client(
			array_merge(
				array(
					'host'       => (string) Settings::get( 'imap_host', '' ),
					'port'       => (int) Settings::get( 'imap_port', 993 ),
					'encryption' => (string) Settings::get( 'imap_encryption', 'ssl' ),
					'user'       => (string) Settings::get( 'imap_user', '' ),
					'password'   => Settings::imap_password(),
					'folder'     => (string) Settings::get( 'imap_folder', 'INBOX' ),
				),
				$over
			),
			self::$transport
		);
	}

	/**
	 * Cron tick.
	 */
	public static function tick() {
		if ( ! Settings::get( 'imap_enabled', false ) ) {
			return;
		}
		$last = (array) get_option( self::LAST, array() );
		$due  = (int) ( $last['at'] ?? 0 ) + (int) Settings::get( 'imap_interval', 5 ) * MINUTE_IN_SECONDS - 30;
		if ( time() >= $due ) {
			self::queue();
		}
	}

	/**
	 * Queues a poll job (or returns the running one).
	 *
	 * @return array|\WP_Error Job.
	 */
	public static function queue() {
		$active = Jobs::active( self::JOB );
		if ( $active ) {
			return $active;
		}
		$id = Jobs::create( self::JOB, array( 'poll' ), array(), 1 );
		return is_wp_error( $id ) ? $id : Jobs::get( $id );
	}

	/**
	 * Job handler.
	 *
	 * @param array $items Items (one "poll").
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_job( array $items, array $job ) {
		unset( $items );
		$result = self::poll();
		if ( ! is_wp_error( $result ) && ! empty( $job['id'] ) ) {
			Jobs::set_result( (int) $job['id'], $result );
		}
		return array( 'poll' => is_wp_error( $result ) ? $result : true );
	}

	/**
	 * Fetches and processes unseen mail.
	 *
	 * @return array|\WP_Error { fetched, created, replied, ignored, reasons }
	 */
	public static function poll() {
		$client = self::client();
		$ok     = $client->connect();
		if ( is_wp_error( $ok ) ) {
			self::record( array( 'error' => $ok->get_error_message() ) );
			return $ok;
		}
		$uids = $client->unseen( (int) Settings::get( 'imap_max', 20 ) );
		if ( is_wp_error( $uids ) ) {
			$client->close();
			self::record( array( 'error' => $uids->get_error_message() ) );
			return $uids;
		}
		$out = array(
			'fetched' => 0,
			'created' => 0,
			'replied' => 0,
			'ignored' => 0,
			'reasons' => array(),
		);
		foreach ( $uids as $uid ) {
			$raw = $client->fetch( $uid );
			if ( is_wp_error( $raw ) || '' === $raw ) {
				continue;
			}
			++$out['fetched'];
			$res = Inbound::process( Mime_Parser::parse( $raw ), 'email' );
			++$out[ $res['action'] ];
			if ( 'ignored' === $res['action'] ) {
				$out['reasons'][] = $res['reason'];
			}
			$client->mark_seen( $uid );
		}
		$client->close();
		self::record( $out );
		return $out;
	}

	/**
	 * Stores the last poll summary.
	 *
	 * @param array $data Data.
	 */
	private static function record( array $data ) {
		update_option( self::LAST, array_merge( array( 'at' => time() ), $data ), false );
	}

	/**
	 * Connection test (login + select, then logout).
	 *
	 * @param array $over Unsaved values.
	 * @return array|\WP_Error { ok, unseen }
	 */
	public static function test( array $over = array() ) {
		$client = self::client( $over );
		$ok     = $client->connect();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$uids = $client->unseen( 100 );
		$client->close();
		return array(
			'ok'     => true,
			'unseen' => is_wp_error( $uids ) ? 0 : count( $uids ),
		);
	}
}
