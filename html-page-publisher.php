<?php
/**
 * Plugin Name:       HTML Page Publisher
 * Plugin URI:        https://github.com/HosseinKarami/html-page-publisher
 * Description:       Publish standalone HTML files — Claude Design exports or any static HTML — as landing pages at a clean URL. Edit in place, manage images, keep version history. Optional subdomain routing.
 * Version:           1.5.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * Author:            Hossein Karami
 * Author URI:        https://hosseinkarami.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       html-page-publisher
 * Domain Path:       /languages
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HTMLPP_VERSION', '1.5.0' );
define( 'HTMLPP_PLUGIN_FILE', __FILE__ );
define( 'HTMLPP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HTMLPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HTMLPP_STORAGE_DIRNAME', 'html-page-publisher' );
define( 'HTMLPP_BACKUPS_DIRNAME', 'html-page-publisher-backups' );

require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-settings.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-meta.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-storage.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-zip.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-sitemap.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-sanitizer.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-uploader.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-page-service.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-rest.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-renderer.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-admin.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-cli.php';
}

/**
 * Accessor for the plugin singleton (for add-ons and themes).
 *
 * @return HTMLPP_Plugin
 */
function htmlpp() {
	return HTMLPP_Plugin::get_instance();
}

register_activation_hook( __FILE__, array( 'HTMLPP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HTMLPP_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'HTMLPP_Plugin', 'get_instance' ) );
