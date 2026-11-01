<?php
/**
 * Turns inbound email (IMAP or webhook) into tickets and replies: threading, quote stripping, loop and
 * abuse protection, attachments. Also normalises Mailgun, SendGrid, Postmark, generic JSON and raw MIME.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Mail;

use Dev3\AiHelpdesk\Data\Replies;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Helpdesk\Files;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Roles;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Inbound.
 */
class Inbound {

	const MAX_NEW_PER_HOUR = 10;

	/**
	 * Processes one normalised message.
	 *
	 * @param array  $msg     Message (see Mime_Parser::parse()).
	 * @param string $channel email|webhook.
	 * @return array { action: created|replied|ignored, ticket_id, reason }
	 */
	public static function process( array $msg, $channel = 'email' ) {
		/**
		 * Filters an inbound message before it is processed. Return null to drop it.
		 *
		 * @param array  $msg     Message.
		 * @param string $channel Channel.
		 */
		$msg = apply_filters( 'dev3hd_inbound_message', $msg, $channel );
		if ( ! is_array( $msg ) ) {
			return self::ignored( 'filtered' );
		}
		$from = strtolower( (string) ( $msg['from_email'] ?? '' ) );
		if ( ! is_email( $from ) ) {
			return self::ignored( 'no_sender' );
		}
		$reason = self::loop_reason( $msg );
		if ( $reason ) {
			return self::ignored( $reason );
		}
		if ( Settings::is_blocked( $from ) ) {
			return self::ignored( 'blocked' );
		}
		$message_id = (string) ( $msg['message_id'] ?? '' );
		if ( '' !== $message_id && Replies::seen( $message_id ) ) {
			return self::ignored( 'duplicate' );
		}
		$subject = trim( (string) ( $msg['subject'] ?? '' ) );
		$text    = (string) ( $msg['text'] ?? '' );
		if ( '' === trim( $text ) && '' !== trim( (string) ( $msg['html'] ?? '' ) ) ) {
			$text = Mime_Parser::html_to_text( (string) $msg['html'] );
		}
		$via = 'webhook' === $channel ? 'webhook' : 'email';

		$ticket = self::thread( $subject, $msg );
		if ( $ticket ) {
			$agent    = get_user_by( 'email', $from );
			$is_agent = $agent && Roles::is_agent( (int) $agent->ID ) && Tickets::agent_can_view( $ticket, (int) $agent->ID );
			$can      = $is_agent || strtolower( (string) $ticket['email'] ) === $from;
			$reopen   = 'closed' !== $ticket['status'] || Settings::get( 'reopen_on_reply', true );
			if ( $can && $reopen ) {
				$files = self::store_attachments( (array) ( $msg['attachments'] ?? array() ) );
				$body  = Mime_Parser::strip_quotes( $text );
				$id    = Service::add_reply(
					$ticket['id'],
					array(
						'type'        => 'reply',
						'author'      => $is_agent ? 'agent' : 'customer',
						'user_id'     => $is_agent ? (int) $agent->ID : (int) $ticket['customer_id'],
						'name'        => $is_agent ? $agent->display_name : '',
						'email'       => $from,
						'body'        => $body,
						'attachments' => $files['stored'],
						'via'         => $via,
						'message_id'  => $message_id,
					)
				);
				if ( is_wp_error( $id ) ) {
					foreach ( $files['stored'] as $file ) {
						Files::delete( $file['key'] );
					}
					return self::ignored( 'empty' );
				}
				self::note_rejected( $ticket['id'], $files['rejected'] );
				return array(
					'action'    => 'replied',
					'ticket_id' => (int) $ticket['id'],
					'reason'    => '',
				);
			}
		}

		if ( ! self::within_rate( $from ) ) {
			return self::ignored( 'rate_limited' );
		}
		$files = self::store_attachments( (array) ( $msg['attachments'] ?? array() ) );
		$id    = Service::create_ticket(
			array(
				'subject'     => '' === $subject ? __( '(no subject)', 'dev3-ai-helpdesk' ) : $subject,
				'message'     => $text,
				'email'       => $from,
				'name'        => (string) ( $msg['from_name'] ?? '' ),
				'channel'     => $via,
				'via'         => $via,
				'message_id'  => $message_id,
				'attachments' => $files['stored'],
			)
		);
		if ( is_wp_error( $id ) ) {
			foreach ( $files['stored'] as $file ) {
				Files::delete( $file['key'] );
			}
			return self::ignored( $id->get_error_code() );
		}
		self::note_rejected( $id, $files['rejected'] );
		return array(
			'action'    => 'created',
			'ticket_id' => (int) $id,
			'reason'    => '',
		);
	}

