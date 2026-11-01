<?php
/**
 * Customer-facing strings for the front-end app (neutral, translatable, filterable).
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Strings.
 */
class Strings {

	/**
	 * All strings.
	 *
	 * @return array
	 */
	public static function all() {
		$s = array(
			'newTicket'      => __( 'New ticket', 'dev3-ai-helpdesk' ),
			'myTickets'      => __( 'My tickets', 'dev3-ai-helpdesk' ),
			'contactTitle'   => __( 'Contact support', 'dev3-ai-helpdesk' ),
			'contactIntro'   => __( 'Tell us what is going on and we will get back to you by email.', 'dev3-ai-helpdesk' ),
			'name'           => __( 'Your name', 'dev3-ai-helpdesk' ),
			'email'          => __( 'Email address', 'dev3-ai-helpdesk' ),
			'subject'        => __( 'Subject', 'dev3-ai-helpdesk' ),
			'category'       => __( 'Topic', 'dev3-ai-helpdesk' ),
			'chooseCategory' => __( 'Choose a topic', 'dev3-ai-helpdesk' ),
			'priority'       => __( 'Priority', 'dev3-ai-helpdesk' ),
			'order'          => __( 'Order number (optional)', 'dev3-ai-helpdesk' ),
			'message'        => __( 'How can we help?', 'dev3-ai-helpdesk' ),
			'messageHint'    => __( 'Include any steps, error messages or links that help us understand.', 'dev3-ai-helpdesk' ),
			'attach'         => __( 'Attach files', 'dev3-ai-helpdesk' ),
			/* translators: 1: max files, 2: max MB. */
			'attachHint'     => __( 'Up to %1$d files, %2$d MB each.', 'dev3-ai-helpdesk' ),
			'remove'         => __( 'Remove', 'dev3-ai-helpdesk' ),
			'submit'         => __( 'Send request', 'dev3-ai-helpdesk' ),
			'sending'        => __( 'Sending…', 'dev3-ai-helpdesk' ),
			'required'       => __( 'Please fill in this field.', 'dev3-ai-helpdesk' ),
			'invalidEmail'   => __( 'Enter a valid email address.', 'dev3-ai-helpdesk' ),
			'suggestTitle'   => __( 'These articles might answer your question', 'dev3-ai-helpdesk' ),
			/* translators: %s: ticket number. */
			'createdTitle'   => __( 'Ticket #%s is open', 'dev3-ai-helpdesk' ),
			'createdText'    => __( 'Thanks! We emailed you a confirmation with a private link to this ticket. We will reply as soon as we can.', 'dev3-ai-helpdesk' ),
			'viewTicket'     => __( 'View ticket', 'dev3-ai-helpdesk' ),
			'another'        => __( 'Open another ticket', 'dev3-ai-helpdesk' ),
			'lostLink'       => __( 'Already contacted us?', 'dev3-ai-helpdesk' ),
			'lostLinkText'   => __( 'Enter your email and we will send you the links to your tickets.', 'dev3-ai-helpdesk' ),
			'sendLinks'      => __( 'Email my links', 'dev3-ai-helpdesk' ),
			'linksSent'      => __( 'If we have tickets for that address, the links are on their way.', 'dev3-ai-helpdesk' ),
			'login'          => __( 'Log in', 'dev3-ai-helpdesk' ),
			'loginRequired'  => __( 'Please log in to contact support.', 'dev3-ai-helpdesk' ),
			'loginHint'      => __( 'Have an account? Log in to see all your tickets.', 'dev3-ai-helpdesk' ),
			'noTickets'      => __( 'You have no tickets yet.', 'dev3-ai-helpdesk' ),
			'updated'        => __( 'Updated', 'dev3-ai-helpdesk' ),
			'awaiting'       => __( 'Waiting for your reply', 'dev3-ai-helpdesk' ),
			'back'           => __( 'Back', 'dev3-ai-helpdesk' ),
			'you'            => __( 'You', 'dev3-ai-helpdesk' ),
			'reply'          => __( 'Your reply', 'dev3-ai-helpdesk' ),
			'sendReply'      => __( 'Send reply', 'dev3-ai-helpdesk' ),
			'closeTicket'    => __( 'My issue is solved', 'dev3-ai-helpdesk' ),
			'confirmClose'   => __( 'Mark this ticket as solved?', 'dev3-ai-helpdesk' ),
			'closedNote'     => __( 'This ticket is closed. Open a new ticket if you need more help.', 'dev3-ai-helpdesk' ),
			'resolvedNote'   => __( 'This ticket is resolved. Reply below if you still need help.', 'dev3-ai-helpdesk' ),
			'rateTitle'      => __( 'How did we do?', 'dev3-ai-helpdesk' ),
			'rateComment'    => __( 'Anything we could do better? (optional)', 'dev3-ai-helpdesk' ),
			'rateSubmit'     => __( 'Send rating', 'dev3-ai-helpdesk' ),
			'rated'          => __( 'Thanks for your feedback!', 'dev3-ai-helpdesk' ),
			'yourRating'     => __( 'Your rating', 'dev3-ai-helpdesk' ),
			'notFound'       => __( 'We could not find that ticket. Use the link from your email, or log in.', 'dev3-ai-helpdesk' ),
			'error'          => __( 'Something went wrong. Please try again.', 'dev3-ai-helpdesk' ),
			'loading'        => __( 'Loading…', 'dev3-ai-helpdesk' ),
			'tooMany'        => __( 'You added the maximum number of files.', 'dev3-ai-helpdesk' ),
			'tooBig'         => __( 'This file is too large.', 'dev3-ai-helpdesk' ),
			'attachments'    => __( 'Attachments', 'dev3-ai-helpdesk' ),
			'searchResults'  => __( 'Search results', 'dev3-ai-helpdesk' ),
			'noResults'      => __( 'No articles match. Try other words or contact us.', 'dev3-ai-helpdesk' ),
			'searching'      => __( 'Searching…', 'dev3-ai-helpdesk' ),
			'helpful'        => __( 'Thanks for letting us know!', 'dev3-ai-helpdesk' ),
			'support'        => __( 'Support team', 'dev3-ai-helpdesk' ),
			'helpCentre'     => __( 'Browse the help centre', 'dev3-ai-helpdesk' ),
			'ticket'         => __( 'Ticket', 'dev3-ai-helpdesk' ),
		);
		/**
		 * Filters front-end strings.
		 *
		 * @param array $s Strings.
		 */
		return (array) apply_filters( 'dev3hd_portal_strings', $s );
	}
}
