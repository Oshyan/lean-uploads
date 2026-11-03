<?php
/** Run with wp eval-file. Synthetic attachments only; settings are overridden in memory and restored. */
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
_wp_image_editor_choose();

if ( ! class_exists( 'WP_Image_Editor_Imagick' ) || ! WP_Image_Editor_Imagick::test() || ! WP_Image_Editor_Imagick::supports_mime_type( 'image/webp' ) || ! function_exists( 'imagecreatetruecolor' ) ) {
	WP_CLI::warning( 'SKIP: quality integration fixtures need Imagick with WebP and GD fixture generation.' );
	return;
}

class Lean_Uploads_Quality_Recorder extends WP_Image_Editor_Imagick {
	public static $writes = array();
	public static $fail_load = false;
	public function load() {
		if ( self::$fail_load ) { return new WP_Error( 'lean_quality_forced_failure', 'Synthetic editor load failure.' ); }
		return parent::load();
	}
	protected function _save( $image, $filename = null, $mime_type = null ) {
		$result = parent::_save( $image, $filename, $mime_type );
		if ( ! is_wp_error( $result ) ) {
			self::$writes[] = array( 'quality' => $this->get_quality(), 'mime' => $result['mime-type'], 'path' => $result['path'] );
		}
		return $result;
	}
}

$plugin = LeanUploads\Plugin::instance();
$made = array();
$temps = array();
$fail = 0;
$prefix = 'lean-quality-' . strtolower( wp_generate_password( 10, false, false ) );
$options = array( 'enabled' => true, 'derivatives' => true, 'enforce_quality' => true, 'format' => 'webp', 'webp_quality' => 85, 'avif_quality' => 61, 'max_dimension' => 600, 'convert_jpeg' => true, 'convert_png' => true, 'png_lossless' => false );
$settings_filter = static function ( $s ) use ( &$options ) { return array_replace( $s, $options ); };
$reset = static function () use ( $plugin ) {
	$r = new ReflectionProperty( $plugin->settings, 'cache' );
	if ( PHP_VERSION_ID < 80100 ) { $r->setAccessible( true ); }
	$r->setValue( $plugin->settings, null );
};
$check = static function ( $ok, $message ) use ( &$fail ) {
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $message );
	if ( ! $ok ) { ++$fail; }
};
$theme = static function () { return 100; };
$editors = static function () { return array( 'Lean_Uploads_Quality_Recorder' ); };
$keep = static function () { return false; };
$fixture = static function ( bool $png = false ) use ( &$temps, $prefix ) {
	$tmp = wp_tempnam( $prefix );
	$temps[] = $tmp;
	$im = imagecreatetruecolor( 800, 600 );
	if ( $png ) { imagealphablending( $im, false ); imagesavealpha( $im, true ); }
	for ( $y = 0; $y < 600; ++$y ) {
		$color = imagecolorallocatealpha( $im, $y % 256, ( $y * 7 ) % 256, ( $y * 13 ) % 256, $png && $y < 100 ? 100 : 0 );
		imageline( $im, 0, $y, 799, $y, $color );
	}
	$png ? imagepng( $im, $tmp ) : imagejpeg( $im, $tmp, 95 );
	return $tmp;
};
$upload = static function ( string $suffix, bool $png = false ) use ( &$made, $prefix, $fixture ) {
	Lean_Uploads_Quality_Recorder::$writes = array();
	$id = media_handle_sideload( array( 'name' => $prefix . '-' . $suffix . ( $png ? '.png' : '.jpg' ), 'tmp_name' => $fixture( $png ) ), 0 );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$made[] = $id;
	return $id;
};
$quality_is = static function ( int $expected ) {
	$writes = array_filter( Lean_Uploads_Quality_Recorder::$writes, static function ( $w ) { return 'image/webp' === $w['mime']; } );
	return count( $writes ) >= 2 && ! array_filter( $writes, static function ( $w ) use ( $expected ) { return $expected !== $w['quality']; } );
};

