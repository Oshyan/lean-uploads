<?php
/**
 * Removes the plugin's option on uninstall, on every site of a network. Converted images are left as they are:
 * they are the site's images now. The `_lean_uploads_keep` flag on opted-out attachments is also left; it is
 * inert without the plugin and harmless if the plugin returns.
 *
 * @package LeanUploads
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $lean_uploads_site_id ) {
		switch_to_blog( (int) $lean_uploads_site_id );
		delete_option( 'lean_uploads' );
		restore_current_blog();
	}
} else {
	delete_option( 'lean_uploads' );
}
