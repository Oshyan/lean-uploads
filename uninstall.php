<?php
/**
 * Removes the plugin's option on uninstall. Converted images are left as they are: they are the site's images now.
 *
 * @package LeanUploads
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lean_uploads' );
