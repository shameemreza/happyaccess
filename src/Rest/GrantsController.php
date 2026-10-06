<?php
/**
 * REST routes for support passes.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Rest;

use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\Notifications;
use HappyAccess\Login\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Create, list, extend, suspend, resume, regenerate and revoke passes.
 *
 * Only create and regenerate return a plain code or link key, and those
 * responses are marked no-store. No response ever carries a stored hash.
 */
final class GrantsController {

	/**
	 * Registers the grant routes.
	 *
	 * @return void
	 */
	public static function routes() {
		$permission = array( Routes::class, 'can_manage' );
		$id_arg     = array(
			'id' => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);

		register_rest_route(
			Routes::NS,
			'/grants',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_items' ),
					'permission_callback' => $permission,
					'args'                => array(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_item' ),
					'permission_callback' => $permission,
					'args'                => self::create_args(),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/grants/revoke-all',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'revoke_all' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			Routes::NS,
			'/grants/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_item' ),
					'permission_callback' => $permission,
					'args'                => $id_arg,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'revoke_item' ),
					'permission_callback' => $permission,
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/grants/(?P<id>\d+)/extend',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'extend_item' ),
				'permission_callback' => $permission,
				'args'                => array_merge(
					$id_arg,
					array(
						'seconds' => array(
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => Grants::MIN_DURATION,
							'maximum'           => Grants::MAX_DURATION,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					)
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/grants/(?P<id>\d+)/suspend',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'suspend_item' ),
				'permission_callback' => $permission,
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			Routes::NS,
			'/grants/(?P<id>\d+)/resume',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resume_item' ),
				'permission_callback' => $permission,
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			Routes::NS,
			'/grants/(?P<id>\d+)/regenerate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'regenerate_item' ),
				'permission_callback' => $permission,
				'args'                => array_merge(
					$id_arg,
					array( 'send_email' => self::boolean_arg() )
				),
			)
		);
	}

