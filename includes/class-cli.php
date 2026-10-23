<?php
/**
 * WP-CLI: status, self-test, and bulk conversion of the existing library.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

/**
 * Converts images to WebP/AVIF and caps their size.
 */
final class CLI {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Shows server capabilities and the active settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lean-uploads status
	 */
	public function status(): void {
		$caps = $this->plugin->capabilities->summary();
		\WP_CLI::log( 'Capabilities:' );
		foreach ( $caps as $k => $v ) {
			\WP_CLI::log( sprintf( '  %-14s %s', $k, is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v ) );
		}
		\WP_CLI::log( 'Settings:' );
		foreach ( $this->plugin->settings->get() as $k => $v ) {
			\WP_CLI::log( sprintf( '  %-14s %s', $k, is_bool( $v ) ? ( $v ? 'on' : 'off' ) : (string) $v ) );
		}
	}

	/**
	 * Runs an end-to-end check on this server: generates test images, pushes them through the real
	 * upload pipeline into the Media Library, verifies the results, and deletes everything it created.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lean-uploads self-test
	 *
	 * @subcommand self-test
	 */
	public function self_test(): void {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$settings = $this->plugin->settings->get();
		$target   = Policy::target_mime( $settings, $this->plugin->pipeline->support_map() );
		if ( '' === $target ) {
			\WP_CLI::error( 'This server cannot encode WebP or AVIF; nothing to test.' );
		}
		$ext      = Policy::EXT[ $target ];
		$failures = 0;
		$created  = array(); // every temp file we make, deleted by exact path at the end (never by pattern)

		// wp_tempnam() creates each file exclusively with a random name, so nothing can be pre-planted at a
		// predictable path; the intended upload name goes in 'name' for the sideload.
		$cases = array(
			array( 'label' => 'large PNG with alpha', 'name' => 'lean-test-alpha.png', 'file' => $this->make_png( wp_tempnam( 'lean-test-alpha' ), 3200, 2000, true, $created ) ),
			array( 'label' => 'JPEG', 'name' => 'lean-test-photo.jpg', 'file' => $this->make_jpeg( wp_tempnam( 'lean-test-photo' ), 1800, 1200, $created ) ),
		);
		if ( '' !== (string) $settings['keep_token'] ) {
			$cases[] = array( 'label' => 'opt-out token', 'name' => 'lean-test-logo' . $settings['keep_token'] . '.png', 'file' => $this->make_png( wp_tempnam( 'lean-test-logo' ), 400, 200, true, $created ), 'expect_keep' => true );
		}

		foreach ( $cases as $case ) {
			$label = $case['label'];
			if ( ! $case['file'] ) {
				\WP_CLI::warning( "$label: could not create test image (GD missing?)" );
				++$failures;
				continue;
			}
			$file_array = array( 'name' => $case['name'], 'tmp_name' => $case['file'] );
			$id         = media_handle_sideload( $file_array, 0 );
			if ( is_wp_error( $id ) ) {
				\WP_CLI::warning( "$label: sideload failed: " . $id->get_error_message() );
				++$failures;
				continue;
			}
			$path = get_attached_file( $id );
			$mime = get_post_mime_type( $id );
			$meta = wp_get_attachment_metadata( $id );
			$ok   = true;
			$msgs = array();

			if ( ! empty( $case['expect_keep'] ) ) {
				if ( 'image/png' !== $mime || false !== strpos( basename( $path ), $settings['keep_token'] ) || 'png' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
					$ok = false; $msgs[] = "expected untouched PNG with token stripped, got $mime " . basename( $path );
				}
				if ( ! empty( $meta['original_image'] ) ) {
					$ok = false; $msgs[] = 'core converted the full size anyway (original_image is set)';
				}
				foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $info ) {
					if ( ( $info['mime-type'] ?? '' ) !== 'image/png' ) {
						$ok = false; $msgs[] = "opted-out file got a {$info['mime-type']} '$size' thumbnail";
						break;
					}
				}
				if ( ! get_post_meta( $id, Pipeline::KEEP_META, true ) ) {
					$ok = false; $msgs[] = 'keep flag not persisted on the attachment';
				}
			} else {
				if ( $mime !== $target ) {
					$ok = false; $msgs[] = "mime is $mime, expected $target";
				}
				if ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) !== $ext ) {
					$ok = false; $msgs[] = 'extension is ' . pathinfo( $path, PATHINFO_EXTENSION );
				}
				$max = (int) $settings['max_dimension'];
				if ( $max > 0 && ( (int) $meta['width'] > $max || (int) $meta['height'] > $max ) ) {
					$ok = false; $msgs[] = "dimensions {$meta['width']}x{$meta['height']} exceed cap $max";
				}
			}
			if ( empty( $case['expect_keep'] ) && ! empty( $settings['derivatives'] ) && ! empty( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size => $info ) {
					if ( ( $info['mime-type'] ?? '' ) !== $target ) {
						$ok = false; $msgs[] = "size '$size' is {$info['mime-type']}, expected $target";
						break;
					}
				}
			}
			$bytes = file_exists( $path ) ? size_format( filesize( $path ) ) : '?';
			wp_delete_attachment( $id, true );
			if ( $ok ) {
				\WP_CLI::success( "$label: ok ($mime, {$meta['width']}x{$meta['height']}, $bytes)" );
			} else {
				++$failures;
				\WP_CLI::warning( "$label: " . implode( '; ', $msgs ) );
			}
		}
		foreach ( $created as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		if ( $failures ) {
			\WP_CLI::error( "$failures check(s) failed." );
		}
		\WP_CLI::success( 'All checks passed.' );
	}

	/**
	 * Shows what the library contains and what a bulk conversion would do to it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lean-uploads scan
	 */
	public function scan(): void {
		$summary = $this->plugin->bulk->summary();
		$labels  = array( 'convert' => 'would be converted', 'resize' => 'would be resized to the cap', 'skip' => 'would be left alone', 'missing' => 'file missing' );
		foreach ( $summary as $key => $row ) {
			\WP_CLI::log( sprintf( '  %-30s %6d images  %s', $labels[ $key ] ?? $key, $row['count'], size_format( $row['bytes'] ) ) );
		}
	}

	/**
	 * Converts existing Media Library images in place.
	 *
	 * Replaces each attachment's file with the converted version, regenerates its thumbnails, deletes the
	 * old files, and (optionally) rewrites references to the old URLs across the database.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs. Omit to use --all.
	 *
	 * [--all]
	 * : Process every convertible image attachment.
	 *
	 * [--limit=<n>]
	 * : Stop after this many conversions.
	 *
	 * [--dry-run]
	 * : Report what would change without touching anything.
	 *
	 * [--update-references]
	 * : After converting, rewrite old file URLs to the new ones in posts, meta and options (serialization-safe).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lean-uploads convert --all --dry-run
	 *     wp lean-uploads convert --all --update-references
	 *     wp lean-uploads convert 123 456
	 *
	 * @param string[]             $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function convert( array $args, array $assoc_args ): void {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$dry   = isset( $assoc_args['dry-run'] );
		$refs  = isset( $assoc_args['update-references'] );
		$limit = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;

		$ids = array_map( 'intval', $args );
		if ( isset( $assoc_args['all'] ) ) {
			$ids = $this->plugin->library->candidate_ids();
		}
		if ( empty( $ids ) ) {
			\WP_CLI::error( 'Nothing to do. Pass attachment IDs or --all.' );
		}

		$library = $this->plugin->library;
		$done    = 0;
		$saved   = 0;
		$skipped = 0;
		$failed  = 0;
		$bar     = \WP_CLI\Utils\make_progress_bar( $dry ? 'Scanning' : 'Converting', count( $ids ) );

		foreach ( $ids as $id ) {
			$bar->tick();
			if ( $limit && $done >= $limit ) {
				break;
			}
			if ( $dry ) {
				$info = $library->inspect( $id );
				if ( is_wp_error( $info ) || 'skip' === $info['action'] ) {
					++$skipped;
					continue;
				}
				++$done;
				\WP_CLI::log( sprintf( '[dry] #%d %s: %s %s (%s)', $id, $info['action'], basename( $info['path'] ), 'convert' === $info['action'] ? '-> ' . Policy::rename( basename( $info['path'] ), $info['ext'] ) : '', size_format( $info['bytes'] ) ) );
				continue;
			}
			$r = $library->convert_attachment( $id, $refs );
			if ( is_wp_error( $r ) ) {
				if ( 'skip' === $r->get_error_code() ) {
					++$skipped;
				} else {
					++$failed;
					\WP_CLI::warning( "#$id: " . $r->get_error_message() );
				}
				continue;
			}
			++$done;
			$saved += $r['bytes_before'] - $r['bytes_after'];
			\WP_CLI::log( sprintf( '#%d %s -> %s (%s -> %s)%s', $id, $r['old_name'], $r['new_name'], size_format( $r['bytes_before'] ), size_format( $r['bytes_after'] ), $refs ? sprintf( ', %d rows updated', (int) $r['rows'] ) : '' ) );
		}
		$bar->finish();
		if ( $dry ) {
			\WP_CLI::success( sprintf( '%d would be processed, %d skipped (dry run).', $done, $skipped ) );
		} else {
			\WP_CLI::success( sprintf( '%d converted, %d skipped, %d failed, %s saved.', $done, $skipped, $failed, size_format( max( 0, $saved ) ) ) );
		}
	}

	/* ------------------------------------------------------------ test image factories */

	private function make_png( $path, int $w, int $h, bool $alpha, array &$created ) {
		if ( ! $path || ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}
		$created[] = $path;
		$im = imagecreatetruecolor( $w, $h );
		if ( $alpha ) {
			imagesavealpha( $im, true );
			imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
		}
		for ( $i = 0; $i < 40; $i++ ) {
			imagefilledellipse( $im, wp_rand( 0, $w ), wp_rand( 0, $h ), wp_rand( 50, 400 ), wp_rand( 50, 400 ), imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
		}
		$ok = imagepng( $im, $path );
		imagedestroy( $im );
		return $ok ? $path : false;
	}

	private function make_jpeg( $path, int $w, int $h, array &$created ) {
		if ( ! $path || ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}
		$created[] = $path;
		$im = imagecreatetruecolor( $w, $h );
		for ( $y = 0; $y < $h; $y += 4 ) {
			imageline( $im, 0, $y, $w, $y, imagecolorallocate( $im, ( $y * 7 ) % 255, ( $y * 3 ) % 255, 200 ) );
		}
		$ok = imagejpeg( $im, $path, 92 );
		imagedestroy( $im );
		return $ok ? $path : false;
	}
}
