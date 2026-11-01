<?php
/**
 * Keeps KB embeddings fresh: a background job embeds stale articles (RETRIEVAL_DOCUMENT) in chunks.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Kb;

use Dev3\AiHelpdesk\Core3\Api\Ai;
use Dev3\AiHelpdesk\Core3\Core\Jobs;
use Dev3\AiHelpdesk\Core3\Data\Embeddings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Indexer.
 */
class Indexer {

	const JOB = 'dev3hd_kb_index';

	/**
	 * Registers the job type.
	 */
	public static function register() {
		Jobs::register( self::JOB, array( self::class, 'run_chunk' ) );
	}

	/**
	 * Published article ids => text hash.
	 *
	 * @return array
	 */
	public static function hashes() {
		$ids = get_posts(
			array(
				'post_type'      => Kb::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 5000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded admin listing.
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = Embeddings_Store::hash( Kb::text( get_post( $id ) ) );
		}
		return $out;
	}

	/**
	 * Index status.
	 *
	 * @return array { articles, indexed, stale, enabled, job }
	 */
	public static function status() {
		$hashes = self::hashes();
		$store  = Embeddings_Store::instance();
		return array(
			'articles' => count( $hashes ),
			'indexed'  => $store->count( Search::TYPE ),
			'stale'    => count( $store->stale( Search::TYPE, $hashes ) ),
			'enabled'  => Ai::enabled( 'admin' ) && Ai::status()['key_set'],
			'job'      => Jobs::active( self::JOB ),
		);
	}

	/**
	 * Queues stale articles (all when $ids is null). Removes orphans on a full run.
	 *
	 * @param int[]|null $ids Ids.
	 * @return array|null|\WP_Error Job, or null when nothing to do / AI off.
	 */
	public static function queue( $ids = null ) {
		if ( ! Ai::enabled( 'admin' ) || ! Ai::status()['key_set'] ) {
			return null;
		}
		$store  = Embeddings_Store::instance();
		$hashes = self::hashes();
		if ( null === $ids ) {
			foreach ( $store->orphans( Search::TYPE, array_keys( $hashes ) ) as $orphan ) {
				$store->delete( Search::TYPE, $orphan );
			}
			$todo = $store->stale( Search::TYPE, $hashes );
		} else {
			$todo = $store->stale( Search::TYPE, array_intersect_key( $hashes, array_flip( array_map( 'intval', $ids ) ) ) );
		}
		if ( ! $todo ) {
			return null;
		}
		$id = Jobs::create( self::JOB, array_values( $todo ), array(), 20 );
		return is_wp_error( $id ) ? $id : Jobs::get( $id );
	}

	/**
	 * Deletes an article's vector.
	 *
	 * @param int $post_id Post id.
	 */
	public static function forget( $post_id ) {
		Embeddings_Store::instance()->delete( Search::TYPE, (int) $post_id );
	}

	/**
	 * Job handler: embeds a chunk.
	 *
	 * @param array $items Post ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		unset( $job );
		$posts = array();
		foreach ( $items as $id ) {
			$post = get_post( (int) $id );
			if ( $post && Kb::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
				$posts[ (int) $id ] = Kb::text( $post );
			}
		}
		$out = array();
		foreach ( $items as $id ) {
			$out[ (string) $id ] = true;
		}
		if ( ! $posts ) {
			return $out;
		}
		$res = Ai::embed( 'kb_index', array_values( $posts ), 'RETRIEVAL_DOCUMENT', 'cron' );
		if ( is_wp_error( $res ) ) {
			foreach ( array_keys( $posts ) as $id ) {
				$out[ (string) $id ] = $res;
			}
			return $out;
		}
		$i = 0;
		foreach ( $posts as $id => $text ) {
			$vector = (array) ( $res['vectors'][ $i ] ?? array() );
			++$i;
			if ( ! $vector ) {
				$out[ (string) $id ] = __( 'No embedding returned.', 'dev3-ai-helpdesk' );
				continue;
			}
			Embeddings_Store::instance()->upsert( Search::TYPE, $id, $vector, Embeddings_Store::hash( $text ) );
		}
		return $out;
	}
}
