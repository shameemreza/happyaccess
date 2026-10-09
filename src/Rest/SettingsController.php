<?php
/**
 * REST routes for settings, first-run setup, the permissions catalog and
 * the emergency lock.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Rest;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\Internal;
use HappyAccess\Core\Recaptcha;
use HappyAccess\Core\SettingLabels;
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

	const SECRET_OPTION = Recaptcha::SECRET_OPTION;

	/**
	 * Setting groups a request may change.
	 */
	const GROUPS = array( 'features', 'security', 'privacy', 'support', 'passwordless', 'two_step' );

	/**
	 * Keys inside a group that hold a flat map of plain values, such as
	 * show_on and role_policy. Nowhere else may a value be an object.
	 */
	const MAP_KEYS = array(
		'passwordless' => array( 'show_on', 'role_policy' ),
		'two_step'     => array( 'role_policy' ),
	);

	/**
	 * Keys inside a group that hold a list of role slugs, such as
	 * device_alert_roles. A list may be empty.
	 */
	const LIST_KEYS = array(
		'two_step' => array( 'device_alert_roles' ),
	);

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
				'validate_callback' => array( __CLASS__, 'validate_group' ),
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
	 * Checks a settings group: an object whose values are all plain text,
	 * numbers or booleans. A list, or a value that is itself a list or an
	 * object, is refused instead of being cast to something else. The
	 * exceptions are a key listed in MAP_KEYS, which may hold a flat object,
	 * and a key listed in LIST_KEYS, which must hold a list of text.
	 *
	 * @param mixed            $value   Param value.
	 * @param \WP_REST_Request $request Request.
	 * @param string           $param   Param name.
	 * @return true|\WP_Error
	 */
	public static function validate_group( $value, $request, $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $valid ) {
			return $valid;
		}
		$is_list = is_array( $value ) && array() !== $value && array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! is_array( $value ) || $is_list ) {
			/* translators: %s: setting group name. */
			return new \WP_Error( 'rest_invalid_param', sprintf( __( '%s must be an object.', 'happyaccess' ), $param ), array( 'status' => 400 ) );
		}
		foreach ( $value as $key => $item ) {
			if ( isset( self::LIST_KEYS[ $param ] ) && in_array( $key, self::LIST_KEYS[ $param ], true ) ) {
				if ( is_array( $item ) && self::is_text_list( $item ) ) {
					continue;
				}
				/* translators: %s: setting group name. */
				return new \WP_Error( 'rest_invalid_param', sprintf( __( '%s has a value that must be a list of role names.', 'happyaccess' ), $param ), array( 'status' => 400 ) );
			}
			if ( isset( self::MAP_KEYS[ $param ] ) && in_array( $key, self::MAP_KEYS[ $param ], true ) ) {
				if ( is_array( $item ) && self::is_flat_map( $item ) ) {
					continue;
				}
				/* translators: %s: setting group name. */
				return new \WP_Error( 'rest_invalid_param', sprintf( __( '%s has a value that must be an object of plain values.', 'happyaccess' ), $param ), array( 'status' => 400 ) );
			}
			if ( ! is_scalar( $item ) ) {
				/* translators: %s: setting group name. */
				return new \WP_Error( 'rest_invalid_param', sprintf( __( '%s can only hold text, numbers and true or false.', 'happyaccess' ), $param ), array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/**
	 * Whether a value is an object of plain values: no list, nothing nested.
	 *
	 * @param array $map Value.
	 * @return bool
	 */
	private static function is_flat_map( array $map ) {
		if ( array() !== $map && array_keys( $map ) === range( 0, count( $map ) - 1 ) ) {
			return false;
		}
		foreach ( $map as $item ) {
			if ( ! is_scalar( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a value is a list of text: keys 0, 1, 2 and so on, and only
	 * strings. An empty list counts.
	 *
	 * @param array $items Value.
	 * @return bool
	 */
	private static function is_text_list( array $items ) {
		if ( array() !== $items && array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
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

		if ( self::follows_main_site() && self::has_main_site_keys( $changes ) ) {
			return self::network_error();
		}

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

		$secret_changed = is_string( $secret ) && (string) get_option( self::SECRET_OPTION, '' ) !== $secret;
		if ( self::secret_refused( $changes, $secret_changed ? $secret : null ) ) {
			return new \WP_Error(
				'happyaccess_recaptcha_secret',
				__( "Google didn't accept this secret key. Check it in your reCAPTCHA admin and try again.", 'happyaccess' ),
				array( 'status' => 400 )
			);
		}
		$extra = self::apply( $changes, $secret_changed ? $secret : null );
		if ( is_wp_error( $extra ) ) {
			return $extra;
		}

		return rest_ensure_response( array_merge( self::present(), $extra ) );
	}

	/**
	 * Whether Google refuses the secret a save would use. It asks only when
	 * the save turns reCAPTCHA on or sets a new secret, once, and only a
	 * clear "unknown secret" answer refuses.
	 *
	 * @param array       $changes Nested changes.
	 * @param string|null $secret  The new secret, or null when it stays.
	 * @return bool
	 */
	private static function secret_refused( array $changes, $secret ) {
		$turns_on = true !== Settings::get( 'security.recaptcha_enabled' ) && true === Settings::merge( $changes )['security']['recaptcha_enabled'];
		if ( null === $secret && ! $turns_on ) {
			return false;
		}
		$check = null === $secret ? (string) get_option( self::SECRET_OPTION, '' ) : $secret;
		return '' !== $check && Recaptcha::secret_rejected( $check );
	}

	/**
	 * The error for a two-step setting sent from a subsite that follows the main site.
	 *
	 * @return \WP_Error
	 */
	private static function network_error() {
		return new \WP_Error(
			'happyaccess_two_step_network',
			__( "HappyAccess is on for the whole network, so two-step login follows the main site's settings. Change them on the main site.", 'happyaccess' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Whether this site reads its two-step settings from the main site:
	 * a subsite while HappyAccess is network active.
	 *
	 * @return bool
	 */
	private static function follows_main_site() {
		return Settings::network_active() && ! is_main_site();
	}

	/**
	 * Whether a save names a setting the main site decides for the network.
	 *
	 * @param array $changes Nested changes.
	 * @return bool
	 */
	private static function has_main_site_keys( array $changes ) {
		foreach ( Settings::MAIN_SITE_PATHS as $path ) {
			list( $group, $key ) = explode( '.', $path );
			if ( isset( $changes[ $group ] ) && is_array( $changes[ $group ] ) && array_key_exists( $key, $changes[ $group ] ) ) {
				return true;
			}
		}
		return false;
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
		if ( self::follows_main_site() && array_key_exists( 'two_step', $features ) ) {
			return self::network_error();
		}

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
		if ( is_wp_error( $extra ) ) {
			return $extra;
		}

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
		return rest_ensure_response( array( 'revoked' => AdminBar::emergency_lock() ) );
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
	 * The error for a write that did not reach the database.
	 *
	 * @return \WP_Error
	 */
	private static function save_error() {
		return new \WP_Error(
			'happyaccess_settings_not_saved',
			__( 'Settings could not be saved. Please try again.', 'happyaccess' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Saves changes, checks that they were stored, and logs the names of the
	 * keys that changed. When Support Access goes from on to off, every pass
	 * ends.
	 *
	 * The row is written before the save only when this request turns
	 * logging off, so that change is the last row logged. Otherwise it is
	 * written after a save that was stored, so a failed save leaves no row
	 * saying it happened.
	 *
	 * @param array       $changes Nested changes, known groups only.
	 * @param string|null $secret  New reCAPTCHA secret, an empty string to clear it, or null to leave it.
	 * @return array|\WP_Error Extra response fields ("revoked" when passes were ended), or an error when a write failed.
	 */
	private static function apply( array $changes, $secret = null ) {
		$was_on   = Features::is_enabled( 'support_access' );
		$pending  = $was_on ? count( Grants::list_current() ) : 0;
		$before   = Settings::all();
		$expected = Settings::merge( $changes );
		$keys     = self::changed_keys( $before, $expected );
		if ( null !== $secret ) {
			$keys[] = 'security.recaptcha_secret_key';
		}
		sort( $keys );
		$log_first = (bool) $before['privacy']['logging'] && ! $expected['privacy']['logging'];

		if ( $log_first ) {
			self::log_change( $keys, $expected );
		}
		Settings::update( $changes );
		// update_option() also returns false for an unchanged value, so compare what is stored with what was meant to be.
		if ( Settings::all() !== $expected ) {
			return self::save_error();
		}
		if ( null !== $secret && ! self::write_secret( $secret ) ) {
			// The other settings were stored, so the log still records them, without the secret.
			if ( ! $log_first ) {
				self::log_change( array_values( array_diff( $keys, array( 'security.recaptcha_secret_key' ) ) ), $expected );
			}
			return self::save_error();
		}
		if ( ! $log_first ) {
			self::log_change( $keys, $expected );
		}

		if ( $was_on && ! Features::is_enabled( 'support_access' ) ) {
			Feature::on_disable();
			return array( 'revoked' => $pending );
		}
		return array();
	}

	/**
	 * Dotted names of the settings whose values differ. Values never leave this method.
	 *
	 * @param array  $before Settings before.
	 * @param array  $after  Settings after.
	 * @param string $prefix Dotted path of this level.
	 * @return string[]
	 */
	private static function changed_keys( array $before, array $after, $prefix = '' ) {
		$keys = array();
		foreach ( $after as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			$old  = array_key_exists( $key, $before ) ? $before[ $key ] : null;
			if ( is_array( $value ) ) {
				$keys = array_merge( $keys, self::changed_keys( is_array( $old ) ? $old : array(), $value, $path ) );
			} elseif ( $old !== $value ) {
				$keys[] = $path;
			}
		}
		return $keys;
	}

	/**
	 * Logs an admin settings change by key name only, plus the new on or off
	 * of any feature switch among them. The line a person reads is built
	 * from these when the row is read (see SettingLabels::summary()).
	 *
	 * @param string[] $keys     Changed dotted keys.
	 * @param array    $settings Settings after the change.
	 * @return void
	 */
	private static function log_change( array $keys, array $settings ) {
		if ( ! $keys ) {
			return;
		}
		$meta     = array( 'keys' => $keys );
		$features = SettingLabels::feature_states( $keys, $settings );
		if ( $features ) {
			$meta['features'] = $features;
		}
		AuditLog::add(
			'settings_changed',
			array(
				'feature' => 'core',
				'summary' => sprintf( 'Changed settings: %s', implode( ', ', $keys ) ),
				'meta'    => $meta,
			)
		);
	}

	/**
	 * Saves the secret in its own option, autoload off. An empty value clears it.
	 *
	 * @param string $secret Cleaned secret.
	 * @return bool Whether the stored secret is now the given one.
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
		return (string) get_option( self::SECRET_OPTION, '' ) === $secret;
	}

	/**
	 * The settings plus two derived fields. The secret itself is never sent.
	 *
	 * @return array
	 */
	private static function present() {
		$settings                         = Settings::all();
		$settings['recaptcha_secret_set'] = '' !== (string) get_option( self::SECRET_OPTION, '' );
		$settings['needs_setup']          = self::needs_setup();
		return $settings;
	}

	/**
	 * Whether first-run setup is still to do: no one has recorded consent yet.
	 * The admin page reads this too, so both agree.
	 *
	 * @return bool
	 */
	public static function needs_setup() {
		return '' === (string) Settings::get( 'support.consent_given_at' );
	}
}
