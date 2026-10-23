<?php

namespace LeanUploads\Tests;

use LeanUploads\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	public function test_defaults_are_forceful(): void {
		$d = Settings::defaults();
		$this->assertTrue( $d['enabled'] );
		$this->assertSame( 'webp', $d['format'] );
		$this->assertSame( 75, $d['webp_quality'] );
		$this->assertSame( 60, $d['avif_quality'] );
		$this->assertSame( 2560, $d['max_dimension'] );
		$this->assertTrue( $d['convert_png'] );
		$this->assertTrue( $d['convert_jpeg'] );
		$this->assertFalse( $d['convert_gif'] );
		$this->assertTrue( $d['derivatives'] );
		$this->assertSame( '--keep', $d['keep_token'] );
	}

	public function test_sanitize_clamps_numbers(): void {
		$s = Settings::sanitize( array( 'webp_quality' => 150, 'avif_quality' => -3, 'max_dimension' => -5 ) );
		$this->assertSame( 100, $s['webp_quality'] );
		$this->assertSame( 1, $s['avif_quality'] );
		$this->assertSame( 0, $s['max_dimension'] );
		$s = Settings::sanitize( array( 'webp_quality' => 0, 'max_dimension' => 99999 ) );
		$this->assertSame( 1, $s['webp_quality'] );
		$this->assertSame( 20000, $s['max_dimension'] );
		$s = Settings::sanitize( array( 'webp_quality' => '80', 'max_dimension' => '2000' ) );
		$this->assertSame( 80, $s['webp_quality'] );
		$this->assertSame( 2000, $s['max_dimension'] );
		// Installs from before per-format quality: the single "quality" applied to WebP.
		$s = Settings::sanitize( array( 'quality' => 66 ) );
		$this->assertSame( 66, $s['webp_quality'] );
		$this->assertSame( 60, $s['avif_quality'] );
	}

	public function test_sanitize_format_and_booleans(): void {
		$s = Settings::sanitize( array( 'format' => 'gif', 'enabled' => '0', 'convert_png' => 'on', 'derivatives' => 'false' ) );
		$this->assertSame( 'webp', $s['format'] );
		$this->assertFalse( $s['enabled'] );
		$this->assertTrue( $s['convert_png'] );
		$this->assertFalse( $s['derivatives'] );
		$this->assertSame( 'avif', Settings::sanitize( array( 'format' => 'avif' ) )['format'] );
	}

	public function test_sanitize_drops_unknown_keys_and_fills_defaults(): void {
		$s = Settings::sanitize( array( 'evil' => 'x' ) );
		$this->assertArrayNotHasKey( 'evil', $s );
		$this->assertSame( Settings::defaults(), $s );
	}

	public function test_token_is_filename_safe(): void {
		$this->assertSame( 'badtoken', Settings::sanitize_token( 'bad/tok.en' ) );
		$this->assertSame( '--keep', Settings::sanitize_token( '--keep' ) );
		$this->assertSame( '', Settings::sanitize_token( '../' ) );
		$this->assertSame( 20, strlen( Settings::sanitize_token( str_repeat( 'a', 50 ) ) ) );
	}

	public function test_form_submission_treats_missing_checkbox_as_off(): void {
		$s = Settings::sanitize_form( array( 'webp_quality' => 70 ) );
		$this->assertFalse( $s['enabled'] );
		$this->assertFalse( $s['convert_png'] );
		$this->assertFalse( $s['derivatives'] );
		$this->assertSame( 70, $s['webp_quality'] );

		$s = Settings::sanitize_form( 'not-an-array' );
		foreach ( array( 'enabled', 'convert_jpeg', 'convert_png', 'png_lossless', 'convert_gif', 'convert_other', 'derivatives' ) as $k ) {
			$this->assertFalse( $s[ $k ], "$k should be off when the form omits it" );
		}
		$this->assertSame( 'webp', $s['format'] );
	}
}
