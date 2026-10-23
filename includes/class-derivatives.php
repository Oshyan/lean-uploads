<?php
/**
 * Thumbnails (sub-sizes) in the efficient format, via the two core filters that exist for exactly this.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Derivatives {

	/** @var Settings */
	private $settings;
	/** @var Capabilities */
	private $caps;

	public function __construct( Settings $settings, Capabilities $caps ) {
		$this->settings = $settings;
		$this->caps     = $caps;
	}

	const KEEP_META = '_lean_uploads_keep';

	/** @var string[] Paths of files stored as uploaded in this request (from the opt-out token). */
	private static $kept_paths = array();

	/** @var bool True while core is generating metadata for an opted-out attachment. */
	private static $processing_kept = false;

	public function register_hooks(): void {
		add_filter( 'image_editor_output_format', array( $this, 'output_format' ), 20, 3 );
		add_filter( 'wp_editor_set_quality', array( $this, 'quality' ), 20, 2 );
		add_action( 'add_attachment', array( $this, 'flag_kept_attachment' ) );
		add_filter( 'big_image_size_threshold', array( $this, 'begin_attachment' ), 1, 4 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'end_attachment' ), PHP_INT_MAX );
	}

	/** Called by the upload handler when a file was stored as uploaded. */
	public static function remember_kept( string $path ): void {
		self::$kept_paths[] = $path;
	}

	/**
	 * Persist "opted out" on the attachment so later regenerations (wp media regenerate, plugins) respect it too.
	 *
	 * @param int $attachment_id New attachment.
	 */
	public function flag_kept_attachment( $attachment_id ): void {
		$file = get_attached_file( (int) $attachment_id );
		if ( $file && in_array( $file, self::$kept_paths, true ) ) {
			update_post_meta( (int) $attachment_id, self::KEEP_META, 1 );
		}
	}

	/**
	 * `big_image_size_threshold` is the first filter core runs inside wp_create_image_subsizes() and it carries the
	 * attachment ID, which makes it the earliest point to learn which attachment the following output-format
	 * calls belong to (they do not always carry a file name).
	 *
	 * @param int|false $threshold     Unchanged.
	 * @param array     $imagesize     Unused.
	 * @param string    $file          Original file.
	 * @param int       $attachment_id Attachment.
	 * @return int|false
	 */
	public function begin_attachment( $threshold, $imagesize, $file, $attachment_id ) {
		self::$processing_kept = $attachment_id && ( get_post_meta( (int) $attachment_id, self::KEEP_META, true ) || in_array( (string) $file, self::$kept_paths, true ) );
		return $threshold;
	}

	/**
	 * @param array $metadata Unchanged.
	 * @return array
	 */
	public function end_attachment( $metadata ) {
		self::$processing_kept = false;
		return $metadata;
	}

	/** Whether a file path (when one is supplied) belongs to an opted-out upload of this request. */
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

	/**
	 * Map source mime types to the output format for generated sizes.
	 *
	 * @param array  $formats  mime => mime map.
	 * @param string $filename Path being processed.
	 * @param string $mime     Source mime.
	 * @return array
	 */
	public function output_format( $formats, $filename, $mime ) {
		$s = $this->settings->get();
		if ( empty( $s['derivatives'] ) || self::$processing_kept || self::is_kept_path( $filename ) ) {
			return $formats;
		}
		$target = Policy::target_mime(
			$s,
			array(
				'image/webp' => $this->caps->supports( 'image/webp' ),
				'image/avif' => $this->caps->supports( 'image/avif' ),
			)
		);
		if ( '' === $target ) {
			return $formats;
		}
		$formats = is_array( $formats ) ? $formats : array();
		foreach ( array( 'image/jpeg', 'image/png', 'image/bmp', 'image/tiff', 'image/heic' ) as $from ) {
			$formats[ $from ] = $target;
		}
		// A WebP original producing AVIF thumbnails (or vice versa) is allowed; same-format stays as is.
		if ( in_array( $mime, Policy::MODERN, true ) && $mime !== $target ) {
			$formats[ $mime ] = $target;
		}
		return $formats;
	}

	/**
	 * Apply the configured quality to modern-format output. Core's own default for WebP is 86.
	 *
	 * @param int    $quality Current quality.
	 * @param string $mime    Output mime.
	 * @return int
	 */
	public function quality( $quality, $mime ) {
		if ( in_array( $mime, Policy::MODERN, true ) ) {
			$s = $this->settings->get();
			if ( ! empty( $s['derivatives'] ) || ! empty( $s['enabled'] ) ) {
				return (int) $s['quality'];
			}
		}
		return $quality;
	}
}
