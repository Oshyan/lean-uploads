<?php

namespace LeanUploads\Tests;

use LeanUploads\Rewriter;
use PHPUnit\Framework\TestCase;

final class RewriterTest extends TestCase {

	private const OLD = 'https://example.test/wp-content/uploads/2026/10/photo.png';
	private const NEW = 'https://example.test/wp-content/uploads/2026/10/photo.webp';

	private function pairs(): array {
		return array(
			self::OLD => self::NEW,
			'https://example.test/wp-content/uploads/2026/10/photo-300x200.png' => 'https://example.test/wp-content/uploads/2026/10/photo-300x200.webp',
		);
	}

	public function test_plain_strings(): void {
		$in = '<img src="' . self::OLD . '"> and <img src="https://example.test/wp-content/uploads/2026/10/photo-300x200.png">';
		$out = Rewriter::replace_in_value( $in, $this->pairs() );
		$this->assertStringContainsString( self::NEW, $out );
		$this->assertStringContainsString( 'photo-300x200.webp', $out );
		$this->assertStringNotContainsString( '.png', $out );
		$this->assertSame( 'untouched', Rewriter::replace_in_value( 'untouched', $this->pairs() ) );
		$this->assertSame( '', Rewriter::replace_in_value( '', $this->pairs() ) );
	}

	public function test_longest_match_wins_so_a_size_url_is_not_mangled_by_its_prefix(): void {
		// The full-size URL is a prefix-sibling of the size URL ("photo.png" vs "photo-300x200.png"), not a true
		// prefix, but a naive pair order could still bite; ensure both map to their own replacement.
		$in  = 'https://example.test/wp-content/uploads/2026/10/photo-300x200.png';
		$this->assertSame( 'https://example.test/wp-content/uploads/2026/10/photo-300x200.webp', Rewriter::replace_in_value( $in, $this->pairs() ) );
	}

	public function test_serialized_array_lengths_are_fixed(): void {
		$data = array( 'url' => self::OLD, 'nested' => array( 'thumb' => 'https://example.test/wp-content/uploads/2026/10/photo-300x200.png', 'n' => 3, 'f' => 1.5, 'b' => true, 'z' => null ) );
		$out  = Rewriter::replace_in_value( serialize( $data ), $this->pairs() );
		$back = unserialize( $out, array( 'allowed_classes' => false ) );
		$this->assertIsArray( $back );
		$this->assertSame( self::NEW, $back['url'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/2026/10/photo-300x200.webp', $back['nested']['thumb'] );
		$this->assertSame( 3, $back['nested']['n'] );
		$this->assertSame( 1.5, $back['nested']['f'] );
		$this->assertTrue( $back['nested']['b'] );
		$this->assertNull( $back['nested']['z'] );
	}

	public function test_objects_pass_through_without_being_instantiated(): void {
		$serialized = 'O:10:"Some\\Thing":2:{s:3:"url";s:' . strlen( self::OLD ) . ':"' . self::OLD . '";s:4:"keep";i:7;}';
		$out        = Rewriter::replace_in_value( $serialized, $this->pairs() );
		$this->assertStringStartsWith( 'O:10:"Some\\Thing":2:{', $out );
		$this->assertStringContainsString( 's:' . strlen( self::NEW ) . ':"' . self::NEW . '";', $out );
		$this->assertStringContainsString( 's:4:"keep";i:7;', $out );
		$this->assertFalse( class_exists( 'Some\\Thing', false ) );
	}

	public function test_custom_serialized_and_references_are_copied_verbatim(): void {
		$c = 'C:8:"MyObject":12:{raw;payload}';
		$this->assertSame( $c, Rewriter::replace_in_value( $c, $this->pairs() ) );
		$a = 'a:2:{i:0;s:1:"x";i:1;R:2;}';
		$this->assertSame( $a, Rewriter::replace_in_value( $a, $this->pairs() ) );
	}

	public function test_nested_serialized_string_inside_a_string_is_rewritten(): void {
		$inner = serialize( array( 'u' => self::OLD ) );
		$outer = serialize( array( 'blob' => $inner ) );
		$out   = Rewriter::replace_in_value( $outer, $this->pairs() );
		$back  = unserialize( $out, array( 'allowed_classes' => false ) );
		$inner_back = unserialize( $back['blob'], array( 'allowed_classes' => false ) );
		$this->assertSame( self::NEW, $inner_back['u'] );
	}

	public function test_malformed_serialized_data_is_left_alone(): void {
		// Looks serialized (prefix and terminator) but the declared length is wrong: parsing fails, value untouched.
		$bad = 'a:2:{s:3:"url";s:5:"' . self::OLD . '";}';
		$this->assertSame( $bad, Rewriter::replace_in_value( $bad, $this->pairs() ) );
		$wrong_class_len = 'O:9:"Some\\Thing":1:{s:3:"url";s:' . strlen( self::OLD ) . ':"' . self::OLD . '";}';
		$this->assertSame( $wrong_class_len, Rewriter::replace_in_value( $wrong_class_len, $this->pairs() ) );
	}

	public function test_text_that_is_not_serialized_gets_plain_replacement(): void {
		// No serialized terminator: treated as text, same as WordPress's own is_serialized() would.
		$trunc = 'a:1:{s:3:"url";s:' . strlen( self::OLD ) . ':"' . self::OLD;
		$this->assertSame( str_replace( self::OLD, self::NEW, $trunc ), Rewriter::replace_in_value( $trunc, $this->pairs() ) );
		$text = 's:3:"abc";' . self::OLD;
		$this->assertSame( 's:3:"abc";' . self::NEW, Rewriter::replace_in_value( $text, $this->pairs() ) );
		$json = '{"url":"' . self::OLD . '"}';
		$this->assertSame( '{"url":"' . self::NEW . '"}', Rewriter::replace_in_value( $json, $this->pairs() ) );
	}

	public function test_multibyte_strings_keep_byte_lengths_correct(): void {
		$data = array( 'caption' => 'Ünïcødé ' . self::OLD . ' ✓' );
		$out  = Rewriter::replace_in_value( serialize( $data ), $this->pairs() );
		$back = unserialize( $out, array( 'allowed_classes' => false ) );
		$this->assertSame( 'Ünïcødé ' . self::NEW . ' ✓', $back['caption'] );
	}

	public function test_common_prefix(): void {
		$this->assertSame( 'https://example.test/wp-content/uploads/2026/10/photo', Rewriter::common_prefix( array_keys( $this->pairs() ) ) );
		$this->assertSame( '', Rewriter::common_prefix( array( 'abc', 'xyz' ) ) );
		$this->assertSame( 'only', Rewriter::common_prefix( array( 'only' ) ) );
	}

	public function test_looks_serialized(): void {
		$this->assertTrue( Rewriter::looks_serialized( serialize( array( 1 ) ) ) );
		$this->assertTrue( Rewriter::looks_serialized( 'N;' ) );
		$this->assertTrue( Rewriter::looks_serialized( 'i:5;' ) );
		$this->assertFalse( Rewriter::looks_serialized( 'https://example.test/a.png' ) );
		$this->assertFalse( Rewriter::looks_serialized( '' ) );
		$this->assertFalse( Rewriter::looks_serialized( 'a:1:{' ) );
	}
}
