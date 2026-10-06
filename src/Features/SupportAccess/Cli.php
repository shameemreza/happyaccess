<?php
/**
 * WP-CLI commands for support grants.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\Codes;
use HappyAccess\Login\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Manages temporary support access from the command line.
 */
final class Cli {

	/**
	 * Registers the command when WP-CLI is running.
	 *
	 * @return void
	 */
	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'happyaccess', self::class );
		}
	}

	/**
	 * Maps CLI flags to Grants::create() arguments.
	 *
	 * @param array $assoc Associative arguments from WP-CLI.
	 * @return array
	 * @throws \InvalidArgumentException When --expires or --notify is not valid.
	 */
	public static function grant_args( array $assoc ) {
		$args = array(
			'label'          => isset( $assoc['label'] ) && is_string( $assoc['label'] ) ? $assoc['label'] : '',
			'one_time'       => ! empty( $assoc['one-time'] ),
			'allow_installs' => ! empty( $assoc['allow-installs'] ),
		);

		foreach ( array( 'email', 'role' ) as $key ) {
			if ( isset( $assoc[ $key ] ) && is_string( $assoc[ $key ] ) && '' !== $assoc[ $key ] ) {
				$args[ $key ] = $assoc[ $key ];
			}
		}

		if ( isset( $assoc['redirect'] ) && is_string( $assoc['redirect'] ) && '' !== $assoc['redirect'] ) {
			$args['redirect_to'] = $assoc['redirect'];
		}

		if ( isset( $assoc['notify'] ) ) {
			if ( ! is_string( $assoc['notify'] ) || ! in_array( $assoc['notify'], array( 'first', 'every', 'off' ), true ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Use --notify=first, every or off.', 'happyaccess' ) );
			}
			$args['notify'] = $assoc['notify'];
		}

		if ( isset( $assoc['expires'] ) ) {
			$args['duration'] = self::parse_duration( $assoc['expires'], '--expires' );
		}

		return $args;
	}

	/**
	 * Current grants as table rows.
	 *
	 * @return array id, label, role, status, expires (site time) and logins per grant.
	 */
	public static function list_rows() {
		$rows = array();
		foreach ( Grants::list_current() as $grant ) {
			$rows[] = array(
				'id'      => $grant['id'],
				'label'   => $grant['label'],
				'role'    => $grant['role'],
				'status'  => $grant['status'],
				'expires' => wp_date( 'Y-m-d H:i', $grant['expires_at'] ),
				'logins'  => $grant['login_count'],
			);
		}
		return $rows;
	}

	/**
	 * Creates a grant and prints the login link, the code and a message to share.
	 *
	 * The link and the code are shown once, here, and cannot be read again.
	 *
	 * ## OPTIONS
	 *
	 * --label=<label>
	 * : Who or what the access is for. Shown in the grant list and the activity log.
	 *
	 * [--email=<email>]
	 * : Email the link and the code to this address.
	 *
	 * [--role=<role>]
	 * : Role of the temporary user.
	 * ---
	 * default: administrator
	 * ---
	 *
	 * [--expires=<duration>]
	 * : How long the access lasts, for example 1d, 3d, 7d, 12h. Units are d (days) and h (hours). Defaults to the duration set in HappyAccess settings.
	 *
	 * [--one-time]
	 * : End the access after the first login.
	 *
	 * [--allow-installs]
	 * : Let the temporary user install and update plugins and themes. Blocked by default.
	 *
	 * [--redirect=<url>]
	 * : Send the user to this admin URL after login.
	 *
	 * [--notify=<mode>]
	 * : Login alerts to the person who created the grant.
	 * ---
	 * default: first
	 * options:
	 *   - first
	 *   - every
	 *   - off
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Three days of administrator access.
	 *     $ wp happyaccess grant --label="Acme support" --expires=3d --user=1
	 *
	 *     # Single-use editor access, emailed to the agent.
	 *     $ wp happyaccess grant --label="Ticket 4521" --role=editor --one-time --email=agent@example.com --user=admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function grant( $args, $assoc_args ) {
		try {
			$create               = self::grant_args( $assoc_args );
			$create['created_by'] = get_current_user_id();
			$result               = Grants::create( $create );
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		if ( 0 === $create['created_by'] ) {
			\WP_CLI::warning( __( 'No owner is set for this grant, so nobody will get login alerts. Pass --user=<id|login> to set one.', 'happyaccess' ) );
		}

		$grant = Grants::get( $result['id'] );
		if ( null === $grant ) {
			\WP_CLI::error( __( 'The grant was created but could not be read back.', 'happyaccess' ) );
			return;
		}

		/* translators: %d: grant id. */
		\WP_CLI::success( sprintf( __( 'Created grant %d. The link and code below are shown once.', 'happyaccess' ), $grant['id'] ) );
		\WP_CLI::line( sprintf( 'Link: %s', Router::url( 'link', array( 'k' => $result['link_key'] ) ) ) );
		\WP_CLI::line( sprintf( 'Code: %s', Codes::format_code( $result['code'] ) ) );
		\WP_CLI::line( '' );
		\WP_CLI::line( Notifications::bundle_text( $grant, $result['code'], $result['link_key'] ) );

		if ( '' !== $grant['recipient_email'] ) {
			if ( Notifications::send_bundle( $grant, $result['code'], $result['link_key'] ) ) {
				/* translators: %s: email address. */
				\WP_CLI::success( sprintf( __( 'Emailed the access details to %s.', 'happyaccess' ), $grant['recipient_email'] ) );
			} else {
				/* translators: %s: email address. */
				\WP_CLI::warning( sprintf( __( 'Could not email %s. Share the details above yourself.', 'happyaccess' ), $grant['recipient_email'] ) );
			}
		}
	}

	/**
	 * Lists grants that are not revoked and not expired.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp happyaccess list
	 *     $ wp happyaccess list --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$rows   = self::list_rows();

		if ( empty( $rows ) && 'table' === $format ) {
			\WP_CLI::line( __( 'No current grants.', 'happyaccess' ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'label', 'role', 'status', 'expires', 'logins' ) );
	}

	/**
	 * Moves the expiry of a grant forward.
	 *
	 * The new expiry is never more than 30 days from now.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Grant id, from `wp happyaccess list`.
	 *
	 * --by=<duration>
	 * : How much time to add, for example 1d or 12h.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp happyaccess extend 4 --by=1d
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function extend( $args, $assoc_args ) {
		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( $id < 1 ) {
			\WP_CLI::error( __( 'Give the id of a grant.', 'happyaccess' ) );
			return;
		}

		try {
			$seconds = self::parse_duration( isset( $assoc_args['by'] ) ? $assoc_args['by'] : '', '--by' );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		if ( ! Grants::extend( $id, $seconds ) ) {
			\WP_CLI::error( __( 'That grant does not exist or has already ended.', 'happyaccess' ) );
			return;
		}

		$grant = Grants::get( $id );
		if ( null === $grant ) {
			\WP_CLI::success( __( 'Extended.', 'happyaccess' ) );
			return;
		}
		/* translators: 1: grant id, 2: new expiry date and time. */
		\WP_CLI::success( sprintf( __( 'Grant %1$d now ends %2$s.', 'happyaccess' ), $grant['id'], wp_date( 'Y-m-d H:i', $grant['expires_at'] ) ) );
	}

	/**
	 * Ends a grant now and removes its temporary user.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Grant id, from `wp happyaccess list`.
	 *
	 * [--all]
	 * : End every grant that has not ended yet.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp happyaccess revoke 4
	 *     $ wp happyaccess revoke --all
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function revoke( $args, $assoc_args ) {
		$all = ! empty( $assoc_args['all'] );
		$id  = isset( $args[0] ) ? absint( $args[0] ) : 0;

		if ( ( $id > 0 ) === $all ) {
			\WP_CLI::error( __( 'Give either a grant id or --all.', 'happyaccess' ) );
			return;
		}

		if ( $all ) {
			$count = Grants::revoke_all( 'revoked_cli' );
			/* translators: %d: number of grants ended. */
			\WP_CLI::success( sprintf( _n( 'Ended %d grant.', 'Ended %d grants.', $count, 'happyaccess' ), $count ) );
			return;
		}

		if ( ! Grants::revoke( $id, 'revoked_cli' ) ) {
			\WP_CLI::error( __( 'That grant does not exist or has already ended.', 'happyaccess' ) );
			return;
		}
		/* translators: %d: grant id. */
		\WP_CLI::success( sprintf( __( 'Ended grant %d.', 'happyaccess' ), $id ) );
	}

	/**
	 * Converts a value such as 3d or 12h to seconds.
	 *
	 * @param mixed  $value Flag value.
	 * @param string $flag  Flag name, for the error message.
	 * @return int
	 * @throws \InvalidArgumentException When the value is not a positive whole number followed by d or h.
	 */
	private static function parse_duration( $value, $flag ) {
		$value  = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		$number = substr( $value, 0, -1 );
		$unit   = substr( $value, -1 );
		$units  = array(
			'd' => DAY_IN_SECONDS,
			'h' => HOUR_IN_SECONDS,
		);

		if ( '' === $number || ! ctype_digit( $number ) || ! isset( $units[ $unit ] ) || (int) $number < 1 ) {
			/* translators: %s: flag name, for example --expires. */
			throw new \InvalidArgumentException( esc_html( sprintf( __( 'Use a whole number followed by d or h for %s, for example 3d or 12h.', 'happyaccess' ), $flag ) ) );
		}

		return (int) $number * $units[ $unit ];
	}
}
