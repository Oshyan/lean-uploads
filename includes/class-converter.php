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
			$result   = null;
			$src_mime = (string) ( $size['mime'] ?? '' );
			$too_big  = $this->exceeds_imagick_cache( (int) $size[0], (int) $size[1] );
			if ( null !== $fit && $this->caps->has_imagick() && ( 'image/jpeg' === $src_mime || ! $too_big ) ) {
				// Direct Imagick for resizes: JPEGs are decoded already downscaled (libjpeg DCT scaling, which also
				// sidesteps the pixel-cache limit), and PNG output uses fast compression since an intermediate is
				// temporary. The core editor would write PNG at level 9 and take four times as long. Falls through
				// to the editor path on any failure.
				$result = $this->convert_direct_imagick( $source, $dest, $target_mime, $quality, $max, 'image/jpeg' === $src_mime );
				if ( is_wp_error( $result ) ) {
					$result = null;
				}
			}
			if ( null === $result && $lossless && 'image/webp' === $target_mime && $this->caps->imagick_can_write( 'WEBP' ) && ! $this->exceeds_imagick_cache( (int) $size[0], (int) $size[1] ) ) {
				$result = $this->convert_lossless_imagick( $source, $dest, $fit );
			} elseif ( null === $result ) {
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
		if ( ! class_exists( 'WP_Image_Editor_GD', false ) && function_exists( '_wp_image_editor_choose' ) ) {
			_wp_image_editor_choose(); // loads the core editor classes; they are not autoloaded
		}
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
		// We name the output format explicitly; the site-wide output-format mapping (including this plugin's own,
		// which turns PNG into WebP) must not second-guess it, or a lossless intermediate comes out lossy.
		$no_mapping = static function () {
			return array();
		};
		add_filter( 'wp_image_editors', $only, PHP_INT_MAX );
		add_filter( 'image_editor_output_format', $no_mapping, PHP_INT_MAX );
		try {
			$editor = wp_get_image_editor( $source, array( 'mime_type' => $target_mime ) );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}
			if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
				$editor->maybe_exif_rotate();
			}
			if ( null !== $fit ) {
				// Fit within a square box: correct whichever way EXIF rotation just turned the image.
				$box = max( $fit[0], $fit[1] );
				$r   = $editor->resize( $box, $box, false );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
			$editor->set_quality( $quality );
			$saved = $editor->save( $dest, $target_mime );
		} finally {
			remove_filter( 'wp_image_editors', $only, PHP_INT_MAX );
			remove_filter( 'image_editor_output_format', $no_mapping, PHP_INT_MAX );
		}
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
	 * Resize with Imagick directly: optional libjpeg DCT-scaled decode (the "jpeg:size" hint), EXIF auto-orient,
	 * Lanczos fit within the cap, fast PNG settings for intermediates, explicit quality for lossy targets.
	 *
	 * @return true|\WP_Error
	 */
	private function convert_direct_imagick( string $source, string $dest, string $target_mime, int $quality, int $max, bool $dct ) {
		if ( ! $this->caps->has_imagick() || $max <= 0 ) {
			return new \WP_Error( 'lean_uploads_no_imagick', 'Imagick unavailable.' );
		}
		$format = strtoupper( (string) ( Policy::EXT[ $target_mime ] ?? substr( strrchr( $target_mime, '/' ), 1 ) ) );
		if ( ! $this->caps->imagick_can_write( $format ) ) {
			return new \WP_Error( 'lean_uploads_imagick_format', "Imagick cannot write $format." );
		}
		$im = new \Imagick();
		if ( $dct ) {
			// libjpeg picks the smallest DCT scale (1/2, 1/4, 1/8) that still yields at least this size.
			$im->setOption( 'jpeg:size', $max . 'x' . $max );
		}
		$im->readImage( $source );
		if ( method_exists( $im, 'autoOrient' ) ) {
			$im->autoOrient();
		}
		$fit = Policy::fit( $im->getImageWidth(), $im->getImageHeight(), $max );
		if ( null !== $fit ) {
			$im->resizeImage( $fit[0], $fit[1], \Imagick::FILTER_LANCZOS, 1 );
		}
		$im->setImageFormat( $format );
		if ( 'PNG' === $format ) {
			// Lossless either way; level 1 writes in a quarter of the time of WordPress's level 9.
			$im->setOption( 'png:compression-level', '1' );
			$im->setOption( 'png:compression-filter', '0' );
		} else {
			$im->setImageCompressionQuality( $quality );
		}
		// Drop EXIF/IPTC/XMP like core does, but keep the colour profile so wide-gamut sources don't shift.
		$icc = $im->getImageProfiles( 'icc', true );
		$im->stripImage();
		if ( ! empty( $icc['icc'] ) ) {
			$im->profileImage( 'icc', $icc['icc'] );
		}
		$ok = $im->writeImage( $dest );
		$im->clear();
		return $ok ? true : new \WP_Error( 'lean_uploads_imagick_write', 'Imagick could not write the file.' );
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
