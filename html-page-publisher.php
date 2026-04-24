<?php
/**
 * Plugin Name:       HTML Page Publisher
 * Plugin URI:        https://github.com/HosseinKarami/html-page-publisher
 * Description:       Upload standalone HTML files (including AI-exported artifacts) and publish them as landing pages at a configurable URL. Optional subdomain routing.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
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

define( 'HTMLPP_VERSION', '1.0.0' );
define( 'HTMLPP_PLUGIN_FILE', __FILE__ );
define( 'HTMLPP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HTMLPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HTMLPP_STORAGE_DIRNAME', 'html-page-publisher' );

require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-settings.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-storage.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-sanitizer.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-uploader.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-renderer.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-admin.php';
require_once HTMLPP_PLUGIN_DIR . 'includes/class-htmlpp-plugin.php';

register_activation_hook( __FILE__, array( 'HTMLPP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HTMLPP_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'HTMLPP_Plugin', 'get_instance' ) );
