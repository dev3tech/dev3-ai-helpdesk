<?php
/**
 * Email: MIME parsing, quote stripping, inbound threading and loop protection, the IMAP client over a
 * scripted transport + the poll job, the webhook (generic, Postmark, Mailgun, raw MIME) and outgoing mail.
 *
 * @package Dev3\AiHelpdesk
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiHelpdesk\Core3\Core\Jobs;
use Dev3\AiHelpdesk\Data\Replies;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Mail\Imap_Client;
use Dev3\AiHelpdesk\Mail\Imap_Transport;
use Dev3\AiHelpdesk\Mail\Inbound;
use Dev3\AiHelpdesk\Mail\Mailer;
use Dev3\AiHelpdesk\Mail\Mime_Parser;
use Dev3\AiHelpdesk\Mail\Poller;
use Dev3\AiHelpdesk\Settings;

/**
 * Scripted IMAP server.
 */
class Dev3hd_Fake_Imap implements Imap_Transport {

	public $written = array();
	public $messages;
	public $seen    = array();
	private $buffer = array();
	public $refuse  = false;

	public function __construct( array $messages ) {
		$this->messages = $messages;
	}

	public function open( $host, $port, $ssl, $timeout ) {
		$this->buffer[] = "* OK IMAP4rev1 ready\r\n";
		return true;
	}

	public function start_tls() {
		return true;
	}

	public function write( $data ) {
		$this->written[] = $data;
		$tag             = strtok( $data, ' ' );
		$cmd             = trim( substr( $data, strlen( $tag ) + 1 ) );
		if ( 0 === strpos( $cmd, 'LOGIN' ) ) {
			$this->buffer[] = $this->refuse ? "{$tag} NO [AUTHENTICATIONFAILED] Invalid\r\n" : "{$tag} OK LOGIN completed\r\n";
		} elseif ( 0 === strpos( $cmd, 'SELECT' ) ) {
			$this->buffer[] = "* 2 EXISTS\r\n";
			$this->buffer[] = "{$tag} OK [READ-WRITE] SELECT completed\r\n";
		} elseif ( 'UID SEARCH UNSEEN' === $cmd ) {
			$unseen         = array_diff( array_keys( $this->messages ), $this->seen );
			$this->buffer[] = '* SEARCH ' . implode( ' ', $unseen ) . "\r\n";
			$this->buffer[] = "{$tag} OK SEARCH completed\r\n";
		} elseif ( preg_match( '/^UID FETCH (\d+) BODY\.PEEK\[\]$/', $cmd, $m ) ) {
			$raw            = $this->messages[ (int) $m[1] ];
			$this->buffer[] = '* 1 FETCH (UID ' . $m[1] . ' BODY[] {' . strlen( $raw ) . "}\r\n";
			$this->buffer[] = array( 'literal' => $raw );
			$this->buffer[] = ")\r\n";
			$this->buffer[] = "{$tag} OK FETCH completed\r\n";
		} elseif ( preg_match( '/^UID STORE (\d+) \+FLAGS/', $cmd, $m ) ) {
			$this->seen[]   = (int) $m[1];
			$this->buffer[] = "{$tag} OK STORE completed\r\n";
		} else {
			$this->buffer[] = "* BYE\r\n";
			$this->buffer[] = "{$tag} OK done\r\n";
		}
		return true;
	}

	public function read_line() {
		$next = array_shift( $this->buffer );
		return is_array( $next ) ? false : ( null === $next ? false : $next );
	}

	public function read_bytes( $length ) {
		$next = array_shift( $this->buffer );
		return is_array( $next ) ? $next['literal'] : '';
	}

	public function close() {}
}

class MailTest extends Dev3hd_Base_Case {

	public function tear_down() {
		Poller::$transport = null;
		parent::tear_down();
	}

	/**
	 * Raw multipart message.
	 *
	 * @param array $h Overrides: from, subject, id, reply_to, extra, body.
	 * @return string
	 */
	private function raw( array $h = array() ) {
		$h = array_merge(
			array(
				'from'     => '"Grace Hopper" <grace@example.com>',
				'subject'  => '=?UTF-8?B?' . base64_encode( 'Order problem – help' ) . '?=',
				'id'       => '<abc-' . wp_generate_password( 6, false ) . '@mail.example.com>',
				'reply_to' => '',
				'extra'    => '',
				'body'     => "Hi team,\r\nMy order arrived br=C3=B6ken.\r\n",
			),
			$h
		);
		$att = base64_encode( "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF" );
		return "From: {$h['from']}\r\nTo: support@example.org\r\nSubject: {$h['subject']}\r\nMessage-ID: {$h['id']}\r\n"
			. ( $h['reply_to'] ? "In-Reply-To: {$h['reply_to']}\r\n" : '' ) . $h['extra']
			. "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"OUTER\"\r\n\r\n"
			. "--OUTER\r\nContent-Type: multipart/alternative; boundary=\"INNER\"\r\n\r\n"
			. "--INNER\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n{$h['body']}\r\n"
			. "--INNER\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>HTML version</p>\r\n--INNER--\r\n"
			. "--OUTER\r\nContent-Type: application/pdf; name=\"invoice.pdf\"\r\nContent-Disposition: attachment; filename=\"invoice.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$att}\r\n--OUTER--\r\n";
	}

