<?php
/**
 * Plugin Name:       DEV3 AI Helpdesk & Ticket Triage for WordPress
 * Plugin URI:        https://dev3.tech/item/dev3-ai-helpdesk
 * Description:       Support tickets from forms and email, a searchable help centre, and DEV3 AI triage, KB-grounded reply drafts and thread summaries.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-helpdesk
 * Domain Path:       /languages
 *
 * @package Dev3\AiHelpdesk
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3HD_VERSION', '1.0.0' );
define( 'DEV3HD_FILE', __FILE__ );
define( 'DEV3HD_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3HD_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3HD_SLUG', 'dev3-ai-helpdesk' );
define( 'DEV3HD_NAME', 'DEV3 AI Helpdesk & Ticket Triage for WordPress' );
define( 'DEV3HD_MIN_PHP', '7.4' );
define( 'DEV3HD_MIN_WP', '6.0' );
define( 'DEV3HD_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3HD_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiHelpdesk\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3HD_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiHelpdesk\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiHelpdesk\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiHelpdesk\Plugin', 'instance' ) );
