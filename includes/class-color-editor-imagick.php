<?php
/** A scoped core editor with color preparation and saved-file verification. */
namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

class Color_Editor_Imagick extends \WP_Image_Editor_Imagick {
	private $required_profile = '';

	public function load() {
		$result = parent::load();
		if ( is_wp_error( $result ) ) { return Color_Profiles::fail( $result ); }
		$profile = Color_Profiles::prepare_image( $this->image );
		if ( is_wp_error( $profile ) ) { return $profile; }
		$this->required_profile = $profile;
		return $result;
	}

	protected function _save( $image, $filename = null, $mime_type = null ) {
		if ( Color_Profiles::context_error() ) { return Color_Profiles::context_error(); }
		$stage = ''; $written = '';
		try {
			Color_Profiles::prepare_save( $image, $this->required_profile );
			if ( $image !== $this->image ) { Color_Profiles::prepare_save( $this->image, $this->required_profile ); }
			list( $target, $extension, $output_mime ) = $this->get_output_format( $filename, $mime_type );
			if ( ! $target ) { $target = $this->generate_filename( null, null, $extension ); }
			$stage = tempnam( dirname( $target ), '.lean-color-' );
			if ( ! $stage || realpath( dirname( $stage ) ) !== realpath( dirname( $target ) ) ) {
				return Color_Profiles::fail( new \WP_Error( 'lean_uploads_color_stage', 'Could not stage color-safe output beside its destination.' ) );
			}
			$written = $stage . '.' . $extension;
			$result = parent::_save( $image, $written, $output_mime );
			if ( is_wp_error( $result ) ) { return Color_Profiles::fail( $result ); }
			$checked = Color_Profiles::validate_file( $result['path'], $this->required_profile );
			if ( is_wp_error( $checked ) ) { return $checked; }
			if ( $result['path'] !== $written || ! rename( $written, $target ) ) {
				return Color_Profiles::fail( new \WP_Error( 'lean_uploads_color_publish', 'Could not publish the verified image; the previous file was retained.' ) );
			}
			$result['path'] = $target;
			$result['file'] = wp_basename( apply_filters( 'image_make_intermediate_size', $target ) );
			return $result;
		} catch ( \Throwable $e ) {
			return Color_Profiles::fail( new \WP_Error( 'lean_uploads_color_save', $e->getMessage() ) );
		} finally {
			foreach ( array( $stage, $written ) as $temp ) { if ( $temp && is_file( $temp ) ) { wp_delete_file( $temp ); } }
		}
	}
}
