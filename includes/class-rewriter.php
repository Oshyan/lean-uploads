<?php
/**
 * Rewrites old file URLs to new ones across the site's database, serialization-safe.
 *
 * Serialized PHP values are handled by walking the serialized format and rewriting string tokens with
 * corrected lengths. Nothing is ever unserialized, so stored data cannot instantiate classes here
 * (no PHP object injection), and object/class tokens pass through byte for byte. Nesting is bounded.
 *
 * Known limit: a serialized blob embedded inside plain text (say, in a shortcode attribute) is rewritten as
 * text, so its string lengths would go stale. Such rows are rare and are not produced by WordPress itself.
 *
 * Tables: posts (content, excerpt), postmeta, options, termmeta, commentmeta of the current site, and usermeta
 * (shared across a multisite network; only rows containing this site's upload URL are ever selected).
 *
 * @package LeanUploads
 */

namespace LeanUploads;

final class Rewriter {

	/** Rows fetched per query when scanning a table. */
	const PAGE = 500;

	/* ------------------------------------------------------------------ pure part (unit-tested) */

	/**
	 * Replace in a stored value, whether plain text or a PHP-serialized string.
	 *
	 * @param string               $value Stored value.
	 * @param array<string,string> $pairs old => new, applied longest-old-first.
	 * @return string The rewritten value (identical to input when nothing matched).
	 */
	public static function replace_in_value( string $value, array $pairs ): string {
		if ( empty( $pairs ) || '' === $value ) {
			return $value;
		}
		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return self::replace_at_depth( $value, $pairs, 0 );
	}

	/** Deepest nesting of serialized structures (and serialized-in-string) the walker follows before giving up. */
	const MAX_DEPTH = 256;

	/** @param array<string,string> $pairs Already sorted longest-first. */
	private static function replace_at_depth( string $value, array $pairs, int $depth ): string {
		// WordPress unserializes trim()med values, so whitespace around serialized data is still live data.
		$trimmed = trim( $value );
		if ( self::looks_serialized( $trimmed ) ) {
			if ( $depth > self::MAX_DEPTH ) {
				return $value; // absurdly deep (or hostile) structure: leave it alone rather than recurse without bound
			}
			$pos = 0;
			$out = self::walk( $trimmed, $pos, $pairs, $depth );
			// Only trust the rewrite if the whole input was consumed; otherwise leave the value alone.
			if ( null === $out || $pos !== strlen( $trimmed ) ) {
				return $value;
			}
			$lead  = strlen( $value ) - strlen( ltrim( $value ) );
			$trail = strlen( $value ) - strlen( rtrim( $value ) );
			return substr( $value, 0, $lead ) . $out . ( $trail ? substr( $value, -$trail ) : '' );
		}
		return self::substitute( $value, $pairs );
	}

	/**
	 * Plain-text replacement with a right boundary: a key matches only when not followed by a name character,
	 * so ".../x" never rewrites ".../xylophone.jpg" and "photo.jpg.webp" (a sibling left by another plugin) is
	 * not turned into "photo.webp.webp". Keys are tried longest-first.
	 *
	 * @param array<string,string> $pairs Already sorted longest-first.
	 */
	public static function substitute( string $value, array $pairs ): string {
		if ( empty( $pairs ) ) {
			return $value;
		}
		$alternation = implode( '|', array_map( static function ( $k ) { return preg_quote( (string) $k, '#' ); }, array_keys( $pairs ) ) );
		$out         = preg_replace_callback(
			'#(?:' . $alternation . ')(?![A-Za-z0-9._-])#',
			static function ( $m ) use ( $pairs ) {
				return $pairs[ $m[0] ];
			},
			$value
		);
		return null === $out ? $value : $out;
	}

	/** Cheap test for PHP-serialized data (same shape core's is_serialized() accepts). */
	public static function looks_serialized( string $value ): bool {
		if ( strlen( $value ) < 4 || ':' !== $value[1] && 'N;' !== $value ) {
			return 'N;' === $value;
		}
		$last = substr( $value, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}
		return (bool) preg_match( '/^(?:[aOC]:\d+:|s:\d+:"|[idb]:|N;)/', $value );
	}

