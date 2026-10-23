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
			echo esc_html( $info->get_error_message() );
			return;
		}
		$label = strtoupper( (string) substr( strrchr( $info['mime'], '/' ), 1 ) );
		printf( '<strong>%s</strong> &middot; %s', esc_html( $label ), esc_html( size_format( $info['bytes'] ) ) );
		if ( 'convert' === $info['action'] || 'resize' === $info['action'] ) {
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
		$done = 0; $skipped = 0; $failed = 0; $saved = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
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
		return add_query_arg( array( 'lean_done' => $done, 'lean_skipped' => $skipped, 'lean_failed' => $failed, 'lean_saved' => max( 0, $saved ) ), remove_query_arg( array( 'lean_done', 'lean_skipped', 'lean_failed', 'lean_saved' ), $redirect ) );
	}

	public function handle_single(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You cannot edit this attachment.', 'lean-uploads' ) );
		}
		check_admin_referer( self::ACTION . '_' . $id );
		$r    = $this->library->convert_attachment( $id, false );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' );
		$back = remove_query_arg( array( 'lean_done', 'lean_skipped', 'lean_failed', 'lean_saved', 'lean_error' ), $back );
		if ( is_wp_error( $r ) ) {
			$back = add_query_arg( 'skip' === $r->get_error_code() ? array( 'lean_skipped' => 1 ) : array( 'lean_failed' => 1, 'lean_error' => rawurlencode( $r->get_error_message() ) ), $back );
		} else {
			$back = add_query_arg( array( 'lean_done' => 1, 'lean_saved' => max( 0, $r['bytes_before'] - $r['bytes_after'] ) ), $back );
		}
		wp_safe_redirect( $back );
		exit;
	}

	public function result_notice(): void {
		if ( ! isset( $_GET['lean_done'] ) && ! isset( $_GET['lean_skipped'] ) && ! isset( $_GET['lean_failed'] ) ) {
			return;
		}
		$done    = (int) ( $_GET['lean_done'] ?? 0 );
		$skipped = (int) ( $_GET['lean_skipped'] ?? 0 );
		$failed  = (int) ( $_GET['lean_failed'] ?? 0 );
		$saved   = (int) ( $_GET['lean_saved'] ?? 0 );
		$error   = isset( $_GET['lean_error'] ) ? sanitize_text_field( wp_unslash( $_GET['lean_error'] ) ) : '';
		$class   = $failed ? 'notice-warning' : 'notice-success';
		$text    = sprintf(
			/* translators: 1: converted count, 2: skipped count, 3: failed count, 4: bytes saved */
			__( 'Lean Uploads: %1$d converted, %2$d skipped, %3$d failed, %4$s saved.', 'lean-uploads' ),
			$done,
			$skipped,
			$failed,
			size_format( $saved )
		);
		if ( $done ) {
			$text .= ' ' . __( 'Content that referenced the old file URLs was not rewritten; use `wp lean-uploads convert --update-references` for that.', 'lean-uploads' );
		}
		printf( '<div class="notice %s is-dismissible"><p>%s%s</p></div>', esc_attr( $class ), esc_html( $text ), $error ? ' <code>' . esc_html( $error ) . '</code>' : '' );
	}

	private function action_url( int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&id=' . $id ), self::ACTION . '_' . $id );
	}
}
