<?php
/**
 * The image pipeline, built on top of WordPress's own.
 *
 * WordPress already does the hard part well: wp_create_image_subsizes() reads the uploaded original once,
 * rotates it per EXIF, scales it down to the "big image" threshold, converts it to the output format the
 * `image_editor_output_format` filter names, and cuts every registered size from that same original. Each
 * derived file is therefore one encode away from the source, never two.
 *
 * This class steers that machinery (threshold = our cap, output format = our target, quality = ours) and then
 * finishes the job core refuses to: it deletes the original, drops the "-scaled" suffix, and updates the mime type.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Pipeline {

	const KEEP_META = '_lean_uploads_keep';

	/** @var Settings */
	private $settings;
	/** @var Capabilities */
	private $caps;
	/** @var Converter */
	private $converter;
	/** @var Notices */
	private $notices;

	/** @var string[] Paths of files stored as uploaded in this request (from the opt-out token). */
	private static $kept_paths = array();

	/** @var bool True while core is generating metadata for an opted-out attachment. */
	private static $processing_kept = false;

	/** @var bool Re-entrancy guard for the one case where finalize() regenerates sizes itself. */
	private static $finalizing = false;

	/** @var callable|null Active "GD first" editor-order filter, if any. */
	private static $gd_first = null;

	/** @var string Path of the lossless intermediate that replaced an oversized original in this request, if any. */
	private static $intermediate = '';

	/** Lossless format for the intermediate: every editor can write PNG, it keeps alpha, and it is temporary. */
	const INTERMEDIATE_MIME = 'image/png';

	public function __construct( Settings $settings, Capabilities $caps, Converter $converter, Notices $notices ) {
		$this->settings  = $settings;
		$this->caps      = $caps;
		$this->converter = $converter;
		$this->notices   = $notices;
	}

	public function register_hooks(): void {
		add_filter( 'image_editor_output_format', array( $this, 'output_format' ), 20, 3 );
		add_filter( 'wp_editor_set_quality', array( $this, 'quality' ), 20, 2 );
		add_action( 'add_attachment', array( $this, 'flag_kept_attachment' ) );
		add_filter( 'big_image_size_threshold', array( $this, 'begin_attachment' ), 1, 4 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'finalize' ), PHP_INT_MAX - 10, 3 );
	}

	/**
	 * Support map in the shape Policy expects.
	 *
	 * @return array<string,bool>
	 */
	public function support_map(): array {
		return array(
			'image/webp' => $this->caps->supports( 'image/webp' ),
			'image/avif' => $this->caps->supports( 'image/avif' ),
			'image/heic' => $this->caps->supports( 'image/heic' ),
			'lossless'   => $this->caps->imagick_can_write( 'WEBP' ),
		);
	}

	/* ------------------------------------------------------------------ opt-out bookkeeping */

	/** Called by the upload handler when a file was stored as uploaded. */
	public static function remember_kept( string $path ): void {
		self::$kept_paths[] = $path;
	}

	/**
	 * Persist "opted out" on the attachment so later regenerations respect it too.
	 *
	 * @param int $attachment_id New attachment.
	 */
	public function flag_kept_attachment( $attachment_id ): void {
		$file = get_attached_file( (int) $attachment_id );
		if ( $file && in_array( $file, self::$kept_paths, true ) ) {
			update_post_meta( (int) $attachment_id, self::KEEP_META, 1 );
		}
	}

	private static function is_kept_path( $filename ): bool {
		if ( ! is_string( $filename ) || '' === $filename ) {
			return false;
		}
		foreach ( self::$kept_paths as $kept ) {
			if ( Policy::path_belongs_to( $filename, $kept ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ steering core */

	/**
	 * `big_image_size_threshold` is the first filter core runs inside wp_create_image_subsizes() and carries the
	 * attachment ID and dimensions: the earliest point to learn which attachment the following filter calls belong
	 * to, to set the size cap, and to steer huge images away from Imagick before it fails.
	 *
	 * @param int|false $threshold     Core default (2560) or whatever an earlier filter set.
	 * @param array     $imagesize     [width, height, ...].
	 * @param string    $file          Original file.
	 * @param int       $attachment_id Attachment.
	 * @return int|false
	 */
	public function begin_attachment( $threshold, $imagesize, $file, $attachment_id ) {
		self::$processing_kept = $attachment_id && ( get_post_meta( (int) $attachment_id, self::KEEP_META, true ) || in_array( (string) $file, self::$kept_paths, true ) );
		$s = $this->settings->get();

		$w = (int) ( $imagesize[0] ?? 0 );
		$h = (int) ( $imagesize[1] ?? 0 );
		if ( null === self::$gd_first && $this->converter->exceeds_imagick_cache( $w, $h ) ) {
			self::$gd_first = static function ( $editors ) {
				$editors = (array) $editors;
				usort(
					$editors,
					static function ( $a, $b ) {
						return (int) ( false !== stripos( (string) $a, 'imagick' ) ) - (int) ( false !== stripos( (string) $b, 'imagick' ) );
					}
				);
				return $editors;
			};
			add_filter( 'wp_image_editors', self::$gd_first, 999 );
		}

		if ( self::$processing_kept || empty( $s['enabled'] ) ) {
			return $threshold;
		}
		$max = (int) $s['max_dimension'];
		if ( $max <= 0 ) {
			return $threshold;
		}
		if ( null === Policy::fit( $w, $h, $max ) ) {
			return $max; // within the cap: core proceeds normally with our cap as its threshold
		}

		/*
		 * Oversized: resample ONCE, losslessly, to the cap, and let that file stand in for the original.
		 * Core then cuts the converted full size and every sub-size from this small lossless file instead of
		 * decoding a huge original twice (and, on hosts with tight Imagick limits, failing twice).
		 * Metadata was already read from the true original a few lines earlier in core, so nothing is lost.
		 */
		$tmp = $file . '.lean-intermediate.png';
		$res = $this->converter->convert( (string) $file, $tmp, self::INTERMEDIATE_MIME, array( 'quality' => 100, 'max_dimension' => $max ) );
		if ( is_wp_error( $res ) || ! rename( $tmp, (string) $file ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $max; // let core try it the slow way
		}
		clearstatcache( true, (string) $file );
		self::$intermediate = (string) $file;
		if ( null !== self::$gd_first ) {
			// The image core will now work on is small; Imagick is the better editor again.
			remove_filter( 'wp_image_editors', self::$gd_first, 999 );
			self::$gd_first = null;
		}
		// Returning 0 disables core's own scaling; the format conversion of the full size still happens.
		return 0;
	}

	private static function end_attachment(): void {
		self::$processing_kept = false;
		self::$intermediate    = '';
		if ( null !== self::$gd_first ) {
			remove_filter( 'wp_image_editors', self::$gd_first, 999 );
			self::$gd_first = null;
		}
	}

	/**
	 * Output format for the converted full size and every generated size.
	 *
	 * @param array  $formats  mime => mime map.
	 * @param string $filename Path being processed (may be empty for sub-sizes).
	 * @param string $mime     Source mime.
	 * @return array
	 */
	public function output_format( $formats, $filename, $mime ) {
		$s = $this->settings->get();
		if ( self::$processing_kept || self::is_kept_path( $filename ) ) {
			return $formats;
		}
		if ( empty( $s['enabled'] ) && empty( $s['derivatives'] ) ) {
			return $formats;
		}
		$target = Policy::target_mime( $s, $this->support_map() );
		if ( '' === $target ) {
			return $formats;
		}
		$formats = is_array( $formats ) ? $formats : array();
		$sources = array();
		if ( ! empty( $s['convert_jpeg'] ) ) {
			$sources[] = 'image/jpeg';
		}
		if ( ! empty( $s['convert_png'] ) ) {
			$sources[] = 'image/png';
		}
		if ( ! empty( $s['convert_other'] ) ) {
			array_push( $sources, 'image/bmp', 'image/tiff', 'image/heic' );
		}
		if ( ! empty( $s['convert_gif'] ) ) {
			$sources[] = 'image/gif'; // Animated GIFs never reach here: the upload handler opts them out.
		}
		foreach ( $sources as $from ) {
			$formats[ $from ] = $target;
		}
		if ( in_array( $mime, Policy::MODERN, true ) && $mime !== $target ) {
			$formats[ $mime ] = $target;
		}
		return $formats;
	}

	/**
	 * @param int    $quality Current quality.
	 * @param string $mime    Output mime.
	 * @return int
	 */
	public function quality( $quality, $mime ) {
		if ( in_array( $mime, Policy::MODERN, true ) ) {
			return (int) $this->settings->get()['quality'];
		}
		return $quality;
	}

	/* ------------------------------------------------------------------ finishing the job */

	/**
	 * Runs after core generated the converted/scaled full size and all sub-sizes from the original.
	 * Removes the original, normalises the file name, fixes the mime type. Falls back to converting the
	 * full size itself when core did not (output format mapping off, or core's editor failed).
	 *
	 * @param array  $metadata      Metadata about to be saved.
	 * @param int    $attachment_id Attachment.
	 * @param string $context       'create' or 'update'.
	 * @return array
	 */
	public function finalize( $metadata, $attachment_id, $context = 'create' ) {
		$attachment_id = (int) $attachment_id;
		try {
			if ( self::$finalizing || ! is_array( $metadata ) || empty( $metadata['file'] ) || ! wp_attachment_is_image( $attachment_id ) ) {
				return $metadata;
			}
			$s = $this->settings->get();
			if ( empty( $s['enabled'] ) || self::$processing_kept || get_post_meta( $attachment_id, self::KEEP_META, true ) ) {
				return $metadata;
			}
			return $this->finish( $metadata, $attachment_id, $s );
		} finally {
			self::end_attachment();
		}
	}

	/**
	 * @param array $metadata
	 * @param array $s Settings.
	 * @return array
	 */
	private function finish( array $metadata, int $id, array $s ): array {
		$file = get_attached_file( $id, true );
		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}
		$dir      = dirname( $file );
		$original = ! empty( $metadata['original_image'] ) ? $dir . '/' . $metadata['original_image'] : $file;
		if ( ! file_exists( $original ) ) {
			$original = $file;
		}
		$orig_mime = wp_get_image_mime( $original );
		if ( ! $orig_mime ) {
			return $metadata;
		}
		$support  = $this->support_map();
		$animated = ( 'image/gif' === $orig_mime ) && Policy::is_animated_gif( $original );
		$decision = Policy::decide( $orig_mime, basename( $original ), array_merge( $s, array( 'keep_token' => '' ) ), $support, $animated );
		if ( ! $decision['convert'] && ! $decision['resize_only'] ) {
			return $metadata;
		}
		$target    = $decision['target_mime'];
		$orig_size = wp_getimagesize( $original );
		$orig_px   = $orig_size ? array( (int) $orig_size[0], (int) $orig_size[1] ) : array( 0, 0 );

		// Did core already produce what we want? (converted and/or scaled full size, distinct from the original)
		$file_mime = wp_get_image_mime( $file );
		if ( $file === $original || $file_mime !== $target ) {
			if ( $decision['convert'] ) {
				$converted = $this->convert_full_myself( $metadata, $id, $original, $decision, $s );
				if ( is_wp_error( $converted ) ) {
					$this->notices->add_failure( basename( $original ), $converted->get_error_message() );
					do_action( 'lean_uploads_failed', array( 'name' => basename( $original ) ), $converted );
					return $metadata;
				}
				$metadata = $converted;
				$file     = get_attached_file( $id, true );
			} else {
				return $metadata; // modern format, within the cap: nothing to do
			}
		}

		$final_px = array( (int) ( $metadata['width'] ?? 0 ), (int) ( $metadata['height'] ?? 0 ) );
		$resized  = $final_px !== $orig_px || ( '' !== self::$intermediate && self::$intermediate === $original );
		if ( '' !== self::$intermediate && ! empty( $metadata['image_meta'] ) ) {
			// The intermediate was written upright; the EXIF orientation read from the true original no longer applies.
			$metadata['image_meta']['orientation'] = 1;
		}

		// Never trade a file for a bigger one unless its dimensions also shrank.
		if ( $file !== $original && ! $resized && wp_filesize( $file ) >= wp_filesize( $original ) && apply_filters( 'lean_uploads_keep_smaller_original', true ) ) {
			wp_delete_file( $file );
			update_attached_file( $id, $original );
			$metadata['file']     = _wp_relative_upload_path( $original );
			$metadata['filesize'] = wp_filesize( $original );
			unset( $metadata['original_image'] );
			return $metadata;
		}

		// The original has done its job.
		if ( $file !== $original ) {
			wp_delete_file( $original );
		}
		unset( $metadata['original_image'] );

		// "photo-scaled.webp" => "photo.webp" when that name is free.
		$plain = $dir . '/' . Policy::rename( basename( $original ), Policy::EXT[ $target ] ?? pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( $plain !== $file && ! file_exists( $plain ) && rename( $file, $plain ) ) {
			$file = $plain;
			update_attached_file( $id, $file );
		}
		$metadata['file']     = _wp_relative_upload_path( $file );
		$metadata['filesize'] = wp_filesize( $file );

		$mime_now = wp_get_image_mime( $file );
		if ( $mime_now && get_post_mime_type( $id ) !== $mime_now ) {
			wp_update_post( array( 'ID' => $id, 'post_mime_type' => $mime_now ) );
		}

		/**
		 * Fires after an attachment's original was replaced by its converted version.
		 *
		 * @param int   $id       Attachment ID.
		 * @param array $metadata Final metadata.
		 */
		do_action( 'lean_uploads_converted', $id, $metadata );
		return $metadata;
	}

	/**
	 * Core did not convert the full size (format mapping off, or its editor failed): do it here, from the
	 * original, and regenerate the sizes from the result only when core produced none.
	 *
	 * @return array|\WP_Error Updated metadata.
	 */
	private function convert_full_myself( array $metadata, int $id, string $original, array $decision, array $s ) {
		$dir  = dirname( $original );
		$dest = $dir . '/' . Policy::rename( basename( $original ), $decision['ext'] );
		if ( file_exists( $dest ) && $dest !== $original ) {
			$dest = $dir . '/' . wp_unique_filename( $dir, basename( $dest ) );
		}
		if ( $dest === $original ) {
			$dest = $original . '.lean-tmp.' . $decision['ext'];
		}
		$result = $this->converter->convert(
			$original,
			$dest,
			$decision['target_mime'],
			array( 'quality' => (int) $s['quality'], 'max_dimension' => (int) $s['max_dimension'], 'lossless' => ! empty( $decision['lossless'] ) )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_attached_file( $id, $dest );
		$metadata['file']           = _wp_relative_upload_path( $dest );
		$metadata['width']          = (int) $result['width'];
		$metadata['height']         = (int) $result['height'];
		$metadata['filesize']       = (int) $result['bytes_after'];
		$metadata['original_image'] = basename( $original );

		if ( empty( $metadata['sizes'] ) ) {
			// Core could not cut sizes from the original (editor failure); cut them from the converted file.
			self::$finalizing = true;
			try {
				$regen = wp_generate_attachment_metadata( $id, $dest );
			} finally {
				self::$finalizing = false;
			}
			if ( is_array( $regen ) && ! empty( $regen['sizes'] ) ) {
				$metadata['sizes'] = $regen['sizes'];
			}
		}
		return $metadata;
	}
}
