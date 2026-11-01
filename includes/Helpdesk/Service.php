<?php
/**
 * Ticket business logic: create, reply, notes, status, properties, ratings. Every change is logged in the
 * ticket timeline and fires an action (emails and AI jobs hook in there).
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Helpdesk;

use Dev3\AiHelpdesk\Data\Replies;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Roles;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Service.
 */
class Service {

	const MAX_TEXT = 20000;

	/**
	 * Plain-text clean-up for message bodies (stored as text, escaped on every output).
	 *
	 * @param string $text Text.
	 * @param int    $max  Max length.
	 * @return string
	 */
	public static function clean_text( $text, $max = self::MAX_TEXT ) {
		$text = wp_check_invalid_utf8( (string) $text, true );
		$text = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), $text );
		$text = preg_replace( "/\n{4,}/", "\n\n\n", $text );
		return trim( mb_substr( (string) $text, 0, $max ) );
	}

	/**
	 * Creates a ticket with its first message.
	 *
	 * @param array $data  subject, message, email, name, category, priority, channel, customer_id, order_id,
	 *                     department, agent_id, message_id, via, attachments (already stored), status.
	 * @param array $files Uploaded files (`$_FILES`-style list).
	 * @return int|\WP_Error Ticket id.
	 */
	public static function create_ticket( array $data, array $files = array() ) {
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'dev3hd_email', __( 'Enter a valid email address.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		if ( Settings::is_blocked( $email ) ) {
			return new \WP_Error( 'dev3hd_blocked', __( 'We could not accept this request.', 'dev3-ai-helpdesk' ), array( 'status' => 403 ) );
		}
		$subject = mb_substr( sanitize_text_field( (string) ( $data['subject'] ?? '' ) ), 0, 190 );
		$message = self::clean_text( (string) ( $data['message'] ?? '' ) );
		$stored  = (array) ( $data['attachments'] ?? array() );
		if ( '' === $subject ) {
			return new \WP_Error( 'dev3hd_subject', __( 'Add a subject.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		if ( '' === $message && ! $stored && ! $files ) {
			return new \WP_Error( 'dev3hd_message', __( 'Describe how we can help.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$customer = (int) ( $data['customer_id'] ?? 0 );
		if ( ! $customer ) {
			$user     = get_user_by( 'email', $email );
			$customer = $user ? (int) $user->ID : 0;
		}
		$name = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 120 );
		if ( '' === $name ) {
			$user = $customer ? get_userdata( $customer ) : null;
			$name = $user ? $user->display_name : ucwords( str_replace( array( '.', '_', '-' ), ' ', strtok( $email, '@' ) ) );
		}
		$category = sanitize_key( (string) ( $data['category'] ?? '' ) );
		$category = in_array( $category, Settings::category_ids(), true ) ? $category : '';
		$dept     = sanitize_key( (string) ( $data['department'] ?? '' ) );
		$dept     = in_array( $dept, Settings::department_ids(), true ) ? $dept : Settings::category_department( $category );
		$priority = in_array( $data['priority'] ?? '', Settings::PRIORITIES, true ) ? $data['priority'] : 'normal';
		$channel  = in_array( $data['channel'] ?? '', Tickets::CHANNELS, true ) ? $data['channel'] : 'portal';
		$agent    = (int) ( $data['agent_id'] ?? 0 );
		$agent    = $agent && Roles::is_agent( $agent ) ? $agent : Routing::pick_agent( $dept );

		if ( $files ) {
			$uploaded = Files::store_many( $files );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}
			$stored = array_merge( $stored, $uploaded );
		}
		$now = time();
		$row = array_merge(
			array(
				'subject'     => $subject,
				'status'      => in_array( $data['status'] ?? '', Tickets::STATUSES, true ) ? $data['status'] : 'new',
				'priority'    => $priority,
				'department'  => $dept,
				'category'    => $category,
				'agent_id'    => $agent,
				'customer_id' => $customer,
				'email'       => strtolower( $email ),
				'name'        => $name,
				'channel'     => $channel,
				'order_id'    => (int) ( $data['order_id'] ?? 0 ),
				'message_id'  => mb_substr( (string) ( $data['message_id'] ?? '' ), 0, 255 ),
			),
			Sla::dues( $priority, $now )
		);
		/**
		 * Filters a new ticket row before it is saved.
		 *
		 * @param array $row  Columns.
		 * @param array $data Input.
		 */
		$row = (array) apply_filters( 'dev3hd_new_ticket', $row, $data );
		$id  = Tickets::insert( $row );
		if ( is_wp_error( $id ) ) {
			foreach ( $stored as $file ) {
				Files::delete( $file['key'] ?? '' );
			}
			return $id;
		}
		$reply_id = Replies::add(
			$id,
			array(
				'type'        => 'reply',
				'author'      => 'customer',
				'user_id'     => $customer,
				'name'        => $name,
				'email'       => $email,
				'body'        => $message,
				'attachments' => $stored,
				'via'         => (string) ( $data['via'] ?? ( in_array( $channel, array( 'email', 'webhook' ), true ) ? 'email' : 'web' ) ),
				'message_id'  => (string) ( $data['message_id'] ?? '' ),
			)
		);
		$labels   = Tickets::channel_labels();
		$by       = (int) ( $data['created_by'] ?? 0 );
		if ( 'agent' === $channel && $by ) {
			$user = get_userdata( $by );
			/* translators: %s: agent name. */
			self::event( $id, sprintf( __( 'Ticket opened by %s on behalf of the customer.', 'dev3-ai-helpdesk' ), $user ? $user->display_name : '' ), $by );
		} else {
			/* translators: %s: channel, e.g. "Email". */
			self::event( $id, sprintf( __( 'Ticket received via %s.', 'dev3-ai-helpdesk' ), $labels[ $channel ] ?? $channel ) );
		}
		if ( $agent ) {
			$user = get_userdata( $agent );
			/* translators: %s: agent name. */
			self::event( $id, sprintf( __( 'Auto-assigned to %s.', 'dev3-ai-helpdesk' ), $user ? $user->display_name : '#' . $agent ) );
		}
		/**
		 * Fires after a ticket and its first message were saved.
		 *
		 * @param int $ticket_id Ticket id.
		 * @param int $reply_id  First message id.
		 */
		do_action( 'dev3hd_ticket_created', $id, $reply_id );
		return $id;
	}

	/**
	 * Adds a reply or an internal note.
	 *
	 * @param int   $ticket_id Ticket id.
	 * @param array $data      body, type (reply|note), author (customer|agent|ai), user_id, name, email, via,
	 *                         message_id, status (agents), attachments (already stored), notify (bool).
	 * @param array $files     Uploaded files.
	 * @return int|\WP_Error Reply id.
	 */
	public static function add_reply( $ticket_id, array $data, array $files = array() ) {
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return new \WP_Error( 'dev3hd_not_found', __( 'Ticket not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) );
		}
		$type   = 'note' === ( $data['type'] ?? '' ) ? 'note' : 'reply';
		$author = in_array( $data['author'] ?? '', array( 'customer', 'agent', 'ai' ), true ) ? $data['author'] : 'customer';
		if ( 'customer' === $author ) {
			$type = 'reply';
			if ( 'closed' === $ticket['status'] && ! Settings::get( 'reopen_on_reply', true ) ) {
				return new \WP_Error( 'dev3hd_closed', __( 'This ticket is closed. Please open a new ticket.', 'dev3-ai-helpdesk' ), array( 'status' => 409 ) );
			}
		}
		$body   = self::clean_text( (string) ( $data['body'] ?? '' ) );
		$stored = (array) ( $data['attachments'] ?? array() );
		if ( $files ) {
			$uploaded = Files::store_many( $files );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}
			$stored = array_merge( $stored, $uploaded );
		}
		if ( '' === $body && ! $stored ) {
			return new \WP_Error( 'dev3hd_message', __( 'Write a message first.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$user_id = (int) ( $data['user_id'] ?? 0 );
		$name    = (string) ( $data['name'] ?? '' );
		if ( '' === $name ) {
			$user = $user_id ? get_userdata( $user_id ) : null;
			$name = 'customer' === $author ? (string) $ticket['name'] : ( $user ? $user->display_name : (string) Settings::get( 'ai_agent_name', 'Support assistant' ) );
		}
		$reply_id = Replies::add(
			$ticket_id,
			array(
				'type'        => $type,
				'author'      => $author,
				'user_id'     => $user_id,
				'name'        => $name,
				'email'       => 'customer' === $author ? (string) ( $data['email'] ?? $ticket['email'] ) : '',
				'body'        => $body,
				'attachments' => $stored,
				'via'         => (string) ( $data['via'] ?? 'web' ),
				'message_id'  => (string) ( $data['message_id'] ?? '' ),
			)
		);
		$patch    = array();
		$next     = '';
		if ( 'reply' === $type ) {
			$patch['last_reply_at'] = Tickets::now();
			$patch['last_reply_by'] = 'customer' === $author ? 'customer' : 'agent';
			if ( 'customer' === $author ) {
				if ( 'pending' === $ticket['status'] || 'on_hold' === $ticket['status'] ) {
					$next = 'open';
				} elseif ( in_array( $ticket['status'], array( 'resolved', 'closed' ), true ) ) {
					$next = Settings::get( 'reopen_on_reply', true ) ? 'open' : '';
				}
			} else {
				if ( empty( $ticket['first_response_at'] ) ) {
					$patch['first_response_at'] = Tickets::now();
				}
				$requested = (string) ( $data['status'] ?? '' );
				$next      = in_array( $requested, Tickets::STATUSES, true ) ? $requested : ( 'new' === $ticket['status'] ? 'open' : '' );
				if ( ! $ticket['agent_id'] && 'agent' === $author && $user_id && Roles::is_agent( $user_id ) ) {
					$patch['agent_id'] = $user_id; // The first agent to reply takes the ticket.
				}
			}
		} elseif ( isset( $data['status'] ) && in_array( $data['status'], Tickets::STATUSES, true ) ) {
			$next = (string) $data['status'];
		}
		if ( $patch ) {
			Tickets::update( $ticket_id, $patch );
		}
		if ( $next && $next !== $ticket['status'] ) {
			self::set_status( $ticket_id, $next, 'customer' === $author ? 0 : $user_id, 'customer' === $author ? 'customer' : 'agent' );
		} else {
			Tickets::update( $ticket_id, array() );
		}
		/**
		 * Fires after a reply or note was added.
		 *
		 * @param int  $ticket_id Ticket id.
		 * @param int  $reply_id  Reply id.
		 * @param bool $notify    Whether emails should go out.
		 */
		do_action( 'dev3hd_reply_added', $ticket_id, $reply_id, ! isset( $data['notify'] ) || (bool) $data['notify'] );
		return $reply_id;
	}

	/**
	 * Changes the status (SLA pause/resume, resolved/closed times, timeline, action).
	 *
	 * @param int    $ticket_id Ticket id.
	 * @param string $status    New status.
	 * @param int    $user_id   Actor (0 = system/customer).
	 * @param string $actor     system|agent|customer|ai.
	 * @return bool|\WP_Error
	 */
	public static function set_status( $ticket_id, $status, $user_id = 0, $actor = 'system' ) {
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return new \WP_Error( 'dev3hd_not_found', __( 'Ticket not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $status, Tickets::STATUSES, true ) ) {
			return new \WP_Error( 'dev3hd_status', __( 'Unknown status.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$old = $ticket['status'];
		if ( $old === $status ) {
			return true;
		}
		$patch = array( 'status' => $status );
		if ( 'pending' === $status ) {
			$patch = array_merge( $patch, Sla::pause( $ticket ) );
		} elseif ( 'pending' === $old ) {
			$patch = array_merge( $patch, Sla::resume( $ticket ) );
		}
		if ( 'resolved' === $status ) {
			$patch['resolved_at'] = Tickets::now();
		} elseif ( 'closed' === $status ) {
			$patch['closed_at'] = Tickets::now();
			if ( empty( $ticket['resolved_at'] ) ) {
				$patch['resolved_at'] = Tickets::now();
			}
		} elseif ( in_array( $old, array( 'resolved', 'closed' ), true ) ) {
			$patch['resolved_at'] = null;
			$patch['closed_at']   = null;
		}
		Tickets::update( $ticket_id, $patch );
		$labels = Tickets::status_labels();
		$who    = '';
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			$who  = $user ? $user->display_name : '';
		} elseif ( 'customer' === $actor ) {
			$who = __( 'the customer', 'dev3-ai-helpdesk' );
		}
		self::event(
			$ticket_id,
			'' === $who
				/* translators: 1: old status, 2: new status. */
				? sprintf( __( 'Status changed from %1$s to %2$s.', 'dev3-ai-helpdesk' ), $labels[ $old ] ?? $old, $labels[ $status ] ?? $status )
				/* translators: 1: old status, 2: new status, 3: person. */
				: sprintf( __( 'Status changed from %1$s to %2$s by %3$s.', 'dev3-ai-helpdesk' ), $labels[ $old ] ?? $old, $labels[ $status ] ?? $status, $who ),
			$user_id
		);
		/**
		 * Fires after a status change.
		 *
		 * @param int    $ticket_id Ticket id.
		 * @param string $old       Old status.
		 * @param string $status    New status.
		 * @param int    $user_id   Actor.
		 */
		do_action( 'dev3hd_status_changed', $ticket_id, $old, $status, $user_id );
		return true;
	}

	/**
	 * Updates properties (priority, agent, department, category, tags, subject, order, status).
	 *
	 * @param int   $ticket_id Ticket id.
	 * @param array $props     Props.
	 * @param int   $user_id   Actor.
	 * @return array|\WP_Error Updated ticket.
	 */
	public static function update_props( $ticket_id, array $props, $user_id = 0 ) {
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return new \WP_Error( 'dev3hd_not_found', __( 'Ticket not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) );
		}
		$patch  = array();
		$events = array();
		$labels = Tickets::priority_labels();
		if ( isset( $props['priority'] ) && in_array( $props['priority'], Settings::PRIORITIES, true ) && $props['priority'] !== $ticket['priority'] ) {
			$patch['priority'] = $props['priority'];
			$dues              = Sla::dues( $props['priority'], (int) strtotime( $ticket['created_at'] . ' UTC' ) );
			if ( ! empty( $ticket['first_response_at'] ) ) {
				unset( $dues['sla_first_due'] );
			}
			$patch                 = array_merge( $patch, $dues );
			$patch['sla_breached'] = 0;
			/* translators: 1: old priority, 2: new priority. */
			$events[] = sprintf( __( 'Priority changed from %1$s to %2$s.', 'dev3-ai-helpdesk' ), $labels[ $ticket['priority'] ] ?? $ticket['priority'], $labels[ $props['priority'] ] );
		}
		if ( isset( $props['department'] ) ) {
			$dept = sanitize_key( (string) $props['department'] );
			if ( in_array( $dept, Settings::department_ids(), true ) && $dept !== $ticket['department'] ) {
				$patch['department'] = $dept;
				/* translators: %s: department. */
				$events[] = sprintf( __( 'Moved to %s.', 'dev3-ai-helpdesk' ), Settings::department_name( $dept ) );
			}
		}
		if ( isset( $props['category'] ) ) {
			$cat = sanitize_key( (string) $props['category'] );
			if ( ( '' === $cat || in_array( $cat, Settings::category_ids(), true ) ) && $cat !== $ticket['category'] ) {
				$patch['category'] = $cat;
				/* translators: %s: category. */
				$events[] = sprintf( __( 'Category set to %s.', 'dev3-ai-helpdesk' ), '' === $cat ? __( 'none', 'dev3-ai-helpdesk' ) : Settings::category_name( $cat ) );
			}
		}
		$assigned = 0;
		if ( isset( $props['agent_id'] ) ) {
			$agent = (int) $props['agent_id'];
			if ( $agent !== (int) $ticket['agent_id'] && ( 0 === $agent || Roles::is_agent( $agent ) ) ) {
				$patch['agent_id'] = $agent;
				$user              = $agent ? get_userdata( $agent ) : null;
				/* translators: %s: agent name. */
				$events[] = $agent ? sprintf( __( 'Assigned to %s.', 'dev3-ai-helpdesk' ), $user ? $user->display_name : '#' . $agent ) : __( 'Unassigned.', 'dev3-ai-helpdesk' );
				$assigned = $agent;
			}
		}
		if ( isset( $props['tags'] ) ) {
			$tags          = is_array( $props['tags'] ) ? $props['tags'] : explode( ',', (string) $props['tags'] );
			$tags          = array_slice( array_values( array_unique( array_filter( array_map( static fn( $t ) => mb_substr( sanitize_text_field( trim( (string) $t ) ), 0, 30 ), $tags ) ) ) ), 0, 10 );
			$patch['tags'] = mb_substr( implode( ',', $tags ), 0, 255 );
		}
		if ( isset( $props['subject'] ) ) {
			$subject = mb_substr( sanitize_text_field( (string) $props['subject'] ), 0, 190 );
			if ( '' !== $subject ) {
				$patch['subject'] = $subject;
			}
		}
		if ( isset( $props['sentiment'] ) && in_array( $props['sentiment'], Tickets::SENTIMENTS, true ) ) {
			$patch['sentiment'] = $props['sentiment'];
		}
		if ( isset( $props['order_id'] ) ) {
			$patch['order_id'] = max( 0, (int) $props['order_id'] );
		}
		if ( $patch ) {
			Tickets::update( $ticket_id, $patch );
		}
		foreach ( $events as $message ) {
			self::event( $ticket_id, $message, $user_id, (string) ( $props['_actor'] ?? 'system' ) );
		}
		if ( $assigned ) {
			/**
			 * Fires when a ticket is assigned to an agent.
			 *
			 * @param int $ticket_id Ticket id.
			 * @param int $agent_id  Agent.
			 * @param int $user_id   Actor.
			 */
			do_action( 'dev3hd_ticket_assigned', $ticket_id, $assigned, $user_id );
		}
		if ( isset( $props['status'] ) && $props['status'] !== $ticket['status'] ) {
			$res = self::set_status( $ticket_id, (string) $props['status'], $user_id, 'agent' );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		return Tickets::get( $ticket_id );
	}

	/**
	 * Customer satisfaction rating (1 = not good, 2 = okay, 3 = great).
	 *
	 * @param int    $ticket_id Ticket id.
	 * @param int    $rating    Rating.
	 * @param string $comment   Comment.
	 * @return bool|\WP_Error
	 */
	public static function rate( $ticket_id, $rating, $comment = '' ) {
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return new \WP_Error( 'dev3hd_not_found', __( 'Ticket not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $ticket['status'], array( 'resolved', 'closed' ), true ) ) {
			return new \WP_Error( 'dev3hd_not_resolved', __( 'You can rate a ticket once it is resolved.', 'dev3-ai-helpdesk' ), array( 'status' => 409 ) );
		}
		$rating = (int) $rating;
		if ( $rating < 1 || $rating > 3 ) {
			return new \WP_Error( 'dev3hd_rating', __( 'Choose a rating.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		Tickets::update(
			$ticket_id,
			array(
				'rating'         => $rating,
				'rating_comment' => mb_substr( sanitize_textarea_field( (string) $comment ), 0, 1000 ),
				'rated_at'       => Tickets::now(),
			)
		);
		$labels = self::rating_labels();
		/* translators: %s: rating label. */
		self::event( $ticket_id, sprintf( __( 'Customer rated the support: %s.', 'dev3-ai-helpdesk' ), $labels[ $rating ] ), 0, 'customer' );
		/**
		 * Fires after a CSAT rating.
		 *
		 * @param int $ticket_id Ticket id.
		 * @param int $rating    1–3.
		 */
		do_action( 'dev3hd_ticket_rated', $ticket_id, $rating );
		return true;
	}

	/**
	 * Rating labels.
	 *
	 * @return array
	 */
	public static function rating_labels() {
		return array(
			1 => __( 'Not good', 'dev3-ai-helpdesk' ),
			2 => __( 'Okay', 'dev3-ai-helpdesk' ),
			3 => __( 'Great', 'dev3-ai-helpdesk' ),
		);
	}

	/**
	 * Timeline event.
	 *
	 * @param int    $ticket_id Ticket id.
	 * @param string $message   Message.
	 * @param int    $user_id   Actor.
	 * @param string $author    system|ai|customer|agent.
	 * @return int
	 */
	public static function event( $ticket_id, $message, $user_id = 0, $author = 'system' ) {
		$user = $user_id ? get_userdata( $user_id ) : null;
		return Replies::add(
			$ticket_id,
			array(
				'type'    => 'event',
				'author'  => in_array( $author, array( 'ai', 'customer', 'agent' ), true ) ? $author : 'system',
				'user_id' => (int) $user_id,
				'name'    => $user ? $user->display_name : '',
				'body'    => (string) $message,
			)
		);
	}
}
