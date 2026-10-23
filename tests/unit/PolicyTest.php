<?php

namespace LeanUploads\Tests;

use LeanUploads\Policy;
use LeanUploads\Settings;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase {

	private function support( bool $webp = true, bool $avif = false, bool $lossless = true ): array {
		return array( 'image/webp' => $webp, 'image/avif' => $avif, 'lossless' => $lossless );
	}

	public function test_png_converts_to_webp_by_default(): void {
		$d = Policy::decide( 'image/png', 'photo.png', Settings::defaults(), $this->support() );
		$this->assertTrue( $d['convert'] );
		$this->assertSame( 'image/webp', $d['target_mime'] );
		$this->assertSame( 'webp', $d['ext'] );
		$this->assertFalse( $d['lossless'] );
		$this->assertFalse( $d['resize_only'] );
	}

	public function test_jpeg_converts_and_bmp_counts_as_other(): void {
		$s = Settings::defaults();
		$this->assertTrue( Policy::decide( 'image/jpeg', 'a.jpg', $s, $this->support() )['convert'] );
		$this->assertTrue( Policy::decide( 'image/bmp', 'a.bmp', $s, $this->support() )['convert'] );
		$s['convert_other'] = false;
		$this->assertSame( 'type_disabled', Policy::decide( 'image/bmp', 'a.bmp', $s, $this->support() )['reason'] );
	}

	public function test_keep_token_skips_and_strips_name(): void {
		$d = Policy::decide( 'image/png', 'logo--keep.png', Settings::defaults(), $this->support() );
		$this->assertFalse( $d['convert'] );
		$this->assertSame( 'keep_token', $d['reason'] );
		$this->assertSame( 'logo.png', $d['keep_name'] );
	}

	public function test_empty_token_disables_escape_hatch(): void {
		$s = Settings::defaults();
		$s['keep_token'] = '';
		$d = Policy::decide( 'image/png', 'logo--keep.png', $s, $this->support() );
		$this->assertTrue( $d['convert'] );
		$this->assertSame( 'logo--keep.png', $d['keep_name'] );
	}

	public function test_disabled_setting(): void {
		$s = Settings::defaults();
		$s['enabled'] = false;
		$this->assertSame( 'disabled', Policy::decide( 'image/png', 'a.png', $s, $this->support() )['reason'] );
	}

	public function test_avif_falls_back_to_webp_when_unsupported(): void {
		$s = Settings::defaults();
		$s['format'] = 'avif';
		$this->assertSame( 'image/webp', Policy::target_mime( $s, $this->support( true, false ) ) );
		$this->assertSame( 'image/avif', Policy::target_mime( $s, $this->support( true, true ) ) );
		$this->assertSame( '', Policy::target_mime( $s, $this->support( false, false ) ) );
	}

	public function test_no_encoder_means_no_conversion(): void {
		$d = Policy::decide( 'image/png', 'a.png', Settings::defaults(), $this->support( false, false ) );
		$this->assertFalse( $d['convert'] );
		$this->assertSame( 'no_encoder', $d['reason'] );
	}

	public function test_modern_formats_are_resize_only_when_capped(): void {
		$s = Settings::defaults();
		$d = Policy::decide( 'image/webp', 'a.webp', $s, $this->support() );
		$this->assertFalse( $d['convert'] );
		$this->assertTrue( $d['resize_only'] );
		$this->assertSame( 'image/webp', $d['target_mime'] );
		$s['max_dimension'] = 0;
		$this->assertFalse( Policy::decide( 'image/webp', 'a.webp', $s, $this->support() )['resize_only'] );
	}

	public function test_gif_rules(): void {
		$s = Settings::defaults();
		$this->assertSame( 'type_disabled', Policy::decide( 'image/gif', 'a.gif', $s, $this->support(), false )['reason'] );
		$s['convert_gif'] = true;
		$this->assertTrue( Policy::decide( 'image/gif', 'a.gif', $s, $this->support(), false )['convert'] );
		$this->assertSame( 'animated_gif', Policy::decide( 'image/gif', 'a.gif', $s, $this->support(), true )['reason'] );
	}

	public function test_heic_needs_a_decoder(): void {
		$s = Settings::defaults();
		$this->assertSame( 'no_decoder', Policy::decide( 'image/heic', 'a.heic', $s, $this->support() )['reason'] );
		$with = $this->support() + array( 'image/heic' => true );
		$this->assertTrue( Policy::decide( 'image/heic', 'a.heic', $s, $with )['convert'] );
		$s['convert_other'] = false;
		$this->assertSame( 'type_disabled', Policy::decide( 'image/heic', 'a.heic', $s, $with )['reason'] );
	}

	public function test_non_images_are_ignored(): void {
		$this->assertSame( 'not_an_image', Policy::decide( 'image/svg+xml', 'a.svg', Settings::defaults(), $this->support() )['reason'] );
		$this->assertSame( 'not_an_image', Policy::decide( 'application/pdf', 'a.pdf', Settings::defaults(), $this->support() )['reason'] );
	}

	public function test_lossless_only_for_png_webp_with_imagick(): void {
		$s = Settings::defaults();
		$s['png_lossless'] = true;
		$this->assertTrue( Policy::decide( 'image/png', 'a.png', $s, $this->support( true, false, true ) )['lossless'] );
		$this->assertFalse( Policy::decide( 'image/png', 'a.png', $s, $this->support( true, false, false ) )['lossless'] );
		$this->assertFalse( Policy::decide( 'image/jpeg', 'a.jpg', $s, $this->support( true, false, true ) )['lossless'] );
		$s['format'] = 'avif';
		$this->assertFalse( Policy::decide( 'image/png', 'a.png', $s, $this->support( true, true, true ) )['lossless'] );
	}

	public function test_rename_replaces_extension_case_insensitively(): void {
		$this->assertSame( 'Photo.webp', Policy::rename( 'Photo.PNG', 'webp' ) );
		$this->assertSame( 'a.b.c.webp', Policy::rename( 'a.b.c.jpg', 'webp' ) );
		$this->assertSame( 'noext.avif', Policy::rename( 'noext', 'AVIF' ) );
	}

	public function test_strip_token_never_returns_empty_name(): void {
		$this->assertSame( 'logo.png', Policy::strip_token( 'logo--keep.png', '--keep' ) );
		$this->assertSame( '--keep', Policy::strip_token( '--keep', '--keep' ) );
	}

	public function test_fit_preserves_aspect_and_only_shrinks(): void {
		$this->assertSame( array( 2560, 1920 ), Policy::fit( 4000, 3000, 2560 ) );
		$this->assertSame( array( 1920, 2560 ), Policy::fit( 3000, 4000, 2560 ) );
		$this->assertNull( Policy::fit( 100, 100, 2560 ) );
		$this->assertNull( Policy::fit( 2560, 2560, 2560 ) );
		$this->assertNull( Policy::fit( 9000, 9000, 0 ) );
		$this->assertSame( array( 1, 100 ), Policy::fit( 1, 20000, 100 ) );
	}

	public function test_path_belongs_to_matches_file_and_its_derivatives_only(): void {
		$kept = '/srv/uploads/2026/10/logo.png';
		$this->assertTrue( Policy::path_belongs_to( '/srv/uploads/2026/10/logo.png', $kept ) );
		$this->assertTrue( Policy::path_belongs_to( '/srv/uploads/2026/10/logo-300x200.png', $kept ) );
		$this->assertTrue( Policy::path_belongs_to( '/srv/uploads/2026/10/logo.webp', $kept ) );
		$this->assertTrue( Policy::path_belongs_to( '\\srv\\uploads\\2026\\10\\logo-scaled.png', $kept ) );
		$this->assertFalse( Policy::path_belongs_to( '/srv/uploads/2026/10/logotype.png', $kept ) );
		$this->assertFalse( Policy::path_belongs_to( '/srv/uploads/2026/11/logo.png', $kept ) );
		$this->assertFalse( Policy::path_belongs_to( '', $kept ) );
	}

	public function test_animated_gif_detection(): void {
		$dir = sys_get_temp_dir();
		$static = $dir . '/lean-static.gif';
		if ( function_exists( 'imagegif' ) ) {
			$im = imagecreatetruecolor( 10, 10 );
			imagegif( $im, $static );
			unset( $im );
			$this->assertFalse( Policy::is_animated_gif( $static ) );
			unlink( $static );
		}
		// Two Graphic Control Extension blocks each followed by an image descriptor: that is an animation.
		$frame    = "\x00\x21\xF9\x04\x04\x0A\x00\x00\x00\x2C";
		$animated = $dir . '/lean-animated.gif';
		file_put_contents( $animated, "GIF89a" . str_repeat( 'x', 20 ) . $frame . str_repeat( 'y', 30 ) . $frame );
		$this->assertTrue( Policy::is_animated_gif( $animated ) );
		unlink( $animated );
		$this->assertFalse( Policy::is_animated_gif( $dir . '/does-not-exist.gif' ) );
	}
}
