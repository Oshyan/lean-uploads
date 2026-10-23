<?php
/**
 * Converting attachments that already exist in the Media Library. Shared by WP-CLI and the admin actions.
 *
 * Runs the same pipeline as an upload: WordPress regenerates the full size and every sub-size from the current
 * original (one encode each), then the Pipeline finalizer removes the original. Old sub-size files that the new
 * set does not overwrite are cleaned up here. The whole thing is treated as a transaction: if regeneration
 * comes back incomplete, the previous metadata is restored and nothing is deleted.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Library {

	/** @var Settings */
	private $settings;
	/** @var Pipeline */
	private $pipeline;

	/** @var Rewriter */
	private $rewriter;

	/** @var array<int,array|\WP_Error> Per-request memo for inspect(), the Media Library asks several times per row. */
	private static $inspected = array();

	public function __construct( Settings $settings, Pipeline $pipeline, Rewriter $rewriter ) {
		$this->settings = $settings;
		$this->pipeline = $pipeline;
		$this->rewriter = $rewriter;
	}

	/**
	 * IDs of attachments the current settings might convert or resize.
	 *
	 * @return int[]
	 */
	public function candidate_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => array_merge( Policy::CONVERTIBLE, Policy::MODERN ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * What would happen to one attachment, without doing it.
	 *
	 * @return array{action:string,reason:string,mime:string,target:string,ext:string,bytes:int,path:string}|\WP_Error
	 */
	public function inspect( int $id, bool $fresh = false ) {
		if ( ! $fresh && isset( self::$inspected[ $id ] ) ) {
			return self::$inspected[ $id ];
		}
		self::$inspected[ $id ] = $this->do_inspect( $id );
		return self::$inspected[ $id ];
	}

	/** @return array|\WP_Error */
	private function do_inspect( int $id ) {
		$path = get_attached_file( $id, true );
		if ( ! $path || ! file_exists( $path ) ) {
			return new \WP_Error( 'missing', 'file missing on disk' );
		}
		if ( ! Pipeline::within_uploads( $path ) ) {
			return new \WP_Error( 'skip', 'file is outside the uploads directory' );
		}
		$mime = wp_get_image_mime( $path );
		if ( ! $mime ) {
			return new \WP_Error( 'skip', 'not an image' );
		}
		$settings = array_merge( $this->settings->get(), array( 'keep_token' => '' ) );
		$animated = ( 'image/gif' === $mime ) && Policy::is_animated_gif( $path );
		$decision = Policy::decide( $mime, basename( $path ), $settings, $this->pipeline->support_map(), $animated );
		$action   = 'skip';
		if ( get_post_meta( $id, Pipeline::KEEP_META, true ) ) {
			$decision['reason'] = 'opted_out';
		} elseif ( $decision['convert'] ) {
			$action = 'convert';
		} elseif ( $decision['resize_only'] ) {
			$size = wp_getimagesize( $path );
			if ( $size && null !== Policy::fit( (int) $size[0], (int) $size[1], (int) $settings['max_dimension'] ) ) {
				$action = 'resize';
			} else {
				$decision['reason'] = 'within_cap';
			}
		}
		return array(
			'action' => $action,
			'reason' => (string) $decision['reason'],
			'mime'   => $mime,
			'target' => (string) $decision['target_mime'],
			'ext'    => (string) $decision['ext'],
			'bytes'  => (int) filesize( $path ),
			'path'   => $path,
		);
	}

	/**
	 * Convert (or resize) one attachment in place.
	 *
	 * @param int  $id                Attachment ID.
	 * @param bool $update_references Rewrite old URLs to the new ones across the site's content, meta and options.
	 * @return array{old_name:string,new_name:string,bytes_before:int,bytes_after:int,urls:array,rows:int}|\WP_Error
	 */
	public function convert_attachment( int $id, bool $update_references = false ) {
		$info = $this->inspect( $id, true );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( 'skip' === $info['action'] ) {
			return new \WP_Error( 'skip', $info['reason'] );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$original  = $info['path'];
		$old_name  = basename( $original );
		$old_meta  = wp_get_attachment_metadata( $id );
		$old_meta  = is_array( $old_meta ) ? $old_meta : array();
		$old_urls  = $this->all_urls( wp_get_attachment_url( $id ), $old_meta );
		$old_files = $this->all_files( $original, $old_meta );

		/*
		 * Two pre-existing attachments can share a base name ("logo.jpg" and "logo.png" in the same month);
		 * regenerating the second would overwrite the first one's new thumbnails, because core never checks
		 * for existing sub-size files. Give this attachment a base name that is unique for the target extension
		 * before regenerating (wp_unique_filename also scans "-WxH" siblings).
		 */
		$dir  = dirname( $original );
		$want = Policy::rename( $old_name, $info['ext'] );
		$uniq = wp_unique_filename( $dir, $want );
		if ( pathinfo( $uniq, PATHINFO_FILENAME ) !== pathinfo( $original, PATHINFO_FILENAME ) ) {
			$moved = $dir . '/' . pathinfo( $uniq, PATHINFO_FILENAME ) . '.' . pathinfo( $original, PATHINFO_EXTENSION );
			if ( file_exists( $moved ) || ! Pipeline::within_uploads( $moved ) || ! rename( $original, $moved ) ) {
				return new \WP_Error( 'lean_uploads_rename', 'Could not give the file a unique name before converting.' );
			}
			update_attached_file( $id, $moved );
			$original = $moved;
		}

		Pipeline::intend( $id );
		$new_meta = wp_generate_attachment_metadata( $id, $original );
		$new_path = get_attached_file( $id, true );

		$complete = is_array( $new_meta ) && ! empty( $new_meta['file'] )
			&& ( empty( $old_meta['sizes'] ) || ! empty( $new_meta['sizes'] ) );
		if ( ! $complete || ( $new_path === $original && 'convert' === $info['action'] ) ) {
			// Not converting after all (regeneration incomplete, conversion failed, or the converted file would not
			// have been smaller). Put the previous state back: remove any new files core produced that the old
			// metadata does not reference, undo the unique-name move, restore the old metadata.
			if ( is_array( $new_meta ) ) {
				foreach ( $this->all_files( $new_path ?: $original, $new_meta ) as $new_file ) {
					if ( $new_file !== $original && $new_file !== $info['path'] && ! in_array( $new_file, $old_files, true ) ) {
						Pipeline::delete_in_uploads( $new_file );
					}
				}
			}
			if ( $original !== $info['path'] && file_exists( $original ) ) {
				rename( $original, $info['path'] ); // undo the unique-name move
				update_attached_file( $id, $info['path'] );
			}
			wp_update_attachment_metadata( $id, $old_meta );
			return new \WP_Error( $complete ? 'skip' : 'lean_uploads_metadata', $complete ? 'converted file would not be smaller, or conversion failed (see admin notices)' : 'WordPress could not regenerate the image; nothing was changed' );
		}
		wp_update_attachment_metadata( $id, $new_meta );

		// Remove old sub-size files the new set did not overwrite (the original itself was handled by the pipeline).
		$keep = $this->all_files( $new_path, $new_meta );
		foreach ( $old_files as $old ) {
			if ( $old !== $info['path'] && $old !== $original && ! in_array( $old, $keep, true ) ) {
				Pipeline::delete_in_uploads( $old );
			}
		}

		$new_urls = $this->all_urls( wp_get_attachment_url( $id ), $new_meta );
		$pairs    = array();
		foreach ( $old_urls as $key => $old_url ) {
			$new_url = $new_urls[ $key ] ?? $new_urls['full'];
			if ( $old_url !== $new_url ) {
				$pairs[ $old_url ] = $new_url;
			}
		}
		$rows = 0;
		if ( $update_references && $pairs ) {
			$rows = $this->rewriter->rewrite( $pairs );
		}
		unset( self::$inspected[ $id ] );

		/**
		 * Fires after an existing attachment was converted in place.
		 *
		 * @param int   $id    Attachment ID.
		 * @param array $pairs old URL => new URL for the original and each size.
		 */
		do_action( 'lean_uploads_attachment_converted', $id, $pairs );

		return array(
			'old_name'     => $old_name,
			'new_name'     => basename( $new_path ),
			'bytes_before' => (int) $info['bytes'],
			'bytes_after'  => (int) wp_filesize( $new_path ),
			'urls'         => $pairs,
			'rows'         => $rows,
		);
	}

	/**
	 * Original URL plus every sub-size URL, keyed so old and new can be paired by size name.
	 *
	 * @return array<string,string>
	 */
	private function all_urls( string $url, array $meta ): array {
		$base = trailingslashit( dirname( $url ) );
		$out  = array( 'full' => $url );
		if ( ! empty( $meta['original_image'] ) ) {
			$out['original_image'] = $base . wp_basename( $meta['original_image'] );
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $info ) {
			if ( ! empty( $info['file'] ) ) {
				$out[ $size ] = $base . wp_basename( $info['file'] );
			}
		}
		return $out;
	}

	/** @return string[] absolute paths of the file, its original_image and every size (all confined to the file's directory) */
	private function all_files( string $path, array $meta ): array {
		$dir = dirname( $path );
		$out = array( $path );
		if ( ! empty( $meta['original_image'] ) ) {
			$out[] = $dir . '/' . wp_basename( $meta['original_image'] );
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $info ) {
			if ( ! empty( $info['file'] ) ) {
				$out[] = $dir . '/' . wp_basename( $info['file'] );
			}
		}
		return $out;
	}

}
