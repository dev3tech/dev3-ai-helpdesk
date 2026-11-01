<?php
/**
 * Front end: the support portal, ticket form and help centre (blocks + shortcodes), the pages created on
 * activation, and My Account → Support when WooCommerce is active. Customer-facing text is neutral.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Frontend;

use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Helpdesk\Service;
use Dev3\AiHelpdesk\Kb\Kb;
use Dev3\AiHelpdesk\Kb\Search;
use Dev3\AiHelpdesk\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Portal.
 */
class Portal {

	const HANDLE = 'dev3hd-frontend';

	const PAGES = 'dev3hd_pages';

	const ENDPOINT = 'support-tickets';

	/**
	 * Hooks.
	 */
	public function register() {
		add_shortcode( 'dev3hd_portal', array( $this, 'shortcode_portal' ) );
		add_shortcode( 'dev3hd_ticket_form', array( $this, 'shortcode_form' ) );
		add_shortcode( 'dev3hd_help_center', array( $this, 'shortcode_help' ) );
		add_action( 'init', array( $this, 'blocks' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ), 5 );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'account_menu' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'account_page' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'account_title' ) );
	}

	/**
	 * Registers the front-end bundle (enqueued only where something renders).
	 */
	public static function register_assets() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$asset_file = DEV3HD_PATH . 'build/frontend.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;
		wp_register_script( self::HANDLE, DEV3HD_URL . 'build/frontend.js', array(), $asset['version'], true );
		if ( is_readable( DEV3HD_PATH . 'build/frontend.css' ) ) {
			wp_register_style( self::HANDLE, DEV3HD_URL . 'build/frontend.css', array(), $asset['version'] );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Enqueues the bundle and prints the boot config once.
	 */
	public static function enqueue() {
		self::register_assets();
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
		static $printed = false;
		if ( ! $printed && wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_add_inline_script( self::HANDLE, 'window.dev3hdPortal = ' . wp_json_encode( self::config() ) . ';', 'before' );
			$printed = true;
		}
	}

	/**
	 * Boot data for the front-end app (no secrets).
	 *
	 * @return array
	 */
	public static function config() {
		$user  = wp_get_current_user();
		$cats  = array_map(
			static fn( $c ) => array(
				'value' => $c['id'],
				'label' => $c['name'],
			),
			Settings::categories()
		);
		$prios = array();
		if ( Settings::get( 'customer_priority', false ) ) {
			foreach ( Tickets::priority_labels() as $value => $label ) {
				$prios[] = array(
					'value' => $value,
					'label' => $label,
				);
			}
		}
		return array(
			'restUrl'      => esc_url_raw( rest_url( 'dev3hd/v1/' ) ),
			'nonce'        => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'loggedIn'     => is_user_logged_in(),
			'user'         => $user && $user->exists() ? array(
				'name'  => $user->display_name,
				'email' => $user->user_email,
			) : null,
			'guest'        => (bool) Settings::get( 'guest_tickets', true ) && ! Settings::get( 'require_login', false ),
			'loginUrl'     => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url( self::page_url( 'portal' ) ),
			'categories'   => $cats,
			'priorities'   => $prios,
			'orderField'   => class_exists( 'WooCommerce' ) && (bool) Settings::get( 'woo_order_field', true ),
			'attachments'  => array(
				'enabled' => (bool) Settings::get( 'allow_attachments', true ),
				'max'     => (int) Settings::get( 'max_files', 3 ),
				'mb'      => (int) Settings::get( 'max_file_mb', 5 ),
				'accept'  => '.jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.log,.csv,.zip,.docx,.xlsx',
			),
			'csat'         => (bool) Settings::get( 'csat_enabled', true ),
			'reopen'       => (bool) Settings::get( 'reopen_on_reply', true ),
			'portalUrl'    => self::page_url( 'portal' ),
			'helpUrl'      => self::page_url( 'help' ),
			'intro'        => (string) Settings::get( 'portal_intro', '' ),
			'ratingLabels' => Service::rating_labels(),
			'strings'      => Strings::all(),
		);
	}

	/**
	 * Accent style attribute.
	 *
	 * @return string
	 */
	private static function style() {
		$accent = sanitize_hex_color( (string) Settings::get( 'accent_color', '#2563eb' ) );
		return '--dev3hd-accent:' . ( $accent ? $accent : '#2563eb' ) . ';';
	}

	/**
	 * Portal / form markup (the app mounts into it; min-height reserves space).
	 *
	 * @param string $view portal|form|account.
	 * @return string
	 */
	public static function render_app( $view = 'portal' ) {
		self::enqueue();
		return '<div class="dev3hd-app dev3hd-app--' . esc_attr( $view ) . '" data-dev3hd-app="portal" data-view="' . esc_attr( $view ) . '" style="' . esc_attr( self::style() ) . '"><div class="dev3hd-p-boot" aria-busy="true"><span class="dev3hd-p-spinner" aria-hidden="true"></span>' . esc_html__( 'Loading…', 'dev3-ai-helpdesk' ) . '</div><noscript><p>' . esc_html__( 'Please enable JavaScript to contact support.', 'dev3-ai-helpdesk' ) . '</p></noscript></div>';
	}

	/**
	 * Shortcode [dev3hd_portal].
	 *
	 * @return string
	 */
	public function shortcode_portal() {
		return self::render_app( 'portal' );
	}

	/**
	 * Shortcode [dev3hd_ticket_form].
	 *
	 * @return string
	 */
	public function shortcode_form() {
		return self::render_app( 'form' );
	}

	/**
	 * Shortcode [dev3hd_help_center].
	 *
	 * @param array $atts Attributes: title.
	 * @return string
	 */
	public function shortcode_help( $atts = array() ) {
		return self::render_help( shortcode_atts( array( 'title' => '' ), (array) $atts ) );
	}

	/**
	 * Help-centre markup (server rendered: search, topics, popular articles; JS adds instant results).
	 *
	 * @param array $atts title.
	 * @return string
	 */
	public static function render_help( array $atts = array() ) {
		self::enqueue();
		$title  = '' !== (string) ( $atts['title'] ?? '' ) ? (string) $atts['title'] : __( 'How can we help?', 'dev3-ai-helpdesk' );
		$action = get_permalink() ? get_permalink() : self::page_url( 'help' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public search form (GET).
		$q     = isset( $_GET['hq'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['hq'] ) ), 0, 200 ) : '';
		$html  = '<div class="dev3hd-hc" data-dev3hd-app="help" style="' . esc_attr( self::style() ) . '">';
		$html .= '<div class="dev3hd-hc-hero"><h2 class="dev3hd-hc-title">' . esc_html( $title ) . '</h2>';
		$html .= '<form class="dev3hd-hc-search" role="search" method="get" action="' . esc_url( $action ) . '"><label class="dev3hd-sr" for="dev3hd-hq">' . esc_html__( 'Search help articles', 'dev3-ai-helpdesk' ) . '</label>';
		$html .= '<input id="dev3hd-hq" class="dev3hd-hc-input" type="search" name="hq" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search for answers…', 'dev3-ai-helpdesk' ) . '" autocomplete="off" aria-controls="dev3hd-hc-results">';
		$html .= '<button class="dev3hd-hc-btn" type="submit">' . esc_html__( 'Search', 'dev3-ai-helpdesk' ) . '</button></form>';
		$html .= '<div id="dev3hd-hc-results" class="dev3hd-hc-results" aria-live="polite">';
		if ( '' !== $q ) {
			$res   = Search::search( $q, 8, 'public' );
			$html .= self::results_html( $res['results'], $q );
		}
		$html .= '</div></div>';

		$terms = get_terms(
			array(
				'taxonomy'   => Kb::TAXONOMY,
				'hide_empty' => true,
				'parent'     => 0,
				'number'     => 24,
			)
		);
		if ( $terms && ! is_wp_error( $terms ) ) {
			$html .= '<section class="dev3hd-hc-section" aria-labelledby="dev3hd-hc-topics"><h3 id="dev3hd-hc-topics" class="dev3hd-hc-h">' . esc_html__( 'Browse topics', 'dev3-ai-helpdesk' ) . '</h3><div class="dev3hd-hc-topics">';
			foreach ( $terms as $term ) {
				$posts = get_posts(
					array(
						'post_type'      => Kb::POST_TYPE,
						'post_status'    => 'publish',
						'posts_per_page' => 4,
						'no_found_rows'  => true,
						'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
							array(
								'taxonomy' => Kb::TAXONOMY,
								'terms'    => $term->term_id,
							),
						),
					)
				);
				$html .= '<div class="dev3hd-hc-topic"><h4 class="dev3hd-hc-topic-title"><a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a></h4>';
				if ( $term->description ) {
					$html .= '<p class="dev3hd-hc-muted">' . esc_html( wp_trim_words( $term->description, 18 ) ) . '</p>';
				}
				$html .= '<ul class="dev3hd-hc-list">';
				foreach ( $posts as $post ) {
					$html .= '<li><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></li>';
				}
				/* translators: %d: number of articles. */
				$html .= '</ul><a class="dev3hd-hc-more" href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( sprintf( _n( 'View %d article', 'View all %d articles', (int) $term->count, 'dev3-ai-helpdesk' ), (int) $term->count ) ) . '</a></div>';
			}
			$html .= '</div></section>';
		}
		$popular = get_posts(
			array(
				'post_type'      => Kb::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 6,
				'no_found_rows'  => true,
				'meta_key'       => '_dev3hd_views', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
			)
		);
		if ( ! $popular ) {
			$popular = get_posts(
				array(
					'post_type'      => Kb::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 6,
					'no_found_rows'  => true,
				)
			);
		}
		if ( $popular ) {
			$html .= '<section class="dev3hd-hc-section" aria-labelledby="dev3hd-hc-popular"><h3 id="dev3hd-hc-popular" class="dev3hd-hc-h">' . esc_html__( 'Popular articles', 'dev3-ai-helpdesk' ) . '</h3><ul class="dev3hd-hc-popular">';
			foreach ( $popular as $post ) {
				$html .= '<li><a href="' . esc_url( get_permalink( $post ) ) . '"><span class="dev3hd-hc-popular-title">' . esc_html( get_the_title( $post ) ) . '</span><span class="dev3hd-hc-muted">' . esc_html( wp_trim_words( wp_strip_all_tags( has_excerpt( $post ) ? $post->post_excerpt : $post->post_content ), 16 ) ) . '</span></a></li>';
			}
			$html .= '</ul></section>';
		}
		if ( ! $terms && ! $popular ) {
			$html .= '<p class="dev3hd-hc-muted">' . esc_html__( 'Help articles will appear here soon.', 'dev3-ai-helpdesk' ) . '</p>';
		}
		$portal = self::page_url( 'portal' );
		if ( $portal ) {
			$html .= '<div class="dev3hd-hc-contact"><div><strong>' . esc_html__( 'Still need help?', 'dev3-ai-helpdesk' ) . '</strong><p class="dev3hd-hc-muted">' . esc_html__( 'Our team usually replies within a business day.', 'dev3-ai-helpdesk' ) . '</p></div><a class="dev3hd-hc-btn" href="' . esc_url( $portal ) . '">' . esc_html__( 'Contact support', 'dev3-ai-helpdesk' ) . '</a></div>';
		}
		return $html . '</div>';
	}

	/**
	 * Server-rendered search results (no-JS fallback).
	 *
	 * @param array  $results Results.
	 * @param string $q       Query.
	 * @return string
	 */
	private static function results_html( array $results, $q ) {
		if ( ! $results ) {
			/* translators: %s: search query. */
			return '<p class="dev3hd-hc-empty">' . esc_html( sprintf( __( 'No articles match "%s". Try other words or contact us.', 'dev3-ai-helpdesk' ), $q ) ) . '</p>';
		}
		$html = '<ul class="dev3hd-hc-hits">';
		foreach ( $results as $row ) {
			$html .= '<li><a href="' . esc_url( $row['url'] ) . '"><span class="dev3hd-hc-hit-title">' . esc_html( $row['title'] ) . '</span><span class="dev3hd-hc-muted">' . esc_html( $row['excerpt'] ) . '</span></a></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Blocks (server rendered).
	 */
	public function blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$asset_file = DEV3HD_PATH . 'build/blocks.asset.php';
		$editor     = '';
		if ( is_readable( $asset_file ) ) {
			$asset = include $asset_file;
			wp_register_script( 'dev3hd-blocks', DEV3HD_URL . 'build/blocks.js', $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( 'dev3hd-blocks', 'dev3-ai-helpdesk', DEV3HD_PATH . 'languages' );
			$editor = 'dev3hd-blocks';
		}
		$common = array(
			'api_version' => 2,
			'category'    => 'widgets',
			'supports'    => array(
				'html'  => false,
				'align' => array( 'wide', 'full' ),
			),
		);
		$blocks = array(
			'dev3hd/portal'      => array(
				'title'           => __( 'Support portal', 'dev3-ai-helpdesk' ),
				'description'     => __( 'Lets customers open tickets, see their tickets and reply.', 'dev3-ai-helpdesk' ),
				'icon'            => 'sos',
				'render_callback' => static fn() => self::render_app( 'portal' ),
			),
			'dev3hd/ticket-form' => array(
				'title'           => __( 'Ticket form', 'dev3-ai-helpdesk' ),
				'description'     => __( 'A contact form that opens a support ticket.', 'dev3-ai-helpdesk' ),
				'icon'            => 'email-alt',
				'render_callback' => static fn() => self::render_app( 'form' ),
			),
			'dev3hd/help-center' => array(
				'title'           => __( 'Help centre', 'dev3-ai-helpdesk' ),
				'description'     => __( 'Searchable knowledge base with topics and popular articles.', 'dev3-ai-helpdesk' ),
				'icon'            => 'book-alt',
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => static fn( $attrs ) => self::render_help( (array) $attrs ),
			),
		);
		foreach ( $blocks as $name => $args ) {
			$args = array_merge( $common, $args );
			if ( $editor ) {
				$args['editor_script'] = $editor;
			}
			register_block_type( $name, $args );
		}
	}

	/**
	 * Page ids.
	 *
	 * @return array { portal, help }
	 */
	public static function pages() {
		$pages = (array) get_option( self::PAGES, array() );
		return array(
			'portal' => (int) ( $pages['portal'] ?? 0 ),
			'help'   => (int) ( $pages['help'] ?? 0 ),
		);
	}

	/**
	 * Published page URL.
	 *
	 * @param string $which portal|help.
	 * @return string
	 */
	public static function page_url( $which ) {
		$id = self::pages()[ $which ] ?? 0;
		return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
	}

	/**
	 * Links for the admin.
	 *
	 * @return array
	 */
	public static function page_links() {
		return array(
			'portal' => self::page_url( 'portal' ),
			'help'   => self::page_url( 'help' ),
		);
	}

	/**
	 * Private customer link to a ticket.
	 *
	 * @param array $ticket Ticket.
	 * @return string
	 */
	public static function ticket_url( array $ticket ) {
		$base = self::page_url( 'portal' );
		return add_query_arg(
			array(
				'ticket' => (int) $ticket['number'],
				'key'    => (string) $ticket['access_key'],
			),
			'' !== $base ? $base : home_url( '/' )
		);
	}

	/**
	 * Creates the portal and help-centre pages.
	 *
	 * @param bool $force Re-create missing/unpublished pages.
	 */
	public static function create_pages( $force = false ) {
		$pages = self::pages();
		$defs  = array(
			'portal' => array( __( 'Support', 'dev3-ai-helpdesk' ), 'support', '<!-- wp:dev3hd/portal /-->' ),
			'help'   => array( __( 'Help centre', 'dev3-ai-helpdesk' ), 'help-centre', '<!-- wp:dev3hd/help-center /-->' ),
		);
		foreach ( $defs as $key => $def ) {
			$id = $pages[ $key ];
			if ( $id && 'publish' === get_post_status( $id ) ) {
				continue;
			}
			if ( $id && get_post( $id ) && ! $force ) {
				continue;
			}
			$new = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $def[0],
					'post_name'    => $def[1],
					'post_content' => $def[2],
				)
			);
			if ( $new && ! is_wp_error( $new ) ) {
				$pages[ $key ] = (int) $new;
			}
		}
		update_option( self::PAGES, $pages, false );
	}

	/**
	 * WooCommerce endpoint.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * My Account menu item (before Logout).
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public function account_menu( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Support', 'dev3-ai-helpdesk' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Support', 'dev3-ai-helpdesk' );
		}
		return $out;
	}

	/**
	 * My Account → Support.
	 */
	public function account_page() {
		echo self::render_app( 'account' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_app().
	}

	/**
	 * Endpoint title.
	 *
	 * @return string
	 */
	public function account_title() {
		return __( 'Support', 'dev3-ai-helpdesk' );
	}
}
