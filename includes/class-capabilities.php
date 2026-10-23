<?php
/**
 * What this server can actually encode. Checked once per request, cached in memory.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	/** @var array<string,bool> mime => supported */
	private $support = array();

	/**
	 * Whether WordPress's chosen image editor can write this mime type.
	 *
	 * Uses the core editor selection (respects the `wp_image_editors` filter), so the answer is the
	 * same one WordPress itself would get when generating thumbnails.
	 */
	public function supports( string $mime ): bool {
		if ( ! isset( $this->support[ $mime ] ) ) {
			$this->support[ $mime ] = function_exists( 'wp_image_editor_supports' )
				&& (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) )
				&& $this->mime_is_allowed_upload( $mime );
		}
		return $this->support[ $mime ];
	}

	/** Whether the Imagick extension is loaded (used for lossless WebP, which the core editor cannot express). */
	public function has_imagick(): bool {
		return extension_loaded( 'imagick' ) && class_exists( '\Imagick' );
	}

	/** Whether Imagick can write a given format, e.g. 'WEBP' or 'AVIF'. */
	public function imagick_can_write( string $format ): bool {
		if ( ! $this->has_imagick() ) {
			return false;
		}
		try {
			return in_array( strtoupper( $format ), \Imagick::queryFormats( strtoupper( $format ) ), true );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Which core editor class WordPress would pick for an output mime (for the status display). */
	public function editor_for( string $mime ): string {
		if ( ! function_exists( '_wp_image_editor_choose' ) ) {
			return '';
		}
		$class = _wp_image_editor_choose( array( 'mime_type' => $mime ) );
		return is_string( $class ) ? $class : '';
	}

	/**
	 * Human-readable summary for settings and CLI status.
	 *
	 * @return array<string,mixed>
	 */
	public function summary(): array {
		$gd = function_exists( 'gd_info' ) ? gd_info() : array();
		return array(
			'webp'           => $this->supports( 'image/webp' ),
			'avif'           => $this->supports( 'image/avif' ),
			'webp_editor'    => $this->editor_for( 'image/webp' ),
			'avif_editor'    => $this->editor_for( 'image/avif' ),
			'imagick'        => $this->has_imagick(),
			'imagick_webp'   => $this->imagick_can_write( 'WEBP' ),
			'imagick_avif'   => $this->imagick_can_write( 'AVIF' ),
			'gd_webp'        => ! empty( $gd['WebP Support'] ),
			'gd_avif'        => ! empty( $gd['AVIF Support'] ),
			'lossless_webp'  => $this->imagick_can_write( 'WEBP' ),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		);
	}

	/** WordPress must also accept the format as an upload type, or the converted file would be rejected later. */
	private function mime_is_allowed_upload( string $mime ): bool {
		return in_array( $mime, array_values( get_allowed_mime_types() ), true );
	}
}
