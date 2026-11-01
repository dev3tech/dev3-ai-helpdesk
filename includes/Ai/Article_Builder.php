<?php
/**
 * A5/A6: help-centre article drafts from solved tickets (personal data masked first) and the
 * "article gaps" report over recently solved tickets.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Ai;

use Dev3\AiHelpdesk\Core3\Api\Prompts;
use Dev3\AiHelpdesk\Core3\Core\Privacy;
use Dev3\AiHelpdesk\Data\Tables;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Kb\Kb;

defined( 'ABSPATH' ) || exit;

/**
 * Article_Builder.
 */
class Article_Builder {

	const GAPS_OPTION = 'dev3hd_kb_gaps';

	const TAGS = array(
		'h2'     => array(),
		'h3'     => array(),
		'p'      => array(),
		'ol'     => array(),
		'ul'     => array(),
		'li'     => array(),
		'strong' => array(),
		'em'     => array(),
		'code'   => array(),
	);

	/**
	 * KB category names.
	 *
	 * @return string[]
	 */
	public static function category_names() {
		$terms = get_terms(
			array(
				'taxonomy'   => Kb::TAXONOMY,
				'hide_empty' => false,
				'number'     => 100,
			)
		);
		return is_wp_error( $terms ) ? array() : array_values( wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * Drafts an article from a ticket.
	 *
	 * @param int $ticket_id Ticket id.
	 * @return array|\WP_Error { title, excerpt, content, category, keywords, model, tokens }
	 */
	public static function draft( $ticket_id ) {
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return new \WP_Error( 'dev3hd_not_found', __( 'Ticket not found.', 'dev3-ai-helpdesk' ), array( 'status' => 404 ) );
		}
		$thread = Privacy::mask( Thread::text( $ticket_id, false, 14000 ) );
		$thread = str_ireplace( array( (string) $ticket['name'], (string) $ticket['email'] ), array( '[customer]', '[email]' ), $thread );
		$cats   = self::category_names();
		$result = Prompts::run(
			'article',
			array(
				'site'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'kb_categories' => $cats ? implode( ', ', $cats ) : '(none yet)',
				'subject'       => $ticket['subject'],
				'thread'        => $thread,
			),
			'admin',
			array( 'cache' => DAY_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = (array) ( $result['data'] ?? array() );
		$kw   = array();
		foreach ( array_slice( (array) ( $data['keywords'] ?? array() ), 0, 6 ) as $word ) {
			$word = mb_substr( sanitize_text_field( (string) $word ), 0, 30 );
			if ( '' !== $word ) {
				$kw[] = $word;
			}
		}
		$out = array(
			'title'    => mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 120 ),
			'excerpt'  => mb_substr( sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) ), 0, 300 ),
			'content'  => trim( wp_kses( mb_substr( (string) ( $data['content'] ?? '' ), 0, 8000 ), self::TAGS ) ),
			'category' => mb_substr( sanitize_text_field( (string) ( $data['category'] ?? '' ) ), 0, 60 ),
			'keywords' => $kw,
			'model'    => (string) $result['model'],
			'tokens'   => (int) ( $result['usage']['total'] ?? 0 ),
		);
		if ( '' === $out['title'] || '' === $out['content'] ) {
			return new \WP_Error( 'dev3hd_ai_empty', __( 'DEV3 AI returned an empty article. Try again.', 'dev3-ai-helpdesk' ), array( 'status' => 502 ) );
		}
		return $out;
	}

