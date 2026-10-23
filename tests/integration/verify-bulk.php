<?php
/**
 * Bulk engine + URL rewriter verification, run with `wp eval-file`. Creates its own attachment, post, post meta
 * and option, and deletes ONLY those (by captured IDs/names). The bulk run is given an explicit ID list
 * containing just the fixture, so nothing else in the library is touched.
 */
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$plugin = LeanUploads\Plugin::instance();
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

$att_id = 0; $post_id = 0; $opt = 'lean_verify_bulk_option_' . wp_rand( 1000, 9999 ); $tmp = '';
// The bulk engine checks edit_post per attachment (it is an admin feature); WP-CLI has no user, so act as one.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( empty( $admins ) ) { WP_CLI::error( 'no administrator user to run as' ); }
wp_set_current_user( (int) $admins[0] );
$bulk_before = get_option( LeanUploads\Bulk::OPTION, null ); // restore whatever bulk state the site had
try {
	// Fixture attachment, created with conversion off so it stays PNG.
	$off = static function ( $s ) { $s['enabled'] = false; $s['derivatives'] = false; return $s; };
	add_filter( 'lean_uploads_settings', $off ); $reset_settings_cache();
	$tmp    = $mk_png( wp_tempnam( 'lean-verify-b' ), 1000, 700 );
	$att_id = media_handle_sideload( array( 'name' => 'lean-verify-b.jpg', 'tmp_name' => $tmp ), 0 );
	remove_filter( 'lean_uploads_settings', $off ); $reset_settings_cache();
	if ( is_wp_error( $att_id ) ) { throw new RuntimeException( 'sideload failed: ' . $att_id->get_error_message() ); }
	$full   = wp_get_attachment_url( $att_id );
	$medium = wp_get_attachment_image_url( $att_id, 'medium' );
	$log( "fixture #$att_id $full" );

	// References: post content (plain), post meta (serialized array), option (serialized nested), untouched lookalike.
	$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'lean-verify-bulk', 'post_content' => "<img src=\"$full\"> <img src=\"$medium\">" ) );
	update_post_meta( $post_id, 'lean_verify_meta', array( 'hero' => $full, 'list' => array( $medium, 'keep-me' ) ) );
	update_option( $opt, array( 'deep' => array( 'url' => $full ), 'n' => 5 ), false );
	$lookalike = str_replace( 'lean-verify-b', 'lean-verify-bx', $full );
	update_post_meta( $post_id, 'lean_verify_lookalike', $lookalike );

	// Dry run first: nothing may change.
	$plugin->bulk->start( true, true, array( $att_id ) );
	$state = $plugin->bulk->step();
	$check( 'done' === $state['status'] && 1 === $state['done'] && 'image/jpeg' === get_post_mime_type( $att_id ), 'dry run reports 1 and changes nothing' );

	// Real run on the fixture only.
	$plugin->bulk->start( true, false, array( $att_id ) );
	$state = $plugin->bulk->step();
	$log( 'state: ' . wp_json_encode( array_intersect_key( $state, array_flip( array( 'status', 'cursor', 'total', 'done', 'skipped', 'failed', 'saved' ) ) ) ) );
	$log( 'log: ' . implode( ' | ', (array) $state['log'] ) );
	$check( 'done' === $state['status'] && 1 === $state['done'] && 0 === $state['failed'], 'bulk run converted the fixture' );
	$new_full = wp_get_attachment_url( $att_id );
	$check( 'image/webp' === get_post_mime_type( $att_id ) && 'webp' === pathinfo( $new_full, PATHINFO_EXTENSION ), 'fixture is WebP now' );

	// Rewriter results, read straight from the database (bypassing caches) and through the API (cache cleaned).
	global $wpdb;
	$content_db = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
	$check( false !== strpos( $content_db, $new_full ) && false === strpos( $content_db, '.jpg' ), 'post content rewritten (db)' );
	$check( false !== strpos( get_post( $post_id )->post_content, $new_full ), 'post content rewritten (api, cache cleaned)' );
	$meta = get_post_meta( $post_id, 'lean_verify_meta', true );
	$check( is_array( $meta ) && $meta['hero'] === $new_full && 'keep-me' === $meta['list'][1] && 'webp' === pathinfo( $meta['list'][0], PATHINFO_EXTENSION ), 'serialized post meta rewritten and still valid' );
	$o = get_option( $opt );
	$check( is_array( $o ) && $o['deep']['url'] === $new_full && 5 === $o['n'], 'serialized option rewritten and still valid' );
	$check( get_post_meta( $post_id, 'lean_verify_lookalike', true ) === $lookalike, 'lookalike URL with a different base name untouched' );

	// Lock: a step while another request holds the lock does nothing and reports busy; a stale lock is taken over.
	$plugin->bulk->start( false, true, array( $att_id ) );
	add_option( LeanUploads\Bulk::LOCK, time(), '', false );
	$s = $plugin->bulk->step();
	$check( ! empty( $s['busy'] ) && 0 === $s['cursor'], 'step reports busy and does nothing while the lock is held' );
	update_option( LeanUploads\Bulk::LOCK, time() - LeanUploads\Bulk::LOCK_STALE_SEC - 1, false );
	$s = $plugin->bulk->step();
	$check( empty( $s['busy'] ) && 'done' === $s['status'] && false === get_option( LeanUploads\Bulk::LOCK ), 'a stale lock is taken over and released afterwards' );
	// Recovery: an item left in flight by a request that died is counted as failed, not retried forever.
	$plugin->bulk->start( false, true, array( $att_id ) );
	$st = get_option( LeanUploads\Bulk::OPTION ); $st['in_flight'] = 999999999; update_option( LeanUploads\Bulk::OPTION, $st, false );
	$s = $plugin->bulk->step();
	$check( 1 === $s['failed'] && 0 === $s['in_flight'] && 'done' === $s['status'] && false !== strpos( implode( ' ', $s['log'] ), '#999999999 failed' ), 'an item left in flight by a dead request is reported as failed once' );

	// Pause/resume/reset state machine.
	$plugin->bulk->start( false, true, array( $att_id ) );
	$s = $plugin->bulk->pause();  $check( 'paused' === $s['status'], 'pause' );
	$s = $plugin->bulk->step();   $check( 'paused' === $s['status'] && 0 === $s['cursor'], 'step does nothing while paused' );
	$s = $plugin->bulk->resume(); $check( 'running' === $s['status'], 'resume' );
	$s = $plugin->bulk->reset();  $check( 'idle' === $s['status'] && 0 === $s['total'], 'reset' );
} catch ( Throwable $e ) {
	$check( false, 'exception: ' . $e->getMessage() );
} finally {
	if ( $post_id && 'lean-verify-bulk' === get_the_title( $post_id ) ) { wp_delete_post( $post_id, true ); $log( "deleted post #$post_id" ); }
	if ( $att_id && 0 === strpos( get_the_title( $att_id ), 'lean-verify-' ) ) { $f = get_attached_file( $att_id, true ); wp_delete_attachment( $att_id, true ); $log( "deleted attachment #$att_id; leftover files: " . count( (array) glob( dirname( (string) $f ) . '/lean-verify-b*' ) ) ); }
	delete_option( $opt );
	if ( null === $bulk_before ) { delete_option( LeanUploads\Bulk::OPTION ); } else { update_option( LeanUploads\Bulk::OPTION, $bulk_before, false ); }
	delete_option( LeanUploads\Bulk::LOCK );
	if ( $tmp && file_exists( $tmp ) ) { wp_delete_file( $tmp ); }
}
if ( $fail ) { WP_CLI::error( "$fail check(s) failed." ); }
WP_CLI::success( 'All bulk/rewriter checks passed.' );
