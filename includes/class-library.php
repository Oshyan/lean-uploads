<?php
/**
 * Converting attachments that already exist in the Media Library. Shared by WP-CLI and the admin actions.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Library {

	/** @var Settings */
	private $settings;
	/** @var Converter */
	private $converter;
	/** @var Upload_Handler */
	private $uploads;

	/** @var bool Set once references were rewritten by direct SQL; a persistent object cache then holds stale posts. */
	private static $cache_dirty = false;

	/** Whether callers should flush the object cache after a run (search-replace writes bypass it). */
	public static function needs_cache_flush(): bool {
		return self::$cache_dirty;
	}

	public static function flush_cache_if_needed(): void {
		if ( self::$cache_dirty ) {
			wp_cache_flush();
			self::$cache_dirty = false;
		}
	}

	public function __construct( Settings $settings, Converter $converter, Upload_Handler $uploads ) {
		$this->settings  = $settings;
		$this->converter = $converter;
		$this->uploads   = $uploads;
	}

	/**
	 * IDs of attachments the current settings would convert or resize.
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
	 * @return array{action:string,reason:string,mime:string,target:string,bytes:int,path:string}|\WP_Error
	 */
	public function inspect( int $id ) {
		$path = get_attached_file( $id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new \WP_Error( 'missing', 'file missing on disk' );
		}
		$mime = wp_get_image_mime( $path );
		if ( ! $mime ) {
			return new \WP_Error( 'skip', 'not an image' );
		}
		$settings = array_merge( $this->settings->get(), array( 'keep_token' => '' ) );
		$animated = ( 'image/gif' === $mime ) && Policy::is_animated_gif( $path );
		$decision = Policy::decide( $mime, basename( $path ), $settings, $this->uploads->support_map(), $animated );
		$action   = 'skip';
		if ( get_post_meta( $id, Derivatives::KEEP_META, true ) ) {
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
			'lossless' => ! empty( $decision['lossless'] ),
			'bytes'  => (int) filesize( $path ),
			'path'   => $path,
		);
	}

	/**
	 * Convert (or resize) one attachment in place: new file, regenerated sizes, old files removed,
	 * optionally old URLs rewritten across the database.
	 *
	 * @param int  $id                Attachment ID.
	 * @param bool $update_references Rewrite old URLs via `wp search-replace` (WP-CLI only; ignored elsewhere).
	 * @return array{old_name:string,new_name:string,bytes_before:int,bytes_after:int,urls:array}|\WP_Error
	 */
	public function convert_attachment( int $id, bool $update_references = false ) {
		$info = $this->inspect( $id );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( 'skip' === $info['action'] ) {
			return new \WP_Error( 'skip', $info['reason'] );
		}
		$settings = $this->settings->get();
		$path     = $info['path'];
		$dir      = dirname( $path );
		$new_name = Policy::rename( basename( $path ), $info['ext'] );
		// wp_unique_filename() would add "-1" because the old file's thumbnails share the base name;
		// those are deleted below, so only a genuine clash on the exact target path needs a new name.
		if ( file_exists( $dir . '/' . $new_name ) && $dir . '/' . $new_name !== $path ) {
			$new_name = wp_unique_filename( $dir, $new_name );
		}
		$dest = $dir . '/' . $new_name;
		$tmp  = ( $dest === $path ) ? $path . '.lean-tmp' : $dest;

		$result = $this->converter->convert(
			$path,
			$tmp,
			$info['target'],
			array( 'quality' => (int) $settings['quality'], 'max_dimension' => (int) $settings['max_dimension'], 'lossless' => $info['lossless'] )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'convert' === $info['action'] && ! $result['resized'] && $result['bytes_after'] >= $info['bytes'] && $this->keep_smaller_original() ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'skip', 'converted file would not be smaller' );
		}

		$old_meta = wp_get_attachment_metadata( $id );
		$old_meta = is_array( $old_meta ) ? $old_meta : array();
		$old_urls = $this->all_urls( wp_get_attachment_url( $id ), $old_meta );

		// Old files go first so regenerated thumbnails cannot collide with or be mistaken for them.
		$this->delete_old_files( $path, $old_meta, $tmp );
		if ( $tmp !== $dest && ! rename( $tmp, $dest ) ) {
			return new \WP_Error( 'lean_uploads_rename', 'Could not place the converted file.' );
		}
		update_attached_file( $id, $dest );
		wp_update_post( array( 'ID' => $id, 'post_mime_type' => $info['target'] ) );
		$new_meta = wp_generate_attachment_metadata( $id, $dest );
		wp_update_attachment_metadata( $id, $new_meta );
		$new_urls = $this->all_urls( wp_get_attachment_url( $id ), is_array( $new_meta ) ? $new_meta : array() );

		$pairs = array();
		foreach ( $old_urls as $key => $old_url ) {
			$new_url = $new_urls[ $key ] ?? $new_urls['full'];
			if ( $old_url !== $new_url ) {
				$pairs[ $old_url ] = $new_url;
			}
		}
		if ( $update_references && $pairs ) {
			$this->rewrite_references( $pairs );
		}

		/**
		 * Fires after an existing attachment was converted in place.
		 *
		 * @param int   $id    Attachment ID.
		 * @param array $pairs old URL => new URL for the original and each size.
		 */
		do_action( 'lean_uploads_attachment_converted', $id, $pairs );

		return array(
			'old_name'     => basename( $path ),
			'new_name'     => $new_name,
			'bytes_before' => (int) $info['bytes'],
			'bytes_after'  => (int) $result['bytes_after'],
			'urls'         => $pairs,
		);
	}

	/** EWWW's rule, and a good one: never replace a file with a bigger one unless we also resized it. */
	private function keep_smaller_original(): bool {
		return (bool) apply_filters( 'lean_uploads_keep_smaller_original', true );
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
			$out['original_image'] = $base . $meta['original_image'];
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $info ) {
			if ( ! empty( $info['file'] ) ) {
				$out[ $size ] = $base . $info['file'];
			}
		}
		return $out;
	}

	private function delete_old_files( string $path, array $meta, string $spare ): void {
		$dir = dirname( $path );
		if ( $path !== $spare ) {
			wp_delete_file( $path );
		}
		if ( ! empty( $meta['original_image'] ) ) {
			wp_delete_file( $dir . '/' . $meta['original_image'] );
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $info ) {
			if ( ! empty( $info['file'] ) && $dir . '/' . $info['file'] !== $spare ) {
				wp_delete_file( $dir . '/' . $info['file'] );
			}
		}
	}

	/**
	 * Serialization-safe URL rewriting by delegating to `wp search-replace`. Only available under WP-CLI;
	 * in the admin we leave content alone and say so.
	 *
	 * @param array<string,string> $pairs old => new.
	 */
	private function rewrite_references( array $pairs ): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		foreach ( $pairs as $old_url => $new_url ) {
			$r = \WP_CLI::runcommand(
				sprintf( 'search-replace %s %s --all-tables-with-prefix --skip-columns=guid --report=0 --quiet', escapeshellarg( $old_url ), escapeshellarg( $new_url ) ),
				array( 'exit_error' => false, 'launch' => false, 'return' => 'all' )
			);
			// search-replace writes straight to the database; on hosts with a persistent object cache the
			// cached copies of those rows are now stale until the cache is flushed.
			if ( is_object( $r ) && isset( $r->stdout ) && ! preg_match( '/Made 0 replacements/', (string) $r->stdout ) ) {
				self::$cache_dirty = true;
			}
		}
	}
}
