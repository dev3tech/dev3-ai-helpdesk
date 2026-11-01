<?php
/**
 * Outgoing notifications (wp_mail, HTML with a plain-text alternative). Customer emails are neutral (no DEV3
 * branding), carry a "[#number]" subject token, a reply marker and a stored Message-ID for threading.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Mail;

use Dev3\AiHelpdesk\Data\Replies;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Frontend\Portal;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Roles;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Mailer.
 */
class Mailer {

	const MARKER = '##- Please type your reply above this line -##';

	/**
	 * Message-ID / alt body for the mail being sent (picked up in phpmailer_init).
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'dev3hd_ticket_created', array( self::class, 'on_created' ), 20, 2 );
		add_action( 'dev3hd_reply_added', array( self::class, 'on_reply' ), 20, 3 );
		add_action( 'dev3hd_status_changed', array( self::class, 'on_status' ), 20, 4 );
		add_action( 'dev3hd_ticket_assigned', array( self::class, 'on_assigned' ), 20, 3 );
		add_action( 'dev3hd_sla_breached', array( self::class, 'on_breach' ), 20, 2 );
		add_action( 'phpmailer_init', array( self::class, 'phpmailer' ) );
	}

	/**
	 * Sender address.
	 *
	 * @return string
	 */
	public static function from_email() {
		$from = (string) Settings::get( 'from_email', '' );
		if ( is_email( $from ) ) {
			return $from;
		}
		$imap = (string) Settings::get( 'imap_user', '' );
		return is_email( $imap ) ? $imap : (string) get_option( 'admin_email' );
	}

	/**
	 * Sender name.
	 *
	 * @return string
	 */
	public static function from_name() {
		$name = (string) Settings::get( 'from_name', '' );
		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * New Message-ID (without brackets).
	 *
	 * @param array $ticket Ticket.
	 * @param int   $part   Reply id or 0.
	 * @return string
	 */
	public static function new_message_id( array $ticket, $part = 0 ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return 'dev3hd.' . (int) $ticket['number'] . '.' . (int) $part . '.' . wp_generate_password( 10, false, false ) . '@' . ( $host ? $host : 'localhost' );
	}

	/**
	 * Sends one email.
	 *
	 * @param string|array $to      Recipient(s).
	 * @param string       $subject Subject.
	 * @param string       $inner   Inner HTML (already escaped).
	 * @param array        $args    ticket, message_id, in_reply_to, marker (bool), button {url,label}, preheader, kind.
	 * @return bool
	 */
	public static function send( $to, $subject, $inner, array $args = array() ) {
		$to = array_values( array_unique( array_filter( (array) $to, 'is_email' ) ) );
		if ( ! $to ) {
			return false;
		}
		$ticket  = $args['ticket'] ?? null;
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::header_safe( self::from_name() ) . ' <' . self::from_email() . '>',
		);
		if ( $ticket ) {
			$headers[] = 'X-DEV3HD-Ticket: ' . (int) $ticket['number'];
		}
		if ( ! empty( $args['in_reply_to'] ) ) {
			$headers[] = 'In-Reply-To: <' . self::header_safe( $args['in_reply_to'] ) . '>';
			$headers[] = 'References: <' . self::header_safe( $args['in_reply_to'] ) . '>';
		}
		if ( 'customer' !== ( $args['kind'] ?? 'customer' ) ) {
			$headers[] = 'Auto-Submitted: auto-generated';
		}
		$html = self::template( $inner, $args );
		/**
		 * Filters a notification's HTML.
		 *
		 * @param string $html Html.
		 * @param array  $args Args.
		 */
		$html          = (string) apply_filters( 'dev3hd_email_html', $html, $args );
		self::$pending = array(
			'message_id' => (string) ( $args['message_id'] ?? '' ),
			'alt'        => self::html_to_text( $inner, $args ),
		);
		$sent          = wp_mail( $to, self::header_safe( $subject ), $html, $headers );
		self::$pending = array();
		return (bool) $sent;
	}

