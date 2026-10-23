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
 * It only ever does that to attachments it was explicitly handed: new uploads (noted at `add_attachment`) and
 * attachments the Library class asks it to process. Core regenerates sub-sizes in other contexts too (its own
 * "create image subsizes" AJAX, regeneration plugins, `wp media regenerate`), some of which any user with
 * `upload_files` can trigger for any attachment; those never touch originals here.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Pipeline {

	const KEEP_META = '_lean_uploads_keep';

	/** Lossless format for the intermediate: every editor can write PNG, it keeps alpha, and it is temporary. */
	const INTERMEDIATE_MIME = 'image/png';

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

	/** @var array<int,bool> Attachment IDs this plugin may finalize in this request. */
	private static $intended = array();

	/** @var array|null The attachment core is currently processing: id, kept, intended, intermediate, source_mime. */
	private static $current = null;

	/** @var bool Re-entrancy guard for the one case where finalize() regenerates sizes itself. */
	private static $finalizing = false;

	/** @var callable|null Active "GD first" editor-order filter, if any. */
	private static $gd_first = null;

	public function __construct( Settings $settings, Capabilities $caps, Converter $converter, Notices $notices ) {
		$this->settings  = $settings;
		$this->caps      = $caps;
		$this->converter = $converter;
		$this->notices   = $notices;
	}

	public function register_hooks(): void {
		add_filter( 'image_editor_output_format', array( $this, 'output_format' ), 20, 3 );
		add_filter( 'wp_editor_set_quality', array( $this, 'quality' ), 20, 2 );
		add_action( 'add_attachment', array( $this, 'note_new_attachment' ) );
		add_filter( 'big_image_size_threshold', array( $this, 'begin_attachment' ), 1, 4 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'finalize' ), PHP_INT_MAX - 10, 3 );
		add_filter( 'wp_unique_filename', array( $this, 'unique_filename' ), 10, 3 );
	}

	/**
	 * Keep a new upload's base name clear of the converted files of earlier uploads.
	 *
	 * Core only checks for existing files with the uploaded extension, so after "photo.jpg" became "photo.webp"
	 * and "photo-300x200.webp", a second "photo.jpg" (or "photo.png") would be accepted and core's editors would
	 * write its thumbnails over the first attachment's. See Policy::free_name().
	 *
	 * @param string $filename Name core settled on.
	 * @param string $ext      Extension with the dot.
	 * @param string $dir      Directory.
	 * @return string
	 */
	public function unique_filename( $filename, $ext, $dir ) {
		$filename = (string) $filename;
		$ext      = strtolower( ltrim( (string) $ext, '.' ) );
		if ( '' === $ext || ! in_array( $ext, array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'webp', 'avif' ), true ) ) {
			return $filename;
		}
		if ( empty( $this->settings->get()['enabled'] ) || ! is_dir( (string) $dir ) ) {
			return $filename;
		}
		$existing = scandir( (string) $dir );
		if ( ! is_array( $existing ) ) {
			return $filename;
		}
		return Policy::free_name( $filename, $existing, array_values( Policy::EXT ) );
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

	/* ------------------------------------------------------------------ intent and opt-out bookkeeping */

	/** Called by the upload handler when a file was stored as uploaded. */
	public static function remember_kept( string $path ): void {
		self::$kept_paths[] = $path;
	}

	/** Mark an attachment as one this plugin may convert in this request (Library does this before regenerating). */
	public static function intend( int $attachment_id ): void {
		self::$intended[ $attachment_id ] = true;
	}

	/**
	 * A new attachment: it is ours to process, and if its file came through the opt-out, persist that on the
	 * attachment so later regenerations respect it too.
	 *
	 * @param int $attachment_id New attachment.
	 */
	public function note_new_attachment( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		self::intend( $attachment_id );
		$file = get_attached_file( $attachment_id, true );
		if ( $file && in_array( $file, self::$kept_paths, true ) ) {
			update_post_meta( $attachment_id, self::KEEP_META, 1 );
		}
	}

	/* ------------------------------------------------------------------ steering core */

	/**
	 * `big_image_size_threshold` is the first filter core runs inside wp_create_image_subsizes() and carries the
	 * attachment ID and dimensions: the earliest point to learn which attachment the following filter calls belong
	 * to, to set the size cap, and to produce the lossless intermediate for oversized images.
	 *
	 * @param int|false $threshold     Core default (2560) or whatever an earlier filter set.
	 * @param array     $imagesize     [width, height, ..., 'mime' => ...].
	 * @param string    $file          Original file.
	 * @param int       $attachment_id Attachment.
	 * @return int|false
	 */
	public function begin_attachment( $threshold, $imagesize, $file, $attachment_id ) {
		if ( self::$finalizing ) {
			return $threshold; // the nested regeneration in convert_full_myself(); the outer record stays in charge
		}
		self::end_attachment();
		$attachment_id = (int) $attachment_id;
		$file          = (string) $file;
		$kept          = $attachment_id && ( get_post_meta( $attachment_id, self::KEEP_META, true ) || in_array( $file, self::$kept_paths, true ) );
		self::$current = array(
			'id'           => $attachment_id,
			'kept'         => (bool) $kept,
			'intended'     => ! empty( self::$intended[ $attachment_id ] ),
			'intermediate' => '', // path of the lossless intermediate standing in for an oversized original
			'aside'        => '', // where the true original was moved while the intermediate stands in for it
			'done'         => false,
			'source_mime'  => (string) ( $imagesize['mime'] ?? '' ),
		);
		$s = $this->settings->get();
		if ( $kept || empty( $s['enabled'] ) || ! self::$current['intended'] || ! self::within_uploads( $file ) ) {
			return $threshold; // not ours: core's own behaviour, untouched
		}

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
		 * decoding a huge original twice. Metadata was already read from the true original a few lines earlier
		 * in core, so nothing is lost. The true original is moved aside (not replaced) until the conversion
		 * has succeeded; if anything fails it is put back, so a failed attempt changes nothing.
		 */
		$token = substr( md5( uniqid( (string) $attachment_id, true ) ), 0, 10 );
		$tmp   = $file . '.' . $token . '.lean-tmp.png';
		$aside = $file . '.' . $token . '.lean-original';
		if ( ! self::claim( $tmp ) || ! self::claim( $aside ) ) {
			self::delete_in_uploads( $tmp );
			self::delete_in_uploads( $aside );
			return $max;
		}
		$res = $this->converter->convert( $file, $tmp, self::INTERMEDIATE_MIME, array( 'quality' => 100, 'max_dimension' => $max ) );
		if ( is_wp_error( $res ) || ! rename( $file, $aside ) ) {
			self::delete_in_uploads( $tmp );
			self::delete_in_uploads( $aside );
			return $max; // let core try it the slow way
		}
		if ( ! rename( $tmp, $file ) ) {
			rename( $aside, $file ); // put the original back
			self::delete_in_uploads( $tmp );
			return $max;
		}
		clearstatcache( true, $file );
		self::$current['intermediate'] = $file;
		self::$current['aside']        = $aside;
		if ( null !== self::$gd_first ) {
			// The image core will now work on is small; Imagick is the better editor again.
			remove_filter( 'wp_image_editors', self::$gd_first, 999 );
			self::$gd_first = null;
		}
		// Returning 0 disables core's own scaling; the format conversion of the full size still happens.
		return 0;
	}

	private static function end_attachment(): void {
		$c = self::$current;
		if ( $c && '' !== $c['aside'] ) {
			if ( ! empty( $c['done'] ) ) {
				self::delete_in_uploads( $c['aside'] ); // converted successfully: the original has done its job
			} elseif ( file_exists( $c['aside'] ) && self::within_uploads( $c['intermediate'] ) ) {
				rename( $c['aside'], $c['intermediate'] ); // not converted: the true original goes back where it was
				clearstatcache( true, $c['intermediate'] );
			}
		}
		self::$current = null;
		if ( null !== self::$gd_first ) {
			remove_filter( 'wp_image_editors', self::$gd_first, 999 );
			self::$gd_first = null;
		}
	}

	/**
	 * Output format for generated sizes, and for the converted full size of attachments we own.
	 *
	 * Core calls this with a file name for the full-size save and with none for sub-sizes, which is how the two
	 * are told apart: thumbnails follow the "Thumbnails" setting for every attachment; the full size is only
	 * converted for attachments this plugin was handed (so a regeneration triggered by someone else never
	 * produces a converted copy of an original we are not allowed to remove).
	 *
	 * @param array       $formats  mime => mime map.
	 * @param string|null $filename Path being written, or null for sub-sizes.
	 * @param string      $mime     Source mime.
	 * @return array
	 */
	public function output_format( $formats, $filename, $mime ) {
		$s = $this->settings->get();
		$c = self::$current;
		if ( $c && $c['kept'] ) {
			return $formats;
		}
		$full = is_string( $filename ) && '' !== $filename;
		$ours = $c && $c['intended'] && ! empty( $s['enabled'] );
		if ( $full && ! $ours ) {
			return $formats;
		}
		if ( ! $full && empty( $s['derivatives'] ) ) {
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
		if ( $c && '' !== $c['intermediate'] ) {
			$sources[] = 'image/png'; // the intermediate is PNG whatever the upload was; it must be converted
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
	 * Per-format quality.
	 *
	 * @param int    $quality Current quality.
	 * @param string $mime    Output mime.
	 * @return int
	 */
	public function quality( $quality, $mime ) {
		$q = $this->settings->quality_for( (string) $mime );
		return null === $q ? $quality : $q;
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
		$c             = self::$current;
		try {
			if ( self::$finalizing || ! is_array( $metadata ) || empty( $metadata['file'] ) || ! wp_attachment_is_image( $attachment_id ) ) {
				return $metadata;
			}
			if ( ! $c || $c['id'] !== $attachment_id || ! $c['intended'] || $c['kept'] ) {
				return $metadata; // not an attachment we were handed in this request
			}
			$s = $this->settings->get();
			if ( empty( $s['enabled'] ) ) {
				return $metadata;
			}
			return $this->finish( $metadata, $attachment_id, $s, $c );
		} finally {
			self::end_attachment();
		}
	}

	/**
	 * @param array $metadata
	 * @param array $s Settings.
	 * @param array $c Current-attachment record.
	 * @return array
	 */
	private function finish( array $metadata, int $id, array $s, array $c ): array {
		$file = get_attached_file( $id, true );
		if ( ! $file || ! file_exists( $file ) || ! self::within_uploads( $file ) ) {
			return $metadata;
		}
		$dir      = dirname( $file );
		$original = ! empty( $metadata['original_image'] ) ? $dir . '/' . wp_basename( $metadata['original_image'] ) : $file;
		if ( ! file_exists( $original ) || ! self::within_uploads( $original ) ) {
			$original = $file;
		}
		// The intermediate is PNG bytes under the uploaded name; decide by what was actually uploaded.
		$orig_mime = ( '' !== $c['intermediate'] && $original === $c['intermediate'] && '' !== $c['source_mime'] )
			? $c['source_mime']
			: wp_get_image_mime( $original );
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
			if ( $decision['convert'] || '' !== $c['intermediate'] ) {
				$converted = $this->convert_full_myself( $metadata, $id, $original, $target, $decision, $s );
				if ( is_wp_error( $converted ) ) {
					$this->notices->add_failure( basename( $original ), $converted );
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
		$resized  = $final_px !== $orig_px || '' !== $c['intermediate'];
		if ( '' !== $c['intermediate'] && ! empty( $metadata['image_meta'] ) ) {
			// The intermediate was written upright; the EXIF orientation read from the true original no longer applies.
			$metadata['image_meta']['orientation'] = 1;
		}

		// Never trade a file for a bigger one unless its dimensions also shrank.
		if ( $file !== $original && ! $resized && wp_filesize( $file ) >= wp_filesize( $original ) && apply_filters( 'lean_uploads_keep_smaller_original', true ) ) {
			self::delete_in_uploads( $file );
			update_attached_file( $id, $original );
			$metadata['file']     = _wp_relative_upload_path( $original );
			$metadata['filesize'] = wp_filesize( $original );
			unset( $metadata['original_image'] );
			return $metadata;
		}

		if ( empty( $metadata['sizes'] ) && $file !== $original && $this->expects_sizes( $final_px[0], $final_px[1], $metadata, $id ) ) {
			// Neither core nor the fallback could cut thumbnails: keep the original beside the converted full size
			// (core's own "-scaled" arrangement) rather than trade it for an attachment with no sizes.
			$metadata['original_image'] = wp_basename( $original );
			$metadata['file']           = _wp_relative_upload_path( $file );
			$metadata['filesize']       = wp_filesize( $file );
			$this->update_mime( $id, $file );
			$this->notices->add_failure( basename( $original ), new \WP_Error( 'lean_uploads_sizes', 'Converted, but no thumbnails could be generated; the original was kept.' ) );
			return $metadata;
		}

		// The original has done its job.
		if ( $file !== $original ) {
			self::delete_in_uploads( $original );
		}
		unset( $metadata['original_image'] );
		if ( self::$current && self::$current['id'] === $id ) {
			self::$current['done'] = true;
		}

		// "photo-scaled.webp" => "photo.webp" when that name is free. Claim it exclusively first: a plain
		// file_exists() check followed by rename() could replace a file another upload just created.
		$plain = $dir . '/' . Policy::rename( basename( $original ), Policy::EXT[ $target ] ?? pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( $plain !== $file && self::claim( $plain ) ) {
			if ( rename( $file, $plain ) ) {
				$file = $plain;
				update_attached_file( $id, $file );
			} else {
				self::delete_in_uploads( $plain );
			}
		}
		$metadata['file']     = _wp_relative_upload_path( $file );
		$metadata['filesize'] = wp_filesize( $file );

		$this->update_mime( $id, $file );

		/**
		 * Fires after an attachment's original was replaced by its converted version.
		 *
		 * @param int   $id       Attachment ID.
		 * @param array $metadata Final metadata.
		 */
		do_action( 'lean_uploads_converted', $id, $metadata );
		return $metadata;
	}

	/** Set post_mime_type from the file's bytes, without re-saving the whole post through the content filters. */
	private function update_mime( int $id, string $file ): void {
		global $wpdb;
		$mime_now = wp_get_image_mime( $file );
		if ( $mime_now && get_post_mime_type( $id ) !== $mime_now ) {
			$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $mime_now ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) );
			clean_post_cache( $id );
		}
	}

	/** Whether an image of this size should have produced at least one registered sub-size. */
	private function expects_sizes( int $w, int $h, array $metadata, int $id ): bool {
		$sizes = apply_filters( 'intermediate_image_sizes_advanced', wp_get_registered_image_subsizes(), $metadata, $id );
		foreach ( (array) $sizes as $size ) {
			$sw = (int) ( $size['width'] ?? 0 );
			$sh = (int) ( $size['height'] ?? 0 );
			if ( ( $sw > 0 && $w > $sw ) || ( $sh > 0 && $h > $sh ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Core did not convert the full size (format mapping off, or its editor failed): do it here, from the
	 * original, and regenerate the sizes from the result only when core produced none.
	 *
	 * @return array|\WP_Error Updated metadata.
	 */
	private function convert_full_myself( array $metadata, int $id, string $original, string $target, array $decision, array $s ) {
		$dir  = dirname( $original );
		$dest = $dir . '/' . Policy::rename( basename( $original ), Policy::EXT[ $target ] ?? 'webp' );
		if ( $dest === $original || ! self::claim( $dest ) ) {
			$dest = $dir . '/' . wp_unique_filename( $dir, basename( $dest ) );
			if ( ! self::claim( $dest ) ) {
				return new \WP_Error( 'lean_uploads_claim', 'Could not reserve a file name for the converted image.' );
			}
		}
		$result = $this->converter->convert(
			$original,
			$dest,
			$target,
			array( 'quality' => $this->settings->quality_for( $target ) ?? 75, 'max_dimension' => (int) $s['max_dimension'], 'lossless' => ! empty( $decision['lossless'] ) )
		);
		if ( is_wp_error( $result ) ) {
			self::delete_in_uploads( $dest );
			return $result;
		}
		update_attached_file( $id, $dest );
		$metadata['file']           = _wp_relative_upload_path( $dest );
		$metadata['width']          = (int) $result['width'];
		$metadata['height']         = (int) $result['height'];
		$metadata['filesize']       = (int) $result['bytes_after'];
		$metadata['original_image'] = wp_basename( $original );

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

	/* ------------------------------------------------------------------ file-system guards */

	/** Whether a path resolves inside the uploads directory (symlinks resolved). */
	public static function within_uploads( string $path ): bool {
		$base = wp_get_upload_dir()['basedir'] ?? '';
		$real_base = $base ? realpath( $base ) : false;
		$real_path = realpath( $path );
		if ( ! $real_base || ! $real_path ) {
			// A path that does not exist yet (a new file we are about to create): check its directory instead.
			$real_path = realpath( dirname( $path ) );
			if ( ! $real_base || ! $real_path ) {
				return false;
			}
			return 0 === strpos( $real_path . '/', trailingslashit( $real_base ) );
		}
		return 0 === strpos( $real_path . '/', trailingslashit( $real_base ) );
	}

	/** Delete a file only if it lives inside the uploads directory. */
	public static function delete_in_uploads( string $path ): void {
		$base = wp_get_upload_dir()['basedir'] ?? '';
		if ( '' !== $base && file_exists( $path ) ) {
			wp_delete_file_from_directory( $path, $base );
		}
	}

	/**
	 * Create a path exclusively (fails if it exists). The caller then renames its real file over this placeholder.
	 */
	public static function claim( string $path ): bool {
		if ( ! self::within_uploads( $path ) ) {
			return false;
		}
		$fh = @fopen( $path, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $fh ) {
			return false;
		}
		fclose( $fh );
		return true;
	}
}
