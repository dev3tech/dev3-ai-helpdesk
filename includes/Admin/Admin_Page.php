<?php
/**
 * Admin menu + React app mount. Helpdesk agents see the menu; settings screens are for managers.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk\Admin;

use Dev3\AiHelpdesk\Core3\Core\Assets;
use Dev3\AiHelpdesk\Core3\Core\Capabilities;
use Dev3\AiHelpdesk\Data\Tickets;
use Dev3\AiHelpdesk\Kb\Kb;
use Dev3\AiHelpdesk\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3HD_FILE ), array( $this, 'links' ) );
		add_filter( 'parent_file', array( $this, 'parent_file' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$count  = 0;
		$bubble = '';
		if ( Roles::is_agent() ) {
			$counts = Tickets::counts( array(), get_current_user_id() );
			$count  = (int) $counts['new'];
		}
		if ( $count > 0 ) {
			$bubble = ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) number_format_i18n( $count ) . '</span></span>';
		}
		$this->hook = (string) add_menu_page( DEV3HD_NAME, 'DEV3 AI Helpdesk' . $bubble, Roles::CAP, DEV3HD_SLUG, array( $this, 'render' ), 'dashicons-sos', 26 );
		add_submenu_page( DEV3HD_SLUG, DEV3HD_NAME, __( 'Helpdesk', 'dev3-ai-helpdesk' ), Roles::CAP, DEV3HD_SLUG, array( $this, 'render' ) );
		add_submenu_page( DEV3HD_SLUG, __( 'Help categories', 'dev3-ai-helpdesk' ), __( 'Help categories', 'dev3-ai-helpdesk' ), 'manage_categories', 'edit-tags.php?taxonomy=' . Kb::TAXONOMY . '&post_type=' . Kb::POST_TYPE );
	}

	/**
	 * Keeps our menu open on the help-category screen.
	 *
	 * @param string $file Parent file.
	 * @return string
	 */
	public function parent_file( $file ) {
		global $current_screen;
		if ( $current_screen && Kb::TAXONOMY === $current_screen->taxonomy ) {
			return DEV3HD_SLUG;
		}
		return $file;
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3hd-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3HD_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3hd-admin-root" class="dev3hd-root dev3hd-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook || 'toplevel_page_' . DEV3HD_SLUG === $hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		if ( current_user_can( Capabilities::manage() ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3HD_SLUG . '#/settings' ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-helpdesk' ) . '</a>' );
		}
		return $links;
	}
}
