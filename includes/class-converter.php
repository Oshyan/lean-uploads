<?php
/**
 * Turns one image file into another: rotate per EXIF, fit within the cap, encode in the target format.
 *
 * Uses WordPress's own image editor (Imagick or GD, whichever core would pick) so behaviour matches
 * thumbnail generation on the same host. Lossless WebP is the one thing the core editor cannot express,
 * so that path talks to Imagick directly when it is available.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Converter {

	/** @var Capabilities */
	private $caps;

	public function __construct( Capabilities $caps ) {
		$this->caps = $caps;
	}

	/**
	 * Convert $source into $dest.
	 *
	 * @param string $source      Readable image path.
	 * @param string $dest        Destination path (will be overwritten).
	 * @param string $target_mime image/webp or image/avif.
	 * @param array  $opts        quality (int), max_dimension (int), lossless (bool).
	 * @return array{path:string,width:int,height:int,mime:string,bytes_before:int,bytes_after:int,resized:bool}|\WP_Error
	 */
	public function convert( string $source, string $dest, string $target_mime, array $opts ) {
		$quality  = (int) ( $opts['quality'] ?? 75 );
		$max      = (int) ( $opts['max_dimension'] ?? 0 );
		$lossless = ! empty( $opts['lossless'] );

		if ( ! is_readable( $source ) ) {
			return new \WP_Error( 'lean_uploads_unreadable', 'Source file is not readable.' );
		}
		$before = (int) filesize( $source );
		$size   = wp_getimagesize( $source );
		if ( ! $size ) {
			return new \WP_Error( 'lean_uploads_not_image', 'Source is not a readable image.' );
		}
		$fit = Policy::fit( (int) $size[0], (int) $size[1], $max );

		/**
		 * Ceiling on source pixels, above which the file is left alone rather than risking PHP's memory or time
		 * limits. 120 megapixels is above any phone or DSLR output and above core's own practical limits.
		 *
		 * @param int $max_pixels Default 120000000.
		 */
		$max_pixels = (int) apply_filters( 'lean_uploads_max_pixels', 120000000 );
		if ( $max_pixels > 0 && ( (int) $size[0] * (int) $size[1] ) > $max_pixels ) {
			return new \WP_Error( 'lean_uploads_too_large', sprintf( 'Image is %dx%d, above the %s megapixel ceiling.', $size[0], $size[1], number_format_i18n( $max_pixels / 1000000 ) ) );
		}

		wp_raise_memory_limit( 'image' );

		try {
			if ( $lossless && 'image/webp' === $target_mime && $this->caps->imagick_can_write( 'WEBP' ) && ! $this->exceeds_imagick_cache( (int) $size[0], (int) $size[1] ) ) {
				$result = $this->convert_lossless_imagick( $source, $dest, $fit );
			} else {
				$result = $this->convert_with_editor( $source, $dest, $target_mime, $quality, $fit, (int) $size[0], (int) $size[1] );
			}
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'lean_uploads_exception', $e->getMessage() );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Trust nothing: verify the file on disk is what we meant to write.
		clearstatcache( true, $dest );
		$after = file_exists( $dest ) ? (int) filesize( $dest ) : 0;
		$mime  = $after > 0 ? wp_get_image_mime( $dest ) : false;
		if ( $after <= 0 || $mime !== $target_mime ) {
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			return new \WP_Error( 'lean_uploads_bad_output', sprintf( 'Encoder produced %s instead of %s.', $mime ? $mime : 'nothing', $target_mime ) );
		}
		$dims = wp_getimagesize( $dest );

		return array(
			'path'         => $dest,
			'width'        => $dims ? (int) $dims[0] : 0,
			'height'       => $dims ? (int) $dims[1] : 0,
			'mime'         => $target_mime,
			'bytes_before' => $before,
			'bytes_after'  => $after,
			'resized'      => null !== $fit,
		);
	}

	/**
	 * Resize only (no format change): used for WebP/AVIF uploads that exceed the cap.
	 *
	 * @return array|\WP_Error Same shape as convert(), or WP_Error 'lean_uploads_no_resize' if nothing to do.
	 */
	public function resize_in_place( string $source, string $mime, int $quality, int $max ) {
		$size = wp_getimagesize( $source );
		if ( ! $size || null === Policy::fit( (int) $size[0], (int) $size[1], $max ) ) {
			return new \WP_Error( 'lean_uploads_no_resize', 'Within cap.' );
		}
		$tmp = $source . '.lean-tmp';
		$res = $this->convert( $source, $tmp, $mime, array( 'quality' => $quality, 'max_dimension' => $max ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! copy( $tmp, $source ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'lean_uploads_copy', 'Could not replace the uploaded file.' );
		}
		wp_delete_file( $tmp );
		$res['path'] = $source;
		return $res;
	}

	/**
	 * Try WordPress's preferred editor first, then any other capable editor.
	 *
	 * Imagick is usually first and usually best, but hosts cap its pixel cache (a 60-megapixel photo
	 * exceeds a common 256MB policy and fails with "cache resources exhausted") while GD, slower but
	 * frugal, gets the same job done. Core's own thumbnail generation has no such fallback.
	 *
	 * @param array{0:int,1:int}|null $fit
	 * @return true|\WP_Error
	 */
	private function convert_with_editor( string $source, string $dest, string $target_mime, int $quality, ?array $fit, int $width = 0, int $height = 0 ) {
		$errors  = array();
		$classes = $this->editor_classes( $target_mime );
		if ( $this->exceeds_imagick_cache( $width, $height ) ) {
			// Don't spend seconds watching Imagick fail; GD first, Imagick as the fallback.
			usort(
				$classes,
				static function ( $a, $b ) {
					return (int) ( false !== stripos( $a, 'imagick' ) ) - (int) ( false !== stripos( $b, 'imagick' ) );
				}
			);
		}
		foreach ( $classes as $class ) {
			$result = $this->convert_with_editor_class( $class, $source, $dest, $target_mime, $quality, $fit );
			if ( ! is_wp_error( $result ) ) {
				return true;
			}
			$errors[] = $class . ': ' . $result->get_error_message();
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
		}
		return new \WP_Error( 'lean_uploads_editor', $errors ? implode( ' / ', $errors ) : 'No image editor can write ' . $target_mime . '.' );
	}

	/**
	 * Whether an image of these dimensions would blow past Imagick's configured pixel-cache limits.
	 *
	 * ImageMagick's pixel cache costs 8 bytes per pixel for Q16 builds and 16 for Q16 HDRI builds (the common
	 * distro default); the worst case is assumed. Hosts cap the memory and map resources via policy.xml and
	 * once an image exceeds both, ImageMagick fails ("cache resources exhausted") rather than spilling to disk.
	 */
	public function exceeds_imagick_cache( int $width, int $height ): bool {
		if ( $width <= 0 || $height <= 0 || ! $this->caps->has_imagick() ) {
			return false;
		}
		try {
			$memory = (int) \Imagick::getResourceLimit( \Imagick::RESOURCETYPE_MEMORY );
			$map    = (int) \Imagick::getResourceLimit( \Imagick::RESOURCETYPE_MAP );
			$area   = (int) \Imagick::getResourceLimit( \Imagick::RESOURCETYPE_AREA );
		} catch ( \Throwable $e ) {
			return false;
		}
		$pixels = $width * $height;
		$bytes  = $pixels * 16;
		$cap    = max( $memory, $map );
		if ( $area > 0 && $pixels > $area ) {
			return true;
		}
		return $cap > 0 && $bytes > $cap;
	}

	/**
	 * Editor classes to try, in core's order of preference, filtered to those that can write the target.
	 *
	 * @return string[]
	 */
	private function editor_classes( string $target_mime ): array {
		$classes = apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) );
		$usable  = array();
		foreach ( (array) $classes as $class ) {
			if ( is_string( $class ) && class_exists( $class ) && is_callable( array( $class, 'test' ) )
				&& call_user_func( array( $class, 'test' ), array( 'mime_type' => $target_mime ) )
				&& call_user_func( array( $class, 'supports_mime_type' ), $target_mime ) ) {
				$usable[] = $class;
			}
		}
		return $usable;
	}

	/**
	 * @param array{0:int,1:int}|null $fit
	 * @return true|\WP_Error
	 */
	private function convert_with_editor_class( string $class, string $source, string $dest, string $target_mime, int $quality, ?array $fit ) {
		$only = static function () use ( $class ) {
			return array( $class );
		};
		add_filter( 'wp_image_editors', $only, PHP_INT_MAX );
		try {
			$editor = wp_get_image_editor( $source, array( 'mime_type' => $target_mime ) );
		} finally {
			remove_filter( 'wp_image_editors', $only, PHP_INT_MAX );
		}
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		if ( null !== $fit ) {
			$r = $editor->resize( $fit[0], $fit[1], false );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		$editor->set_quality( $quality );
		$saved = $editor->save( $dest, $target_mime );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		// The editor may pick its own file name if the extension disagrees with the mime; normalise.
		if ( ! empty( $saved['path'] ) && $saved['path'] !== $dest ) {
			if ( ! rename( $saved['path'], $dest ) ) {
				return new \WP_Error( 'lean_uploads_rename', 'Could not place the converted file.' );
			}
		}
		return true;
	}

	/**
	 * @param array{0:int,1:int}|null $fit
	 * @return true|\WP_Error
	 */
	private function convert_lossless_imagick( string $source, string $dest, ?array $fit ) {
		$im = new \Imagick( $source );
		if ( method_exists( $im, 'autoOrient' ) ) {
			$im->autoOrient();
		}
		if ( null !== $fit ) {
			$im->resizeImage( $fit[0], $fit[1], \Imagick::FILTER_LANCZOS, 1 );
		}
		$im->setImageFormat( 'WEBP' );
		$im->setOption( 'webp:lossless', 'true' );
		$im->setOption( 'webp:method', '6' );
		$ok = $im->writeImage( $dest );
		$im->clear();
		return $ok ? true : new \WP_Error( 'lean_uploads_imagick_write', 'Imagick could not write the WebP file.' );
	}
}
