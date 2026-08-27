<?php
/**
 * Fired when the plugin is uninstalled via the WordPress admin.
 *
 * We intentionally do NOT delete the uploaded HTML pages stored under
 * wp-content/uploads/html-page-publisher/. Users may uninstall and reinstall,
 * or switch to another tool, without losing content. To delete pages
 * manually, remove that directory yourself.
 *
 * @package HTMLPP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'htmlpp_settings' );
delete_option( 'htmlpp_version' );
delete_option( 'htmlpp_installed_at' );
delete_option( 'htmlpp_publish_count' );
// Page metadata (draft status, custom paths, redirects) is content, like the
// page files themselves, and is deliberately kept so a reinstall does not
// publish former drafts. Delete the htmlpp_pages, htmlpp_redirects and
// htmlpp_path_redirects options manually if you want them gone.
delete_transient( 'htmlpp_protection_status' );
delete_metadata( 'user', 0, 'htmlpp_review_notice', '', true );

// Clean per-user notice transients. Direct query is appropriate during
// uninstall: runs once, caching is not relevant.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_htmlpp\_%' OR option_name LIKE '\_transient\_timeout\_htmlpp\_%'"
);
