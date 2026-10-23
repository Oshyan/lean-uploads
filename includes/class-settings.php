<?php
/**
 * Options storage, sanitization, and the Settings > Media section.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/** @var Capabilities */
	private $caps;

	/** @var array|null in-request cache */
	private $cache = null;

	public function __construct( Capabilities $caps ) {
		$this->caps = $caps;
	}

	/**
	 * Strong defaults: convert everything to WebP at quality 75, cap at WordPress's own 2560px threshold,
	 * generate thumbnails in the same format. Lossless PNG is off because for web display it rarely earns its bytes.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'       => true,
			'format'        => 'webp',
			'quality'       => 75,
			'max_dimension' => 2560,
			'convert_jpeg'  => true,
			'convert_png'   => true,
			'png_lossless'  => false,
			'convert_gif'   => false,
			'convert_other' => true,
			'derivatives'   => true,
			'keep_token'    => '--keep',
		);
	}

	/**
	 * Current settings, merged over defaults and filterable.
	 *
	 * @return array<string,mixed>
	 */
	public function get(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( OPTION, array() );
			$merged      = self::sanitize( is_array( $stored ) ? $stored : array() );
			$this->cache = (array) apply_filters( 'lean_uploads_settings', $merged );
		}
		return $this->cache;
	}

	/**
	 * Clamp and coerce. Safe to call with partial or hostile input; unknown keys are dropped.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$d = self::defaults();
		$bool = static function ( $key ) use ( $input, $d ) {
			return array_key_exists( $key, $input ) ? (bool) filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN ) : $d[ $key ];
		};
		$out = array(
			'enabled'       => $bool( 'enabled' ),
			'format'        => ( isset( $input['format'] ) && 'avif' === $input['format'] ) ? 'avif' : 'webp',
			'quality'       => isset( $input['quality'] ) ? min( 100, max( 1, (int) $input['quality'] ) ) : $d['quality'],
			'max_dimension' => isset( $input['max_dimension'] ) ? min( 20000, max( 0, (int) $input['max_dimension'] ) ) : $d['max_dimension'],
			'convert_jpeg'  => $bool( 'convert_jpeg' ),
			'convert_png'   => $bool( 'convert_png' ),
			'png_lossless'  => $bool( 'png_lossless' ),
			'convert_gif'   => $bool( 'convert_gif' ),
			'convert_other' => $bool( 'convert_other' ),
			'derivatives'   => $bool( 'derivatives' ),
			'keep_token'    => isset( $input['keep_token'] ) ? self::sanitize_token( (string) $input['keep_token'] ) : $d['keep_token'],
		);
		return $out;
	}

	/** The token must be safe inside a file name and contain no path separators or dots. */
	public static function sanitize_token( string $token ): string {
		$token = preg_replace( '#[^A-Za-z0-9_\-]#', '', $token );
		return is_string( $token ) ? substr( $token, 0, 20 ) : '';
	}

	/* ---------------------------------------------------------------- admin UI */

	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	public function register(): void {
		register_setting(
			'media',
			OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_form' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section( 'lean_uploads', __( 'Lean Uploads', 'lean-uploads' ), array( $this, 'section_intro' ), 'media' );

		$fields = array(
			'enabled'       => __( 'Convert uploads', 'lean-uploads' ),
			'format'        => __( 'Output format', 'lean-uploads' ),
			'quality'       => __( 'Quality', 'lean-uploads' ),
			'max_dimension' => __( 'Maximum dimension', 'lean-uploads' ),
			'types'         => __( 'Source formats', 'lean-uploads' ),
			'derivatives'   => __( 'Thumbnails', 'lean-uploads' ),
			'keep_token'    => __( 'Opt-out token', 'lean-uploads' ),
		);
		foreach ( $fields as $key => $label ) {
			add_settings_field( 'lean_uploads_' . $key, $label, array( $this, 'field_' . $key ), 'media', 'lean_uploads', array( 'label_for' => 'lean_uploads_' . $key ) );
		}
	}

	/**
	 * Checkbox forms omit unchecked keys, so a saved form must treat a missing checkbox as false, not default.
	 *
	 * @param mixed $input Raw form input.
	 * @return array<string,mixed>
	 */
	public static function sanitize_form( $input ): array {
		$input = is_array( $input ) ? $input : array();
		foreach ( array( 'enabled', 'convert_jpeg', 'convert_png', 'png_lossless', 'convert_gif', 'convert_other', 'derivatives' ) as $k ) {
			if ( ! array_key_exists( $k, $input ) ) {
				$input[ $k ] = false;
			}
		}
		return self::sanitize( $input );
	}

	public function section_intro(): void {
		$s = $this->caps->summary();
		echo '<p id="lean-uploads">' . esc_html__( 'Uploaded images are converted to an efficient format and resized to the cap below. The original file is replaced; keep your source files outside WordPress.', 'lean-uploads' ) . '</p>';
		$webp = $s['webp'] ? __( 'yes', 'lean-uploads' ) : __( 'no', 'lean-uploads' );
		$avif = $s['avif'] ? __( 'yes', 'lean-uploads' ) : __( 'no', 'lean-uploads' );
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: yes/no, 2: editor class, 3: yes/no, 4: yes/no */
					__( 'This server can write WebP: %1$s (%2$s). AVIF: %3$s. Lossless WebP: %4$s.', 'lean-uploads' ),
					$webp,
					$s['webp_editor'] ? $s['webp_editor'] : '-',
					$avif,
					$s['lossless_webp'] ? __( 'yes', 'lean-uploads' ) : __( 'no', 'lean-uploads' )
				)
			)
		);
	}

	private function checkbox( string $key, string $label, string $help = '' ): void {
		$v = $this->get();
		printf(
			'<label><input type="checkbox" id="lean_uploads_%1$s" name="%2$s[%1$s]" value="1" %3$s> %4$s</label>',
			esc_attr( $key ),
			esc_attr( OPTION ),
			checked( ! empty( $v[ $key ] ), true, false ),
			esc_html( $label )
		);
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	public function field_enabled(): void {
		$this->checkbox( 'enabled', __( 'Convert and resize images when they are uploaded', 'lean-uploads' ), __( 'Applies to the Media Library, the block editor, the REST API, and WP-CLI imports.', 'lean-uploads' ) );
	}

	public function field_format(): void {
		$v = $this->get();
		$avif_ok = $this->caps->supports( 'image/avif' );
		printf( '<select id="lean_uploads_format" name="%s[format]">', esc_attr( OPTION ) );
		printf( '<option value="webp" %s>WebP</option>', selected( $v['format'], 'webp', false ) );
		printf( '<option value="avif" %s %s>AVIF%s</option>', selected( $v['format'], 'avif', false ), disabled( ! $avif_ok, true, false ), $avif_ok ? '' : esc_html__( ' (not available on this server)', 'lean-uploads' ) );
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'WebP is supported by every current browser and every host. AVIF is smaller but slower to encode and needs a newer image library. If AVIF is chosen but unavailable, WebP is used.', 'lean-uploads' ) . '</p>';
	}

	public function field_quality(): void {
		$v = $this->get();
		printf( '<input type="number" id="lean_uploads_quality" name="%s[quality]" value="%d" min="1" max="100" step="1" class="small-text">', esc_attr( OPTION ), (int) $v['quality'] );
		echo '<p class="description">' . esc_html__( 'Lossy quality for converted originals and thumbnails. 75 is visually transparent for photos at a fraction of the size; 85 if you are picky, 60 if you are not.', 'lean-uploads' ) . '</p>';
	}

	public function field_max_dimension(): void {
		$v = $this->get();
		printf( '<input type="number" id="lean_uploads_max_dimension" name="%s[max_dimension]" value="%d" min="0" max="20000" step="1" class="small-text"> px', esc_attr( OPTION ), (int) $v['max_dimension'] );
		echo '<p class="description">' . esc_html__( 'Images larger than this on either side are scaled down to fit, before conversion. 0 disables. 2560 matches the threshold WordPress itself uses to create "-scaled" copies, so with the default nothing larger than that is ever stored.', 'lean-uploads' ) . '</p>';
	}

	public function field_types(): void {
		$this->checkbox( 'convert_jpeg', __( 'JPEG', 'lean-uploads' ) );
		echo '<br>';
		$this->checkbox( 'convert_png', __( 'PNG', 'lean-uploads' ) );
		echo '<br>';
		$this->checkbox( 'png_lossless', __( 'Keep PNG conversions lossless (bigger files; needs Imagick)', 'lean-uploads' ) );
		echo '<br>';
		$this->checkbox( 'convert_gif', __( 'GIF (static only; animated GIFs are always left alone)', 'lean-uploads' ) );
		echo '<br>';
		$this->checkbox( 'convert_other', __( 'BMP, TIFF and HEIC (HEIC needs a decoder on the server)', 'lean-uploads' ) );
		echo '<p class="description">' . esc_html__( 'SVG, WebP and AVIF uploads are never re-encoded. WebP and AVIF are still resized to the cap.', 'lean-uploads' ) . '</p>';
	}

	public function field_derivatives(): void {
		$this->checkbox( 'derivatives', __( 'Generate all thumbnail sizes in the output format', 'lean-uploads' ), __( 'Applies the same format and quality to every registered image size, including for images that were not converted (opted out, or uploaded before this plugin).', 'lean-uploads' ) );
	}

	public function field_keep_token(): void {
		$v = $this->get();
		printf( '<input type="text" id="lean_uploads_keep_token" name="%s[keep_token]" value="%s" class="regular-text" placeholder="--keep">', esc_attr( OPTION ), esc_attr( $v['keep_token'] ) );
		echo '<p class="description">' . esc_html__( 'A file whose name contains this token is stored exactly as uploaded; the token is removed from the saved name. For example "logo--keep.png" is saved as logo.png. Leave empty to disable the escape hatch.', 'lean-uploads' ) . '</p>';
	}
}
