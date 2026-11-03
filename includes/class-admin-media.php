<?php
/**
 * Media Library integration: a column showing format and size, a per-image "Convert" action, and a bulk action.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Admin_Media {

	const ACTION = 'lean_uploads_convert';
	const BULK   = 'lean_uploads_convert_bulk';
	const COLOR_ACTION = 'lean_uploads_normalize_srgb';
	const COLOR_BULK = 'lean_uploads_normalize_srgb_bulk';

	/** Synchronous bulk conversions are capped; larger batches belong to WP-CLI. */
	const BULK_LIMIT = 25;

	/** @var Library */
	private $library;

	public function __construct( Library $library ) {
		$this->library = $library;
	}

	public function register_hooks(): void {
		add_filter( 'manage_media_columns', array( $this, 'column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_single' ) );
		add_action( 'admin_post_' . self::COLOR_ACTION, array( $this, 'handle_color_single' ) );
		add_action( 'admin_notices', array( $this, 'result_notice' ) );
	}

	/** @param array<string,string> $columns */
	public function column( array $columns ): array {
		$columns['lean_uploads'] = __( 'Format', 'lean-uploads' );
		return $columns;
	}

	public function column_content( string $column, int $id ): void {
		if ( 'lean_uploads' !== $column || ! wp_attachment_is_image( $id ) ) {
			return;
		}
		$info = $this->library->inspect( $id );
		if ( is_wp_error( $info ) ) {
			echo esc_html( 'missing' === $info->get_error_code() ? __( 'file missing', 'lean-uploads' ) : '' );
			return;
		}
		$label = strtoupper( (string) substr( strrchr( $info['mime'], '/' ), 1 ) );
		printf( '<strong>%s</strong> &middot; %s', esc_html( $label ), esc_html( size_format( $info['bytes'] ) ) );
		if ( ( 'convert' === $info['action'] || 'resize' === $info['action'] ) && current_user_can( 'edit_post', $id ) ) {
			printf( '<br><a href="%s">%s</a>', esc_url( $this->action_url( $id ) ), 'resize' === $info['action'] ? esc_html__( 'Resize to cap', 'lean-uploads' ) : esc_html__( 'Convert', 'lean-uploads' ) );
		} elseif ( 'opted_out' === $info['reason'] ) {
			echo '<br><span class="description">' . esc_html__( 'kept as uploaded', 'lean-uploads' ) . '</span>';
		}
	}

	/**
	 * @param array<string,string> $actions
	 * @param \WP_Post             $post
	 * @return array<string,string>
	 */
	public function row_action( array $actions, $post ): array {
		if ( wp_attachment_is_image( $post ) && current_user_can( 'edit_post', $post->ID ) ) {
			if ( ! get_post_meta( $post->ID, Pipeline::KEEP_META, true ) ) {
				$actions['lean_uploads_srgb'] = '<a href="' . esc_url( $this->color_action_url( (int) $post->ID ) ) . '">' . esc_html__( 'Normalize colors to sRGB', 'lean-uploads' ) . '</a>';
			}
			$info = $this->library->inspect( (int) $post->ID );
			if ( ! is_wp_error( $info ) && 'skip' !== $info['action'] ) {
				$actions['lean_uploads'] = '<a href="' . esc_url( $this->action_url( (int) $post->ID ) ) . '">' . esc_html__( 'Convert', 'lean-uploads' ) . '</a>';
			}
		}
		return $actions;
	}

	/** @param array<string,string> $actions */
	public function bulk_action( array $actions ): array {
		$actions[ self::BULK ] = __( 'Convert to modern format', 'lean-uploads' );
		$actions[ self::COLOR_BULK ] = __( 'Normalize colors to sRGB (tagged files only)', 'lean-uploads' );
		return $actions;
	}

	/**
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action name.
	 * @param int[]  $ids      Selected IDs.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( self::COLOR_BULK === $action && current_user_can( 'upload_files' ) ) {
			return $this->color_results( (string) $redirect, array_map( 'intval', (array) $ids ) );
		}
		if ( self::BULK !== $action || ! current_user_can( 'upload_files' ) ) {
			return $redirect;
		}
		$done = 0; $skipped = 0; $failed = 0; $saved = 0; $left = 0;
		$ids = array_map( 'intval', (array) $ids );
		foreach ( $ids as $i => $id ) {
			if ( $i >= self::BULK_LIMIT ) {
				++$left;
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				++$skipped;
				continue;
			}
			$r = $this->library->convert_attachment( $id, false );
			if ( is_wp_error( $r ) ) {
				'skip' === $r->get_error_code() ? ++$skipped : ++$failed;
				continue;
			}
			++$done;
			$saved += $r['bytes_before'] - $r['bytes_after'];
		}
		return add_query_arg(
			array( 'lean_done' => $done, 'lean_skipped' => $skipped, 'lean_failed' => $failed, 'lean_saved' => max( 0, $saved ), 'lean_left' => $left ),
			remove_query_arg( array( 'lean_done', 'lean_skipped', 'lean_failed', 'lean_saved', 'lean_left' ), $redirect )
		);
	}

	public function handle_single(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You cannot edit this attachment.', 'lean-uploads' ) );
		}
		check_admin_referer( self::ACTION . '_' . $id );
		$r    = $this->library->convert_attachment( $id, false );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' );
		$back = remove_query_arg( array( 'lean_done', 'lean_skipped', 'lean_failed', 'lean_saved', 'lean_left' ), $back );
		if ( is_wp_error( $r ) ) {
			$back = add_query_arg( 'skip' === $r->get_error_code() ? array( 'lean_skipped' => 1 ) : array( 'lean_failed' => 1 ), $back );
		} else {
			$back = add_query_arg( array( 'lean_done' => 1, 'lean_saved' => max( 0, $r['bytes_before'] - $r['bytes_after'] ) ), $back );
		}
		wp_safe_redirect( $back );
		exit;
	}

	public function result_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$this->color_result_notice();
		if ( ! isset( $_GET['lean_done'] ) && ! isset( $_GET['lean_skipped'] ) && ! isset( $_GET['lean_failed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$done    = (int) ( $_GET['lean_done'] ?? 0 );    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = (int) ( $_GET['lean_skipped'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed  = (int) ( $_GET['lean_failed'] ?? 0 );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved   = (int) ( $_GET['lean_saved'] ?? 0 );   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$left    = (int) ( $_GET['lean_left'] ?? 0 );    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$class   = $failed ? 'notice-warning' : 'notice-success';
		$text    = sprintf(
			/* translators: 1: converted count, 2: skipped count, 3: failed count, 4: bytes saved */
			__( 'Lean Uploads: %1$d converted, %2$d skipped, %3$d failed, %4$s saved.', 'lean-uploads' ),
			$done,
			$skipped,
			$failed,
			size_format( $saved )
		);
		if ( $left ) {
			/* translators: %d: number of selected items not processed */
			$text .= ' ' . sprintf( __( '%d selected items were not processed; the bulk action handles 25 at a time. Use WP-CLI for large batches.', 'lean-uploads' ), $left );
		}
		if ( $done ) {
			$text .= ' ' . __( 'Content that referenced the old file URLs was not rewritten; use `wp lean-uploads convert --update-references` for that.', 'lean-uploads' );
		}
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $text ) );
	}

	/** A separate operation: retains URLs/formats and never guesses a missing profile. */
	public function handle_color_single(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! $id || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You cannot edit this attachment.', 'lean-uploads' ) );
		}
		check_admin_referer( self::COLOR_ACTION . '_' . $id );
		$back = wp_get_referer() ?: admin_url( 'upload.php' );
		wp_safe_redirect( $this->color_results( $back, array( $id ) ) );
		exit;
	}

	private function color_results( string $redirect, array $ids ): string {
		$service = new Color_Library();
		$done = 0; $skipped = 0; $failed = 0; $untagged = 0; $recovery = 0;
		$ids = array_values( array_unique( $ids ) );
		foreach ( array_slice( $ids, 0, self::BULK_LIMIT ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				++$skipped;
				continue;
			}
			$result = $service->normalize_attachment( $id );
			if ( is_wp_error( $result ) ) {
				'skip' === $result->get_error_code() ? ++$skipped : ++$failed;
				if ( get_option( Color_Library::LOCK_PREFIX . $id, false ) ) {
					++$recovery;
				}
			} else {
				$done += $result['converted'];
				$untagged += $result['untagged'];
			}
		}
		$keys = array( 'lean_color_done', 'lean_color_skipped', 'lean_color_failed', 'lean_color_untagged', 'lean_color_left', 'lean_color_recovery', 'lean_done', 'lean_skipped', 'lean_failed', 'lean_saved', 'lean_left' );
		return add_query_arg( array(
			'lean_color_done' => $done, 'lean_color_skipped' => $skipped, 'lean_color_failed' => $failed,
			'lean_color_untagged' => $untagged, 'lean_color_left' => max( 0, count( $ids ) - self::BULK_LIMIT ), 'lean_color_recovery' => $recovery,
		), remove_query_arg( $keys, $redirect ) );
	}

	private function color_result_notice(): void {
		if ( ! isset( $_GET['lean_color_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$done = max( 0, (int) $_GET['lean_color_done'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = max( 0, (int) ( $_GET['lean_color_skipped'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed = max( 0, (int) ( $_GET['lean_color_failed'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$untagged = max( 0, (int) ( $_GET['lean_color_untagged'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$text = sprintf(
			/* translators: 1: file count, 2: attachment count, 3: attachment count, 4: file count */
			__( 'Lean Uploads: %1$d image files normalized to sRGB; %2$d attachments skipped, %3$d failed. %4$d untagged files in processed attachments were left unchanged. URLs and dimensions are preserved.', 'lean-uploads' ),
			$done, $skipped, $failed, $untagged
		);
		if ( ! empty( $_GET['lean_color_left'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$text .= ' ' . __( 'Only the first 25 selected attachments were processed. Use WP-CLI for larger batches.', 'lean-uploads' );
		}
		if ( ! empty( $_GET['lean_color_recovery'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$text .= ' ' . __( 'An attachment is locked by a running or interrupted conversion. Ask the site administrator to inspect its recovery journal before retrying.', 'lean-uploads' );
		}
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', $failed ? 'notice-warning' : 'notice-success', esc_html( $text ) );
	}

	private function color_action_url( int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::COLOR_ACTION . '&id=' . $id ), self::COLOR_ACTION . '_' . $id );
	}

	private function action_url( int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&id=' . $id ), self::ACTION . '_' . $id );
	}
}
