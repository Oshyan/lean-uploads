<?php
/**
 * Plugin Name:       Lean Uploads
 * Plugin URI:        https://github.com/oshyan/lean-uploads
 * Description:       Converts uploaded images to WebP (or AVIF), caps their dimensions, and generates every thumbnail in the same efficient format. The original upload is replaced, not kept beside it.
 * Version:           0.1.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Oshyan Greene
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lean-uploads
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const OPTION      = 'lean_uploads';
const PLUGIN_FILE = __FILE__;

/**
 * Minimal PSR-4-ish autoloader for the plugin's own classes (no Composer required at runtime).
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$file     = __DIR__ . '/includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/**
 * Plugin bootstrap. One instance wires the hooks; everything else is composed from small classes.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	public $settings;

	/** @var Capabilities */
	public $capabilities;

	/** @var Converter */
	public $converter;

	/** @var Upload_Handler */
	public $uploads;

	/** @var Derivatives */
	public $derivatives;

	/** @var Notices */
	public $notices;

	/** @var Library */
	public $library;

	/** @var Admin_Media */
	public $admin_media;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->capabilities = new Capabilities();
		$this->settings     = new Settings( $this->capabilities );
		$this->notices      = new Notices();
		$this->converter    = new Converter( $this->capabilities );
		$this->uploads      = new Upload_Handler( $this->settings, $this->capabilities, $this->converter, $this->notices );
		$this->derivatives  = new Derivatives( $this->settings, $this->capabilities );
		$this->library      = new Library( $this->settings, $this->converter, $this->uploads );
		$this->admin_media  = new Admin_Media( $this->library );
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->settings->register_hooks();
		$this->notices->register_hooks();
		$this->uploads->register_hooks();
		$this->derivatives->register_hooks();
		if ( is_admin() ) {
			$this->admin_media->register_hooks();
		}

		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'lean-uploads', new CLI( $this ) );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'lean-uploads', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'options-media.php#lean-uploads' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'lean-uploads' ) . '</a>' );
		return $links;
	}
}

/**
 * Activation: refuse politely on hosts that cannot write any modern format at all.
 */
function activate(): void {
	$caps = new Capabilities();
	if ( ! $caps->supports( 'image/webp' ) && ! $caps->supports( 'image/avif' ) ) {
		deactivate_plugins( plugin_basename( PLUGIN_FILE ) );
		wp_die(
			esc_html__( 'Lean Uploads needs a PHP image library (Imagick or GD) that can write WebP or AVIF. Neither is available on this server, so the plugin was not activated.', 'lean-uploads' ),
			esc_html__( 'Lean Uploads', 'lean-uploads' ),
			array( 'back_link' => true )
		);
	}
	// Seed defaults so the Media settings screen shows real values immediately.
	if ( false === get_option( OPTION ) ) {
		add_option( OPTION, Settings::defaults() );
	}
}
register_activation_hook( PLUGIN_FILE, __NAMESPACE__ . '\\activate' );

Plugin::instance()->boot();
