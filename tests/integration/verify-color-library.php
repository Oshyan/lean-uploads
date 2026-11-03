<?php
/** Run with wp eval-file on a disposable WordPress install. All images and attachments are synthetic. */
require_once ABSPATH . 'wp-admin/includes/image.php';

if ( ! class_exists( 'Imagick' ) ) {
	WP_CLI::error( 'This test requires Imagick; no image processing was tested.' );
}

$service = new LeanUploads\Color_Library();
$profile = file_get_contents( dirname( __DIR__ ) . '/fixtures/synthetic-gamma18.icc' );
$srgb = LeanUploads\Color_Profiles::srgb_profile();
if ( is_wp_error( $srgb ) || ! $profile ) {
	WP_CLI::error( 'Color test profiles are unavailable.' );
}
$upload = wp_upload_dir();
$directory = $upload['path'] . '/lean-color-library-' . bin2hex( random_bytes( 8 ) );
if ( ! wp_mkdir_p( $directory ) ) {
	WP_CLI::error( 'Cannot create the temporary fixture directory.' );
}
$made = array();
$failures = 0;
$checks = 0;
$check = static function ( $ok, string $label ) use ( &$failures, &$checks ) {
	++$checks;
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $label );
	if ( ! $ok ) { ++$failures; }
};
$png = static function ( string $path, string $icc, int $w = 400, int $h = 240 ) {
	$image = new Imagick();
	$image->newImage( $w, $h, new ImagickPixel( 'transparent' ), 'PNG' );
	$draw = new ImagickDraw();
	$draw->setFillColor( new ImagickPixel( 'rgba(180,70,40,0.65)' ) );
	$draw->rectangle( 10, 10, $w - 10, $h - 10 );
	$image->drawImage( $draw );
	if ( $icc ) { $image->profileImage( 'icc', $icc ); }
	$image->writeImage( $path );
	$image->clear();
};
$attachment = static function ( string $stem ) use ( $directory, $png, $profile, $srgb, &$made ) {
	$full = $directory . '/' . $stem . '.png';
	$png( $full, $profile );
	$png( $directory . '/' . $stem . '-original.png', $profile, 500, 300 );
	$png( $directory . '/' . $stem . '-tagged.png', $profile, 100, 60 );
	$png( $directory . '/' . $stem . '-untagged.png', '', 50, 30 );
	$png( $directory . '/' . $stem . '-srgb.png', $srgb, 60, 36 );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Synthetic color regression fixture', 'post_status' => 'inherit' ), $full, 0, true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Cannot create synthetic attachment.' ); }
	$made[] = $id;
	$meta = array(
		'file' => _wp_relative_upload_path( $full ), 'width' => 400, 'height' => 240, 'filesize' => wp_filesize( $full ),
		'original_image' => $stem . '-original.png',
		'sizes' => array(
			'tagged' => array( 'file' => $stem . '-tagged.png', 'width' => 100, 'height' => 60, 'mime-type' => 'image/png' ),
			'untagged' => array( 'file' => $stem . '-untagged.png', 'width' => 50, 'height' => 30, 'mime-type' => 'image/png' ),
			'srgb' => array( 'file' => $stem . '-srgb.png', 'width' => 60, 'height' => 36, 'mime-type' => 'image/png' ),
		),
	);
	wp_update_attachment_metadata( $id, $meta );
	return $id;
};
$hashes = static function ( array $files ): array {
	$result = array();
	foreach ( $files as $file ) { $result[ $file ] = hash_file( 'sha256', $file ); }
	return $result;
};

