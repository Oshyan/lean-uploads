<?php

namespace LeanUploads\Tests;

use LeanUploads\Capabilities;
use LeanUploads\Pipeline;
use LeanUploads\Settings;
use PHPUnit\Framework\TestCase;

final class QualityTest extends TestCase {
	private function pipeline( array $options = array(), ?array $context = array() ): Pipeline {
		$caps = new Capabilities();
		$settings = new Settings( $caps );
		$this->set_property( $settings, 'cache', Settings::sanitize( $options ) );
		$pipeline = ( new \ReflectionClass( Pipeline::class ) )->newInstanceWithoutConstructor();
		$this->set_property( $pipeline, 'settings', $settings );
		$this->set_property( $pipeline, 'caps', $caps );
		$this->set_property( $pipeline, 'current', null === $context ? null : $context + array( 'kept' => false, 'intended' => true, 'source_mime' => 'image/jpeg' ) );
		return $pipeline;
	}

	private function set_property( $object, string $name, $value ): void {
		$r = new \ReflectionProperty( $object, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$r->setAccessible( true );
		}
		$r->setValue( $object, $value );
	}

	protected function tearDown(): void {
		$this->pipeline( array(), null );
	}

	public function test_enforces_both_configured_formats_without_touching_jpeg_or_png(): void {
		$p = $this->pipeline( array( 'webp_quality' => 85, 'avif_quality' => 61 ) );
		$this->assertSame( 85, $p->enforce_quality( 100, 'image/webp' ) );
		$this->assertSame( 61, $p->enforce_quality( 100, 'image/avif' ) );
		$this->assertSame( 94, $p->enforce_quality( 94, 'image/jpeg' ) );
		$this->assertSame( 100, $p->enforce_quality( 100, 'image/png' ) );
	}

	public function test_opt_out_keeps_early_cooperative_quality_but_not_final_override(): void {
		$p = $this->pipeline( array( 'enforce_quality' => false, 'webp_quality' => 85 ) );
		$this->assertSame( 85, $p->quality( 82, 'image/webp' ) );
		$this->assertSame( 100, $p->enforce_quality( 100, 'image/webp' ) );
	}

	public function test_unrelated_kept_and_disabled_work_passes_through(): void {
		$this->assertSame( 100, $this->pipeline( array(), null )->enforce_quality( 100, 'image/webp' ) );
		$this->assertSame( 100, $this->pipeline( array(), array( 'kept' => true ) )->enforce_quality( 100, 'image/webp' ) );
		$this->assertSame( 100, $this->pipeline( array( 'enabled' => false, 'derivatives' => false ) )->quality( 100, 'image/webp' ) );
		$this->assertSame( 100, $this->pipeline( array( 'derivatives' => false ), array( 'intended' => false ) )->enforce_quality( 100, 'image/webp' ) );
	}

	public function test_derivative_only_mode_is_still_owned_processing(): void {
		$p = $this->pipeline( array( 'enabled' => false, 'derivatives' => true ), array( 'intended' => false ) );
		$this->assertSame( 75, $p->enforce_quality( 100, 'image/webp' ) );
	}
}
