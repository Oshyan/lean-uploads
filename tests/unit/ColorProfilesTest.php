<?php
namespace {
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private $code; private $message;
			public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ) { return $value instanceof WP_Error; }
	}
}
namespace LeanUploads\Tests {
	use LeanUploads\Color_Profiles;
	use PHPUnit\Framework\TestCase;
	require_once dirname( __DIR__, 2 ) . '/includes/class-color-profiles.php';

	final class ColorProfilesTest extends TestCase {
		private $files = array();
		private function file( string $bytes ): string {
			$path = tempnam( sys_get_temp_dir(), 'lean-color-test-' );
			file_put_contents( $path, $bytes ); $this->files[] = $path; return $path;
		}
		private function chunk( string $type, string $value ): string {
			return pack( 'N', strlen( $value ) ) . $type . $value . pack( 'N', crc32( $type . $value ) );
		}
		private function png( string $profile = '', string $extra = '' ): string {
			return "\x89PNG\r\n\x1a\n" . $this->chunk( 'IHDR', pack( 'NNCCCCC', 1, 1, 8, 2, 0, 0, 0 ) )
				. ( $profile ? $this->chunk( 'iCCP', "Generic\0\0" . gzcompress( $profile ) ) : '' ) . $extra
				. $this->chunk( 'IDAT', gzcompress( "\0\0\0\0" ) ) . $this->chunk( 'IEND', '' );
		}
		protected function tearDown(): void {
			Color_Profiles::pop_context( 0 );
			foreach ( $this->files as $path ) { unlink( $path ); }
		}
		public function test_generic_profiles_are_valid_and_names_do_not_define_srgb(): void {
			$srgb = Color_Profiles::srgb_profile();
			$custom = file_get_contents( dirname( __DIR__ ) . '/fixtures/synthetic-gamma18.icc' );
			$this->assertTrue( Color_Profiles::valid_icc( $srgb ) );
			$this->assertTrue( Color_Profiles::valid_icc( $custom ) );
			$this->assertTrue( Color_Profiles::is_srgb_profile( $srgb ) );
			$this->assertFalse( Color_Profiles::is_srgb_profile( $custom ) );
			$this->assertFalse( Color_Profiles::valid_icc( substr( $srgb, 0, -1 ) ) );
		}
		public function test_sampled_v2_srgb_with_legacy_d65_white_is_recognized(): void {
			$source = Color_Profiles::srgb_profile(); $tags = array();
			$count = unpack( 'N', substr( $source, 128, 4 ) )[1];
			for ( $i = 0; $i < $count; ++$i ) {
				$name = substr( $source, 132 + $i * 12, 4 ); $e = unpack( 'Noffset/Nsize', substr( $source, 136 + $i * 12, 8 ) );
				$tags[ $name ] = substr( $source, $e['offset'], $e['size'] );
			}
			unset( $tags['chad'] );
			$curve = 'curv' . str_repeat( "\0", 4 ) . pack( 'N', 1024 );
			for ( $i = 0; $i < 1024; ++$i ) { $x = $i / 1023; $curve .= pack( 'n', (int) round( 65535 * ( $x <= 0.04045 ? $x / 12.92 : pow( ( $x + 0.055 ) / 1.055, 2.4 ) ) ) ); }
			foreach ( array( 'rTRC', 'gTRC', 'bTRC' ) as $name ) { $tags[ $name ] = $curve; }
			$tags['wtpt'] = 'XYZ ' . str_repeat( "\0", 4 ) . pack( 'N3', (int) round( 0.95047 * 65536 ), 65536, (int) round( 1.08883 * 65536 ) );
			$header = substr( $source, 0, 128 ); $header[8] = chr( 2 ); $table = pack( 'N', count( $tags ) ); $payload = '';
			foreach ( $tags as $name => $value ) {
				$table .= $name . pack( 'NN', 132 + 12 * count( $tags ) + strlen( $payload ), strlen( $value ) );
				$payload .= $value . str_repeat( "\0", ( 4 - strlen( $value ) % 4 ) % 4 );
			}
			$profile = $header . $table . $payload; $profile = substr_replace( $profile, pack( 'N', strlen( $profile ) ), 0, 4 );
			$this->assertTrue( Color_Profiles::valid_icc( $profile ) );
			$this->assertTrue( Color_Profiles::is_srgb_profile( $profile ) );
		}
		public function test_png_jpeg_and_webp_profiles_are_read_without_pixel_decode(): void {
			$p = Color_Profiles::srgb_profile();
			$segment = "ICC_PROFILE\0\x01\x01" . $p;
			$jpeg = "\xff\xd8\xff\xe2" . pack( 'n', strlen( $segment ) + 2 ) . $segment . "\xff\xd9";
			$webp = 'ICCP' . pack( 'V', strlen( $p ) ) . $p . ( strlen( $p ) % 2 ? "\0" : '' );
			$webp = 'RIFF' . pack( 'V', strlen( $webp ) + 4 ) . 'WEBP' . $webp;
			foreach ( array( $this->png( $p ), $jpeg, $webp ) as $data ) {
				$info = Color_Profiles::inspect( $this->file( $data ) );
				$this->assertIsArray( $info ); $this->assertSame( hash( 'sha256', $p ), $info['hash'] );
			}
		}
		public function test_untagged_nonstandard_gamma_and_animation_fail_closed(): void {
			$this->assertInstanceOf( \WP_Error::class, Color_Profiles::inspect( $this->file( $this->png( '', $this->chunk( 'gAMA', pack( 'N', 55556 ) ) ) ) ) );
			$this->assertInstanceOf( \WP_Error::class, Color_Profiles::inspect( $this->file( $this->png( '', $this->chunk( 'acTL', pack( 'NN', 2, 0 ) ) ) ) ) );
			$this->assertIsArray( Color_Profiles::inspect( $this->file( $this->png( '', $this->chunk( 'gAMA', pack( 'N', 45455 ) ) ) ) ) );
		}
		public function test_bad_profile_context_blocks_editors_and_propagates_nested_failure(): void {
			$outer = Color_Profiles::push_context( $this->file( $this->png() ) );
			$this->assertNull( Color_Profiles::context_error() );
			$inner = Color_Profiles::push_context( $this->file( $this->png( 'invalid ICC' ) ) );
			$this->assertSame( array(), Color_Profiles::filter_editors( array( 'WP_Image_Editor_GD' ) ) );
			Color_Profiles::pop_context( $inner );
			$this->assertInstanceOf( \WP_Error::class, Color_Profiles::context_error() );
			Color_Profiles::pop_context( $outer );
			$this->assertNull( Color_Profiles::context_error() );
		}
		public function test_saved_profile_loss_is_rejected(): void {
			$this->assertTrue( Color_Profiles::validate_file( $this->file( $this->png( Color_Profiles::srgb_profile() ) ), Color_Profiles::srgb_profile() ) );
			$this->assertInstanceOf( \WP_Error::class, Color_Profiles::validate_file( $this->file( $this->png() ), Color_Profiles::srgb_profile() ) );
		}
		public function test_profiled_source_is_blocked_when_imagick_is_unavailable(): void {
			if ( class_exists( 'Imagick' ) ) { $this->markTestSkipped( 'This check exercises a GD-only runtime.' ); }
			$token = Color_Profiles::push_context( $this->file( $this->png( Color_Profiles::srgb_profile() ) ) );
			$this->assertInstanceOf( \WP_Error::class, Color_Profiles::context_error() );
			$this->assertSame( array(), Color_Profiles::filter_editors( array( 'WP_Image_Editor_GD' ) ) );
			Color_Profiles::pop_context( $token );
		}
	}
}