	/**
	 * Simple HTML (h2/h3/p/lists) to block markup.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function to_blocks( $html ) {
		$html = trim( wp_kses( (string) $html, self::TAGS ) );
		if ( ! preg_match( '#<(h2|h3|p|ol|ul)\b#i', $html ) ) {
			$html = wpautop( $html );
		}
		preg_match_all( '#<(h2|h3|p|ol|ul)\b[^>]*>.*?</\1>#is', $html, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $match ) {
			$tag   = strtolower( $match[1] );
			$inner = $match[0];
			if ( 'h2' === $tag || 'h3' === $tag ) {
				$level = 'h3' === $tag ? ' {"level":3}' : '';
				$out[] = '<!-- wp:heading' . $level . ' -->' . "\n" . preg_replace( '#^<' . $tag . '[^>]*>#i', '<' . $tag . ' class="wp-block-heading">', $inner ) . "\n" . '<!-- /wp:heading -->';
			} elseif ( 'p' === $tag ) {
				$out[] = "<!-- wp:paragraph -->\n" . $inner . "\n<!-- /wp:paragraph -->";
			} else {
				$items = preg_replace( '#<li>(.*?)</li>#is', "<!-- wp:list-item -->\n<li>$1</li>\n<!-- /wp:list-item -->", $inner );
				$attr  = 'ol' === $tag ? ' {"ordered":true}' : '';
				$out[] = '<!-- wp:list' . $attr . " -->\n" . preg_replace( '#^<' . $tag . '[^>]*>#i', '<' . $tag . ' class="wp-block-list">', $items ) . "\n<!-- /wp:list -->";
			}
		}
		return $out ? implode( "\n\n", $out ) : $html;
	}

	/**
	 * Creates a draft article from an accepted (possibly edited) draft.
	 *
	 * @param array $data      title, excerpt, content, category.
	 * @param int   $ticket_id Source ticket.
	 * @return array|\WP_Error { id, edit_url }
	 */
	public static function create( array $data, $ticket_id = 0 ) {
		$title = mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 190 );
		if ( '' === $title || '' === trim( (string) ( $data['content'] ?? '' ) ) ) {
			return new \WP_Error( 'dev3hd_article_invalid', __( 'The article needs a title and content.', 'dev3-ai-helpdesk' ), array( 'status' => 400 ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => Kb::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_excerpt' => mb_substr( sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) ), 0, 300 ),
				'post_content' => self::to_blocks( (string) $data['content'] ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$cat = trim( sanitize_text_field( (string) ( $data['category'] ?? '' ) ) );
		if ( '' !== $cat ) {
			$term = term_exists( $cat, Kb::TAXONOMY );
			if ( ! $term ) {
				$term = wp_insert_term( $cat, Kb::TAXONOMY );
			}
			if ( ! is_wp_error( $term ) ) {
				wp_set_object_terms( $id, (int) $term['term_id'], Kb::TAXONOMY );
			}
		}
		if ( $ticket_id ) {
			update_post_meta( $id, '_dev3hd_source_ticket', (int) $ticket_id );
			/* translators: %s: article title. */
			Service::event( $ticket_id, sprintf( __( 'Help article draft created: %s', 'dev3-ai-helpdesk' ), $title ), get_current_user_id() );
		}
		return array(
			'id'       => (int) $id,
			'edit_url' => get_edit_post_link( $id, 'raw' ),
		);
	}

	/**
	 * Gaps report over recently solved tickets.
	 *
	 * @return array|\WP_Error { at, gaps[{ question, count, examples[{id,number,subject}], title }] }
	 */
	public static function gaps() {
		global $wpdb;
		$table = Tables::name( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE status IN ('resolved','closed') AND updated_at > %s ORDER BY id DESC LIMIT 80", gmdate( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS ) ), ARRAY_A );
		if ( count( (array) $rows ) < 3 ) {
			return new \WP_Error( 'dev3hd_gaps_few', __( 'Solve a few more tickets first: the report needs at least 3 resolved tickets.', 'dev3-ai-helpdesk' ), array( 'status' => 409 ) );
		}
		$lines = array();
		$valid = array();
		foreach ( $rows as $row ) {
			$ticket = Tickets::get( (int) $row['id'] );
			$sum    = (string) ( $ticket['ai']['triage']['summary'] ?? '' );
			if ( '' === $sum ) {
				$sum = wp_trim_words( Thread::last_customer( $ticket['id'] ), 30 );
			}
			$valid[ (int) $ticket['id'] ] = $ticket;
			$lines[]                      = (int) $ticket['id'] . ' | ' . $ticket['subject'] . ' | ' . Privacy::mask( $sum );
		}
		$titles = get_posts(
			array(
				'post_type'      => Kb::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded admin listing.
				'fields'         => 'ids',
			)
		);
		$titles = array_map( static fn( $id ) => '- ' . get_the_title( $id ), $titles );
		$result = Prompts::run(
			'gaps',
			array(
				'articles' => $titles ? implode( "\n", $titles ) : '(no articles yet)',
				'tickets'  => implode( "\n", $lines ),
			),
			'admin',
			array( 'cache' => 6 * HOUR_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$gaps = array();
		foreach ( array_slice( (array) ( $result['data']['gaps'] ?? array() ), 0, 8 ) as $gap ) {
			$examples = array();
			foreach ( array_slice( (array) ( $gap['examples'] ?? array() ), 0, 3 ) as $id ) {
				if ( isset( $valid[ (int) $id ] ) ) {
					$examples[] = array(
						'id'      => (int) $id,
						'number'  => (int) $valid[ (int) $id ]['number'],
						'subject' => $valid[ (int) $id ]['subject'],
					);
				}
			}
			$question = mb_substr( sanitize_text_field( (string) ( $gap['question'] ?? '' ) ), 0, 200 );
			if ( '' === $question ) {
				continue;
			}
			$gaps[] = array(
				'question' => $question,
				'count'    => max( 1, (int) ( $gap['count'] ?? 1 ) ),
				'examples' => $examples,
				'title'    => mb_substr( sanitize_text_field( (string) ( $gap['title'] ?? '' ) ), 0, 120 ),
			);
		}
		$report = array(
			'at'      => Tickets::now(),
			'tickets' => count( $lines ),
			'gaps'    => $gaps,
			'model'   => (string) $result['model'],
		);
		update_option( self::GAPS_OPTION, $report, false );
		return $report;
	}
}