	/**
	 * Args for POST /grants.
	 *
	 * @return array
	 */
	private static function create_args() {
		$string_list = array(
			'type'              => 'array',
			'items'             => array( 'type' => 'string' ),
			'sanitize_callback' => 'rest_sanitize_request_arg',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return array(
			'label'          => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'email'          => array(
				'type'              => 'string',
				'format'            => 'email',
				'sanitize_callback' => 'rest_sanitize_request_arg',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'level'          => array(
				'type'              => 'string',
				'enum'              => Grants::LEVELS,
				'default'           => 'protected',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'caps'           => $string_list,
			'confirm_full'   => self::boolean_arg(),
			'role'           => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'duration'       => array(
				'type'              => 'integer',
				'minimum'           => Grants::MIN_DURATION,
				'maximum'           => Grants::MAX_DURATION,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'one_time'       => self::boolean_arg(),
			'allow_installs' => self::boolean_arg(),
			'ips'            => $string_list,
			'menus'          => $string_list,
			'hide_admin_bar' => self::boolean_arg(),
			'redirect_to'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'rest_sanitize_request_arg',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'notify'         => array(
				'type'              => 'string',
				'enum'              => array( 'first', 'every', 'off' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'send_email'     => self::boolean_arg(),
		);
	}

	/**
	 * Schema for an optional boolean arg.
	 *
	 * @return array
	 */
	private static function boolean_arg() {
		return array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_request_arg',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * GET /grants.
	 *
	 * @return \WP_REST_Response
	 */
	public static function list_items() {
		return rest_ensure_response(
			array( 'items' => array_map( array( __CLASS__, 'present' ), Grants::list_current() ) )
		);
	}

	/**
	 * POST /grants.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_item( \WP_REST_Request $request ) {
		$args = array( 'created_by' => get_current_user_id() );
		$keys = array( 'label', 'email', 'level', 'caps', 'confirm_full', 'role', 'duration', 'one_time', 'allow_installs', 'ips', 'menus', 'hide_admin_bar', 'redirect_to', 'notify' );
		foreach ( $keys as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$args[ $key ] = $request->get_param( $key );
			}
		}

		try {
			$made = Grants::create( $args );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'happyaccess_invalid', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \RuntimeException $e ) {
			return self::failed();
		}

		$grant = Grants::get( $made['id'] );
		if ( null === $grant ) {
			return self::failed();
		}

		$response = self::secret_response( $grant, $made['code'], $made['link_key'], true === $request->get_param( 'send_email' ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * GET /grants/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_item( \WP_REST_Request $request ) {
		$grant = Grants::get( (int) $request->get_param( 'id' ) );
		if ( null === $grant ) {
			return new \WP_Error( 'happyaccess_not_found', __( 'This support pass does not exist.', 'happyaccess' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::present( $grant ) );
	}

	/**
	 * POST /grants/{id}/extend.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function extend_item( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! Grants::extend( $id, (int) $request->get_param( 'seconds' ) ) ) {
			return self::conflict();
		}
		return self::current( $id );
	}

	/**
	 * POST /grants/{id}/suspend.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function suspend_item( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! Grants::suspend( $id ) ) {
			return self::conflict();
		}
		return self::current( $id );
	}

	/**
	 * POST /grants/{id}/resume.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resume_item( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! Grants::resume( $id ) ) {
			return self::conflict();
		}
		return self::current( $id );
	}

	/**
	 * POST /grants/{id}/regenerate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function regenerate_item( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		try {
			$fresh = Grants::regenerate( $id );
		} catch ( \RuntimeException $e ) {
			return self::failed();
		}
		if ( null === $fresh ) {
			return self::conflict();
		}

		$grant = Grants::get( $id );
		if ( null === $grant ) {
			return self::conflict();
		}
		return self::secret_response( $grant, $fresh['code'], $fresh['link_key'], true === $request->get_param( 'send_email' ) );
	}

	/**
	 * DELETE /grants/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function revoke_item( \WP_REST_Request $request ) {
		if ( ! Grants::revoke( (int) $request->get_param( 'id' ), 'revoked' ) ) {
			return self::conflict();
		}
		return rest_ensure_response( array( 'revoked' => true ) );
	}

	/**
	 * POST /grants/revoke-all.
	 *
	 * @return \WP_REST_Response
	 */
	public static function revoke_all() {
		return rest_ensure_response( array( 'count' => (int) Grants::revoke_all( 'revoked' ) ) );
	}

	/**
	 * Public view of a grant. Built key by key, so nothing stored on the row
	 * can leak into a response.
	 *
	 * @param array $grant Normalized grant.
	 * @return array
	 */
	public static function present( array $grant ) {
		$created = (int) $grant['created_at'];
		$expires = (int) $grant['expires_at'];

		return array(
			'id'             => (int) $grant['id'],
			'label'          => (string) $grant['label'],
			'email'          => (string) $grant['recipient_email'],
			'level'          => (string) $grant['level'],
			'role'           => (string) $grant['role'],
			'caps'           => array_values( array_map( 'strval', (array) $grant['caps'] ) ),
			'allow_installs' => (bool) $grant['allow_installs'],
			'status'         => (string) $grant['status'],
			'one_time'       => 1 === (int) $grant['max_uses'],
			'notify'         => (string) $grant['notify'],
			'restrictions'   => array(
				'ips'            => array_values( array_map( 'strval', (array) $grant['restrictions']['ips'] ) ),
				'menus'          => array_values( array_map( 'strval', (array) $grant['restrictions']['menus'] ) ),
				'hide_admin_bar' => (bool) $grant['restrictions']['hide_admin_bar'],
			),
			'redirect_to'    => (string) $grant['redirect_to'],
			'login_count'    => (int) $grant['login_count'],
			'use_count'      => (int) $grant['use_count'],
			'created_at'     => $created,
			'expires_at'     => $expires,
			'last_login_at'  => (int) $grant['last_login_at'],
			'created_by'     => (int) $grant['created_by'],
			'seconds_left'   => max( 0, $expires - Clock::now() ),
			'duration'       => $expires - $created,
		);
	}

	/**
	 * The one-time secrets of a grant, for the create and regenerate responses.
	 *
	 * @param array  $grant    Normalized grant.
	 * @param string $code     Plain code.
	 * @param string $link_key Plain link key.
	 * @return array
	 */
	public static function secrets( array $grant, $code, $link_key ) {
		return array(
			'code'     => Codes::format_code( $code ),
			'link_url' => Router::url( 'link', array( 'k' => $link_key ) ),
			'code_url' => Router::url( 'code' ),
			'message'  => Notifications::bundle_text( $grant, $code, $link_key ),
		);
	}

	/**
	 * Response that carries secrets: the grant, its secrets and whether they
	 * were emailed, marked so no cache keeps it.
	 *
	 * @param array  $grant      Normalized grant.
	 * @param string $code       Plain code.
	 * @param string $link_key   Plain link key.
	 * @param bool   $send_email Whether to email the secrets to the grant's address.
	 * @return \WP_REST_Response
	 */
	private static function secret_response( array $grant, $code, $link_key, $send_email ) {
		$emailed = false;
		if ( $send_email && '' !== $grant['recipient_email'] ) {
			$emailed = (bool) Notifications::send_bundle( $grant, $code, $link_key );
		}

		$response = rest_ensure_response(
			array_merge(
				self::present( $grant ),
				self::secrets( $grant, $code, $link_key ),
				array( 'emailed' => $emailed )
			)
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * The grant as it is now, after a change.
	 *
	 * @param int $id Grant id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function current( $id ) {
		$grant = Grants::get( $id );
		if ( null === $grant ) {
			return self::conflict();
		}
		return rest_ensure_response( self::present( $grant ) );
	}

	/**
	 * Error for a change the pass's current state doesn't allow.
	 *
	 * @return \WP_Error
	 */
	private static function conflict() {
		return new \WP_Error(
			'happyaccess_conflict',
			__( "This support pass can't be changed in its current state.", 'happyaccess' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Error for a save that failed on the server.
	 *
	 * @return \WP_Error
	 */
	private static function failed() {
		return new \WP_Error(
			'happyaccess_failed',
			__( "The support pass couldn't be saved. Try again.", 'happyaccess' ),
			array( 'status' => 500 )
		);
	}
}
