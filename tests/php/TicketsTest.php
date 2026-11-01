<?php
/**
 * Ticket business logic: creation, numbering, routing, replies/notes/status flows, SLA, visibility,
 * search/counts, ratings, auto-close, canned replies, privacy.
 *
 * @package Dev3\AiHelpdesk
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiHelpdesk\Data\Canned;
use Dev3\AiHelpdesk\Data\Personal_Data;
use Dev3\AiHelpdesk\Data\Replies;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Helpdesk\Automation;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Helpdesk\Sla;

class TicketsTest extends Dev3hd_Base_Case {

	public function test_create_validates_and_numbers_from_the_start_setting() {
		$this->settings( array( 'number_start' => 5000 ) );
		$this->assertWPError( Service::create_ticket( array( 'subject' => 'x', 'message' => 'y', 'email' => 'nope' ) ) );
		$this->assertSame( 'dev3hd_subject', Service::create_ticket( array( 'subject' => '', 'message' => 'y', 'email' => 'a@example.com' ) )->get_error_code() );
		$this->assertSame( 'dev3hd_message', Service::create_ticket( array( 'subject' => 's', 'message' => '  ', 'email' => 'a@example.com' ) )->get_error_code() );
		$a = Tickets::get( $this->ticket() );
		$b = Tickets::get( $this->ticket( array( 'email' => 'GRACE@Example.com' ) ) );
		$this->assertSame( 5000, $a['number'] );
		$this->assertSame( 5001, $b['number'] );
		$this->assertSame( 'grace@example.com', $b['email'] );
		$this->assertSame( 32, strlen( $a['access_key'] ) );
		$this->assertNotSame( $a['access_key'], $b['access_key'] );
		$this->assertSame( 'new', $a['status'] );
	}

	public function test_category_routes_to_department_and_blocked_senders_are_refused() {
		$id = $this->ticket( array( 'category' => 'billing' ) );
		$t  = Tickets::get( $id );
		$this->assertSame( 'billing', $t['category'] );
		$this->assertSame( 'billing', $t['department'] );
		$bad = Tickets::get( $this->ticket( array( 'category' => 'nonsense' ) ) );
		$this->assertSame( '', $bad['category'] );
		$this->assertSame( 'support', $bad['department'], 'First department is the fallback' );
		$this->settings( array( 'block_list' => "@spam.test\nbad@example.com" ) );
		$this->assertSame( 'dev3hd_blocked', Service::create_ticket( array( 'subject' => 's', 'message' => 'm', 'email' => 'x@spam.test' ) )->get_error_code() );
		$this->assertSame( 'dev3hd_blocked', Service::create_ticket( array( 'subject' => 's', 'message' => 'm', 'email' => 'Bad@example.com' ) )->get_error_code() );
	}

	public function test_links_registered_customers_by_email() {
		$user = self::factory()->user->create( array( 'user_email' => 'grace@example.com', 'display_name' => 'Grace H' ) );
		$t    = Tickets::get( $this->ticket( array( 'name' => '' ) ) );
		$this->assertSame( $user, $t['customer_id'] );
		$this->assertSame( 'Grace H', $t['name'] );
	}

	public function test_round_robin_assignment_within_the_department() {
		$a = $this->agent( array( 'billing' ) );
		$b = $this->agent( array( 'billing' ) );
		$this->settings( array( 'auto_assign' => 'round_robin' ) );
		$first  = Tickets::get( $this->ticket( array( 'category' => 'billing' ) ) );
		$second = Tickets::get( $this->ticket( array( 'category' => 'billing' ) ) );
		$third  = Tickets::get( $this->ticket( array( 'category' => 'billing' ) ) );
		$this->assertSame( array( $a, $b, $a ), array( $first['agent_id'], $second['agent_id'], $third['agent_id'] ) );
		$other = Tickets::get( $this->ticket( array( 'category' => 'general' ) ) );
		$this->assertSame( 0, $other['agent_id'], 'No agents in Customer support' );
	}

	public function test_agent_reply_sets_first_response_takes_ticket_and_pauses_sla() {
		$agent = $this->agent();
		$id    = $this->ticket( array( 'priority' => 'high' ) );
		$rid   = Service::add_reply(
			$id,
			array(
				'author'  => 'agent',
				'user_id' => $agent,
				'body'    => 'Looking into it.',
				'status'  => 'pending',
			)
		);
		$this->assertIsInt( $rid );
		$t = Tickets::get( $id );
		$this->assertNotEmpty( $t['first_response_at'] );
		$this->assertSame( $agent, $t['agent_id'], 'First replier takes an unassigned ticket' );
		$this->assertSame( 'pending', $t['status'] );
		$this->assertSame( 'agent', $t['last_reply_by'] );
		$this->assertNotEmpty( $t['sla_paused_at'] );
		$state = Sla::state( $t );
		$this->assertTrue( $state['first']['met'] );
		$this->assertTrue( $state['paused'] );

		// Customer replies: back to open, the clock resumes and the resolve due moves forward.
		global $wpdb;
		$wpdb->update( Dev3\AiHelpdesk\Data\Tables::name( 'tickets' ), array( 'sla_paused_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'id' => $id ) ); // phpcs:ignore
		$before = Tickets::get( $id );
		Service::add_reply(
			$id,
			array(
				'author' => 'customer',
				'body'   => 'Any news?',
			)
		);
		$after = Tickets::get( $id );
		$this->assertSame( 'open', $after['status'] );
		$this->assertNull( $after['sla_paused_at'] );
		$this->assertEqualsWithDelta( strtotime( $before['sla_resolve_due'] ) + 3600, strtotime( $after['sla_resolve_due'] ), 5 );
	}

	public function test_notes_are_private_and_do_not_change_status() {
		$agent = $this->agent();
		$id    = $this->ticket();
		Service::add_reply(
			$id,
			array(
				'type'    => 'note',
				'author'  => 'agent',
				'user_id' => $agent,
				'body'    => 'Internal: VIP customer',
			)
		);
		$t = Tickets::get( $id );
		$this->assertSame( 'new', $t['status'] );
		$this->assertEmpty( $t['first_response_at'] );
		$public = Replies::for_ticket( $id );
		$this->assertCount( 1, $public, 'Only the customer message is public' );
		$this->assertNotContains( 'Internal: VIP customer', wp_list_pluck( $public, 'body' ) );
		$this->assertCount( 0, array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['message'], 'VIP' ) ), 'Notes are never emailed' );
	}

	public function test_customer_reply_reopens_resolved_tickets_unless_disabled() {
		$id = $this->ticket();
		Service::set_status( $id, 'resolved' );
		$this->assertNotEmpty( Tickets::get( $id )['resolved_at'] );
		Service::add_reply( $id, array( 'author' => 'customer', 'body' => 'Still broken' ) );
		$t = Tickets::get( $id );
		$this->assertSame( 'open', $t['status'] );
		$this->assertNull( $t['resolved_at'] );

		$this->settings( array( 'reopen_on_reply' => false ) );
		Service::set_status( $id, 'closed' );
		$this->assertSame( 'dev3hd_closed', Service::add_reply( $id, array( 'author' => 'customer', 'body' => 'Hello?' ) )->get_error_code() );
	}

	public function test_priority_change_recomputes_dues_and_logs_events() {
		$id  = $this->ticket( array( 'priority' => 'low' ) );
		$low = Tickets::get( $id );
		Service::update_props( $id, array( 'priority' => 'urgent' ), 0 );
		$urgent = Tickets::get( $id );
		$this->assertLessThan( strtotime( $low['sla_first_due'] ), strtotime( $urgent['sla_first_due'] ) );
		$events = array_values( array_filter( Replies::for_ticket( $id, true ), static fn( $r ) => 'event' === $r['type'] ) );
		$this->assertStringContainsString( 'Priority changed from Low to Urgent', end( $events )['body'] );
		$agent = $this->agent();
		Service::update_props( $id, array( 'agent_id' => $agent, 'tags' => 'vip, refund ,vip' ), 0 );
		$t = Tickets::get( $id );
		$this->assertSame( $agent, $t['agent_id'] );
		$this->assertSame( 'vip,refund', $t['tags'] );
		Service::update_props( $id, array( 'agent_id' => self::factory()->user->create( array( 'role' => 'subscriber' ) ) ), 0 );
		$this->assertSame( $agent, Tickets::get( $id )['agent_id'], 'Non-agents cannot be assigned' );
	}

	public function test_agent_visibility_by_department() {
		$billing = $this->agent( array( 'billing' ) );
		$none    = $this->agent();
		$bill_t  = Tickets::get( $this->ticket( array( 'category' => 'billing' ) ) );
		$gen_t   = Tickets::get( $this->ticket( array( 'category' => 'general' ) ) );
		$this->assertTrue( Tickets::agent_can_view( $bill_t, $billing ) );
		$this->assertFalse( Tickets::agent_can_view( $gen_t, $billing ) );
		$this->assertTrue( Tickets::agent_can_view( $gen_t, $none ), 'Agents without departments see unassigned tickets' );
		$this->assertSame( 1, Tickets::query( array(), $billing )['total'] );
		$this->settings( array( 'agents_see_all' => true ) );
		$this->assertSame( 2, Tickets::query( array(), $billing )['total'] );
		$this->assertFalse( Tickets::agent_can_view( $gen_t, self::factory()->user->create( array( 'role' => 'editor' ) ) ) );
	}

	public function test_search_counts_and_views() {
		$a = $this->ticket( array( 'subject' => 'Password reset broken' ) );
		$b = $this->ticket( array( 'subject' => 'Invoice', 'message' => 'Need a VAT invoice for order 77' ) );
		Service::set_status( $b, 'pending' );
		$this->assertSame( array( $a ), wp_list_pluck( Tickets::query( array( 'search' => 'password' ) )['rows'], 'id' ) );
		$this->assertSame( array( $b ), wp_list_pluck( Tickets::query( array( 'search' => 'VAT invoice' ) )['rows'], 'id' ), 'Message text is searched' );
		$num = Tickets::get( $b )['number'];
		$this->assertSame( array( $b ), wp_list_pluck( Tickets::query( array( 'search' => '#' . $num ) )['rows'], 'id' ) );
		$counts = Tickets::counts( array() );
		$this->assertSame( 1, $counts['new'] );
		$this->assertSame( 1, $counts['pending'] );
		$this->assertSame( 2, $counts['active'] );
		$this->assertSame( 2, $counts['unassigned'] );
		$this->assertSame( 1, Tickets::query( array( 'status' => 'pending' ) )['total'] );
	}

	public function test_overdue_breach_fires_once_and_auto_close() {
		$id = $this->ticket( array( 'priority' => 'urgent' ) );
		global $wpdb;
		$table = Dev3\AiHelpdesk\Data\Tables::name( 'tickets' );
		$wpdb->update( $table, array( 'sla_first_due' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $id ) ); // phpcs:ignore
		$fired = array();
		add_action(
			'dev3hd_sla_breached',
			static function ( $tid, $kind ) use ( &$fired ) {
				$fired[] = $kind;
			},
			10,
			2
		);
		$this->assertSame( 1, Sla::check_breaches() );
		$this->assertSame( 0, Sla::check_breaches(), 'Only once' );
		$this->assertSame( array( 'first' ), $fired );
		$this->assertSame( 1, Tickets::counts( array() )['overdue'] );
		$this->assertNotEmpty( array_filter( $this->subjects(), static fn( $s ) => false !== strpos( $s, 'SLA breached' ) ) );

		Service::set_status( $id, 'resolved' );
		$wpdb->update( $table, array( 'resolved_at' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ), array( 'id' => $id ) ); // phpcs:ignore
		$this->assertSame( 1, Automation::auto_close() );
		$this->assertSame( 'closed', Tickets::get( $id )['status'] );
	}

	public function test_rating_only_after_resolution() {
		$id = $this->ticket();
		$this->assertSame( 'dev3hd_not_resolved', Service::rate( $id, 3 )->get_error_code() );
		Service::set_status( $id, 'resolved' );
		$this->assertSame( 'dev3hd_rating', Service::rate( $id, 9 )->get_error_code() );
		$this->assertTrue( Service::rate( $id, 3, 'Fast and kind' ) );
		$t = Tickets::get( $id );
		$this->assertSame( 3, $t['rating'] );
		$this->assertSame( 'Fast and kind', $t['rating_comment'] );
	}

	public function test_canned_reply_placeholders_and_use_count() {
		$this->admin();
		$cid = Canned::save(
			array(
				'title' => 'Greeting',
				'body'  => 'Hi {{customer_first}}, about {{ticket_number}} ({{subject}}).',
			)
		);
		$this->assertWPError( Canned::save( array( 'title' => '', 'body' => '' ) ) );
		$t    = Tickets::get( $this->ticket() );
		$text = Canned::use_for( $cid, $t );
		$this->assertSame( 'Hi Grace, about #' . $t['number'] . ' (Refund not received).', $text );
		$this->assertSame( 1, Canned::get( $cid )['uses'] );
	}

	public function test_delete_removes_replies_and_files() {
		$file = $this->upload( 'shot.jpg' );
		$id   = Service::create_ticket(
			array(
				'subject' => 'With file',
				'message' => 'See attached',
				'email'   => 'a@example.com',
			),
			array( $file )
		);
		$this->assertIsInt( $id );
		$reply = Replies::for_ticket( $id )[0];
		$path  = Dev3\AiHelpdesk\Helpdesk\Files::path( $reply['attachments'][0]['key'] );
		$this->assertFileExists( $path );
		Tickets::delete( $id );
		$this->assertNull( Tickets::get( $id ) );
		$this->assertSame( array(), Replies::for_ticket( $id, true ) );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_privacy_export_and_erase() {
		$id = $this->ticket();
		Service::add_reply( $id, array( 'author' => 'customer', 'body' => 'My phone is 555-1234' ) );
		$export = Personal_Data::export( 'grace@example.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertStringContainsString( '555-1234', wp_json_encode( $export['data'] ) );
		$erase = Personal_Data::erase( 'grace@example.com' );
		$this->assertSame( 1, $erase['items_removed'] );
		$t = Tickets::get( $id );
		$this->assertStringStartsWith( 'deleted-', $t['email'] );
		$this->assertStringNotContainsString( '555-1234', wp_json_encode( Replies::for_ticket( $id, true ) ) );
	}
}