	/**
	 * Parse one serialized value starting at $pos and return it rewritten; advances $pos past it.
	 * Returns null on malformed input.
	 *
	 * @param array<string,string> $pairs
	 */
	private static function walk( string $s, int &$pos, array $pairs, int $depth = 0 ): ?string {
		if ( $pos >= strlen( $s ) || $depth > self::MAX_DEPTH ) {
			return null;
		}
		$type = $s[ $pos ];
		switch ( $type ) {
			case 'N':
				if ( 'N;' !== substr( $s, $pos, 2 ) ) {
					return null;
				}
				$pos += 2;
				return 'N;';
			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $pos );
				if ( false === $end ) {
					return null;
				}
				$token = substr( $s, $pos, $end - $pos + 1 );
				$pos   = $end + 1;
				return $token;
			case 's':
				return self::walk_string( $s, $pos, $pairs, $depth );
			case 'E':
				// E:<len>:"<Enum:Case>"; (PHP 8.1 enums): copied verbatim.
				if ( ! preg_match( '/\GE:(\d+):"/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$end = $pos + strlen( $m[0] ) + (int) $m[1];
				if ( $end + 2 > strlen( $s ) || '";' !== substr( $s, $end, 2 ) ) {
					return null;
				}
				$token = substr( $s, $pos, $end + 2 - $pos );
				$pos   = $end + 2;
				return $token;
			case 'a':
				// a:<count>:{ (key value)* }
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$count = (int) $m[1];
				$pos  += strlen( $m[0] );
				$out   = $m[0];
				for ( $i = 0; $i < $count; $i++ ) {
					$k = self::walk( $s, $pos, array(), $depth + 1 ); // keys are never rewritten
					$v = self::walk( $s, $pos, $pairs, $depth + 1 );
					if ( null === $k || null === $v ) {
						return null;
					}
					$out .= $k . $v;
				}
				if ( $pos >= strlen( $s ) || '}' !== $s[ $pos ] ) {
					return null;
				}
				++$pos;
				return $out . '}';
			case 'O':
				// O:<len>:"<class>":<count>:{ (key value)* }
				if ( ! preg_match( '/\GO:(\d+):"/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$len   = (int) $m[1];
				$start = $pos + strlen( $m[0] );
				$class = substr( $s, $start, $len );
				$after = $start + $len;
				if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $after ) ) {
					return null;
				}
				$count = (int) $m2[1];
				$pos   = $after + strlen( $m2[0] );
				$out   = 'O:' . $len . ':"' . $class . '":' . $count . ':{';
				for ( $i = 0; $i < $count; $i++ ) {
					$k = self::walk( $s, $pos, array(), $depth + 1 );
					$v = self::walk( $s, $pos, $pairs, $depth + 1 );
					if ( null === $k || null === $v ) {
						return null;
					}
					$out .= $k . $v;
				}
				if ( $pos >= strlen( $s ) || '}' !== $s[ $pos ] ) {
					return null;
				}
				++$pos;
				return $out . '}';
			case 'C':
				// C:<len>:"<class>":<datalen>:{<data>} : custom serialization, copied verbatim.
				if ( ! preg_match( '/\GC:(\d+):"/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$len   = (int) $m[1];
				$after = $pos + strlen( $m[0] ) + $len;
				if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $after ) ) {
					return null;
				}
				$dlen = (int) $m2[1];
				$end  = $after + strlen( $m2[0] ) + $dlen;
				if ( $end >= strlen( $s ) || '}' !== $s[ $end ] ) {
					return null;
				}
				$token = substr( $s, $pos, $end - $pos + 1 );
				$pos   = $end + 1;
				return $token;
			default:
				return null;
		}
	}

	/** @param array<string,string> $pairs */
	private static function walk_string( string $s, int &$pos, array $pairs, int $depth = 0 ): ?string {
		if ( ! preg_match( '/\Gs:(\d+):"/', $s, $m, 0, $pos ) ) {
			return null;
		}
		$len   = (int) $m[1];
		$start = $pos + strlen( $m[0] );
		if ( $start + $len + 2 > strlen( $s ) || '";' !== substr( $s, $start + $len, 2 ) ) {
			return null;
		}
		$str = substr( $s, $start, $len );
		$pos = $start + $len + 2;
		// Strings are opaque bytes; a nested serialized string is just bytes too, so recurse for it as well.
		$new = $pairs ? self::replace_at_depth( $str, $pairs, $depth + 1 ) : $str;
		return 's:' . strlen( $new ) . ':"' . $new . '";';
	}

	/* ------------------------------------------------------------------ database part */

	/**
	 * Rewrite URLs in posts (content, excerpt), post/term/user/comment meta and options of the current site.
	 * Rows are found with one LIKE on the common prefix of the old URLs, then rewritten in PHP.
	 *
	 * @param array<string,string> $pairs old URL => new URL.
	 * @return int Rows changed.
	 */
	public function rewrite( array $pairs ): int {
		global $wpdb;
		$baseurl = (string) ( wp_get_upload_dir()['baseurl'] ?? '' );
		$pairs   = array_filter(
			$pairs,
			static function ( $new, $old ) use ( $baseurl ) {
				// Only upload URLs with an image extension are rewritten; anything else is not ours to touch.
				return is_string( $old ) && is_string( $new ) && $old !== $new && '' !== $baseurl
					&& 0 === strpos( $old, $baseurl ) && strlen( $old ) > strlen( $baseurl ) + 1
					&& preg_match( '/\.(jpe?g|jpe|png|gif|bmp|tiff?|heic|heif|webp|avif)$/i', $old );
			},
			ARRAY_FILTER_USE_BOTH
		);
		if ( empty( $pairs ) ) {
			return 0;
		}
		$needle = self::common_prefix( array_keys( $pairs ) );
		if ( strlen( $needle ) <= strlen( $baseurl ) ) {
			return 0; // refuse to scan on a prefix no longer than the uploads URL itself
		}
		// JSON stored as text (block attributes, builder data, theme options) escapes slashes: match that form too.
		$escaped = array();
		foreach ( $pairs as $old => $new ) {
			$escaped[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
		}
		$pairs  += $escaped;
		$like    = '%' . $wpdb->esc_like( $needle ) . '%';
		$like2   = '%' . $wpdb->esc_like( str_replace( '/', '\\/', $needle ) ) . '%';
		$changed = 0;

		$targets = array(
			array( $wpdb->posts, 'ID', array( 'post_content', 'post_excerpt' ), 'post' ),
			array( $wpdb->postmeta, 'meta_id', array( 'meta_value' ), 'postmeta' ),
			array( $wpdb->options, 'option_id', array( 'option_value' ), 'option' ),
			array( $wpdb->termmeta, 'meta_id', array( 'meta_value' ), 'termmeta' ),
			array( $wpdb->usermeta, 'umeta_id', array( 'meta_value' ), 'usermeta' ),
			array( $wpdb->commentmeta, 'meta_id', array( 'meta_value' ), 'commentmeta' ),
		);
		foreach ( $targets as list( $table, $pk, $columns, $kind ) ) {
			foreach ( $columns as $column ) {
				$extra = 'post' === $kind ? ', post_type' : ( 'postmeta' === $kind ? ', post_id' : ( 'option' === $kind ? ', option_name' : ( 'termmeta' === $kind ? ', term_id' : ( 'usermeta' === $kind ? ', user_id' : ', comment_id' ) ) ) );
				$last  = 0;
				do {
					// Page by primary key so a large table is never loaded into memory at once.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `$pk` AS pk, `$column` AS val{$extra} FROM `$table` WHERE `$pk` > %d AND (`$column` LIKE %s OR `$column` LIKE %s) ORDER BY `$pk` ASC LIMIT %d", $last, $like, $like2, self::PAGE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					foreach ( (array) $rows as $row ) {
						$last = (int) $row->pk;
						$new  = self::replace_in_value( (string) $row->val, $pairs );
						if ( $new === (string) $row->val ) {
							continue;
						}
						$ok = $wpdb->update( $table, array( $column => $new ), array( $pk => $row->pk ), array( '%s' ), array( '%d' ) );
						if ( false === $ok ) {
							continue;
						}
						++$changed;
						self::clean_cache( $kind, $row );
					}
					$wpdb->flush();
				} while ( count( (array) $rows ) === self::PAGE );
			}
		}
		return $changed;
	}

	/** @param string[] $strings */
	public static function common_prefix( array $strings ): string {
		$prefix = (string) array_shift( $strings );
		foreach ( $strings as $s ) {
			while ( '' !== $prefix && 0 !== strpos( $s, $prefix ) ) {
				$prefix = substr( $prefix, 0, -1 );
			}
		}
		return $prefix;
	}

	/** Targeted cache invalidation for a rewritten row, so a persistent object cache does not serve the old value. */
	private static function clean_cache( string $kind, $row ): void {
		switch ( $kind ) {
			case 'post':
				clean_post_cache( (int) $row->pk );
				break;
			case 'postmeta':
				wp_cache_delete( (int) $row->post_id, 'post_meta' );
				break;
			case 'option':
				wp_cache_delete( (string) $row->option_name, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				break;
			case 'termmeta':
				wp_cache_delete( (int) $row->term_id, 'term_meta' );
				break;
			case 'usermeta':
				wp_cache_delete( (int) $row->user_id, 'user_meta' );
				break;
			case 'commentmeta':
				wp_cache_delete( (int) $row->comment_id, 'comment_meta' );
				break;
		}
	}
}