	/**
	 * Ignored result.
	 *
	 * @param string $reason Reason.
	 * @return array
	 */
	private static function ignored( $reason ) {
		return array(
			'action'    => 'ignored',
			'ticket_id' => 0,
			'reason'    => (string) $reason,
		);
	}

	/**
	 * Existing ticket for a message ([#number] token, then In-Reply-To / References).
	 *
	 * @param string $subject Subject.
	 * @param array  $msg     Message.
	 * @return array|null
	 */
	public static function thread( $subject, array $msg ) {
		$number = Tickets::number_from_subject( $subject );
		if ( $number ) {
			$ticket = Tickets::get_by_number( $number );
			if ( $ticket ) {
				return $ticket;
			}
		}
		$ids = array_merge( (array) ( $msg['references'] ?? array() ), array( (string) ( $msg['in_reply_to'] ?? '' ) ) );
		$id  = Replies::ticket_for_message_ids( $ids );
		return $id ? Tickets::get( $id ) : null;
	}

	/**
	 * Why a message looks automatic or looped ('' when it is fine).
	 *
	 * @param array $msg Message.
	 * @return string
	 */
	public static function loop_reason( array $msg ) {
		$h    = array_change_key_case( (array) ( $msg['headers'] ?? array() ), CASE_LOWER );
		$from = strtolower( (string) ( $msg['from_email'] ?? '' ) );
		$auto = strtolower( trim( (string) ( $h['auto-submitted'] ?? '' ) ) );
		if ( '' !== $auto && 'no' !== $auto ) {
			return 'auto_submitted';
		}
		if ( in_array( strtolower( trim( (string) ( $h['precedence'] ?? '' ) ) ), array( 'bulk', 'junk', 'list', 'auto_reply' ), true ) ) {
			return 'bulk';
		}
		if ( isset( $h['x-autoreply'] ) || isset( $h['x-autorespond'] ) || isset( $h['x-dev3hd-ticket'] ) ) {
			return 'auto_reply';
		}
		$local = strtok( $from, '@' );
		if ( in_array( $local, array( 'mailer-daemon', 'postmaster', 'noreply', 'no-reply', 'donotreply', 'do-not-reply' ), true ) ) {
			return 'system_sender';
		}
		$own = array_filter( array( strtolower( Mailer::from_email() ), strtolower( (string) Settings::get( 'imap_user', '' ) ) ) );
		if ( in_array( $from, $own, true ) ) {
			return 'own_address';
		}
		return '';
	}

