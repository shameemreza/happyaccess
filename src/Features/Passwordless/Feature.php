<?php
/**
 * Passwordless login feature wiring.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\Passwordless;

use HappyAccess\Core\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the passwordless login hooks. Plugin::init() calls register() on
 * every load, and this is the only class of the feature loaded while it is
 * off: nothing else is autoloaded and no hook is added.
 */
final class Feature {

	const BLOCK = 'happyaccess/login';

	/**
	 * Registers the login steps, forms, REST routes and role policy while
	 * the feature is on. Every hook added here must be safe to add twice
	 * (same callback and priority), so register() needs no run-once flag.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! Features::is_enabled( 'passwordless' ) ) {
			return;
		}

		LoginSteps::register();
		RolePolicy::register();
		Forms::register();
		Shortcode::register();
		RestController::register();

		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	/**
	 * Registers the HappyAccess Login block. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register_block() {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}

		add_filter( 'block_type_metadata', array( __CLASS__, 'block_metadata' ) );
		register_block_type( HAPPYACCESS_PLUGIN_DIR . 'blocks/login' );
		remove_filter( 'block_type_metadata', array( __CLASS__, 'block_metadata' ) );
	}

	/**
	 * Drops the editor script of our block while its build is missing, as in
	 * a checkout that never ran npm run build. Core would otherwise report
	 * the missing asset file on every request.
	 *
	 * @param array $metadata The block.json data.
	 * @return array
	 */
	public static function block_metadata( $metadata ) {
		if ( ! is_array( $metadata ) || ! isset( $metadata['name'], $metadata['file'], $metadata['editorScript'] ) || self::BLOCK !== $metadata['name'] || ! is_string( $metadata['editorScript'] ) ) {
			return $metadata;
		}

		$script = substr( $metadata['editorScript'], 5 );
		$asset  = dirname( (string) $metadata['file'] ) . '/' . preg_replace( '/\.js$/', '.asset.php', $script );
		if ( 0 !== strpos( $metadata['editorScript'], 'file:' ) || ! is_readable( $asset ) ) {
			unset( $metadata['editorScript'] );
		}
		return $metadata;
	}

	/**
	 * Whether this request is the block editor asking for a preview. The
	 * editor loads it through the REST block renderer with context=edit,
	 * which only users who can edit posts may call.
	 *
	 * @return bool
	 */
	public static function is_editor_preview() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the REST route checks the capability.
		return isset( $_GET['context'] ) && 'edit' === $_GET['context'] && current_user_can( 'edit_posts' );
	}

	/**
	 * Makes the form readable in the editor, where no script runs: shows
	 * the toggle so its style is visible, and drops the no-script link.
	 *
	 * @param string $html The form markup.
	 * @return string
	 */
	public static function preview_markup( $html ) {
		$html = preg_replace( '/(<p class="happyaccess-pl__toggle-row")\s+hidden>/', '$1>', $html );
		$html = preg_replace( '/<p class="happyaccess-pl__fallback">.*?<\/p>/s', '', (string) $html );
		return (string) $html;
	}
}
