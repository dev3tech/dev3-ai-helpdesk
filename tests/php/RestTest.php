<?php
/**
 * REST permissions and flows: agent vs manager vs subscriber, department visibility, replies with files,
 * the customer portal (spam traps, keys, owner access, reply/close/rate, resend), KB search/votes,
 * private attachments, WooCommerce order panel.
 *
 * @package Dev3\AiHelpdesk
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiHelpdesk\Data\Replies;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Helpdesk\Files;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Woo\Orders;

class RestTest extends Dev3hd_Base_Case {

	/**
	 * Valid portal form.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function form( array $over = array() ) {
		return array_merge(
			array(
				'name'     => 'Grace Hopper',
				'email'    => 'grace@example.com',
				'subject'  => 'Cannot log in',
				'message'  => 'The login page says my password is wrong.',
				'category' => 'account',
				'elapsed'  => 6000,
				'website'  => '',
			),
			$over
		);
	}

	public function test_agent_routes_need_the_agent_capability_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', 'boot' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', 'tickets' )->get_status() );
		wp_set_current_user( $this->agent() );
		$this->assertSame( 403, $this->rest( 'GET', 'boot', array(), false )->get_status(), 'Strict nonce' );
		$boot = $this->rest( 'GET', 'boot' );
		$this->assertSame( 200, $boot->get_status() );
		$this->assertFalse( $boot->get_data()['me']['manager'] );
		$this->assertSame( 403, $this->rest( 'GET', 'reports' )->get_status(), 'Reports are for managers' );
		$this->assertSame( 403, $this->rest( 'GET', 'settings' )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', 'canned', array( 'title' => 'x', 'body' => 'y' ) )->get_status() );
		$this->admin();
		$this->assertSame( 200, $this->rest( 'GET', 'reports' )->get_status() );
		$this->assertTrue( $this->rest( 'GET', 'boot' )->get_data()['me']['manager'] );
	}

	public function test_agents_only_see_their_departments() {
		$billing = $this->agent( array( 'billing' ) );
		$mine    = $this->ticket( array( 'category' => 'billing' ) );
		$other   = $this->ticket( array( 'category' => 'general' ) );
		wp_set_current_user( $billing );
		$list = $this->rest( 'GET', 'tickets', array( 'status' => 'active' ) )->get_data();
		$this->assertSame( array( $mine ), wp_list_pluck( $list['rows'], 'id' ) );
		$this->assertSame( 403, $this->rest( 'GET', 'tickets/' . $other )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', 'tickets/' . $other . '/replies', array( 'body' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'DELETE', 'tickets/' . $mine )->get_status(), 'Only managers delete' );
		$bulk = $this->rest(
			'POST',
			'tickets/bulk',
			array(
				'ids'    => array( $mine, $other ),
				'action' => 'priority',
				'value'  => 'urgent',
			)
		)->get_data();
		$this->assertSame( 1, $bulk['updated'] );
		$this->assertSame( 'normal', Tickets::get( $other )['priority'] );
	}

	public function test_agent_reply_with_attachment_and_status() {
		$agent = $this->agent();
		$id    = $this->ticket();
		wp_set_current_user( $agent );
		$res = $this->rest(
			'POST',
			'tickets/' . $id . '/replies',
			array(
				'body'   => 'Here is a screenshot.',
				'status' => 'pending',
			),
			true,
			array( 'files' => $this->upload( 'steps.jpg' ) )
		);
		$this->assertSame( 201, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'pending', $data['status'] );
		$msgs = array_values( array_filter( $data['replies'], static fn( $r ) => 'reply' === $r['type'] ) );
		$last = end( $msgs );
		$this->assertSame( 'steps.jpg', $last['attachments'][0]['name'] );
		$this->assertStringContainsString( 'dev3hd_file=', $last['attachments'][0]['url'] );
		$bad = $this->rest( 'POST', 'tickets/' . $id . '/replies', array( 'body' => 'x' ), true, array( 'files' => $this->upload( 'evil.php', 'php' ) ) );
		$this->assertSame( 400, $bad->get_status() );
		$note = $this->rest( 'POST', 'tickets/' . $id . '/replies', array( 'body' => 'Internal', 'type' => 'note' ) )->get_data();
		$this->assertSame( 'pending', $note['status'], 'Notes keep the status' );
	}

	public function test_agent_patch_and_new_ticket_on_behalf() {
		$this->admin();
		$id  = $this->ticket();
		$res = $this->rest(
			'PATCH',
			'tickets/' . $id,
			array(
				'status'   => 'on_hold',
				'priority' => 'high',
				'category' => 'billing',
				'tags'     => 'vip',
			)
		)->get_data();
		$this->assertSame( array( 'on_hold', 'high', 'billing', array( 'vip' ) ), array( $res['status'], $res['priority'], $res['category'], $res['tags'] ) );
		$new = $this->rest(
			'POST',
			'tickets',
			array(
				'name'    => 'Phone caller',
				'email'   => 'caller@example.com',
				'subject' => 'Called about delivery',
				'message' => 'Customer called: parcel late.',
			)
		);
		$this->assertSame( 201, $new->get_status() );
		$this->assertSame( 'agent', $new->get_data()['channel'] );
		$this->assertSame( 'open', $new->get_data()['status'] );
	}

	public function test_portal_create_with_spam_traps_and_login_rules() {
		$this->assertSame( 400, $this->rest( 'POST', 'portal/tickets', $this->form( array( 'website' => 'http://spam' ) ), false )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', 'portal/tickets', $this->form( array( 'elapsed' => 500 ) ), false )->get_status() );
		$res = $this->rest( 'POST', 'portal/tickets', $this->form(), false );
		$this->assertSame( 201, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 32, strlen( $data['key'] ) );
		$this->assertArrayNotHasKey( 'ai', $data, 'No internal data for customers' );
		$t = Tickets::get_by_number( $data['number'] );
		$this->assertSame( 'account', $t['category'] );
		$this->assertSame( 'technical', $t['department'] );
		$this->assertSame( 'normal', Tickets::get_by_number( $this->rest( 'POST', 'portal/tickets', $this->form( array( 'priority' => 'urgent' ) ), false )->get_data()['number'] )['priority'], 'Customers cannot pick priority unless allowed' );

		$this->settings( array( 'require_login' => true ) );
		$this->assertSame( 401, $this->rest( 'POST', 'portal/tickets', $this->form(), false )->get_status() );
		$user = self::factory()->user->create( array( 'user_email' => 'member@example.com', 'display_name' => 'Member' ) );
		wp_set_current_user( $user );
		$mine = $this->rest( 'POST', 'portal/tickets', $this->form( array( 'email' => 'spoof@example.com' ) ) );
		$this->assertSame( 201, $mine->get_status() );
		$this->assertSame( 'member@example.com', Tickets::get_by_number( $mine->get_data()['number'] )['email'], 'Logged-in email wins' );
		$this->assertCount( 1, $this->rest( 'GET', 'portal/tickets' )->get_data() );
	}

	public function test_portal_view_needs_owner_or_key_and_hides_notes() {
		$id = $this->ticket();
		$t  = Tickets::get( $id );
		Service::add_reply( $id, array( 'type' => 'note', 'author' => 'agent', 'user_id' => $this->agent(), 'body' => 'SECRET NOTE' ) );
		$this->assertSame( 404, $this->rest( 'GET', 'portal/tickets/' . $t['number'], array(), false )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', 'portal/tickets/' . $t['number'], array( 'key' => str_repeat( 'a', 32 ) ), false )->get_status() );
		$ok = $this->rest( 'GET', 'portal/tickets/' . $t['number'], array( 'key' => $t['access_key'] ), false );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertStringNotContainsString( 'SECRET NOTE', wp_json_encode( $ok->get_data() ) );
		$this->assertStringNotContainsString( 'Ticket received via', wp_json_encode( $ok->get_data() ), 'No system events' );
		$owner = self::factory()->user->create( array( 'user_email' => 'grace@example.com' ) );
		wp_set_current_user( $owner );
		$this->assertSame( 200, $this->rest( 'GET', 'portal/tickets/' . $t['number'] )->get_status(), 'Owner by email' );
	}

	public function test_portal_reply_close_rate_and_resend() {
		$id  = $this->ticket();
		$t   = Tickets::get( $id );
		$key = $t['access_key'];
		$r   = $this->rest( 'POST', 'portal/tickets/' . $t['number'] . '/reply', array( 'key' => $key, 'body' => 'More info' ), false, array( 'files' => $this->upload( 'error.pdf', 'pdf' ) ) );
		$this->assertSame( 201, $r->get_status() );
		$this->assertSame( 'error.pdf', end( $r->get_data()['replies'] )['attachments'][0]['name'] );
		$this->assertSame( 409, $this->rest( 'POST', 'portal/tickets/' . $t['number'] . '/rate', array( 'key' => $key, 'rating' => 3 ), false )->get_status() );
		$closed = $this->rest( 'POST', 'portal/tickets/' . $t['number'] . '/close', array( 'key' => $key ), false )->get_data();
		$this->assertSame( 'resolved', $closed['status'] );
		$this->assertTrue( $closed['can_rate'] );
		$rated = $this->rest( 'POST', 'portal/tickets/' . $t['number'] . '/rate', array( 'key' => $key, 'rating' => 1, 'comment' => 'Slow' ), false )->get_data();
		$this->assertSame( 1, $rated['rating'] );
		$this->assertSame( 409, $this->rest( 'POST', 'portal/tickets/' . $t['number'] . '/rate', array( 'key' => $key, 'rating' => 3 ), false )->get_status(), 'Once only' );

		$this->mails = array();
		$this->assertTrue( $this->rest( 'POST', 'portal/resend', array( 'email' => 'grace@example.com' ), false )->get_data()['sent'] );
		$this->assertTrue( $this->rest( 'POST', 'portal/resend', array( 'email' => 'nobody@example.com' ), false )->get_data()['sent'], 'Same answer for unknown emails' );
		$this->assertCount( 1, $this->mails_to( 'grace@example.com' ) );
		$this->assertCount( 0, $this->mails_to( 'nobody@example.com' ) );
	}

	public function test_public_routes_reject_cross_origin_requests() {
		$req = new WP_REST_Request( 'POST', '/dev3hd/v1/portal/tickets' );
		$req->set_header( 'Origin', 'https://evil.example' );
		$req->set_body_params( $this->form() );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
	}

	public function test_kb_search_and_votes() {
		$a = $this->article( 'Reset your password', 'Click "Forgot password" on the login page and follow the email.', 'Account' );
		$this->article( 'Shipping times', 'We ship within two business days.', 'Orders' );
		$res = $this->rest( 'GET', 'kb/search', array( 'q' => 'forgot my password' ), false )->get_data();
		$this->assertSame( 'keyword', $res['mode'] );
		$this->assertSame( 'Reset your password', $res['results'][0]['title'] );
		$this->assertArrayNotHasKey( 'views', $res['results'][0] );
		$this->assertTrue( $this->rest( 'POST', 'kb/vote', array( 'id' => $a, 'helpful' => true ), false )->get_data()['ok'] );
		$this->rest( 'POST', 'kb/vote', array( 'id' => $a, 'helpful' => true ), false );
		$this->assertSame( 1, (int) get_post_meta( $a, '_dev3hd_helpful_yes', true ), 'One vote per IP per article' );
		$this->assertSame( 404, $this->rest( 'POST', 'kb/vote', array( 'id' => 999999, 'helpful' => true ), false )->get_status() );
	}

	public function test_attachments_are_private_with_expiring_signatures() {
		$stored = Files::store( $this->upload( 'proof.jpg' ) );
		$this->assertIsArray( $stored );
		$this->assertStringContainsString( 'dev3hd-private', Files::path( $stored['key'] ) );
		$this->assertFileExists( Files::base_dir() . '/.htaccess' );
		$url = Files::url( $stored['key'], 'proof.jpg' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertTrue( Files::verify( rawurldecode( $q['dev3hd_file'] ), (int) $q['exp'], $q['sig'] ) );
		$this->assertFalse( Files::verify( rawurldecode( $q['dev3hd_file'] ), (int) $q['exp'], str_repeat( '0', 64 ) ) );
		$this->assertFalse( Files::verify( rawurldecode( $q['dev3hd_file'] ), (int) $q['exp'], $q['sig'], (int) $q['exp'] + 10 ), 'Expired' );
		$this->assertSame( '', Files::path( '../../wp-config.php' ) );
		$this->assertWPError( Files::store( $this->upload( 'shell.php', 'php' ) ) );
		$this->settings( array( 'max_files' => 1 ) );
		$this->assertSame( 'dev3hd_upload_count', Files::store_many( array( $this->upload(), $this->upload() ) )->get_error_code() );
	}

	public function test_woocommerce_order_panel_and_order_field() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not installed.' );
		}
		$product = new WC_Product_Simple();
		$product->set_name( 'Desk lamp' );
		$product->set_regular_price( '40' );
		$product->save();
		$order = wc_create_order();
		$order->add_product( $product, 2 );
		$order->set_billing_email( 'grace@example.com' );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		$this->mails = array();

		$this->assertSame( $order->get_id(), Orders::find_for( (string) $order->get_order_number(), 'GRACE@example.com' ) );
		$this->assertSame( 0, Orders::find_for( (string) $order->get_order_number(), 'eve@example.com' ), 'Order must belong to the email' );
		$bad = $this->rest( 'POST', 'portal/tickets', $this->form( array( 'order' => '99999' ) ), false );
		$this->assertSame( 400, $bad->get_status() );
		$good = $this->rest( 'POST', 'portal/tickets', $this->form( array( 'order' => '#' . $order->get_order_number() ) ), false );
		$this->assertSame( 201, $good->get_status() );
		$t = Tickets::get_by_number( $good->get_data()['number'] );
		$this->assertSame( $order->get_id(), $t['order_id'] );

		$this->admin();
		$panel = $this->rest( 'GET', 'tickets/' . $t['id'] . '/orders' )->get_data();
		$this->assertTrue( $panel['orders'][0]['linked'] );
		$this->assertSame( '2 × Desk lamp', $panel['orders'][0]['items'][0]['qty'] . ' × ' . $panel['orders'][0]['items'][0]['name'] );
		$this->assertStringNotContainsString( '&#', $panel['orders'][0]['total'] );
	}

	public function test_manager_canned_agents_and_reports() {
		$this->admin();
		$c = $this->rest(
			'POST',
			'canned',
			array(
				'title' => 'Shipping',
				'body'  => 'We ship in {{subject}}',
			)
		);
		$this->assertSame( 201, $c->get_status() );
		$this->assertSame( 200, $this->rest( 'PATCH', 'canned/' . $c->get_data()['id'], array( 'title' => 'Shipping 2', 'body' => 'b' ) )->get_status() );
		$user = self::factory()->user->create( array( 'user_login' => 'newagent', 'role' => 'subscriber' ) );
		$this->assertSame( 404, $this->rest( 'POST', 'agents', array( 'user' => 'ghost' ) )->get_status() );
		$this->rest( 'POST', 'agents', array( 'user' => 'newagent', 'departments' => array( 'billing' ) ) );
		$this->assertTrue( Dev3\AiHelpdesk\Roles::is_agent( $user ) );
		$this->assertSame( array( 'billing' ), Dev3\AiHelpdesk\Roles::departments_of( $user ) );
		$this->rest( 'DELETE', 'agents/' . $user );
		$this->assertFalse( Dev3\AiHelpdesk\Roles::is_agent( $user ) );

		$id = $this->ticket();
		Service::add_reply( $id, array( 'author' => 'agent', 'user_id' => get_current_user_id(), 'body' => 'Hi' ) );
		Service::set_status( $id, 'resolved' );
		Service::rate( $id, 3 );
		$report = $this->rest( 'GET', 'reports', array( 'days' => 30 ) )->get_data();
		$this->assertSame( 1, $report['totals']['created'] );
		$this->assertSame( 100, $report['totals']['csat'] );
		$this->assertSame( 100, $report['totals']['sla_compliance'] );
		$csv = $this->rest( 'GET', 'reports/export', array( 'days' => 30 ) )->get_data();
		$this->assertStringContainsString( 'Refund not received', $csv['csv'] );
		$stats = $this->rest( 'GET', 'stats' )->get_data();
		$this->assertSame( 1, $stats['resolved7'] );
		$this->assertCount( 14, $stats['trend'] );
	}
}
