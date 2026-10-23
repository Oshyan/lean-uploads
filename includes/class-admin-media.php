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
		return $actions;
	}

	/**
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action name.
	 * @param int[]  $ids      Selected IDs.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
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

	private function action_url( int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&id=' . $id ), self::ACTION . '_' . $id );
	}
}