	public function test_mime_parser_decodes_headers_parts_and_attachments() {
		$msg = Mime_Parser::parse( $this->raw( array( 'id' => '<m1@mail.example.com>' ) ) );
		$this->assertSame( 'grace@example.com', $msg['from_email'] );
		$this->assertSame( 'Grace Hopper', $msg['from_name'] );
		$this->assertSame( 'Order problem – help', $msg['subject'] );
		$this->assertSame( 'm1@mail.example.com', $msg['message_id'] );
		$this->assertStringContainsString( 'arrived bröken', $msg['text'] );
		$this->assertStringContainsString( 'HTML version', $msg['html'] );
		$this->assertCount( 1, $msg['attachments'] );
		$this->assertSame( 'invoice.pdf', $msg['attachments'][0]['name'] );
		$this->assertStringStartsWith( '%PDF', $msg['attachments'][0]['content'] );
		$latin = Mime_Parser::parse( "From: a@example.com\nSubject: =?ISO-8859-1?Q?Caf=E9?=\nContent-Type: text/plain; charset=ISO-8859-1\n\nCaf\xe9 au lait" );
		$this->assertSame( 'Café', $latin['subject'] );
		$this->assertSame( 'Café au lait', trim( $latin['text'] ) );
	}

	public function test_strip_quotes_keeps_only_the_new_text() {
		$text = "Thanks, that fixed it!\n\n" . Mailer::MARKER . "\nOld stuff";
		$this->assertSame( 'Thanks, that fixed it!', Mime_Parser::strip_quotes( $text ) );
		$this->assertSame( 'New answer', Mime_Parser::strip_quotes( "New answer\n\nOn Mon, 20 Oct 2026 at 10:00, Support <s@x.com> wrote:\n> old line\n> more" ) );
		$this->assertSame( "Line one\nLine two", Mime_Parser::strip_quotes( "Line one\n> quoted\nLine two\n-- \nGrace\nCEO" ) );
	}

	public function test_inbound_creates_tickets_and_threads_replies() {
		$res = Inbound::process( Mime_Parser::parse( $this->raw( array( 'id' => '<first@mail.example.com>' ) ) ) );
		$this->assertSame( 'created', $res['action'] );
		$t = Tickets::get( $res['ticket_id'] );
		$this->assertSame( 'email', $t['channel'] );
		$this->assertSame( 'Order problem – help', $t['subject'] );
		$first = Replies::for_ticket( $t['id'] )[0];
		$this->assertCount( 1, $first['attachments'], 'PDF stored privately' );
		$this->assertSame( 'first@mail.example.com', $first['message_id'] );

		// Reply by subject token from the same sender.
		$reply = Inbound::process( Mime_Parser::parse( $this->raw( array( 'subject' => 'Re: [#' . $t['number'] . '] Order problem', 'body' => "Any update?\r\n\r\nOn Tue, Support wrote:\r\n> old" ) ) ) );
		$this->assertSame( 'replied', $reply['action'] );
		$this->assertSame( $t['id'], $reply['ticket_id'] );
		$bodies = wp_list_pluck( Replies::for_ticket( $t['id'] ), 'body' );
		$this->assertSame( 'Any update?', end( $bodies ) );

		// Reply threaded by In-Reply-To (our outgoing Message-ID) without a subject token.
		$agent = $this->agent();
		Service::add_reply( $t['id'], array( 'author' => 'agent', 'user_id' => $agent, 'body' => 'Shipped a new one.' ) );
		$sent = array_values( array_filter( Replies::for_ticket( $t['id'] ), static fn( $r ) => 'agent' === $r['author'] ) );
		$this->assertNotEmpty( $sent[0]['message_id'], 'Outgoing Message-ID stored' );
		$threaded = Inbound::process( Mime_Parser::parse( $this->raw( array( 'subject' => 'Re: shipping', 'reply_to' => '<' . $sent[0]['message_id'] . '>', 'body' => 'Great, thanks' ) ) ) );
		$this->assertSame( 'replied', $threaded['action'] );

		// A stranger quoting the number opens a new ticket instead.
		$stranger = Inbound::process( Mime_Parser::parse( $this->raw( array( 'from' => 'eve@example.net', 'subject' => 'Re: [#' . $t['number'] . ']' ) ) ) );
		$this->assertSame( 'created', $stranger['action'] );
		$this->assertNotSame( $t['id'], $stranger['ticket_id'] );
	}

