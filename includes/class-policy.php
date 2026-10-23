<?php
/**
 * Pure decision logic: no WordPress calls, so it can be unit-tested without a WordPress install.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

if ( ! function_exists( __NAMESPACE__ . '\\wp_normalize_path_compat' ) ) {
	/** Backslashes to slashes, collapsed; WordPress-free so Policy stays unit-testable. */
	function wp_normalize_path_compat( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return (string) preg_replace( '#/+#', '/', $path );
	}
}

final class Policy {

	/** Source mime types the plugin knows how to convert. Animated GIFs are detected separately; HEIC needs a capable decoder. */
	const CONVERTIBLE = array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff', 'image/heic' );

	/** Formats that are already "modern"; only resized when over the cap, never re-encoded otherwise. */
	const MODERN = array( 'image/webp', 'image/avif' );

	const EXT = array(
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	/**
	 * Decide what to do with one file.
	 *
	 * @param string $mime      Actual mime type (sniffed from bytes, not the client's claim).
	 * @param string $name      Original file name (used for the opt-out token).
	 * @param array  $settings  Sanitized settings, see Settings::defaults().
	 * @param array  $support   array( 'image/webp' => bool, 'image/avif' => bool, 'lossless' => bool ).
	 * @param bool   $animated  Whether a GIF is animated (caller detects).
	 * @return array{convert:bool,resize_only:bool,reason:string,target_mime:string,ext:string,lossless:bool,keep_name:string}
	 */
	public static function decide( string $mime, string $name, array $settings, array $support, bool $animated = false ): array {
		$result = array(
			'convert'     => false,
			'resize_only' => false,
			'reason'      => '',
			'target_mime' => '',
			'ext'         => '',
			'lossless'    => false,
			'keep_name'   => $name,
		);

		$token = (string) ( $settings['keep_token'] ?? '' );
		if ( '' !== $token && false !== strpos( $name, $token ) ) {
			$result['reason']    = 'keep_token';
			$result['keep_name'] = self::strip_token( $name, $token );
			return $result;
		}

		if ( empty( $settings['enabled'] ) ) {
			$result['reason'] = 'disabled';
			return $result;
		}

		$target = self::target_mime( $settings, $support );
		if ( '' === $target ) {
			$result['reason'] = 'no_encoder';
			return $result;
		}

		if ( in_array( $mime, self::MODERN, true ) ) {
			// Already efficient. Only touch it if the size cap applies (caller checks dimensions).
			$result['resize_only'] = ( (int) ( $settings['max_dimension'] ?? 0 ) > 0 );
			$result['reason']      = 'already_modern';
			$result['target_mime'] = $mime;
			$result['ext']         = self::EXT[ $mime ];
			return $result;
		}

		if ( ! in_array( $mime, self::CONVERTIBLE, true ) ) {
			$result['reason'] = 'not_an_image';
			return $result;
		}

		$per_type = array(
			'image/jpeg' => ! empty( $settings['convert_jpeg'] ),
			'image/png'  => ! empty( $settings['convert_png'] ),
			'image/gif'  => ! empty( $settings['convert_gif'] ) && ! $animated,
			'image/bmp'  => ! empty( $settings['convert_other'] ),
			'image/tiff' => ! empty( $settings['convert_other'] ),
			'image/heic' => ! empty( $settings['convert_other'] ) && ! empty( $support['image/heic'] ),
		);
		if ( empty( $per_type[ $mime ] ) ) {
			if ( $animated ) {
				$result['reason'] = 'animated_gif';
			} elseif ( 'image/heic' === $mime && empty( $support['image/heic'] ) ) {
				$result['reason'] = 'no_decoder';
			} else {
				$result['reason'] = 'type_disabled';
			}
			return $result;
		}

		$result['convert']     = true;
		$result['reason']      = 'convert';
		$result['target_mime'] = $target;
		$result['ext']         = self::EXT[ $target ];
		$result['lossless']    = ( 'image/png' === $mime && ! empty( $settings['png_lossless'] ) && ! empty( $support['lossless'] ) && 'image/webp' === $target );
		return $result;
	}

	/**
	 * The format we will write, falling back from AVIF to WebP when the server cannot encode AVIF.
	 */
	public static function target_mime( array $settings, array $support ): string {
		$wanted = ( 'avif' === ( $settings['format'] ?? 'webp' ) ) ? 'image/avif' : 'image/webp';
		if ( ! empty( $support[ $wanted ] ) ) {
			return $wanted;
		}
		if ( 'image/avif' === $wanted && ! empty( $support['image/webp'] ) ) {
			return 'image/webp';
		}
		return '';
	}

	/**
	 * New file name for a converted file: same base name, new extension, extension always lowercase.
	 */
	public static function rename( string $name, string $ext ): string {
		$dot = strrpos( $name, '.' );
		$base = ( false === $dot ) ? $name : substr( $name, 0, $dot );
		return $base . '.' . strtolower( $ext );
	}

	/** Remove the opt-out token from a file name, e.g. "logo--keep.png" => "logo.png". */
	public static function strip_token( string $name, string $token ): string {
		$stripped = str_replace( $token, '', $name );
		return '' === trim( $stripped, '.' ) ? $name : $stripped;
	}

	/**
	 * Target dimensions when fitting within a square cap, preserving aspect ratio. Null means "no resize needed".
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function fit( int $width, int $height, int $max ): ?array {
		if ( $max <= 0 || $width <= 0 || $height <= 0 ) {
			return null;
		}
		if ( $width <= $max && $height <= $max ) {
			return null;
		}
		$ratio = min( $max / $width, $max / $height );
		return array( max( 1, (int) round( $width * $ratio ) ), max( 1, (int) round( $height * $ratio ) ) );
	}

	/**
	 * Whether $candidate is the kept file itself or one of its derived files (same directory, same base name
	 * followed by "-" or "."). Used to exempt opted-out uploads from thumbnail format mapping.
	 */
	public static function path_belongs_to( string $candidate, string $kept ): bool {
		if ( '' === $candidate || '' === $kept ) {
			return false;
		}
		$candidate = wp_normalize_path_compat( $candidate );
		$kept      = wp_normalize_path_compat( $kept );
		if ( dirname( $candidate ) !== dirname( $kept ) ) {
			return false;
		}
		$cb = basename( $candidate );
		$kb = pathinfo( $kept, PATHINFO_FILENAME );
		return $cb === basename( $kept ) || 0 === strpos( $cb, $kb . '-' ) || 0 === strpos( $cb, $kb . '.' );
	}

	/**
	 * Cheap animated-GIF detection: more than one Graphic Control Extension block.
	 */
	public static function is_animated_gif( string $path ): bool {
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $fh ) {
			return false;
		}
		$count = 0;
		while ( ! feof( $fh ) && $count < 2 ) {
			$chunk  = fread( $fh, 1024 * 100 );
			$count += preg_match_all( '#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk );
		}
		fclose( $fh );
		return $count > 1;
	}
}
