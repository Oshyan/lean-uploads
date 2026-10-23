<?php
/**
 * Tells the uploading user when a file could not be converted (the original was kept).
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

	public function add_failure( string $filename, string $reason ): void {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return; // Nobody to tell; the action hook still fires for logging.
		}
		$list   = get_transient( self::TRANSIENT . $uid );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array( 'file' => $filename, 'reason' => $reason );
		set_transient( self::TRANSIENT . $uid, array_slice( $list, -10 ), 10 * MINUTE_IN_SECONDS );
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
			printf( '<li><code>%s</code> &mdash; %s</li>', esc_html( $item['file'] ), esc_html( $item['reason'] ) );
		}
		echo '</ul></div>';
	}
}
