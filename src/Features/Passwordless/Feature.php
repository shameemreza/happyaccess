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
	 * For each REST request being served, inner ones last, whether it is
	 * the block renderer asking for this block with context=edit.
	 *
	 * @var bool[]
	 */
	private static $rest_previews = array();

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
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'note_rest_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'forget_rest_request' ), 10, 1 );
	}

	/**
	 * Notes, before a REST route runs, whether it is the block renderer
	 * asking for this block's editor preview.
	 *
	 * @param mixed            $response Response so far, passed on as it is.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  The request.
	 * @return mixed
	 */
	public static function note_rest_request( $response, $handler = array(), $request = null ) {
		unset( $handler );
		self::$rest_previews[] = $request instanceof \WP_REST_Request
			&& '/wp/v2/block-renderer/' . self::BLOCK === $request->get_route()
			&& 'edit' === $request->get_param( 'context' );
		return $response;
	}

	/**
	 * Drops the note for the REST route that just ran.
	 *
	 * @param mixed $response Response, passed on as it is.
	 * @return mixed
	 */
	public static function forget_rest_request( $response ) {
		array_pop( self::$rest_previews );
		return $response;
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
		$block = register_block_type( HAPPYACCESS_PLUGIN_DIR . 'blocks/login' );
		remove_filter( 'block_type_metadata', array( __CLASS__, 'block_metadata' ) );

		// Core sets the text domain from block.json; this adds the bundled translations, as the admin app has.
		if ( $block instanceof \WP_Block_Type ) {
			foreach ( $block->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'happyaccess', HAPPYACCESS_PLUGIN_DIR . 'languages' );
			}
		}
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

		// A script handle needs no build file here; only a file: path does.
		if ( 0 !== strpos( $metadata['editorScript'], 'file:' ) ) {
			return $metadata;
		}

		$script = substr( $metadata['editorScript'], 5 );
		$asset  = dirname( (string) $metadata['file'] ) . '/' . preg_replace( '/\.js$/', '.asset.php', $script );
		if ( ! is_readable( $asset ) ) {
			unset( $metadata['editorScript'] );
		}
		return $metadata;
	}

	/**
	 * Whether this request is the block editor asking for a preview. The
	 * editor loads it through the REST block renderer route for this block
	 * with context=edit, which only users who can edit posts may call.
	 * Another route that renders the block, such as a post read in edit
	 * context, is no preview.
	 *
	 * @return bool
	 */
	public static function is_editor_preview() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || array() === self::$rest_previews ) {
			return false;
		}
		return true === end( self::$rest_previews ) && current_user_can( 'edit_posts' );
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
