<?php
/**
 * Optional, same-format sRGB normalization of existing attachment files.
 *
 * No missing profile is guessed. Every selected output is staged and verified before any source is
 * replaced. The operation keeps URLs and dimensions; a failed commit restores the original files.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Color_Library {
	const LOCK_PREFIX = 'lean_uploads_color_lock_';
	const SUPPORTED_MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );

	/**
	 * Inspect the attachment and each registered file independently, without decoding pixels.
	 *
	 * @return array|\WP_Error
	 */
	public function inspect( int $id ) {
		if ( ! wp_attachment_is_image( $id ) ) {
			return new \WP_Error( 'skip', 'Not an image attachment.' );
		}
		if ( get_post_meta( $id, Pipeline::KEEP_META, true ) ) {
			return new \WP_Error( 'skip', 'This attachment is marked to be kept as uploaded.' );
		}
		$path = get_attached_file( $id, true );
		if ( ! $path || ! is_file( $path ) || ! Pipeline::within_uploads( $path ) ) {
			return new \WP_Error( 'lean_uploads_color_missing', 'The attachment file is missing or outside uploads.' );
		}
		if ( ! in_array( wp_get_image_mime( $path ), self::SUPPORTED_MIMES, true ) ) {
			return new \WP_Error( 'skip', 'Same-format color normalization supports JPEG, PNG, WebP and AVIF attachments only.' );
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return new \WP_Error( 'lean_uploads_color_metadata', 'The attachment has no complete image metadata; regenerate its metadata before color conversion.' );
		}
		$files = self::files( $path, $meta );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$convert = array();
		$skipped = array();
		foreach ( $files as $file ) {
			if ( ! is_file( $file ) || ! Pipeline::within_uploads( $file ) ) {
				return new \WP_Error( 'lean_uploads_color_missing', 'A registered attachment image is missing or outside uploads; nothing was changed.' );
			}
			if ( ! in_array( wp_get_image_mime( $file ), self::SUPPORTED_MIMES, true ) ) {
				$skipped[ $file ] = 'unsupported_format';
				continue; // Do not invoke an image decoder for SVG or other unsupported formats.
			}
			$info = Color_Profiles::inspect( $file );
			if ( is_wp_error( $info ) ) {
				return $info;
			}
			if ( empty( $info['has_icc'] ) ) {
				$skipped[ $file ] = 'untagged';
			} elseif ( ! empty( $info['is_srgb'] ) ) {
				$skipped[ $file ] = 'already_srgb';
			} else {
				$hash = hash_file( 'sha256', $file );
				if ( false === $hash ) {
					return new \WP_Error( 'lean_uploads_color_read', 'Could not read an attachment image.' );
				}
				$convert[ $file ] = $hash;
			}
		}
		return array( 'path' => $path, 'metadata' => $meta, 'files' => $files, 'convert' => $convert, 'skipped' => $skipped );
	}

	/**
	 * Normalize only files with a known, non-sRGB embedded profile.
	 *
	 * The attachment lock is deliberately not stolen on a timer: a slow encoder must never race a second
	 * request. An interrupted PHP process needs operator recovery; originals remain in the journal paths.
	 *
	 * @return array{converted:int,skipped:int,untagged:int}|\WP_Error
	 */
	public function normalize_attachment( int $id ) {
		$lock = self::LOCK_PREFIX . $id;
		if ( ! add_option( $lock, array( 'started' => time(), 'state' => 'inspecting' ), '', false ) ) {
			return new \WP_Error( 'lean_uploads_color_busy', 'Color conversion is already running, or an interrupted conversion needs recovery. Do not clear its lock until any saved originals have been restored.' );
		}
		$entries = array();
		$info = null;
		$committed = false;
		$recovered = true;
		$metadata_touched = false;
		try {
			$info = $this->inspect( $id );
			if ( is_wp_error( $info ) ) {
				return $info;
			}
			if ( empty( $info['convert'] ) ) {
				return new \WP_Error( 'skip', 'No tagged non-sRGB files need conversion; untagged files were left untouched.' );
			}
			foreach ( $info['convert'] as $source => $hash ) {
				$token = bin2hex( random_bytes( 12 ) );
				$stage = dirname( $source ) . '/.lean-color-' . $token . '.' . pathinfo( $source, PATHINFO_EXTENSION );
				$backup = dirname( $source ) . '/.lean-color-' . $token . '.original';
				if ( ! Pipeline::claim( $stage ) ) {
					return new \WP_Error( 'lean_uploads_color_stage', 'Could not reserve a temporary image; nothing was changed.' );
				}
				$entries[ $source ] = array( 'stage' => $stage, 'backup' => $backup, 'hash' => $hash, 'moved' => false, 'backup_claimed' => false );
				$this->journal( $lock, $info, $entries, 'staging' );
				$result = Color_Profiles::normalize_file( $source, $stage );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				// The helper verifies the saved color profile. Check container and geometry independently too.
				$before = wp_getimagesize( $source );
				$after = wp_getimagesize( $stage );
				if ( ! $before || ! $after || $before[0] !== $after[0] || $before[1] !== $after[1] || wp_get_image_mime( $source ) !== wp_get_image_mime( $stage ) ) {
					return new \WP_Error( 'lean_uploads_color_geometry', 'The saved image changed format or dimensions; nothing was changed.' );
				}
				$permissions = fileperms( $source );
				if ( false !== $permissions ) {
					chmod( $stage, $permissions & 0777 );
				}
			}
			// Refuse to overwrite a concurrent edit made while the encoders ran.
			if ( get_attached_file( $id, true ) !== $info['path'] || wp_get_attachment_metadata( $id ) !== $info['metadata'] ) {
				return new \WP_Error( 'lean_uploads_color_changed', 'The attachment changed during processing; please retry.' );
			}
			foreach ( $entries as $source => $entry ) {
				if ( ! is_file( $source ) || hash_file( 'sha256', $source ) !== $entry['hash'] ) {
					return new \WP_Error( 'lean_uploads_color_changed', 'An image changed during processing; please retry.' );
				}
			}
			$this->journal( $lock, $info, $entries, 'committing' );
			foreach ( $entries as $source => &$entry ) {
				if ( ! is_file( $source ) || hash_file( 'sha256', $source ) !== $entry['hash'] ) {
					return new \WP_Error( 'lean_uploads_color_changed', 'An image changed during processing; restoring the originals already replaced.' );
				}
				if ( ! Pipeline::claim( $entry['backup'] ) ) {
					return new \WP_Error( 'lean_uploads_color_commit', 'Could not reserve an original for rollback.' );
				}
				$entry['backup_claimed'] = true;
				if ( ! rename( $source, $entry['backup'] ) ) {
					return new \WP_Error( 'lean_uploads_color_commit', 'Could not preserve an original for rollback.' );
				}
				$entry['moved'] = true;
				$this->journal( $lock, $info, $entries, 'committing' );
				if ( ! rename( $entry['stage'], $source ) ) {
					return new \WP_Error( 'lean_uploads_color_commit', 'Could not install a verified image; restoring the originals.' );
				}
				clearstatcache( true, $source );
			}
			unset( $entry );
			$meta = $info['metadata'];
			$meta['filesize'] = wp_filesize( $info['path'] );
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $size ) {
				if ( ! empty( $size['file'] ) ) {
					$meta['sizes'][ $name ]['filesize'] = wp_filesize( dirname( $info['path'] ) . '/' . $size['file'] );
				}
			}
			$metadata_touched = true;
			wp_update_attachment_metadata( $id, $meta );
			if ( wp_get_attachment_metadata( $id ) !== $meta ) {
				wp_update_attachment_metadata( $id, $info['metadata'] );
				return new \WP_Error( 'lean_uploads_color_metadata', 'Could not save updated attachment metadata; restoring the originals.' );
			}
			$this->journal( $lock, $info, $entries, 'committed' );
			$committed = true;
			return array(
				'converted' => count( $entries ),
				'skipped' => count( $info['skipped'] ),
				'untagged' => count( array_filter( $info['skipped'], static function ( $reason ) { return 'untagged' === $reason; } ) ),
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'lean_uploads_color_exception', 'Color conversion failed; restoring any replaced originals.' );
		} finally {
			unset( $entry ); // A return inside the by-reference commit loop must not leak its reference here.
			if ( ! $committed && $metadata_touched && is_array( $info ) ) {
				try {
					wp_update_attachment_metadata( $id, $info['metadata'] );
					$recovered = wp_get_attachment_metadata( $id ) === $info['metadata'];
				} catch ( \Throwable $e ) {
					$recovered = false; // A broken metadata hook must not prevent restoring image files.
				}
			}
			foreach ( array_reverse( $entries, true ) as $source => $entry ) {
				if ( ! $committed && $entry['moved'] ) {
					if ( ! is_file( $entry['backup'] ) || ! rename( $entry['backup'], $source ) ) {
						$recovered = false; // Retain the journal and backup: never erase the only original.
					}
					clearstatcache( true, $source );
				}
				Pipeline::delete_in_uploads( $entry['stage'] );
				if ( $committed || ( ! $entry['moved'] && $entry['backup_claimed'] ) ) {
					Pipeline::delete_in_uploads( $entry['backup'] );
				}
			}
			if ( $committed || $recovered ) {
				delete_option( $lock );
			} else {
				try { $this->journal( $lock, is_array( $info ) ? $info : array(), $entries, 'recovery_required' ); }
				catch ( \Throwable $e ) { /* The last verified journal still contains the original paths. */ }
				return new \WP_Error( 'lean_uploads_color_recovery', 'An original could not be restored automatically. Its backup and recovery journal were retained; ask the site administrator to recover this attachment before retrying.' );
			}
		}
	}

	/** Record enough to restore a process interrupted between file renames. Kept only in the database. */
	private function journal( string $lock, array $info, array $entries, string $state ): void {
		$record = array( 'state' => $state, 'updated' => time(), 'path' => $info['path'] ?? '', 'metadata' => $info['metadata'] ?? array(), 'entries' => $entries );
		update_option( $lock, $record, false );
		if ( get_option( $lock ) !== $record ) {
			throw new \RuntimeException( 'Could not persist the color-conversion recovery journal.' );
		}
	}

	/** @return string[]|\WP_Error Registered files, rejecting path components instead of silently truncating. */
	public static function files( string $path, array $meta ) {
		$out = array( $path );
		$names = array();
		if ( ! empty( $meta['original_image'] ) ) {
			$names[] = $meta['original_image'];
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$names[] = $size['file'];
			}
		}
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || basename( $name ) !== $name || false !== strpos( $name, '\\' ) || false !== strpos( $name, "\0" ) || in_array( $name, array( '.', '..' ), true ) ) {
				return new \WP_Error( 'lean_uploads_color_path', 'Attachment metadata contains an unsafe file name.' );
			}
			$out[] = dirname( $path ) . '/' . $name;
		}
		return array_values( array_unique( $out ) );
	}
}