	public function test_agents_can_reply_by_email() {
		$agent = self::factory()->user->create( array( 'role' => 'dev3hd_agent', 'user_email' => 'bo@example.org', 'display_name' => 'Bo' ) );
		$id    = $this->ticket();
		$t     = Tickets::get( $id );
		$res   = Inbound::process( Mime_Parser::parse( $this->raw( array( 'from' => 'Bo <bo@example.org>', 'subject' => 'Re: [#' . $t['number'] . ']', 'body' => 'We refunded you.' ) ) ) );
		$this->assertSame( 'replied', $res['action'] );
		$last = Replies::for_ticket( $id );
		$last = end( $last );
		$this->assertSame( 'agent', $last['author'] );
		$this->assertSame( $agent, $last['user_id'] );
		$this->assertNotEmpty( array_filter( $this->mails_to( 'grace@example.com' ), static fn( $m ) => false !== strpos( (string) $m['message'], 'We refunded you.' ) ), 'Customer gets the agent reply' );
	}

	public function test_loop_and_abuse_protection() {
		$auto = Inbound::process( Mime_Parser::parse( $this->raw( array( 'extra' => "Auto-Submitted: auto-replied\r\n" ) ) ) );
		$this->assertSame( array( 'ignored', 'auto_submitted' ), array( $auto['action'], $auto['reason'] ) );
		$bulk = Inbound::process( Mime_Parser::parse( $this->raw( array( 'extra' => "Precedence: bulk\r\n" ) ) ) );
		$this->assertSame( 'bulk', $bulk['reason'] );
		$daemon = Inbound::process( Mime_Parser::parse( $this->raw( array( 'from' => 'MAILER-DAEMON@mx.example.com' ) ) ) );
		$this->assertSame( 'system_sender', $daemon['reason'] );
		$own = Inbound::process( Mime_Parser::parse( $this->raw( array( 'from' => get_option( 'admin_email' ) ) ) ) );
		$this->assertSame( 'own_address', $own['reason'] );
		$raw  = $this->raw( array( 'id' => '<dup@mail.example.com>' ) );
		$this->assertSame( 'created', Inbound::process( Mime_Parser::parse( $raw ) )['action'] );
		$this->assertSame( 'duplicate', Inbound::process( Mime_Parser::parse( $raw ) )['reason'] );
		add_filter( 'dev3hd_inbound_hourly_limit', static fn() => 2 );
		Inbound::process( Mime_Parser::parse( $this->raw( array( 'from' => 'flood@example.com' ) ) ) );
		Inbound::process( Mime_Parser::parse( $this->raw( array( 'from' => 'flood@example.com' ) ) ) );
		$this->assertSame( 'rate_limited', Inbound::process( Mime_Parser::parse( $this->raw( array( 'from' => 'flood@example.com' ) ) ) )['reason'] );
	}

	public function test_imap_client_and_poll_job() {
		$fake              = new Dev3hd_Fake_Imap(
			array(
				7 => $this->raw( array( 'id' => '<imap7@mail.example.com>' ) ),
				9 => $this->raw( array( 'id' => '<imap9@mail.example.com>', 'from' => 'other@example.com' ) ),
			)
		);
		Poller::$transport = $fake;
		$this->settings(
			array(
				'imap_enabled'  => true,
				'imap_host'     => 'imap.example.com',
				'imap_user'     => 'help@example.org',
				'imap_password' => 'p"ss\\word',
			)
		);
		$this->assertTrue( Settings::get( 'imap_password_set' ) );
		$this->assertArrayNotHasKey( 'imap_password', Dev3\AiHelpdesk\Core3\Core\Settings_Store::public_settings(), 'Password never reaches the browser' );
		$this->assertSame( 'p"ss\\word', Settings::imap_password() );

		$job = Poller::queue();
		$this->assertSame( Poller::JOB, $job['type'] );
		$done = Jobs::run( $job['id'] );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( 2, (int) ( (array) $done['result'] )['created'] );
		$this->assertSame( array( 7, 9 ), $fake->seen );
		$this->assertStringContainsString( 'LOGIN "help@example.org" "p\\"ss\\\\word"', implode( '', $fake->written ) );
		$this->assertSame( 2, Tickets::counts( array() )['all'] );

		$again = Poller::poll();
		$this->assertSame( 0, $again['fetched'], 'Seen messages are not fetched again' );

		$bad         = new Dev3hd_Fake_Imap( array() );
		$bad->refuse = true;
		$client      = new Imap_Client(
			array(
				'host' => 'imap.example.com',
				'user' => 'x',
			),
			$bad
		);
		$this->assertSame( 'dev3hd_imap_login', $client->connect()->get_error_code() );
	}

