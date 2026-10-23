<?php
/**
 * Pure decision logic: no WordPress calls, so it can be unit-tested without a WordPress install.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

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
	 * A file name whose base is free for its own extension and for every extension in $protect, considering the
	 * full-size name, "-WxH" sub-sizes and core's "-scaled"/"-rotated" variants. Core's wp_unique_filename() only
	 * checks the uploaded extension, so a new "photo.jpg" would otherwise reuse the base of an earlier upload that
	 * this plugin already turned into "photo.webp" + "photo-300x200.webp", and core's editors would then write the
	 * new thumbnails over the old ones (they never check for existing files).
	 *
	 * @param string   $filename Proposed name, e.g. "photo.jpg".
	 * @param string[] $existing Names already in the directory (any case).
	 * @param string[] $protect  Extensions to keep clear as well, e.g. array( 'webp', 'avif' ).
	 * @return string The same name, or "photo-N.jpg" with the first free N.
	 */
	public static function free_name( string $filename, array $existing, array $protect ): string {
		$dot  = strrpos( $filename, '.' );
		$base = false === $dot ? $filename : substr( $filename, 0, $dot );
		$ext  = false === $dot ? '' : substr( $filename, $dot ); // includes the dot
		$exts = array_unique( array_filter( array_merge( array( strtolower( ltrim( $ext, '.' ) ) ), array_map( 'strtolower', $protect ) ) ) );
		if ( empty( $exts ) || empty( $existing ) ) {
			return $filename;
		}
		$names = array();
		foreach ( $existing as $name ) {
			$names[ strtolower( (string) $name ) ] = true;
		}
		$taken = static function ( string $candidate ) use ( $names, $exts ): bool {
			$q = preg_quote( strtolower( $candidate ), '#' );
			$e = '(?:' . implode( '|', array_map( 'preg_quote', $exts ) ) . ')';
			foreach ( $exts as $x ) {
				if ( isset( $names[ strtolower( $candidate ) . '.' . $x ] ) ) {
					return true;
				}
			}
			foreach ( array_keys( $names ) as $n ) {
				if ( preg_match( '#^' . $q . '-(?:\d+x\d+|scaled|rotated)\.' . $e . '$#', $n ) ) {
					return true;
				}
			}
			return false;
		};
		if ( ! $taken( $base ) ) {
			return $filename;
		}
		for ( $i = 1; $i < 10000; $i++ ) { // like core: append "-N", never rewrite a "-N" the user typed
			if ( ! $taken( $base . '-' . $i ) ) {
				return $base . '-' . $i . $ext;
			}
		}
		return $base . '-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . $ext;
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
		$tail  = '';
		while ( ! feof( $fh ) && $count < 2 ) {
			// Carry the last bytes of the previous chunk so a marker straddling the boundary is still seen.
			$chunk  = $tail . fread( $fh, 1024 * 100 );
			$count += preg_match_all( '#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk );
			$tail   = substr( $chunk, -9 ); // one byte short of the 10-byte pattern, so a marker is never counted twice
		}
		fclose( $fh );
		return $count > 1;
	}
}
