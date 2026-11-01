=== DEV3 AI Helpdesk & Ticket Triage for WordPress ===
Contributors: dev3tech
Tags: helpdesk, support tickets, knowledge base, email piping, ai
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Support tickets from forms and email, a searchable help centre, and DEV3 AI triage, KB-grounded reply drafts and thread summaries.

== Description ==

A complete, self-hosted helpdesk for WordPress. Customers open tickets from a support portal, a contact form or by email; your team answers from a fast inbox; a help centre answers the common questions before they become tickets. DEV3 AI (Google Gemini, your own key) triages new tickets, drafts replies grounded in your knowledge base, summarises long threads and turns solved tickets into help articles. AI only drafts: agents review and send. Everything works without AI.

= Tickets and inbox =
* Support portal block + [dev3hd_portal], ticket form block + [dev3hd_ticket_form], My Account → Support with WooCommerce.
* Guests get a private link; "Lost your link?" re-sends links. Honeypot, fill-time check, rate limits, block list.
* Statuses (new, open, waiting on customer, on hold, resolved, closed), priorities, departments, categories, tags.
* Replies, internal notes, saved replies with placeholders, private attachments with signed expiring links.
* Inbox views (open, mine, unassigned, overdue), filters, search in messages, bulk actions, CSV export.

= Email piping =
* IMAP polling (SSL, STARTTLS) through background jobs, no PHP imap extension needed, "Check now" with progress.
* Inbound webhook for Mailgun, SendGrid Inbound Parse, Postmark, generic JSON or raw MIME.
* Threading by [#number] and Message-ID, quote stripping, auto-reply and loop protection.

= SLAs, CSAT, reports =
* First-response and resolution targets per priority, business hours, clock paused while waiting on the customer, breach alerts.
* Satisfaction ratings after resolution; reports by agent, category, channel and priority.

= Knowledge base =
* Help articles with categories, a help centre block + [dev3hd_help_center] with instant search, topics and popular articles, "Was this helpful?" votes and related articles.

= DEV3 AI =
* Triage: category, priority, sentiment, language and summary (apply with one click, or opt in to auto-apply).
* Reply drafts grounded in your help articles (embeddings), with cited sources; optional auto-send for confident answers.
* Rewrite (grammar, friendlier, formal, shorter, empathy), thread summaries, article builder from solved tickets, article-gap report.
* Semantic help-centre search with keyword fallback, cost cap, cache and PII masking.

= WooCommerce =
* Customer orders panel on every ticket, order number on the form (verified against the email). HPOS compatible.

== Installation ==

1. Upload the zip in Plugins → Add New → Upload and activate.
2. Support and Help centre pages are created for you.
3. Open DEV3 AI Helpdesk → Settings to add departments, agents, SLAs and email piping.
4. Optional: add your Google Gemini API key in API Config.

== Frequently Asked Questions ==

= Does it work without AI? =
Yes. Every helpdesk feature works without a key; AI features stay hidden or disabled.

= Will AI send replies to my customers? =
Only if you turn on auto-send. By default DEV3 AI prepares drafts that an agent reviews and sends.

= Do I need the PHP imap extension? =
No. The plugin includes its own IMAP client.

== Screenshots ==

1. Dashboard with SLA, CSAT and the created/resolved chart.
2. Ticket with DEV3 AI summary, triage and a knowledge-base-grounded reply draft.
3. Inbox with views, filters and SLA badges.
4. Knowledge base with the AI search index and article gaps.
5. Help centre on the front end.
6. Customer ticket view on a phone.

== Changelog ==

= 1.0.0 =
* Initial release.
