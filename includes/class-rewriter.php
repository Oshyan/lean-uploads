<?php
/**
 * Rewrites old file URLs to new ones across the current site's database, serialization-safe.
 *
 * Serialized PHP values are handled by walking the serialized format and rewriting string tokens with
 * corrected lengths. Nothing is ever unserialized, so stored data cannot instantiate classes here
 * (no PHP object injection), and object/class tokens pass through byte for byte.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

final class Rewriter {

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
		if ( self::looks_serialized( $value ) ) {
			$pos = 0;
			$out = self::walk( $value, $pos, $pairs );
			// Only trust the rewrite if the whole input was consumed; otherwise leave the value alone.
			return ( null !== $out && $pos === strlen( $value ) ) ? $out : $value;
		}
		return strtr( $value, $pairs );
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
	private static function walk( string $s, int &$pos, array $pairs ): ?string {
		if ( $pos >= strlen( $s ) ) {
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
				return self::walk_string( $s, $pos, $pairs );
			case 'a':
				// a:<count>:{ (key value)* }
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$count = (int) $m[1];
				$pos  += strlen( $m[0] );
				$out   = $m[0];
				for ( $i = 0; $i < $count; $i++ ) {
					$k = self::walk( $s, $pos, array() ); // keys are never rewritten
					$v = self::walk( $s, $pos, $pairs );
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
					$k = self::walk( $s, $pos, array() );
					$v = self::walk( $s, $pos, $pairs );
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
	private static function walk_string( string $s, int &$pos, array $pairs ): ?string {
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
		$new = ( $pairs && self::looks_serialized( $str ) ) ? self::replace_in_value( $str, $pairs ) : ( $pairs ? strtr( $str, $pairs ) : $str );
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
		$pairs = array_filter( $pairs, static function ( $new, $old ) { return is_string( $old ) && is_string( $new ) && '' !== $old && $old !== $new; }, ARRAY_FILTER_USE_BOTH );
		if ( empty( $pairs ) ) {
			return 0;
		}
		$needle  = self::common_prefix( array_keys( $pairs ) );
		if ( strlen( $needle ) < 12 ) {
			return 0; // refuse to scan on a prefix too short to be meaningful
		}
		$like    = '%' . $wpdb->esc_like( $needle ) . '%';
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
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT `$pk` AS pk, `$column` AS val{$extra} FROM `$table` WHERE `$column` LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( (array) $rows as $row ) {
					$new = self::replace_in_value( (string) $row->val, $pairs );
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
