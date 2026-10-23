<?php
/**
 * The upload hook. Runs before WordPress moves the file into the uploads folder, so the original
 * never lands on disk: WordPress only ever sees the converted file.
 *
 * Covers the Media Library uploader, the block editor, the REST API (`/wp/v2/media`), XML-RPC,
 * `media_sideload_image()`, and `wp media import`, because all of them pass through
 * `wp_handle_upload_prefilter` or `wp_handle_sideload_prefilter`.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Upload_Handler {

	/** @var Settings */
	private $settings;
	/** @var Capabilities */
	private $caps;
	/** @var Converter */
	private $converter;
	/** @var Notices */
	private $notices;

	/** @var array<string,array> Results keyed by tmp path, for tests and the self-test command. */
	public $last = array();

	public function __construct( Settings $settings, Capabilities $caps, Converter $converter, Notices $notices ) {
		$this->settings  = $settings;
		$this->caps      = $caps;
		$this->converter = $converter;
		$this->notices   = $notices;
	}

	/** @var bool Set when the current upload opted out, consumed once the file has landed. */
	private $pending_keep = false;

	public function register_hooks(): void {
		// Late priority: let validation/renaming plugins run first; we change name, type and bytes together.
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'handle' ), 50 );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'handle' ), 50 );
		// Fires for both uploads and sideloads once the file is in place; final path known here.
		add_filter( 'wp_handle_upload', array( $this, 'after_move' ), 10, 2 );
	}

	/**
	 * @param array  $upload file, url, type.
	 * @param string $action Unused.
	 * @return array
	 */
	public function after_move( $upload, $action = '' ) {
		if ( $this->pending_keep && is_array( $upload ) && ! empty( $upload['file'] ) ) {
			Derivatives::remember_kept( (string) $upload['file'] );
		}
		$this->pending_keep = false;
		return $upload;
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

	/**
	 * Prefilter callback. Must never block an upload: on any failure the file passes through untouched.
	 *
	 * @param array $file One $_FILES-style entry: name, type, tmp_name, error, size.
	 * @return array
	 */
	public function handle( $file ) {
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return $file;
		}
		$tmp = (string) $file['tmp_name'];
		if ( isset( $this->last[ $tmp ] ) || ! is_readable( $tmp ) ) {
			return $file;
		}
		$name = (string) ( $file['name'] ?? '' );

		// Cheap pre-check before touching bytes: only image-like uploads are considered at all.
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'bmp', 'tif', 'tiff', 'webp', 'avif', 'heic', 'heif' ), true ) ) {
			return $file;
		}

		$mime = wp_get_image_mime( $tmp );
		if ( ! $mime ) {
			return $file;
		}
		$settings = $this->settings->get();
		$animated = ( 'image/gif' === $mime ) && Policy::is_animated_gif( $tmp );
		$decision = Policy::decide( $mime, $name, $settings, $this->support_map(), $animated );

		/**
		 * Last word on whether to touch this upload at all.
		 *
		 * @param bool   $convert  Whether the file will be converted or resized.
		 * @param array  $file     The upload entry.
		 * @param string $mime     Sniffed mime type.
		 * @param array  $decision Policy result.
		 */
		$proceed = apply_filters( 'lean_uploads_should_convert', ( $decision['convert'] || $decision['resize_only'] ), $file, $mime, $decision );

		if ( 'keep_token' === $decision['reason'] ) {
			$file['name']       = $decision['keep_name'];
			$this->pending_keep = true;
		}
		if ( ! $proceed ) {
			$this->last[ $tmp ] = array( 'skipped' => $decision['reason'] );
			return $file;
		}

		$opts = array(
			'quality'       => (int) $settings['quality'],
			'max_dimension' => (int) $settings['max_dimension'],
			'lossless'      => ! empty( $decision['lossless'] ),
		);

		if ( $decision['resize_only'] ) {
			$result = $this->converter->resize_in_place( $tmp, $mime, $opts['quality'], $opts['max_dimension'] );
			if ( is_wp_error( $result ) ) {
				if ( 'lean_uploads_no_resize' !== $result->get_error_code() ) {
					$this->fail( $file, $result );
				}
				$this->last[ $tmp ] = array( 'skipped' => 'within_cap' );
				return $file;
			}
			$file['size']       = (int) filesize( $tmp );
			$this->last[ $tmp ] = $result;
			do_action( 'lean_uploads_converted', $file, $result );
			return $file;
		}

		// Convert to a sibling temp path, then copy the bytes back over the original tmp path.
		// Keeping the path identical matters: PHP's move_uploaded_file() only accepts the path it received.
		$dest   = $tmp . '.lean.' . $decision['ext'];
		$result = $this->converter->convert( $tmp, $dest, $decision['target_mime'], $opts );
		if ( is_wp_error( $result ) ) {
			$this->fail( $file, $result );
			$this->last[ $tmp ] = array( 'error' => $result->get_error_message() );
			return $file;
		}
		// Never trade a file for a bigger one unless we also shrank its dimensions (then the trade is the point).
		if ( ! $result['resized'] && $result['bytes_after'] >= $result['bytes_before'] && apply_filters( 'lean_uploads_keep_smaller_original', true ) ) {
			wp_delete_file( $dest );
			$this->last[ $tmp ] = array( 'skipped' => 'not_smaller' );
			return $file;
		}
		if ( ! copy( $dest, $tmp ) ) {
			wp_delete_file( $dest );
			$this->fail( $file, new \WP_Error( 'lean_uploads_copy', 'Could not replace the uploaded file.' ) );
			return $file;
		}
		wp_delete_file( $dest );
		clearstatcache( true, $tmp );

		$file['name'] = Policy::rename( $file['name'], $decision['ext'] );
		$file['type'] = $decision['target_mime'];
		$file['size'] = (int) filesize( $tmp );

		$result['path']     = $tmp;
		$this->last[ $tmp ] = $result;

		/**
		 * Fires after an upload was converted.
		 *
		 * @param array $file   The (now modified) upload entry.
		 * @param array $result Converter result: width, height, mime, bytes_before, bytes_after, resized.
		 */
		do_action( 'lean_uploads_converted', $file, $result );
		return $file;
	}

	private function fail( array $file, \WP_Error $error ): void {
		$this->notices->add_failure( (string) ( $file['name'] ?? '' ), $error->get_error_message() );
		/**
		 * Fires when conversion failed and the original was kept.
		 *
		 * @param array     $file  The upload entry.
		 * @param \WP_Error $error Why.
		 */
		do_action( 'lean_uploads_failed', $file, $error );
	}
}
