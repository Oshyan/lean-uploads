<?php
/**
 * Tells the uploading user when a file could not be converted (the original was kept).
 *
 * Users see a fixed, non-technical message. The detailed reason (which can contain server paths and library
 * internals) goes to the error log when WP_DEBUG is on, and to the `lean_uploads_failed` action for anyone
 * who wants it.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Notices {

	const TRANSIENT = 'lean_uploads_notice_';

	public function register_hooks(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * @param string           $filename File that could not be converted (basename only is shown).
	 * @param \WP_Error|string $detail   Technical reason; logged, never shown.
	 */
	public function add_failure( string $filename, $detail ): void {
		$reason = is_wp_error( $detail ) ? $detail->get_error_message() : (string) $detail;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'Lean Uploads: could not convert "%s": %s', wp_basename( $filename ), $reason ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return; // Nobody to tell; the action hook still fires for logging.
		}
		$list   = get_transient( self::TRANSIENT . $uid );
		$list   = is_array( $list ) ? $list : array();
		$list[] = wp_basename( $filename );
		set_transient( self::TRANSIENT . $uid, array_slice( array_unique( $list ), -10 ), 10 * MINUTE_IN_SECONDS );
	}

	public function render(): void {
		$uid = get_current_user_id();
		if ( ! $uid || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$list = get_transient( self::TRANSIENT . $uid );
		if ( empty( $list ) || ! is_array( $list ) ) {
			return;
		}
		delete_transient( self::TRANSIENT . $uid );
		echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Lean Uploads', 'lean-uploads' ) . '</strong> ' . esc_html__( 'could not convert these files, so the originals were kept:', 'lean-uploads' ) . '</p><ul>';
		foreach ( $list as $item ) {
			printf( '<li><code>%s</code></li>', esc_html( (string) $item ) );
		}
		echo '</ul></div>';
	}
}