	/**
	 * Sets the Message-ID and plain-text alternative.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
	 */
	public static function phpmailer( $mailer ) {
		if ( ! self::$pending ) {
			return;
		}
		if ( '' !== self::$pending['message_id'] ) {
			$mailer->MessageID = '<' . self::$pending['message_id'] . '>'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		$mailer->AltBody = self::$pending['alt']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Strips line breaks from header values.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function header_safe( $value ) {
		return trim( str_replace( array( "\r", "\n" ), ' ', (string) $value ) );
	}

	/**
	 * Escaped paragraph HTML from plain text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function text_html( $text ) {
		return make_clickable( nl2br( esc_html( (string) $text ) ) );
	}

	/**
	 * Wraps content in the email layout.
	 *
	 * @param string $inner Inner HTML.
	 * @param array  $args  Args.
	 * @return string
	 */
	public static function template( $inner, array $args ) {
		$accent = sanitize_hex_color( (string) Settings::get( 'accent_color', '#2563eb' ) );
		$accent = $accent ? $accent : '#2563eb';
		$site   = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$marker = ! empty( $args['marker'] ) && ( Settings::get( 'imap_enabled', false ) || Settings::get( 'webhook_enabled', false ) )
			? '<p style="margin:0 0 16px;color:#6b7280;font-size:12px;">' . esc_html( self::MARKER ) . '</p>'
			: '';
		$button = '';
		if ( ! empty( $args['button']['url'] ) ) {
			$button = '<p style="margin:24px 0 8px;"><a href="' . esc_url( $args['button']['url'] ) . '" style="display:inline-block;background:' . esc_attr( $accent ) . ';color:#ffffff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:8px;">' . esc_html( $args['button']['label'] ) . '</a></p>';
		}
		$pre = ! empty( $args['preheader'] ) ? '<div style="display:none;max-height:0;overflow:hidden;">' . esc_html( $args['preheader'] ) . '</div>' : '';
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
			. '<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#111827;">' . $pre
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">'
			. '<tr><td style="padding:20px 24px;border-bottom:3px solid ' . esc_attr( $accent ) . ';font-weight:700;font-size:16px;">' . $site . '</td></tr>'
			. '<tr><td style="padding:24px;font-size:15px;line-height:1.6;">' . $marker . $inner . $button . '</td></tr>'
			. '</table><p style="color:#6b7280;font-size:12px;margin:16px 0 0;">' . $site . '</p></td></tr></table></body></html>';
	}

	/**
	 * Plain-text alternative.
	 *
	 * @param string $inner Inner HTML.
	 * @param array  $args  Args.
	 * @return string
	 */
	private static function html_to_text( $inner, array $args ) {
		$text = Mime_Parser::html_to_text( $inner );
		if ( ! empty( $args['button']['url'] ) ) {
			$text .= "\n\n" . $args['button']['label'] . ': ' . $args['button']['url'];
		}
		if ( ! empty( $args['marker'] ) && ( Settings::get( 'imap_enabled', false ) || Settings::get( 'webhook_enabled', false ) ) ) {
			$text = self::MARKER . "\n\n" . $text;
		}
		return $text;
	}

	/**
	 * Agent recipients for a ticket (department email, else department agents, else admin email).
	 *
	 * @param array $ticket Ticket.
	 * @return string[]
	 */
	public static function team_recipients( array $ticket ) {
		$dept = Settings::department( $ticket['department'] );
		if ( $dept && is_email( $dept['email'] ?? '' ) ) {
			return array( $dept['email'] );
		}
		$out = array();
		foreach ( (array) ( $dept['agents'] ?? array() ) as $uid ) {
			$user = get_userdata( (int) $uid );
			if ( $user && Roles::is_agent( (int) $uid ) ) {
				$out[] = $user->user_email;
			}
		}
		return $out ? $out : array( (string) get_option( 'admin_email' ) );
	}

	/**
	 * Admin link to a ticket.
	 *
	 * @param array $ticket Ticket.
	 * @return string
	 */
	public static function admin_link( array $ticket ) {
		return admin_url( 'admin.php?page=' . DEV3HD_SLUG . '#/tickets/' . (int) $ticket['id'] );
	}

	/**
	 * Ticket created: confirmation to the customer, alert to the team.
	 *
	 * @param int $ticket_id Ticket id.
	 * @param int $reply_id  First message.
	 */
	public static function on_created( $ticket_id, $reply_id ) {
		$ticket = Tickets::get( $ticket_id );
		$reply  = Replies::get( $reply_id );
		if ( ! $ticket || ! $reply ) {
			return;
		}
		$tag = '[#' . (int) $ticket['number'] . '] ';
		if ( Settings::notify( 'customer_new' ) ) {
			$first = trim( (string) strtok( (string) $ticket['name'], ' ' ) );
			/* translators: %s: customer first name. */
			$inner = '<p>' . esc_html( sprintf( __( 'Hi %s,', 'dev3-ai-helpdesk' ), $first ) ) . '</p>'
				/* translators: %s: ticket number. */
				. '<p>' . esc_html( sprintf( __( 'Thanks for getting in touch. We received your request and opened ticket #%s. We will reply as soon as we can.', 'dev3-ai-helpdesk' ), $ticket['number'] ) ) . '</p>'
				. '<p style="color:#4b5563;"><strong>' . esc_html( $ticket['subject'] ) . '</strong><br>' . self::text_html( wp_trim_words( $reply['body'], 60 ) ) . '</p>';
			$mid = self::new_message_id( $ticket, 0 );
			self::send(
				$ticket['email'],
				/* translators: %s: subject. */
				$tag . sprintf( __( 'We received your request: %s', 'dev3-ai-helpdesk' ), $ticket['subject'] ),
				$inner,
				array(
					'ticket'      => $ticket,
					'marker'      => true,
					'message_id'  => $mid,
					'in_reply_to' => $ticket['message_id'],
					'button'      => array(
						'url'   => Portal::ticket_url( $ticket ),
						'label' => __( 'View your ticket', 'dev3-ai-helpdesk' ),
					),
				)
			);
			if ( '' === $ticket['message_id'] ) {
				Tickets::update( $ticket_id, array( 'message_id' => $mid ) );
			}
		}
		if ( Settings::notify( 'agent_new' ) ) {
			$inner = '<p>' . esc_html(
				/* translators: 1: customer name, 2: email. */
				sprintf( __( 'New ticket from %1$s (%2$s).', 'dev3-ai-helpdesk' ), $ticket['name'], $ticket['email'] )
			) . '</p><p><strong>' . esc_html( $ticket['subject'] ) . '</strong></p><p>' . self::text_html( wp_trim_words( $reply['body'], 120 ) ) . '</p>';
			$to = $ticket['agent_id'] ? array( (string) get_userdata( $ticket['agent_id'] )->user_email ) : self::team_recipients( $ticket );
			self::send(
				$to,
				/* translators: %s: subject. */
				$tag . sprintf( __( 'New ticket: %s', 'dev3-ai-helpdesk' ), $ticket['subject'] ),
				$inner,
				array(
					'ticket' => $ticket,
					'kind'   => 'agent',
					'button' => array(
						'url'   => self::admin_link( $ticket ),
						'label' => __( 'Open ticket', 'dev3-ai-helpdesk' ),
					),
				)
			);
		}
	}

	/**
	 * Reply added: agent/AI reply → customer; customer reply → assigned agent or team.
	 *
	 * @param int  $ticket_id Ticket id.
	 * @param int  $reply_id  Reply id.
	 * @param bool $notify    Send emails.
	 */
	public static function on_reply( $ticket_id, $reply_id, $notify = true ) {
		$ticket = Tickets::get( $ticket_id );
		$reply  = Replies::get( $reply_id );
		if ( ! $notify || ! $ticket || ! $reply || 'reply' !== $reply['type'] ) {
			return;
		}
		$tag = '[#' . (int) $ticket['number'] . '] ';
		if ( 'customer' !== $reply['author'] ) {
			if ( ! Settings::notify( 'customer_reply' ) ) {
				return;
			}
			$files = '';
			if ( $reply['attachments'] ) {
				/* translators: %d: number of files. */
				$files = '<p style="color:#4b5563;font-size:14px;">' . esc_html( sprintf( _n( '%d attachment: open the ticket to download it.', '%d attachments: open the ticket to download them.', count( $reply['attachments'] ), 'dev3-ai-helpdesk' ), count( $reply['attachments'] ) ) ) . '</p>';
			}
			$inner = '<p>' . self::text_html( $reply['body'] ) . '</p>' . $files . '<p style="color:#6b7280;font-size:14px;">— ' . esc_html( $reply['name'] ) . '</p>';
			$mid   = self::new_message_id( $ticket, $reply_id );
			$sent  = self::send(
				$ticket['email'],
				$tag . 'Re: ' . $ticket['subject'],
				$inner,
				array(
					'ticket'      => $ticket,
					'marker'      => true,
					'message_id'  => $mid,
					'in_reply_to' => $ticket['message_id'],
					'button'      => array(
						'url'   => Portal::ticket_url( $ticket ),
						'label' => __( 'View the conversation', 'dev3-ai-helpdesk' ),
					),
				)
			);
			if ( $sent ) {
				Replies::set_message_id( $reply_id, $mid );
			}
			return;
		}
		if ( ! Settings::notify( 'agent_reply' ) ) {
			return;
		}
		$agent = $ticket['agent_id'] ? get_userdata( $ticket['agent_id'] ) : null;
		$to    = $agent ? array( $agent->user_email ) : self::team_recipients( $ticket );
		$inner = '<p>' . esc_html(
			/* translators: %s: customer name. */
			sprintf( __( '%s replied:', 'dev3-ai-helpdesk' ), $ticket['name'] )
		) . '</p><p>' . self::text_html( $reply['body'] ) . '</p>';
		self::send(
			$to,
			/* translators: %s: subject. */
			$tag . sprintf( __( 'Customer replied: %s', 'dev3-ai-helpdesk' ), $ticket['subject'] ),
			$inner,
			array(
				'ticket' => $ticket,
				'kind'   => 'agent',
				'button' => array(
					'url'   => self::admin_link( $ticket ),
					'label' => __( 'Open ticket', 'dev3-ai-helpdesk' ),
				),
			)
		);
	}

	/**
	 * Resolved → customer email with the rating request.
	 *
	 * @param int    $ticket_id Ticket id.
	 * @param string $old       Old status.
	 * @param string $status    New status.
	 * @param int    $user_id   Actor.
	 */
	public static function on_status( $ticket_id, $old, $status, $user_id ) {
		unset( $old, $user_id );
		if ( 'resolved' !== $status || ! Settings::notify( 'customer_resolved' ) ) {
			return;
		}
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return;
		}
		$inner = '<p>' . esc_html(
			/* translators: %s: ticket number. */
			sprintf( __( 'We marked ticket #%s as resolved. If something is still not right, just reply and we will pick it up again.', 'dev3-ai-helpdesk' ), $ticket['number'] )
		) . '</p>';
		if ( Settings::get( 'csat_enabled', true ) && ! $ticket['rating'] ) {
			$inner .= '<p><strong>' . esc_html__( 'How did we do?', 'dev3-ai-helpdesk' ) . '</strong></p><p>';
			foreach ( array_reverse( Service::rating_labels(), true ) as $value => $label ) {
				$inner .= '<a href="' . esc_url( add_query_arg( 'rate', $value, Portal::ticket_url( $ticket ) ) ) . '" style="display:inline-block;margin:0 8px 8px 0;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;color:#111827;text-decoration:none;">' . esc_html( $label ) . '</a>';
			}
			$inner .= '</p>';
		}
		self::send(
			$ticket['email'],
			/* translators: %s: subject. */
			'[#' . (int) $ticket['number'] . '] ' . sprintf( __( 'Resolved: %s', 'dev3-ai-helpdesk' ), $ticket['subject'] ),
			$inner,
			array(
				'ticket'      => $ticket,
				'marker'      => true,
				'message_id'  => self::new_message_id( $ticket, 0 ),
				'in_reply_to' => $ticket['message_id'],
			)
		);
	}

	/**
	 * Assigned → the agent (unless they assigned themselves).
	 *
	 * @param int $ticket_id Ticket id.
	 * @param int $agent_id  Agent.
	 * @param int $user_id   Actor.
	 */
	public static function on_assigned( $ticket_id, $agent_id, $user_id ) {
		if ( ! Settings::notify( 'agent_assigned' ) || (int) $agent_id === (int) $user_id ) {
			return;
		}
		$ticket = Tickets::get( $ticket_id );
		$agent  = get_userdata( (int) $agent_id );
		if ( ! $ticket || ! $agent ) {
			return;
		}
		self::send(
			$agent->user_email,
			/* translators: %s: subject. */
			'[#' . (int) $ticket['number'] . '] ' . sprintf( __( 'Assigned to you: %s', 'dev3-ai-helpdesk' ), $ticket['subject'] ),
			'<p>' . esc_html(
				/* translators: %s: ticket number. */
				sprintf( __( 'Ticket #%s was assigned to you.', 'dev3-ai-helpdesk' ), $ticket['number'] )
			) . '</p><p><strong>' . esc_html( $ticket['subject'] ) . '</strong></p>',
			array(
				'ticket' => $ticket,
				'kind'   => 'agent',
				'button' => array(
					'url'   => self::admin_link( $ticket ),
					'label' => __( 'Open ticket', 'dev3-ai-helpdesk' ),
				),
			)
		);
	}

	/**
	 * SLA breach → assigned agent (or team) + admin.
	 *
	 * @param int    $ticket_id Ticket id.
	 * @param string $kind      first|resolve.
	 */
	public static function on_breach( $ticket_id, $kind ) {
		if ( ! Settings::notify( 'sla_breach' ) ) {
			return;
		}
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return;
		}
		$agent = $ticket['agent_id'] ? get_userdata( $ticket['agent_id'] ) : null;
		$to    = $agent ? array( $agent->user_email ) : self::team_recipients( $ticket );
		$to[]  = (string) get_option( 'admin_email' );
		$what  = 'first' === $kind ? __( 'first response', 'dev3-ai-helpdesk' ) : __( 'resolution', 'dev3-ai-helpdesk' );
		self::send(
			$to,
			/* translators: 1: SLA target name, 2: subject. */
			'[#' . (int) $ticket['number'] . '] ' . sprintf( __( 'SLA breached (%1$s): %2$s', 'dev3-ai-helpdesk' ), $what, $ticket['subject'] ),
			'<p>' . esc_html(
				/* translators: 1: ticket number, 2: SLA target name. */
				sprintf( __( 'Ticket #%1$s missed its %2$s target.', 'dev3-ai-helpdesk' ), $ticket['number'], $what )
			) . '</p>',
			array(
				'ticket' => $ticket,
				'kind'   => 'agent',
				'button' => array(
					'url'   => self::admin_link( $ticket ),
					'label' => __( 'Open ticket', 'dev3-ai-helpdesk' ),
				),
			)
		);
	}

	/**
	 * Re-sends ticket links to a customer (portal "Lost your link?").
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	public static function send_links( $email ) {
		$tickets = Tickets::for_customer( 0, $email, 20 );
		if ( ! $tickets ) {
			return false;
		}
		$items = '';
		foreach ( $tickets as $ticket ) {
			$labels = Tickets::status_labels();
			$items .= '<li style="margin:0 0 8px;"><a href="' . esc_url( Portal::ticket_url( $ticket ) ) . '">#' . (int) $ticket['number'] . ' ' . esc_html( $ticket['subject'] ) . '</a> · ' . esc_html( $labels[ $ticket['status'] ] ?? $ticket['status'] ) . '</li>';
		}
		return self::send(
			$email,
			__( 'Your support tickets', 'dev3-ai-helpdesk' ),
			'<p>' . esc_html__( 'Here are the links to your support tickets:', 'dev3-ai-helpdesk' ) . '</p><ul style="padding-left:18px;">' . $items . '</ul>',
			array( 'kind' => 'customer' )
		);
	}
}