	/**
	 * Per-sender limit on new tickets (10 per hour).
	 *
	 * @param string $email Sender.
	 * @return bool
	 */
	private static function within_rate( $email ) {
		$key   = 'dev3hd_in_' . md5( $email );
		$count = (int) get_transient( $key );
		/**
		 * Max new tickets per sender per hour from email.
		 *
		 * @param int $limit Limit.
		 */
		if ( $count >= (int) apply_filters( 'dev3hd_inbound_hourly_limit', self::MAX_NEW_PER_HOUR ) ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Stores email attachments (within the count/type/size limits).
	 *
	 * @param array $attachments [{ name, mime, content }].
	 * @return array { stored, rejected (names) }
	 */
	public static function store_attachments( array $attachments ) {
		$stored   = array();
		$rejected = array();
		$max      = Settings::get( 'allow_attachments', true ) ? max( 1, (int) Settings::get( 'max_files', 3 ) ) : 0;
		foreach ( $attachments as $att ) {
			$name = sanitize_file_name( (string) ( $att['name'] ?? 'attachment' ) );
			if ( count( $stored ) >= $max || '' === (string) ( $att['content'] ?? '' ) ) {
				$rejected[] = $name;
				continue;
			}
			$res = Files::store_bytes( (string) $att['content'], $name );
			if ( is_wp_error( $res ) ) {
				$rejected[] = $name;
				continue;
			}
			$stored[] = $res;
		}
		return array(
			'stored'   => $stored,
			'rejected' => $rejected,
		);
	}

	/**
	 * Internal note about attachments that were not accepted.
	 *
	 * @param int   $ticket_id Ticket id.
	 * @param array $names     File names.
	 */
	private static function note_rejected( $ticket_id, array $names ) {
		if ( $names ) {
			/* translators: %s: comma-separated file names. */
			Service::event( $ticket_id, sprintf( __( 'Email attachments not accepted (type, size or count limit): %s', 'dev3-ai-helpdesk' ), implode( ', ', array_slice( $names, 0, 10 ) ) ) );
		}
	}

	/**
	 * Normalises a webhook request (auto-detects the provider).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|null Message or null when it cannot be read.
	 */
	public static function from_request( \WP_REST_Request $request ) {
		$ct   = $request->get_content_type();
		$type = strtolower( (string) ( $ct['value'] ?? '' ) );
		if ( 'message/rfc822' === $type || 'text/plain' === $type ) {
			return Mime_Parser::parse( (string) $request->get_body() );
		}
		$json   = $request->get_json_params();
		$params = is_array( $json ) && $json ? $json : (array) $request->get_body_params();
		$files  = Files::normalize( (array) $request->get_file_params() );
		foreach ( array( 'raw', 'mime', 'email' ) as $raw_key ) {
			if ( ! empty( $params[ $raw_key ] ) && is_string( $params[ $raw_key ] ) && false !== strpos( $params[ $raw_key ], "\n" ) && preg_match( '/^[A-Za-z-]+:/', ltrim( $params[ $raw_key ] ) ) ) {
				return Mime_Parser::parse( $params[ $raw_key ] );
			}
		}
		if ( isset( $params['FromFull'] ) || isset( $params['TextBody'] ) ) {
			return self::from_postmark( $params );
		}
		if ( isset( $params['body-plain'] ) || isset( $params['stripped-text'] ) || isset( $params['message-headers'] ) ) {
			return self::from_mailgun( $params, $files );
		}
		if ( isset( $params['envelope'] ) || isset( $params['charsets'] ) ) {
			return self::from_sendgrid( $params, $files );
		}
		return self::from_generic( $params );
	}

	/**
	 * Generic JSON: { from, from_name, subject, text, html, message_id, in_reply_to, references, headers, attachments[{filename,content(base64),content_type}] }.
	 *
	 * @param array $p Params.
	 * @return array
	 */
	public static function from_generic( array $p ) {
		$from = Mime_Parser::address( (string) ( $p['from'] ?? '' ) );
		$atts = array();
		foreach ( (array) ( $p['attachments'] ?? array() ) as $att ) {
			if ( is_array( $att ) && isset( $att['content'] ) ) {
				$atts[] = array(
					'name'    => (string) ( $att['filename'] ?? ( $att['name'] ?? 'attachment' ) ),
					'mime'    => (string) ( $att['content_type'] ?? ( $att['mime'] ?? '' ) ),
					'content' => (string) base64_decode( (string) $att['content'], false ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				);
			}
		}
		$refs = $p['references'] ?? array();
		return array(
			'from_email'  => $from['email'],
			'from_name'   => (string) ( $p['from_name'] ?? $from['name'] ),
			'to'          => (string) ( $p['to'] ?? '' ),
			'subject'     => (string) ( $p['subject'] ?? '' ),
			'text'        => (string) ( $p['text'] ?? '' ),
			'html'        => (string) ( $p['html'] ?? '' ),
			'message_id'  => Mime_Parser::message_id( (string) ( $p['message_id'] ?? '' ) ),
			'in_reply_to' => Mime_Parser::message_id( (string) ( $p['in_reply_to'] ?? '' ) ),
			'references'  => is_array( $refs ) ? array_map( array( Mime_Parser::class, 'message_id' ), array_map( 'strval', $refs ) ) : Mime_Parser::message_ids( (string) $refs ),
			'headers'     => array_change_key_case( (array) ( $p['headers'] ?? array() ), CASE_LOWER ),
			'attachments' => $atts,
		);
	}

	/**
	 * Mailgun route (forward) payload.
	 *
	 * @param array $p     Params.
	 * @param array $files Uploaded attachments.
	 * @return array
	 */
	public static function from_mailgun( array $p, array $files ) {
		$headers = array();
		$list    = json_decode( (string) ( $p['message-headers'] ?? '[]' ), true );
		foreach ( (array) $list as $pair ) {
			if ( is_array( $pair ) && 2 === count( $pair ) && ! isset( $headers[ strtolower( $pair[0] ) ] ) ) {
				$headers[ strtolower( (string) $pair[0] ) ] = (string) $pair[1];
			}
		}
		$from = Mime_Parser::address( (string) ( $p['from'] ?? ( $p['sender'] ?? '' ) ) );
		return array(
			'from_email'  => $from['email'],
			'from_name'   => $from['name'],
			'to'          => (string) ( $p['recipient'] ?? '' ),
			'subject'     => (string) ( $p['subject'] ?? '' ),
			'text'        => (string) ( $p['body-plain'] ?? '' ),
			'html'        => (string) ( $p['body-html'] ?? '' ),
			'message_id'  => Mime_Parser::message_id( (string) ( $p['Message-Id'] ?? ( $headers['message-id'] ?? '' ) ) ),
			'in_reply_to' => Mime_Parser::message_id( (string) ( $p['In-Reply-To'] ?? ( $headers['in-reply-to'] ?? '' ) ) ),
			'references'  => Mime_Parser::message_ids( (string) ( $p['References'] ?? ( $headers['references'] ?? '' ) ) ),
			'headers'     => $headers,
			'attachments' => self::uploaded( $files ),
		);
	}

	/**
	 * SendGrid Inbound Parse payload (raw mode is handled as MIME).
	 *
	 * @param array $p     Params.
	 * @param array $files Uploaded attachments.
	 * @return array
	 */
	public static function from_sendgrid( array $p, array $files ) {
		$headers = Mime_Parser::headers( str_replace( "\r\n", "\n", (string) ( $p['headers'] ?? '' ) ) );
		$from    = Mime_Parser::address( (string) ( $p['from'] ?? ( $headers['from'] ?? '' ) ) );
		return array(
			'from_email'  => $from['email'],
			'from_name'   => $from['name'],
			'to'          => (string) ( $p['to'] ?? '' ),
			'subject'     => (string) ( $p['subject'] ?? Mime_Parser::decode_header( $headers['subject'] ?? '' ) ),
			'text'        => (string) ( $p['text'] ?? '' ),
			'html'        => (string) ( $p['html'] ?? '' ),
			'message_id'  => Mime_Parser::message_id( $headers['message-id'] ?? '' ),
			'in_reply_to' => Mime_Parser::message_id( $headers['in-reply-to'] ?? '' ),
			'references'  => Mime_Parser::message_ids( $headers['references'] ?? '' ),
			'headers'     => $headers,
			'attachments' => self::uploaded( $files ),
		);
	}

	/**
	 * Postmark inbound JSON.
	 *
	 * @param array $p Payload.
	 * @return array
	 */
	public static function from_postmark( array $p ) {
		$headers = array();
		foreach ( (array) ( $p['Headers'] ?? array() ) as $h ) {
			if ( isset( $h['Name'] ) && ! isset( $headers[ strtolower( (string) $h['Name'] ) ] ) ) {
				$headers[ strtolower( (string) $h['Name'] ) ] = (string) ( $h['Value'] ?? '' );
			}
		}
		$full  = (array) ( $p['FromFull'] ?? array() );
		$email = isset( $full['Email'] ) ? (string) $full['Email'] : Mime_Parser::address( (string) ( $p['From'] ?? '' ) )['email'];
		$atts  = array();
		foreach ( (array) ( $p['Attachments'] ?? array() ) as $att ) {
			$atts[] = array(
				'name'    => (string) ( $att['Name'] ?? 'attachment' ),
				'mime'    => (string) ( $att['ContentType'] ?? '' ),
				'content' => (string) base64_decode( (string) ( $att['Content'] ?? '' ), false ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			);
		}
		$email = sanitize_email( $email );
		return array(
			'from_email'  => is_email( $email ) ? strtolower( $email ) : '',
			'from_name'   => sanitize_text_field( (string) ( $full['Name'] ?? ( $p['FromName'] ?? '' ) ) ),
			'to'          => (string) ( $p['To'] ?? '' ),
			'subject'     => (string) ( $p['Subject'] ?? '' ),
			'text'        => (string) ( $p['TextBody'] ?? '' ),
			'html'        => (string) ( $p['HtmlBody'] ?? '' ),
			'message_id'  => Mime_Parser::message_id( $headers['message-id'] ?? (string) ( $p['MessageID'] ?? '' ) ),
			'in_reply_to' => Mime_Parser::message_id( $headers['in-reply-to'] ?? '' ),
			'references'  => Mime_Parser::message_ids( $headers['references'] ?? '' ),
			'headers'     => $headers,
			'attachments' => $atts,
		);
	}

	/**
	 * Reads uploaded attachment files.
	 *
	 * @param array $files Normalised uploads.
	 * @return array
	 */
	private static function uploaded( array $files ) {
		$out = array();
		foreach ( array_slice( $files, 0, 10 ) as $file ) {
			$tmp = (string) ( $file['tmp_name'] ?? '' );
			if ( '' === $tmp || ! is_readable( $tmp ) || ( ! is_uploaded_file( $tmp ) && ! apply_filters( 'dev3hd_allow_local_files', false, $tmp ) ) ) {
				continue;
			}
			$out[] = array(
				'name'    => (string) ( $file['name'] ?? 'attachment' ),
				'mime'    => (string) ( $file['type'] ?? '' ),
				'content' => (string) file_get_contents( $tmp ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);
		}
		return $out;
	}
}
