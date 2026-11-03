<?php
/** Synthetic file-only checks: no attachments, database writes, or saved option changes. Run with wp eval-file. */
if ( ! class_exists( 'Imagick' ) ) { WP_CLI::warning( 'SKIP: Imagick is required.' ); return; }
require_once ABSPATH . 'wp-admin/includes/image.php';
$plugin = LeanUploads\Plugin::instance();
$failures = 0;
$check = static function ( $ok, $message ) use ( &$failures ) {
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $message );
	if ( ! $ok ) { ++$failures; }
};
$dir = wp_tempnam( 'lean-color-converter-' ); unlink( $dir ); mkdir( $dir, 0700 );
$source = $dir . '/source.png';
$fixture = file_get_contents( dirname( __DIR__ ) . '/fixtures/synthetic-gamma18.icc' );
$target = LeanUploads\Color_Profiles::srgb_profile();
$gd = static function () { return array( 'WP_Image_Editor_GD' ); };
$reject = static function ( $limit ) {
	LeanUploads\Color_Profiles::fail( new WP_Error( 'lean_color_injected_error', 'Synthetic blocked color context.' ) );
	return $limit;
};
$poison = static function ( $path ) use ( $dir ) {
	if ( dirname( $path ) === $dir && 0 === strpos( basename( $path ), '.lean-color-' ) ) {
		$im = new Imagick( $path );
		$im->removeImageProfile( 'icc' ); $im->writeImage( $path ); $im->clear();
	}
	return $path;
};
$token = null;
try {
	$im = new Imagick(); $im->newImage( 800, 600, new ImagickPixel( 'rgba(80,130,220,0.75)' ), 'PNG' );
	$im->setImageProfile( 'icc', $fixture ); $im->writeImage( $source ); $im->clear();
	$source_hash = hash_file( 'sha256', $source );
	foreach ( array( 'preserve', 'srgb' ) as $mode ) {
		foreach ( array( 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif' ) as $mime => $ext ) {
			if ( ! $plugin->capabilities->supports( $mime ) ) { WP_CLI::log( 'SKIP unsupported ' . $mime ); continue; }
			foreach ( array( 0, 300 ) as $max ) {
				$dest = $dir . '/' . $mode . '-' . $max . '.' . $ext;
				$r = $plugin->converter->convert( $source, $dest, $mime, array( 'quality' => 85, 'max_dimension' => $max, 'color_mode' => $mode ) );
				$info = is_file( $dest ) ? LeanUploads\Color_Profiles::inspect( $dest ) : null;
				$expected = 'srgb' === $mode ? $target : $fixture;
				$check( ! is_wp_error( $r ) && is_array( $info ) && hash( 'sha256', $expected ) === $info['hash'], "$mode $mime cap=$max retains expected ICC in reopened output" );
				if ( is_wp_error( $r ) ) { WP_CLI::log( $r->get_error_message() ); }
			}
		}
	}
	add_filter( 'wp_image_editors', $gd, PHP_INT_MAX );
	$r = $plugin->converter->convert( $source, $dir . '/forced-gd.webp', 'image/webp', array( 'quality' => 85, 'max_dimension' => 0 ) );
	remove_filter( 'wp_image_editors', $gd, PHP_INT_MAX );
	$info = is_file( $dir . '/forced-gd.webp' ) ? LeanUploads\Color_Profiles::inspect( $dir . '/forced-gd.webp' ) : null;
	$check( ! is_wp_error( $r ) && is_array( $info ) && hash( 'sha256', $fixture ) === $info['hash'], 'a forced GD preference cannot bypass the safe tagged-image editor' );

	$normalized = $dir . '/normalized.png';
	$r = LeanUploads\Color_Profiles::normalize_file( $source, $normalized );
	$im = new Imagick( $source ); $reference = $im->getImagePixelColor( 400, 300 )->getColor();
	$im->profileImage( 'icc', $target ); $managed = $im->getImagePixelColor( 400, 300 )->getColor(); $im->clear();
	$out = new Imagick( $normalized ); $actual = $out->getImagePixelColor( 400, 300 )->getColor();
	$alpha = $out->getImageAlphaChannel(); $out->clear();
	$check( ! is_wp_error( $r ) && $actual === $managed && $actual !== $reference && $alpha, 'sRGB normalization changes pixels through the source profile and preserves alpha' );

	$indexed = $dir . '/indexed.png';
	$im = new Imagick(); $im->newImage( 800, 600, 'transparent', 'PNG' ); $draw = new ImagickDraw();
	foreach ( array( array( 'rgb(180,65,42)', 0, 0, 399, 299 ), array( 'rgb(60,150,200)', 400, 0, 799, 299 ), array( 'rgb(120,120,120)', 0, 300, 399, 599 ), array( 'rgba(60,160,80,0.5)', 400, 300, 799, 599 ) ) as $patch ) {
		$draw->setFillColor( $patch[0] ); $draw->rectangle( $patch[1], $patch[2], $patch[3], $patch[4] );
	}
	$im->drawImage( $draw ); $im->setImageProfile( 'icc', $fixture ); $im->writeImage( $indexed ); $im->clear();
	$im = new Imagick( $indexed ); $indexed_fixture = '3' === $im->getImageProperty( 'png:IHDR.color-type-orig' ) && $im->getImageDepth() < 8;
	$original_patch = $im->getImagePixelColor( 100, 100 )->getColor(); $im->clear();
	$token = LeanUploads\Color_Profiles::push_context( $indexed, 'preserve' );
	$editor = wp_get_image_editor( $indexed );
	if ( is_wp_error( $editor ) ) { throw new RuntimeException( $editor->get_error_message() ); }
	$editor->resize( 400, 300 ); $r = $editor->save( $dir . '/indexed-resized.png', 'image/png' );
	LeanUploads\Color_Profiles::pop_context( $token ); $token = null;
	$out = new Imagick( $dir . '/indexed-resized.png' ); $actual_patch = $out->getImagePixelColor( 50, 50 )->getColor(); $out->clear();
	$check( $indexed_fixture && ! is_wp_error( $r ) && $actual_patch === $original_patch, 'indexed PNG palette index depth cannot quantize 8-bit RGB patch colors during core resize' );

	$existing = $dir . '/existing.png'; copy( $source, $existing ); $existing_hash = hash_file( 'sha256', $existing );
	$token = LeanUploads\Color_Profiles::push_context( $source, 'preserve' );
	$editor = wp_get_image_editor( $source );
	if ( is_wp_error( $editor ) ) { throw new RuntimeException( $editor->get_error_message() ); }
	$editor->resize( 300, 225, false );
	add_filter( 'image_make_intermediate_size', $poison, PHP_INT_MAX );
	$r = $editor->save( $existing, 'image/png' );
	remove_filter( 'image_make_intermediate_size', $poison, PHP_INT_MAX );
	$check( is_wp_error( $r ) && is_wp_error( LeanUploads\Color_Profiles::context_error() ) && $existing_hash === hash_file( 'sha256', $existing ), 'saved-output profile loss rejects staging and preserves an existing destination' );
	LeanUploads\Color_Profiles::pop_context( $token ); $token = null;
	add_filter( 'lean_uploads_max_pixels', $reject );
	$r = $plugin->converter->convert( $source, $existing, 'image/png', array( 'quality' => 85, 'max_dimension' => 300, 'color_mode' => 'preserve' ) );
	remove_filter( 'lean_uploads_max_pixels', $reject );
	$check( is_wp_error( $r ) && $existing_hash === hash_file( 'sha256', $existing ) && null === LeanUploads\Color_Profiles::context_error(), 'standalone conversion cannot fall through an errored color context and report success' );
	$check( $source_hash === hash_file( 'sha256', $source ) && ! glob( $dir . '/.lean-color-*' ), 'source is unchanged and staged editor files are cleaned after failure' );
} catch ( Throwable $e ) {
	$check( false, $e->getMessage() );
} finally {
	remove_filter( 'wp_image_editors', $gd, PHP_INT_MAX );
	remove_filter( 'lean_uploads_max_pixels', $reject );
	remove_filter( 'image_make_intermediate_size', $poison, PHP_INT_MAX );
	if ( null !== $token ) { LeanUploads\Color_Profiles::pop_context( $token ); }
	foreach ( array_merge( (array) glob( $dir . '/*' ), (array) glob( $dir . '/.lean-color-*' ) ) as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
	rmdir( $dir );
}
if ( $failures ) { WP_CLI::error( "$failures color converter check(s) failed." ); }
WP_CLI::success( 'Color converter checks passed; synthetic files removed.' );
