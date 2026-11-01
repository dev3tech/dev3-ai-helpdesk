<?php
/**
 * Knowledge base: the article post type and category taxonomy, view counts, "Was this helpful?" votes and
 * related articles on article pages, and re-indexing on save.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Kb;

use Dev3\AiHelpdesk\Frontend\Portal;
use Dev3\AiHelpdesk\Roles;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Kb.
 */
class Kb {

	const POST_TYPE = 'dev3hd_article';

	const TAXONOMY = 'dev3hd_kb_cat';

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'init', array( self::class, 'register_types' ) );
		add_filter( 'the_content', array( $this, 'article_footer' ), 20 );
		add_action( 'template_redirect', array( $this, 'count_view' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'saved' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'deleted' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Registers the post type and taxonomy.
	 */
	public static function register_types() {
		$slug = (string) Settings::get( 'kb_slug', 'help' );
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Help articles', 'dev3-ai-helpdesk' ),
					'singular_name' => __( 'Help article', 'dev3-ai-helpdesk' ),
					'add_new_item'  => __( 'Add help article', 'dev3-ai-helpdesk' ),
					'edit_item'     => __( 'Edit help article', 'dev3-ai-helpdesk' ),
					'all_items'     => __( 'Help articles', 'dev3-ai-helpdesk' ),
					'menu_name'     => __( 'Help articles', 'dev3-ai-helpdesk' ),
				),
				'public'          => true,
				'show_in_rest'    => true,
				'show_in_menu'    => DEV3HD_SLUG,
				'has_archive'     => false,
				'rewrite'         => array(
					'slug'       => $slug . '/article',
					'with_front' => false,
				),
				'supports'        => array( 'title', 'editor', 'excerpt', 'revisions', 'author', 'thumbnail', 'custom-fields' ),
				'menu_icon'       => 'dashicons-book',
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Help categories', 'dev3-ai-helpdesk' ),
					'singular_name' => __( 'Help category', 'dev3-ai-helpdesk' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => $slug . '/topic',
					'with_front' => false,
				),
			)
		);
		foreach ( array( '_dev3hd_views', '_dev3hd_helpful_yes', '_dev3hd_helpful_no', '_dev3hd_source_ticket' ) as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'          => 'integer',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
				)
			);
		}
	}

	/**
	 * Published articles count.
	 *
	 * @return int
	 */
	public static function count() {
		$counts = wp_count_posts( self::POST_TYPE );
		return (int) ( $counts->publish ?? 0 );
	}

	/**
	 * Article for JSON.
	 *
	 * @param \WP_Post $post  Post.
	 * @param float    $score Score.
	 * @return array
	 */
	public static function present( \WP_Post $post, $score = 0.0 ) {
		$terms = get_the_terms( $post, self::TAXONOMY );
		$yes   = (int) get_post_meta( $post->ID, '_dev3hd_helpful_yes', true );
		$no    = (int) get_post_meta( $post->ID, '_dev3hd_helpful_no', true );
		return array(
			'id'       => (int) $post->ID,
			'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'excerpt'  => wp_trim_words( wp_strip_all_tags( has_excerpt( $post ) ? $post->post_excerpt : strip_shortcodes( $post->post_content ) ), 26 ),
			'url'      => get_permalink( $post ),
			'category' => $terms && ! is_wp_error( $terms ) ? html_entity_decode( $terms[0]->name, ENT_QUOTES, 'UTF-8' ) : '',
			'views'    => (int) get_post_meta( $post->ID, '_dev3hd_views', true ),
			'helpful'  => $yes + $no > 0 ? (int) round( 100 * $yes / ( $yes + $no ) ) : null,
			'votes'    => $yes + $no,
			'status'   => $post->post_status,
			'score'    => round( (float) $score, 3 ),
			'modified' => get_post_modified_time( 'Y-m-d H:i:s', true, $post ),
		);
	}

	/**
	 * Plain text used for search and embeddings.
	 *
	 * @param \WP_Post $post Post.
	 * @param int      $max  Max chars.
	 * @return string
	 */
	public static function text( \WP_Post $post, $max = 6000 ) {
		$body = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$body = preg_replace( '/\s+/', ' ', html_entity_decode( $body, ENT_QUOTES, 'UTF-8' ) );
		return mb_substr( trim( get_the_title( $post ) . "\n" . $post->post_excerpt . "\n" . $body ), 0, $max );
	}

	/**
	 * Feedback widget + related articles under article content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function article_footer( $content ) {
		if ( ! is_singular( self::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post ) {
			return $content;
		}
		$related = Search::related_posts( $post, 3 );
		$help    = Portal::page_url( 'help' );
		$portal  = Portal::page_url( 'portal' );
		$html    = '<div class="dev3hd-article-foot" data-dev3hd-vote="' . (int) $post->ID . '">';
		$html   .= '<div class="dev3hd-vote" role="group" aria-label="' . esc_attr__( 'Was this article helpful?', 'dev3-ai-helpdesk' ) . '"><span class="dev3hd-vote-q">' . esc_html__( 'Was this article helpful?', 'dev3-ai-helpdesk' ) . '</span>'
			. '<button type="button" class="dev3hd-vote-btn" data-vote="yes">' . esc_html__( 'Yes', 'dev3-ai-helpdesk' ) . '</button>'
			. '<button type="button" class="dev3hd-vote-btn" data-vote="no">' . esc_html__( 'No', 'dev3-ai-helpdesk' ) . '</button>'
			. '<span class="dev3hd-vote-msg" aria-live="polite"></span></div>';
		if ( $related ) {
			$html .= '<div class="dev3hd-related"><h2 class="dev3hd-related-title">' . esc_html__( 'Related articles', 'dev3-ai-helpdesk' ) . '</h2><ul>';
			foreach ( $related as $item ) {
				$html .= '<li><a href="' . esc_url( get_permalink( $item ) ) . '">' . esc_html( get_the_title( $item ) ) . '</a></li>';
			}
			$html .= '</ul></div>';
		}
		$links = array();
		if ( $help ) {
			$links[] = '<a href="' . esc_url( $help ) . '">' . esc_html__( 'Back to the help centre', 'dev3-ai-helpdesk' ) . '</a>';
		}
		if ( $portal ) {
			$links[] = '<a href="' . esc_url( $portal ) . '">' . esc_html__( 'Still need help? Contact us', 'dev3-ai-helpdesk' ) . '</a>';
		}
		if ( $links ) {
			$html .= '<p class="dev3hd-article-links">' . implode( ' · ', $links ) . '</p>';
		}
		$html .= '</div>';
		return $content . $html;
	}

	/**
	 * Enqueues the small front-end bundle on article pages (votes).
	 */
	public function assets() {
		if ( is_singular( self::POST_TYPE ) ) {
			Portal::enqueue();
		}
	}

	/**
	 * Counts a view (not for agents or bots).
	 */
	public function count_view() {
		if ( ! is_singular( self::POST_TYPE ) || Roles::is_agent() ) {
			return;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|preview/i', $ua ) ) {
			return;
		}
		$id = (int) get_queried_object_id();
		update_post_meta( $id, '_dev3hd_views', (int) get_post_meta( $id, '_dev3hd_views', true ) + 1 );
	}

	/**
	 * Records a vote.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $helpful Yes/no.
	 * @return bool
	 */
	public static function vote( $post_id, $helpful ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}
		$key = $helpful ? '_dev3hd_helpful_yes' : '_dev3hd_helpful_no';
		update_post_meta( $post->ID, $key, (int) get_post_meta( $post->ID, $key, true ) + 1 );
		return true;
	}

	/**
	 * Re-index on save.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function saved( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' === $post->post_status ) {
			Indexer::queue( array( (int) $post_id ) );
		} else {
			Indexer::forget( (int) $post_id );
		}
	}

	/**
	 * Removes the embedding of a deleted article.
	 *
	 * @param int $post_id Post id.
	 */
	public function deleted( $post_id ) {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			Indexer::forget( (int) $post_id );
		}
	}
}
