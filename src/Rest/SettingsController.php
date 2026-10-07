<?php
/**
 * REST routes for settings, first-run setup, the permissions catalog and
 * the emergency lock.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Rest;

use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\Internal;
use HappyAccess\Core\Settings;
use HappyAccess\Features\SupportAccess\AdminBar;
use HappyAccess\Features\SupportAccess\Catalog;
use HappyAccess\Features\SupportAccess\Feature;
use HappyAccess\Features\SupportAccess\Grants;

defined( 'ABSPATH' ) || exit;

/**
 * Settings are read and written through the Settings class, which drops
 * unknown keys and clamps values. The reCAPTCHA secret lives in its own
 * option and is write-only: no response ever carries it.
 */
final class SettingsController {

	const SECRET_OPTION = 'happyaccess_recaptcha_secret_key';

	/**
	 * Setting groups a request may change.
	 */
	const GROUPS = array( 'features', 'security', 'privacy', 'support' );

	/**
	 * Consent is recorded by POST /setup only, so a settings save can't fake it.
	 */
	const CONSENT_KEYS = array( 'consent_given_at', 'consent_user_id' );

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function routes() {
		$permission = array( Routes::class, 'can_manage' );

		$group_args = array();
		foreach ( self::GROUPS as $group ) {
			$group_args[ $group ] = array(
				'type'              => 'object',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			);
		}

		register_rest_route(
			Routes::NS,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_settings' ),
					'permission_callback' => $permission,
					'args'                => array_merge(
						$group_args,
						array(
							'recaptcha_secret_key' => array(
								'type'              => 'string',
								'sanitize_callback' => array( __CLASS__, 'sanitize_secret' ),
								'validate_callback' => 'rest_validate_request_arg',
							),
						)
					),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/setup',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'setup' ),
				'permission_callback' => $permission,
				'args'                => array(
					'features' => array(
						'type'                 => 'object',
						'required'             => true,
						'properties'           => array(
							'support_access' => array( 'type' => 'boolean' ),
							'passwordless'   => array( 'type' => 'boolean' ),
							'two_step'       => array( 'type' => 'boolean' ),
						),
						'additionalProperties' => false,
						'validate_callback'    => 'rest_validate_request_arg',
						'sanitize_callback'    => 'rest_sanitize_request_arg',
					),
					'consent'  => array(
						'type'              => 'boolean',
						'default'           => false,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/catalog',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'catalog' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			Routes::NS,
			'/lock',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'lock' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Cleans the secret. It is never echoed, even in an error.
	 *
	 * @param mixed $value Param value.
	 * @return string
	 */
	public static function sanitize_secret( $value ) {
		return is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
	}

	/**
	 * GET /settings.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_settings() {
		return rest_ensure_response( self::present() );
	}

	/**
	 * POST /settings. Before setup records consent, a save can't turn
	 * Support Access on, so the first-run screen stays the only way in.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_settings( \WP_REST_Request $request ) {
		$changes = array();
		foreach ( self::GROUPS as $group ) {
			$value = $request->get_param( $group );
			if ( is_array( $value ) ) {
				$changes[ $group ] = $value;
			}
		}
		unset( $changes['support']['consent_given_at'], $changes['support']['consent_user_id'] );

		$turns_on = ! Features::is_enabled( 'support_access' )
			&& isset( $changes['features'] )
			&& array_key_exists( 'support_access', $changes['features'] )
			&& rest_sanitize_boolean( $changes['features']['support_access'] );
		if ( $turns_on && '' === (string) Settings::get( 'support.consent_given_at' ) ) {
			return self::consent_error();
		}

		// The secret may come at the top level or inside the security group.
		// A nested value that isn't text is ignored, so it can't clear the stored secret.
		$secret = $request->get_param( 'recaptcha_secret_key' );
		if ( null === $secret && isset( $changes['security']['recaptcha_secret_key'] ) && is_string( $changes['security']['recaptcha_secret_key'] ) ) {
			$secret = self::sanitize_secret( $changes['security']['recaptcha_secret_key'] );
		}
		unset( $changes['security']['recaptcha_secret_key'] );

		$extra = self::apply( $changes );

		if ( is_string( $secret ) ) {
			self::write_secret( $secret );
		}

		return rest_ensure_response( array_merge( self::present(), $extra ) );
	}

	/**
	 * POST /setup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function setup( \WP_REST_Request $request ) {
		$features = (array) $request->get_param( 'features' );
		$features = array_intersect_key( $features, array_flip( Features::ALL ) );

		$support_on = array_key_exists( 'support_access', $features ) ? (bool) $features['support_access'] : Features::is_enabled( 'support_access' );
		if ( $support_on && true !== $request->get_param( 'consent' ) ) {
			return self::consent_error();
		}

		// Finishing setup always records who did it and when, or the first-run screen would show again.
		$extra = self::apply(
			array(
				'features' => $features,
				'support'  => array(
					'consent_given_at' => Clock::mysql(),
					'consent_user_id'  => get_current_user_id(),
				),
			)
		);

		return rest_ensure_response( array_merge( self::present(), $extra ) );
	}

	/**
	 * GET /catalog.
	 *
	 * @return \WP_REST_Response
	 */
	public static function catalog() {
		return rest_ensure_response(
			array(
				'groups'  => Catalog::groups(),
				'presets' => Catalog::presets(),
			)
		);
	}

	/**
	 * POST /lock. Reports the passes that were current, as the settings
	 * save does, though the lock also ends expired rows not yet revoked.
	 *
	 * @return \WP_REST_Response
	 */
	public static function lock() {
		$current = count( Grants::list_current() );
		AdminBar::emergency_lock();
		return rest_ensure_response( array( 'revoked' => $current ) );
	}

	/**
	 * The error for turning Support Access on before consent is recorded.
	 *
	 * @return \WP_Error
	 */
	private static function consent_error() {
		return new \WP_Error(
			'happyaccess_consent_required',
			__( 'Please confirm before giving anyone access.', 'happyaccess' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Saves changes. When Support Access goes from on to off, every pass ends.
	 *
	 * @param array $changes Nested changes, known groups only.
	 * @return array Extra response fields: "revoked" when passes were ended.
	 */
	private static function apply( array $changes ) {
		$was_on  = Features::is_enabled( 'support_access' );
		$pending = $was_on ? count( Grants::list_current() ) : 0;

		Settings::update( $changes );

		if ( $was_on && ! Features::is_enabled( 'support_access' ) ) {
			Feature::on_disable();
			return array( 'revoked' => $pending );
		}
		return array();
	}

	/**
	 * Saves the secret in its own option, autoload off. An empty value clears it.
	 *
	 * @param string $secret Cleaned secret.
	 * @return void
	 */
	private static function write_secret( $secret ) {
		Internal::run(
			static function () use ( $secret ) {
				if ( '' === $secret ) {
					delete_option( self::SECRET_OPTION );
				} else {
					update_option( self::SECRET_OPTION, $secret, false );
				}
			}
		);
	}

	/**
	 * The settings plus two derived fields. The secret itself is never sent.
	 *
	 * @return array
	 */
	private static function present() {
		$settings                         = Settings::all();
		$settings['recaptcha_secret_set'] = '' !== (string) get_option( self::SECRET_OPTION, '' );
		$settings['needs_setup']          = '' === (string) $settings['support']['consent_given_at'];
		return $settings;
	}
}
