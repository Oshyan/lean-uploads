<?php
/**
 * Lean Uploads verification, run with `wp eval-file`. Creates its own attachments and deletes ONLY those,
 * by the IDs media_handle_sideload() returned, after re-checking their titles. Never touches anything else.
 */
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$plugin = LeanUploads\Plugin::instance();
$made   = array();
$fail   = 0;
$log    = static function ( $m ) { WP_CLI::log( $m ); };
$check  = static function ( $ok, $m ) use ( &$fail, $log ) { $log( ( $ok ? 'PASS ' : 'FAIL ' ) . $m ); if ( ! $ok ) { ++$fail; } };
$reset_settings_cache = static function () use ( $plugin ) {
	$r = new ReflectionProperty( $plugin->settings, 'cache' ); $r->setAccessible( true ); $r->setValue( $plugin->settings, null );
};
$mk_png = static function ( $path, $w, $h ) {
	// Photo-like content (smooth gradients plus soft shapes) so a lossy WebP is reliably smaller than the PNG.
	$im = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y += 2 ) { imageline( $im, 0, $y, $w, $y, imagecolorallocate( $im, (int) ( 255 * $y / $h ), (int) ( 128 + 127 * sin( $y / 40 ) ), (int) ( 255 - 255 * $y / $h ) ) ); }
	for ( $i = 0; $i < 40; $i++ ) { imagefilledellipse( $im, wp_rand( 0, $w ), wp_rand( 0, $h ), wp_rand( 80, 300 ), wp_rand( 80, 300 ), imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) ); }
	imagejpeg( $im, $path, 95 ); return $path;
};
$describe = static function ( $id ) {
	$f = get_attached_file( $id, true ); $m = wp_get_attachment_metadata( $id );
	$mimes = array_unique( array_map( static function ( $s ) { return $s['mime-type'] ?? '?'; }, (array) ( $m['sizes'] ?? array() ) ) );
	return sprintf( '#%d %s %s %dx%d sizes=%d(%s) original_image=%s', $id, get_post_mime_type( $id ), basename( (string) $f ), (int) ( $m['width'] ?? 0 ), (int) ( $m['height'] ?? 0 ), count( (array) ( $m['sizes'] ?? array() ) ), implode( ',', $mimes ), $m['original_image'] ?? '-' );
};

try {
	// ---- 1. A PNG attachment created with conversion OFF (stands in for a pre-existing library image).
	$off = static function ( $s ) { $s['enabled'] = false; $s['derivatives'] = false; return $s; };
	add_filter( 'lean_uploads_settings', $off ); $reset_settings_cache();
	$tmp = $mk_png( wp_tempnam( 'lean-verify-a' ), 1200, 800 );
	$id  = media_handle_sideload( array( 'name' => 'lean-verify-a.jpg', 'tmp_name' => $tmp ), 0 );
	remove_filter( 'lean_uploads_settings', $off ); $reset_settings_cache();
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'sideload failed: ' . $id->get_error_message() ); }
	$made[] = $id;
	$log( 'created ' . $describe( $id ) );
	$check( 'image/jpeg' === get_post_mime_type( $id ), 'fixture is a JPEG attachment' );

	// ---- 2. Core's regenerate path (what the upload_files-only AJAX endpoint and regenerate plugins call)
	//         must NOT convert or delete the original now that conversion is on again.
	$before_file = get_attached_file( $id, true );
	$before_size = filesize( $before_file );
	wp_update_image_subsizes( $id );
	clearstatcache();
	$after_file = get_attached_file( $id, true );
	$log( 'after wp_update_image_subsizes(): ' . $describe( $id ) );
	$check( $after_file === $before_file && file_exists( $after_file ) && filesize( $after_file ) === $before_size, 'non-intended regeneration left the original file untouched' );
	$check( 'image/jpeg' === get_post_mime_type( $id ) && 'image/jpeg' === wp_get_image_mime( $after_file ), 'non-intended regeneration left mime/type as JPEG' );
	$m = wp_get_attachment_metadata( $id );
	$check( empty( $m['original_image'] ), 'no converted twin was created' );

	// ---- 3. The same attachment converted through the plugin's own Library path (intended).
	$r = $plugin->library->convert_attachment( $id, false );
	$log( 'after Library::convert_attachment(): ' . ( is_wp_error( $r ) ? 'ERR ' . $r->get_error_message() : $describe( $id ) ) );
	$check( ! is_wp_error( $r ), 'library conversion succeeded' );
	$f = get_attached_file( $id, true ); $m = wp_get_attachment_metadata( $id );
	$check( 'image/webp' === get_post_mime_type( $id ) && 'webp' === pathinfo( $f, PATHINFO_EXTENSION ), 'attachment is now WebP' );
	$check( ! file_exists( $before_file ), 'old JPEG original removed' );
	$check( empty( $m['original_image'] ), 'no original_image left in metadata' );
	$sizes_ok = ! empty( $m['sizes'] ); foreach ( (array) ( $m['sizes'] ?? array() ) as $s ) { if ( 'image/webp' !== ( $s['mime-type'] ?? '' ) ) { $sizes_ok = false; } }
	$check( $sizes_ok, 'all sizes are WebP' );
	$leftovers = glob( dirname( $f ) . '/lean-verify-a*.jpg' );
	$check( empty( $leftovers ), 'no JPEG files left for the fixture' );

	// ---- 4. Per-format quality plumbing.
	$q = $plugin->settings->quality_for( 'image/webp' ); $qa = $plugin->settings->quality_for( 'image/avif' );
	$check( is_int( $q ) && is_int( $qa ) && $q !== $qa, "quality_for: webp=$q avif=$qa" );
} catch ( Throwable $e ) {
	$check( false, 'exception: ' . $e->getMessage() );
} finally {
	// ---- cleanup: only the IDs we created, re-verified by title prefix.
	foreach ( $made as $id ) {
		$t = get_the_title( $id );
		if ( 0 === strpos( $t, 'lean-verify-' ) ) {
			$f = get_attached_file( $id, true );
			wp_delete_attachment( $id, true );
			$left = glob( dirname( (string) $f ) . '/lean-verify-*' );
			$log( "deleted #$id ($t); leftover files: " . count( (array) $left ) );
		} else {
			$log( "NOT deleting #$id: title '$t' is not ours" );
		}
	}
	if ( isset( $tmp ) && file_exists( $tmp ) ) { wp_delete_file( $tmp ); }
}
if ( $fail ) { WP_CLI::error( "$fail check(s) failed." ); }
WP_CLI::success( 'All verification checks passed.' );
