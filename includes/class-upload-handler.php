<?php
/**
 * The upload hooks. Two jobs, both small: honour the opt-out token, and keep animated GIFs away from the
 * conversion pipeline. Everything else happens after WordPress has stored the original (see Pipeline).
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Upload_Handler {

	/** @var Settings */
	private $settings;

	/** @var bool Set when the current upload opted out, consumed once the file has landed. */
	private $pending_keep = false;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register_hooks(): void {
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'prefilter' ), 50 );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'prefilter' ), 50 );
		// Fires for both uploads and sideloads once the file is in place; the final path is known here.
		add_filter( 'wp_handle_upload', array( $this, 'after_move' ), 10, 2 );
	}

	/**
	 * @param array $file One $_FILES-style entry: name, type, tmp_name, error, size.
	 * @return array
	 */
	public function prefilter( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) || ! empty( $file['error'] ) ) {
			return $file;
		}
		$s     = $this->settings->get();
		$token = (string) ( $s['keep_token'] ?? '' );
		$name  = (string) $file['name'];

		if ( '' !== $token && false !== strpos( $name, $token ) ) {
			$file['name']       = Policy::strip_token( $name, $token );
			$this->pending_keep = true;
			return $file;
		}

		// An animated GIF must stay a GIF: flag it like an opt-out so no filter converts it.
		if ( ! empty( $file['tmp_name'] ) && is_readable( $file['tmp_name'] ) && 'image/gif' === wp_get_image_mime( $file['tmp_name'] ) && Policy::is_animated_gif( $file['tmp_name'] ) ) {
			$this->pending_keep = true;
		}
		return $file;
	}

	/**
	 * @param array  $upload file, url, type.
	 * @param string $action Unused.
	 * @return array
	 */
	public function after_move( $upload, $action = '' ) {
		if ( $this->pending_keep && is_array( $upload ) && ! empty( $upload['file'] ) ) {
			Pipeline::remember_kept( (string) $upload['file'] );
		}
		$this->pending_keep = false;
		return $upload;
	}
}