try {
	$id = $attachment( 'normalize' );
	$url = wp_get_attachment_url( $id );
	$plan = $service->inspect( $id );
	$check( ! is_wp_error( $plan ) && 3 === count( $plan['convert'] ) && 2 === count( $plan['skipped'] ), 'inspection selects only tagged non-sRGB full, original and thumbnail files' );
	if ( is_wp_error( $plan ) ) { throw new RuntimeException( $plan->get_error_message() ); }
	$before = $hashes( $plan['files'] );
	$service->inspect( $id );
	$check( $before === $hashes( $plan['files'] ), 'dry inspection does not modify any file' );
	$result = $service->normalize_attachment( $id );
	$check( ! is_wp_error( $result ) && 3 === $result['converted'] && 1 === $result['untagged'], 'normalization converts the three eligible files and reports the untagged derivative' );
	$check( $url === wp_get_attachment_url( $id ) && $plan['path'] === get_attached_file( $id, true ), 'attachment URL and file path remain unchanged' );
	$meta = wp_get_attachment_metadata( $id );
	foreach ( $plan['convert'] as $file => $hash ) {
		$info = LeanUploads\Color_Profiles::inspect( $file );
		$check( ! is_wp_error( $info ) && $info['is_srgb'] && $info['hash'] === hash( 'sha256', $srgb ), 'reopened saved file has the actual standard sRGB profile: ' . basename( $file ) );
	}
	foreach ( $plan['skipped'] as $file => $reason ) {
		$check( $before[ $file ] === hash_file( 'sha256', $file ), 'skipped file is byte-for-byte unchanged: ' . $reason );
	}
	$check( $meta['width'] === 400 && $meta['height'] === 240 && $meta['filesize'] === wp_filesize( $plan['path'] ), 'full-size geometry and file-size metadata are correct' );
	$check( $meta['sizes']['tagged']['filesize'] === wp_filesize( $directory . '/normalize-tagged.png' ), 'thumbnail file-size metadata is refreshed' );
	$normalized_hashes = $hashes( $plan['files'] );
	$again = $service->normalize_attachment( $id );
	$check( is_wp_error( $again ) && 'skip' === $again->get_error_code() && $normalized_hashes === $hashes( $plan['files'] ), 'repeat operation skips all files without recompression' );
	$check( false === get_option( LeanUploads\Color_Library::LOCK_PREFIX . $id, false ), 'successful and no-op operations release their attachment lock' );

	$failure_id = $attachment( 'rollback' );
	$failure_plan = $service->inspect( $failure_id );
	$failure_hashes = $hashes( $failure_plan['files'] );
	$thrown = false;
	$observed_replacement = false;
	$interrupt = static function ( $metadata, $attachment_id ) use ( $failure_id, &$thrown, &$observed_replacement, $failure_plan, $failure_hashes, $hashes ) {
		if ( (int) $attachment_id === $failure_id && ! $thrown ) {
			$thrown = true;
			$observed_replacement = $failure_hashes !== $hashes( $failure_plan['files'] );
			throw new RuntimeException( 'Synthetic failure after file replacement.' );
		}
		return $metadata;
	};
	add_filter( 'wp_update_attachment_metadata', $interrupt, PHP_INT_MAX, 2 );
	try { $failed = $service->normalize_attachment( $failure_id ); }
	finally { remove_filter( 'wp_update_attachment_metadata', $interrupt, PHP_INT_MAX ); }
	$check( is_wp_error( $failed ) && $thrown && $observed_replacement, 'injected failure occurs after the verified outputs replaced the sources' );
	$check( $failure_hashes === $hashes( $failure_plan['files'] ), 'failed commit restores every original byte-for-byte' );
	$check( $failure_plan['metadata'] === wp_get_attachment_metadata( $failure_id ), 'failed commit restores the original attachment metadata' );
	$check( false === get_option( LeanUploads\Color_Library::LOCK_PREFIX . $failure_id, false ) && ! glob( $directory . '/.lean-color-*' ), 'successful rollback removes temporary files and releases its lock' );
	$always_fail = static function ( $metadata, $attachment_id ) use ( $failure_id ) {
		if ( (int) $attachment_id === $failure_id ) { throw new RuntimeException( 'Synthetic persistent metadata failure.' ); }
		return $metadata;
	};
	add_filter( 'wp_update_attachment_metadata', $always_fail, PHP_INT_MAX, 2 );
	try { $needs_recovery = $service->normalize_attachment( $failure_id ); }
	finally { remove_filter( 'wp_update_attachment_metadata', $always_fail, PHP_INT_MAX ); }
	$check( is_wp_error( $needs_recovery ) && 'lean_uploads_color_recovery' === $needs_recovery->get_error_code() && $failure_hashes === $hashes( $failure_plan['files'] ), 'a persistently broken metadata hook cannot prevent restoring every image file' );
	$journal = get_option( LeanUploads\Color_Library::LOCK_PREFIX . $failure_id );
	$check( is_array( $journal ) && 'recovery_required' === $journal['state'], 'incomplete metadata recovery retains a journal and blocks further processing' );
	delete_option( LeanUploads\Color_Library::LOCK_PREFIX . $failure_id );

	$lock = LeanUploads\Color_Library::LOCK_PREFIX . $failure_id;
	add_option( $lock, array( 'state' => 'synthetic-active-lock' ), '', false );
	$busy = $service->normalize_attachment( $failure_id );
	$check( is_wp_error( $busy ) && 'lean_uploads_color_busy' === $busy->get_error_code() && get_option( $lock ) === array( 'state' => 'synthetic-active-lock' ), 'concurrent operation refuses to steal or clear another request lock' );
	delete_option( $lock );
	update_post_meta( $failure_id, LeanUploads\Pipeline::KEEP_META, 1 );
	$kept = $service->normalize_attachment( $failure_id );
	$check( is_wp_error( $kept ) && 'skip' === $kept->get_error_code() && $failure_hashes === $hashes( $failure_plan['files'] ), 'keep-as-uploaded flag blocks on-demand normalization' );
	delete_post_meta( $failure_id, LeanUploads\Pipeline::KEEP_META );
	$unsafe = LeanUploads\Color_Library::files( $failure_plan['path'], array( 'original_image' => '../outside.png' ) );
	$check( is_wp_error( $unsafe ), 'metadata paths cannot escape the attachment directory' );
	$svg = $directory . '/unsupported.svg';
	file_put_contents( $svg, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#123456"/></svg>' );
	$svg_id = wp_insert_attachment( array( 'post_mime_type' => 'image/svg+xml', 'post_title' => 'Synthetic unsupported fixture', 'post_status' => 'inherit' ), $svg, 0, true );
	if ( is_wp_error( $svg_id ) ) { throw new RuntimeException( 'Cannot create unsupported-format fixture.' ); }
	$made[] = $svg_id;
	$svg_hash = hash_file( 'sha256', $svg );
	$unsupported = $service->normalize_attachment( $svg_id );
	$check( is_wp_error( $unsupported ) && 'skip' === $unsupported->get_error_code() && $svg_hash === hash_file( 'sha256', $svg ), 'unsupported full attachment skips before profile decoding or metadata processing' );
	$meta = wp_get_attachment_metadata( $id );
	$meta['sizes']['unsupported'] = array( 'file' => 'unsupported.svg', 'width' => 10, 'height' => 10, 'mime-type' => 'image/svg+xml' );
	wp_update_attachment_metadata( $id, $meta );
	$with_unsupported = $service->inspect( $id );
	$check( ! is_wp_error( $with_unsupported ) && 'unsupported_format' === $with_unsupported['skipped'][ $svg ] && $svg_hash === hash_file( 'sha256', $svg ), 'unsupported derivative is explicitly skipped and left unchanged' );
	unset( $meta['sizes']['unsupported'] );
	wp_update_attachment_metadata( $id, $meta ); // The SVG belongs to its own synthetic attachment for cleanup.
} catch ( Throwable $e ) {
	++$failures;
	WP_CLI::warning( $e->getMessage() );
} finally {
	foreach ( $made as $id ) {
		delete_option( LeanUploads\Color_Library::LOCK_PREFIX . $id );
		wp_delete_attachment( $id, true );
	}
	foreach ( glob( $directory . '/*' ) as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
	foreach ( glob( $directory . '/.lean-color-*' ) as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
	rmdir( $directory );
}
if ( $failures ) { WP_CLI::error( "$failures color-library checks failed." ); }
WP_CLI::success( "$checks color-library checks passed; all synthetic attachments and files removed." );