add_filter( 'lean_uploads_settings', $settings_filter, PHP_INT_MAX );
add_filter( 'wp_image_editors', $editors, PHP_INT_MAX );
add_filter( 'lean_uploads_keep_smaller_original', $keep );
add_filter( 'wp_editor_set_quality', $theme, 99, 2 );
// Added after plugin boot, at the same final priority: begin_attachment must move enforcement behind it.
add_filter( 'wp_editor_set_quality', $theme, PHP_INT_MAX, 2 );
add_image_size( $prefix, 300, 200, true );
$reset();
try {
	$id = $upload( 'on' );
	$check( 'image/webp' === get_post_mime_type( $id ) && $quality_is( 85 ), 'upload full and thumbnails use 85 after priority-99 and late final-priority theme filters' );
	$info = wp_get_webp_info( get_attached_file( $id, true ) );
	$check( 'lossy' === $info['type'], 'enforced photo output is lossy WebP, not accidental quality-100 lossless' );
	$check( 100 === apply_filters( 'wp_editor_set_quality', 82, 'image/webp' ), 'quality policy does not leak into unrelated editors after attachment completion' );
	$check( 100 === apply_filters( 'wp_editor_set_quality', 82, 'image/jpeg' ), 'theme retains JPEG quality control' );

	$options['enforce_quality'] = false; $reset();
	$upload( 'off' );
	$check( $quality_is( 100 ), 'opt-out allows theme quality for full and generated sizes' );

	$options['enabled'] = false; $options['derivatives'] = false; $reset();
	$legacy = $upload( 'legacy' );
	$check( 'image/jpeg' === get_post_mime_type( $legacy ), 'disabled conversion and derivatives leave JPEG fixture as JPEG' );
	$options['enabled'] = true; $options['derivatives'] = true; $options['enforce_quality'] = true; $reset();
	Lean_Uploads_Quality_Recorder::$writes = array();
	$result = $plugin->library->convert_attachment( $legacy, false );
	$check( ! is_wp_error( $result ) && $quality_is( 85 ), 'bulk/library conversion and sizes enforce configured quality' );

	$source = $fixture();
	foreach ( array( true, false ) as $enforce ) {
		$options['enforce_quality'] = $enforce; $reset();
		$base = wp_tempnam( $prefix ); $temps[] = $base;
		$dest = $base . '.webp'; $temps[] = $dest;
		Lean_Uploads_Quality_Recorder::$writes = array();
		$result = $plugin->converter->convert( $source, $dest, 'image/webp', array( 'quality' => 67, 'max_dimension' => 0 ) );
		$writes = Lean_Uploads_Quality_Recorder::$writes;
		$last = end( $writes );
		$check( ! is_wp_error( $result ) && $last && ( $enforce ? 67 : 100 ) === $last['quality'], 'fallback save honors ' . ( $enforce ? 'explicit operation quality 67, not site default 85' : 'theme quality when enforcement is off' ) );
		$check( 100 === apply_filters( 'wp_editor_set_quality', 82, 'image/webp' ), 'temporary operation quality filter removed after save' );
	}

	$options['enforce_quality'] = true; $options['png_lossless'] = true; $reset();
	Lean_Uploads_Quality_Recorder::$fail_load = true;
	$result = $plugin->converter->convert( $source, $dest, 'image/webp', array( 'quality' => 67, 'max_dimension' => 0 ) );
	Lean_Uploads_Quality_Recorder::$fail_load = false;
	$check( is_wp_error( $result ) && 100 === apply_filters( 'wp_editor_set_quality', 82, 'image/webp' ), 'failed fallback editor removes its temporary quality override' );
	$lossless = $upload( 'lossless', true );
	$info = wp_get_webp_info( get_attached_file( $lossless, true ) );
	$check( 'lossless' === $info['type'] && $quality_is( 100 ), 'PNG lossless intent survives enforced defaults for full and thumbnails' );
	$source_png = $fixture( true );
	$base = wp_tempnam( $prefix ); $temps[] = $base;
	$dest = $base . '.webp'; $temps[] = $dest;
	$result = $plugin->converter->convert( $source_png, $dest, 'image/webp', array( 'quality' => 67, 'max_dimension' => 400, 'lossless' => true ) );
	$info = is_wp_error( $result ) ? array() : wp_get_webp_info( $dest );
	$check( ! is_wp_error( $result ) && 'lossless' === ( $info['type'] ?? '' ), 'explicit lossless resized conversion bypasses lossy resize shortcut' );
} catch ( Throwable $e ) {
	$check( false, 'exception: ' . $e->getMessage() );
} finally {
	Lean_Uploads_Quality_Recorder::$fail_load = false;
	remove_filter( 'lean_uploads_settings', $settings_filter, PHP_INT_MAX );
	remove_filter( 'wp_image_editors', $editors, PHP_INT_MAX );
	remove_filter( 'lean_uploads_keep_smaller_original', $keep );
	remove_filter( 'wp_editor_set_quality', $theme, 99 );
	remove_filter( 'wp_editor_set_quality', $theme, PHP_INT_MAX );
	remove_image_size( $prefix );
	$reset();
	foreach ( $made as $id ) {
		if ( 0 === strpos( get_the_title( $id ), $prefix . '-' ) ) { wp_delete_attachment( $id, true ); }
	}
	foreach ( $temps as $path ) { if ( is_file( $path ) ) { wp_delete_file( $path ); } }
}
if ( $fail ) { WP_CLI::error( "$fail quality check(s) failed." ); }
WP_CLI::success( 'Quality integration checks passed; fixtures and request-only filters removed.' );