	public function test_webhook_requires_enabled_and_secret() {
		$this->settings( array( 'webhook_enabled' => false ) );
		$token = Settings::inbound_token();
		$this->assertSame( 32, strlen( $token ) );
		$this->assertSame( 403, $this->rest( 'POST', 'inbound/' . $token, array( 'from' => 'a@example.com' ), false )->get_status() );
		$this->settings( array( 'webhook_enabled' => true ) );
		$this->assertSame( 403, $this->rest( 'POST', 'inbound/' . str_repeat( 'x', 32 ), array( 'from' => 'a@example.com' ), false )->get_status() );

		$res = $this->rest(
			'POST',
			'inbound/' . $token,
			array(
				'from'        => 'Ada <ada@example.com>',
				'subject'     => 'Login broken',
				'text'        => 'I cannot log in.',
				'message_id'  => '<gen1@example.com>',
				'attachments' => array(
					array(
						'filename'     => 'notes.txt',
						'content'      => base64_encode( 'error 500 at login' ),
						'content_type' => 'text/plain',
					),
				),
			),
			false
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'created', $res->get_data()['action'] );
		$t = Tickets::get( $res->get_data()['ticket_id'] );
		$this->assertSame( 'webhook', $t['channel'] );
		$this->assertSame( 'notes.txt', Replies::for_ticket( $t['id'] )[0]['attachments'][0]['name'] );

		// Postmark JSON, threaded by subject.
		$pm = $this->rest(
			'POST',
			'inbound/' . $token,
			array(
				'FromFull'  => array(
					'Email' => 'ada@example.com',
					'Name'  => 'Ada',
				),
				'Subject'   => 'RE: [#' . $t['number'] . '] Login broken',
				'TextBody'  => "Still broken\n\nOn Mon, Support wrote:\n> x",
				'MessageID' => 'pm-1',
				'Headers'   => array(
					array(
						'Name'  => 'Message-ID',
						'Value' => '<pm-1@postmark>',
					),
				),
			),
			false
		);
		$this->assertSame( 'replied', $pm->get_data()['action'] );

		// Mailgun form fields.
		$mg = $this->rest(
			'POST',
			'inbound/' . $token,
			array(
				'sender'     => 'mg@example.com',
				'from'       => 'MG User <mg@example.com>',
				'subject'    => 'Billing question',
				'body-plain' => 'Can I get an invoice?',
				'Message-Id' => '<mg-1@example.com>',
			),
			false,
			array( 'none' => array() )
		);
		$this->assertSame( 'created', $mg->get_data()['action'] );

		// Raw MIME in a field.
		$raw = $this->rest( 'POST', 'inbound/' . $token, array( 'raw' => $this->raw( array( 'from' => 'raw@example.com' ) ) ), false );
		$this->assertSame( 'created', $raw->get_data()['action'] );
	}

	public function test_outgoing_notifications() {
		$this->settings( array( 'imap_enabled' => true ) );
		$id = $this->ticket( array( 'category' => 'billing' ) );
		$t  = Tickets::get( $id );
		$customer = $this->mails_to( 'grace@example.com' );
		$this->assertCount( 1, $customer );
		$this->assertStringStartsWith( '[#' . $t['number'] . '] ', $customer[0]['subject'] );
		$this->assertStringContainsString( Mailer::MARKER, $customer[0]['message'] );
		$this->assertStringContainsString( 'key=' . $t['access_key'], html_entity_decode( $customer[0]['message'] ) );
		$this->assertNotEmpty( $t['message_id'], 'Thread id stored for web tickets' );
		$this->assertNotEmpty( $this->mails_to( get_option( 'admin_email' ) ), 'Team alert goes to admin when the department has no agents' );

		$this->mails = array();
		$this->settings( array( 'notify' => array( 'customer_reply' => false ) ) );
		Service::add_reply( $id, array( 'author' => 'agent', 'user_id' => $this->agent(), 'body' => 'Muted' ) );
		$this->assertCount( 0, $this->mails_to( 'grace@example.com' ) );

		$this->mails = array();
		Service::set_status( $id, 'resolved' );
		$resolved = $this->mails_to( 'grace@example.com' );
		$this->assertCount( 1, $resolved );
		$this->assertStringContainsString( 'rate=3', html_entity_decode( $resolved[0]['message'] ) );
		$this->assertStringNotContainsString( 'DEV3', $resolved[0]['message'], 'Customer emails are not branded' );
	}
}
