<?php
/**
 * Help-centre search: keyword scoring (always available) and semantic search over KB embeddings
 * (when AI is on and the index exists), with the keyword results as fallback.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Kb;

use Dev3\AiHelpdesk\Core3\Api\Ai;
use Dev3\AiHelpdesk\Core3\Data\Embeddings_Store;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Search.
 */
class Search {

	const TYPE = 'kb_article';

	const MIN_SCORE = 0.5;

	const STOP = array( 'the', 'and', 'for', 'you', 'your', 'with', 'that', 'this', 'have', 'from', 'are', 'was', 'can', 'not', 'but', 'how', 'what', 'when', 'where', 'why', 'who', 'does', 'did', 'will', 'would', 'could', 'should', 'there', 'their', 'they', 'them', 'our', 'out', 'get', 'got', 'has', 'had', 'its', 'into', 'about', 'any', 'all', 'just', 'please', 'thanks', 'thank', 'hello', 'help', 'need', 'want', 'i', 'my', 'me', 'is', 'it', 'to', 'a', 'an', 'of', 'in', 'on', 'or', 'do', 'be', 'so', 'if', 'at', 'as', 'we', 'us', 'hi' );

	/**
	 * Search terms from free text.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max terms.
	 * @return string[]
	 */
	public static function terms( $text, $max = 8 ) {
		$text  = mb_strtolower( wp_strip_all_tags( (string) $text ) );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( (array) $words as $word ) {
			if ( mb_strlen( $word ) < 3 || in_array( $word, self::STOP, true ) ) {
				continue;
			}
			$out[ $word ] = ( $out[ $word ] ?? 0 ) + 1;
		}
		uksort(
			$out,
			static function ( $a, $b ) use ( $out ) {
				$cmp = $out[ $b ] <=> $out[ $a ];
				return 0 !== $cmp ? $cmp : mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);
		return array_slice( array_keys( $out ), 0, $max );
	}

	/**
	 * Keyword search (any term; title matches weigh more).
	 *
	 * @param string $query Query.
	 * @param int    $limit Limit.
	 * @param array  $opts  exclude (ids).
	 * @return array [ [ post, score ] ]
	 */
	public static function keyword( $query, $limit = 6, array $opts = array() ) {
		global $wpdb;
		$terms = self::terms( $query );
		if ( ! $terms ) {
			return array();
		}
		$likes = array();
		foreach ( $terms as $term ) {
			$like    = '%' . $wpdb->esc_like( $term ) . '%';
			$likes[] = $wpdb->prepare( '(post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s)', $like, $like, $like );
		}
		$where = implode( ' OR ', $likes );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ({$where}) LIMIT 200", Kb::POST_TYPE ) );
		$out = array();
		foreach ( (array) $ids as $id ) {
			if ( in_array( (int) $id, (array) ( $opts['exclude'] ?? array() ), true ) ) {
				continue;
			}
			$post  = get_post( (int) $id );
			$title = mb_strtolower( $post->post_title );
			$body  = mb_strtolower( wp_strip_all_tags( $post->post_excerpt . ' ' . $post->post_content ) );
			$score = 0.0;
			foreach ( $terms as $i => $term ) {
				$weight = 1 / ( 1 + $i * 0.15 );
				if ( false !== mb_strpos( $title, $term ) ) {
					$score += 3 * $weight;
				}
				$score += min( 3, substr_count( $body, $term ) ) * 0.5 * $weight;
			}
			$out[] = array( $post, $score );
		}
		usort( $out, static fn( $a, $b ) => $b[1] <=> $a[1] );
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Whether semantic search can run in a context.
	 *
	 * @param string $context admin|public.
	 * @return bool
	 */
	public static function semantic_available( $context ) {
		if ( 'public' === $context && ! Settings::get( 'ai_public_search', true ) ) {
			return false;
		}
		return Ai::enabled( $context ) && Embeddings_Store::instance()->count( self::TYPE ) > 0;
	}

	/**
	 * Semantic search.
	 *
	 * @param string $query   Query.
	 * @param int    $limit   Limit.
	 * @param string $context Context.
	 * @param array  $opts    exclude.
	 * @return array|\WP_Error [ [ post, score ] ]
	 */
	public static function semantic( $query, $limit = 6, $context = 'public', array $opts = array() ) {
		$embed = Ai::embed( 'kb_search', array( mb_substr( (string) $query, 0, 2000 ) ), 'RETRIEVAL_QUERY', $context, array( 'cache' => DAY_IN_SECONDS ) );
		if ( is_wp_error( $embed ) ) {
			return $embed;
		}
		$hits = Embeddings_Store::instance()->search(
			(array) ( $embed['vectors'][0] ?? array() ),
			self::TYPE,
			$limit * 2,
			array(
				'min_score' => (float) apply_filters( 'dev3hd_kb_min_score', self::MIN_SCORE ),
				'exclude'   => (array) ( $opts['exclude'] ?? array() ),
			)
		);
		$out  = array();
		foreach ( $hits as $hit ) {
			$post = get_post( $hit['object_id'] );
			if ( $post && Kb::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
				$out[] = array( $post, (float) $hit['score'] );
			}
		}
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Search with semantic results first and keyword results filling the rest.
	 *
	 * @param string $query   Query.
	 * @param int    $limit   Limit.
	 * @param string $context admin|public.
	 * @param array  $opts    exclude.
	 * @return array { mode: semantic|keyword, results: [ article ] }
	 */
	public static function search( $query, $limit = 6, $context = 'public', array $opts = array() ) {
		$query = trim( (string) $query );
		if ( mb_strlen( $query ) < 3 ) {
			return array(
				'mode'    => 'keyword',
				'results' => array(),
			);
		}
		$mode = 'keyword';
		$hits = array();
		if ( self::semantic_available( $context ) ) {
			$sem = self::semantic( $query, $limit, $context, $opts );
			if ( ! is_wp_error( $sem ) ) {
				$hits = $sem;
				$mode = 'semantic';
			}
		}
		if ( count( $hits ) < $limit ) {
			$seen = array_map( static fn( $h ) => (int) $h[0]->ID, $hits );
			foreach ( self::keyword( $query, $limit, $opts ) as $hit ) {
				if ( ! in_array( (int) $hit[0]->ID, $seen, true ) ) {
					$hits[] = array( $hit[0], 0.0 );
					$seen[] = (int) $hit[0]->ID;
				}
			}
		}
		return array(
			'mode'    => $mode,
			'results' => array_map( static fn( $h ) => Kb::present( $h[0], $h[1] ), array_slice( $hits, 0, $limit ) ),
		);
	}

	/**
	 * Related articles for an article page (stored vector, then same category, then title words).
	 *
	 * @param \WP_Post $post  Post.
	 * @param int      $limit Limit.
	 * @return \WP_Post[]
	 */
	public static function related_posts( \WP_Post $post, $limit = 3 ) {
		$out = array();
		$row = Embeddings_Store::instance()->get( self::TYPE, $post->ID );
		if ( $row && ! empty( $row['vector'] ) ) {
			foreach ( Embeddings_Store::instance()->search( $row['vector'], self::TYPE, $limit, array( 'exclude' => array( $post->ID ) ) ) as $hit ) {
				$item = get_post( $hit['object_id'] );
				if ( $item && 'publish' === $item->post_status ) {
					$out[] = $item;
				}
			}
		}
		if ( count( $out ) < $limit ) {
			$terms = wp_get_post_terms( $post->ID, Kb::TAXONOMY, array( 'fields' => 'ids' ) );
			$query = new \WP_Query(
				array(
					'post_type'      => Kb::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'post__not_in'   => array_merge( array( $post->ID ), wp_list_pluck( $out, 'ID' ) ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
					'no_found_rows'  => true,
					'tax_query'      => $terms && ! is_wp_error( $terms ) ? array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => Kb::TAXONOMY,
							'terms'    => $terms,
						),
					) : array(),
				)
			);
			$out   = array_merge( $out, $query->posts );
		}
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Articles relevant to a ticket thread (reply drafts).
	 *
	 * @param string $text    Thread text.
	 * @param int    $limit   Limit.
	 * @param string $context Context.
	 * @return array [ [ post, score ] ]
	 */
	public static function for_text( $text, $limit = 4, $context = 'admin' ) {
		if ( self::semantic_available( $context ) ) {
			$sem = self::semantic( $text, $limit, $context );
			if ( ! is_wp_error( $sem ) && $sem ) {
				return $sem;
			}
		}
		return self::keyword( $text, $limit );
	}
}
