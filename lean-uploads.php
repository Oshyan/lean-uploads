<?php
/**
 * Plugin Name:       Lean Uploads
 * Plugin URI:        https://github.com/Oshyan/lean-uploads
 * Description:       Converts uploaded images to WebP (or AVIF), caps their dimensions, and generates every thumbnail in the same efficient format. The original upload is replaced, not kept beside it.
 * Version:           0.3.1
 * Requires at least: 6.5
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

const VERSION     = '0.3.1';
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

	/** @var Pipeline */
	public $pipeline;

	/** @var Notices */
	public $notices;

	/** @var Rewriter */
	public $rewriter;

	/** @var Library */
	public $library;

	/** @var Bulk */
	public $bulk;

	/** @var Admin_Media */
	public $admin_media;

	/** @var Admin_Tools */
	public $admin_tools;

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
		$this->uploads      = new Upload_Handler( $this->settings );
		$this->pipeline     = new Pipeline( $this->settings, $this->capabilities, $this->converter, $this->notices );
		$this->rewriter     = new Rewriter();
		$this->library      = new Library( $this->settings, $this->pipeline, $this->rewriter );
		$this->bulk         = new Bulk( $this->library );
		$this->admin_media  = new Admin_Media( $this->library );
		$this->admin_tools  = new Admin_Tools( $this->bulk );
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->settings->register_hooks();
		$this->notices->register_hooks();
		$this->uploads->register_hooks();
		$this->pipeline->register_hooks();
		if ( is_admin() ) {
			$this->admin_media->register_hooks();
			$this->admin_tools->register_hooks();
			$this->bulk->register_hooks();
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
