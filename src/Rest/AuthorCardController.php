<?php
/**
 * REST route for the author card.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Rest;

use HappyAccess\Admin\AuthorCard;

defined( 'ABSPATH' ) || exit;

/**
 * Saves the current user's choice on the author card: dismissed or rated.
 * It always writes to the current user, never to a user named in the request.
 */
final class AuthorCardController {

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			Routes::NS,
			'/author-card',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save' ),
				'permission_callback' => array( Routes::class, 'can_manage' ),
				'args'                => array(
					'choice' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => AuthorCard::CHOICES,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * Stores the choice for the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( \WP_REST_Request $request ) {
		$choice = (string) $request->get_param( 'choice' );
		if ( ! AuthorCard::save( get_current_user_id(), $choice ) ) {
			return new \WP_Error( 'happyaccess_author_card_not_saved', __( 'Could not save that. Try again.', 'happyaccess' ), array( 'status' => 500 ) );
		}

		$response = rest_ensure_response(
			array(
				'choice' => $choice,
				'state'  => 'credit',
			)
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}
}
