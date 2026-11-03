<?php
/** Synthetic real-upload regression checks; run with wp eval-file on an isolated test site. */
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
if ( ! class_exists( 'Imagick' ) || ! Imagick::queryFormats( 'WEBP' ) ) {
 WP_CLI::warning( 'SKIP: requires Imagick and WebP.' ); return;
}
$plugin = LeanUploads\Plugin::instance();
$made = $temps = array(); $fail = 0;
$prefix = 'lean-color-pipeline-' . strtolower( wp_generate_password( 8, false, false ) );
$profile = file_get_contents( dirname( __DIR__ ) . '/fixtures/synthetic-gamma18.icc' );
$options = array( 'enabled' => true, 'derivatives' => true, 'color_mode' => 'preserve', 'format' => 'webp', 'max_dimension' => 600, 'webp_quality' => 85, 'png_lossless' => false );
$settings_filter = static function ( $s ) use ( &$options ) { return array_replace( $s, $options ); };
$reset = static function () use ( $plugin ) {
 $r = new ReflectionProperty( $plugin->settings, 'cache' );
 if ( PHP_VERSION_ID < 80100 ) { $r->setAccessible( true ); }
 $r->setValue( $plugin->settings, null );
};
$check = static function ( $ok, $message ) use ( &$fail ) { WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $message ); if ( ! $ok ) { ++$fail; } };
$keep = static function () { return false; };
$upload = static function ( $name, $format = 'png', $icc = null ) use ( &$made, &$temps, $profile, $prefix ) {
 $tmp = wp_tempnam( $prefix ); $temps[] = $tmp;
 $im = new Imagick(); $im->newImage( 800, 600, new ImagickPixel( 'rgb(180,65,42)' ) );
 $draw = new ImagickDraw(); $draw->setFillColor( 'rgb(80,160,200)' ); $draw->rectangle( 400, 0, 799, 599 ); $im->drawImage( $draw );
 $im->setImageFormat( $format );
 if ( false !== $icc ) { $im->profileImage( 'icc', $icc ?? $profile ); }
 if ( 'webp' === $format ) { $im->setOption( 'webp:lossless', 'true' ); }
 $im->writeImage( $tmp ); $im->clear();
 $source_hash = hash_file( 'sha256', $tmp );
 $id = media_handle_sideload( array( 'name' => $prefix . '-' . $name . '.' . $format, 'tmp_name' => $tmp ), 0 );
 if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
 $made[] = $id;
 return array( $id, $source_hash );
};
$profiles_ok = static function ( $id, $srgb ) use ( $profile ) {
 $path = get_attached_file( $id, true ); $meta = wp_get_attachment_metadata( $id ); $files = array( $path );
 foreach ( $meta['sizes'] ?? array() as $info ) { $files[] = dirname( $path ) . '/' . $info['file']; }
 if ( count( $files ) < 2 ) { return false; }
 foreach ( $files as $file ) {
  $p = LeanUploads\Color_Profiles::inspect( $file );
  if ( is_wp_error( $p ) || ! $p['has_icc'] || ( $srgb ? ! $p['is_srgb'] : hash( 'sha256', $profile ) !== $p['hash'] ) ) { return false; }
 }
 return true;
};
// Fault injection changes only this run's private staged output, before saved-file validation.
$inject_mode = ''; $injected = false; $full_saved = false; $nested_seen = false; $corrupted_path = '';
$is_nested = static function () {
 $property = new ReflectionProperty( LeanUploads\Pipeline::class, 'finalizing' );
 if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
 return (bool) $property->getValue();
};
$corrupt_staged_profile = static function ( $path ) use ( &$inject_mode, &$injected, &$full_saved, &$nested_seen, &$corrupted_path, $is_nested ) {
 if ( '' === $inject_mode || ! is_string( $path ) || ! is_file( $path ) || 0 !== strpos( basename( $path ), '.lean-color-' ) || 'image/webp' !== wp_get_image_mime( $path ) ) { return $path; }
 $size = wp_getimagesize( $path );
 if ( ! $size ) { return $path; }
 $nested = $is_nested();
 if ( $nested ) { $nested_seen = true; }
 if ( ! $nested && 600 === $size[0] ) { $full_saved = true; }
 $should_corrupt = 'nested' === $inject_mode ? $nested : ( $full_saved && $size[0] < 600 );
 if ( $should_corrupt && ! $injected ) {
  $im = new Imagick( $path ); $im->profileImage( 'icc', null ); $im->writeImage( $path ); $im->clear();
  $injected = true; $corrupted_path = $path;
 }
 return $path;
};
$defer_initial_sizes = static function ( $sizes ) use ( $is_nested ) { return $is_nested() ? $sizes : array(); };
$no_formats = static function () { return array(); };
$no_sizes = static function () { return array(); };
add_filter( 'image_make_intermediate_size', $corrupt_staged_profile, PHP_INT_MAX );
add_filter( 'lean_uploads_settings', $settings_filter, PHP_INT_MAX );
add_filter( 'lean_uploads_keep_smaller_original', $keep );
add_image_size( $prefix, 300, 200, true );
$reset();
try {
 list( $id ) = $upload( 'preserve' );
 $check( 'image/webp' === get_post_mime_type( $id ) && $profiles_ok( $id, false ), 'default preservation survives oversized PNG intermediate, converted original and cropped thumbnails' );
 $check( 600 === wp_get_attachment_metadata( $id )['width'], 'preserved-profile upload obeys dimension cap' );
 $check( null === LeanUploads\Color_Profiles::context_error(), 'attachment color context is unwound after completion' );
 $options['color_mode'] = 'srgb'; $reset();
 list( $id ) = $upload( 'normalize' );
 $check( $profiles_ok( $id, true ), 'opt-in upload normalization writes verified sRGB original and all thumbnails' );
 $im = new Imagick( get_attached_file( $id, true ) ); $pixel = $im->getImagePixelColor( 50, 50 )->getColor(); $im->clear();
 $check( abs( $pixel['r'] - 180 ) > 3, 'sRGB normalization changes pixels, rather than merely assigning a profile' );
 $options['max_dimension'] = 0; $reset();
 list( $id ) = $upload( 'modern-normalize', 'webp' );
 $check( 'image/webp' === get_post_mime_type( $id ) && 800 === wp_get_attachment_metadata( $id )['width'] && $profiles_ok( $id, true ), 'already-modern upload normalizes when needed even without a resize cap' );
 list( $id, $hash ) = $upload( 'already-srgb', 'webp', file_get_contents( dirname( __DIR__, 2 ) . '/assets/srgb.icc' ) );
 $check( $hash === hash_file( 'sha256', get_attached_file( $id, true ) ), 'already-sRGB modern full-size upload stays byte-for-byte unchanged' );
 list( $id, $hash ) = $upload( 'untagged', 'webp', false );
 $check( $hash === hash_file( 'sha256', get_attached_file( $id, true ) ), 'untagged modern upload is not assigned a guessed profile or re-encoded' );
 list( $id, $hash ) = $upload( 'optout--keep' );
 $check( $hash === hash_file( 'sha256', get_attached_file( $id, true ) ) && get_post_meta( $id, LeanUploads\Pipeline::KEEP_META, true ), 'explicit upload opt-out leaves original bytes and profile intact' );
 $options['enabled'] = false; $options['derivatives'] = true; $reset();
 list( $id, $hash ) = $upload( 'derivatives-only' );
 $check( $hash === hash_file( 'sha256', get_attached_file( $id, true ) ) && $profiles_ok( $id, false ), 'derivative-only mode preserves source profile without normalizing the original' );
 $options['enabled'] = true; $options['color_mode'] = 'preserve'; $options['max_dimension'] = 600; $reset();
 $result = $plugin->library->convert_attachment( $id );
 $check( ! is_wp_error( $result ) && $profiles_ok( $id, false ), 'explicit existing-library format conversion preserves profiles' );
 $check( false === get_option( 'lean_uploads_color_lock_' . $id ), 'format conversion releases its shared attachment lock' );

 // A failure after a valid full-size save must put the true source back, not just stop deletion.
 $inject_mode = 'after-full'; $injected = $full_saved = false; $corrupted_path = '';
 list( $id, $hash ) = $upload( 'failed-subsize' );
 $inject_mode = '';
 $path = get_attached_file( $id, true ); $meta = wp_get_attachment_metadata( $id );
 $check( $injected && $full_saved, 'fault injection removes ICC from a staged thumbnail after a verified full-size save' );
 $check( $hash === hash_file( 'sha256', $path ) && 'image/png' === wp_get_image_mime( $path ) && basename( $path ) === $prefix . '-failed-subsize.png' && 800 === $meta['width'] && 600 === $meta['height'], 'later thumbnail failure restores original bytes, attachment pointer and original dimensions' );
 $check( $corrupted_path && ! file_exists( $corrupted_path ), 'failed staged thumbnail is removed without publishing profile-free output' );
 $check( null === LeanUploads\Color_Profiles::context_error() && array( 'WP_Image_Editor_GD' ) === LeanUploads\Color_Profiles::filter_editors( array( 'WP_Image_Editor_GD' ) ), 'failed attachment unwinds its color context and editor restriction' );

 // Make core leave full-size conversion to the fallback and defer sub-sizes to its nested regeneration.
 $inject_mode = 'nested'; $injected = $nested_seen = false; $corrupted_path = '';
 add_filter( 'image_editor_output_format', $no_formats, PHP_INT_MAX, 3 );
 add_filter( 'intermediate_image_sizes_advanced', $defer_initial_sizes, PHP_INT_MAX );
 try { list( $id, $hash ) = $upload( 'failed-nested-subsize' ); }
 finally {
  $inject_mode = '';
  remove_filter( 'image_editor_output_format', $no_formats, PHP_INT_MAX );
  remove_filter( 'intermediate_image_sizes_advanced', $defer_initial_sizes, PHP_INT_MAX );
 }
 $path = get_attached_file( $id, true ); $meta = wp_get_attachment_metadata( $id );
 $check( $nested_seen && $injected, 'fault injection reaches a protected save inside fallback nested thumbnail regeneration' );
 $check( $hash === hash_file( 'sha256', $path ) && basename( $path ) === $prefix . '-failed-nested-subsize.png' && 800 === $meta['width'] && 600 === $meta['height'], 'nested color failure reaches outer finalizer and restores true original bytes, pointer and dimensions' );
 $check( null === LeanUploads\Color_Profiles::context_error() && array( 'WP_Image_Editor_GD' ) === LeanUploads\Color_Profiles::filter_editors( array( 'WP_Image_Editor_GD' ) ), 'nested failure unwinds context only when outer attachment completes' );

 // No dimension change and no thumbnails must not cause modern-format normalization to be skipped.
 $options['color_mode'] = 'srgb'; $options['max_dimension'] = 1200; $reset();
 add_filter( 'intermediate_image_sizes_advanced', $no_sizes, PHP_INT_MAX );
 try { list( $id ) = $upload( 'modern-below-cap-no-sizes', 'webp' ); }
 finally { remove_filter( 'intermediate_image_sizes_advanced', $no_sizes, PHP_INT_MAX ); }
 $path = get_attached_file( $id, true ); $meta = wp_get_attachment_metadata( $id ); $color = LeanUploads\Color_Profiles::inspect( $path );
 $check( ! is_wp_error( $color ) && $color['is_srgb'] && 'image/webp' === wp_get_image_mime( $path ) && 800 === $meta['width'] && 600 === $meta['height'] && empty( $meta['sizes'] ), 'modern image below the cap normalizes without resizing even when no thumbnail sizes are requested' );

} catch ( Throwable $e ) { $check( false, 'exception: ' . $e->getMessage() ); }
finally {
 remove_filter( 'image_make_intermediate_size', $corrupt_staged_profile, PHP_INT_MAX );
 remove_filter( 'image_editor_output_format', $no_formats, PHP_INT_MAX );
 remove_filter( 'intermediate_image_sizes_advanced', $defer_initial_sizes, PHP_INT_MAX );
 remove_filter( 'intermediate_image_sizes_advanced', $no_sizes, PHP_INT_MAX );
 remove_filter( 'lean_uploads_settings', $settings_filter, PHP_INT_MAX ); remove_filter( 'lean_uploads_keep_smaller_original', $keep ); remove_image_size( $prefix ); $reset();
 foreach ( $made as $id ) { wp_delete_attachment( $id, true ); }
 foreach ( $temps as $tmp ) { if ( is_file( $tmp ) ) { wp_delete_file( $tmp ); } }
}
if ( $fail ) { WP_CLI::error( "$fail color pipeline check(s) failed." ); }
WP_CLI::success( 'Color pipeline checks passed; synthetic fixtures removed.' );
